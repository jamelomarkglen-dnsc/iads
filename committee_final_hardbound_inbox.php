<?php
session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';

$allowedRoles = ['committee_chairperson', 'committee_chair', 'panel'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureFinalHardboundTables($conn);

$reviewer_id = (int)($_SESSION['user_id'] ?? 0);
$requests = fetch_final_hardbound_committee_requests_for_reviewer($conn, $reviewer_id);
$highlight_request_id = (int)($_GET['request_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Hardbound Inbox</title>
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
                <h3 class="fw-bold text-success mb-1">Final Hardbound Inbox</h3>
                <p class="text-muted mb-0">Review adviser endorsements and upload your signature.</p>
            </div>
        </div>

        <?php if ($highlight_request_id > 0): ?>
            <div class="alert alert-info border-0 shadow-sm">
                An endorsement request is ready for your review.
            </div>
        <?php endif; ?>

        <div class="card inbox-card">
            <div class="card-body p-0">
                <?php if (empty($requests)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        No final hardbound endorsements assigned yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Title</th>
                                    <th>Submission</th>
                                    <th>Request</th>
                                    <th>Your Status</th>
                                    <th>Submitted</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <?php
                                        $submitted = $request['submitted_at'] ? date('M d, Y g:i A', strtotime($request['submitted_at'])) : 'N/A';
                                        $rowHighlight = $highlight_request_id === (int)$request['id'] ? 'table-success' : '';
                                        $requestDisplay = final_hardbound_display_status($request['status'] ?? 'Pending');
                                        $reviewDisplay = final_hardbound_display_status($request['review_status'] ?? 'Pending');
                                        $submissionDisplay = final_hardbound_display_status($request['submission_status'] ?? 'Submitted');
                                        $requestBadge = final_hardbound_status_badge($requestDisplay);
                                        $reviewBadge = final_hardbound_status_badge($reviewDisplay);
                                        $submissionBadge = final_hardbound_status_badge($submissionDisplay);
                                    ?>
                                    <tr class="<?php echo $rowHighlight; ?>">
                                        <td class="fw-semibold text-success"><?php echo htmlspecialchars(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($request['submission_title'] ?? ''); ?></td>
                                        <td><span class="badge <?php echo $submissionBadge; ?>"><?php echo htmlspecialchars($submissionDisplay); ?></span></td>
                                        <td><span class="badge <?php echo $requestBadge; ?>"><?php echo htmlspecialchars($requestDisplay); ?></span></td>
                                        <td><span class="badge <?php echo $reviewBadge; ?>"><?php echo htmlspecialchars($reviewDisplay); ?></span></td>
                                        <td><?php echo htmlspecialchars($submitted); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-success" href="committee_final_hardbound_review.php?request_id=<?php echo (int)$request['id']; ?>">
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
