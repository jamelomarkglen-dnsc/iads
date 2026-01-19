<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'program_chairperson') {
    header("Location: login.php");
    exit;
}

$message = "";
$oldInput = [
    'fullname' => '',
    'email' => '',
    'contact' => '',
    'gender' => '',
    'department' => '',
    'college' => '',
    'role' => 'faculty',
    'username' => '',
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

function split_name($name)
{
    $parts = preg_split('/\s+/', trim($name));
    $first = array_shift($parts) ?? '';
    $last = implode(' ', $parts);
    return [$first, $last];
}

$hasSpecializationColumn = false;
try {
    $columnCheck = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'specialization' LIMIT 1");
    if ($columnCheck instanceof mysqli_result) {
        $hasSpecializationColumn = $columnCheck->num_rows > 0;
        $columnCheck->free();
    }
} catch (Exception $e) {
    $hasSpecializationColumn = false;
}

if (isset($_POST['create'])) {
    foreach ($oldInput as $key => $value) {
        $oldInput[$key] = trim($_POST[$key] ?? '');
    }
    $oldInput['role'] = 'faculty';
    $passwordPlain = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($passwordPlain !== $confirmPassword) {
        $message = "<div class='alert alert-danger'>Passwords do not match.</div>";
    } elseif (!preg_match('/^\d{10,15}$/', $oldInput['contact'])) {
        $message = "<div class='alert alert-danger'>Contact number should contain 10-15 digits.</div>";
    } elseif (!filter_var($oldInput['email'], FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert alert-danger'>Please enter a valid email address.</div>";
    } elseif ($oldInput['department'] === '') {
        $message = "<div class='alert alert-danger'>Please select a program.</div>";
    } else {
        $email = $oldInput['email'];
        $username = $oldInput['username'];

        $check = $conn->prepare("SELECT 1 FROM users WHERE email = ? OR username = ?");
        $check->bind_param("ss", $email, $username);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $message = "<div class='alert alert-danger'>Email or username already exists!</div>";
        } else {
            [$firstname, $lastname] = split_name($oldInput['fullname']);
            $password = password_hash($passwordPlain, PASSWORD_DEFAULT);
            $sql = null;

            if ($hasSpecializationColumn) {
                $sql = $conn->prepare("
                    INSERT INTO users (firstname, lastname, username, password, email, role, contact, gender, department, college, specialization)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($sql) {
                    $sql->bind_param(
                        "sssssssssss",
                        $firstname,
                        $lastname,
                        $username,
                        $password,
                        $email,
                        $oldInput['role'],
                        $oldInput['contact'],
                        $oldInput['gender'],
                        $oldInput['department'],
                        $oldInput['college'],
                        $oldInput['specialization']
                    );
                }
            } else {
                $sql = $conn->prepare("
                    INSERT INTO users (firstname, lastname, username, password, email, role, contact, gender, department, college)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($sql) {
                    $sql->bind_param(
                        "ssssssssss",
                        $firstname,
                        $lastname,
                        $username,
                        $password,
                        $email,
                        $oldInput['role'],
                        $oldInput['contact'],
                        $oldInput['gender'],
                        $oldInput['department'],
                        $oldInput['college']
                    );
                }
            }

            if ($sql) {
                if ($sql->execute()) {
                    $message = "<div class='alert alert-success'>Faculty account created successfully!</div>";
                    foreach ($oldInput as $key => $value) {
                        $oldInput[$key] = '';
                    }
                } else {
                    $message = "<div class='alert alert-danger'>Error creating account.</div>";
                }
                $sql->close();
            } else {
                $message = "<div class='alert alert-danger'>Unable to process account creation. Please contact the administrator.</div>";
            }
        }
    }
}

$roleCounts = [
    'faculty' => 0,
];
$countResult = $conn->query("SELECT role, COUNT(*) as total FROM users WHERE role = 'faculty' GROUP BY role");
if ($countResult) {
    while ($row = $countResult->fetch_assoc()) {
        $roleCounts[$row['role']] = (int)$row['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Faculty Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="IAdS.css" rel="stylesheet">
    <script src="https://kit.fontawesome.com/a2e0a1e6d3.js" crossorigin="anonymous"></script>
    <style>
        body.create-faculty-page {
            min-height: 100vh;
            background: radial-gradient(circle at top left, rgba(22, 86, 44, 0.35), transparent 55%),
                        radial-gradient(circle at bottom right, rgba(24, 53, 92, 0.35), transparent 50%),
                        linear-gradient(135deg, #f3f7f5, #e6f0eb);
        }
        .account-card {
            backdrop-filter: blur(4px);
            border-radius: 1.25rem;
        }
        .account-card .card-header {
            background: linear-gradient(135deg, #16562c, #0f3b1d);
        }
        .floating-label {
            font-size: 0.9rem;
            color: #455a4f;
        }
        .form-control:focus, .form-select:focus {
            border-color: #16562c;
            box-shadow: 0 0 0 0.2rem rgba(22, 86, 44, 0.25);
        }
        .role-badge {
            font-size: 0.75rem;
            background: rgba(22, 86, 44, 0.1);
            color: #16562c;
        }
        @media (max-width: 576px) {
            .account-card {
                border-radius: 0.85rem;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="create-faculty-page">
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main class="container py-5 px-3 px-md-4" style="max-width: 860px;">
    <div class="text-center mb-4">
        <h1 class="h3 fw-semibold text-success mb-2">Faculty Onboarding</h1>
        <p class="text-muted mb-0">Fill out the fields below to set up a new faculty account.</p>
    </div>
    <div class="row g-3 mb-4">
        <?php foreach ($roleCounts as $roleName => $total): ?>
            <div class="col-12 col-sm-4">
                <div class="border rounded-4 p-3 h-100 bg-white shadow-sm">
                    <div class="d-flex align-items-center">
                        <span class="role-badge px-3 py-1 rounded-pill text-uppercase fw-semibold me-3">
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $roleName))); ?>
                        </span>
                        <span class="fs-4 fw-bold text-success"><?php echo $total; ?></span>
                    </div>
                    <div class="small text-muted mt-2">Active faculty accounts.</div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card account-card shadow border-0">
        <div class="card-header text-center text-white py-4">
            <h3 class="mb-0 fw-bold">Create Faculty Account</h3>
            <p class="mb-0 small text-white-50">Ensure the information is accurate before submitting.</p>
        </div>
        <div class="card-body p-4 p-lg-5">
            <?php echo $message; ?>
            <form method="POST" novalidate class="needs-validation">
                <div class="mb-4">
                    <label class="form-label floating-label">Full Name</label>
                    <input type="text" name="fullname" class="form-control form-control-lg" placeholder="Juan Dela Cruz"
                           value="<?php echo htmlspecialchars($oldInput['fullname'], ENT_QUOTES); ?>" required>
                    <div class="form-text">Include middle initial if available.</div>
                </div>
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label floating-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="form-control" placeholder="name@school.edu"
                                   value="<?php echo htmlspecialchars($oldInput['email'], ENT_QUOTES); ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label floating-label">Contact Number</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-telephone"></i></span>
                            <input type="tel" name="contact" class="form-control" placeholder="09XXXXXXXXX"
                                   value="<?php echo htmlspecialchars($oldInput['contact'], ENT_QUOTES); ?>" required
                                   pattern="\d{10,15}" inputmode="numeric">
                        </div>
                        <div class="form-text">Digits only, 10-15 characters.</div>
                    </div>
                </div>
                <div class="mt-4">
                    <label class="form-label floating-label">Gender</label>
                    <select name="gender" class="form-select" required>
                        <option value="" disabled <?php echo $oldInput['gender'] === '' ? 'selected' : ''; ?>>Select Gender</option>
                        <option value="Male" <?php echo $oldInput['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $oldInput['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                        <option value="Prefer not to say" <?php echo $oldInput['gender'] === 'Prefer not to say' ? 'selected' : ''; ?>>Prefer not to say</option>
                    </select>
                </div>
                <div class="mt-4">
                    <label class="form-label floating-label">Program</label>
                    <select name="department" class="form-select" required>
                        <option value="" disabled <?php echo $oldInput['department'] === '' ? 'selected' : ''; ?>>Select Program</option>
                        <?php foreach ($programOptions as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES); ?>" <?php echo $oldInput['department'] === $code ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mt-4">
                    <label class="form-label floating-label">Institute</label>
                    <input type="text" name="college" class="form-control"
                           placeholder="Institute of Advance Studies"
                           value="Institute of Advance Studies" readonly required>
                </div>
                <div class="mt-4">
                    <label class="form-label floating-label">Role</label>
                    <input type="text" class="form-control form-control-lg" value="Faculty" disabled>
                    <input type="hidden" name="role" value="faculty">
                </div>
                <?php if ($hasSpecializationColumn): ?>
                    <div class="mt-4">
                        <label class="form-label floating-label">Specialization</label>
                        <input type="text" name="specialization" class="form-control"
                               placeholder="e.g., Data Science, Software Engineering"
                               value="<?php echo htmlspecialchars($oldInput['specialization'], ENT_QUOTES); ?>">
                        <div class="form-text">Optional: helps students match advisers by expertise.</div>
                    </div>
                <?php endif; ?>
                <hr class="my-4">
                <h5 class="fw-bold text-secondary text-center mb-3">Account Credentials</h5>
                <div class="mb-4">
                    <label class="form-label floating-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-person-badge"></i></span>
                        <input type="text" name="username" class="form-control"
                               placeholder="username"
                               value="<?php echo htmlspecialchars($oldInput['username'], ENT_QUOTES); ?>" required>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label floating-label">Password</label>
                        <div class="input-group">
                            <button type="button" class="btn btn-outline-success">
                                <i class="bi bi-shield-lock"></i>
                            </button>
                            <input type="password" name="password" id="password" class="form-control" required minlength="8"
                                   placeholder="Min. 8 characters">
                            <button type="button" id="togglePassword" class="btn btn-outline-secondary">
                                <i class="bi bi-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                        <div class="form-text" id="passwordHelp">Use at least 8 characters with letters and numbers.</div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar bg-success" id="passwordStrength" role="progressbar" style="width: 0;"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label floating-label">Confirm Password</label>
                        <div class="input-group">
                            <button type="button" class="btn btn-outline-success">
                                <i class="bi bi-check2-circle"></i>
                            </button>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="8"
                                   placeholder="Re-type password">
                            <button type="button" id="toggleConfirmPassword" class="btn btn-outline-secondary">
                                <i class="bi bi-eye" id="toggleConfirmIcon"></i>
                            </button>
                        </div>
                        <div class="form-text text-danger d-none" id="passwordMismatch">Passwords do not match.</div>
                    </div>
                </div>
                <div class="d-grid mt-5">
                    <button type="submit" name="create" class="btn btn-success btn-lg">
                        <i class="bi bi-person-plus-fill me-2"></i>Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    (function () {
        const passwordInput = document.querySelector("#password");
        const confirmPasswordInput = document.querySelector("#confirm_password");
        const togglePasswordBtn = document.querySelector("#togglePassword");
        const togglePasswordIcon = document.querySelector("#toggleIcon");
        const toggleConfirmBtn = document.querySelector("#toggleConfirmPassword");
        const toggleConfirmIcon = document.querySelector("#toggleConfirmIcon");
        const passwordStrengthBar = document.querySelector("#passwordStrength");
        const passwordMismatchText = document.querySelector("#passwordMismatch");

        if (togglePasswordBtn && passwordInput) {
            togglePasswordBtn.addEventListener("click", function () {
                const type = passwordInput.getAttribute("type") === "password" ? "text" : "password";
                passwordInput.setAttribute("type", type);
                if (togglePasswordIcon) {
                    togglePasswordIcon.classList.toggle("bi-eye");
                    togglePasswordIcon.classList.toggle("bi-eye-slash");
                }
            });
        }

        if (toggleConfirmBtn && confirmPasswordInput) {
            toggleConfirmBtn.addEventListener("click", function () {
                const type = confirmPasswordInput.getAttribute("type") === "password" ? "text" : "password";
                confirmPasswordInput.setAttribute("type", type);
                if (toggleConfirmIcon) {
                    toggleConfirmIcon.classList.toggle("bi-eye");
                    toggleConfirmIcon.classList.toggle("bi-eye-slash");
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
            if (!passwordInput || !passwordStrengthBar) {
                return;
            }
            const value = passwordInput.value;
            if (!value) {
                passwordStrengthBar.style.width = "0%";
                passwordStrengthBar.classList.remove("bg-danger", "bg-warning", "bg-success");
                return;
            }
            const strength = evaluateStrength(value);
            passwordStrengthBar.style.width = strength + "%";
            passwordStrengthBar.classList.remove("bg-danger", "bg-warning", "bg-success");
            if (strength <= 25) {
                passwordStrengthBar.classList.add("bg-danger");
            } else if (strength < 75) {
                passwordStrengthBar.classList.add("bg-warning");
            } else {
                passwordStrengthBar.classList.add("bg-success");
            }
        }

        function checkMatch() {
            if (!passwordInput || !confirmPasswordInput || !passwordMismatchText) {
                return;
            }
            const mismatch = passwordInput.value && confirmPasswordInput.value && passwordInput.value !== confirmPasswordInput.value;
            passwordMismatchText.classList.toggle("d-none", !mismatch);
        }

        if (passwordInput) {
            passwordInput.addEventListener("input", function () {
                updateStrength();
                checkMatch();
            });
        }

        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener("input", checkMatch);
        }

        updateStrength();
        checkMatch();

        const forms = document.querySelectorAll(".needs-validation");
        Array.prototype.slice.call(forms).forEach(function (form) {
            form.addEventListener("submit", function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add("was-validated");
            }, false);
        });
    })();
</script>
</body>
</html>
