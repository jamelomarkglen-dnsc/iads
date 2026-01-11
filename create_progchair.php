<?php
session_start();
include 'db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'dean') {
    header("Location: login.php");
    exit;
}

$message = "";
if (isset($_POST['create'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $college = trim($_POST['college'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $program_focus = trim($_POST['program_focus'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $passwordPlain = $_POST['password'] ?? '';
    $password = password_hash($passwordPlain, PASSWORD_DEFAULT);
    $assign_option = isset($_POST['assign_option']) ? 1 : 0;

    if ($department === '' || $program_focus === '') {
        $message = "<div class='alert alert-danger'>Please provide both the department and the program handled.</div>";
    } elseif ($fullname === '' || $email === '' || $username === '' || $passwordPlain === '') {
        $message = "<div class='alert alert-danger'>All required fields must be completed.</div>";
    } else {

        // Handle file upload
        $photo = NULL;
        if (!empty($_FILES['photo']['name'])) {
            if (!is_dir("uploads")) { mkdir("uploads", 0777, true); } 
            $photo = "uploads/" . basename($_FILES['photo']['name']);
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo);
        }

        // Check for duplicate email
        $check = $conn->prepare("SELECT id FROM users WHERE email=?");
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $message = "<div class='alert alert-danger'>Email already exists!</div>";
        } else {
            $sql = $conn->prepare("INSERT INTO users (firstname, lastname, username, password, email, role, contact, gender, department, college, program) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $names = preg_split('/\s+/', $fullname, 2);
            $firstname = $names[0] ?? '';
            $lastname = $names[1] ?? '';
            $role = 'program_chairperson';
            $sql->bind_param("sssssssssss", $firstname, $lastname, $username, $password, $email, $role, $contact, $gender, $department, $college, $program_focus);
            if ($sql->execute()) {
                $message = "<div class='alert alert-success'>Program Chairperson created successfully!</div>";
            } else {
                $message = "<div class='alert alert-danger'>Error creating account.</div>";
            }
        }
        $check->close();
        if (isset($sql)) {
            $sql->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Program Chairperson</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dnsc-green: #16562C;
            --dnsc-green-dark: #0f3e1f;
            --dnsc-cream: #f5f8f5;
        }
        body {
            background: linear-gradient(135deg, #edf4ef, #fdfefd);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            color: #27372b;
        }
        .content {
            margin-left: 220px;
            padding: 2.5rem;
            min-height: 100vh;
            transition: margin-left .3s ease;
        }
        #sidebar.collapsed ~ .content {
            margin-left: 70px;
        }
        .page-wrapper {
            position: relative;
            z-index: 0;
            max-width: 1200px;
            margin: 0 auto;
        }
        .page-header {
            background: #fff;
            border-radius: 1.25rem;
            padding: 1.5rem 2rem;
            box-shadow: 0 15px 40px rgba(22, 86, 44, 0.08);
            border-left: 5px solid var(--dnsc-green);
        }
        .page-header p {
            margin: 0;
        }
        .btn-outline-success {
            border-color: rgba(22, 86, 44, 0.35);
            color: var(--dnsc-green);
        }
        .btn-outline-success:hover {
            background: var(--dnsc-green);
            color: #fff;
        }
        .form-card {
            border-radius: 1.2rem;
        }
        .form-card .card-body {
            padding: 2.25rem;
        }
        .badge-soft {
            background: rgba(22, 86, 44, 0.12);
            color: var(--dnsc-green-dark);
            font-size: 0.75rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            font-weight: 600;
        }
        .form-section + .form-section {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eef2ee;
        }
        .form-label {
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 600;
            color: #6b7a6f;
        }
        .form-control,
        .form-select {
            border-radius: 0.85rem;
            border-color: #dfe6df;
            padding: 0.65rem 0.95rem;
            font-size: 0.95rem;
        }
        .form-control:focus,
        .form-select:focus {
            border-color: var(--dnsc-green);
            box-shadow: 0 0 0 0.2rem rgba(22, 86, 44, 0.15);
        }
        .btn-dnsc {
            background: var(--dnsc-green);
            color: #fff;
            font-weight: 600;
            border-radius: 0.85rem;
            padding: 0.8rem 2.5rem;
            box-shadow: 0 10px 25px rgba(22, 86, 44, 0.2);
        }
        .btn-dnsc:hover {
            background: var(--dnsc-green-dark);
            color: #fff;
        }
        .toggle-password-btn {
            border: none;
            border-left: 1px solid #e2e8e2;
            padding: 0 1rem;
        }
        .toggle-password-btn:hover {
            background: rgba(22, 86, 44, 0.05);
            color: var(--dnsc-green);
        }
        .assign-card {
            background: var(--dnsc-cream);
            border-radius: 1rem;
            border: 1px solid rgba(22, 86, 44, 0.15);
        }
        #chairInfo {
            display: none;
        }
        .alert-soft-success {
            background: rgba(22, 86, 44, 0.08);
            border: 1px solid rgba(22, 86, 44, 0.2);
            border-radius: 0.9rem;
            color: var(--dnsc-green);
            font-weight: 600;
        }
        .info-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #16562C, #0f3e1f);
            border-radius: 1.5rem;
            color: #fff;
        }
        .info-card::after {
            content: '';
            position: absolute;
            width: 320px;
            height: 320px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 50%;
            top: -120px;
            right: -60px;
            filter: blur(0px);
        }
        .info-card::before {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 50%;
            bottom: -120px;
            left: -40px;
        }
        .info-card .card-body {
            position: relative;
            z-index: 1;
        }
        .list-check i {
            color: #9fe3ad;
            font-size: 1rem;
        }
        .list-check li + li {
            margin-top: 0.8rem;
        }
        .status-pill {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: var(--dnsc-green);
            font-weight: 700;
            font-size: 1rem;
        }
        .border-white-25 {
            border-color: rgba(255, 255, 255, 0.25) !important;
        }
        @media (max-width: 991.98px) {
            .content {
                margin-left: 0;
                padding: 1.5rem;
            }
            .form-card .card-body {
                padding: 1.5rem;
            }
            .page-header {
                padding: 1.25rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>
    <div class="content">
    <main class="page-wrapper py-4 px-3 px-lg-5">
        <div class="page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-center mb-4">
            <div>
                <p class="text-uppercase text-muted small mb-1">Dean Workspace</p>
                <h1 class="h4 text-dark fw-bold mb-2">Create Program Chairperson</h1>
                <p class="text-muted mb-0">Onboard new academic leaders with a streamlined, dean-ready experience.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="program_chairperson.php" class="btn btn-outline-success px-4">
                    <i class="bi bi-people me-2"></i>Directory
                </a>
            </div>
        </div>

        <div class="row g-4 align-items-stretch">
            <div class="col-xl-7 order-2 order-xl-1">
                <div class="card form-card shadow border-0 h-100">
                    <div class="card-body">
                        <?php if (!empty($message)) { echo '<div class="mb-4">'.$message.'</div>'; } ?>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="form-section">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge-soft">Personal Profile</span>
                                    <span class="text-muted small">Verify the basic details carefully</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="fullname" class="form-control" placeholder="Enter full name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email Address</label>
                                        <input type="email" name="email" class="form-control" placeholder="name@dnsc.edu.ph" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" name="contact" class="form-control" placeholder="+63 900 000 0000" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Gender</label>
                                        <select name="gender" class="form-select" required>
                                            <option value="" disabled selected>Select gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Institute</label>
                                        <input type="text" name="college" class="form-control" placeholder="Institute / College" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Department</label>
                                        <input type="text" name="department" class="form-control" placeholder="Department / Program Unit" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Profile Photo</label>
                                        <input type="file" name="photo" class="form-control" accept="image/*">
                                        <small class="text-muted">Optional - Upload JPG, PNG, or WebP up to 2MB.</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge-soft">Program Placement</span>
                                    <span class="text-muted small">Assign their scope of leadership</span>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Program Handled</label>
                                    <select name="program_focus" class="form-select" required>
                                        <option value="" disabled selected>Select Program</option>
                                        <option value="PhD in Educational Management" data-desc="Doctor of Philosophy in Educational Management">PhD in Educational Management</option>
                                        <option value="PhD in English Language Studies and Teaching" data-desc="Doctor of Philosophy in English Language Studies and Teaching">PhD in English Language Studies and Teaching</option>
                                        <option value="MS in Marine Biodiversity & Fisheries Management" data-desc="Master of Science in Marine Biodiversity & Fisheries Management">MS in Marine Biodiversity & Fisheries Management</option>
                                        <option value="Master in Information Technology" data-desc="Master in Information Technology">Master in Information Technology</option>
                                        <option value="Custom / Others" data-desc="Custom / Others - specify during onboarding.">Custom / Others</option>
                                    </select>
                                </div>
                                <div id="chairInfo" class="alert alert-soft-success mt-2"></div>
                                <p class="text-muted small mb-0">Hover or change the selection to preview the specialization they will oversee.</p>
                            </div>

                            <div class="form-section">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge-soft">Account Credentials</span>
                                    <span class="text-muted small">Provide secure access for the new leader</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Username</label>
                                        <input type="text" name="username" class="form-control" placeholder="Username" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Password</label>
                                        <div class="input-group">
                                            <input type="password" id="password" name="password" class="form-control" placeholder="Enter password" required>
                                            <button type="button" class="btn toggle-password-btn" id="togglePassword">
                                                <i class="bi bi-eye-slash" id="togglePasswordIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Confirm Password</label>
                                        <div class="input-group">
                                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm password" required>
                                            <button type="button" class="btn toggle-password-btn" id="toggleConfirmPassword">
                                                <i class="bi bi-eye-slash" id="toggleConfirmPasswordIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="assign-card d-flex align-items-start gap-3 p-3 mt-3">
                                    <div class="form-check m-0">
                                        <input type="checkbox" name="assign_option" class="form-check-input mt-1" id="assign_option">
                                        <label for="assign_option" class="form-check-label">Allow adviser assignment</label>
                                    </div>
                                    <p class="text-muted small mb-0">
                                        Enable this to let the chairperson assign advisers for new student submissions.
                                    </p>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-3 mt-4">
                                <button type="submit" name="create" class="btn btn-dnsc">
                                    <i class="bi bi-person-plus me-2"></i>Create Account
                                </button>
                                <button type="reset" class="btn btn-link text-muted px-0">Reset Form</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-xl-5 order-1 order-xl-2">
                <div class="card info-card border-0 shadow h-100">
                    <div class="card-body p-4 p-lg-5 d-flex flex-column">
                        <p class="text-uppercase small text-white-50 mb-2">Dean Insight</p>
                        <h4 class="fw-bold text-white">Lead with clarity and a modern touch.</h4>
                        <p class="text-white-50 mb-4">Give every program chair a polished onboarding moment that reflects the standards of the Dean's office.</p>
                        <ul class="list-unstyled list-check mb-4">
                            <li class="d-flex align-items-start gap-2">
                                <i class="bi bi-check-circle-fill mt-1"></i>
                                <span>Confirm institute and contact details before saving.</span>
                            </li>
                            <li class="d-flex align-items-start gap-2">
                                <i class="bi bi-check-circle-fill mt-1"></i>
                                <span>Use the specialization preview to avoid duplicate assignments.</span>
                            </li>
                            <li class="d-flex align-items-start gap-2">
                                <i class="bi bi-check-circle-fill mt-1"></i>
                                <span>Enable adviser assignment only for senior chairpersons.</span>
                            </li>
                        </ul>
                        <div class="mt-auto pt-4 border-top border-white-25">
                            <div class="d-flex align-items-center gap-3">
                                <div class="status-pill shadow-sm">24/7</div>
                                <div>
                                    <p class="mb-0 fw-semibold text-white">Automated Access Control</p>
                                    <small class="text-white-50">Credentials activate immediately after account creation.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    </div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    if (togglePassword) {
        togglePassword.addEventListener('click', function () {
            const input = document.getElementById('password');
            const icon = document.getElementById('togglePasswordIcon');
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("bi-eye-slash");
                icon.classList.add("bi-eye");
            } else {
                input.type = "password";
                icon.classList.remove("bi-eye");
                icon.classList.add("bi-eye-slash");
            }
        });
    }

    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    if (toggleConfirmPassword) {
        toggleConfirmPassword.addEventListener('click', function () {
            const input = document.getElementById('confirm_password');
            const icon = document.getElementById('toggleConfirmPasswordIcon');
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("bi-eye-slash");
                icon.classList.add("bi-eye");
            } else {
                input.type = "password";
                icon.classList.remove("bi-eye");
                icon.classList.add("bi-eye-slash");
            }
        });
    }
</script>

<script>
  const select = document.querySelector('select[name="program_focus"]');
  const info = document.getElementById('chairInfo');
  if (select && info) {
    const updateInfo = () => {
      const selected = select.options[select.selectedIndex];
      const desc = selected ? selected.getAttribute('data-desc') : null;
      if (desc) {
        info.textContent = `Program Handled: ${desc}`;
        info.style.display = 'block';
      } else {
        info.style.display = 'none';
      }
    };

    select.addEventListener('mouseover', updateInfo);
    select.addEventListener('change', updateInfo);
  }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

