<?php
session_start();
include 'db.php'; // Database connection
require_once 'role_helpers.php';

// Diagnostic logging function
function detailed_error_log($message, $data = null) {
    $log = "[LOGIN DEBUG] " . $message . "\n";
    if ($data !== null) {
        $log .= print_r($data, true) . "\n";
    }
    error_log($log);
    return $log;
}

// Debugging function to output errors directly on the page
function display_login_error($message) {
    $_SESSION['login_error'] = $message;
    // Output error directly to help with debugging
    echo "<div style='color:red; background:yellow; padding:10px; position:fixed; top:0; left:0; width:100%; z-index:1000;'>";
    echo "Login Error: " . htmlspecialchars($message);
    echo "</div>";
}

if (isset($_POST['login'])) {
    // Prevent any potential output
    ob_clean();
    ob_start();

    // Ensure no output before headers
    header('Content-Type: text/html; charset=utf-8');
    
    // Enhanced error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // Debugging: Log all POST data
    error_log("Login Attempt POST Data: " . print_r($_POST, true));

    $username = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Single, comprehensive input validation
    if (empty($username) || empty($password)) {
        $error = "Email and password are required.";
        error_log("Login failed: Empty credentials");
        
        // Use session to store error for display
        $_SESSION['login_error'] = $error;
        
        // Prevent further processing
        ob_end_clean();
        header("Location: login.php");
        exit;
    }

    // Log input details
    detailed_error_log("Login Attempt", [
        'email' => $username,
        'password_length' => strlen($password)
    ]);

    // Database connection check
    if (!$conn) {
        $connectionError = "Database connection failed: " . mysqli_connect_error();
        detailed_error_log($connectionError);
        $_SESSION['login_error'] = $connectionError;
        header("Location: login.php");
        exit;
    }

    // Prepare statement with detailed error checking
    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        $prepareError = "Prepare statement failed: " . $conn->error;
        detailed_error_log($prepareError);
        $_SESSION['login_error'] = $prepareError;
        header("Location: login.php");
        exit;
    }

    $stmt->bind_param("s", $username);
    
    // Execute with error checking
    if (!$stmt->execute()) {
        $executeError = "Query execution failed: " . $stmt->error;
        detailed_error_log($executeError);
        $_SESSION['login_error'] = $executeError;
        header("Location: login.php");
        exit;
    }

    $result = $stmt->get_result();

    // Log result details
    detailed_error_log("Query Result", [
        'num_rows' => $result->num_rows
    ]);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Log user details (be careful with sensitive information)
        detailed_error_log("User Found", [
            'id' => $row['id'],
            'email' => $row['email'],
            'password_hash_length' => strlen($row['password'])
        ]);

        // Verify password with detailed logging
        $passwordVerifyResult = password_verify($password, $row['password']);
        
        detailed_error_log("Password Verification", [
            'result' => $passwordVerifyResult ? 'MATCH' : 'NO MATCH',
            'stored_hash' => substr($row['password'], 0, 20) . '...' // Partial hash for debugging
        ]);

        if ($passwordVerifyResult) {
            $userId = (int)$row['id'];
            
            // Enhanced role determination with validation
            $defaultRole = (string)($row['role'] === 'reviewer' ? 'reviewer' : $row['role']);
            detailed_error_log("Login attempt for user $userId with default role: $defaultRole");

            try {
                // Existing role and login logic
                ensureRoleInfrastructure($conn);
                ensureUserRoleAssignment($conn, $userId, $defaultRole);
                ensureRoleBundleAssignments($conn, $userId, $defaultRole);

                $assignments = refreshUserSessionRoles($conn, $userId, $defaultRole);
                
                if (empty($assignments)) {
                    $error = "No valid roles assigned. Contact system administrator.";
                    detailed_error_log("Login failed: No valid roles for user $userId");
                    $_SESSION['login_error'] = $error;
                    header("Location: login.php");
                    exit;
                }

                $activeRole = determinePreferredRole($assignments, $defaultRole);
                detailed_error_log("Active role determined: $activeRole");
                
                if (!validateRoleAssignment($conn, $userId, $activeRole, $activeRole)) {
                    $error = "Invalid role assignment. Contact system administrator.";
                    detailed_error_log("Login failed: Invalid role assignment for user $userId");
                    $_SESSION['login_error'] = $error;
                    header("Location: login.php");
                    exit;
                }

                // Existing session and role setup logic
                setActiveRole($activeRole);
                setUserPrimaryRole($conn, $userId, $activeRole);
                $_SESSION['role_switch_token'] = getRoleSwitchToken();
                $_SESSION['user_id'] = $userId;
                $_SESSION['available_roles'] = $assignments;
                $_SESSION['program'] = $row['program'] ?? '';
                $_SESSION['department'] = $row['department'] ?? '';
                $_SESSION['college'] = $row['college'] ?? '';

                logRoleSwitch($conn, $userId, '', $activeRole);

                $redirectPage = getRoleDashboard($activeRole);
                detailed_error_log("Redirecting user $userId to $redirectPage");
                header("Location: {$redirectPage}");
                exit;

            } catch (Exception $e) {
                $error = "System error during login. Please contact administrator.";
                detailed_error_log("Login exception for user $userId: " . $e->getMessage());
                $_SESSION['login_error'] = $error;
                header("Location: login.php");
                exit;
            }
        } else {
            $error = "Incorrect email or password.";
            detailed_error_log("Failed login attempt for email: $username");
            
            // Store error in session for display
            $_SESSION['login_error'] = $error;
            
            // Prevent further processing
            ob_end_clean();
            header("Location: login.php");
            exit;
        }
    } else {
        $error = "Incorrect email or password.";
        detailed_error_log("No user found for email: $username");
        
        // Store error in session for display
        $_SESSION['login_error'] = $error;
        
        // Prevent further processing
        ob_end_clean();
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Institute of Advanced Studies</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', sans-serif;
            opacity: 5;
        }
        .login-container {
            max-width: 420px;
            margin: auto;
            margin-top: 5%;
            background: #fff;
            padding: 35px;
            border-radius: 10px;
            box-shadow: 0 5px 10px rgba(0,0,0,0.08);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 25px;
        }
        .login-logo img {
            max-height: 80px;
        }
        .btn-login {
            background: #198754;
            border: none;
        }
        .btn-login:hover {
            background: #146c43;
        }
        .social-login {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 15px;
        }
        .social-login button {
            border: 1px solid #ddd;
            background: #fff;
            padding: 8px 15px;
            border-radius: 5px;
            width: 48%;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .social-login img {
            width: 20px;
            height: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
         <div class="text-center mb-3">
             <img src="IAdS.png" alt="DNSC LMS Logo" style="max-width: 150px;">
        </div>

        <?php
        // Display login error if exists
        if (!empty($_SESSION['login_error'])) {
            echo "<div class='alert alert-danger'>" .
                 htmlspecialchars($_SESSION['login_error']) .
                 "</div>";
            // Clear the error after displaying
            unset($_SESSION['login_error']);
        }
        ?>

        <h4 class="text-center mb-4">Welcome Back Markglen Pogi!</h4>
        <?php if (!empty($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
        <form method="POST">
            <div class="mb-3">
                <input type="text" name="email" class="form-control" placeholder="Email" required>
            </div>
            <div class="mb-3 position-relative">
                <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                <button type="button" id="togglePassword" class="btn position-absolute top-50 end-0 translate-middle-y me-2" style="background:none; border:none;">
                    <i class="bi bi-eye" id="toggleIcon"></i>
                </button>
            </div>
            <button type="submit" name="login" class="btn btn-login w-100 text-white">Log in</button>
        </form>
        <p class="text-center mt-3">Don't have an account? <a href="register.php" class="text-decoration-none text-primary">Register</a></p>
        
        <hr>
        <p class="text-center small text-muted mb-2">Sign in with your DNSC Account:</p>
        <div class="social-login">
            <button><img src="https://img.icons8.com/color/48/google-logo.png"/> Google</button>
            <button><img src="https://img.icons8.com/color/48/microsoft.png"/> Microsoft</button>
        </div>
    </div>
<script>
    const togglePassword = document.querySelector("#togglePassword");
    const password = document.querySelector("#password");
    const toggleIcon = document.querySelector("#toggleIcon");

    togglePassword.addEventListener("click", function () {
        const type = password.getAttribute("type") === "password" ? "text" : "password";
        password.setAttribute("type", type);
        toggleIcon.classList.toggle("bi-eye");
        toggleIcon.classList.toggle("bi-eye-slash");
    });
</script>
</body>
</html>
