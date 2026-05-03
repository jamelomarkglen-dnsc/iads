<?php
session_start();
include 'db.php';
require_once 'notifications_helper.php';

$message = "";
$allowedRegistrationRoles = ['student'];
$oldInput = [
    'role' => '',
    'firstname' => '',
    'lastname' => '',
    'fullname' => '',
    'email' => '',
    'contact' => '',
    'gender' => '',
    'college' => '',
    'department' => '',
    'program_focus' => '',
    'username' => '',
    'specialization' => '',
    'student_id' => '',
    'program' => '',
    'year_level' => '',
];

function columnExists(mysqli $conn, string $table, string $column): bool
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

function ensureColumn(mysqli $conn, string $table, string $column, string $definition): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return columnExists($conn, $table, $column);
    }
    if (columnExists($conn, $table, $column)) {
        return true;
    }
    $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
    try {
        return (bool)$conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function split_name(string $name): array
{
    $parts = preg_split('/\s+/', trim($name));
    $first = array_shift($parts) ?? '';
    $last = implode(' ', $parts);
    return [$first, $last];
}

function bindParams(mysqli_stmt $stmt, string $types, array $values): bool
{
    $params = [$types];
    foreach ($values as $value) {
        $params[] = $value;
    }
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    return (bool)call_user_func_array([$stmt, 'bind_param'], $refs);
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

function normalize_scope_value(?string $value): string
{
    return trim((string)$value);
}

function scope_value_equals(string $left, string $right): bool
{
    if ($left === '' || $right === '') {
        return false;
    }
    return strcasecmp($left, $right) === 0;
}

function verifier_matches_scope(array $verifier, string $candidateProgram, string $candidateDepartment, string $candidateCollege): bool
{
    $program = normalize_scope_value($verifier['program'] ?? '');
    $department = normalize_scope_value($verifier['department'] ?? '');
    $college = normalize_scope_value($verifier['college'] ?? '');

    if ($program !== '') {
        return scope_value_equals($candidateProgram, $program) || scope_value_equals($candidateDepartment, $program);
    }
    if ($department !== '') {
        return scope_value_equals($candidateDepartment, $department) || scope_value_equals($candidateProgram, $department);
    }
    if ($college !== '') {
        return scope_value_equals($candidateCollege, $college);
    }
    return false;
}

function fetch_role_users(mysqli $conn, string $role, bool $hasProgram, bool $hasDepartment, bool $hasCollege): array
{
    $fields = ['id'];
    if ($hasProgram) {
        $fields[] = 'program';
    }
    if ($hasDepartment) {
        $fields[] = 'department';
    }
    if ($hasCollege) {
        $fields[] = 'college';
    }

    $sql = sprintf("SELECT %s FROM users WHERE role = ?", implode(', ', $fields));
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows ?: [];
}

function chair_matches_faculty(array $chair, string $facultyDepartment, string $facultyCollege): bool
{
    $program = normalize_scope_value($chair['program'] ?? '');
    $department = normalize_scope_value($chair['department'] ?? '');
    $college = normalize_scope_value($chair['college'] ?? '');

    if ($program !== '') {
        return scope_value_equals($facultyDepartment, $program);
    }
    if ($department !== '') {
        return scope_value_equals($facultyDepartment, $department);
    }
    if ($college !== '') {
        return scope_value_equals($facultyCollege, $college);
    }
    return false;
}

function faculty_matches_student(array $faculty, string $studentProgram, string $studentDepartment, string $studentCollege): bool
{
    $program = normalize_scope_value($faculty['program'] ?? '');
    $department = normalize_scope_value($faculty['department'] ?? '');
    $college = normalize_scope_value($faculty['college'] ?? '');

    if ($program !== '') {
        return scope_value_equals($studentProgram, $program) || scope_value_equals($studentDepartment, $program);
    }
    if ($department !== '') {
        return scope_value_equals($studentProgram, $department) || scope_value_equals($studentDepartment, $department);
    }
    if ($college !== '') {
        return scope_value_equals($studentCollege, $college);
    }
    return false;
}

function notify_verification_for_registration(
    mysqli $conn,
    string $role,
    string $fullname,
    string $department,
    string $college,
    string $program,
    string $studentProgram,
    string $studentDepartment,
    string $studentCollege,
    bool $hasProgramColumn,
    bool $hasDepartmentColumn,
    bool $hasCollegeColumn
): void {
    $role = trim($role);
    $fullname = trim($fullname);

    if ($role === 'program_chairperson') {
        $title = 'Program Chairperson Verification Required';
        $message = $fullname !== '' ? "New program chairperson account pending verification: {$fullname}." : 'New program chairperson account pending verification.';
        if ($hasDepartmentColumn || $hasCollegeColumn || $hasProgramColumn) {
            $deans = fetch_role_users($conn, 'dean', $hasProgramColumn, $hasDepartmentColumn, $hasCollegeColumn);
            $targets = [];
            foreach ($deans as $dean) {
                if (verifier_matches_scope($dean, $program, $department, $college)) {
                    $targets[] = (int)($dean['id'] ?? 0);
                }
            }
            $targets = array_values(array_unique(array_filter($targets)));
            if (!empty($targets)) {
                notify_users($conn, $targets, $title, $message, 'verify_program_chair.php');
                return;
            }
        }
        // Fallback: notify all deans if no scope match or no scope data available.
        notify_role($conn, 'dean', $title, $message, 'verify_program_chair.php');
        return;
    }

    if ($role === 'faculty') {
        $title = 'Faculty Verification Required';
        $details = $department !== '' ? " Program: {$department}." : '';
        $message = $fullname !== '' ? "New faculty account pending verification: {$fullname}.{$details}" : "New faculty account pending verification.{$details}";

        if ($hasDepartmentColumn || $hasCollegeColumn || $hasProgramColumn) {
            $chairs = fetch_role_users($conn, 'program_chairperson', $hasProgramColumn, $hasDepartmentColumn, $hasCollegeColumn);
            $targets = [];
            foreach ($chairs as $chair) {
                if (chair_matches_faculty($chair, $department, $college)) {
                    $targets[] = (int)($chair['id'] ?? 0);
                }
            }
            $targets = array_values(array_unique(array_filter($targets)));
            if (!empty($targets)) {
                notify_users($conn, $targets, $title, $message, 'verify_faculty.php', false);
                return;
            }
        }
        // Fallback: notify all program chairpersons if no scope match or no scope data available.
        notify_role($conn, 'program_chairperson', $title, $message, 'verify_faculty.php', false);
        return;
    }

    if ($role === 'student') {
        $title = 'Student Verification Required';
        $programLabel = $studentProgram !== '' ? " Program: {$studentProgram}." : '';
        $message = $fullname !== '' ? "New student account pending verification: {$fullname}.{$programLabel}" : "New student account pending verification.{$programLabel}";

        if ($hasDepartmentColumn || $hasCollegeColumn || $hasProgramColumn) {
            $faculty = fetch_role_users($conn, 'faculty', $hasProgramColumn, $hasDepartmentColumn, $hasCollegeColumn);
            $targets = [];
            foreach ($faculty as $member) {
                if (faculty_matches_student($member, $studentProgram, $studentDepartment, $studentCollege)) {
                    $targets[] = (int)($member['id'] ?? 0);
                }
            }
            $targets = array_values(array_unique(array_filter($targets)));
            if (!empty($targets)) {
                notify_users($conn, $targets, $title, $message, 'verify_students.php');
                return;
            }
        }
        return;
    }
}

$programOptions = [
    'PHDEM' => 'Doctor of Philosophy in Educational Management (PHDEM)',
    'PHD-ELST' => 'Doctor of Philosophy in English Language Studies and Teaching (PhD ELST)',
    'PHD-SCIED' => 'Doctor of Philosophy in Science Education (PhD SciEd)',
    'MAEM' => 'Master of Arts in Educational Management (MAEM)',
    'MAED-ELST' => 'Master of Education Major in English Language Studies and Teaching (MAED-ELST)',
    'MST-GENSCI' => 'Master in Science Teaching Major in General Science (MST-GENSCI)',
    'MST-MATH' => 'Master in Science Teaching Major in Mathematics (MST-MATH)',
    'MFM-AT' => 'Master in Fisheries Management Major in Aquaculture Technology (MFM-AT)',
    'MFM-FP' => 'Master in Fisheries Management Major in Fish Processing (MFM-FP)',
    'MSMB' => 'Master of Science in Marine Biodiversity (MSMB)',
    'MIT' => 'Master in Information Technology (MIT)',
];

$yearOptions = [
    '1st Year',
    '2nd Year',
    '3rd Year',
    '4th Year',
];

$hasAccountStatusColumn = ensureColumn($conn, 'users', 'account_status', "VARCHAR(20) NOT NULL DEFAULT 'approved'");
$hasSpecializationColumn = columnExists($conn, 'users', 'specialization');
$hasStudentIdColumn = columnExists($conn, 'users', 'student_id');
$hasProgramColumn = columnExists($conn, 'users', 'program');
$hasDepartmentColumn = columnExists($conn, 'users', 'department');
$hasCollegeColumn = columnExists($conn, 'users', 'college');
$hasYearLevelColumn = columnExists($conn, 'users', 'year_level');
$hasPhotoColumn = columnExists($conn, 'users', 'photo');

if (isset($_POST['register'])) {
    foreach ($oldInput as $key => $value) {
        $oldInput[$key] = trim($_POST[$key] ?? '');
    }

    $role = $oldInput['role'];
    $email = $oldInput['email'];
    $passwordPlain = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $errors = [];

    if (!in_array($role, $allowedRegistrationRoles, true)) {
        $errors[] = "This page is for student registration only.";
    } else {
        $role = 'student';
        $oldInput['role'] = 'student';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if ($passwordPlain === '' || $confirmPassword === '') {
        $errors[] = "Password and confirmation are required.";
    } elseif ($passwordPlain !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    } elseif (strlen($passwordPlain) < 8) {
        $errors[] = "Password must be at least 8 characters and contain letters and numbers.";
    } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).+$/', $passwordPlain)) {
        $errors[] = "Password must contain at least one letter and one number.";
    }

    $firstname = '';
    $lastname = '';
    $username = '';
    $accountStatus = 'approved';
    $program = '';
    $photoPath = null;

    if (empty($errors)) {
        if ($role === 'student') {
            if ($oldInput['firstname'] === '' || $oldInput['lastname'] === '') {
                $errors[] = "First and last name are required.";
            }
            if ($hasStudentIdColumn && !preg_match('/^[0-9]{2,}$/', $oldInput['student_id'])) {
                $errors[] = "Student ID should contain digits only.";
            }
            if ($oldInput['contact'] === '' || $oldInput['gender'] === '') {
                $errors[] = "Contact number and gender are required.";
            }
            if ($oldInput['program'] === '' || $oldInput['year_level'] === '') {
                $errors[] = "Program and year level are required.";
            }
            if ($oldInput['department'] === '' || $oldInput['college'] === '') {
                $errors[] = "Department and institute are required.";
            }
            if (empty($errors)) {
                $firstname = $oldInput['firstname'];
                $lastname = $oldInput['lastname'];
                $seed = $oldInput['student_id'] ?: ($firstname . $lastname) ?: $email;
                $username = generateUniqueUsername($conn, $seed);
                $accountStatus = 'pending';
            }
        } else {
            $errors[] = "Invalid role selection.";
        }
    }

    if (empty($errors) && !$hasAccountStatusColumn) {
        $errors[] = "Account verification is not available right now. Please contact the administrator.";
    }

    if (empty($errors)) {
        $checkSql = "SELECT id FROM users WHERE email = ?";
        $checkTypes = "s";
        $checkValues = [$email];

        if ($role === 'student' && $hasStudentIdColumn && $oldInput['student_id'] !== '') {
            $checkSql .= " OR student_id = ?";
            $checkTypes .= "s";
            $checkValues[] = $oldInput['student_id'];
        }

        $checkStmt = $conn->prepare($checkSql);
        if (!$checkStmt) {
            $errors[] = "Unable to validate account information.";
        } else {
            bindParams($checkStmt, $checkTypes, $checkValues);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            if ($checkResult && $checkResult->num_rows > 0) {
                $errors[] = $hasStudentIdColumn && $oldInput['student_id'] !== ''
                    ? "Email or Student ID already exists."
                    : "Email already exists.";
            }
            if ($checkResult) {
                $checkResult->free();
            }
            $checkStmt->close();
        }
    }

    if (!empty($errors)) {
        $message = "<div class='alert alert-danger'>" . implode("<br>", array_map('htmlspecialchars', $errors)) . "</div>";
    } else {
        $passwordHashed = password_hash($passwordPlain, PASSWORD_DEFAULT);
        $columns = ['firstname', 'lastname', 'username', 'password', 'email', 'role'];
        $types = "ssssss";
        $values = [$firstname, $lastname, $username, $passwordHashed, $email, $role];

        if ($role === 'student') {
            if ($hasStudentIdColumn) {
                $columns[] = 'student_id';
                $types .= 's';
                $values[] = $oldInput['student_id'];
            }
            $columns[] = 'contact';
            $types .= 's';
            $values[] = $oldInput['contact'];
            $columns[] = 'gender';
            $types .= 's';
            $values[] = $oldInput['gender'];
            if ($hasProgramColumn) {
                $columns[] = 'program';
                $types .= 's';
                $values[] = $oldInput['program'];
            }
            if ($hasDepartmentColumn) {
                $columns[] = 'department';
                $types .= 's';
                $values[] = $oldInput['department'];
            }
            if ($hasCollegeColumn) {
                $columns[] = 'college';
                $types .= 's';
                $values[] = $oldInput['college'];
            }
            if ($hasYearLevelColumn) {
                $columns[] = 'year_level';
                $types .= 's';
                $values[] = $oldInput['year_level'];
            }
        }

        $columns[] = 'account_status';
        $types .= 's';
        $values[] = $accountStatus;

        $insertSql = sprintf(
            "INSERT INTO users (%s) VALUES (%s)",
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $insertStmt = $conn->prepare($insertSql);
        if (!$insertStmt) {
            $message = "<div class='alert alert-danger'>Unable to create account right now.</div>";
        } else {
            bindParams($insertStmt, $types, $values);
            if ($insertStmt->execute()) {
                if ($accountStatus === 'pending') {
                    $fullName = trim($firstname . ' ' . $lastname);
                    notify_verification_for_registration(
                        $conn,
                        $role,
                        $fullName,
                        trim((string)($oldInput['department'] ?? '')),
                        trim((string)($oldInput['college'] ?? '')),
                        trim((string)($program ?? '')),
                        trim((string)($oldInput['program'] ?? '')),
                        trim((string)($oldInput['department'] ?? '')),
                        trim((string)($oldInput['college'] ?? '')),
                        $hasProgramColumn,
                        $hasDepartmentColumn,
                        $hasCollegeColumn
                    );
                }
                $message = "<div class='alert alert-success'>Registration successful! Your account is pending verification by the Faculty.</div>";
                foreach ($oldInput as $key => $value) {
                    $oldInput[$key] = '';
                }
            } else {
                $message = "<div class='alert alert-danger'>Error: Could not register.</div>";
            }
            $insertStmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DNSC Student Registration - Institute of Advanced Studies</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', sans-serif;
        }
        .register-container {
            max-width: 760px;
            margin: 3% auto;
            background: #fff;
            padding: 32px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .register-title {
            font-weight: bold;
            text-align: center;
            color: #198754;
            margin-bottom: 20px;
        }
        .btn-register {
            background: #198754;
            border: none;
        }
        .btn-register:hover {
            background: #146c43;
        }
        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1f5132;
            margin: 16px 0 10px;
        }
    </style>
</head>
<body class="d-flex justify-content-center align-items-start py-4">
    <div class="register-container">
        <h3 class="register-title">DNSC Student Registration</h3>
        <p class="text-center text-muted small mb-4">This form is intended for DNSC student accounts only. Faculty, Program Chairperson, and Dean accounts are created through authorized staff channels.</p>
        <?php echo $message; ?>
        <form method="POST" enctype="multipart/form-data" id="registration">
            <div class="mb-3">
                <label class="form-label">Account Type</label>
                <input type="hidden" name="role" id="role" value="student">
                <input type="text" class="form-control" value="Student" disabled>
                <div class="form-text">This form is reserved for DNSC student accounts only.</div>
            </div>

            <div class="section-title">Account Credentials</div>
            <div class="row mb-3">
                <div class="col-12">
                    <input type="email" name="email" class="form-control" placeholder="Email" value="<?php echo htmlspecialchars($oldInput['email']); ?>" required>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label mb-1">Password</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control" placeholder="Password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Toggle password visibility">
                            <i class="bi bi-eye-slash" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label mb-1">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm Password" required>
                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword" aria-label="Toggle confirm password visibility">
                            <i class="bi bi-eye-slash" id="toggleConfirmPasswordIcon"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <small id="passwordMatchStatus" class="text-muted d-flex align-items-center gap-2">
                    <i id="passwordMatchIcon" class="bi bi-dash-circle"></i>
                    <span>Passwords will be checked as you type.</span>
                </small>
            </div>
            <div class="mb-3">
                <small class="text-muted">Password must be at least 8 characters and contain letters and numbers. Symbols are optional.</small>
            </div>

            <div class="section-title">Student Profile</div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" name="firstname" class="form-control" placeholder="First Name" value="<?php echo htmlspecialchars($oldInput['firstname']); ?>" data-required="true">
                </div>
                <div class="col-md-6">
                    <input type="text" name="lastname" class="form-control" placeholder="Last Name" value="<?php echo htmlspecialchars($oldInput['lastname']); ?>" data-required="true">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" name="contact" class="form-control" placeholder="Contact Number" value="<?php echo htmlspecialchars($oldInput['contact']); ?>" data-required="true">
                </div>
                <div class="col-md-6">
                    <select name="gender" class="form-select" data-required="true">
                        <option value="" disabled <?php echo $oldInput['gender'] === '' ? 'selected' : ''; ?>>Select Gender</option>
                        <option value="Male" <?php echo $oldInput['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $oldInput['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                        <option value="Prefer not to say" <?php echo $oldInput['gender'] === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" name="student_id" class="form-control" placeholder="Student ID" value="<?php echo htmlspecialchars($oldInput['student_id']); ?>" data-required="true">
                </div>
                <div class="col-md-6">
                    <select name="program" class="form-select" data-required="true">
                        <option value="" disabled <?php echo $oldInput['program'] === '' ? 'selected' : ''; ?>>Select Program</option>
                        <?php foreach ($programOptions as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>" <?php echo $oldInput['program'] === $code ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" name="department" class="form-control" placeholder="Department / Program Unit" value="<?php echo htmlspecialchars($oldInput['department']); ?>" data-required="true">
                </div>
                <div class="col-md-6">
                    <input type="text" name="college" class="form-control" placeholder="Institute / College" value="<?php echo htmlspecialchars($oldInput['college']); ?>" data-required="true">
                </div>
            </div>
            <div class="mb-3">
                <select name="year_level" class="form-select" data-required="true">
                    <option value="" disabled <?php echo $oldInput['year_level'] === '' ? 'selected' : ''; ?>>Select Year Level</option>
                    <?php foreach ($yearOptions as $year): ?>
                        <option value="<?php echo htmlspecialchars($year, ENT_QUOTES); ?>" <?php echo $oldInput['year_level'] === $year ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="terms" required>
                <label for="terms" class="form-check-label">
                    I have read and agree to the
                    <a href="terms.php?back=register.php#registration" target="_blank" rel="noopener noreferrer" class="text-primary text-decoration-underline fw-semibold">Terms &amp; Conditions</a>
                </label>
            </div>
            <button type="submit" name="register" class="btn btn-register w-100 text-white">Register</button>
        </form>
        <p class="text-center mt-3">Already have an account? <a href="login.php" class="text-decoration-none text-success">Login</a></p>
    </div>

    <script>
        const passwordField = document.getElementById('password');
        const confirmPasswordField = document.getElementById('confirm_password');
        const togglePassword = document.getElementById('togglePassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const togglePasswordIcon = document.getElementById('togglePasswordIcon');
        const toggleConfirmPasswordIcon = document.getElementById('toggleConfirmPasswordIcon');
        const passwordMatchStatus = document.getElementById('passwordMatchStatus');
        const passwordMatchIcon = document.getElementById('passwordMatchIcon');

        function toggleFieldVisibility(field, icon) {
            if (!field) {
                return;
            }
            const isHidden = field.type === 'password';
            field.type = isHidden ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('bi-eye', isHidden);
                icon.classList.toggle('bi-eye-slash', !isHidden);
            }
        }

        if (togglePassword) {
            togglePassword.addEventListener('click', () => toggleFieldVisibility(passwordField, togglePasswordIcon));
        }
        if (toggleConfirmPassword) {
            toggleConfirmPassword.addEventListener('click', () => toggleFieldVisibility(confirmPasswordField, toggleConfirmPasswordIcon));
        }

        function updatePasswordMatchStatus() {
            if (!passwordMatchStatus || !passwordMatchIcon) {
                return;
            }

            const passwordValue = passwordField ? passwordField.value : '';
            const confirmValue = confirmPasswordField ? confirmPasswordField.value : '';
            const messageNode = passwordMatchStatus.querySelector('span');

            if (!passwordValue && !confirmValue) {
                messageNode.textContent = 'Passwords will be checked as you type.';
                passwordMatchStatus.className = 'text-muted d-flex align-items-center gap-2';
                passwordMatchIcon.className = 'bi bi-dash-circle';
                return;
            }

            if (!passwordValue || !confirmValue) {
                messageNode.textContent = 'Please retype the password to confirm it.';
                passwordMatchStatus.className = 'text-muted d-flex align-items-center gap-2';
                passwordMatchIcon.className = 'bi bi-dash-circle';
                return;
            }

            if (passwordValue === confirmValue) {
                messageNode.textContent = 'Passwords match.';
                passwordMatchStatus.className = 'text-success fw-semibold d-flex align-items-center gap-2';
                passwordMatchIcon.className = 'bi bi-check-circle-fill';
            } else {
                messageNode.textContent = 'Passwords do not match.';
                passwordMatchStatus.className = 'text-danger fw-semibold d-flex align-items-center gap-2';
                passwordMatchIcon.className = 'bi bi-x-circle-fill';
            }
        }

        if (passwordField) {
            passwordField.addEventListener('input', updatePasswordMatchStatus);
        }
        if (confirmPasswordField) {
            confirmPasswordField.addEventListener('input', updatePasswordMatchStatus);
        }
        updatePasswordMatchStatus();
    </script>
</body>
</html>
