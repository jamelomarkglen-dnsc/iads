<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_paper_helpers.php';

enforce_role_access(['committee_chairperson']);

ensureFinalPaperTables($conn);

$submissionId = (int)($_GET['submission_id'] ?? 0);
$chairId = (int)($_SESSION['user_id'] ?? 0);

if ($submissionId <= 0 || $chairId <= 0) {
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

$reviews = fetchFinalPaperReviews($conn, $submissionId);
$currentDecision = trim((string)($submission['final_decision_notes'] ?? ''));
$currentStatus = trim((string)($submission['status'] ?? ''));
$decisionMadeAt = $submission['final_decision_at'] ?? null;

$success = '';
$error = '';
$gateSuccess = '';
$gateError = '';
$gateStatusOptions = [
    'Passed' => 'Passed',
    'Passed with Minor Revision' => 'Passed with minor revisions',
    'Passed with Major Revision' => 'Passed with major revisions',
    'Redefense' => 'Redefense',
    'Failed' => 'Failed',
];
$currentGateStatus = trim((string)($submission['review_gate_status'] ?? ''));

$notifyReviewGate = function (string $gateStatus) use ($conn, $submissionId, $reviews, $studentName): void {
    if ($gateStatus === '') {
        return;
    }
    $link = 'final_paper_inbox.php?review_submission_id=' . $submissionId;
    $message = "{$studentName}'s outline defense manuscript is ready for review. Status: {$gateStatus}.";
    foreach ($reviews as $review) {
        $reviewerId = (int)($review['reviewer_id'] ?? 0);
        $reviewerRole = trim((string)($review['reviewer_role'] ?? ''));
        if ($reviewerId <= 0 || $reviewerRole === 'committee_chairperson') {
            continue;
        }
        notify_user_for_role(
            $conn,
            $reviewerId,
            $reviewerRole,
            'Outline defense review unlocked',
            $message,
            $link,
            true
        );
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_review_gate'])) {
    $gateStatus = trim($_POST['review_gate_status'] ?? '');
    if (!array_key_exists($gateStatus, $gateStatusOptions)) {
        $gateError = 'Please select a valid review status.';
    } else {
        $updateGate = $conn->prepare("
            UPDATE final_paper_submissions
            SET review_gate_status = ?
            WHERE id = ?
        ");
        if (!$updateGate) {
            $gateError = 'Unable to save the review gate status. Please try again.';
        } else {
            $updateGate->bind_param('si', $gateStatus, $submissionId);
            if ($updateGate->execute()) {
                $gateSuccess = 'Review access status confirmed successfully.';
                $submission['review_gate_status'] = $gateStatus;
                if ($gateStatus !== $currentGateStatus) {
                    $notifyReviewGate($gateStatus);
                    $currentGateStatus = $gateStatus;
                }
            } else {
                $gateError = 'Unable to save the review gate status. Please try again.';
            }
            $updateGate->close();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_decision'])) {
    $finalStatus = trim($_POST['final_status'] ?? '');
    $decisionNotes = trim($_POST['decision_notes'] ?? '');
    $verdict = trim($_POST['outline_verdict'] ?? '');

    $validStatuses = ['Approved', 'Rejected', 'Needs Revision', 'Minor Revision', 'Major Revision'];
    $validVerdicts = ['Passed', 'Passed with Revision', 'Failed'];

    if (!in_array($finalStatus, $validStatuses, true)) {
        $error = 'Invalid final status selected.';
    } elseif (!in_array($verdict, $validVerdicts, true)) {
        $error = 'Invalid outline defense verdict selected.';
    } else {
        $autoGateStatus = $currentGateStatus;
        if ($autoGateStatus === '') {
            $autoGateStatus = match ($finalStatus) {
                'Approved' => 'Passed',
                'Minor Revision', 'Needs Revision' => 'Passed with Minor Revision',
                'Major Revision' => 'Passed with Major Revision',
                'Rejected' => 'Failed',
                default => '',
            };
            if ($autoGateStatus === '' && $verdict === 'Passed') {
                $autoGateStatus = 'Passed';
            } elseif ($autoGateStatus === '' && $verdict === 'Passed with Revision') {
                $autoGateStatus = 'Passed with Minor Revision';
            } elseif ($autoGateStatus === '' && $verdict === 'Failed') {
                $autoGateStatus = 'Failed';
            }
        }
        if ($autoGateStatus === '') {
            $autoGateStatus = null;
        }
        $updateStmt = $conn->prepare("
            UPDATE final_paper_submissions
            SET status = ?,
                final_decision_by = ?,
                final_decision_notes = ?,
                final_decision_at = NOW(),
                committee_reviews_completed_at = NOW(),
                review_gate_status = COALESCE(review_gate_status, ?)
            WHERE id = ?
        ");
        if (!$updateStmt) {
            $error = 'Unable to save the final decision. Please try again.';
        } else {
            $updateStmt->bind_param('sissi', $finalStatus, $chairId, $decisionNotes, $autoGateStatus, $submissionId);
            if ($updateStmt->execute()) {
                setOutlineDefenseVerdict($conn, $submissionId, $verdict);

                $currentDecision = $decisionNotes;
                $currentStatus = $finalStatus;
                $decisionMadeAt = date('Y-m-d H:i:s');
                if ($currentGateStatus === '' && $autoGateStatus !== '') {
                    $submission['review_gate_status'] = $autoGateStatus;
                    $notifyReviewGate($autoGateStatus);
                    $currentGateStatus = $autoGateStatus;
                }

                $chairName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
                $chairName = $chairName !== '' ? $chairName : 'Committee Chairperson';

                $verdictLabel = match ($verdict) {
                    'Passed' => 'Passed',
                    'Passed with Revision' => 'Passed with Revision',
                    'Failed' => 'Failed',
                    default => $verdict,
                };

                notify_user(
                    $conn,
                    $studentId,
                    'Outline Defense Decision Made',
                    "{$chairName} has made the final decision on your outline defense manuscript. Verdict: {$verdictLabel}. Please check your submission page for details.",
                    'submit_final_paper.php',
                    false
                );

                $success = 'Final decision has been recorded successfully.';
            } else {
                $error = 'Unable to save the final decision. Please try again.';
            }
            $updateStmt->close();
        }
    }
}

$chairName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
$chairName = $chairName !== '' ? $chairName : 'Committee Chairperson';
$reviewGateStatus = $currentGateStatus;

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Outline Defense Final Decision - DNSC IAdS</title>
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
        .review-card { border-left: 4px solid #16562c; border-radius: 8px; padding: 16px; background: #f9fafb; }
        .status-badge { font-size: 0.9rem; padding: 0.5rem 1rem; border-radius: 999px; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <h3 class="fw-bold mb-1">Outline Defense Final Decision</h3>
            <p class="mb-0 text-white-50">Review all committee feedback and make the final decision on the outline defense manuscript.</p>
        </div>

        <?php if ($gateSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($gateSuccess); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif ($gateError): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($gateError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

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

        <div class="card info-card mb-4">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h5 class="fw-bold text-success mb-1">Review Access Confirmation</h5>
                    <p class="text-muted mb-0">Confirm the review status before faculty can open the manuscript review.</p>
                    <div class="mt-2 small">
                        Current status:
                        <?php if ($reviewGateStatus !== ''): ?>
                            <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($reviewGateStatus); ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary">Not confirmed</span>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#reviewGateModal">
                    Confirm Status
                </button>
            </div>
        </div>

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
                        <label class="form-label">Current Status</label>
                        <p class="form-control-plaintext">
                            <span class="badge <?= finalPaperStatusClass($currentStatus); ?>">
                                <?= htmlspecialchars(finalPaperStatusLabel($currentStatus)); ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Committee Reviews & Decision Form -->
            <div class="col-lg-8">
                <!-- Committee Reviews Summary -->
                <div class="card form-card p-4 mb-4">
                    <h5 class="fw-bold text-success mb-3">Committee Reviews</h5>
                    <?php if (empty($reviews)): ?>
                        <div class="alert alert-warning">No reviews have been submitted yet.</div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php
                            $roleLabels = [
                                'adviser' => 'Thesis Adviser',
                                'committee_chairperson' => 'Committee Chairperson',
                                'panel' => 'Panel Member',
                            ];
                            ?>
                            <?php foreach ($reviews as $review): ?>
                                <?php
                                $roleKey = $review['reviewer_role'] ?? '';
                                $roleLabel = $roleLabels[$roleKey] ?? ucfirst($roleKey);
                                $reviewerName = $review['reviewer_name'] ?? 'Reviewer';
                                $reviewStatus = finalPaperStatusLabel($review['status'] ?? 'Pending');
                                $reviewComments = trim((string)($review['comments'] ?? ''));
                                $reviewedAt = $review['reviewed_at'] ?? null;
                                ?>
                                <div class="col-12">
                                    <div class="review-card">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars($reviewerName); ?></div>
                                                <div class="small text-muted"><?= htmlspecialchars($roleLabel); ?></div>
                                            </div>
                                            <span class="badge <?= finalPaperReviewStatusClass($review['status'] ?? 'Pending'); ?>">
                                                <?= htmlspecialchars($reviewStatus); ?>
                                            </span>
                                        </div>
                                        <?php if ($reviewComments !== ''): ?>
                                            <div class="small mb-2"><?= nl2br(htmlspecialchars($reviewComments)); ?></div>
                                        <?php else: ?>
                                            <div class="small text-muted">No comment provided.</div>
                                        <?php endif; ?>
                                        <?php if ($reviewedAt): ?>
                                            <div class="small text-muted mt-2">
                                                <i class="bi bi-clock me-1"></i>
                                                Reviewed on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($reviewedAt))); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Final Decision Form -->
                <div class="card form-card p-4">
                    <h5 class="fw-bold text-success mb-3">Make Final Decision</h5>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Final Status</label>
                            <select name="final_status" class="form-select" required>
                                <option value="">-- Select Final Status --</option>
                                <option value="Approved">Approved</option>
                                <option value="Minor Revision">Passed with Minor Revision</option>
                                <option value="Major Revision">Passed with Major Revision</option>
                                <option value="Needs Revision">Needs Revision</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                            <div class="form-text">Select the overall status based on committee feedback.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Outline Defense Verdict</label>
                            <select name="outline_verdict" class="form-select" required>
                                <option value="">-- Select Verdict --</option>
                                <option value="Passed">Passed</option>
                                <option value="Passed with Revision">Passed with Revision</option>
                                <option value="Failed">Failed</option>
                            </select>
                            <div class="form-text">The final verdict for the outline defense.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Decision Notes</label>
                            <textarea name="decision_notes" class="form-control" rows="6" placeholder="Provide the final decision and any important notes for the student..." required></textarea>
                            <div class="form-text">This message will be visible to the student.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="submit_decision" class="btn btn-success">
                                <i class="bi bi-check-circle me-1"></i> Submit Final Decision
                            </button>
                            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back
                            </a>
                        </div>
                    </form>

                    <?php if ($decisionMadeAt): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Final decision was made on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($decisionMadeAt))); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="reviewGateModal" tabindex="-1" aria-labelledby="reviewGateLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title" id="reviewGateLabel">Confirm Review Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Select the status to confirm review access for faculty.
                    </p>
                    <label class="form-label fw-semibold">Status</label>
                    <select name="review_gate_status" class="form-select" required>
                        <option value="">Select status</option>
                        <?php foreach ($gateStatusOptions as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value); ?>" <?= $reviewGateStatus === $value ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="set_review_gate" value="1" class="btn btn-success">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
