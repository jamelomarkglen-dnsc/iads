<?php
session_start();
include 'db.php';
require_once 'notifications_helper.php';
require_once 'submission_helpers.php';
require_once 'role_helpers.php';
require_once 'final_paper_helpers.php';
require_once 'defense_committee_helpers.php';

enforce_role_access(['student']);

$studentId = (int)$_SESSION['user_id'];
ensureFinalPaperTables($conn);
ensureDefenseCommitteeRequestsTable($conn);
ensureDefensePanelMemberColumns($conn);

function fetchStatusTimeline(mysqli $conn, int $studentId): array
{
    $sql = "
        SELECT
            l.id,
            l.submission_id,
            l.old_status,
            l.new_status,
            l.changed_at,
            s.title,
            s.type,
            COALESCE(u.firstname, '') AS updater_firstname,
            COALESCE(u.lastname, '') AS updater_lastname,
            COALESCE(u.email, '') AS updater_email,
            COALESCE(u.role, '') AS updater_role
        FROM status_logs l
        JOIN submissions s ON l.submission_id = s.id
        LEFT JOIN users u ON u.id = l.updated_by
        WHERE s.student_id = ?
        ORDER BY l.changed_at DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $studentId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $tableEscaped = $conn->real_escape_string($table);
    $columnEscaped = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$tableEscaped}'
          AND COLUMN_NAME = '{$columnEscaped}'
        LIMIT 1
    ";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

function statusBadgeClass(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        'badge bg-success-subtle text-success' => ['approved', 'accepted', 'completed', 'passed'],
        'badge bg-warning-subtle text-warning' => [
            'pending',
            'pending approval',
            'submitted',
            'for review',
            'under review',
            'in review',
            'assign reviewer',
            'assigning reviewer',
            'for approval',
            'awaiting review'
        ],
        'badge bg-danger-subtle text-danger'  => ['for revision', 'needs revision', 'revision required', 'revision'],
        'badge bg-secondary-subtle text-secondary' => ['draft', 'withdrawn'],
    ];
    foreach ($map as $class => $values) {
        if (in_array($status, $values, true)) {
            return $class;
        }
    }
    return 'badge bg-secondary-subtle text-secondary';
}

function formatTimestamp(?string $timestamp, string $fallback = 'N/A'): string
{
    if (!$timestamp) {
        return $fallback;
    }
    try {
        $date = new DateTime($timestamp);
    } catch (Exception $e) {
        return $fallback;
    }
    return $date->format('M d, Y') . ' &bull; ' . $date->format('g:i A');
}

$hasProgramColumn = columnExists($conn, 'users', 'program');
$hasYearLevelColumn = columnExists($conn, 'users', 'year_level');
$hasStudentIdColumn = columnExists($conn, 'users', 'student_id');
$hasContactColumn = columnExists($conn, 'users', 'contact');

$studentInfoColumns = ['firstname', 'lastname', 'email'];
if ($hasProgramColumn) {
    $studentInfoColumns[] = 'program';
}
if ($hasYearLevelColumn) {
    $studentInfoColumns[] = 'year_level';
}
if ($hasStudentIdColumn) {
    $studentInfoColumns[] = 'student_id';
}
if ($hasContactColumn) {
    $studentInfoColumns[] = 'contact';
}

$studentInfo = array_fill_keys($studentInfoColumns, '');
$infoSql = "SELECT " . implode(', ', $studentInfoColumns) . " FROM users WHERE id = ? LIMIT 1";
if ($infoStmt = $conn->prepare($infoSql)) {
    $infoStmt->bind_param('i', $studentId);
    $infoStmt->execute();
    $row = $infoStmt->get_result()->fetch_assoc();
    if ($row) {
        $studentInfo = array_merge($studentInfo, $row);
    }
    $infoStmt->close();
}

$advisorSql = "
    SELECT adv.id, adv.firstname, adv.lastname, adv.email
    FROM users stu
    LEFT JOIN users adv ON adv.id = stu.adviser_id
    WHERE stu.id = ?
    LIMIT 1
";
$advisor = null;
if ($advisorStmt = $conn->prepare($advisorSql)) {
    $advisorStmt->bind_param('i', $studentId);
    $advisorStmt->execute();
    $advisor = $advisorStmt->get_result()->fetch_assoc();
    $advisorStmt->close();
}

if (!$advisor || empty($advisor['id'])) {
    $fallbackSql = "
        SELECT adv.id, adv.firstname, adv.lastname, adv.email
        FROM users stu
        LEFT JOIN users adv ON adv.id = stu.advisor_id
        WHERE stu.id = ?
        LIMIT 1
    ";
    if ($advisorFallbackStmt = $conn->prepare($fallbackSql)) {
        $advisorFallbackStmt->bind_param('i', $studentId);
        $advisorFallbackStmt->execute();
        $fallbackAdvisor = $advisorFallbackStmt->get_result()->fetch_assoc();
        $advisorFallbackStmt->close();
        if ($fallbackAdvisor && !empty($fallbackAdvisor['id'])) {
            $advisor = $fallbackAdvisor;
        }
    }
}

$hasAdvisor = !empty($advisor['id']);

$submissionsTableExists = columnExists($conn, 'submissions', 'id');
$submissionHasStatus = $submissionsTableExists ? columnExists($conn, 'submissions', 'status') : false;
$submissionHasUpdatedAt = $submissionsTableExists ? columnExists($conn, 'submissions', 'updated_at') : false;
$submissionHasFile = $submissionsTableExists ? columnExists($conn, 'submissions', 'file_path') : false;
$submissionHasType = $submissionsTableExists ? columnExists($conn, 'submissions', 'type') : false;

$submissionColumns = ['id', 'title', 'created_at'];
if ($submissionHasType) {
    $submissionColumns[] = 'type';
}
if ($submissionHasStatus) {
    $submissionColumns[] = 'status';
}
if ($submissionHasFile) {
    $submissionColumns[] = 'file_path';
}
if ($submissionHasUpdatedAt) {
    $submissionColumns[] = 'updated_at';
}

$studentSubmissions = [];
if ($submissionsTableExists) {
    $submissionSql = "SELECT " . implode(', ', $submissionColumns) . " FROM submissions WHERE student_id = ? ORDER BY created_at DESC";
    if ($submissionStmt = $conn->prepare($submissionSql)) {
        $submissionStmt->bind_param('i', $studentId);
        $submissionStmt->execute();
        $result = $submissionStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $studentSubmissions[] = $row;
        }
        $submissionStmt->close();
    }
}

$totalSubmissions = count($studentSubmissions);
$pendingSubmissions = 0;
$revisionRequired = 0;
$approvedSubmissions = 0;

if ($submissionHasStatus) {
$pendingStatuses = [
    'pending',
    'pending approval',
    'submitted',
    'for review',
    'under review',
    'in review',
    'reviewing',
    'assigning reviewer',
    'reviewer assigning',
    'assign reviewer',
    'for approval',
    'awaiting review'
];
    $revisionStatuses = ['for revision', 'needs revision', 'revision required', 'revision'];
    $approvedStatuses = ['approved', 'accepted', 'completed', 'passed'];

    foreach ($studentSubmissions as $submission) {
        $status = strtolower((string)($submission['status'] ?? ''));
        if (in_array($status, $pendingStatuses, true)) {
            $pendingSubmissions++;
        } elseif (in_array($status, $revisionStatuses, true)) {
            $revisionRequired++;
        } elseif (in_array($status, $approvedStatuses, true)) {
            $approvedSubmissions++;
        }
    }
}

$latestSubmission = $studentSubmissions[0] ?? null;
$progressPercent = $totalSubmissions > 0 ? (int)round(($approvedSubmissions / $totalSubmissions) * 100) : 0;
$inReviewPercent = $totalSubmissions > 0 ? (int)round(($pendingSubmissions / $totalSubmissions) * 100) : 0;
$needsWorkPercent = $totalSubmissions > 0 ? (int)round(($revisionRequired / $totalSubmissions) * 100) : 0;
$progressPercent = min(100, max(0, $progressPercent));
$inReviewPercent = min(100, max(0, $inReviewPercent));
$needsWorkPercent = min(100, max(0, $needsWorkPercent));
$othersPercent = $totalSubmissions > 0 ? max(0, 100 - min(100, $progressPercent + $inReviewPercent + $needsWorkPercent)) : 0;

$unreadNotifications = count_unread_notifications($conn, $studentId, 'student');
$recentNotifications = fetch_user_notifications($conn, $studentId, 'student', 5);
$finalPickMessages = array_values(array_filter(
    $recentNotifications,
    static function ($notification): bool {
        return isset($notification['title']) && $notification['title'] === 'Final concept recommendation';
    }
));
$latestFinalPickMessage = $finalPickMessages[0] ?? null;
if (function_exists('notifications_bootstrap')) {
    notifications_bootstrap($conn);
}
$finalPickMessageStmt = $conn->prepare("
    SELECT id, title, message, created_at
    FROM notifications
    WHERE user_id = ? AND title = 'Final concept recommendation'
    ORDER BY created_at DESC
    LIMIT 1
");
if ($finalPickMessageStmt) {
    $finalPickMessageStmt->bind_param('i', $studentId);
    if ($finalPickMessageStmt->execute()) {
        $finalPickMessageResult = $finalPickMessageStmt->get_result();
        $finalPickMessageRow = $finalPickMessageResult ? $finalPickMessageResult->fetch_assoc() : null;
        if ($finalPickMessageRow) {
            $latestFinalPickMessage = $finalPickMessageRow;
        }
    }
    $finalPickMessageStmt->close();
}
$chairFeedbackFeed = [];
$chairFeedbackSql = "
    SELECT
        cr.id,
        cr.concept_paper_id,
        COALESCE(cp.title, 'Untitled Concept') AS concept_title,
        cr.rank_order,
        cr.chair_feedback,
        cr.chair_feedback_at,
        CONCAT(COALESCE(pc.firstname,''), ' ', COALESCE(pc.lastname,'')) AS chair_name,
        CONCAT(COALESCE(r.firstname,''), ' ', COALESCE(r.lastname,'')) AS adviser_name
    FROM concept_reviews cr
    INNER JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
    LEFT JOIN concept_papers cp ON cp.id = cr.concept_paper_id
    LEFT JOIN users pc ON pc.id = cr.chair_feedback_by
    LEFT JOIN users r ON r.id = cra.reviewer_id
    WHERE cra.student_id = ?
      AND cr.chair_feedback IS NOT NULL
    ORDER BY cr.chair_feedback_at DESC
    LIMIT 5
";
$chairStmt = $conn->prepare($chairFeedbackSql);
if ($chairStmt) {
    $chairStmt->bind_param('i', $studentId);
    $chairStmt->execute();
    $chairResult = $chairStmt->get_result();
    if ($chairResult) {
        while ($row = $chairResult->fetch_assoc()) {
            $chairFeedbackFeed[] = $row;
        }
        $chairResult->free();
    }
    $chairStmt->close();
}

$finalPick = null;
$finalPickTie = false;
$finalPickRows = [];
$finalPickSql = "
    SELECT
        cp.id AS concept_id,
        cp.title,
        cp.created_at,
        SUM(CASE WHEN cr.rank_order = 1 THEN 1 ELSE 0 END) AS rank_one_votes,
        SUM(CASE WHEN cr.rank_order = 2 THEN 1 ELSE 0 END) AS rank_two_votes,
        SUM(CASE WHEN cr.rank_order = 3 THEN 1 ELSE 0 END) AS rank_three_votes,
        MAX(cr.updated_at) AS last_ranked_at
    FROM concept_reviews cr
    INNER JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
    INNER JOIN concept_papers cp ON cp.id = cr.concept_paper_id
    WHERE cra.student_id = ?
      AND cr.rank_order IS NOT NULL
    GROUP BY cp.id, cp.title, cp.created_at
    HAVING (rank_one_votes > 0 OR rank_two_votes > 0 OR rank_three_votes > 0)
    ORDER BY rank_one_votes DESC, rank_two_votes DESC, rank_three_votes DESC, cp.created_at DESC
    LIMIT 2
";
$finalPickStmt = $conn->prepare($finalPickSql);
if ($finalPickStmt) {
    $finalPickStmt->bind_param('i', $studentId);
    $finalPickStmt->execute();
    $finalPickResult = $finalPickStmt->get_result();
    if ($finalPickResult) {
        while ($row = $finalPickResult->fetch_assoc()) {
            $finalPickRows[] = $row;
        }
        $finalPickResult->free();
    }
    $finalPickStmt->close();
}

if (!empty($finalPickRows)) {
    $finalPick = $finalPickRows[0];
    if (isset($finalPickRows[1])) {
        $topVotes = (int)($finalPickRows[0]['rank_one_votes'] ?? 0);
        $secondVotes = (int)($finalPickRows[1]['rank_one_votes'] ?? 0);
        $finalPickTie = $topVotes > 0 && $topVotes === $secondVotes;
    }
}

$finalSubmission = null;
$finalSubmissionTitle = '';
$finalPickStatusLabel = 'Not submitted';
$hasFinalSubmissionTable = columnExists($conn, 'final_concept_submissions', 'id');
if ($hasFinalSubmissionTable) {
    $finalSubmissionSql = "
        SELECT final_title, status, submitted_at, reviewed_at
        FROM final_concept_submissions
        WHERE student_id = ?
        ORDER BY submitted_at DESC
        LIMIT 1
    ";
    if ($finalSubmissionStmt = $conn->prepare($finalSubmissionSql)) {
        $finalSubmissionStmt->bind_param('i', $studentId);
        $finalSubmissionStmt->execute();
        $finalSubmission = $finalSubmissionStmt->get_result()->fetch_assoc();
        $finalSubmissionStmt->close();
    }
    if ($finalSubmission) {
        $finalSubmissionTitle = trim((string)($finalSubmission['final_title'] ?? ''));
        $finalPickStatusLabel = trim((string)($finalSubmission['status'] ?? ''));
        if ($finalPickStatusLabel === '') {
            $finalPickStatusLabel = 'Submitted';
        }
    }
}
$finalPickStatusClass = statusBadgeClass($finalPickStatusLabel);
$finalPickStatusDisplay = ucwords(strtolower($finalPickStatusLabel));

$committeeRequest = null;
$committeeStatusLabel = 'Not requested';
$committeeStatusClass = 'badge bg-secondary-subtle text-secondary';
$committeeScheduleLabel = 'TBA';
$committeeVenueLabel = '';
$committeeReviewNotes = '';
$committeeMemoTitle = '';
$committeeMemoReceivedAt = null;
$committeeMemoAvailable = false;
$committeeMembers = [
    'adviser' => '',
    'chair' => '',
    'panel' => '',
];
$committeeSql = "
    SELECT
        r.status,
        r.review_notes,
        r.requested_at,
        r.reviewed_at,
        r.memo_final_title,
        r.memo_received_at,
        r.memo_body,
        ds.defense_date,
        ds.defense_time,
        ds.venue,
        CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
        CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name,
        CONCAT(p1.firstname, ' ', p1.lastname) AS panel_one_name,
        CONCAT(p2.firstname, ' ', p2.lastname) AS panel_two_name
    FROM defense_committee_requests r
    JOIN defense_schedules ds ON ds.id = r.defense_id
    LEFT JOIN users adv ON adv.id = r.adviser_id
    LEFT JOIN users ch ON ch.id = r.chair_id
    LEFT JOIN users p1 ON p1.id = r.panel_member_one_id
    LEFT JOIN users p2 ON p2.id = r.panel_member_two_id
    WHERE r.student_id = ?
    ORDER BY r.reviewed_at DESC, r.requested_at DESC
    LIMIT 1
";
$committeeStmt = $conn->prepare($committeeSql);
if ($committeeStmt) {
    $committeeStmt->bind_param('i', $studentId);
    $committeeStmt->execute();
    $committeeRequest = $committeeStmt->get_result()->fetch_assoc();
    $committeeStmt->close();
}
if ($committeeRequest) {
    $committeeStatusLabel = trim((string)($committeeRequest['status'] ?? '')) ?: 'Pending';
    $committeeStatusClass = function_exists('defense_committee_status_class')
        ? defense_committee_status_class($committeeStatusLabel)
        : statusBadgeClass($committeeStatusLabel);
    $committeeDate = trim((string)($committeeRequest['defense_date'] ?? ''));
    $committeeTime = trim((string)($committeeRequest['defense_time'] ?? ''));
    if ($committeeDate !== '' && $committeeDate !== '0000-00-00') {
        $committeeScheduleLabel = date('M d, Y', strtotime($committeeDate));
        if ($committeeTime !== '' && $committeeTime !== '00:00:00') {
            $committeeScheduleLabel .= ' &bull; ' . date('g:i A', strtotime($committeeTime));
        }
    }
    $committeeVenueLabel = trim((string)($committeeRequest['venue'] ?? ''));
    $committeeReviewNotes = trim((string)($committeeRequest['review_notes'] ?? ''));
    $committeeMemoTitle = trim((string)($committeeRequest['memo_final_title'] ?? ''));
    $committeeMemoReceivedAt = $committeeRequest['memo_received_at'] ?? null;
    $committeeMemoAvailable = $committeeStatusLabel === 'Approved'
        && trim((string)($committeeRequest['memo_body'] ?? '')) !== '';
    $committeeMembers['adviser'] = trim((string)($committeeRequest['adviser_name'] ?? ''));
    $committeeMembers['chair'] = trim((string)($committeeRequest['chair_name'] ?? ''));
    $panelNames = array_filter([
        trim((string)($committeeRequest['panel_one_name'] ?? '')),
        trim((string)($committeeRequest['panel_two_name'] ?? '')),
    ]);
    $committeeMembers['panel'] = $panelNames ? implode(', ', $panelNames) : '';
}

$finalPaperSubmission = fetchLatestFinalPaperSubmission($conn, $studentId);
$finalPaperStatusLabel = 'Not submitted';
$finalPaperStatusBadgeClass = 'badge bg-secondary-subtle text-secondary';
$finalPaperTitle = '';
$finalPaperVersion = 0;
$finalPaperSubmittedAt = null;
if ($finalPaperSubmission) {
    $finalPaperStatusLabel = trim((string)($finalPaperSubmission['status'] ?? ''));
    if ($finalPaperStatusLabel === '') {
        $finalPaperStatusLabel = 'Submitted';
    }
    $finalPaperStatusBadgeClass = 'badge ' . finalPaperStatusClass($finalPaperStatusLabel);
    $finalPaperTitle = trim((string)($finalPaperSubmission['final_title'] ?? ''));
    $finalPaperVersion = (int)($finalPaperSubmission['version'] ?? 1);
    $finalPaperSubmittedAt = $finalPaperSubmission['submitted_at'] ?? null;
}

$submissionFeedbackFeed = fetch_submission_feedback_for_student($conn, $studentId, 5);

$defenseSchedules = [];
$defenseSql = "
    SELECT id, defense_date, defense_time, venue, status
    FROM defense_schedules
    WHERE student_id = ?
    ORDER BY defense_date ASC, defense_time ASC
    LIMIT 3
";
if ($defenseStmt = $conn->prepare($defenseSql)) {
    $defenseStmt->bind_param('i', $studentId);
    $defenseStmt->execute();
    $result = $defenseStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $defenseSchedules[] = $row;
    }
    $defenseStmt->close();
}
$nextDefense = $defenseSchedules[0] ?? null;

$statusTimeline = fetchStatusTimeline($conn, $studentId);

$studentFullName = trim(($studentInfo['firstname'] ?? '') . ' ' . ($studentInfo['lastname'] ?? ''));
if ($studentFullName === '') {
    $studentFullName = 'Student';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f4f8f4;
            color: #1d3522;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        .content {
            margin-left: var(--sidebar-width-expanded, 240px);
            transition: margin-left 0.3s ease;
            padding: 28px 24px;
            min-height: 100vh;
        }
        #sidebar.collapsed ~ .content {
            margin-left: var(--sidebar-width-collapsed, 70px);
        }
        .hero-card {
            border-radius: 1.5rem;
            background: linear-gradient(135deg, rgba(22, 86, 44, 0.95), rgba(12, 51, 26, 0.9));
            color: #fff;
            padding: 32px;
            position: relative;
            overflow: hidden;
        }
        .hero-card::after {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
        }
        .stat-card {
            border-radius: 1.2rem;
            border: 1px solid rgba(22, 86, 44, 0.08);
            background: #fff;
            box-shadow: 0 15px 25px rgba(22, 86, 44, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 35px rgba(22, 86, 44, 0.12);
        }
        .stat-card .icon-pill {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.4rem;
        }
        .card {
            border-radius: 1.25rem;
            border: 1px solid rgba(22, 86, 44, 0.08);
            background: #fff;
        }
        .card-header {
            border-top-left-radius: 1.25rem;
            border-top-right-radius: 1.25rem;
            border-bottom: 1px solid rgba(22, 86, 44, 0.12);
            background: #fff;
        }
        .card-header h5 {
            font-size: 1rem;
            font-weight: 600;
            color: #16562c;
        }
        .final-pick-message {
            border: 1px solid rgba(22, 86, 44, 0.28);
            background: #f6fbf7;
            border-radius: 1rem;
            padding: 16px 18px;
        }
        .final-pick-message .message-title {
            color: #0f6b35;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .quick-action-list li + li {
            border-top: 1px solid rgba(22, 86, 44, 0.08);
        }
        .quick-action-list a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
            padding: 0.85rem 0;
            transition: transform 0.1s ease;
        }
        .quick-action-list a:hover {
            transform: translateX(4px);
            color: #16562c;
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

<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                <div>
                    <div class="badge bg-light text-success mb-2 fw-semibold">Student Portal</div>
                    <h1 class="h3 fw-semibold mb-1">Hello, <?php echo htmlspecialchars($studentFullName); ?>!</h1>
                    <p class="mb-0 text-white-50">
                        Track your submissions, stay updated with notifications, and coordinate with your adviser.
                    </p>
                    <?php if ($latestSubmission): ?>
                        <div class="mt-3 small">
                            <span class="text-white-50">Latest submission:</span>
                            <strong><?php echo htmlspecialchars($latestSubmission['title'] ?? 'Untitled'); ?></strong>
                            <?php if ($submissionHasStatus && !empty($latestSubmission['status'])): ?>
                                <span class="badge bg-light text-success ms-2 text-capitalize"><?php echo htmlspecialchars($latestSubmission['status']); ?></span>
                            <?php endif; ?>
                            <div class="text-white-50">
                                <?php echo formatTimestamp($latestSubmission['created_at'] ?? null, 'Not yet submitted'); ?>
                                <?php if ($submissionHasType && !empty($latestSubmission['type'])): ?>
                                    &bull; <?php echo htmlspecialchars($latestSubmission['type']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-md-end mt-3 mt-md-0">
                    <?php if ($hasStudentIdColumn && !empty($studentInfo['student_id'])): ?>
                        <div class="badge bg-light text-success mb-2">Student ID: <?php echo htmlspecialchars($studentInfo['student_id']); ?></div>
                    <?php endif; ?>
                    <?php if ($hasProgramColumn && !empty($studentInfo['program'])): ?>
                        <div class="badge bg-success-subtle text-success">Program: <?php echo htmlspecialchars($studentInfo['program']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="icon-pill bg-primary-subtle text-primary"><i class="bi bi-file-earmark-text"></i></div>
                        <span class="badge bg-primary-subtle text-primary">All Time</span>
                    </div>
                    <h6 class="text-uppercase text-muted small mb-1">Total Submissions</h6>
                    <h2 class="fw-bold text-primary mb-1"><?php echo number_format($totalSubmissions); ?></h2>
                    <p class="text-muted small mb-0">Concept papers you have submitted.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="icon-pill bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                        <span class="badge bg-warning-subtle text-warning">Awaiting</span>
                    </div>
                    <h6 class="text-uppercase text-muted small mb-1">Pending Reviews</h6>
                    <h2 class="fw-bold text-warning mb-1"><?php echo number_format($pendingSubmissions); ?></h2>
                    <p class="text-muted small mb-0">Submissions still awaiting panel feedback.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="icon-pill bg-danger-subtle text-danger"><i class="bi bi-exclamation-circle"></i></div>
                        <span class="badge bg-danger-subtle text-danger">Action</span>
                    </div>
                    <h6 class="text-uppercase text-muted small mb-1">Needs Revision</h6>
                    <h2 class="fw-bold text-danger mb-1"><?php echo number_format($revisionRequired); ?></h2>
                    <p class="text-muted small mb-0">Items requiring your updates.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="icon-pill bg-success-subtle text-success"><i class="bi bi-bell"></i></div>
                        <span class="badge bg-success-subtle text-success">Inbox</span>
                    </div>
                    <h6 class="text-uppercase text-muted small mb-1">Unread Notifications</h6>
                    <h2 class="fw-bold text-success mb-1"><?php echo number_format($unreadNotifications); ?></h2>
                    <p class="text-muted small mb-0">Alerts from faculty and administrators.</p>
                </div>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Submission Feedback</h5>
                        <?php if (!empty($submissionFeedbackFeed)): ?>
                            <span class="badge bg-success-subtle text-success"><?php echo count($submissionFeedbackFeed); ?> notes</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($submissionFeedbackFeed)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-inbox fs-1 mb-2"></i>
                                <p class="mb-0">No notes from the Program Chair yet.</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($submissionFeedbackFeed as $feedback): ?>
                                    <?php
                                        $submissionTitle = trim((string)($feedback['submission_title'] ?? 'Concept Submission'));
                                        $chairName = trim((string)($feedback['chair_name'] ?? 'Program Chair'));
                                        $feedbackTimestamp = formatTimestamp($feedback['created_at'] ?? null, 'Awaiting post');
                                    ?>
                                    <li class="mb-3 pb-3 border-bottom border-light">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-semibold text-success mb-1">
                                                    <?php echo htmlspecialchars($submissionTitle); ?>
                                                </div>
                                                <p class="mb-2 text-dark">
                                                    <?php echo nl2br(htmlspecialchars($feedback['message'] ?? '')); ?>
                                                </p>
                                                <div class="text-muted small">
                                                    <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($chairName); ?>
                                                </div>
                                            </div>
                                            <div class="text-end small text-muted">
                                                <i class="bi bi-clock me-1"></i><?php echo $feedbackTimestamp; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Quick Actions</h5>
                          <a href="student_activity_log.php" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-clock-history me-1"></i> View Activity Log
                        </a>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled quick-action-list mb-0">
                            <li>
                                <a href="submit_paper.php">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-upload text-success"></i> Submit a Concept Paper
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <li>
                                <a href="view_defense_schedule.php">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-calendar-event text-primary"></i> View Defense Schedule
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <li>
                                <a href="proof_of_payment.php">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-credit-card text-warning"></i> Submit Payment Proof
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <li>
                                <a href="student_messages.php">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-chat-dots text-info"></i> Message Adviser
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <li>
                                <a href="notifications.php">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-bell text-danger"></i> Manage Notifications
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Program Chair Feedback</h5>
                        <?php if (!empty($chairFeedbackFeed)): ?>
                            <span class="badge bg-success-subtle text-success"><?php echo count($chairFeedbackFeed); ?> updates</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($chairFeedbackFeed)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-chat-square-text fs-1 mb-2"></i>
                                <p class="mb-0">You haven't received feedback from the Program Chair yet.</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($chairFeedbackFeed as $feedback): ?>
                                    <?php
                                        $rankText = $feedback['rank_order'] ? 'Rank #' . (int)$feedback['rank_order'] : 'Rank pending';
                                        $adviserDisplay = trim((string)($feedback['adviser_name'] ?? '')) ?: 'Adviser not set';
                                        $chairName = trim((string)($feedback['chair_name'] ?? 'Program Chair'));
                                    ?>
                                    <li class="mb-3 pb-3 border-bottom border-light">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-semibold text-success mb-1">
                                                    <?php echo htmlspecialchars($feedback['concept_title']); ?>
                                                </div>
                                                <div class="small text-muted mb-2">
                                                    Adviser: <?php echo htmlspecialchars($adviserDisplay); ?> &middot;
                                                    <?php echo htmlspecialchars($rankText); ?>
                                                </div>
                                                <p class="mb-0 text-dark">
                                                    <?php echo nl2br(htmlspecialchars($feedback['chair_feedback'] ?? '')); ?>
                                                </p>
                                                <div class="text-muted small mt-2">
                                                    <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($chairName); ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($feedback['concept_paper_id'])): ?>
                                                <a href="view_concept.php?id=<?php echo (int)$feedback['concept_paper_id']; ?>" class="btn btn-sm btn-outline-success">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small mt-2">
                                            <i class="bi bi-clock me-1"></i>
                                            <?php echo formatTimestamp($feedback['chair_feedback_at'] ?? null, 'Awaiting send'); ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Adviser</h5>
                        <?php if ($hasAdvisor): ?>
                            <span class="badge bg-success-subtle text-success">Linked</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning">Unassigned</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($hasAdvisor): ?>
                            <h6 class="fw-semibold text-success mb-1"><?php echo htmlspecialchars(($advisor['firstname'] ?? '') . ' ' . ($advisor['lastname'] ?? '')); ?></h6>
                            <p class="text-muted mb-3">
                                <i class="bi bi-envelope me-1 text-success"></i>
                                <a href="mailto:<?php echo htmlspecialchars($advisor['email'] ?? ''); ?>"><?php echo htmlspecialchars($advisor['email'] ?? ''); ?></a>
                            </p>
                            <a class="btn btn-outline-success btn-sm" href="student_messages.php"><i class="bi bi-chat-dots me-1"></i>Open Messages</a>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-person-plus fs-1 mb-2"></i>
                                <p class="mb-0">No adviser is linked to your account yet. Please coordinate with the program chair.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Final Pick Recommendation</h5>
                        <?php if ($finalPick): ?>
                            <span class="badge bg-success-subtle text-success">Confirmed</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning">Pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!$finalPick): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-flag fs-1 mb-2"></i>
                                <p class="mb-0">Final pick will appear once reviewers submit rankings.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div>
                                    <div class="text-uppercase small text-muted">Recommended Title</div>
                                    <div class="fw-semibold text-success mb-2"><?php echo htmlspecialchars($finalPick['title'] ?? ''); ?></div>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <span class="badge bg-success-subtle text-success">R1: <?php echo number_format((int)($finalPick['rank_one_votes'] ?? 0)); ?></span>
                                        <span class="badge bg-info-subtle text-info">R2: <?php echo number_format((int)($finalPick['rank_two_votes'] ?? 0)); ?></span>
                                        <span class="badge bg-secondary-subtle text-secondary">R3: <?php echo number_format((int)($finalPick['rank_three_votes'] ?? 0)); ?></span>
                                        <?php if ($finalPickTie): ?>
                                            <span class="badge bg-warning-subtle text-warning">Tie on Rank 1</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="text-muted small mb-1">Final submission status</div>
                                    <span class="<?php echo $finalPickStatusClass; ?> text-capitalize"><?php echo htmlspecialchars($finalPickStatusDisplay); ?></span>
                                    <?php if ($finalSubmissionTitle !== ''): ?>
                                        <div class="text-muted small mt-2">Submitted title: <?php echo htmlspecialchars($finalSubmissionTitle); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($latestFinalPickMessage['message'])): ?>
                                <div class="final-pick-message mt-3">
                                    <div class="message-title">Message from the Program Chairperson</div>
                                    <div class="text-success"><?php echo nl2br(htmlspecialchars($latestFinalPickMessage['message'])); ?></div>
                                    <div class="text-muted small mt-2">
                                        <i class="bi bi-clock me-1"></i><?php echo formatTimestamp($latestFinalPickMessage['created_at'] ?? null, 'Just now'); ?>
                                    </div>
                                </div>
                            <?php elseif ($finalPick): ?>
                                <div class="final-pick-message mt-3">
                                    <div class="message-title">Message from the Program Chairperson</div>
                                    <div class="text-muted">No message yet. The Program Chairperson will send the final pick note here.</div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Defense Committee</h5>
                        <span class="<?php echo $committeeStatusClass; ?>"><?php echo htmlspecialchars($committeeStatusLabel); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (!$committeeRequest): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-people fs-1 mb-2"></i>
                                <p class="mb-0">No committee request yet. Please wait for the program chairperson.</p>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <div class="text-muted small">Committee review schedule</div>
                                <div class="fw-semibold text-success"><?php echo htmlspecialchars($committeeScheduleLabel); ?></div>
                                <?php if ($committeeVenueLabel !== ''): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars($committeeVenueLabel); ?></div>
                                <?php endif; ?>
                                <div class="text-muted small mt-1">Final defense schedule will be set by the program chairperson.</div>
                            </div>
                            <?php if ($committeeMemoAvailable && $committeeMemoTitle !== ''): ?>
                                <div class="mb-3">
                                    <div class="text-muted small">Outline Defense Title</div>
                                    <div class="fw-semibold text-success"><?php echo htmlspecialchars($committeeMemoTitle); ?></div>
                                    <?php if (!empty($committeeMemoReceivedAt)): ?>
                                        <div class="text-muted small mt-1">
                                            <i class="bi bi-check-circle-fill text-success me-1"></i>
                                            Memo received <?php echo htmlspecialchars(formatTimestamp($committeeMemoReceivedAt, 'Just now')); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted small mt-1">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Open the memo notification to unlock manuscript submission.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="mb-2"><strong>Adviser:</strong> <?php echo htmlspecialchars($committeeMembers['adviser'] ?: 'TBA'); ?></div>
                            <div class="mb-2"><strong>Committee Chairperson:</strong> <?php echo htmlspecialchars($committeeMembers['chair'] ?: 'TBA'); ?></div>
                            <div class="mb-2"><strong>Panel Members:</strong> <?php echo htmlspecialchars($committeeMembers['panel'] ?: 'TBA'); ?></div>
                            <?php if ($committeeStatusLabel === 'Rejected' && $committeeReviewNotes !== ''): ?>
                                <div class="alert alert-warning mt-3 mb-0">
                                    <?php echo htmlspecialchars($committeeReviewNotes); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Final Paper Status</h5>
                        <?php if ($finalPaperSubmission): ?>
                            <span class="badge bg-success-subtle text-success">Latest</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning">Not submitted</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!$finalPaperSubmission): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-file-earmark-arrow-up fs-1 mb-2"></i>
                                <p class="mb-3">Submit your final paper to start the review process.</p>
                                <a href="submit_final_paper.php" class="btn btn-outline-success btn-sm">
                                    Submit Final Paper
                                </a>
                            </div>
                        <?php else: ?>
                            <?php if ($finalPaperTitle !== ''): ?>
                                <div class="fw-semibold text-success mb-1"><?php echo htmlspecialchars($finalPaperTitle); ?></div>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="<?php echo $finalPaperStatusBadgeClass; ?> text-capitalize">
                                    <?php echo htmlspecialchars(finalPaperStatusLabel($finalPaperStatusLabel)); ?>
                                </span>
                                <span class="badge bg-light text-success">
                                    Version <?php echo htmlspecialchars((string)max(1, $finalPaperVersion)); ?>
                                </span>
                            </div>
                            <div class="text-muted small mb-3">
                                Last submitted: <?php echo formatTimestamp($finalPaperSubmittedAt, 'Not submitted'); ?>
                            </div>
                            <a href="submit_final_paper.php" class="btn btn-outline-success btn-sm">
                                Open Final Submission
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Status Change Timeline</h5>
                        <span class="badge bg-light text-success"><?php echo number_format(count($statusTimeline)); ?> entries</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($statusTimeline)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-activity fs-2 mb-2"></i>
                                <p class="mb-0">No status updates have been logged for your submissions yet.</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach (array_slice($statusTimeline, 0, 4) as $log): ?>
                                    <?php
                                        $changedAt = $log['changed_at'] ?? null;
                                        $updatedBy = trim(($log['updater_firstname'] ?? '') . ' ' . ($log['updater_lastname'] ?? '')) ?: 'Program Chair';
                                        $email = $log['updater_email'] ?? '';
                                        $roleLabel = str_replace('_', ' ', $log['updater_role'] ?? '');
                                        $title = $log['title'] ?? 'Submission';
                                        $type = $log['type'] ?? 'Concept Paper';
                                        $oldStatus = $log['old_status'] ?? '';
                                        $newStatus = $log['new_status'] ?? '';
                                    ?>
                                    <li class="mb-4 pb-4 border-bottom">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-semibold text-success"><?php echo htmlspecialchars($title); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($type); ?></div>
                                            </div>
                                            <div class="text-end text-muted small">
                                                <?php echo formatTimestamp($changedAt, 'Date TBA'); ?>
                                            </div>
                                        </div>
                                        <hr>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="<?php echo statusBadgeClass($oldStatus); ?> text-capitalize">From <?php echo htmlspecialchars($oldStatus ?: 'Pending'); ?></span>
                                            <i class="bi bi-arrow-right text-muted"></i>
                                            <span class="<?php echo statusBadgeClass($newStatus); ?> text-capitalize">To <?php echo htmlspecialchars($newStatus ?: 'Pending'); ?></span>
                                        </div>
                                        <div class="text-muted small mt-3">
                                            <i class="bi bi-person-circle me-1"></i>
                                            <?php echo htmlspecialchars($updatedBy); ?>
                                            <?php if ($roleLabel !== ''): ?>
                                                <span class="badge bg-light text-success ms-2"><?php echo htmlspecialchars(ucwords($roleLabel)); ?></span>
                                            <?php endif; ?>
                                            <?php if ($email !== ''): ?>
                                                <div><?php echo htmlspecialchars($email); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($statusTimeline) > 4): ?>
                                <div class="text-end mt-3">
                                    <a href="status_logs.php" class="btn btn-sm btn-outline-success">
                                        View full history
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Upcoming Defense</h5>
                        <a href="view_defense_schedule.php" class="btn btn-outline-primary btn-sm">Full Schedule</a>
                    </div>
                    <div class="card-body">
                        <?php if ($nextDefense): ?>
                            <div class="mb-3">
                                <h6 class="fw-semibold text-primary mb-1">
                                    <?php
                                        $defenseDate = $nextDefense['defense_date'] ?? '';
                                        $defenseTime = $nextDefense['defense_time'] ?? '';
                                        $dateLabel = (!empty($defenseDate) && $defenseDate !== '0000-00-00') ? date('F j, Y', strtotime($defenseDate)) : 'Date TBA';
                                        $timeLabel = (!empty($defenseTime) && $defenseTime !== '00:00:00') ? ' &bull; ' . date('g:i A', strtotime($defenseTime)) : '';
                                        echo $dateLabel . $timeLabel;
                                    ?>
                                </h6>
                                <p class="text-muted mb-1"><i class="bi bi-geo-alt me-2 text-success"></i><?php echo htmlspecialchars($nextDefense['venue'] ?? 'Venue TBA'); ?></p>
                                <?php if (!empty($nextDefense['status'])): ?>
                                    <span class="<?php echo statusBadgeClass((string)$nextDefense['status']); ?> text-capitalize"><?php echo htmlspecialchars($nextDefense['status']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (count($defenseSchedules) > 1): ?>
                                <div class="small text-muted">
                                    <strong>Also scheduled:</strong>
                                    <ul class="ps-3 mt-2 mb-0">
                                        <?php foreach (array_slice($defenseSchedules, 1) as $schedule): ?>
                                            <?php
                                                $dDate = $schedule['defense_date'] ?? '';
                                                $dTime = $schedule['defense_time'] ?? '';
                                                $dLabel = (!empty($dDate) && $dDate !== '0000-00-00') ? date('M j, Y', strtotime($dDate)) : 'Date TBA';
                                                $tLabel = (!empty($dTime) && $dTime !== '00:00:00') ? ' &bull; ' . date('g:i A', strtotime($dTime)) : '';
                                            ?>
                                            <li><?php echo $dLabel . $tLabel; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-calendar-x fs-1 mb-2"></i>
                                <p class="mb-0">You don’t have a scheduled defense yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
