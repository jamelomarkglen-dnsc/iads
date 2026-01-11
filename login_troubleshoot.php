<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
require_once 'db.php';

// Detailed error logging function
function logError($message, $context = []) {
    $logEntry = date('Y-m-d H:i:s') . " - " . $message . "\n";
    if (!empty($context)) {
        $logEntry .= "Context: " . print_r($context, true) . "\n";
    }
    file_put_contents('login_troubleshoot.log', $logEntry, FILE_APPEND);
}

// Comprehensive login troubleshooting
function troubleshootLogin($username, $password) {
    global $conn;
    
    // Validate input
    if (empty($username) || empty($password)) {
        logError("Empty credentials", [
            'username' => $username,
            'password_length' => strlen($password)
        ]);
        return "Empty credentials are not allowed.";
    }

    // Database connection check
    if (!$conn) {
        $connectionError = mysqli_connect_error();
        logError("Database connection failed", [
            'error' => $connectionError
        ]);
        return "Database connection error: " . $connectionError;
    }

    // Prepare statement
    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        $prepareError = $conn->error;
        logError("Statement preparation failed", [
            'sql' => $sql,
            'error' => $prepareError
        ]);
        return "SQL statement preparation error: " . $prepareError;
    }

    // Bind and execute
    $stmt->bind_param("s", $username);
    $executeResult = $stmt->execute();

    if (!$executeResult) {
        $executeError = $stmt->error;
        logError("Statement execution failed", [
            'username' => $username,
            'error' => $executeError
        ]);
        return "Statement execution error: " . $executeError;
    }

    // Get results
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        logError("No user found", [
            'username' => $username
        ]);
        return "No user found with this email.";
    }

    // Fetch user data
    $user = $result->fetch_assoc();

    // Verify password
    if (!password_verify($password, $user['password'])) {
        logError("Password verification failed", [
            'username' => $username,
            'stored_hash_length' => strlen($user['password'])
        ]);
        return "Incorrect password.";
    }

    // Successful login
    return true;
}

// Handle form submission
$loginResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    $loginResult = troubleshootLogin($username, $password);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login Troubleshooter</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h3 class="text-center">Login Troubleshooter</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($loginResult !== true && $loginResult !== null): ?>
                            <div class="alert alert-danger">
                                <?= htmlspecialchars($loginResult) ?>
                            </div>
                        <?php elseif ($loginResult === true): ?>
                            <div class="alert alert-success">
                                Login Successful! 
                                <a href="index.php" class="btn btn-primary btn-sm">Continue</a>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="text" name="email" class="form-control" placeholder="Enter email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="Enter password">
                            </div>
                            <button type="submit" name="login" class="btn btn-danger w-100">Troubleshoot Login</button>
                        </form>
                    </div>
                    <div class="card-footer">
                        <small class="text-muted">
                            Detailed logs are saved in login_troubleshoot.log
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>