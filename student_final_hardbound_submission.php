<?php
session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: login.php');
    exit;
}

ensureFinalHardboundTables($conn);

$student_id = (int)$_SESSION['user_id'];
$routing_submission = fetch_latest_passed_final_routing($conn, $student_id);
$latest = fetch_latest_final_hardbound_submission($conn, $student_id);
$submissions = fetch_final_hardbound_submissions_for_student($conn, $student_id);
$latest_request = $latest ? fetch_final_hardbound_request($conn, (int)$latest['id']) : null;

$routingReady = $routing_submission !== null;
$canUpload = $routingReady && (!$latest || in_array(($latest['status'] ?? ''), ['Rejected'], true));

$step1Status = $latest ? 'Completed' : ($canUpload ? 'Ready' : 'Locked');
$step2Status = $latest_request ? ($latest_request['status'] ?? 'Pending') : 'Not requested';
$step3Status = $latest_request && ($latest_request['status'] ?? '') === 'Verified' ? 'Verified' : 'Pending';

$statusBadge = $latest ? final_hardbound_status_badge($latest['status'] ?? '') : 'bg-secondary-subtle text-secondary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Hardbound Submission</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card { border-radius: 18px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
        .stepper { display: flex; gap: 12px; flex-wrap: wrap; }
        .step-item { flex: 1 1 140px; background: #fff; border: 1px solid #e2e8e2; border-radius: 14px; padding: 12px 14px; }
        .step-item small { display: block; color: #6c757d; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.7rem; }
        .step-item .step-title { font-weight: 600; color: #16562c; }
        .step-item .step-status { font-size: 0.85rem; color: #4e5d4e; }
        .status-list { list-style: none; padding: 0; margin: 0; }
        .status-list li { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eef2ef; font-size: 0.9rem; }
        .status-list li:last-child { border-bottom: 0; }
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
                <h3 class="fw-bold text-success mb-1">Final Hardbound Submission</h3>
                <p class="text-muted mb-0">Upload the final hardbound PDF and track adviser verification.</p>
            </div>
            <?php if ($latest): ?>
                <span class="badge <?php echo $statusBadge; ?>">
                    <?php echo htmlspecialchars($latest['status'] ?? 'Submitted'); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="stepper mb-4">
            <div class="step-item">
                <small>Step 1</small>
                <div class="step-title">Upload Hardbound</div>
                <div class="step-status"><?php echo htmlspecialchars($step1Status); ?></div>
            </div>
            <div class="step-item">
                <small>Step 2</small>
                <div class="step-title">Adviser Request</div>
                <div class="step-status"><?php echo htmlspecialchars($step2Status); ?></div>
            </div>
            <div class="step-item">
                <small>Step 3</small>
                <div class="step-title">Chair Verification</div>
                <div class="step-status"><?php echo htmlspecialchars($step3Status); ?></div>
            </div>
        </div>

        <?php if (isset($_SESSION['final_hardbound_upload_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($_SESSION['final_hardbound_upload_success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['final_hardbound_upload_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['final_hardbound_upload_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($_SESSION['final_hardbound_upload_error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['final_hardbound_upload_error']); ?>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="fw-semibold mb-3">Upload Final Hardbound PDF</h5>
                        <?php if (!$routingReady): ?>
                            <div class="alert alert-warning">
                                Final routing must be marked as <strong>Passed</strong> before uploading the hardbound copy.
                            </div>
                        <?php endif; ?>
                        <?php if (!$canUpload): ?>
                            <div class="alert alert-info mb-0">
                                <?php if ($latest): ?>
                                    Your latest hardbound submission is currently <strong><?php echo htmlspecialchars($latest['status'] ?? 'Submitted'); ?></strong>.
                                    Please wait for adviser and chair verification.
                                <?php else: ?>
                                    Upload will unlock once final routing is passed.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <form enctype="multipart/form-data" method="POST" action="final_hardbound_upload_handler.php">
                                <div class="mb-3">
                                    <label class="form-label">Select PDF File</label>
                                    <input type="file" class="form-control" name="pdf_file" accept=".pdf" required>
                                    <small class="text-muted">Maximum file size: 50MB</small>
                                </div>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-cloud-upload me-2"></i>Upload Hardbound
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Your Hardbound Submissions</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($submissions)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-file-earmark-text fs-2 d-block mb-2"></i>
                                No hardbound submissions yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Filename</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submissions as $submission): ?>
                                            <?php
                                                $badge = final_hardbound_status_badge($submission['status'] ?? 'Submitted');
                                                $submitted = $submission['submitted_at'] ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : 'N/A';
                                            ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-file-pdf text-danger me-2"></i>
                                                    <?php echo htmlspecialchars($submission['original_filename'] ?? ''); ?>
                                                </td>
                                                <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($submission['status'] ?? 'Submitted'); ?></span></td>
                                                <td><?php echo htmlspecialchars($submitted); ?></td>
                                                <td class="text-end">
                                                    <?php if (!empty($submission['file_path'])): ?>
                                                        <a class="btn btn-sm btn-outline-success" href="<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank">
                                                            View
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <h5 class="fw-semibold mb-3">Status Overview</h5>
                        <ul class="status-list">
                            <li>
                                <span>Final Routing Verdict</span>
                                <span class="badge <?php echo $routingReady ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>">
                                    <?php echo $routingReady ? 'Passed' : 'Pending'; ?>
                                </span>
                            </li>
                            <li>
                                <span>Hardbound Upload</span>
                                <span class="badge <?php echo $latest ? final_hardbound_status_badge($latest['status'] ?? '') : 'bg-secondary-subtle text-secondary'; ?>">
                                    <?php echo $latest ? htmlspecialchars($latest['status'] ?? 'Submitted') : 'Not uploaded'; ?>
                                </span>
                            </li>
                            <li>
                                <span>Adviser Request</span>
                                <span class="badge <?php echo $latest_request ? final_hardbound_status_badge($latest_request['status'] ?? 'Pending') : 'bg-secondary-subtle text-secondary'; ?>">
                                    <?php echo $latest_request ? htmlspecialchars($latest_request['status'] ?? 'Pending') : 'Not requested'; ?>
                                </span>
                            </li>
                            <li>
                                <span>Program Chair Verification</span>
                                <span class="badge <?php echo $latest_request && ($latest_request['status'] ?? '') === 'Verified' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?>">
                                    <?php echo $latest_request && ($latest_request['status'] ?? '') === 'Verified' ? 'Verified' : 'Pending'; ?>
                                </span>
                            </li>
                        </ul>
                        <?php if ($latest && !empty($latest['review_notes'])): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <strong>Program Chair Notes:</strong>
                                <?php echo nl2br(htmlspecialchars($latest['review_notes'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
