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
$submissionId = (int)($_GET['submission_id'] ?? 0);
$submission = null;

if ($submissionId > 0) {
    $stmt = $conn->prepare("
        SELECT fds.*, s.title AS submission_title,
               CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
               CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
               CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name,
               CONCAT(p1.firstname, ' ', p1.lastname) AS panel_one_name,
               CONCAT(p2.firstname, ' ', p2.lastname) AS panel_two_name
        FROM final_defense_submissions fds
        LEFT JOIN submissions s ON s.id = fds.submission_id
        LEFT JOIN users stu ON stu.id = fds.student_id
        LEFT JOIN users adv ON adv.id = fds.adviser_id
        LEFT JOIN users ch ON ch.id = fds.chair_id
        LEFT JOIN users p1 ON p1.id = fds.panel_member_one_id
        LEFT JOIN users p2 ON p2.id = fds.panel_member_two_id
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
}

if (!$submission) {
    header('Location: final_defense_committee_dashboard.php');
    exit;
}

$isAssigned = in_array($userId, [
    (int)($submission['adviser_id'] ?? 0),
    (int)($submission['chair_id'] ?? 0),
    (int)($submission['panel_member_one_id'] ?? 0),
    (int)($submission['panel_member_two_id'] ?? 0),
], true);
if (!$isAssigned) {
    header('Location: final_defense_committee_dashboard.php');
    exit;
}

$isChair = $userId === (int)($submission['chair_id'] ?? 0);
$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_review']) && $isChair) {
    $decision = trim((string)($_POST['decision'] ?? ''));
    $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));

    if (!in_array($decision, ['Passed', 'Failed'], true)) {
        $alert = ['type' => 'danger', 'message' => 'Please select a valid decision.'];
    } else {
        $updateStmt = $conn->prepare("
            UPDATE final_defense_submissions
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
            WHERE id = ?
        ");
        if ($updateStmt) {
            $updateStmt->bind_param('sisi', $decision, $userId, $reviewNotes, $submissionId);
            if ($updateStmt->execute()) {
                $submission['status'] = $decision;
                $submission['review_notes'] = $reviewNotes;
                $submission['reviewed_at'] = date('Y-m-d H:i:s');

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
                if (!empty($chairs)) {
                    foreach ($chairs as $chairId) {
                        notify_user(
                            $conn,
                            $chairId,
                            'Final defense decision',
                            "Final defense result for {$studentName} is {$decision}.{$archivingNote}",
                            'final_defense_inbox.php',
                            false
                        );
                    }
                } else {
                    notify_role(
                        $conn,
                        'program_chairperson',
                        'Final defense decision',
                        "Final defense result for {$studentName} is {$decision}.{$archivingNote}",
                        'final_defense_inbox.php',
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

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Defense Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .preview-frame { width: 100%; height: 560px; border: 1px solid #d9e5da; border-radius: 12px; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Defense Review</h3>
                <p class="text-muted mb-0"><?= htmlspecialchars($submission['student_name'] ?? 'Student'); ?></p>
            </div>
            <a href="final_defense_committee_dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']); ?>">
                <?= htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card card-shell p-3">
                    <h5 class="fw-semibold text-success">Final Defense File</h5>
                    <?php if (!empty($submission['file_path'])): ?>
                        <iframe class="preview-frame" src="<?= htmlspecialchars($submission['file_path']); ?>"></iframe>
                    <?php else: ?>
                        <div class="text-muted">No file uploaded.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card card-shell p-4">
                    <h5 class="fw-semibold text-success mb-3">Submission Details</h5>
                    <div class="mb-2"><strong>Title:</strong> <?= htmlspecialchars($submission['submission_title'] ?? ''); ?></div>
                    <div class="mb-2"><strong>Adviser:</strong> <?= htmlspecialchars($submission['adviser_name'] ?? ''); ?></div>
                    <div class="mb-2"><strong>Chair:</strong> <?= htmlspecialchars($submission['chair_name'] ?? ''); ?></div>
                    <div class="mb-2"><strong>Panel 1:</strong> <?= htmlspecialchars($submission['panel_one_name'] ?? ''); ?></div>
                    <div class="mb-3"><strong>Panel 2:</strong> <?= htmlspecialchars($submission['panel_two_name'] ?? ''); ?></div>

                    <div class="mb-3">
                        <span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($submission['status'] ?? 'Submitted'); ?></span>
                    </div>

                    <?php if ($isChair): ?>
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-success">Decision</label>
                                <select name="decision" class="form-select" required>
                                    <?php foreach (['Passed','Failed'] as $option): ?>
                                        <option value="<?= $option; ?>" <?= (($submission['status'] ?? '') === $option) ? 'selected' : ''; ?>>
                                            <?= $option; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-success">Notes</label>
                                <textarea name="review_notes" class="form-control" rows="3"><?= htmlspecialchars($submission['review_notes'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" name="save_review" class="btn btn-success">Save Decision</button>
                        </form>
                    <?php else: ?>
                        <?php if (!empty($submission['review_notes'])): ?>
                            <div class="text-muted small">Notes: <?= htmlspecialchars($submission['review_notes']); ?></div>
                        <?php endif; ?>
                        <div class="alert alert-info mt-3 mb-0">Only the committee chairperson can submit the final decision.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
