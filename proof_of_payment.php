<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'program_chairperson'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$userEmail = $_SESSION['email'] ?? '';

$error = '';
$success = '';

// ------------------------------------------------------------------
// Student upload
// ------------------------------------------------------------------
if ($role === 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_proof'])) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $reference = trim($_POST['reference_number'] ?? '');
    $file = $_FILES['payment_proof'];

    if ($reference === '' || !preg_match('/^\d+$/', $reference)) {
        $error = 'Reference number must contain digits only.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK || !in_array($file['type'], $allowedTypes)) {
        $error = 'Invalid file upload.';
    } else {
        $uploadDir = 'uploads/payments/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filename = uniqid('proof_', true) . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        $target = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $target)) {
            $existingId = null;
            $existingStmt = $conn->prepare("SELECT id FROM payment_proofs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            if ($existingStmt) {
                $existingStmt->bind_param('i', $userId);
                $existingStmt->execute();
                $existingRow = $existingStmt->get_result()->fetch_assoc();
                $existingStmt->close();
                $existingId = $existingRow ? (int)$existingRow['id'] : null;
            }

            if ($existingId) {
                $stmt = $conn->prepare("
                    UPDATE payment_proofs
                    SET user_email = ?,
                        file_path = ?,
                        reference_number = ?,
                        status = 'pending',
                        notes = NULL,
                        created_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                if ($stmt) {
                    $stmt->bind_param('sssi', $userEmail, $target, $reference, $existingId);
                }
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO payment_proofs (user_id, user_email, file_path, reference_number, status, created_at)
                    VALUES (?, ?, ?, ?, 'pending', NOW())
                ");
                if ($stmt) {
                    $stmt->bind_param('isss', $userId, $userEmail, $target, $reference);
                }
            }

            if ($stmt && $stmt->execute()) {
                $success = 'Payment proof uploaded successfully.';
                notify_roles(
                    $conn,
                    ['program_chairperson', 'committee_chairperson', 'committee_chair'],
                    'New payment proof submitted',
                    'A student uploaded a new payment proof. Review it on your dashboard.',
                    'receive_payment.php'
                );
            } else {
                $error = 'Failed to save payment info.';
            }
            if ($stmt) {
                $stmt->close();
            }
        } else {
            $error = 'Failed to move uploaded file.';
        }
    }
}

// ------------------------------------------------------------------
// Program chair updates status
// ------------------------------------------------------------------
if ($role === 'program_chairperson' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_proof_id'])) {
    $proofId = (int)$_POST['update_proof_id'];
    $newStatus = $_POST['status'] ?? 'pending';
    $remarks = trim($_POST['remarks']);

    $validStatuses = ['pending', 'payment_declined', 'payment_accepted'];
    if (in_array($newStatus, $validStatuses, true)) {
        $ownerStmt = $conn->prepare("SELECT user_id FROM payment_proofs WHERE id = ? LIMIT 1");
        $ownerStmt->bind_param('i', $proofId);
        $ownerStmt->execute();
        $ownerResult = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();

        if ($ownerResult) {
            $stmt = $conn->prepare("UPDATE payment_proofs SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('ssi', $newStatus, $remarks, $proofId);
            if ($stmt->execute()) {
                $success = 'Payment proof updated successfully.';

                $statusLabel = ucwords(str_replace('_', ' ', $newStatus));
                $message = "Your payment proof status is now {$statusLabel}.";
                if ($remarks !== '') {
                    $message .= " Remarks: {$remarks}.";
                }

                notify_user(
                    $conn,
                    (int)$ownerResult['user_id'],
                    'Payment proof updated',
                    $message,
                    'proof_of_payment.php'
                );
            } else {
                $error = 'Failed to update payment.';
            }
            $stmt->close();
        } else {
            $error = 'Payment record not found.';
        }
    }
}

// ------------------------------------------------------------------
// Fetch data
// ------------------------------------------------------------------
if ($role === 'student') {
    $stmt = $conn->prepare("SELECT * FROM payment_proofs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $userId);
} else {
    $stmt = $conn->prepare("
        SELECT p.*, u.email AS student_email
        FROM payment_proofs p
        JOIN (
            SELECT user_id, MAX(id) AS latest_id
            FROM payment_proofs
            GROUP BY user_id
        ) latest ON latest.latest_id = p.id
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC
    ");
}
$stmt->execute();
$result = $stmt->get_result();
$proofs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stats = [
    'total' => count($proofs),
    'pending' => 0,
    'accepted' => 0,
    'declined' => 0,
];
foreach ($proofs as $proof) {
    if ($proof['status'] === 'payment_accepted') {
        $stats['accepted']++;
    } elseif ($proof['status'] === 'payment_declined') {
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
    <title>Proof of Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background: #f3f9f3; }
        #main-content { margin-left: 260px; padding: 30px; transition: margin-left .3s ease; }
        @media (max-width: 768px) { #main-content { margin-left: 0; padding: 20px; } }
        .stats-card { border-radius: 16px; border: 1px solid rgba(22,86,44,.12); box-shadow: 0 12px 24px rgba(22,86,44,.08); background: #fff; }
        .stats-icon { width: 48px; height: 48px; border-radius: 12px; display:flex; align-items:center; justify-content:center; font-size:1.25rem; }
        .receipt-img { border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,.15); }
        .table-green thead tr { background: #16562c !important; color: #fff; }
        .timeline { border-left: 3px solid #c8e7d4; padding-left: 1.5rem; margin-left: 1rem; }
        .timeline-item { position: relative; margin-bottom: 1.5rem; }
        .timeline-item::before { content:''; position:absolute; left:-1.65rem; top:6px; width:10px; height:10px; border-radius:50%; background:#16562c; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div id="main-content">
    <h2 class="fw-bold text-success mb-4">Proof of Payment</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-success-subtle text-success"><i class="bi bi-check-circle"></i></div>
                <div>
                    <small class="text-muted">Accepted</small>
                    <h4 class="mb-0"><?= $stats['accepted']; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <small class="text-muted">Pending</small>
                    <h4 class="mb-0"><?= $stats['pending']; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-danger-subtle text-danger"><i class="bi bi-x-circle"></i></div>
                <div>
                    <small class="text-muted">Declined</small>
                    <h4 class="mb-0"><?= $stats['declined']; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card p-3 d-flex align-items-center gap-3">
                <div class="stats-icon bg-primary-subtle text-primary"><i class="bi bi-receipt"></i></div>
                <div>
                    <small class="text-muted">Total Uploads</small>
                    <h4 class="mb-0"><?= $stats['total']; ?></h4>
                </div>
            </div>
        </div>
    </div>

    <?php if ($role === 'student'): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title text-success mb-3">Upload Your Payment Receipt</h5>
                <form method="POST" enctype="multipart/form-data">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Receipt (JPG/PNG)</label>
                            <input type="file" name="payment_proof" class="form-control" accept=".jpg,.jpeg,.png" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="Digits only" inputmode="numeric" pattern="\d+" maxlength="20" required>
                            <small class="text-muted">Use the numeric reference from your bank/receipt.</small>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-success px-4"><i class="bi bi-cloud-upload"></i> Upload</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Payment Timeline</div>
            <div class="card-body timeline">
                <?php if (empty($proofs)): ?>
                    <div class="text-muted">No payments submitted yet.</div>
                <?php else: ?>
                    <?php foreach ($proofs as $proof): ?>
                        <div class="timeline-item">
                            <div class="d-flex justify-content-between">
                                <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $proof['status']))); ?></strong>
                                <small class="text-muted"><?= date('M d, Y h:i A', strtotime($proof['created_at'])); ?></small>
                            </div>
                            <div class="small text-muted">Reference: <?= htmlspecialchars($proof['reference_number'] ?? 'N/A'); ?></div>
                            <?php if (!empty($proof['notes'])): ?>
                                <div class="small">Remarks: <?= htmlspecialchars($proof['notes']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-2"></i>Payment Submissions</span>
            <small class="text-muted">Showing <?= count($proofs); ?> entries</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-green align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <?php if ($role === 'program_chairperson'): ?>
                                <th>Student Email</th>
                            <?php endif; ?>
                            <th>Receipt</th>
                            <th>Reference No.</th>
                            <th>Remarks</th>
                            <th>Status</th>
                            <th>Uploaded At</th>
                            <?php if ($role === 'program_chairperson'): ?>
                                <th>Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($proofs)): ?>
                            <tr><td colspan="<?= $role === 'program_chairperson' ? 8 : 7; ?>" class="text-center text-muted">No payment proofs found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($proofs as $proof): ?>
                                <tr>
                                    <td><?= $proof['id']; ?></td>
                                    <?php if ($role === 'program_chairperson'): ?>
                                        <td><?= htmlspecialchars($proof['student_email'] ?? ''); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <a href="<?= htmlspecialchars($proof['file_path']); ?>" target="_blank" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($proof['reference_number'] ?? '-'); ?></td>
                                    <td><?= htmlspecialchars($proof['notes'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-<?=
                                            $proof['status'] === 'payment_accepted' ? 'success' :
                                            ($proof['status'] === 'payment_declined' ? 'danger' : 'warning text-dark');
                                        ?>">
                                            <?= ucwords(str_replace('_', ' ', $proof['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?= date('M d, Y h:i A', strtotime($proof['created_at'])); ?></td>
                                    <?php if ($role === 'program_chairperson'): ?>
                                        <td>
                                            <?php
                                                $statusActionClass = $proof['status'] === 'payment_declined'
                                                    ? 'btn-danger'
                                                    : ($proof['status'] === 'payment_accepted' ? 'btn-success' : 'btn-warning text-dark');
                                            ?>
                                            <form method="POST" class="d-flex flex-column flex-lg-row gap-2">
                                                <input type="hidden" name="update_proof_id" value="<?= $proof['id']; ?>">
                                                <select name="status" class="form-select form-select-sm">
                                                    <option value="pending" <?= $proof['status']==='pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="payment_accepted" <?= $proof['status']==='payment_accepted' ? 'selected' : ''; ?>>Accepted</option>
                                                    <option value="payment_declined" <?= $proof['status']==='payment_declined' ? 'selected' : ''; ?>>Declined</option>
                                                </select>
                                                <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Remarks" value="<?= htmlspecialchars($proof['notes'] ?? ''); ?>">
                                                <button type="submit" class="btn btn-sm <?= $statusActionClass; ?>">Update</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
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
