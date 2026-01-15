<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_paper_helpers.php';
require_once 'notice_commence_helpers.php';

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
$canFinalize = in_array($role, ['committee_chairperson', 'committee_chair'], true);

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

                // Check if all route slip reviews are completed
                $allRouteSlipReviewsComplete = true;
                foreach ($reviews as $review) {
                    $slipStatus = strtolower(trim((string)($review['route_slip_status'] ?? '')));
                    if ($slipStatus === '' || $slipStatus === 'pending') {
                        $allRouteSlipReviewsComplete = false;
                        break;
                    }
                }

                // If all route slip reviews are complete and this is from committee chairperson, notify student and dean
                if ($allRouteSlipReviewsComplete && $reviewerRoleFilter === 'committee_chairperson') {
                    $studentId = (int)($submission['student_id'] ?? 0);
                    $studentName = $submission['student_name'] ?? 'Student';
                    $reviewerName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
                    $reviewerName = $reviewerName !== '' ? $reviewerName : 'Committee Chairperson';

                    // Notify student
                    notify_user(
                        $conn,
                        $studentId,
                        'Route slip review completed',
                        "{$reviewerName} has completed the route slip review. Decision: {$newStatus}. Please check your submission page for details.",
                        'submit_final_paper.php',
                        false
                    );

                    // Notify dean
                    notify_role(
                        $conn,
                        'dean',
                        'Route slip final decision made',
                        "Committee Chairperson has completed the route slip review for {$studentName}. Decision: {$newStatus}.",
                        'submit_final_paper.php',
                        false
                    );
                }
            } else {
                $reviewError = 'Unable to save your route slip review.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the route slip review update.';
        }
    }
}

// Handle overall route slip decision from committee chairperson
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_overall_route_slip_decision']) && $canFinalize) {
    $overallDecision = trim($_POST['overall_decision'] ?? '');
    $decisionNotes = trim($_POST['decision_notes'] ?? '');

    if (!in_array($overallDecision, ['Approved', 'Minor Revision', 'Major Revision', 'Rejected'], true)) {
        $reviewError = 'Please choose a valid overall decision.';
    } else {
        $stmt = $conn->prepare("
            UPDATE final_paper_submissions
            SET route_slip_overall_decision = ?,
                route_slip_decision_notes = ?,
                route_slip_decision_by = ?,
                route_slip_decision_at = NOW()
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('ssii', $overallDecision, $decisionNotes, $reviewerId, $submissionId);
            if ($stmt->execute()) {
                $reviewSuccess = 'Overall route slip decision saved successfully.';
                $submission = fetchFinalPaperSubmission($conn, $submissionId);

                $studentId = (int)($submission['student_id'] ?? 0);
                $studentName = $submission['student_name'] ?? 'Student';
                $chairName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
                $chairName = $chairName !== '' ? $chairName : 'Committee Chairperson';

                // Notify student
                notify_user(
                    $conn,
                    $studentId,
                    'Route slip overall decision made',
                    "{$chairName} has made the overall decision on your route slip. Decision: {$overallDecision}. Please check your submission page for details.",
                    'submit_final_paper.php',
                    false
                );

                // Auto-create Notice to Commence if approved
                if ($overallDecision === 'Approved') {
                    ensureNoticeCommenceTable($conn);
                    
                    // Check if notice already exists
                    $checkStmt = $conn->prepare("
                        SELECT id FROM notice_to_commence_requests
                        WHERE submission_id = ? AND status IN ('Pending', 'Approved')
                        LIMIT 1
                    ");
                    $noticeExists = false;
                    if ($checkStmt) {
                        $checkStmt->bind_param('i', $submissionId);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        $noticeExists = $checkResult && $checkResult->num_rows > 0;
                        if ($checkResult) $checkResult->free();
                        $checkStmt->close();
                    }
                    
                    if (!$noticeExists) {
                        // Get program chairperson for this student's program
                        $programChairStmt = $conn->prepare("
                            SELECT u.id, u.program
                            FROM users u
                            JOIN users s ON s.program = u.program
                            WHERE s.id = ? AND u.role = 'program_chairperson'
                            LIMIT 1
                        ");
                        $programChairId = 0;
                        if ($programChairStmt) {
                            $programChairStmt->bind_param('i', $studentId);
                            $programChairStmt->execute();
                            $chairResult = $programChairStmt->get_result();
                            $chairRow = $chairResult ? $chairResult->fetch_assoc() : null;
                            if ($chairResult) $chairResult->free();
                            $programChairStmt->close();
                            $programChairId = (int)($chairRow['id'] ?? 0);
                        }
                        
                        if ($programChairId > 0) {
                            $noticeDate = date('Y-m-d');
                            $startDate = date('Y-m-d');
                            $subject = 'NOTIFICATION TO COMMENCE THE APPROVED PROPOSAL';
                            $finalTitle = $submission['final_title'] ?? 'the research proposal';
                            $studentProgram = '';
                            
                            // Get student program
                            $studentProgramStmt = $conn->prepare("SELECT program FROM users WHERE id = ? LIMIT 1");
                            if ($studentProgramStmt) {
                                $studentProgramStmt->bind_param('i', $studentId);
                                $studentProgramStmt->execute();
                                $progResult = $studentProgramStmt->get_result();
                                $progRow = $progResult ? $progResult->fetch_assoc() : null;
                                if ($progResult) $progResult->free();
                                $studentProgramStmt->close();
                                $studentProgram = $progRow['program'] ?? '';
                            }
                            
                            $body = build_notice_commence_body($studentName, $finalTitle, $studentProgram, $startDate);
                            
                            // Create notice to commence
                            $insertNoticeStmt = $conn->prepare("
                                INSERT INTO notice_to_commence_requests
                                    (student_id, submission_id, program_chair_id, status, notice_date, start_date, subject, body)
                                VALUES (?, ?, ?, 'Pending', ?, ?, ?, ?)
                            ");
                            if ($insertNoticeStmt) {
                                $insertNoticeStmt->bind_param(
                                    'iiissss',
                                    $studentId,
                                    $submissionId,
                                    $programChairId,
                                    $noticeDate,
                                    $startDate,
                                    $subject,
                                    $body
                                );
                                $insertNoticeStmt->execute();
                                $insertNoticeStmt->close();
                            }
                        }
                    }
                }

                // Notify program chairperson
                $noticeLink = $overallDecision === 'Approved' ? 'notice_to_commence.php' : 'submit_final_paper.php';
                $noticeMsg = $overallDecision === 'Approved'
                    ? "Committee Chairperson has approved the route slip for {$studentName}. A Notice to Commence has been prepared for your review and submission to the Dean."
                    : "Committee Chairperson has made the overall route slip decision for {$studentName}. Decision: {$overallDecision}.";
                
                notify_role(
                    $conn,
                    'program_chairperson',
                    'Route slip overall decision made',
                    $noticeMsg,
                    $noticeLink,
                    false
                );

                // Notify dean
                notify_role(
                    $conn,
                    'dean',
                    'Route slip overall decision made',
                    "Committee Chairperson has made the overall route slip decision for {$studentName}. Decision: {$overallDecision}.",
                    'submit_final_paper.php',
                    false
                );
            } else {
                $reviewError = 'Unable to save the overall decision.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the overall decision update.';
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

                <?php if ($canFinalize): ?>
                <div class="card review-card p-4 mt-4">
                    <h5 class="fw-bold text-success mb-3">Overall Route Slip Decision</h5>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Overall Decision</label>
                            <select name="overall_decision" class="form-select" required>
                                <option value="">-- Select Overall Decision --</option>
                                <option value="Approved" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="Minor Revision" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Minor Revision' ? 'selected' : ''; ?>>Approved with Minor Revision</option>
                                <option value="Major Revision" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Major Revision' ? 'selected' : ''; ?>>Approved with Major Revision</option>
                                <option value="Rejected" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <div class="form-text">Select the overall decision based on all route slip reviews.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Decision Notes</label>
                            <textarea name="decision_notes" class="form-control" rows="4" placeholder="Provide the overall decision notes for the student..."><?= htmlspecialchars($submission['route_slip_decision_notes'] ?? ''); ?></textarea>
                            <div class="form-text">This message will be visible to the student and dean.</div>
                        </div>
                        <button type="submit" name="save_overall_route_slip_decision" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i> Save Overall Decision
                        </button>
                    </form>
                    <?php if (!empty($submission['route_slip_decision_at'])): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Overall decision was made on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($submission['route_slip_decision_at']))); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
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
