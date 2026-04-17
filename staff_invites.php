<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/registration_invite_helpers.php';

ensureRoleInfrastructure($conn);
ensure_registration_invites_table($conn);
ensure_registration_account_status_column($conn);
enforce_role_access(['dean']);

$currentPage = basename($_SERVER['PHP_SELF']);
$message = '';
$generatedLink = '';
$generatedInvite = null;
$formRole = 'faculty';
$formEmail = '';
$formDays = 7;
$appBaseUrl = '';

if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');
    $appBaseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($scriptDir !== '' ? $scriptDir : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_invite'])) {
    $formRole = trim((string)($_POST['role'] ?? 'faculty'));
    $formEmail = trim((string)($_POST['email'] ?? ''));
    $formDays = max(1, min(30, (int)($_POST['valid_days'] ?? 7)));

    if (!filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger">Please enter a valid email address.</div>';
    } elseif (!in_array($formRole, registration_invite_allowed_roles(), true)) {
        $message = '<div class="alert alert-danger">Invalid role selection.</div>';
    } else {
        $generatedInvite = create_registration_invite(
            $conn,
            $formEmail,
            $formRole,
            (int)($_SESSION['user_id'] ?? 0),
            $formDays
        );
        if ($generatedInvite) {
            $relativeLink = 'accept_registration_invite.php?token=' . urlencode($generatedInvite['token']);
            $generatedLink = $appBaseUrl !== '' ? rtrim($appBaseUrl, '/') . '/' . $relativeLink : $relativeLink;
            $message = '<div class="alert alert-success">Invite created successfully.</div>';
            $formEmail = '';
            $formDays = 7;
        } else {
            $message = '<div class="alert alert-danger">Unable to create the invite.</div>';
        }
    }
}

$recentInvites = [];
$stmt = $conn->prepare("
    SELECT id, email, role, expires_at, used_at, created_at
    FROM registration_invites
    ORDER BY created_at DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recentInvites[] = $row;
        }
        $result->free();
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registration Invites</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #eef4ef, #f8fbf8);
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
        }
        .content {
            margin-left: 220px;
            padding: 2.5rem;
            min-height: 100vh;
        }
        #sidebar.collapsed ~ .content {
            margin-left: 70px;
        }
        .invite-card {
            border: 0;
            border-radius: 1.25rem;
            box-shadow: 0 18px 40px rgba(22, 86, 44, 0.08);
        }
        .invite-header {
            background: linear-gradient(135deg, #16562c, #0f3e1f);
            color: #fff;
            border-radius: 1.25rem 1.25rem 0 0;
        }
        .token-box {
            font-family: Consolas, monospace;
            word-break: break-all;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid" style="max-width: 1180px;">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card invite-card">
                    <div class="card-header invite-header py-4 px-4">
                        <h1 class="h4 mb-1">Registration Invites</h1>
                        <p class="mb-0 text-white-50">Create invite-only access for faculty and program chairperson accounts.</p>
                    </div>
                    <div class="card-body p-4">
                        <?php echo $message; ?>
                        <?php if ($generatedLink): ?>
                            <div class="alert alert-info">
                                <div class="fw-semibold mb-1">Share this invite link:</div>
                                <div class="token-box">
                                    <a href="<?php echo htmlspecialchars($generatedLink); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo htmlspecialchars($generatedLink); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Role</label>
                                <select name="role" class="form-select" required>
                                    <option value="faculty" <?php echo $formRole === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                                    <option value="program_chairperson" <?php echo $formRole === 'program_chairperson' ? 'selected' : ''; ?>>Program Chairperson</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Valid for (days)</label>
                                <input type="number" name="valid_days" class="form-control" min="1" max="30" value="<?php echo (int)$formDays; ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email address</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($formEmail); ?>" placeholder="name@dnsc.edu.ph" required>
                                <div class="form-text">The invite is tied to this email address.</div>
                            </div>
                            <div class="col-12">
                                <button type="submit" name="create_invite" class="btn btn-success">
                                    <i class="bi bi-link-45deg me-1"></i>Create Invite
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card invite-card">
                    <div class="card-header invite-header py-4 px-4">
                        <h2 class="h5 mb-1">Recent Invites</h2>
                        <p class="mb-0 text-white-50">Track invite status at a glance.</p>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php if (empty($recentInvites)): ?>
                                <div class="list-group-item text-muted py-4 text-center">No invites yet.</div>
                            <?php else: ?>
                                <?php foreach ($recentInvites as $invite): ?>
                                    <?php
                                        $isUsed = !empty($invite['used_at']);
                                        $expiresAt = strtotime((string)($invite['expires_at'] ?? ''));
                                        $isExpired = $expiresAt ? ($expiresAt < time()) : true;
                                        $statusLabel = $isUsed ? 'Used' : ($isExpired ? 'Expired' : 'Active');
                                        $statusClass = $isUsed ? 'bg-secondary' : ($isExpired ? 'bg-danger' : 'bg-success');
                                    ?>
                                    <div class="list-group-item py-3">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars(registration_invite_role_label((string)$invite['role'])); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars((string)$invite['email']); ?></div>
                                                <div class="small text-muted">Expires: <?php echo htmlspecialchars((string)$invite['expires_at']); ?></div>
                                            </div>
                                            <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
