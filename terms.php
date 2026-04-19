<?php
$inviteToken = trim((string)($_GET['token'] ?? ''));
$backTarget = trim((string)($_GET['back'] ?? ''));

$allowedBackTargets = [
    'accept_registration_invite.php',
    'register.php',
];

if (!in_array($backTarget, $allowedBackTargets, true)) {
    $backTarget = 'accept_registration_invite.php';
}

$backUrl = $backTarget;
if ($backTarget === 'accept_registration_invite.php' && $inviteToken !== '') {
    $backUrl .= '?token=' . rawurlencode($inviteToken);
} elseif ($backTarget === 'register.php') {
    $backUrl .= '#registration';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms &amp; Conditions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(22, 86, 44, 0.08), transparent 35%),
                linear-gradient(180deg, #f4f8f5 0%, #eef5f0 100%);
            font-family: 'Segoe UI', sans-serif;
        }
        .terms-shell {
            max-width: 860px;
            margin: 2.5rem auto;
            padding: 0 1rem;
        }
        .terms-card {
            border: 1px solid rgba(22, 86, 44, 0.14);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 14px 30px rgba(17, 73, 37, 0.08);
            overflow: hidden;
        }
        .terms-header {
            padding: 1.5rem 1.5rem 0.5rem;
            text-align: center;
        }
        .terms-title {
            color: #198754;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }
        .terms-body {
            padding: 0.75rem 1.5rem 1.5rem;
            color: #27372b;
        }
        .terms-body h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #1f5132;
            margin-top: 1rem;
        }
        .terms-body p,
        .terms-body li {
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .terms-body ul {
            padding-left: 1.25rem;
        }
        .back-link {
            color: #198754;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        @media (max-width: 575.98px) {
            .terms-shell {
                margin: 1rem auto;
                padding: 0 0.5rem;
            }
            .terms-header {
                padding: 1.1rem 1rem 0.4rem;
            }
            .terms-body {
                padding: 0.6rem 1rem 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="terms-shell">
        <div class="terms-card">
            <div class="terms-header">
                <h1 class="terms-title">Terms &amp; Conditions</h1>
                <p class="mb-0 text-muted">Davao del Norte State College account registration terms</p>
            </div>
            <div class="terms-body">
                <h2>1. Accuracy of Information</h2>
                <p>You certify that all information you provide to Davao del Norte State College is true, complete, and accurate to the best of your knowledge.</p>

                <h2>2. Account Responsibility</h2>
                <p>You are responsible for maintaining the confidentiality of your account credentials and for all activity performed under your account.</p>

                <h2>3. Proper Use</h2>
                <p>You agree to use the account only for authorized academic and institutional purposes and to follow all applicable school rules, policies, and procedures.</p>

                <h2>4. Verification and Approval</h2>
                <p>You understand that the College may verify your details before or after account creation and may reject, suspend, or disable accounts with incomplete, inaccurate, or false information.</p>

                <h2>5. Data Privacy</h2>
                <p>You consent to the collection, storage, and processing of your information for account creation, verification, and system administration in accordance with the College's data privacy and records policies.</p>

                <h2>6. Violation of Terms</h2>
                <p>Any violation of these terms may result in account suspension, denial, or removal without prior notice, subject to College review and applicable policy.</p>

                <div class="mt-4">
                    <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES); ?>" class="back-link">Go back to registration</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
