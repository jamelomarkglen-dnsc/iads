<?php
session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';
require_once 'notice_commence_helpers.php';
require_once 'final_concept_helpers.php';
require_once 'notifications_helper.php';

$allowedRoles = ['committee_chairperson', 'committee_chair', 'panel'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureFinalHardboundTables($conn);

$request_id = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    header('Location: committee_final_hardbound_inbox.php');
    exit;
}

$reviewer_id = (int)($_SESSION['user_id'] ?? 0);
$request = fetch_final_hardbound_committee_request_by_id($conn, $request_id);
if (!$request) {
    header('Location: committee_final_hardbound_inbox.php');
    exit;
}

$reviewRow = fetch_final_hardbound_committee_review_row($conn, $request_id, $reviewer_id);
if (!$reviewRow) {
    header('Location: committee_final_hardbound_inbox.php');
    exit;
}

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_committee_review'])) {
    $status = trim((string)($_POST['review_status'] ?? ''));
    $remarks = trim((string)($_POST['review_remarks'] ?? ''));
    $signatureError = '';
    $signaturePath = '';
    if (isset($_FILES['review_signature'])) {
        $signaturePath = save_notice_signature_upload($_FILES['review_signature'], $reviewer_id, $signatureError);
    }

    if ($signatureError !== '') {
        $alert = ['type' => 'danger', 'message' => $signatureError];
    } elseif ($signaturePath === '' && empty($reviewRow['signature_path'])) {
        $alert = ['type' => 'danger', 'message' => 'Please upload your signature.'];
    } elseif (!in_array($status, ['Passed', 'Needs Revision'], true)) {
        $alert = ['type' => 'danger', 'message' => 'Please choose a valid status.'];
    } else {
        $result = update_final_hardbound_committee_review($conn, $request_id, $reviewer_id, $status, $remarks, $signaturePath);
        if (!empty($result['success'])) {
            $reviewRow = fetch_final_hardbound_committee_review_row($conn, $request_id, $reviewer_id) ?: $reviewRow;
            $request = fetch_final_hardbound_committee_request_by_id($conn, $request_id) ?: $request;
            $reviewerName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'A committee member';
            $studentName = trim(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? '')) ?: 'the student';
            $studentId = (int)($request['student_id'] ?? 0);
            $adviserId = (int)($request['adviser_id'] ?? 0);

            if ($adviserId > 0) {
                notify_user(
                    $conn,
                    $adviserId,
                    'Final hardbound endorsement update',
                    "{$reviewerName} marked the endorsement as {$status} for {$studentName}.",
                    'adviser_final_hardbound_request.php',
                    true
                );
            }

            if ($studentId > 0) {
                notify_user(
                    $conn,
                    $studentId,
                    'Final hardbound endorsement update',
                    "{$reviewerName} marked your hardbound endorsement as {$status}.",
                    'student_final_hardbound_submission.php',
                    true
                );
            }

            $overall = $result['overall_status'] ?? 'Pending';
            if ($overall === 'Passed') {
                if ($adviserId > 0) {
                    notify_user(
                        $conn,
                        $adviserId,
                        'Final hardbound endorsements completed',
                        "All committee signatures are complete for {$studentName}.",
                        'adviser_final_hardbound_request.php',
                        true
                    );
                }
                if ($studentId > 0) {
                    notify_user(
                        $conn,
                        $studentId,
                        'Final hardbound endorsed',
                        "All committee signatures are complete for your hardbound submission.",
                        'student_final_hardbound_submission.php',
                        true
                    );
                }
                $programChairs = getProgramChairsForStudent($conn, $studentId);
                if (!empty($programChairs)) {
                    $chairMessage = "Final hardbound endorsement for {$studentName} is Passed.";
                    foreach ($programChairs as $chairId) {
                        notify_user($conn, (int)$chairId, 'Final hardbound endorsement', $chairMessage, 'program_chairperson.php', true);
                    }
                }
            } elseif ($overall === 'Needs Revision') {
                if ($adviserId > 0) {
                    notify_user(
                        $conn,
                        $adviserId,
                        'Final hardbound needs revision',
                        "The committee requested revisions for {$studentName}.",
                        'adviser_final_hardbound_request.php',
                        true
                    );
                }
                if ($studentId > 0) {
                    notify_user(
                        $conn,
                        $studentId,
                        'Final hardbound needs revision',
                        "The committee requested revisions for your hardbound submission.",
                        'student_final_hardbound_submission.php',
                        true
                    );
                }
                $programChairs = getProgramChairsForStudent($conn, $studentId);
                if (!empty($programChairs)) {
                    $chairMessage = "Final hardbound endorsement for {$studentName} needs revision.";
                    foreach ($programChairs as $chairId) {
                        notify_user($conn, (int)$chairId, 'Final hardbound endorsement', $chairMessage, 'program_chairperson.php', true);
                    }
                }
            }

            $alert = ['type' => 'success', 'message' => 'Review saved successfully.'];
        } else {
            $alert = ['type' => 'danger', 'message' => $result['error'] ?? 'Unable to save review.'];
        }
    }
}

$reviews = fetch_final_hardbound_committee_reviews($conn, $request_id);
$requestBadge = final_hardbound_status_badge($request['status'] ?? 'Pending');
$submissionBadge = final_hardbound_status_badge($request['submission_status'] ?? 'Submitted');
$displayRequestStatus = final_hardbound_display_status($request['status'] ?? 'Pending');
$displaySubmissionStatus = final_hardbound_display_status($request['submission_status'] ?? 'Submitted');
$letterTitleValue = trim((string)($request['submission_title'] ?? ''));
$letterStudentName = trim(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? '')) ?: '________________________';
$letterDegree = trim((string)($request['program'] ?? ''));
if ($letterDegree === '') {
    $letterDegree = trim((string)($request['department'] ?? ''));
}
if ($letterDegree === '') {
    $letterDegree = trim((string)($request['college'] ?? ''));
}
$letterDate = $request['requested_at'] ? date('F d, Y', strtotime($request['requested_at'])) : date('F d, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Hardbound Endorsement</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card { border-radius: 18px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
        .signature-preview { max-height: 80px; max-width: 220px; object-fit: contain; }
        .letter-card { border-radius: 18px; overflow: hidden; border: 1px solid #e2ece2; background: #fff; }
        .letter-head,
        .letter-foot {
            background-image: url('memopic.jpg');
            background-repeat: no-repeat;
            background-size: 100% auto;
            width: 100%;
        }
        .letter-head { height: 120px; background-position: top center; border-bottom: 1px solid #d9e2d6; }
        .letter-foot { height: 80px; background-position: bottom center; border-top: 1px solid #d9e2d6; }
        .letter-body { padding: 16px 22px; line-height: 1.5; text-align: justify; text-justify: inter-word; font-size: 0.9rem; }
        .letter-title { text-align: center; font-weight: 600; margin-bottom: 12px; }
        .letter-text { margin-bottom: 10px; }
        .letter-inline { display: inline-block; min-width: 180px; vertical-align: baseline; }
        .letter-inline-wide { min-width: 100%; }
        .letter-inline-md { min-width: 200px; }
        .letter-input {
            border: none;
            border-bottom: 1px solid #1f2d22;
            background: transparent;
            padding: 2px 4px;
            font-weight: 600;
            width: 100%;
            font-size: 0.9rem;
        }
        .letter-input:focus { outline: none; box-shadow: none; }
        .letter-signature { margin-top: 16px; text-align: center; }
        .letter-signature-line { border-top: 1px solid #1f2d22; width: 200px; margin: 8px auto 6px; }
        .letter-signature-label { font-size: 0.85rem; font-weight: 600; }
        .letter-card.is-compact { max-width: 320px; margin: 0 auto; border-radius: 14px; }
        .letter-card.is-compact .letter-head { height: 70px; }
        .letter-card.is-compact .letter-foot { height: 50px; }
        .letter-card.is-compact .letter-body { padding: 10px 12px; font-size: 0.7rem; line-height: 1.35; }
        .letter-card.is-compact .letter-title { font-size: 0.75rem; margin-bottom: 8px; }
        .letter-card.is-compact .letter-input { font-size: 0.7rem; }
        .letter-card.is-compact .letter-inline { min-width: 120px; }
        .letter-card.is-compact .letter-signature-line { width: 140px; }
        .letter-card.is-compact .letter-signature-label { font-size: 0.7rem; }
        .letter-card.is-compact .signature-preview { max-height: 45px; max-width: 160px; }
        .letter-preview-trigger { cursor: pointer; }
        .letter-preview-trigger:hover { box-shadow: 0 10px 20px rgba(22, 86, 44, 0.12); }
        .letter-modal .letter-card { max-width: 720px; margin: 0 auto; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Hardbound Endorsement</h3>
                <p class="text-muted mb-0"><?php echo htmlspecialchars(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? '')); ?></p>
            </div>
            <a href="committee_final_hardbound_inbox.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Inbox
            </a>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>">
                <?php echo htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0">Hardbound Preview</h5>
                        <span class="badge <?php echo $submissionBadge; ?>"><?php echo htmlspecialchars($displaySubmissionStatus); ?></span>
                    </div>
                    <?php if (!empty($request['file_path'])): ?>
                        <iframe src="<?php echo htmlspecialchars($request['file_path']); ?>" style="width: 100%; height: 560px; border: none;"></iframe>
                    <?php else: ?>
                        <div class="text-muted">No PDF uploaded.</div>
                    <?php endif; ?>
                </div>
                <div class="card p-3 mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Your Review Status</h6>
                        <?php $reviewDisplay = final_hardbound_display_status($reviewRow['status'] ?? 'Pending'); ?>
                        <span class="badge <?php echo final_hardbound_status_badge($reviewDisplay); ?>"><?php echo htmlspecialchars($reviewDisplay); ?></span>
                    </div>
                    <?php if (!empty($reviewRow['remarks'])): ?>
                        <div class="text-muted small mb-2"><strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($reviewRow['remarks'])); ?></div>
                    <?php else: ?>
                        <div class="text-muted small mb-2">No remarks provided.</div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success btn-sm w-100" data-bs-toggle="modal" data-bs-target="#reviewModal">
                        Submit/Update Review
                    </button>
                </div>
                <div class="card p-3 mt-3">
                    <h6 class="fw-semibold mb-3">Committee Summary</h6>
                    <?php if (empty($reviews)): ?>
                        <div class="text-muted small">No signatures yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Reviewer</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reviews as $review): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($review['reviewer_name'] ?? ''); ?></td>
                                            <?php $reviewDisplay = final_hardbound_display_status($review['status'] ?? 'Pending'); ?>
                                            <td><span class="badge <?php echo final_hardbound_status_badge($reviewDisplay); ?>"><?php echo htmlspecialchars($reviewDisplay); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card p-3 mb-3">
                    <h6 class="fw-semibold mb-2">Request Details</h6>
                    <div class="text-muted small mb-2"><strong>Student:</strong> <?php echo htmlspecialchars(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? '')); ?></div>
                    <div class="text-muted small mb-2"><strong>Title:</strong> <?php echo htmlspecialchars($request['submission_title'] ?? ''); ?></div>
                    <div class="text-muted small mb-2"><strong>Status:</strong> <span class="badge <?php echo $requestBadge; ?>"><?php echo htmlspecialchars($displayRequestStatus); ?></span></div>
                    <div class="text-muted small"><strong>Submitted:</strong>
                        <?php echo htmlspecialchars($request['submitted_at'] ? date('M d, Y g:i A', strtotime($request['submitted_at'])) : 'N/A'); ?>
                    </div>
                </div>

                <div class="card p-3 mb-3">
                    <h6 class="fw-semibold mb-2">Adviser Endorsement</h6>
                    <?php if (!empty($request['remarks'])): ?>
                        <div class="text-muted small"><strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($request['remarks'])); ?></div>
                    <?php else: ?>
                        <div class="text-muted small">No remarks provided.</div>
                    <?php endif; ?>
                    <div class="small text-muted mt-3 mb-2">Adviser Endorsement Letter (Preview)</div>
                    <div class="letter-card is-compact letter-preview-trigger" data-bs-toggle="modal" data-bs-target="#adviserEndorsementModal" role="button" aria-label="Open full adviser endorsement letter">
                        <div class="letter-head" aria-hidden="true"></div>
                        <div class="letter-body">
                            <div class="letter-title">
                                Adviser's Endorsement for Routing of Final Hardbound Capstone/Thesis/Dissertation
                            </div>
                            <p class="letter-text">
                                The final hardbound thesis/dissertation entitled:
                                <span class="letter-inline letter-inline-wide">
                                    <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterTitleValue); ?>" readonly>
                                </span>
                            </p>
                            <p class="letter-text">
                                prepared and submitted by
                                <span class="letter-inline letter-inline-md">
                                    <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterStudentName); ?>" readonly>
                                </span>
                                (Name of Student) in partial fulfillment of the requirements for the degree of
                                <span class="letter-inline letter-inline-md">
                                    <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterDegree); ?>" readonly>
                                </span>,
                                has been carefully reviewed by the undersigned.
                            </p>
                            <p class="letter-text">I hereby certify that:</p>
                            <p class="letter-text">
                                All corrections and revisions required by the Panel of Examiners have been satisfactorily complied with;
                            </p>
                            <p class="letter-text">
                                The manuscript conforms to the prescribed academic, technical, and formatting standards of the Institute of Advanced Studies; and
                            </p>
                            <p class="letter-text">
                                This copy is endorsed as the final corrected and approved manuscript for official routing and hardbinding of the capstone/thesis/dissertation.
                                This endorsement is issued to facilitate the processing of the student's graduation and institutional documentation requirements.
                            </p>
                            <div class="letter-signature">
                                <div class="letter-signature-line"></div>
                                <div class="letter-signature-label">Adviser's Signature Over Printed Name</div>
                            </div>
                            <div class="letter-text" style="margin-top: 14px;">
                                Date of Endorsement:
                                <span class="letter-inline letter-inline-md">
                                    <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterDate); ?>" readonly>
                                </span>
                            </div>
                        </div>
                        <div class="letter-foot" aria-hidden="true"></div>
                    </div>
                    <button type="button" class="btn btn-outline-success btn-sm w-100 mt-2" data-bs-toggle="modal" data-bs-target="#adviserEndorsementModal">
                        View full letter
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reviewModalLabel">Submit/Update Review</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="request_id" value="<?php echo (int)$request_id; ?>">
                    <input type="hidden" name="save_committee_review" value="1">

                    <label class="form-label">Status</label>
                    <select name="review_status" class="form-select mb-3" required>
                        <option value="">Select status</option>
                        <?php $currentStatus = final_hardbound_display_status($reviewRow['status'] ?? ''); ?>
                        <option value="Passed" <?php echo $currentStatus === 'Passed' ? 'selected' : ''; ?>>Passed</option>
                        <option value="Needs Revision" <?php echo $currentStatus === 'Needs Revision' ? 'selected' : ''; ?>>Needs Revision</option>
                    </select>

                    <label class="form-label">Remarks (optional)</label>
                    <textarea name="review_remarks" class="form-control mb-3" rows="3" placeholder="Notes for the adviser/student"><?php echo htmlspecialchars($reviewRow['remarks'] ?? ''); ?></textarea>

                    <label class="form-label">Signature</label>
                    <input type="file" name="review_signature" class="form-control mb-2" accept="image/png,image/jpeg">
                    <?php if (!empty($reviewRow['signature_path'])): ?>
                        <img src="<?php echo htmlspecialchars($reviewRow['signature_path']); ?>" alt="Signature preview" class="signature-preview mt-2">
                    <?php endif; ?>

                    <button type="submit" class="btn btn-success w-100 mt-2">Save Review</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade letter-modal" id="adviserEndorsementModal" tabindex="-1" aria-labelledby="adviserEndorsementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adviserEndorsementModalLabel">Adviser Endorsement Letter</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="letter-card">
                    <div class="letter-head" aria-hidden="true"></div>
                    <div class="letter-body">
                        <div class="letter-title">
                            Adviser's Endorsement for Routing of Final Hardbound Capstone/Thesis/Dissertation
                        </div>
                        <p class="letter-text">
                            The final hardbound thesis/dissertation entitled:
                            <span class="letter-inline letter-inline-wide">
                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterTitleValue); ?>" readonly>
                            </span>
                        </p>
                        <p class="letter-text">
                            prepared and submitted by
                            <span class="letter-inline letter-inline-md">
                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterStudentName); ?>" readonly>
                            </span>
                            (Name of Student) in partial fulfillment of the requirements for the degree of
                            <span class="letter-inline letter-inline-md">
                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterDegree); ?>" readonly>
                            </span>,
                            has been carefully reviewed by the undersigned.
                        </p>
                        <p class="letter-text">I hereby certify that:</p>
                        <p class="letter-text">
                            All corrections and revisions required by the Panel of Examiners have been satisfactorily complied with;
                        </p>
                        <p class="letter-text">
                            The manuscript conforms to the prescribed academic, technical, and formatting standards of the Institute of Advanced Studies; and
                        </p>
                        <p class="letter-text">
                            This copy is endorsed as the final corrected and approved manuscript for official routing and hardbinding of the capstone/thesis/dissertation.
                            This endorsement is issued to facilitate the processing of the student's graduation and institutional documentation requirements.
                        </p>
                        <div class="letter-signature">
                            <div class="letter-signature-line"></div>
                            <div class="letter-signature-label">Adviser's Signature Over Printed Name</div>
                        </div>
                        <div class="letter-text" style="margin-top: 14px;">
                            Date of Endorsement:
                            <span class="letter-inline letter-inline-md">
                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($letterDate); ?>" readonly>
                            </span>
                        </div>
                    </div>
                    <div class="letter-foot" aria-hidden="true"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
