<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_endorsement_helpers.php';

enforce_role_access(['program_chairperson']);

ensureFinalEndorsementTables($conn);

$submissionId = (int)($_GET['submission_id'] ?? 0);
$reviewerId = (int)($_SESSION['user_id'] ?? 0);

$submission = null;
if ($submissionId > 0) {
    $stmt = $conn->prepare("
        SELECT s.*, CONCAT(u.firstname, ' ', u.lastname) AS student_name, u.email AS student_email
        FROM final_endorsement_submissions s
        JOIN users u ON u.id = s.student_id
        WHERE s.id = ?
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
    header('Location: final_endorsement_inbox.php');
    exit;
}

$reviewSuccess = '';
$reviewError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_review'])) {
    $decision = trim($_POST['final_status'] ?? '');
    $notes = trim($_POST['review_notes'] ?? '');

    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        $reviewError = 'Please choose a valid decision.';
    } else {
        $stmt = $conn->prepare("
            UPDATE final_endorsement_submissions
            SET status = ?,
                reviewed_by = ?,
                reviewed_at = NOW(),
                review_notes = ?
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('sisi', $decision, $reviewerId, $notes, $submissionId);
            if ($stmt->execute()) {
                $reviewSuccess = 'Decision saved.';
                $submission['status'] = $decision;
                $submission['review_notes'] = $notes;
                $submission['reviewed_at'] = date('Y-m-d H:i:s');
                notify_user(
                    $conn,
                    (int)$submission['student_id'],
                    'Final endorsement decision',
                    "Your final endorsement has been marked as {$decision}.",
                    'submit_final_endorsement.php',
                    false
                );
            } else {
                $reviewError = 'Unable to save the decision.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the decision update.';
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
    <title>Final Endorsement Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .review-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .preview-frame { width: 100%; height: 600px; border: 1px solid #d9e5da; border-radius: 12px; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Endorsement Review</h3>
                <p class="text-muted mb-0"><?= htmlspecialchars($submission['student_name'] ?? 'Student'); ?></p>
            </div>
            <a href="final_endorsement_inbox.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Inbox</a>
        </div>

        <?php if ($reviewSuccess): ?>
            <div class="alert alert-success"><?= htmlspecialchars($reviewSuccess); ?></div>
        <?php elseif ($reviewError): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($reviewError); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card review-card p-3">
                    <h5 class="fw-bold text-success">PDF Preview</h5>
                    <?php if (!empty($submission['file_path'])): ?>
                        <iframe class="preview-frame" src="<?= htmlspecialchars($submission['file_path']); ?>"></iframe>
                    <?php else: ?>
                        <div class="text-muted">No file uploaded.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card review-card p-4">
                    <h5 class="fw-bold text-success mb-3">Decision</h5>
                    <div class="mb-2 text-muted small">
                        Current status: <span class="badge <?= finalEndorsementStatusClass($submission['status'] ?? 'Submitted'); ?>">
                            <?= htmlspecialchars($submission['status'] ?? 'Submitted'); ?>
                        </span>
                    </div>
                    <?php if (!empty($submission['notes'])): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Student Notes</label>
                            <div class="form-control-plaintext"><?= nl2br(htmlspecialchars($submission['notes'])); ?></div>
                        </div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Decision</label>
                            <select name="final_status" class="form-select" required>
                                <?php foreach (['Approved','Rejected'] as $option): ?>
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
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
