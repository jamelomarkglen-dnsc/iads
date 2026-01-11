<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
require_once 'db.php';

// Function to log diagnostic information
function logDiagnostic($message) {
    error_log("[LOGIN DIAGNOSTIC] " . $message);
    echo "<div class='alert alert-info'>" . htmlspecialchars($message) . "</div>";
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logDiagnostic("Form submitted via POST method");

    // Check if login button is set
    if (!isset($_POST['login'])) {
        logDiagnostic("ERROR: Login button not set in POST data");
    }

    // Retrieve and trim credentials
    $username = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Validate input
    if (empty($username) || empty($password)) {
        logDiagnostic("ERROR: Empty credentials");
        logDiagnostic("Username: " . ($username ? 'Provided' : 'Empty'));
        logDiagnostic("Password: " . ($password ? 'Provided' : 'Empty'));
    }

    // Database connection diagnostic
    if (!$conn) {
        logDiagnostic("CRITICAL: Database connection failed");
        logDiagnostic("Connection Error: " . mysqli_connect_error());
    } else {
        logDiagnostic("Database connection successful");
    }

    // Prepare statement diagnostic
    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        logDiagnostic("ERROR: Statement preparation failed");
        logDiagnostic("Prepare Error: " . $conn->error);
    } else {
        logDiagnostic("SQL Statement prepared successfully");

        // Bind and execute
        $stmt->bind_param("s", $username);
        $executeResult = $stmt->execute();

        if (!$executeResult) {
            logDiagnostic("ERROR: Statement execution failed");
            logDiagnostic("Execute Error: " . $stmt->error);
        } else {
            logDiagnostic("Statement executed successfully");
            
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                
                // Password verification diagnostic
                $passwordVerifyResult = password_verify($password, $row['password']);
                
                if ($passwordVerifyResult) {
                    logDiagnostic("Password verified successfully");
                } else {
                    logDiagnostic("ERROR: Password verification failed");
                }
            } else {
                logDiagnostic("No user found with the provided email");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login Diagnostic</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h3 class="text-center">Login Diagnostic</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="text" name="email" class="form-control" placeholder="Enter email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Enter password">
                            </div>
                            <button type="submit" name="login" class="btn btn-primary w-100">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>