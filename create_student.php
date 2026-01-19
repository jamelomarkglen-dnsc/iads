<?php
session_start();
include 'db.php';
require_once 'chair_scope_helper.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'program_chairperson') {
    header("Location: login.php");
    exit;
}

$success = $error = '';
$oldInput = [
    'firstname' => '',
    'lastname' => '',
    'email' => '',
    'student_id' => '',
    'program' => '',
    'year_level' => '',
];

function columnExists($conn, $table, $column)
{
    $tableEscaped = $conn->real_escape_string($table);
    $columnEscaped = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tableEscaped' AND COLUMN_NAME = '$columnEscaped' LIMIT 1";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

function ensureColumn($conn, $table, $column, $definition)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return columnExists($conn, $table, $column);
    }
    if (columnExists($conn, $table, $column)) {
        return true;
    }
    $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
    try {
        if ($conn->query($sql)) {
            return true;
        }
    } catch (mysqli_sql_exception $e) {
        return false;
    }
    return false;
}

function bindParams($stmt, $types, $values)
{
    $params = [];
    $params[] = $types;
    foreach ($values as $value) {
        $params[] = $value;
    }
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

function generateUniqueUsername(mysqli $conn, string $seed): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', $seed));
    if ($base === '') {
        $base = 'student';
    }
    $username = $base;
    $suffix = 1;

    $checkSql = "SELECT id FROM users WHERE username = ? LIMIT 1";
    $stmt = $conn->prepare($checkSql);
    if (!$stmt) {
        return $username;
    }

    while (true) {
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result || $result->num_rows === 0) {
            break;
        }
        $username = $base . $suffix;
        $suffix++;
    }
    $stmt->close();
    return $username;
}

$hasStudentIdColumn = ensureColumn($conn, 'users', 'student_id', 'VARCHAR(100) DEFAULT NULL');
$hasProgramColumn = ensureColumn($conn, 'users', 'program', 'VARCHAR(255) DEFAULT NULL');
$hasDepartmentColumn = ensureColumn($conn, 'users', 'department', 'VARCHAR(255) DEFAULT NULL');
$hasCollegeColumn = ensureColumn($conn, 'users', 'college', 'VARCHAR(255) DEFAULT NULL');
$hasYearLevelColumn = ensureColumn($conn, 'users', 'year_level', "VARCHAR(50) DEFAULT NULL");
$chairScopeDefaults = get_program_chair_scope($conn, (int)($_SESSION['user_id'] ?? 0));
$scopedProgramDefault = $hasProgramColumn ? trim((string)($chairScopeDefaults['program'] ?? '')) : '';
$scopedDepartmentDefault = $hasDepartmentColumn ? trim((string)($chairScopeDefaults['department'] ?? '')) : '';
$scopedCollegeDefault = $hasCollegeColumn ? trim((string)($chairScopeDefaults['college'] ?? '')) : '';
$programRequired = $hasProgramColumn && ($scopedProgramDefault !== '' || ($scopedDepartmentDefault === '' && $scopedCollegeDefault === ''));

if (isset($_POST['create_student'])) {
    foreach ($oldInput as $key => $value) {
        $oldInput[$key] = trim($_POST[$key] ?? '');
    }

    $passwordPlain = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');
    $role = 'student';
    $scopedDepartment = $scopedDepartmentDefault;
    $scopedCollege = $scopedCollegeDefault;

    if ($passwordPlain !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif (strlen($passwordPlain) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif (!filter_var($oldInput['email'], FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid email address.";
    } elseif ($hasStudentIdColumn && !preg_match('/^[0-9]{2,}$/', $oldInput['student_id'])) {
        $error = "Student ID should contain digits only.";
    } elseif ($programRequired && $oldInput['program'] === '') {
        $error = "Please select a program.";
    } elseif ($hasYearLevelColumn && $oldInput['year_level'] === '') {
        $error = "Please select a year level.";
    } else {
        $passwordHashed = password_hash($passwordPlain, PASSWORD_DEFAULT);

        $checkSql = "SELECT id FROM users WHERE email = ?";
        if ($hasStudentIdColumn) {
            $checkSql .= " OR student_id = ?";
        }
        $stmt = $conn->prepare($checkSql);
        if ($stmt) {
            $checkTypes = "s";
            $checkValues = [$oldInput['email']];
            if ($hasStudentIdColumn) {
                $checkTypes .= "s";
                $checkValues[] = $oldInput['student_id'];
            }
            bindParams($stmt, $checkTypes, $checkValues);

            $stmt->execute();
            $checkResult = $stmt->get_result();

            if ($checkResult && $checkResult->num_rows > 0) {
                $error = $hasStudentIdColumn ? "Email or Student ID already exists!" : "Email already exists!";
            } else {
                $usernameSeed = $oldInput['student_id'] ?: ($oldInput['firstname'] . $oldInput['lastname']) ?: $oldInput['email'];
                $username = generateUniqueUsername($conn, $usernameSeed);

                $insertColumns = ['firstname', 'lastname', 'email', 'username', 'password', 'role'];
                $insertTypes = "ssssss";
                $insertValues = [
                    $oldInput['firstname'],
                    $oldInput['lastname'],
                    $oldInput['email'],
                    $username,
                    $passwordHashed,
                    $role,
                ];

                if ($hasStudentIdColumn) {
                    $insertColumns[] = 'student_id';
                    $insertTypes .= 's';
                    $insertValues[] = $oldInput['student_id'];
                }
                if ($hasProgramColumn) {
                    $insertColumns[] = 'program';
                    $insertTypes .= 's';
                    $insertValues[] = $oldInput['program'];
                }
                if ($hasDepartmentColumn) {
                    $insertColumns[] = 'department';
                    $insertTypes .= 's';
                    $insertValues[] = $scopedDepartment;
                }
                if ($hasCollegeColumn) {
                    $insertColumns[] = 'college';
                    $insertTypes .= 's';
                    $insertValues[] = $scopedCollege;
                }
                if ($hasYearLevelColumn) {
                    $insertColumns[] = 'year_level';
                    $insertTypes .= 's';
                    $insertValues[] = $oldInput['year_level'];
                }

                $insertSql = sprintf(
                    "INSERT INTO users (%s) VALUES (%s)",
                    implode(', ', $insertColumns),
                    implode(', ', array_fill(0, count($insertColumns), '?'))
                );
                $insertStmt = $conn->prepare($insertSql);
                if ($insertStmt) {
                    bindParams($insertStmt, $insertTypes, $insertValues);

                    if ($insertStmt->execute()) {
                        $success = "Student account created successfully!";
                        foreach ($oldInput as $key => $value) {
                            $oldInput[$key] = '';
                        }
                    } else {
                        $error = "Error creating account.";
                    }
                    $insertStmt->close();
                } else {
                    $error = "Unable to process request right now.";
                }
            }
            if ($checkResult) {
                $checkResult->free();
            }
            $stmt->close();
        } else {
            $error = "Unable to validate account information.";
        }
    }
}

$studentStats = [
    'total' => 0,
    'byYear' => [],
    'byProgram' => [],
];

$totalResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student'");
if ($totalResult && $row = $totalResult->fetch_assoc()) {
    $studentStats['total'] = (int)$row['total'];
}
if ($totalResult) {
    $totalResult->free();
}

if ($hasYearLevelColumn) {
    $yearResult = $conn->query("SELECT year_level, COUNT(*) AS total FROM users WHERE role = 'student' GROUP BY year_level");
    if ($yearResult) {
        while ($row = $yearResult->fetch_assoc()) {
            $studentStats['byYear'][$row['year_level']] = (int)$row['total'];
        }
        $yearResult->free();
    }
}

if ($hasProgramColumn) {
    $programResult = $conn->query("SELECT program, COUNT(*) AS total FROM users WHERE role = 'student' GROUP BY program");
    if ($programResult) {
        while ($row = $programResult->fetch_assoc()) {
            $studentStats['byProgram'][$row['program']] = (int)$row['total'];
        }
        $programResult->free();
    }
}

$programOptions = $hasProgramColumn ? [
    'PHDEM' => 'Doctor of Philosophy in Educational Management (PHDEM)',
    'MAEM' => 'Master of Arts in Educational Management (MAEM)',
    'MAED-ELST' => 'Master of Education Major in English Language Studies and Teaching (MAED-ELST)',
    'MST-GENSCI' => 'Master in Science Teaching Major in General Science (MST-GENSCI)',
    'MST-MATH' => 'Master in Science Teaching Major in Mathematics (MST-MATH)',
    'MFM-AT' => 'Master in Fisheries Management Major in Aquaculture Technology (MFM-AT)',
    'MFM-FP' => 'Master in Fisheries Management Major in Fish Processing (MFM-FP)',
    'MSMB' => 'Master of Science in Marine Biodiversity (MSMB)',
    'MIT' => 'Master in Information Technology (MIT)',
] : [];

$yearOptions = $hasYearLevelColumn ? [
    '1st Year',
    '2nd Year',
    '3rd Year',
    '4th Year',
] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Student Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body.create-student-page {
            min-height: 100vh;
            background: radial-gradient(circle at top left, rgba(22, 86, 44, 0.25), transparent 55%),
                        radial-gradient(circle at bottom right, rgba(24, 53, 92, 0.2), transparent 45%),
                        linear-gradient(135deg, #f4f9f6, #e7efe9);
        }
        .content-wrapper {
            margin-left: 220px;
            transition: margin-left 0.3s ease;
            padding: 32px 24px;
        }
        #sidebar.collapsed ~ .content-wrapper {
            margin-left: 70px;
        }
        .summary-card {
            backdrop-filter: blur(6px);
            border-radius: 1rem;
            border: 1px solid rgba(22, 86, 44, 0.08);
        }
        .summary-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: rgba(22, 86, 44, 0.12);
            color: #16562c;
        }
        .quick-link-card {
            border-radius: 0.9rem;
            border: 1px dashed rgba(22, 86, 44, 0.28);
            transition: all 0.2s ease;
        }
        .quick-link-card:hover {
            transform: translateY(-3px);
            border-style: solid;
            text-decoration: none;
            box-shadow: 0 0.75rem 2rem rgba(22, 86, 44, 0.12);
        }
        .form-section {
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 1.75rem 3rem rgba(15, 59, 29, 0.08);
        }
        .form-section .card-header {
            background: linear-gradient(135deg, #16562c, #0f3b1d);
            color: #fff;
        }
        .form-floating > label {
            color: #4a5f53;
        }
        .form-control:focus,
        .form-select:focus {
            border-color: #16562c;
            box-shadow: 0 0 0 0.25rem rgba(22, 86, 44, 0.2);
        }
        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 24px 16px;
            }
        }
    </style>
</head>
<body class="create-student-page">
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content-wrapper">
    <div class="container-fluid px-0">
        <div class="text-center text-lg-start mb-5">
            <h1 class="h3 fw-semibold text-success mb-1">Student Enrollment Console</h1>
            <p class="text-muted mb-0">Track active students and quickly enroll new learners.</p>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-12 col-lg-4">
                <div class="card summary-card h-100 border-0 shadow-sm p-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="icon me-3">
                                <i class="bi bi-mortarboard-fill fs-4"></i>
                            </div>
                            <div>
                                <p class="text-uppercase text-muted small mb-1">Total Students</p>
                                <h2 class="mb-0 fw-bold text-success"><?php echo number_format($studentStats['total']); ?></h2>
                            </div>
                        </div>
                        <p class="small text-muted mb-0">Active records across all programs.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="card summary-card h-100 border-0 shadow-sm p-3">
                    <div class="card-body">
                        <p class="text-uppercase text-muted small mb-3">Distribution By Year</p>
                        <?php if (!empty($studentStats['byYear'])): ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($studentStats['byYear'] as $year => $count): ?>
                                    <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                        <span class="fw-semibold text-success"><?php echo htmlspecialchars($year ?? 'N/A'); ?></span>
                                        <span class="badge bg-success-subtle text-success fw-semibold"><?php echo number_format($count); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No year-level data available.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-4">
                <div class="card summary-card h-100 border-0 shadow-sm p-3">
                    <div class="card-body">
                        <p class="text-uppercase text-muted small mb-3">Top Programs</p>
                        <?php if (!empty($studentStats['byProgram'])): ?>
                            <ul class="list-unstyled mb-3">
                                <?php foreach (array_slice($studentStats['byProgram'], 0, 3, true) as $programCode => $count): ?>
                                    <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                        <span class="fw-semibold text-success">
                                            <?php echo htmlspecialchars($programOptions[$programCode] ?? (string)$programCode); ?>
                                        </span>
                                        <span class="badge bg-success-subtle text-success fw-semibold"><?php echo number_format($count); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No program data available.</p>
                        <?php endif; ?>
                        <?php if (!empty($studentStats['byProgram']) && count($studentStats['byProgram']) > 3): ?>
                            <div class="small text-muted">Additional programs tracked in the database.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($studentStats['byProgram'])): ?>
            <div class="row g-3 mb-5">
                <?php foreach ($studentStats['byProgram'] as $programCode => $count): ?>
                    <div class="col-12 col-md-4">
                        <a href="student_dashboard.php?program=<?php echo urlencode((string)$programCode); ?>" class="quick-link-card d-flex align-items-center justify-content-between p-3 text-success text-decoration-none bg-white shadow-sm">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($programOptions[$programCode] ?? (string)$programCode); ?></div>
                                <div class="small text-muted">Active students</div>
                            </div>
                            <div class="badge bg-success-subtle text-success fw-bold rounded-pill px-3 py-2">
                                <?php echo number_format($count); ?>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success shadow-sm rounded-3 mb-4"><?php echo $success; ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger shadow-sm rounded-3 mb-4"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card form-section border-0">
            <div class="card-header py-4 text-center">
                <h2 class="h4 fw-bold mb-0">Create Student Account</h2>
                <p class="mb-0 small text-white-50">Capture the student's academic and login information carefully.</p>
            </div>
            <div class="card-body p-4 p-lg-5">
                <form method="POST" class="needs-validation" novalidate>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" name="firstname" class="form-control" id="firstname" placeholder="First Name"
                                       value="<?php echo htmlspecialchars($oldInput['firstname'], ENT_QUOTES); ?>" required>
                                <label for="firstname">First Name</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" name="lastname" class="form-control" id="lastname" placeholder="Last Name"
                                       value="<?php echo htmlspecialchars($oldInput['lastname'], ENT_QUOTES); ?>" required>
                                <label for="lastname">Last Name</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-4 mt-1">
                        <div class="<?php echo $hasStudentIdColumn ? 'col-md-6' : 'col-12'; ?>">
                            <div class="form-floating">
                                <input type="email" name="email" class="form-control" id="email" placeholder="Email"
                                       value="<?php echo htmlspecialchars($oldInput['email'], ENT_QUOTES); ?>" required>
                                <label for="email">Institutional Email</label>
                            </div>
                            <div class="form-text">This becomes the student's login email.</div>
                        </div>
                        <?php if ($hasStudentIdColumn): ?>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" name="student_id" class="form-control" id="student_id" placeholder="Student ID"
                                           value="<?php echo htmlspecialchars($oldInput['student_id'], ENT_QUOTES); ?>" required
                                           inputmode="numeric" pattern="\d{2,}">
                                    <label for="student_id">Student ID Number</label>
                                </div>
                                <div class="form-text">Digits only, matching registrar records.</div>
                            </div>
                        <?php else: ?>
                            <div class="col-md-6">
                                <div class="alert alert-warning mb-0">
                                    Student ID tracking is not configured in the database. Contact your administrator to add the column.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="row g-4 mt-1">
                        <?php if ($hasProgramColumn): ?>
                            <div class="<?php echo $hasYearLevelColumn ? 'col-md-6' : 'col-12'; ?>">
                                <div class="form-floating">
                                    <select name="program" id="program" class="form-select" <?php echo $programRequired ? 'required' : ''; ?>>
                                        <option value="" disabled <?php echo $oldInput['program'] === '' ? 'selected' : ''; ?>>Select Program</option>
                                        <?php foreach ($programOptions as $code => $label): ?>
                                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>" <?php echo $oldInput['program'] === $code ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="program">Program</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($hasYearLevelColumn): ?>
                            <div class="<?php echo $hasProgramColumn ? 'col-md-6' : 'col-12'; ?>">
                                <div class="form-floating">
                                    <select name="year_level" id="year_level" class="form-select" required>
                                        <option value="" disabled <?php echo $oldInput['year_level'] === '' ? 'selected' : ''; ?>>Select Year Level</option>
                                        <?php foreach ($yearOptions as $year): ?>
                                            <option value="<?php echo htmlspecialchars($year, ENT_QUOTES); ?>" <?php echo $oldInput['year_level'] === $year ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($year); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="year_level">Year Level</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!$hasProgramColumn || !$hasYearLevelColumn): ?>
                            <div class="col-12">
                                <div class="alert alert-warning mb-0">
                                    Program or year level tracking is not configured in the database. Coordinate with your administrator if these fields are required.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="row g-4 mt-1">
                        <div class="col-md-6">
                            <label for="password" class="form-label text-success fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-success-subtle"><i class="bi bi-shield-lock"></i></span>
                                <input type="password" name="password" id="password" class="form-control" placeholder="Create password"
                                       minlength="8" required>
                                <button type="button" class="btn btn-outline-success" id="togglePassword">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                            <div class="form-text">At least 8 characters with letters and numbers.</div>
                            <div class="progress mt-2" style="height: 6px;">
                                <div class="progress-bar" id="passwordStrength" role="progressbar" style="width:0;"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label text-success fw-semibold">Confirm Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-success-subtle"><i class="bi bi-check2-circle"></i></span>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-type password"
                                       minlength="8" required>
                                <button type="button" class="btn btn-outline-success" id="toggleConfirmPassword">
                                    <i class="bi bi-eye" id="toggleConfirmPasswordIcon"></i>
                                </button>
                            </div>
                            <div class="form-text text-danger d-none" id="passwordMismatch">Passwords do not match.</div>
                        </div>
                    </div>
                    <div class="d-grid mt-5">
                        <button type="submit" name="create_student" class="btn btn-success btn-lg">
                            <i class="bi bi-person-plus-fill me-2"></i>Create Student Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const togglePasswordBtn = document.getElementById('togglePassword');
        const togglePasswordIcon = document.getElementById('togglePasswordIcon');
        const toggleConfirmPasswordBtn = document.getElementById('toggleConfirmPassword');
        const toggleConfirmIcon = document.getElementById('toggleConfirmPasswordIcon');
        const passwordStrength = document.getElementById('passwordStrength');
        const passwordMismatch = document.getElementById('passwordMismatch');

        if (togglePasswordBtn && passwordInput) {
            togglePasswordBtn.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                if (togglePasswordIcon) {
                    togglePasswordIcon.classList.toggle('bi-eye');
                    togglePasswordIcon.classList.toggle('bi-eye-slash');
                }
            });
        }

        if (toggleConfirmPasswordBtn && confirmPasswordInput) {
            toggleConfirmPasswordBtn.addEventListener('click', function () {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                if (toggleConfirmIcon) {
                    toggleConfirmIcon.classList.toggle('bi-eye');
                    toggleConfirmIcon.classList.toggle('bi-eye-slash');
                }
            });
        }

        function evaluateStrength(value) {
            let strength = 0;
            if (value.length >= 8) strength += 25;
            if (/[A-Z]/.test(value)) strength += 25;
            if (/[a-z]/.test(value)) strength += 25;
            if (/\d|[@$!%*?&#]/.test(value)) strength += 25;
            return Math.min(strength, 100);
        }

        function updateStrength() {
            if (!passwordInput || !passwordStrength) return;
            const value = passwordInput.value;
            if (!value) {
                passwordStrength.style.width = '0%';
                passwordStrength.className = 'progress-bar';
                return;
            }
            const strength = evaluateStrength(value);
            passwordStrength.style.width = strength + '%';
            passwordStrength.className = 'progress-bar';
            if (strength <= 25) {
                passwordStrength.classList.add('bg-danger');
            } else if (strength < 75) {
                passwordStrength.classList.add('bg-warning');
            } else {
                passwordStrength.classList.add('bg-success');
            }
        }

        function checkMatch() {
            if (!passwordInput || !confirmPasswordInput || !passwordMismatch) return;
            const mismatch = passwordInput.value && confirmPasswordInput.value && passwordInput.value !== confirmPasswordInput.value;
            passwordMismatch.classList.toggle('d-none', !mismatch);
        }

        if (passwordInput) {
            passwordInput.addEventListener('input', function () {
                updateStrength();
                checkMatch();
            });
        }

        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', checkMatch);
        }

        updateStrength();

        const forms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(forms).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();
</script>

</body>
</html>
