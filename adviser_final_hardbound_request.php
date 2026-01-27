<?php
session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';
require_once 'notice_commence_helpers.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'adviser') {
    header('Location: login.php');
    exit;
}

ensureFinalHardboundTables($conn);

$adviser_id = (int)($_SESSION['user_id'] ?? 0);
$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_verification'])) {
    $hardbound_id = (int)($_POST['hardbound_id'] ?? 0);
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($hardbound_id <= 0) {
        $alert = ['type' => 'danger', 'message' => 'Invalid hardbound submission.'];
    } else {
        $submission = null;
        $stmt = $conn->prepare("
            SELECT s.*, u.firstname, u.lastname
            FROM final_hardbound_submissions s
            JOIN users u ON u.id = s.student_id
            WHERE s.id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $hardbound_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $submission = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }
            $stmt->close();
        }

        if (!$submission) {
            $alert = ['type' => 'danger', 'message' => 'Hardbound submission not found.'];
        } else {
            $student_id = (int)($submission['student_id'] ?? 0);
            $committee = fetch_latest_hardbound_committee_for_student($conn, $student_id);
            if (!$committee) {
                $alert = ['type' => 'danger', 'message' => 'Defense committee is not yet approved for this student.'];
            } else {
                $signatureError = '';
                $signaturePath = '';
                if (isset($_FILES['adviser_signature'])) {
                    $signaturePath = save_notice_signature_upload($_FILES['adviser_signature'], $adviser_id, $signatureError);
                }
                if ($signaturePath === '') {
                    $signaturePath = find_existing_signature_path($adviser_id);
                }
                if ($signatureError !== '' || $signaturePath === '') {
                    $alert = ['type' => 'danger', 'message' => $signatureError !== '' ? $signatureError : 'Please upload your signature.'];
                } else {
                    $result = create_final_hardbound_committee_request($conn, $hardbound_id, $adviser_id, $remarks, $signaturePath, $committee);
                }
                if (!empty($result['success'])) {
                    $studentName = trim(($submission['firstname'] ?? '') . ' ' . ($submission['lastname'] ?? '')) ?: 'the student';
                    $reviewers = build_final_hardbound_committee_reviewers($committee);
                    $reviewerIds = array_map(static fn ($reviewer) => (int)$reviewer['reviewer_id'], $reviewers);
                    notify_users(
                        $conn,
                        $reviewerIds,
                        'Final hardbound endorsement request',
                        "Adviser sent the final hardbound endorsement for {$studentName}. Please review and upload your signature.",
                        "committee_final_hardbound_review.php?request_id=" . (int)($result['request_id'] ?? 0),
                        true
                    );
                    $alert = ['type' => 'success', 'message' => 'Endorsement request sent to the defense committee.'];
                } elseif (!$alert) {
                    $alert = ['type' => 'danger', 'message' => $result['error'] ?? 'Unable to create request.'];
                }
            }
        }
    }
}

$submissions = fetch_final_hardbound_submissions_for_adviser($conn, $adviser_id);
$adviserSignaturePath = find_existing_signature_path($adviser_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Hardbound Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card { border-radius: 18px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
        .card-header { background: #f8fbf8; border-bottom: 1px solid #e2ece2; }
        .table thead { text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.08em; }
        .badge { font-weight: 600; }
        .btn-request { min-width: 96px; }
        .guide-card { border-left: 4px solid #1f6f3a; }
        .endorsement-letter { border-radius: 16px; overflow: hidden; border: 1px solid #e2ece2; background: #fff; }
        .letter-head,
        .letter-foot {
            background-image: url('memopic.jpg');
            background-repeat: no-repeat;
            background-size: 100% auto;
            width: 100%;
        }
        .letter-head { height: 170px; background-position: top center; border-bottom: 1px solid #d9e2d6; }
        .letter-foot { height: 110px; background-position: bottom center; border-top: 1px solid #d9e2d6; }
        .letter-body { padding: 20px 36px; line-height: 1.5; text-align: justify; text-justify: inter-word; font-size: 0.95rem; }
        .letter-title { text-align: center; font-weight: 600; margin-bottom: 16px; }
        .letter-text { margin-bottom: 12px; }
        .letter-inline { display: inline-block; min-width: 200px; vertical-align: baseline; }
        .letter-input {
            border: none;
            border-bottom: 1px solid #1f2d22;
            background: transparent;
            padding: 2px 4px;
            font-weight: 600;
            width: 100%;
            font-size: 0.95rem;
        }
        .letter-input:focus { outline: none; box-shadow: none; }
        .signature-block { margin-top: 22px; text-align: center; }
        .signature-line { border-top: 1px solid #1f2d22; width: 240px; margin: 10px auto 6px; }
        .signature-preview { max-height: 70px; max-width: 220px; object-fit: contain; display: block; margin: 0 auto; }
        .signature-label { font-size: 0.9rem; font-weight: 600; }
        .letter-date { margin-top: 18px; text-align: left; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Hardbound Requests</h3>
                <p class="text-muted mb-0">Review student uploads and send endorsements to the defense committee.</p>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>">
                <?php echo htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Student Hardbound Uploads</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($submissions)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No hardbound submissions yet.
                            </div>
                        <?php else: ?>
                            <?php $modalBlocks = []; ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Title</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                            <th>Request</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submissions as $submission): ?>
                                            <?php
                                                $request = fetch_final_hardbound_committee_request($conn, (int)$submission['id']);
                                                $requestStatus = $request['status'] ?? '';
                                                $requestDisplay = final_hardbound_display_status($requestStatus);
                                                $requestBadge = $requestStatus !== '' ? final_hardbound_status_badge($requestDisplay) : 'bg-secondary-subtle text-secondary';
                                                $submitted = $submission['submitted_at'] ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : 'N/A';
                                                $submissionDisplay = final_hardbound_display_status($submission['status'] ?? 'Submitted');
                                                $statusBadge = final_hardbound_status_badge($submissionDisplay);
                                                $canRequest = empty($requestStatus) || $requestDisplay === 'Needs Revision';
                                            ?>
                                            <tr>
                                                <td class="fw-semibold text-success"><?php echo htmlspecialchars($submission['student_name'] ?? 'Student'); ?></td>
                                                <td><?php echo htmlspecialchars($submission['submission_title'] ?? ''); ?></td>
                                                <td><span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars($submissionDisplay); ?></span></td>
                                                <td><?php echo htmlspecialchars($submitted); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $requestBadge; ?>">
                                                        <?php echo $requestStatus !== '' ? htmlspecialchars($requestDisplay) : 'Not requested'; ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($request): ?>
                                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#letterModal<?php echo (int)$submission['id']; ?>">
                                                            View
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-outline-success" disabled>View</button>
                                                    <?php endif; ?>
                                                    <?php if ($canRequest): ?>
                                                        <button class="btn btn-sm btn-success ms-1 btn-request" data-bs-toggle="modal" data-bs-target="#requestModal<?php echo (int)$submission['id']; ?>">
                                                            Request
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($request): ?>
                                                <?php
                                                    $requestTitle = trim((string)($submission['submission_title'] ?? ''));
                                                    $requestStudent = $submission['student_name'] ?? '________________________';
                                                    $requestDegree = trim((string)($submission['student_program'] ?? ''));
                                                    if ($requestDegree === '') {
                                                        $requestDegree = trim((string)($submission['student_department'] ?? ''));
                                                    }
                                                    if ($requestDegree === '') {
                                                        $requestDegree = trim((string)($submission['student_college'] ?? ''));
                                                    }
                                                    $requestDate = !empty($request['requested_at'])
                                                        ? date('F d, Y', strtotime($request['requested_at']))
                                                        : date('F d, Y');
                                                    $requestSignature = $request['adviser_signature_path'] ?? $adviserSignaturePath;
                                                ?>
                                                <?php ob_start(); ?>
                                                <div class="modal fade" id="letterModal<?php echo (int)$submission['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Adviser Endorsement Letter</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="endorsement-letter">
                                                                    <div class="letter-head" aria-hidden="true"></div>
                                                                    <div class="letter-body">
                                                                        <div class="letter-title">
                                                                            Adviser's Endorsement for Routing of Final Hardbound Capstone/Thesis/Dissertation
                                                                        </div>
                                                                        <p class="letter-text">
                                                                            The final hardbound thesis/dissertation entitled:
                                                                            <span class="letter-inline" style="min-width: 100%;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($requestTitle); ?>" readonly>
                                                                            </span>
                                                                        </p>
                                                                        <p class="letter-text">
                                                                            prepared and submitted by
                                                                            <span class="letter-inline" style="min-width: 220px;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($requestStudent); ?>" readonly>
                                                                            </span>
                                                                            (Name of Student) in partial fulfillment of the requirements for the degree of
                                                                            <span class="letter-inline" style="min-width: 220px;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($requestDegree); ?>" readonly>
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
                                                                        <div class="signature-block">
                                                                            <?php if (!empty($requestSignature)): ?>
                                                                                <img src="<?php echo htmlspecialchars($requestSignature); ?>" alt="Adviser signature" class="signature-preview">
                                                                            <?php endif; ?>
                                                                            <div class="signature-line"></div>
                                                                            <div class="signature-label">Adviser's Signature Over Printed Name</div>
                                                                        </div>
                                                                        <div class="letter-date">
                                                                            Date of Endorsement:
                                                                            <span class="letter-inline" style="min-width: 200px;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($requestDate); ?>" readonly>
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                    <div class="letter-foot" aria-hidden="true"></div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php $modalBlocks[] = ob_get_clean(); ?>
                                            <?php endif; ?>
                                            <?php if ($canRequest): ?>
                                                <?php ob_start(); ?>
                                                <div class="modal fade" id="requestModal<?php echo (int)$submission['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                                        <form method="post" enctype="multipart/form-data" class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Send Endorsement</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <input type="hidden" name="request_verification" value="1">
                                                                <input type="hidden" name="hardbound_id" value="<?php echo (int)$submission['id']; ?>">
                                                                <p class="mb-3">Send this hardbound submission to the defense committee for signatures.</p>
                                                                <?php
                                                                    $studentName = $submission['student_name'] ?? '________________________';
                                                                    $titleValue = trim((string)($submission['submission_title'] ?? ''));
                                                                    $degreeValue = trim((string)($submission['student_program'] ?? ''));
                                                                    if ($degreeValue === '') {
                                                                        $degreeValue = trim((string)($submission['student_department'] ?? ''));
                                                                    }
                                                                    if ($degreeValue === '') {
                                                                        $degreeValue = trim((string)($submission['student_college'] ?? ''));
                                                                    }
                                                                    $dateValue = date('F d, Y');
                                                                ?>
                                                                <div class="endorsement-letter mb-4">
                                                                    <div class="letter-head" aria-hidden="true"></div>
                                                                    <div class="letter-body">
                                                                        <div class="letter-title">
                                                                            Adviser's Endorsement for Routing of Final Hardbound Capstone/Thesis/Dissertation
                                                                        </div>
                                                                        <p class="letter-text">
                                                                            The final hardbound thesis/dissertation entitled:
                                                                            <span class="letter-inline" style="min-width: 100%;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($titleValue); ?>" readonly>
                                                                            </span>
                                                                        </p>
                                                                        <p class="letter-text">
                                                                            prepared and submitted by
                                                                            <span class="letter-inline" style="min-width: 220px;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($studentName); ?>" readonly>
                                                                            </span>
                                                                            (Name of Student) in partial fulfillment of the requirements for the degree of
                                                                            <span class="letter-inline" style="min-width: 220px;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($degreeValue); ?>" readonly>
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
                                                                        <div class="signature-block">
                                                                            <?php if ($adviserSignaturePath): ?>
                                                                                <img src="<?php echo htmlspecialchars($adviserSignaturePath); ?>" alt="Adviser signature" class="signature-preview">
                                                                            <?php endif; ?>
                                                                            <div class="signature-line"></div>
                                                                            <div class="signature-label">Adviser's Signature Over Printed Name</div>
                                                                        </div>
                                                                        <div class="letter-date">
                                                                            Date of Endorsement:
                                                                            <span class="letter-inline" style="min-width: 200px;">
                                                                                <input type="text" class="letter-input" value="<?php echo htmlspecialchars($dateValue); ?>" readonly>
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                    <div class="letter-foot" aria-hidden="true"></div>
                                                                </div>
                                                                <label class="form-label">Adviser Signature Upload</label>
                                                                <input type="file" name="adviser_signature" class="form-control mb-3" accept="image/png,image/jpeg">
                                                                <label class="form-label">Remarks (optional)</label>
                                                                <textarea name="remarks" class="form-control" rows="3" placeholder="Any notes for the defense committee..."></textarea>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-success">Send Request</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                                <?php $modalBlocks[] = ob_get_clean(); ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (!empty($modalBlocks)) echo implode("\n", $modalBlocks); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card guide-card">
                    <div class="card-body">
                        <h5 class="fw-semibold mb-3">Request Guide</h5>
                        <p class="text-muted mb-3">Send endorsements only after the student uploads the hardbound copy.</p>
                        <ul class="text-muted small ps-3 mb-0">
                            <li>Review the PDF for completeness.</li>
                            <li>Click Request to notify the committee.</li>
                            <li>Track signature status in the list.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
