<?php
session_start();
require_once 'db.php';
require_once 'committee_pdf_submission_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: login.php');
    exit;
}

ensureCommitteePdfTables($conn);

$student_id = (int)$_SESSION['user_id'];
$submissions = fetch_committee_pdf_submissions_for_student($conn, $student_id);

function committee_pdf_status_badge(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'reviewed' => 'bg-success-subtle text-success',
        default => 'bg-warning-subtle text-warning',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Committee PDF Submissions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card { border-radius: 18px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
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
                <h3 class="fw-bold text-success mb-1">Committee PDF Submissions</h3>
                <p class="text-muted mb-0">Upload your outline defense PDF for committee annotations.</p>
            </div>
            <span class="badge bg-success-subtle text-success">
                <?php echo count($submissions); ?> submitted
            </span>
        </div>

        <?php if (isset($_SESSION['committee_pdf_upload_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($_SESSION['committee_pdf_upload_success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php
                unset($_SESSION['committee_pdf_upload_success']);
                unset($_SESSION['committee_pdf_upload_submission_id']);
            ?>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">Upload New Committee PDF</h5>
                <form enctype="multipart/form-data" method="POST" action="committee_pdf_upload_handler.php">
                    <input type="hidden" name="action" value="upload">
                    <div class="mb-3">
                        <label class="form-label">Select PDF File</label>
                        <input type="file" class="form-control" name="pdf_file" accept=".pdf" required>
                        <small class="text-muted">Maximum file size: 50MB</small>
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-cloud-upload me-2"></i>Upload PDF
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Your Committee Submissions</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($submissions)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-file-earmark-text fs-2 d-block mb-2"></i>
                        No committee submissions yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Filename</th>
                                    <th>Version</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submissions as $submission): ?>
                                    <?php
                                        $badge = committee_pdf_status_badge($submission['submission_status'] ?? '');
                                        $submitted = $submission['submitted_at'] ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : 'N/A';
                                    ?>
                                    <tr>
                                        <td>
                                            <i class="bi bi-file-pdf text-danger me-2"></i>
                                            <?php echo htmlspecialchars($submission['original_filename'] ?? ''); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-success">v<?php echo (int)($submission['version_number'] ?? 1); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge; ?>">
                                                <?php echo htmlspecialchars($submission['submission_status'] ?? 'pending'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($submitted); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-success" href="student_committee_pdf_view.php?submission_id=<?php echo (int)$submission['id']; ?>">
                                                View
                                            </a>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
