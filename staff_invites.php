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
            background:
                radial-gradient(circle at top left, rgba(22, 86, 44, 0.10), transparent 30%),
                radial-gradient(circle at bottom right, rgba(13, 110, 253, 0.06), transparent 28%),
                linear-gradient(135deg, #eef4ef, #f8fbf8);
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
        }
        .content {
            margin-left: 220px;
            padding: 2rem 2.25rem 2.5rem;
            min-height: 100vh;
        }
        #sidebar.collapsed ~ .content {
            margin-left: 70px;
        }
        .page-shell {
            max-width: 1240px;
            margin: 0 auto;
        }
        .page-hero {
            background: linear-gradient(135deg, rgba(22, 86, 44, 0.96), rgba(15, 62, 31, 0.98));
            color: #fff;
            border-radius: 1.5rem;
            padding: 1.35rem 1.5rem;
            box-shadow: 0 18px 35px rgba(22, 86, 44, 0.14);
            margin-bottom: 1.25rem;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: "";
            position: absolute;
            inset: auto -10% -45% auto;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }
        .page-hero h1 {
            margin: 0;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .page-hero p {
            margin: 0.35rem 0 0;
            color: rgba(255, 255, 255, 0.82);
        }
        .hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.9rem;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 0.88rem;
            font-weight: 600;
        }
        .invite-card {
            border: 1px solid rgba(22, 86, 44, 0.10);
            border-radius: 1.35rem;
            box-shadow: 0 18px 40px rgba(22, 86, 44, 0.08);
            overflow: hidden;
        }
        .invite-header {
            background: #ffffff;
            color: #1f2937;
            border-bottom: 1px solid #e7ece8;
            border-radius: 0;
        }
        .invite-header .h4,
        .invite-header .h5 {
            color: #13251a;
            font-weight: 800;
        }
        .invite-header .invite-subtitle {
            color: #5d6a61;
        }
        .form-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #1f5132;
            margin-bottom: 0.35rem;
        }
        .form-control,
        .form-select {
            border-radius: 0.85rem;
            min-height: 44px;
            border-color: #d7e2db;
        }
        .form-control:focus,
        .form-select:focus {
            border-color: #7bbd96;
            box-shadow: 0 0 0 0.18rem rgba(25, 135, 84, 0.12);
        }
        .invite-button {
            min-height: 46px;
            border-radius: 0.9rem;
            font-weight: 700;
            width: 100%;
        }
        .token-box {
            font-family: Consolas, monospace;
            word-break: break-all;
            padding: 0.9rem 1rem;
            border-radius: 0.95rem;
            background: #f6fbf7;
            border: 1px dashed rgba(22, 86, 44, 0.22);
        }
        .token-box a {
            color: #16562c;
            text-decoration: none;
            font-weight: 600;
        }
        .token-box a:hover {
            text-decoration: underline;
        }
        .invite-tip {
            color: #4e6456;
            font-size: 0.92rem;
        }
        .invite-list {
            max-height: 720px;
            overflow: auto;
        }
        .invite-item {
            transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .invite-item:hover {
            background: #f8fbf8;
        }
        .invite-role {
            color: #12381f;
            font-weight: 700;
        }
        .invite-email,
        .invite-expiry {
            color: #66776d;
            font-size: 0.93rem;
        }
        .status-pill {
            min-width: 64px;
            text-align: center;
            border-radius: 999px;
            padding: 0.35rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .invite-meta {
            font-size: 0.86rem;
            color: #6b7b70;
        }
        @media (min-width: 992px) {
            .sticky-lg {
                position: sticky;
                top: 1.25rem;
            }
        }
        @media (max-width: 991.98px) {
            .content {
                margin-left: 70px;
                padding: 1.25rem 1rem 1.5rem;
            }
        }
        @media (max-width: 575.98px) {
            .content {
                padding: 1rem 0.75rem 1.25rem;
            }
            .page-hero {
                border-radius: 1.15rem;
                padding: 1.15rem 1rem;
            }
            .invite-card {
                border-radius: 1.1rem;
            }
            .invite-list {
                max-height: none;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="page-shell">
        <div class="page-hero">
            <h1 class="h3">Registration Invites</h1>
            <p>Create invite-only access for DNSC faculty and program chairperson accounts.</p>
            <div class="hero-badges">
                <span class="hero-badge"><i class="bi bi-shield-lock"></i> Dean-only access</span>
                <span class="hero-badge"><i class="bi bi-link-45deg"></i> One-time invite links</span>
                <span class="hero-badge"><i class="bi bi-person-check"></i> Email-locked onboarding</span>
            </div>
        </div>
        <div class="container-fluid p-0">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card invite-card">
                    <div class="card-header invite-header py-4 px-4">
                        <h1 class="h4 mb-1">Invite Details</h1>
                        <p class="mb-0 invite-subtitle">Configure the role, email, and validity period.</p>
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
                                <div class="invite-tip mt-2">Send this exact link to the invited DNSC account holder. It can be used only once.</div>
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
                                <button type="submit" name="create_invite" class="btn btn-success invite-button">
                                    <i class="bi bi-link-45deg me-1"></i>Create Invite
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card invite-card sticky-lg">
                    <div class="card-header invite-header py-4 px-4">
                        <h2 class="h5 mb-1">Invite Log</h2>
                        <p class="mb-0 invite-subtitle">Track invite status at a glance.</p>
                    </div>
                    <div class="card-body p-0 invite-list">
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
                                    <div class="list-group-item py-3 invite-item">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div class="flex-grow-1">
                                                <div class="invite-role"><?php echo htmlspecialchars(registration_invite_role_label((string)$invite['role'])); ?></div>
                                                <div class="invite-email"><?php echo htmlspecialchars((string)$invite['email']); ?></div>
                                                <div class="invite-expiry">Expires: <?php echo htmlspecialchars((string)$invite['expires_at']); ?></div>
                                            </div>
                                            <span class="badge <?php echo $statusClass; ?> status-pill"><?php echo htmlspecialchars($statusLabel); ?></span>
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
</div>
</body>
</html>
