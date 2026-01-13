<?php
session_start();
include 'db.php';
require_once 'notifications_helper.php';
require_once 'defense_committee_helpers.php';
require_once 'defense_schedule_helpers.php';
require_once 'role_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'dean') {
    header("Location: login.php");
    exit;
}

$deanId = (int)$_SESSION['user_id'];
$deanName = fetch_user_fullname($conn, $deanId);

ensureDefenseCommitteeRequestsTable($conn);
ensureRoleInfrastructure($conn);

$alert = null;

if (!function_exists('ensure_user_events_table')) {
    function ensure_user_events_table(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'user_events'");
        $exists = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck) {
            $tableCheck->free();
        }
        if (!$exists) {
            $createTableQuery = "
                CREATE TABLE user_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    role VARCHAR(50) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    start_datetime DATETIME NOT NULL,
                    end_datetime DATETIME,
                    category ENUM('Defense', 'Meeting', 'Call', 'Academic', 'Personal', 'Other') DEFAULT 'Other',
                    color VARCHAR(7) DEFAULT '#16562c',
                    source VARCHAR(50) NULL,
                    source_id INT NULL,
                    is_locked BOOLEAN DEFAULT FALSE,
                    is_all_day BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user_role_events (user_id, role),
                    UNIQUE KEY uniq_user_source (user_id, source, source_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            $conn->query($createTableQuery);
        }

        $columns = [
            'source' => "ALTER TABLE user_events ADD COLUMN source VARCHAR(50) NULL AFTER color",
            'source_id' => "ALTER TABLE user_events ADD COLUMN source_id INT NULL AFTER source",
            'is_locked' => "ALTER TABLE user_events ADD COLUMN is_locked BOOLEAN DEFAULT FALSE AFTER source_id",
        ];
        foreach ($columns as $column => $sql) {
            $check = $conn->query("SHOW COLUMNS FROM user_events LIKE '{$column}'");
            if ($check && $check->num_rows === 0) {
                $conn->query($sql);
            }
            if ($check) {
                $check->free();
            }
        }

        $indexCheck = $conn->query("SHOW INDEX FROM user_events WHERE Key_name = 'uniq_user_source'");
        if ($indexCheck && $indexCheck->num_rows === 0) {
            $conn->query("ALTER TABLE user_events ADD UNIQUE KEY uniq_user_source (user_id, source, source_id)");
        }
        if ($indexCheck) {
            $indexCheck->free();
        }

        $ensured = true;
    }
}

if (!function_exists('fetch_user_role')) {
    function fetch_user_role(mysqli $conn, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return trim((string)($row['role'] ?? ''));
    }
}

if (!function_exists('sync_defense_calendar_events')) {
    function sync_defense_calendar_events(
        mysqli $conn,
        int $defenseId,
        array $userIds,
        string $title,
        string $description,
        string $startDateTime,
        string $endDateTime
    ): void {
        if ($defenseId <= 0) {
            return;
        }
        ensure_user_events_table($conn);

        $deleteStmt = $conn->prepare("DELETE FROM user_events WHERE source = 'defense' AND source_id = ?");
        if ($deleteStmt) {
            $deleteStmt->bind_param('i', $defenseId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($userIds)) {
            return;
        }

        $insertStmt = $conn->prepare("
            INSERT INTO user_events
                (user_id, role, title, description, start_datetime, end_datetime, category, color, source, source_id, is_locked)
            VALUES (?, ?, ?, ?, ?, ?, 'Defense', '#16562c', 'defense', ?, 1)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                start_datetime = VALUES(start_datetime),
                end_datetime = VALUES(end_datetime),
                category = VALUES(category),
                color = VALUES(color),
                role = VALUES(role),
                is_locked = 1
        ");
        if (!$insertStmt) {
            return;
        }

        foreach ($userIds as $userId) {
            $role = fetch_user_role($conn, $userId);
            if ($role === '') {
                $role = 'student';
            }
            $insertStmt->bind_param(
                'isssssi',
                $userId,
                $role,
                $title,
                $description,
                $startDateTime,
                $endDateTime,
                $defenseId
            );
            $insertStmt->execute();
        }
        $insertStmt->close();
    }
}

function grant_committee_chair_role(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    ensureUserRoleAssignment($conn, $userId, 'committee_chairperson');
    ensureUserRoleAssignment($conn, $userId, 'faculty');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_committee_request'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));
    $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
    $memoNumber = trim((string)($_POST['memo_number'] ?? ''));
    $memoSeriesYear = trim((string)($_POST['memo_series_year'] ?? ''));
    $memoDateInput = trim((string)($_POST['memo_date'] ?? ''));
    $memoSubject = trim((string)($_POST['memo_subject'] ?? ''));
    $memoBody = trim((string)($_POST['memo_body'] ?? ''));

    if ($requestId <= 0 || !in_array($decision, ['Approved', 'Rejected'], true)) {
        $alert = ['type' => 'danger', 'message' => 'Please choose a valid review action.'];
    } else {
        $requestInfo = null;
        $lookup = $conn->prepare("
            SELECT r.defense_id, r.requested_by, r.status, r.adviser_id, r.chair_id,
                   r.panel_member_one_id, r.panel_member_two_id, r.student_id, r.memo_final_title,
                   CONCAT(u.firstname, ' ', u.lastname) AS student_name
            FROM defense_committee_requests r
            JOIN users u ON u.id = r.student_id
            WHERE r.id = ?
            LIMIT 1
        ");
        if ($lookup) {
            $lookup->bind_param('i', $requestId);
            $lookup->execute();
            $result = $lookup->get_result();
            $requestInfo = $result ? $result->fetch_assoc() : null;
            $lookup->close();
        }

        if (!$requestInfo) {
            $alert = ['type' => 'danger', 'message' => 'Unable to locate that committee request.'];
        } elseif (($requestInfo['status'] ?? '') === $decision) {
            $alert = ['type' => 'warning', 'message' => 'This request already has the selected status.'];
        } else {
            $memoDate = null;
            if ($memoDateInput !== '') {
                $memoDateObj = DateTime::createFromFormat('Y-m-d', $memoDateInput);
                if ($memoDateObj && $memoDateObj->format('Y-m-d') === $memoDateInput) {
                    $memoDate = $memoDateInput;
                }
            }
            if ($decision === 'Approved') {
                if ($memoNumber === '' || $memoDate === null || $memoSubject === '' || $memoBody === '') {
                    $alert = ['type' => 'danger', 'message' => 'Memo number, date, subject, and body are required before approving.'];
                }
            }
        }

        if (!$alert) {
            $memoFinalTitle = trim((string)($requestInfo['memo_final_title'] ?? ''));
            if ($memoFinalTitle === '') {
                $memoFinalTitle = fetch_final_pick_title_for_student($conn, (int)($requestInfo['student_id'] ?? 0));
            }
            $update = $conn->prepare("
                UPDATE defense_committee_requests
                SET status = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    review_notes = ?,
                    memo_number = ?,
                    memo_series_year = ?,
                    memo_date = ?,
                    memo_subject = ?,
                    memo_body = ?,
                    memo_final_title = ?,
                    memo_updated_at = NOW()
                WHERE id = ?
            ");
            if ($update) {
                $seriesYear = $memoSeriesYear !== '' ? $memoSeriesYear : date('Y');
                $memoDateParam = $memoDate ?? null;
                $update->bind_param(
                    'sisssssssi',
                    $decision,
                    $deanId,
                    $reviewNotes,
                    $memoNumber,
                    $seriesYear,
                    $memoDateParam,
                    $memoSubject,
                    $memoBody,
                    $memoFinalTitle,
                    $requestId
                );
                if ($update->execute()) {
                    if ($decision === 'Approved') {
                        $scheduleUpdate = $conn->prepare("UPDATE defense_schedules SET status = 'Confirmed' WHERE id = ?");
                        if ($scheduleUpdate) {
                            $scheduleUpdate->bind_param('i', $requestInfo['defense_id']);
                            $scheduleUpdate->execute();
                            $scheduleUpdate->close();
                        }
                        grant_committee_chair_role($conn, (int)($requestInfo['chair_id'] ?? 0));
                    }

                    $studentName = $requestInfo['student_name'] ?? 'the student';
                    $chairId = (int)($requestInfo['requested_by'] ?? 0);
                    $committeeChairId = (int)($requestInfo['chair_id'] ?? 0);
                    $adviserId = (int)($requestInfo['adviser_id'] ?? 0);
                    $panelOneId = (int)($requestInfo['panel_member_one_id'] ?? 0);
                    $panelTwoId = (int)($requestInfo['panel_member_two_id'] ?? 0);
                    $studentId = (int)($requestInfo['student_id'] ?? 0);
                    $memoLink = 'defense_committee_memo.php?request_id=' . $requestId;
                    $adviserName = fetch_user_fullname($conn, $adviserId);
                    $chairName = fetch_user_fullname($conn, $committeeChairId);
                    $panelOneName = fetch_user_fullname($conn, $panelOneId);
                    $panelTwoName = fetch_user_fullname($conn, $panelTwoId);
                    $committeeSummary = trim(implode(', ', array_filter([
                        $adviserName !== '' ? "Adviser: {$adviserName}" : '',
                        $chairName !== '' ? "Chair: {$chairName}" : '',
                        $panelOneName !== '' ? "Panel: {$panelOneName}" : '',
                        $panelTwoName !== '' ? "Panel: {$panelTwoName}" : '',
                    ])));
                    if ($decision === 'Approved' && $requestInfo['defense_id']) {
                        ensureDefenseScheduleTimeColumns($conn);
                        $scheduleStmt = $conn->prepare("
                            SELECT defense_date,
                                   COALESCE(start_time, defense_time) AS start_time,
                                   COALESCE(end_time, ADDTIME(COALESCE(start_time, defense_time), '01:00:00')) AS end_time,
                                   venue
                            FROM defense_schedules
                            WHERE id = ?
                            LIMIT 1
                        ");
                        if ($scheduleStmt) {
                            $scheduleId = (int)$requestInfo['defense_id'];
                            $scheduleStmt->bind_param('i', $scheduleId);
                            $scheduleStmt->execute();
                            $scheduleResult = $scheduleStmt->get_result();
                            $scheduleRow = $scheduleResult ? $scheduleResult->fetch_assoc() : null;
                            if ($scheduleResult) {
                                $scheduleResult->free();
                            }
                            $scheduleStmt->close();

                            if ($scheduleRow && !empty($scheduleRow['defense_date']) && !empty($scheduleRow['start_time'])) {
                                $scheduleDate = $scheduleRow['defense_date'];
                                $startTime = $scheduleRow['start_time'] ?? '';
                                $endTime = $scheduleRow['end_time'] ?? $startTime;
                                $venue = trim((string)($scheduleRow['venue'] ?? ''));
                                $panelNames = array_filter([$panelOneName, $panelTwoName]);
                                $panelList = $panelNames ? implode(', ', $panelNames) : 'Not assigned';
                                $description = "Student: {$studentName}\nVenue: {$venue}\nAdviser: {$adviserName}\nChair: {$chairName}\nPanel: {$panelList}";
                                $title = "Defense: {$studentName}";
                                $startDateTime = "{$scheduleDate} {$startTime}";
                                $endDateTime = "{$scheduleDate} {$endTime}";
                                $calendarUserIds = array_filter([
                                    $studentId,
                                    $adviserId,
                                    $committeeChairId,
                                    $panelOneId,
                                    $panelTwoId,
                                ]);
                                sync_defense_calendar_events($conn, $scheduleId, $calendarUserIds, $title, $description, $startDateTime, $endDateTime);
                            }
                        }
                    }
                    $decisionMessage = "Dean {$decision} the defense committee for {$studentName}.";
                    if ($reviewNotes !== '') {
                        $decisionMessage .= " Notes: {$reviewNotes}";
                    }
                    if ($chairId > 0) {
                        notify_user($conn, $chairId, "Defense committee {$decision}", $decisionMessage, 'defense_committee.php', false);
                    }
                    if ($committeeChairId > 0) {
                        notify_user_for_role(
                            $conn,
                            $committeeChairId,
                            'committee_chairperson',
                            "Defense committee {$decision}",
                            $decisionMessage . ($committeeSummary !== '' ? " {$committeeSummary}." : ''),
                            $memoLink,
                            false
                        );
                    }
                    if ($adviserId > 0) {
                        notify_user_for_role(
                            $conn,
                            $adviserId,
                            'adviser',
                            "Defense committee {$decision}",
                            $decisionMessage . ($committeeSummary !== '' ? " {$committeeSummary}." : ''),
                            $memoLink,
                            false
                        );
                    }
                    $panelStmt = $conn->prepare("
                        SELECT panel_member_id
                        FROM defense_panels
                        WHERE defense_id = ?
                          AND panel_role = 'panel_member'
                    ");
                    if ($panelStmt) {
                        $panelStmt->bind_param('i', $requestInfo['defense_id']);
                        $panelStmt->execute();
                        $panelResult = $panelStmt->get_result();
                        $panelIds = [];
                        if ($panelResult) {
                            while ($panelRow = $panelResult->fetch_assoc()) {
                                $panelId = (int)($panelRow['panel_member_id'] ?? 0);
                                if ($panelId > 0) {
                                    $panelIds[] = $panelId;
                                }
                            }
                            $panelResult->free();
                        }
                        $panelStmt->close();
                        $panelIds = array_values(array_unique($panelIds));
                        foreach ($panelIds as $panelId) {
                            notify_user_for_role(
                                $conn,
                                $panelId,
                                'panel',
                                "Defense committee {$decision}",
                                $decisionMessage . ($committeeSummary !== '' ? " {$committeeSummary}." : ''),
                                $memoLink,
                                false
                            );
                        }
                    }
                    if ($decision === 'Approved' && $studentId > 0) {
                        $studentMessage = "Defense committee approved. {$committeeSummary}."
                            . " Please submit your outline to the assigned committee members.";
                        notify_user(
                            $conn,
                            $studentId,
                            'Defense committee approved',
                            $studentMessage,
                            $memoLink,
                            false
                        );
                    }
                    $alert = ['type' => 'success', 'message' => "Request {$decision} successfully."];
                } else {
                    $alert = ['type' => 'danger', 'message' => 'Unable to update the committee request.'];
                }
                $update->close();
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to prepare the review request.'];
            }
        }
    }
}

$requests = [];
$requestSql = "
    SELECT
        r.id,
        r.student_id,
        r.status,
        r.request_notes,
        r.review_notes,
        r.requested_at,
        r.reviewed_at,
        r.memo_number,
        r.memo_series_year,
        r.memo_date,
        r.memo_subject,
        r.memo_body,
        r.memo_final_title,
        ds.defense_date,
        ds.defense_time,
        ds.venue,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
        CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
        CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name,
        CONCAT(p1.firstname, ' ', p1.lastname) AS panel_one_name,
        CONCAT(p2.firstname, ' ', p2.lastname) AS panel_two_name,
        CONCAT(pc.firstname, ' ', pc.lastname) AS chair_name_request
    FROM defense_committee_requests r
    JOIN users stu ON stu.id = r.student_id
    JOIN defense_schedules ds ON ds.id = r.defense_id
    LEFT JOIN users adv ON adv.id = r.adviser_id
    LEFT JOIN users ch ON ch.id = r.chair_id
    LEFT JOIN users p1 ON p1.id = r.panel_member_one_id
    LEFT JOIN users p2 ON p2.id = r.panel_member_two_id
    LEFT JOIN users pc ON pc.id = r.requested_by
    ORDER BY r.requested_at DESC
    LIMIT 20
";
$requestResult = $conn->query($requestSql);
if ($requestResult) {
    $requests = $requestResult->fetch_all(MYSQLI_ASSOC);
    $requestResult->free();
}
$requests = array_map(function ($request) use ($conn) {
    $studentId = (int)($request['student_id'] ?? 0);
    $memoTitle = trim((string)($request['memo_final_title'] ?? ''));
    $request['final_pick_title'] = $memoTitle !== ''
        ? $memoTitle
        : fetch_final_pick_title_for_student($conn, $studentId);
    return $request;
}, $requests);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Defense Committee Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f6f8f5;
            color: #1f2d22;
        }
        .content {
            margin-left: var(--sidebar-width-expanded, 240px);
            transition: margin-left 0.3s ease;
        }
        #sidebar.collapsed ~ .content {
            margin-left: var(--sidebar-width-collapsed, 70px);
        }
        .page-hero {
            background: linear-gradient(120deg, #0f3e1f, #16562c);
            color: #fff;
            border-radius: 20px;
            padding: 28px;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            top: -90px;
            right: -80px;
        }
        .review-card {
            border-radius: 18px;
            border: 1px solid rgba(22, 86, 44, 0.12);
            box-shadow: 0 18px 40px rgba(15, 61, 31, 0.08);
        }
        .review-actions textarea {
            min-height: 80px;
        }
        @media (max-width: 992px) {
            .content {
                margin-left: 0;
            }
            #sidebar.collapsed ~ .content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main class="content dashboard-content" role="main">
    <div class="container-fluid py-4">
        <div class="page-hero mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <div class="badge bg-light text-success mb-2">Dean Review</div>
                    <h1 class="h4 fw-semibold mb-1">Defense Committee Verification</h1>
                    <p class="mb-0 text-white-50">Review and approve committee selections submitted by program chairs.</p>
                </div>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> border-0 shadow-sm">
                <?php echo htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="card review-card">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                <h2 class="h6 fw-semibold mb-1">Committee Requests</h2>
                <span class="text-muted small">Latest 20 requests</span>
            </div>
            <div class="card-body">
                <?php if (empty($requests)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 mb-2"></i>
                        <p class="mb-0">No committee requests to review right now.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Schedule</th>
                                    <th>Committee</th>
                                    <th>Status</th>
                                    <th>Review</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <?php
                                        $status = $request['status'] ?? 'Pending';
                                        $statusClass = defense_committee_status_class($status);
                                        $scheduleLabel = '';
                                        if (!empty($request['defense_date'])) {
                                            $scheduleLabel = date('M d, Y', strtotime($request['defense_date']));
                                        }
                                        if (!empty($request['defense_time'])) {
                                            $scheduleLabel .= $scheduleLabel ? ' • ' . date('g:i A', strtotime($request['defense_time'])) : '';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-success"><?php echo htmlspecialchars($request['student_name'] ?? ''); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($request['chair_name_request'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($scheduleLabel ?: 'TBA'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($request['venue'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <div><strong>Adviser:</strong> <?php echo htmlspecialchars($request['adviser_name'] ?? ''); ?></div>
                                                <div><strong>Chair:</strong> <?php echo htmlspecialchars($request['chair_name'] ?? ''); ?></div>
                                                <div><strong>Panel:</strong> <?php echo htmlspecialchars($request['panel_one_name'] ?? ''); ?>, <?php echo htmlspecialchars($request['panel_two_name'] ?? ''); ?></div>
                                            </div>
                                            <?php if (!empty($request['request_notes'])): ?>
                                                <div class="text-muted small mt-1">
                                                    <i class="bi bi-chat-left-text me-1"></i><?php echo htmlspecialchars($request['request_notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                                            <?php if (!empty($request['review_notes'])): ?>
                                                <div class="text-muted small mt-1">
                                                    <i class="bi bi-chat-square-text me-1"></i><?php echo htmlspecialchars($request['review_notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="review-actions">
                                            <?php if ($status === 'Pending'): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-success btn-sm review-memo-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#reviewMemoModal"
                                                    data-request-id="<?php echo (int)$request['id']; ?>"
                                                    data-student-id="<?php echo (int)$request['student_id']; ?>"
                                                    data-student-name="<?php echo htmlspecialchars($request['student_name'] ?? ''); ?>"
                                                    data-adviser-name="<?php echo htmlspecialchars($request['adviser_name'] ?? ''); ?>"
                                                    data-chair-name="<?php echo htmlspecialchars($request['chair_name'] ?? ''); ?>"
                                                    data-panel-one-name="<?php echo htmlspecialchars($request['panel_one_name'] ?? ''); ?>"
                                                    data-panel-two-name="<?php echo htmlspecialchars($request['panel_two_name'] ?? ''); ?>"
                                                    data-defense-date="<?php echo htmlspecialchars($request['defense_date'] ?? ''); ?>"
                                                    data-defense-time="<?php echo htmlspecialchars($request['defense_time'] ?? ''); ?>"
                                                    data-venue="<?php echo htmlspecialchars($request['venue'] ?? ''); ?>"
                                                    data-final-title="<?php echo htmlspecialchars($request['final_pick_title'] ?? ''); ?>"
                                                    data-memo-number="<?php echo htmlspecialchars($request['memo_number'] ?? ''); ?>"
                                                    data-memo-year="<?php echo htmlspecialchars($request['memo_series_year'] ?? ''); ?>"
                                                    data-memo-date="<?php echo htmlspecialchars($request['memo_date'] ?? ''); ?>"
                                                    data-memo-subject="<?php echo htmlspecialchars($request['memo_subject'] ?? ''); ?>"
                                                    data-memo-body="<?php echo htmlspecialchars($request['memo_body'] ?? ''); ?>"
                                                >
                                                    Review &amp; Prepare Memo
                                                </button>
                                            <?php else: ?>
                                                <div class="d-flex flex-column gap-1">
                                                    <div class="text-muted small">Reviewed</div>
                                                    <?php if (!empty($request['memo_body'])): ?>
                                                        <a class="btn btn-outline-secondary btn-sm" href="defense_committee_memo.php?request_id=<?php echo (int)$request['id']; ?>" target="_blank" rel="noopener">
                                                            View Memo
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="reviewMemoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success">Defense Committee Memo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="review_committee_request" value="1">
                <input type="hidden" name="request_id" id="memoRequestId">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Student</label>
                        <input type="text" class="form-control" id="memoStudentName" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">Final Pick Title</label>
                        <input type="text" class="form-control" id="memoFinalTitle" readonly>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label text-muted small">Memo No.</label>
                        <input type="text" class="form-control" name="memo_number" id="memoNumberInput" placeholder="e.g., 01">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted small">Series of</label>
                        <input type="text" class="form-control" name="memo_series_year" id="memoSeriesYearInput" placeholder="e.g., 2025">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted small">Date</label>
                        <input type="date" class="form-control" name="memo_date" id="memoDateInput">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small">Subject</label>
                    <input type="text" class="form-control" name="memo_subject" id="memoSubjectInput">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Memo Body</label>
                    <textarea class="form-control" name="memo_body" id="memoBodyTextarea" rows="14" placeholder="Edit committee names and memo details before approval."></textarea>
                    <div class="form-text">You can edit committee names and details before approving.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small">Dean Notes (optional)</label>
                    <textarea class="form-control" name="review_notes" id="memoReviewNotes" rows="3" placeholder="Optional notes for the program chairperson."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="decision" value="Rejected" class="btn btn-outline-danger">Reject</button>
                <button type="submit" name="decision" value="Approved" class="btn btn-success">Approve &amp; Send Memo</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (() => {
        const modal = document.getElementById('reviewMemoModal');
        if (!modal) {
            return;
        }

        const memoNumberInput = modal.querySelector('#memoNumberInput');
        const memoSeriesYearInput = modal.querySelector('#memoSeriesYearInput');
        const memoDateInput = modal.querySelector('#memoDateInput');
        const memoSubjectInput = modal.querySelector('#memoSubjectInput');
        const memoBodyTextarea = modal.querySelector('#memoBodyTextarea');
        const memoRequestId = modal.querySelector('#memoRequestId');
        const memoStudentName = modal.querySelector('#memoStudentName');
        const memoFinalTitle = modal.querySelector('#memoFinalTitle');
        const memoReviewNotes = modal.querySelector('#memoReviewNotes');

        const formatDate = (value) => {
            if (!value) {
                return '';
            }
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return value;
            }
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        };

        const formatTime = (value) => {
            if (!value) {
                return '';
            }
            const parts = value.split(':');
            if (parts.length < 2) {
                return value;
            }
            const date = new Date();
            date.setHours(Number(parts[0]), Number(parts[1]), 0, 0);
            return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        };

        const buildMemoTemplate = (payload) => {
            const {
                studentName,
                finalTitle,
                adviserName,
                chairName,
                panelOneName,
                panelTwoName,
                defenseDate,
                defenseTime,
                venue,
                memoNumber,
                seriesYear,
                memoDate,
                deanName
            } = payload;

            const memoDateFormatted = formatDate(memoDate);
            const scheduleDate = formatDate(defenseDate);
            const scheduleTime = formatTime(defenseTime);
            const titleLine = finalTitle ? `the thesis titled "${finalTitle}"` : 'the outline defense';
            const deanLine = deanName ? `${deanName}\nDean, Institute of Advanced Studies` : 'Dean, Institute of Advanced Studies';

            return [
                'OFFICE OF THE DEAN',
                `Memorandum No. ${memoNumber || '___'}`,
                `Series of ${seriesYear || new Date().getFullYear()}`,
                '',
                'To:',
                `${adviserName || 'Adviser Name'}, Thesis Adviser`,
                `${chairName || 'Committee Chair'}, TAC Chairperson`,
                `${panelOneName || 'Panel Member'}, TAC Member`,
                `${panelTwoName || 'Panel Member'}, TAC Member`,
                '',
                `Date: ${memoDateFormatted || '__________'}`,
                '',
                `Subject: ${memoSubjectInput.value || 'OUTLINE DEFENSE'}`,
                '',
                `We are pleased to inform you that the candidate has applied for Outline Defense for ${titleLine}. With this, we kindly request your attendance as panel member to assist the student.`,
                '',
                'The thesis panel will consist of the following members:',
                `${adviserName || 'Adviser Name'}, Thesis Adviser`,
                `${chairName || 'Committee Chair'}, TAC Chairperson`,
                `${panelOneName || 'Panel Member'}, TAC Member`,
                `${panelTwoName || 'Panel Member'}, TAC Member`,
                '',
                'The Outline Defense will be held as follows:',
                `Student: ${studentName || 'Student'}`,
                `Date: ${scheduleDate || 'TBA'}`,
                `Time: ${scheduleTime || 'TBA'}`,
                `Location: ${venue || 'TBA'}`,
                '',
                'Your expertise and insights will be valuable in ensuring a thorough evaluation of the candidate’s research work.',
                '',
                'Thank you for your time and confirmation. We truly appreciate your continued support.',
                '',
                deanLine
            ].join('\n');
        };

        modal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            if (!trigger) {
                return;
            }
            const dataset = trigger.dataset;
            const memoDateValue = dataset.memoDate || new Date().toISOString().split('T')[0];
            const seriesYearValue = dataset.memoYear || String(new Date().getFullYear());

            memoRequestId.value = dataset.requestId || '';
            memoStudentName.value = dataset.studentName || '';
            memoFinalTitle.value = dataset.finalTitle || '';
            memoNumberInput.value = dataset.memoNumber || '';
            memoSeriesYearInput.value = seriesYearValue;
            memoDateInput.value = memoDateValue;
            memoSubjectInput.value = dataset.memoSubject || 'OUTLINE DEFENSE';
            memoReviewNotes.value = '';

            if (dataset.memoBody) {
                memoBodyTextarea.value = dataset.memoBody;
                return;
            }

            memoBodyTextarea.value = buildMemoTemplate({
                studentName: dataset.studentName || '',
                finalTitle: dataset.finalTitle || '',
                adviserName: dataset.adviserName || '',
                chairName: dataset.chairName || '',
                panelOneName: dataset.panelOneName || '',
                panelTwoName: dataset.panelTwoName || '',
                defenseDate: dataset.defenseDate || '',
                defenseTime: dataset.defenseTime || '',
                venue: dataset.venue || '',
                memoNumber: dataset.memoNumber || '',
                seriesYear: seriesYearValue,
                memoDate: memoDateValue,
                deanName: <?php echo json_encode($deanName); ?>
            });
        });

    })();
</script>
</body>
</html>
