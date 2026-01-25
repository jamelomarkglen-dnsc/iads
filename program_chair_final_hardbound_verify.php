<?php
session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header('Location: login.php');
    exit;
}

ensureFinalHardboundTables($conn);

$chair_id = (int)($_SESSION['user_id'] ?? 0);
$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_request'])) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $hardbound_id = (int)($_POST['hardbound_id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($request_id <= 0 || $hardbound_id <= 0 || !in_array($decision, ['Verified', 'Rejected'], true)) {
        $alert = ['type' => 'danger', 'message' => 'Please select a valid decision.'];
    } else {
        $request = null;
        $stmt = $conn->prepare("
            SELECT r.*, s.student_id, u.firstname, u.lastname
            FROM final_hardbound_requests r
            JOIN final_hardbound_submissions s ON s.id = r.hardbound_submission_id
            JOIN users u ON u.id = s.student_id
            WHERE r.id = ? AND r.program_chair_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ii', $request_id, $chair_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $request = $result ? $result->fetch_assoc() : null;
            if ($result) {
                $result->free();
            }
            $stmt->close();
        }

        if (!$request) {
            $alert = ['type' => 'danger', 'message' => 'Request not found.'];
        } else {
            $ok = update_final_hardbound_request($conn, $request_id, $hardbound_id, $decision, $chair_id, $remarks);
            if ($ok) {
                $student_id = (int)($request['student_id'] ?? 0);
                $adviser_id = (int)($request['adviser_id'] ?? 0);
                $student_name = trim(($request['firstname'] ?? '') . ' ' . ($request['lastname'] ?? '')) ?: 'the student';
                $message = "Final hardbound request for {$student_name} has been {$decision}.";
                $link = 'student_final_hardbound_submission.php';

                if ($student_id > 0) {
                    notify_user(
                        $conn,
                        $student_id,
                        'Final hardbound decision',
                        $message,
                        $link,
                        false
                    );
                }
                if ($adviser_id > 0) {
                    notify_user(
                        $conn,
                        $adviser_id,
                        'Final hardbound decision',
                        $message,
                        'adviser_final_hardbound_request.php',
                        false
                    );
                }

                $alert = ['type' => 'success', 'message' => "Request {$decision}."];
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to update the request.'];
            }
        }
    }
}

$pendingRequests = fetch_pending_hardbound_requests_for_chair($conn, $chair_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Hardbound Verification</title>
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
        .btn-review { min-width: 90px; }
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
                <h3 class="fw-bold text-success mb-1">Final Hardbound Verification</h3>
                <p class="text-muted mb-0">Review adviser requests and verify hardbound submissions.</p>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>">
                <?php echo htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Pending Verification Requests</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pendingRequests)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                        No pending hardbound requests.
                    </div>
                        <?php else: ?>
                            <?php $modalBlocks = []; ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                    <th>Student</th>
                                    <th>Title</th>
                                    <th>Adviser</th>
                                    <th>Submitted</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRequests as $row): ?>
                                    <?php
                                        $studentName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: 'Student';
                                        $adviserName = trim(($row['adviser_firstname'] ?? '') . ' ' . ($row['adviser_lastname'] ?? '')) ?: 'Adviser';
                                        $submitted = $row['submitted_at'] ? date('M d, Y g:i A', strtotime($row['submitted_at'])) : 'N/A';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold text-success"><?php echo htmlspecialchars($studentName); ?></td>
                                        <td><?php echo htmlspecialchars($row['submission_title'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($adviserName); ?></td>
                                        <td><?php echo htmlspecialchars($submitted); ?></td>
                                            <td class="text-end">
                                                <?php if (!empty($row['file_path'])): ?>
                                                    <a class="btn btn-sm btn-outline-success" href="<?php echo htmlspecialchars($row['file_path']); ?>" target="_blank">
                                                        View
                                                    </a>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-success ms-1 btn-review" data-bs-toggle="modal" data-bs-target="#verifyModal<?php echo (int)$row['id']; ?>">
                                                    Review
                                                </button>
                                            </td>
                                        </tr>

                                        <?php ob_start(); ?>
                                        <div class="modal fade" id="verifyModal<?php echo (int)$row['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <form method="post" class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Verify Hardbound</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="review_request" value="1">
                                                        <input type="hidden" name="request_id" value="<?php echo (int)$row['id']; ?>">
                                                        <input type="hidden" name="hardbound_id" value="<?php echo (int)$row['hardbound_submission_id']; ?>">
                                                        <label class="form-label">Decision</label>
                                                        <select name="decision" class="form-select mb-3" required>
                                                            <option value="">Select...</option>
                                                            <option value="Verified">Verify</option>
                                                            <option value="Rejected">Reject</option>
                                                        </select>
                                                        <label class="form-label">Remarks (optional)</label>
                                                        <textarea name="remarks" class="form-control" rows="3" placeholder="Notes for adviser/student..."></textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">Submit</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                        <?php $modalBlocks[] = ob_get_clean(); ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($modalBlocks)) echo implode("\n", $modalBlocks); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
