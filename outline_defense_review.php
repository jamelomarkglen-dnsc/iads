<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_paper_helpers.php';

enforce_role_access(['adviser', 'committee_chairperson', 'panel']);

ensureFinalPaperTables($conn);

$submissionId = (int)($_GET['submission_id'] ?? 0);
$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$reviewerRole = trim((string)($_SESSION['role'] ?? ''));

if ($submissionId <= 0 || $reviewerId <= 0 || $reviewerRole === '') {
    header('Location: login.php');
    exit;
}

$submission = fetchFinalPaperSubmission($conn, $submissionId);
if (!$submission) {
    header('Location: login.php');
    exit;
}

$studentId = (int)($submission['student_id'] ?? 0);
$studentStmt = $conn->prepare("SELECT firstname, lastname, email FROM users WHERE id = ? LIMIT 1");
if (!$studentStmt) {
    header('Location: login.php');
    exit;
}
$studentStmt->bind_param('i', $studentId);
$studentStmt->execute();
$studentResult = $studentStmt->get_result();
$student = $studentResult ? $studentResult->fetch_assoc() : null;
$studentStmt->close();

if (!$student) {
    header('Location: login.php');
    exit;
}

$studentName = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
$studentEmail = $student['email'] ?? '';

$review = fetchFinalPaperReviewForUser($conn, $submissionId, $reviewerId);
if (!$review) {
    header('Location: login.php');
    exit;
}

$currentStatus = trim((string)($review['status'] ?? 'Pending'));
$currentComments = trim((string)($review['comments'] ?? ''));
$reviewedAt = $review['reviewed_at'] ?? null;

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $newStatus = trim($_POST['review_status'] ?? '');
    $newComments = trim($_POST['review_comments'] ?? '');

    $validStatuses = ['Pending', 'Approved', 'Rejected', 'Needs Revision', 'Minor Revision', 'Major Revision'];
    if (!in_array($newStatus, $validStatuses, true)) {
        $error = 'Invalid review status selected.';
    } elseif ($newStatus === 'Pending') {
        $error = 'Please select a final review status (Approved, Rejected, or Revision Required).';
    } else {
        $updateStmt = $conn->prepare("
            UPDATE final_paper_reviews
            SET status = ?,
                comments = ?,
                reviewed_at = NOW()
            WHERE id = ?
        ");
        if (!$updateStmt) {
            $error = 'Unable to save your review. Please try again.';
        } else {
            $updateStmt->bind_param('ssi', $newStatus, $newComments, $review['id']);
            if ($updateStmt->execute()) {
                $currentStatus = $newStatus;
                $currentComments = $newComments;
                $reviewedAt = date('Y-m-d H:i:s');

                $reviewerName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
                $reviewerName = $reviewerName !== '' ? $reviewerName : 'Reviewer';

                $statusLabel = match ($newStatus) {
                    'Approved' => 'Approved',
                    'Rejected' => 'Rejected',
                    'Needs Revision' => 'Needs Revision',
                    'Minor Revision' => 'Passed with Minor Revision',
                    'Major Revision' => 'Passed with Major Revision',
                    default => $newStatus,
                };

                notify_user(
                    $conn,
                    $studentId,
                    'Outline Defense Review Completed',
                    "{$reviewerName} ({$reviewerRole}) has completed their review of your outline defense manuscript. Status: {$statusLabel}",
                    'submit_final_paper.php',
                    false
                );

                $success = 'Your review has been submitted successfully.';
            } else {
                $error = 'Unable to save your review. Please try again.';
            }
            $updateStmt->close();
        }
    }
}

$roleLabel = match ($reviewerRole) {
    'adviser' => 'Thesis Adviser',
    'committee_chairperson' => 'Committee Chairperson',
    'panel' => 'Panel Member',
    default => ucfirst(str_replace('_', ' ', $reviewerRole)),
};

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Outline Defense Review - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .hero-card { border-radius: 20px; background: linear-gradient(130deg, #16562c, #0f3d1f); color: #fff; padding: 28px; }
        .info-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .form-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .form-label { font-weight: 600; color: #16562c; }
        .document-preview { border: 2px dashed #16562c; border-radius: 12px; padding: 20px; text-align: center; }
        .status-badge { font-size: 0.9rem; padding: 0.5rem 1rem; border-radius: 999px; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <h3 class="fw-bold mb-1">Outline Defense Manuscript Review</h3>
            <p class="mb-0 text-white-50">Review and provide feedback on the student's outline defense manuscript.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Student & Submission Info -->
            <div class="col-lg-4">
                <div class="card info-card p-4">
                    <h5 class="fw-bold text-success mb-3">Student Information</h5>
                    <div class="mb-3">
                        <label class="form-label">Student Name</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($studentName); ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <p class="form-control-plaintext"><a href="mailto:<?= htmlspecialchars($studentEmail); ?>"><?= htmlspecialchars($studentEmail); ?></a></p>
                    </div>
                    <hr>
                    <h5 class="fw-bold text-success mb-3">Submission Details</h5>
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($submission['final_title'] ?? ''); ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Submitted</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars($submission['submitted_at'] ?? ''); ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Version</label>
                        <p class="form-control-plaintext"><?= htmlspecialchars((string)($submission['version'] ?? 1)); ?></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Overall Status</label>
                        <p class="form-control-plaintext">
                            <span class="badge <?= finalPaperStatusClass($submission['status'] ?? 'Submitted'); ?>">
                                <?= htmlspecialchars(finalPaperStatusLabel($submission['status'] ?? 'Submitted')); ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Review Form -->
            <div class="col-lg-8">
                <div class="card form-card p-4">
                    <h5 class="fw-bold text-success mb-3">Your Review (<?= htmlspecialchars($roleLabel); ?>)</h5>

                    <!-- Document Links -->
                    <div class="mb-4">
                        <label class="form-label">Manuscript Document</label>
                        <?php if (!empty($submission['file_path'])): ?>
                            <div class="document-preview">
                                <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2rem;"></i>
                                <p class="mt-2 mb-0">
                                    <a href="<?= htmlspecialchars($submission['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-download me-1"></i> Download Manuscript
                                    </a>
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">No manuscript file available.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Route Slip Link -->
                    <?php if (!empty($submission['route_slip_path'])): ?>
                        <div class="mb-4">
                            <label class="form-label">Route Slip Document</label>
                            <div class="document-preview">
                                <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2rem;"></i>
                                <p class="mt-2 mb-0">
                                    <a href="<?= htmlspecialchars($submission['route_slip_path']); ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-download me-1"></i> Download Route Slip
                                    </a>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <hr>

                    <!-- Review Form -->
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Review Status</label>
                            <select name="review_status" class="form-select" required>
                                <option value="Pending" <?= $currentStatus === 'Pending' ? 'selected' : ''; ?>>-- Select Status --</option>
                                <option value="Approved" <?= $currentStatus === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="Minor Revision" <?= $currentStatus === 'Minor Revision' ? 'selected' : ''; ?>>Passed with Minor Revision</option>
                                <option value="Major Revision" <?= $currentStatus === 'Major Revision' ? 'selected' : ''; ?>>Passed with Major Revision</option>
                                <option value="Needs Revision" <?= $currentStatus === 'Needs Revision' ? 'selected' : ''; ?>>Needs Revision</option>
                                <option value="Rejected" <?= $currentStatus === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <div class="form-text">Select your review decision for this manuscript.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Comments & Feedback</label>
                            <textarea name="review_comments" class="form-control" rows="6" placeholder="Provide detailed feedback for the student..."><?= htmlspecialchars($currentComments); ?></textarea>
                            <div class="form-text">Be constructive and specific in your feedback.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="submit_review" class="btn btn-success">
                                <i class="bi bi-check-circle me-1"></i> Submit Review
                            </button>
                            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back
                            </a>
                        </div>
                    </form>

                    <?php if ($reviewedAt): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Your review was last submitted on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($reviewedAt))); ?>
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
