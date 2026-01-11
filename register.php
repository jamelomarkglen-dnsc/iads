<?php
session_start();
include 'db.php'; // database connection

$message = "";

if (isset($_POST['register'])) {
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $username = $firstname . " " . $lastname;

    // Check if email already exists
    $check = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $message = "<div class='alert alert-danger'>Email already exists!</div>";
    } else {
        $sql = $conn->prepare("INSERT INTO users (firstname, lastname, username, password, email, role) VALUES (?, ?, ?, ?, ?, ?)");
        $sql->bind_param("ssssss", $firstname, $lastname, $username, $password, $email, $role);

        if ($sql->execute()) {
            $message = "<div class='alert alert-success'>Registration successful! Redirecting...</div>";
            header("refresh:2; url=login.php");
        } else {
            $message = "<div class='alert alert-danger'>Error: Could not register.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Institute of Advanced Studies</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', sans-serif;
        }
        .register-container {
            max-width: 450px;
            margin: auto;
            margin-top: 3%;
            background: #fff;
            padding: 35px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
    </style>
</head>
<body class="d-flex justify-content-center align-items-center vh-100">
    <div class="register-container">
        <h3 class="register-title">Create Your Account</h3>
        <?php echo $message; ?>
        <form method="POST">
            <div class="row mb-3">
                <div class="col">
                    <input type="text" name="firstname" class="form-control" placeholder="First Name" required>
                </div>
                <div class="col">
                    <input type="text" name="lastname" class="form-control" placeholder="Last Name" required>
                </div>
            </div>
            <div class="mb-3">
                <input type="email" name="email" class="form-control" placeholder="Email" required>
            </div>
            <div class="mb-3">
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            <div class="mb-3">
                <select name="role" class="form-select" required>
                    <option value="" disabled selected>Select Role</option>
                    <option value="student">Student</option>
                    <option value="dean">Dean</option>
                    <option value="program_chairperson">Program Chairperson</option>
                    <option value="faculty">Faculty</option>
                </select>
            </div>
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="terms" required>
                <label for="terms" class="form-check-label">I agree to the Terms & Conditions</label>
            </div>
            <button type="submit" name="register" class="btn btn-register w-100 text-white">Register</button>
        </form>
        <p class="text-center mt-3">Already have an account? <a href="login.php" class="text-decoration-none text-success">Login</a></p>
    </div>
</body>
</html>
