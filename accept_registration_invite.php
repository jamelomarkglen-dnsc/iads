<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/registration_invite_helpers.php';

ensureRoleInfrastructure($conn);
ensure_registration_invites_table($conn);
ensure_registration_account_status_column($conn);

$inviteToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$invite = $inviteToken !== '' ? get_registration_invite_by_token($conn, $inviteToken) : null;
$error = '';
$success = '';

if ($inviteToken === '') {
    $error = 'Invite token is required.';
} elseif (!$invite) {
    $error = 'Invite is invalid, expired, or already used.';
} elseif (!in_array((string)$invite['role'], registration_invite_allowed_roles(), true)) {
    $error = 'This invite is not valid for registration.';
}

$role = $invite['role'] ?? '';
$email = $invite['email'] ?? '';
$oldInput = [
    'fullname' => '',
    'email' => $email,
    'contact' => '',
    'gender' => '',
    'college' => '',
    'department' => '',
    'username' => '',
    'specialization' => '',
    'program_focus' => '',
];

function invite_split_name(string $name): array
{
    $parts = preg_split('/\s+/', trim($name));
    $first = array_shift($parts) ?? '';
    $last = implode(' ', $parts);
    return [$first, $last];
}

if (!$error && $invite) {
    if (!empty($invite['is_expired'])) {
        $error = 'This invite has expired.';
    } elseif (!empty($invite['is_used'])) {
        $error = 'This invite has already been used.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    foreach ($oldInput as $key => $value) {
        $oldInput[$key] = trim((string)($_POST[$key] ?? $value));
    }
    $passwordPlain = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $postedEmail = trim((string)($_POST['email'] ?? ''));

    if ($postedEmail === '' || strcasecmp($postedEmail, $email) !== 0) {
        $error = 'The invite email does not match this registration form.';
    } elseif ($passwordPlain === '' || $confirmPassword === '') {
        $error = 'Password and confirmation are required.';
    } elseif ($passwordPlain !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($passwordPlain) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($oldInput['fullname'] === '' || $oldInput['username'] === '') {
        $error = 'Full name and username are required.';
    } elseif ($oldInput['contact'] === '' || $oldInput['gender'] === '' || $oldInput['college'] === '' || $oldInput['department'] === '') {
        $error = 'Please complete all required profile fields.';
    } elseif ($role === 'program_chairperson' && $oldInput['program_focus'] === '') {
        $error = 'Program handled is required for program chairperson accounts.';
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param('ss', $postedEmail, $oldInput['username']);
            $checkStmt->execute();
            $checkStmt->store_result();
            if ($checkStmt->num_rows > 0) {
                $error = 'Email or username already exists.';
            }
            $checkStmt->close();
        } else {
            $error = 'Unable to validate account information.';
        }
    }

    if ($error === '') {
        [$firstname, $lastname] = invite_split_name($oldInput['fullname']);
        $passwordHashed = password_hash($passwordPlain, PASSWORD_DEFAULT);
        $accountStatus = 'approved';
        if ($role === 'faculty') {
            $columns = ['firstname', 'lastname', 'username', 'password', 'email', 'role', 'contact', 'gender', 'department', 'college', 'account_status'];
            $values = [
                $firstname,
                $lastname,
                $oldInput['username'],
                $passwordHashed,
                $postedEmail,
                'faculty',
                $oldInput['contact'],
                $oldInput['gender'],
                $oldInput['department'],
                $oldInput['college'],
                $accountStatus,
            ];
            $types = 'sssssssssss';

            if ($oldInput['specialization'] !== '') {
                $columns[] = 'specialization';
                $values[] = $oldInput['specialization'];
                $types .= 's';
            }

            $insertSql = sprintf(
                'INSERT INTO users (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?'))
            );
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) {
                $error = 'Unable to prepare account creation.';
            } else {
                $params = [$types];
                foreach ($values as $value) {
                    $params[] = $value;
                }
                $refs = [];
                foreach ($params as $key => $value) {
                    $refs[$key] = &$params[$key];
                }
                if (!call_user_func_array([$insertStmt, 'bind_param'], $refs) || !$insertStmt->execute()) {
                    $error = 'Unable to create the account.';
                } else {
                    $userId = (int)$conn->insert_id;
                    ensureRoleBundleAssignments($conn, $userId, $role);
                    consume_registration_invite($conn, (int)$invite['id'], $userId);
                    $success = 'Account created successfully. You can now log in.';
                }
                $insertStmt->close();
            }
        } elseif ($role === 'program_chairperson') {
            $columns = ['firstname', 'lastname', 'username', 'password', 'email', 'role', 'contact', 'gender', 'department', 'college', 'program', 'account_status'];
            $values = [
                $firstname,
                $lastname,
                $oldInput['username'],
                $passwordHashed,
                $postedEmail,
                'program_chairperson',
                $oldInput['contact'],
                $oldInput['gender'],
                $oldInput['department'],
                $oldInput['college'],
                $oldInput['program_focus'],
                $accountStatus,
            ];
            $types = 'ssssssssssss';

            $insertSql = sprintf(
                'INSERT INTO users (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?'))
            );
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) {
                $error = 'Unable to prepare account creation.';
            } else {
                $params = [$types];
                foreach ($values as $value) {
                    $params[] = $value;
                }
                $refs = [];
                foreach ($params as $key => $value) {
                    $refs[$key] = &$params[$key];
                }
                if (!call_user_func_array([$insertStmt, 'bind_param'], $refs) || !$insertStmt->execute()) {
                    $error = 'Unable to create the account.';
                } else {
                    $userId = (int)$conn->insert_id;
                    ensureRoleBundleAssignments($conn, $userId, $role);
                    consume_registration_invite($conn, (int)$invite['id'], $userId);
                    $success = 'Account created successfully. You can now log in.';
                }
                $insertStmt->close();
            }
        } else {
            $error = 'This invite is not valid for registration.';
        }
    }
}

if ($error !== '' && !$invite) {
    $role = '';
    $email = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #eef4ef, #fdfefe);
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
        }
        .invite-shell {
            max-width: 900px;
            margin: 4rem auto;
            padding: 0 1rem;
        }
        .invite-card {
            border: 0;
            border-radius: 1.25rem;
            box-shadow: 0 18px 40px rgba(22, 86, 44, 0.10);
        }
        .invite-card .card-header {
            background: linear-gradient(135deg, #16562c, #0f3e1f);
            color: #fff;
            border-radius: 1.25rem 1.25rem 0 0;
        }
        .role-pill {
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.22);
        }
        .invite-form-label {
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 600;
            color: #6b7a6f;
        }
    </style>
</head>
<body>
    <div class="invite-shell">
        <div class="card invite-card">
            <div class="card-header p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-center">
                    <div>
                        <h1 class="h4 mb-1">Complete Your Registration</h1>
                        <div class="text-white-50 small">Invite-based onboarding for <?php echo htmlspecialchars(registration_invite_role_label((string)$role)); ?></div>
                    </div>
                    <span class="badge role-pill px-3 py-2"><?php echo htmlspecialchars(registration_invite_role_label((string)$role)); ?></span>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                        <div class="mt-2"><a href="login.php" class="alert-link">Go to login</a></div>
                    </div>
                <?php elseif ($error === ''): ?>
                    <div class="alert alert-info">
                        This invite is reserved for <?php echo htmlspecialchars($email); ?>.
                    </div>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($inviteToken); ?>">
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Full Name</label>
                            <input type="text" name="fullname" class="form-control" value="<?php echo htmlspecialchars($oldInput['fullname']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($oldInput['username']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Contact Number</label>
                            <input type="text" name="contact" class="form-control" value="<?php echo htmlspecialchars($oldInput['contact']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Gender</label>
                            <select name="gender" class="form-select" required>
                                <option value="">Select gender</option>
                                <option value="Male" <?php echo $oldInput['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $oldInput['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                <option value="Prefer not to say" <?php echo $oldInput['gender'] === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Institute / College</label>
                            <input type="text" name="college" class="form-control" value="<?php echo htmlspecialchars($oldInput['college']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Department / Program Unit</label>
                            <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($oldInput['department']); ?>" required>
                        </div>
                        <?php if ($role === 'program_chairperson'): ?>
                            <div class="col-md-6">
                                <label class="form-label invite-form-label">Program Handled</label>
                                <input type="text" name="program_focus" class="form-control" value="<?php echo htmlspecialchars($oldInput['program_focus']); ?>" placeholder="e.g. MIT" required>
                            </div>
                        <?php else: ?>
                            <div class="col-md-6">
                                <label class="form-label invite-form-label">Specialization</label>
                                <input type="text" name="specialization" class="form-control" value="<?php echo htmlspecialchars($oldInput['specialization']); ?>" placeholder="Optional">
                            </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Password</label>
                            <input type="password" name="password" class="form-control" required minlength="8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label invite-form-label">Role</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars(registration_invite_role_label((string)$role)); ?>" disabled>
                            <div class="form-text">This role is fixed by the invite.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check2-circle me-1"></i>Complete Registration
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
