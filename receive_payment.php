<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header("Location: login.php");
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_id'])) {
    $paymentId = (int)$_POST['payment_id'];
    $status = $_POST['status'] ?? 'pending';
    $remarks = trim($_POST['remarks']);

    $valid = ['pending', 'payment_accepted', 'payment_declined'];
    if (in_array($status, $valid, true)) {
        $ownerStmt = $conn->prepare("SELECT user_id FROM payment_proofs WHERE id = ? LIMIT 1");
        $ownerStmt->bind_param('i', $paymentId);
        $ownerStmt->execute();
        $ownerResult = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();

        if ($ownerResult) {
            $stmt = $conn->prepare("UPDATE payment_proofs SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ssi', $status, $remarks, $paymentId);
            if ($stmt->execute()) {
                $message = 'Payment updated successfully!';

                $statusLabel = ucwords(str_replace('_', ' ', $status));
                $note = "Your payment submission #{$paymentId} is now {$statusLabel}.";
                if ($remarks !== '') {
                    $note .= " Remarks: {$remarks}.";
                }
                notify_user(
                    $conn,
                    (int)$ownerResult['user_id'],
                    'Payment status updated',
                    $note,
                    'proof_of_payment.php'
                );
            } else {
                $message = 'Failed to update payment.';
            }
            $stmt->close();
        } else {
            $message = 'Payment record not found.';
        }
    }
}

$query = "
    SELECT p.*, u.email AS student_email
    FROM payment_proofs p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.created_at DESC
";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
$payments = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stats = [
    'total' => count($payments),
    'accepted' => 0,
    'pending' => 0,
    'declined' => 0,
];
foreach ($payments as $payment) {
    if ($payment['status'] === 'payment_accepted') {
        $stats['accepted']++;
    } elseif ($payment['status'] === 'payment_declined') {
        $stats['declined']++;
    } else {
        $stats['pending']++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Received Payments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background: #f3f9f3; }
        #main-content { margin-left: 260px; padding: 30px; transition: margin-left .3s ease; }
        @media (max-width: 768px) { #main-content { margin-left: 0; padding: 20px; } }
        .stats-card { border-radius: 16px; border: 1px solid rgba(22,86,44,.12); box-shadow: 0 12px 24px rgba(22,86,44,.08); background:#fff; }
        .stats-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; }
        .table-green thead tr { background:#16562c !important; color:#fff; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <h2 class="fw-bold text-success mb-0">Received Payments</h2>
        <span class="badge bg-success fs-6">Total Accepted: <?= $stats['accepted']; ?></span>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info alert-dismissible fade show">
            <?= htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-success-subtle text-success"><i class="bi bi-check-circle"></i></div>
                <div><small class="text-muted">Accepted</small><h4 class="mb-0"><?= $stats['accepted']; ?></h4></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div><small class="text-muted">Pending</small><h4 class="mb-0"><?= $stats['pending']; ?></h4></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-danger-subtle text-danger"><i class="bi bi-x-circle"></i></div>
                <div><small class="text-muted">Declined</small><h4 class="mb-0"><?= $stats['declined']; ?></h4></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-primary-subtle text-primary"><i class="bi bi-receipt"></i></div>
                <div><small class="text-muted">Total Submissions</small><h4 class="mb-0"><?= $stats['total']; ?></h4></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-2"></i>Payment Submissions</span>
            <small class="text-muted">Showing <?= count($payments); ?> entries</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-green align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student Email</th>
                            <th>Receipt</th>
                            <th>Reference No.</th>
                            <th>Remarks</th>
                            <th>Status</th>
                            <th>Uploaded At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="8" class="text-center text-muted">No payments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= $payment['id']; ?></td>
                                    <td><?= htmlspecialchars($payment['student_email']); ?></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($payment['file_path']); ?>" target="_blank" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($payment['reference_number']); ?></td>
                                    <td><?= htmlspecialchars($payment['notes']); ?></td>
                                    <td>
                                        <?php if ($payment['status'] === 'payment_accepted'): ?>
                                            <span class="badge bg-success">Accepted</span>
                                        <?php elseif ($payment['status'] === 'payment_declined'): ?>
                                            <span class="badge bg-danger">Declined</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y h:i A', strtotime($payment['created_at'])); ?></td>
                                    <td>
                                        <?php
                                            $statusActionClass = $payment['status'] === 'payment_declined'
                                                ? 'btn-danger'
                                                : ($payment['status'] === 'payment_accepted' ? 'btn-success' : 'btn-warning text-dark');
                                        ?>
                                        <form method="post" class="d-flex flex-column flex-lg-row gap-2 align-items-start">
                                            <input type="hidden" name="payment_id" value="<?= $payment['id']; ?>">
                                            <select name="status" class="form-select form-select-sm">
                                                <option value="pending" <?= $payment['status']==='pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="payment_accepted" <?= $payment['status']==='payment_accepted' ? 'selected' : ''; ?>>Accepted</option>
                                                <option value="payment_declined" <?= $payment['status']==='payment_declined' ? 'selected' : ''; ?>>Declined</option>
                                            </select>
                                            <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Remarks" value="<?= htmlspecialchars($payment['notes']); ?>">
                                            <button type="submit" class="btn btn-sm <?= $statusActionClass; ?>">Update</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('form select[name="status"]').forEach((select) => {
        const form = select.closest('form');
        const button = form ? form.querySelector('button[type="submit"]') : null;
        if (!button) {
            return;
        }
        const updateClass = () => {
            button.classList.remove('btn-success', 'btn-danger', 'btn-warning', 'text-dark');
            if (select.value === 'payment_accepted') {
                button.classList.add('btn-success');
            } else if (select.value === 'payment_declined') {
                button.classList.add('btn-danger');
            } else {
                button.classList.add('btn-warning', 'text-dark');
            }
        };
        select.addEventListener('change', updateClass);
        updateClass();
    });
</script>
</body>
</html>
