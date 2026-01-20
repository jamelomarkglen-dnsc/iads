<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_concept_helpers.php';
require_once 'final_defense_submission_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chair', 'committee_chairperson'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureFinalDefenseSubmissionTable($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['final_decision'])) {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));
    $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));

    if ($submissionId <= 0 || !in_array($decision, ['Passed', 'Failed'], true)) {
        $alert = ['type' => 'danger', 'message' => 'Please select a valid decision.'];
    } else {
        $submission = null;
        $stmt = $conn->prepare("
            SELECT fds.*, s.title AS submission_title,
                   CONCAT(stu.firstname, ' ', stu.lastname) AS student_name
            FROM final_defense_submissions fds
            LEFT JOIN submissions s ON s.id = fds.submission_id
            LEFT JOIN users stu ON stu.id = fds.student_id
            WHERE fds.id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $submissionId);
            $stmt->execute();
            $result = $stmt->get_result();
            $submission = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }
            $stmt->close();
        }

        if (!$submission) {
            $alert = ['type' => 'danger', 'message' => 'Final defense submission not found.'];
        } elseif ((int)($submission['chair_id'] ?? 0) !== $userId) {
            $alert = ['type' => 'danger', 'message' => 'Only the committee chairperson can submit the final decision.'];
        } else {
            $updateSql = $decision === 'Passed'
                ? "UPDATE final_defense_submissions
                   SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?, archive_ready_at = NOW()
                   WHERE id = ?"
                : "UPDATE final_defense_submissions
                   SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?, archive_ready_at = NULL
                   WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            if ($updateStmt) {
                $updateStmt->bind_param('sisi', $decision, $userId, $reviewNotes, $submissionId);
                if ($updateStmt->execute()) {
                    $studentId = (int)($submission['student_id'] ?? 0);
                    $studentName = $submission['student_name'] ?? 'the student';
                    $title = $submission['submission_title'] ?? 'the submission';
                    $archivingNote = $decision === 'Passed' ? ' Ready for archiving.' : '';

                    if ($studentId > 0) {
                        notify_user(
                            $conn,
                            $studentId,
                            'Final defense result',
                            "Your final defense submission for \"{$title}\" has been marked as {$decision}.",
                            'submit_final_defense.php',
                            false
                        );
                    }

                    $adviserId = (int)($submission['adviser_id'] ?? 0);
                    if ($adviserId > 0) {
                        notify_user(
                            $conn,
                            $adviserId,
                            'Final defense result',
                            "Final defense result for {$studentName} is {$decision}.{$archivingNote}",
                            'final_defense_committee_dashboard.php',
                            false
                        );
                    }

                    if ($decision === 'Passed') {
                        $panelIds = array_unique(array_filter([
                            (int)($submission['panel_member_one_id'] ?? 0),
                            (int)($submission['panel_member_two_id'] ?? 0),
                        ]));
                        foreach ($panelIds as $panelId) {
                            notify_user(
                                $conn,
                                $panelId,
                                'Final defense result',
                                "Final defense result for {$studentName} is {$decision}.{$archivingNote}",
                                'final_defense_committee_dashboard.php',
                                false
                            );
                        }
                    }

                    $chairs = getProgramChairsForStudent($conn, $studentId);
                    $programChairLink = $decision === 'Passed' ? 'archive_manager.php' : 'program_chairperson.php';
                    if (!empty($chairs)) {
                        foreach ($chairs as $chairId) {
                            notify_user(
                                $conn,
                                $chairId,
                                'Final defense decision',
                                "Final defense result for {$studentName} is {$decision}.{$archivingNote}",
                                $programChairLink,
                                false
                            );
                        }
                    } else {
                        notify_role(
                            $conn,
                            'program_chairperson',
                            'Final defense decision',
                            "Final defense result for {$studentName} is {$decision}.{$archivingNote}",
                            $programChairLink,
                            false
                        );
                    }

                    $alert = ['type' => 'success', 'message' => 'Decision saved.'];
                } else {
                    $alert = ['type' => 'danger', 'message' => 'Unable to save the decision.'];
                }
                $updateStmt->close();
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to prepare the decision update.'];
            }
        }
    }
}

$conditions = [
    '(fds.adviser_id = ? OR fds.chair_id = ? OR fds.panel_member_one_id = ? OR fds.panel_member_two_id = ?)'
];
$types = 'iiii';
$params = [$userId, $userId, $userId, $userId];

if ($search !== '') {
    $conditions[] = '(s.title LIKE ? OR CONCAT(stu.firstname, " ", stu.lastname) LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
    $conditions[] = 'fds.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

$sql = "
    SELECT fds.*, s.title AS submission_title,
           CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
           CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name
    FROM final_defense_submissions fds
    LEFT JOIN submissions s ON s.id = fds.submission_id
    LEFT JOIN users stu ON stu.id = fds.student_id
    LEFT JOIN users ch ON ch.id = fds.chair_id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY fds.submitted_at DESC, fds.id DESC
";
$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $stmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Defense Committee Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Defense Committee Dashboard</h3>
                <p class="text-muted mb-0">Review final defense files submitted by students.</p>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']); ?>">
                <?= htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="card card-shell mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold text-success">Search</label>
                        <input type="search" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control" placeholder="Search student or title">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold text-success">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <?php foreach (['Submitted','Passed','Failed'] as $option): ?>
                                <option value="<?= $option; ?>" <?= $statusFilter === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button type="submit" class="btn btn-success"><i class="bi bi-search me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card card-shell">
            <div class="card-body">
                <?php if (empty($rows)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 mb-2"></i>
                        <p class="mb-0">No final defense submissions yet.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $status = $row['status'] ?? 'Submitted';
                                $badgeClass = $status === 'Passed'
                                    ? 'bg-success-subtle text-success'
                                    : ($status === 'Failed' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning');
                                $isChair = (int)($row['chair_id'] ?? 0) === $userId;
                                $submittedLabel = $row['submitted_at'] ? date('M d, Y g:i A', strtotime($row['submitted_at'])) : 'Not recorded';
                            ?>
                            <div class="border rounded-4 p-3">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="fw-semibold text-success"><?= htmlspecialchars($row['student_name'] ?? 'Student'); ?></div>
                                        <div class="text-muted small">Title: <?= htmlspecialchars($row['submission_title'] ?? 'Submission'); ?></div>
                                        <div class="text-muted small">Chair: <?= htmlspecialchars($row['chair_name'] ?? 'Committee Chair'); ?></div>
                                        <div class="text-muted small">Submitted <?= htmlspecialchars($submittedLabel); ?></div>
                                    </div>
                                    <span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span>
                                </div>

                                <div class="mt-3 d-flex flex-wrap gap-2">
                                    <?php if (!empty($row['file_path'])): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($row['file_path']); ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-file-earmark-text"></i> View File
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-success" href="final_defense_review.php?submission_id=<?= (int)$row['id']; ?>">
                                        <i class="bi bi-eye"></i> Details
                                    </a>
                                </div>

                                <?php if ($isChair): ?>
                                    <form method="post" class="mt-3 border-top pt-3">
                                        <input type="hidden" name="final_decision" value="1">
                                        <input type="hidden" name="submission_id" value="<?= (int)$row['id']; ?>">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold text-success">Decision</label>
                                                <select name="decision" class="form-select" required>
                                                    <?php foreach (['Passed','Failed'] as $option): ?>
                                                        <option value="<?= $option; ?>" <?= ($status === $option) ? 'selected' : ''; ?>><?= $option; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-success">Notes (optional)</label>
                                                <input type="text" name="review_notes" class="form-control" value="<?= htmlspecialchars($row['review_notes'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-2 d-grid">
                                                <button type="submit" class="btn btn-success">Save</button>
                                            </div>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <?php if (!empty($row['review_notes'])): ?>
                                        <div class="text-muted small mt-3">Notes: <?= htmlspecialchars($row['review_notes']); ?></div>
                                    <?php endif; ?>
                                    <div class="alert alert-info mt-3 mb-0">Only the committee chairperson can submit the final decision.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
