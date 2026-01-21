<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_paper_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chairperson', 'committee_chair'];
enforce_role_access($allowedRoles);

ensureFinalPaperTables($conn);

$submissionId = (int)($_GET['submission_id'] ?? 0);
$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewerRoleFilter = $roleMap[$role] ?? $role;

$submission = null;
if ($submissionId > 0) {
    $stmt = $conn->prepare("
        SELECT s.*, CONCAT(u.firstname, ' ', u.lastname) AS student_name, u.email AS student_email
        FROM final_paper_submissions s
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
    header('Location: final_paper_inbox.php');
    exit;
}

$reviewRow = null;
if (in_array($reviewerRoleFilter, ['adviser', 'panel', 'committee_chairperson'], true)) {
    $reviewStmt = $conn->prepare("
        SELECT *
        FROM final_paper_reviews
        WHERE submission_id = ? AND reviewer_id = ? AND reviewer_role = ?
        LIMIT 1
    ");
    if ($reviewStmt) {
        $reviewStmt->bind_param('iis', $submissionId, $reviewerId, $reviewerRoleFilter);
        $reviewStmt->execute();
        $reviewResult = $reviewStmt->get_result();
        $reviewRow = $reviewResult ? $reviewResult->fetch_assoc() : null;
        if ($reviewResult) {
            $reviewResult->free();
        }
        $reviewStmt->close();
    }
}
if (!$reviewRow) {
    header('Location: final_paper_inbox.php');
    exit;
}

$reviewGateStatus = trim((string)($submission['review_gate_status'] ?? ''));
if (!in_array($role, ['committee_chairperson', 'committee_chair'], true) && $reviewGateStatus === '') {
    header('Location: final_paper_inbox.php?review_submission_id=' . $submissionId);
    exit;
}

$reviews = fetchFinalPaperReviews($conn, $submissionId);
$canFinalize = in_array($role, ['committee_chairperson', 'committee_chair'], true);

$reviewSuccess = '';
$reviewError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_review'])) {
    $newStatus = trim($_POST['review_status'] ?? '');
    $comments = trim($_POST['review_comments'] ?? '');

    if (!in_array($newStatus, ['Approved', 'Rejected', 'Minor Revision', 'Major Revision'], true)) {
        $reviewError = 'Please choose a valid review status.';
    } else {
        $stmt = $conn->prepare("
            UPDATE final_paper_reviews
            SET status = ?, comments = ?, reviewed_at = NOW()
            WHERE submission_id = ? AND reviewer_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('ssii', $newStatus, $comments, $submissionId, $reviewerId);
            if ($stmt->execute()) {
                $reviewSuccess = 'Review saved successfully.';
                $reviewRow = fetchFinalPaperReviewForUser($conn, $submissionId, $reviewerId);
                $reviews = fetchFinalPaperReviews($conn, $submissionId);

                if (($submission['status'] ?? '') === 'Submitted') {
                    $conn->query("UPDATE final_paper_submissions SET status = 'Under Review' WHERE id = {$submissionId}");
                    $submission['status'] = 'Under Review';
                }

                $allReviewed = true;
                foreach ($reviews as $review) {
                    $statusValue = strtolower(trim((string)($review['status'] ?? '')));
                    if ($statusValue === '' || $statusValue === 'pending') {
                        $allReviewed = false;
                        break;
                    }
                }
                if ($allReviewed) {
                    $submissionInfo = fetchFinalPaperSubmission($conn, $submissionId);
                    $notifiedAt = $submissionInfo['committee_reviews_completed_at'] ?? null;
                    if (empty($notifiedAt)) {
                        $updateNotify = $conn->prepare("
                            UPDATE final_paper_submissions
                            SET committee_reviews_completed_at = NOW()
                            WHERE id = ? AND committee_reviews_completed_at IS NULL
                        ");
                        if ($updateNotify) {
                            $updateNotify->bind_param('i', $submissionId);
                            if ($updateNotify->execute()) {
                                $adviserId = 0;
                                foreach ($reviews as $review) {
                                    if (($review['reviewer_role'] ?? '') === 'adviser') {
                                        $adviserId = (int)($review['reviewer_id'] ?? 0);
                                        break;
                                    }
                                }
                                if ($adviserId > 0) {
                                    notify_user_for_role(
                                        $conn,
                                        $adviserId,
                                        'adviser',
                                        'Committee reviews completed',
                                        "{$submission['student_name']} has received feedback from all committee reviewers.",
                                        'final_paper_inbox.php'
                                    );
                                }
                            }
                            $updateNotify->close();
                        }
                    }
                }
            } else {
                $reviewError = 'Unable to save your review.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the review update.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['final_decision']) && $canFinalize) {
    $decision = trim($_POST['final_status'] ?? '');
    $notes = trim($_POST['final_notes'] ?? '');
    if (!in_array($decision, ['Approved', 'Rejected', 'Minor Revision', 'Major Revision'], true)) {
        $reviewError = 'Please choose a valid final decision.';
    } else {
        $stmt = $conn->prepare("
            UPDATE final_paper_submissions
            SET status = ?,
                final_decision_by = ?,
                final_decision_notes = ?,
                final_decision_at = NOW()
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('sisi', $decision, $reviewerId, $notes, $submissionId);
            if ($stmt->execute()) {
                $reviewSuccess = 'Overall decision saved.';
                $submission['status'] = $decision;
                $submission['final_decision_by'] = $reviewerId;
                $submission['final_decision_notes'] = $notes;
                $submission['final_decision_at'] = date('Y-m-d H:i:s');

                notify_user_for_role(
                    $conn,
                    (int)$submission['student_id'],
                    'student',
                    'Outline defense manuscript decision',
                    "Your outline defense manuscript has been marked as " . finalPaperStatusLabel($decision) . ".",
                    'submit_final_paper.php'
                );
                if ($decision === 'Approved') {
                    $adviserReview = null;
                    foreach ($reviews as $review) {
                        if (($review['reviewer_role'] ?? '') === 'adviser') {
                            $adviserReview = $review;
                            break;
                        }
                    }
                    $adviserId = (int)($adviserReview['reviewer_id'] ?? 0);
                    if ($adviserId > 0) {
                        notify_user_for_role(
                            $conn,
                            $adviserId,
                            'adviser',
                            'Outline defense passed',
                            "{$submission['student_name']} has passed the outline defense review.",
                            'final_paper_inbox.php'
                        );
                    }
                }
            } else {
                $reviewError = 'Unable to save the final decision.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the final decision update.';
        }
    }
}

include 'header.php';
include 'sidebar.php';
$selectedReviewStatus = $reviewRow['status'] ?? '';
if ($selectedReviewStatus === 'Needs Revision') {
    $selectedReviewStatus = 'Minor Revision';
}
$prefillStatus = trim($_GET['prefill_status'] ?? '');
$prefillAllowed = ['Approved', 'Minor Revision', 'Major Revision', 'Rejected'];
if (($selectedReviewStatus === '' || $selectedReviewStatus === 'Pending')
    && in_array($prefillStatus, $prefillAllowed, true)) {
    $selectedReviewStatus = $prefillStatus;
}
$selectedFinalStatus = $submission['status'] ?? '';
if ($selectedFinalStatus === 'Needs Revision') {
    $selectedFinalStatus = 'Minor Revision';
}
$reviewOptions = [
    'Approved' => 'Passed',
    'Minor Revision' => 'Passed with Minor Revision',
    'Major Revision' => 'Passed with Major Revision',
    'Rejected' => 'Failed',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Outline Defense Manuscript Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .review-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .preview-frame { width: 100%; height: 600px; border: 1px solid #d9e5da; border-radius: 12px; }
        .review-table thead th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #556; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Outline Defense Manuscript Review</h3>
                <p class="text-muted mb-0"><?= htmlspecialchars($submission['student_name'] ?? 'Student'); ?> - <?= htmlspecialchars($submission['final_title'] ?? ''); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="route_slip_review.php?submission_id=<?= (int)$submissionId; ?>" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-check me-1"></i>Route Slip Review
                </a>
                <a href="final_paper_inbox.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Inbox
                </a>
            </div>
        </div>

        <?php if ($reviewSuccess): ?>
            <div class="alert alert-success"><?= htmlspecialchars($reviewSuccess); ?></div>
        <?php elseif ($reviewError): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($reviewError); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card review-card p-3">
                    <h5 class="fw-bold text-success">Manuscript Preview</h5>
                    <?php if (!empty($submission['file_path'])): ?>
                        <iframe class="preview-frame" src="<?= htmlspecialchars($submission['file_path']); ?>"></iframe>
                    <?php else: ?>
                        <div class="text-muted">No file uploaded.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card review-card p-4 mb-4">
                    <h5 class="fw-bold text-success mb-3">Your Review</h5>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Manuscript Review Status</label>
                            <div class="border rounded-3 p-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="review_status" id="reviewApproved" value="Approved"
                                        <?= ($selectedReviewStatus === 'Approved') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="reviewApproved">Passed (approved to proceed).</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="review_status" id="reviewMinor" value="Minor Revision"
                                        <?= ($selectedReviewStatus === 'Minor Revision') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="reviewMinor">Passed with minor revisions.</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="review_status" id="reviewMajor" value="Major Revision"
                                        <?= ($selectedReviewStatus === 'Major Revision') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="reviewMajor">Passed with major revisions.</label>
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="radio" name="review_status" id="reviewRejected" value="Rejected"
                                        <?= ($selectedReviewStatus === 'Rejected') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="reviewRejected">Failed / needs resubmission.</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Manuscript Comments</label>
                            <textarea name="review_comments" class="form-control" rows="4"><?= htmlspecialchars($reviewRow['comments'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" name="save_review" class="btn btn-success">Save Review</button>
                    </form>
                </div>

                <?php if ($canFinalize): ?>
                <div class="card review-card p-4">
                    <h5 class="fw-bold text-success mb-3">Overall Decision</h5>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Decision</label>
                            <select name="final_status" class="form-select" required>
                                <?php foreach ($reviewOptions as $value => $label): ?>
                                    <option value="<?= $value; ?>" <?= ($selectedFinalStatus === $value) ? 'selected' : ''; ?>>
                                        <?= $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Notes</label>
                            <textarea name="final_notes" class="form-control" rows="3"><?= htmlspecialchars($submission['final_decision_notes'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" name="final_decision" class="btn btn-outline-success">Save Overall Decision</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card review-card p-4 mt-4">
            <h5 class="fw-bold text-success mb-3">Review Summary</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle review-table mb-0">
                    <thead>
                        <tr>
                            <th>Reviewer</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Reviewed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviews as $review): ?>
                            <tr>
                                <td><?= htmlspecialchars($review['reviewer_name'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($review['reviewer_role'] ?? ''); ?></td>
                                <td><span class="badge <?= finalPaperReviewStatusClass($review['status'] ?? ''); ?>"><?= htmlspecialchars(finalPaperStatusLabel($review['status'] ?? 'Pending')); ?></span></td>
                                <td><?= htmlspecialchars($review['reviewed_at'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
