<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'dean') {
    header("Location: login.php");
    exit;
}

$message = '';
$token = $_SESSION['verify_token'] ?? bin2hex(random_bytes(16));
$_SESSION['verify_token'] = $token;

function column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $tableEscaped = $conn->real_escape_string($table);
    $columnEscaped = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableEscaped' AND COLUMN_NAME = '$columnEscaped' LIMIT 1";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    $cache[$key] = $exists;
    return $exists;
}

function ensure_column(mysqli $conn, string $table, string $column, string $definition): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return column_exists($conn, $table, $column);
    }
    if (column_exists($conn, $table, $column)) {
        return true;
    }
    $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
    try {
        return (bool)$conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

$hasAccountStatus = ensure_column($conn, 'users', 'account_status', "VARCHAR(20) NOT NULL DEFAULT 'approved'");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        $message = "<div class='alert alert-danger'>Invalid verification token. Please refresh the page.</div>";
    } elseif (!$hasAccountStatus) {
        $message = "<div class='alert alert-danger'>Account verification is not available right now.</div>";
    } else {
        $action = $_POST['action'];
        $userId = (int)$_POST['user_id'];
        $newStatus = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : '');

        if ($newStatus === '') {
            $message = "<div class='alert alert-danger'>Invalid action.</div>";
        } else {
            $stmt = $conn->prepare("
                UPDATE users
                SET account_status = ?
                WHERE id = ? AND role = 'program_chairperson' AND account_status = 'pending'
            ");
            if ($stmt) {
                $stmt->bind_param('si', $newStatus, $userId);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $message = "<div class='alert alert-success'>Program chairperson account updated.</div>";
                } else {
                    $message = "<div class='alert alert-warning'>No pending account found or already processed.</div>";
                }
                $stmt->close();
            } else {
                $message = "<div class='alert alert-danger'>Unable to process verification right now.</div>";
            }
        }
    }
}

$pendingAccounts = [];
if ($hasAccountStatus) {
    $sql = "
        SELECT id, firstname, lastname, email, department, college, program, contact, gender, created_at
        FROM users
        WHERE role = 'program_chairperson' AND account_status = 'pending'
        ORDER BY created_at DESC
    ";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pendingAccounts[] = $row;
        }
        $result->free();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Program Chairpersons</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body.verify-page {
            min-height: 100vh;
            background: #f5f8f5;
        }
        .content {
            margin-left: 220px;
            padding: 28px 24px;
            min-height: 100vh;
            transition: margin-left .3s;
        }
        #sidebar.collapsed ~ .content {
            margin-left: 60px;
        }
        .table th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #47654c;
        }
        @media (max-width: 992px) {
            .content {
                margin-left: 0;
                padding: 24px 16px;
            }
        }
    </style>
</head>
<body class="verify-page">
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
<main class="container py-5 px-3 px-md-4" style="max-width: 1100px;">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h4 fw-semibold text-success mb-1">Program Chairperson Verification</h1>
            <p class="text-muted mb-0">Approve or reject pending program chairperson registrations.</p>
        </div>
        <span class="badge bg-success-subtle text-success fs-6">
            Pending: <?php echo count($pendingAccounts); ?>
        </span>
    </div>

    <?php echo $message; ?>

    <?php if (!$hasAccountStatus): ?>
        <div class="alert alert-warning">Account verification is not available right now.</div>
    <?php elseif (empty($pendingAccounts)): ?>
        <div class="alert alert-info">No pending program chairperson accounts.</div>
    <?php else: ?>
        <div class="table-responsive bg-white shadow-sm rounded-3">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Program</th>
                        <th>Department</th>
                        <th>College</th>
                        <th>Contact</th>
                        <th>Gender</th>
                        <th>Requested</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingAccounts as $account): ?>
                        <tr>
                            <td class="fw-semibold">
                                <?php echo htmlspecialchars(trim(($account['firstname'] ?? '') . ' ' . ($account['lastname'] ?? ''))); ?>
                            </td>
                            <td><?php echo htmlspecialchars((string)($account['email'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($account['program'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($account['department'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($account['college'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($account['contact'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($account['gender'] ?? '')); ?></td>
                            <td>
                                <?php
                                $createdAt = (string)($account['created_at'] ?? '');
                                echo $createdAt !== '' ? htmlspecialchars(date('M d, Y g:i A', strtotime($createdAt))) : 'N/A';
                                ?>
                            </td>
                            <td class="text-end">
                                <form method="POST" class="d-inline-flex gap-2">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$account['id']; ?>">
                                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                    <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">
                                        Approve
                                    </button>
                                    <button type="submit" name="action" value="reject" class="btn btn-outline-danger btn-sm" onclick="return confirm('Reject this account?');">
                                        Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</div>
</body>
</html>
