<?php
session_start();
require_once 'db.php';
require_once 'committee_pdf_submission_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chairperson', 'committee_chair'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureCommitteePdfTables($conn);

$reviewer_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewer_role = $roleMap[$role] ?? $role;

$submissions = fetch_committee_pdf_submissions_for_reviewer($conn, $reviewer_id, $reviewer_role);
$highlight_submission_id = (int)($_GET['submission_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Committee PDF Inbox</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .inbox-card { border-radius: 18px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
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
                <h3 class="fw-bold text-success mb-1">Committee PDF Inbox</h3>
                <p class="text-muted mb-0">Review committee PDF submissions assigned to you.</p>
            </div>
        </div>

        <?php if ($highlight_submission_id > 0): ?>
            <div class="alert alert-info border-0 shadow-sm">
                Submission is ready for your review.
            </div>
        <?php endif; ?>

        <div class="card inbox-card">
            <div class="card-body p-0">
                <?php if (empty($submissions)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        No committee PDF submissions assigned yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
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
                                        $submitted = $submission['submitted_at'] ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : 'N/A';
                                        $rowHighlight = $highlight_submission_id === (int)$submission['id'] ? 'table-success' : '';
                                    ?>
                                    <tr class="<?php echo $rowHighlight; ?>">
                                        <td class="fw-semibold text-success"><?php echo htmlspecialchars($submission['student_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($submission['original_filename'] ?? ''); ?></td>
                                        <td><span class="badge bg-light text-success">v<?php echo (int)($submission['version_number'] ?? 1); ?></span></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary"><?php echo htmlspecialchars($submission['submission_status'] ?? 'pending'); ?></span></td>
                                        <td><?php echo htmlspecialchars($submitted); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-success" href="committee_pdf_review.php?submission_id=<?php echo (int)$submission['id']; ?>">
                                                Review
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
