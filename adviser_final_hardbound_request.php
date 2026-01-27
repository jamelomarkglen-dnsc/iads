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
                                                $requestBadge = $requestStatus !== '' ? final_hardbound_status_badge($requestStatus) : 'bg-secondary-subtle text-secondary';
                                                $submitted = $submission['submitted_at'] ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : 'N/A';
                                                $statusBadge = final_hardbound_status_badge($submission['status'] ?? 'Submitted');
                                                $canRequest = empty($requestStatus) || $requestStatus === 'Needs Revision';
                                            ?>
                                            <tr>
                                                <td class="fw-semibold text-success"><?php echo htmlspecialchars($submission['student_name'] ?? 'Student'); ?></td>
                                                <td><?php echo htmlspecialchars($submission['submission_title'] ?? ''); ?></td>
                                                <td><span class="badge <?php echo $statusBadge; ?>"><?php echo htmlspecialchars($submission['status'] ?? 'Submitted'); ?></span></td>
                                                <td><?php echo htmlspecialchars($submitted); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $requestBadge; ?>">
                                                        <?php echo $requestStatus !== '' ? htmlspecialchars($requestStatus) : 'Not requested'; ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if (!empty($submission['file_path'])): ?>
                                                        <a class="btn btn-sm btn-outline-success" href="<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank">
                                                            View
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($canRequest): ?>
                                                        <button class="btn btn-sm btn-success ms-1 btn-request" data-bs-toggle="modal" data-bs-target="#requestModal<?php echo (int)$submission['id']; ?>">
                                                            Request
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($canRequest): ?>
                                                <?php ob_start(); ?>
                                                <div class="modal fade" id="requestModal<?php echo (int)$submission['id']; ?>" tabindex="-1" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <form method="post" enctype="multipart/form-data" class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Send Endorsement</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <input type="hidden" name="request_verification" value="1">
                                                                <input type="hidden" name="hardbound_id" value="<?php echo (int)$submission['id']; ?>">
                                                                <p class="mb-3">Send this hardbound submission to the defense committee for signatures.</p>
                                                                <label class="form-label">Adviser Signature</label>
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
