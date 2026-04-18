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
    'first_name' => '',
    'last_name' => '',
    'email' => $email,
    'contact' => '',
    'gender' => '',
    'college' => '',
    'department' => '',
    'specialization' => '',
];

$programOptions = [
    'PHDEM' => 'Doctor of Philosophy in Educational Management (PHDEM)',
    'MAEM' => 'Master of Arts in Educational Management (MAEM)',
    'MAED-ELST' => 'Master of Education Major in English Language Studies and Teaching (MAED-ELST)',
    'MST-GENSCI' => 'Master in Science Teaching Major in General Science (MST-GENSCI)',
    'MST-MATH' => 'Master in Science Teaching Major in Mathematics (MST-MATH)',
    'MFM-AT' => 'Master in Fisheries Management Major in Aquaculture Technology (MFM-AT)',
    'MFM-FP' => 'Master in Fisheries Management Major in Fish Processing (MFM-FP)',
    'MSMB' => 'Master of Science in Marine Biodiversity (MSMB)',
    'MIT' => 'Master in Information Technology (MIT)',
];

function generateUniqueUsername(mysqli $conn, string $email, string $firstName, string $lastName): string
{
    $base = strtolower((string)strtok($email, '@'));
    $base = preg_replace('/[^a-z0-9._-]/i', '', $base ?? '');
    if ($base === '') {
        $base = strtolower(trim($firstName . '.' . $lastName));
        $base = preg_replace('/[^a-z0-9._-]/i', '', $base ?? '');
    }
    if ($base === '') {
        $base = 'user';
    }

    $candidate = $base;
    $suffix = 1;
    $checkStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if (!$checkStmt) {
        return $candidate;
    }

    while (true) {
        $checkStmt->bind_param('s', $candidate);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows === 0) {
            break;
        }
        $candidate = $base . $suffix;
        $suffix++;
    }

    $checkStmt->close();
    return $candidate;
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
    } elseif ($oldInput['first_name'] === '' || $oldInput['last_name'] === '') {
        $error = 'First name and last name are required.';
    } elseif ($oldInput['contact'] === '' || $oldInput['gender'] === '' || $oldInput['college'] === '' || $oldInput['department'] === '') {
        $error = 'Please complete all required profile fields.';
    } else {
        $generatedUsername = generateUniqueUsername($conn, $postedEmail, $oldInput['first_name'], $oldInput['last_name']);
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param('s', $postedEmail);
            $checkStmt->execute();
            $checkStmt->store_result();
            if ($checkStmt->num_rows > 0) {
                $error = 'Email already exists.';
            }
            $checkStmt->close();
        } else {
            $error = 'Unable to validate account information.';
        }
    }

    if ($error === '') {
        $firstname = $oldInput['first_name'];
        $lastname = $oldInput['last_name'];
        $generatedUsername = $generatedUsername ?? generateUniqueUsername($conn, $postedEmail, $firstname, $lastname);
        $passwordHashed = password_hash($passwordPlain, PASSWORD_DEFAULT);
        $accountStatus = 'approved';
        if ($role === 'faculty') {
            $columns = ['firstname', 'lastname', 'username', 'password', 'email', 'role', 'contact', 'gender', 'department', 'college', 'account_status'];
            $values = [
                $firstname,
                $lastname,
                $generatedUsername,
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
                $generatedUsername,
                $passwordHashed,
                $postedEmail,
                'program_chairperson',
                $oldInput['contact'],
                $oldInput['gender'],
                $oldInput['department'],
                $oldInput['college'],
                $oldInput['department'],
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
            background:
                radial-gradient(circle at top left, rgba(22, 86, 44, 0.08), transparent 35%),
                linear-gradient(180deg, #f4f8f5 0%, #eef5f0 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
        }
        .invite-shell {
            max-width: 820px;
            margin: 2.25rem auto 2.75rem;
            padding: 0 0.75rem;
        }
        .account-card {
            border: 1px solid rgba(22, 86, 44, 0.14);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 14px 30px rgba(17, 73, 37, 0.08);
            overflow: hidden;
        }
        .account-card .card-header {
            background: transparent;
            border-bottom: 0;
            padding: 1.4rem 0.9rem 0.55rem;
        }
        .account-card .card-body {
            padding: 0.85rem 0.9rem 1.2rem;
        }
        .page-title {
            font-weight: 800;
            color: #198754;
            text-align: center;
            margin-bottom: 0;
            margin-top: 0.1rem;
            font-size: 1.55rem;
        }
        .section-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #1f5132;
            margin: 0 0 0.25rem;
        }
        .floating-label {
            font-size: 0.84rem;
            font-weight: 400;
            color: #212529;
        }
        .form-control:focus, .form-select:focus {
            border-color: #9ec5fe;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.2);
        }
        .form-control,
        .form-select {
            border-radius: 10px;
            min-height: 33px;
            font-size: 0.9rem;
        }
        .form-control-lg,
        .form-select-lg {
            padding-top: 0.28rem;
            padding-bottom: 0.28rem;
        }
        .role-lock {
            pointer-events: none;
            background: #fff !important;
            opacity: 1;
        }
        .section-panel {
            border: 1px solid rgba(22, 86, 44, 0.14);
            border-radius: 12px;
            padding: 0.85rem 0.7rem 0.95rem;
            margin-top: 0.6rem;
            background: #fff;
        }
        .section-panel + .section-panel {
            margin-top: 0.65rem;
        }
        .row-tight {
            --bs-gutter-x: 0.4rem;
            --bs-gutter-y: 0.3rem;
        }
        .toggle-password-btn {
            border-left: 1px solid #d8e0d8;
        }
        .register-btn {
            background: #198754;
            border: none;
            border-radius: 8px;
            min-height: 34px;
            font-size: 0.88rem;
            margin-top: 0.45rem;
        }
        .register-btn:hover {
            background: #146c43;
        }
        @media (max-width: 991.98px) {
            .invite-shell {
                max-width: 100%;
                margin: 1.6rem auto 2rem;
                padding: 0 0.75rem;
            }
            .account-card .card-header {
                padding: 1.15rem 0.75rem 0.45rem;
            }
            .account-card .card-body {
                padding: 0.7rem 0.75rem 1rem;
            }
        }
        @media (max-width: 575.98px) {
            .invite-shell {
                margin: 1.1rem auto 1.35rem;
                padding: 0 0.5rem;
            }
            .account-card .card-header {
                padding: 0.85rem 0.6rem 0.3rem;
            }
            .page-title {
                font-size: 1.05rem;
            }
            .account-card .card-body {
                padding: 0.55rem 0.6rem 0.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="invite-shell">
        <div class="card account-card">
            <div class="card-header">
                <h1 class="page-title">Create Your Account</h1>
            </div>
            <div class="card-body">
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success !== ''): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                        <div class="mt-2"><a href="login.php" class="alert-link">Go to login</a></div>
                    </div>
                <?php elseif ($error === ''): ?>
                    <form method="post">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($inviteToken); ?>">

                        <div class="mb-2">
                            <label class="form-label">Role</label>
                            <select class="form-select role-lock" aria-disabled="true" tabindex="-1">
                                <option selected><?php echo htmlspecialchars(registration_invite_role_label((string)$role)); ?></option>
                            </select>
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars((string)$role); ?>">
                        </div>

                        <div class="section-panel">
                            <div class="section-title">Account Credentials</div>
                            <div class="row row-tight">
                                <div class="col-12">
                                    <label class="form-label floating-label">Email</label>
                                <input type="email" name="email" class="form-control" placeholder="Email" value="<?php echo htmlspecialchars($email); ?>" readonly required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label floating-label">Password</label>
                                <div class="input-group">
                                    <input type="password" id="password" name="password" class="form-control" placeholder="Password" required minlength="8">
                                    <button type="button" class="btn btn-outline-secondary toggle-password-btn" id="togglePassword">
                                        <i class="bi bi-eye-slash" id="toggleIcon"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label floating-label">Confirm Password</label>
                                <div class="input-group">
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm Password" required minlength="8">
                                    <button type="button" class="btn btn-outline-secondary toggle-password-btn" id="toggleConfirmPassword">
                                        <i class="bi bi-eye-slash" id="toggleConfirmIcon"></i>
                                    </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="section-panel">
                            <div class="section-title"><?php echo $role === 'program_chairperson' ? 'Program Chairperson Profile' : 'Faculty Profile'; ?></div>
                            <div class="row row-tight">
                                <div class="col-md-6">
                                    <label class="form-label floating-label">First Name</label>
                                    <input type="text" name="first_name" class="form-control" placeholder="First Name" value="<?php echo htmlspecialchars($oldInput['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label floating-label">Last Name</label>
                                    <input type="text" name="last_name" class="form-control" placeholder="Last Name" value="<?php echo htmlspecialchars($oldInput['last_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label floating-label">Contact Number</label>
                                    <input type="text" name="contact" class="form-control" placeholder="Contact Number" value="<?php echo htmlspecialchars($oldInput['contact']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label floating-label">Select Gender</label>
                                    <select name="gender" class="form-select" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo $oldInput['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $oldInput['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="Prefer not to say" <?php echo $oldInput['gender'] === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label floating-label">Institute / College</label>
                                    <input type="text" name="college" class="form-control" placeholder="Institute / College" value="<?php echo htmlspecialchars($oldInput['college']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label floating-label">Select Program</label>
                                    <select name="department" class="form-select" required>
                                        <option value="">Select Program</option>
                                        <?php foreach ($programOptions as $code => $label): ?>
                                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>" <?php echo $oldInput['department'] === $code ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="section-panel">
                            <label class="form-label floating-label">Specialization (optional)</label>
                            <input type="text" name="specialization" class="form-control" placeholder="Specialization (optional)" value="<?php echo htmlspecialchars($oldInput['specialization']); ?>">
                        </div>

                        <div class="mt-1">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="terms" required>
                                <label class="form-check-label" for="terms">
                                    I have read and agree to the <a href="terms.php" target="_blank" rel="noopener">Terms &amp; Conditions</a>
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn register-btn w-100 text-white mt-1">Register</button>
                    </form>

                    <p class="text-center mt-1 mb-0">
                        Already have an account? <a href="login.php" class="text-decoration-none text-success">Login</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const passwordInput = document.querySelector("#password");
            const confirmPasswordInput = document.querySelector("#confirm_password");
            const togglePasswordBtn = document.querySelector("#togglePassword");
            const togglePasswordIcon = document.querySelector("#toggleIcon");
            const toggleConfirmBtn = document.querySelector("#toggleConfirmPassword");
            const toggleConfirmIcon = document.querySelector("#toggleConfirmIcon");

            function toggleVisibility(field, icon) {
                if (!field) {
                    return;
                }
                const hidden = field.getAttribute("type") === "password";
                field.setAttribute("type", hidden ? "text" : "password");
                if (icon) {
                    icon.classList.toggle("bi-eye");
                    icon.classList.toggle("bi-eye-slash");
                }
            }

            if (togglePasswordBtn) {
                togglePasswordBtn.addEventListener("click", () => toggleVisibility(passwordInput, togglePasswordIcon));
            }
            if (toggleConfirmBtn) {
                toggleConfirmBtn.addEventListener("click", () => toggleVisibility(confirmPasswordInput, toggleConfirmIcon));
            }
        })();
    </script>
    </body>
</html>
