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

$reviews = fetchFinalPaperReviews($conn, $submissionId);

$reviewSuccess = '';
$reviewError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_route_slip_review'])) {
    $newStatus = trim($_POST['route_slip_status'] ?? '');
    $comments = trim($_POST['route_slip_comments'] ?? '');

    if (!in_array($newStatus, ['Approved', 'Minor Revision', 'Major Revision'], true)) {
        $reviewError = 'Please choose a valid route slip decision.';
    } else {
        $stmt = $conn->prepare("
            UPDATE final_paper_reviews
            SET route_slip_status = ?, route_slip_comments = ?, route_slip_reviewed_at = NOW()
            WHERE submission_id = ? AND reviewer_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('ssii', $newStatus, $comments, $submissionId, $reviewerId);
            if ($stmt->execute()) {
                $reviewSuccess = 'Route slip review saved successfully.';
                $reviewRow = fetchFinalPaperReviewForUser($conn, $submissionId, $reviewerId);
                $reviews = fetchFinalPaperReviews($conn, $submissionId);
            } else {
                $reviewError = 'Unable to save your route slip review.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the route slip review update.';
        }
    }
}

include 'header.php';
include 'sidebar.php';
$selectedRouteSlipStatus = $reviewRow['route_slip_status'] ?? '';
if ($selectedRouteSlipStatus === 'Needs Revision') {
    $selectedRouteSlipStatus = 'Minor Revision';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Slip Review</title>
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
                <h3 class="fw-bold text-success mb-1">Route Slip Review</h3>
                <p class="text-muted mb-0"><?= htmlspecialchars($submission['student_name'] ?? 'Student'); ?> - <?= htmlspecialchars($submission['final_title'] ?? ''); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="final_paper_review.php?submission_id=<?= (int)$submissionId; ?>" class="btn btn-outline-success">
                    <i class="bi bi-file-text me-1"></i>Manuscript Review
                </a>
                <a href="route_slip_inbox.php" class="btn btn-outline-secondary">
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
                    <h5 class="fw-bold text-success">Route Slip Preview</h5>
                    <?php if (!empty($submission['route_slip_path'])): ?>
                        <iframe class="preview-frame" src="<?= htmlspecialchars($submission['route_slip_path']); ?>"></iframe>
                    <?php else: ?>
                        <div class="text-muted">No route slip uploaded yet.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card review-card p-4">
                    <h5 class="fw-bold text-success mb-3">Route Slip Checklist</h5>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Decision</label>
                            <div class="border rounded-3 p-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="route_slip_status" id="slipApproved" value="Approved"
                                        <?= ($selectedRouteSlipStatus === 'Approved') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="slipApproved">Approval for the conduct of the study.</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="route_slip_status" id="slipMinor" value="Minor Revision"
                                        <?= ($selectedRouteSlipStatus === 'Minor Revision') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="slipMinor">Approval for the conduct of the study but still subjected to minor revisions and improvement.</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="route_slip_status" id="slipMajor" value="Major Revision"
                                        <?= ($selectedRouteSlipStatus === 'Major Revision') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="slipMajor">Disapproval. The paper needs further major revisions and improvement.</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Comments</label>
                            <textarea name="route_slip_comments" class="form-control" rows="4"><?= htmlspecialchars($reviewRow['route_slip_comments'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" name="save_route_slip_review" class="btn btn-success">Save Route Slip Review</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card review-card p-4 mt-4">
            <h5 class="fw-bold text-success mb-3">Route Slip Review Summary</h5>
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
                            <?php
                                $status = $review['route_slip_status'] ?? '';
                                if (strcasecmp($status, 'Needs Revision') === 0) {
                                    $status = 'Minor Revision';
                                }
                                $statusLabel = $status !== '' ? $status : 'Pending';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($review['reviewer_name'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($review['reviewer_role'] ?? ''); ?></td>
                                <td><span class="badge <?= finalPaperReviewStatusClass($statusLabel); ?>"><?= htmlspecialchars($statusLabel); ?></span></td>
                                <td><?= htmlspecialchars($review['route_slip_reviewed_at'] ?? ''); ?></td>
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
