<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';

// Detailed login debugging function
function debugLogin($email, $password) {
    global $conn;
    
    // Debugging output configuration
    $debug = [
        'input_validation' => [],
        'database_query' => [],
        'password_verification' => [],
        'role_assignment' => []
    ];
    
    // 1. Input Validation
    $email = trim($email);
    $password = trim($password);
    
    $debug['input_validation']['email_length'] = strlen($email);
    $debug['input_validation']['password_length'] = strlen($password);
    $debug['input_validation']['email_format'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? 'Valid' : 'Invalid';
    
    // 2. Database Query
    $stmt = $conn->prepare("
        SELECT 
            id, 
            email, 
            password, 
            role, 
            first_name, 
            last_name, 
            program, 
            department, 
            college
        FROM users 
        WHERE email = ?
    ");
    
    if (!$stmt) {
        $debug['database_query']['error'] = $conn->error;
        return $debug;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $debug['database_query']['rows_found'] = $result->num_rows;
    
    if ($result->num_rows === 0) {
        $debug['database_query']['error'] = "No user found with this email";
        return $debug;
    }
    
    // 3. User Details and Password Verification
    $user = $result->fetch_assoc();
    
    $debug['password_verification']['stored_hash_length'] = strlen($user['password']);
    $debug['password_verification']['password_verify'] = password_verify($password, $user['password']);
    
    // 4. Role Assignment Debugging
    if ($debug['password_verification']['password_verify']) {
        try {
            $userId = $user['id'];
            $defaultRole = $user['role'];
            
            ensureRoleInfrastructure($conn);
            ensureUserRoleAssignment($conn, $userId, $defaultRole);
            ensureRoleBundleAssignments($conn, $userId, $defaultRole);
            
            $assignments = refreshUserSessionRoles($conn, $userId, $defaultRole);
            
            $debug['role_assignment']['assignments'] = $assignments;
            $debug['role_assignment']['default_role'] = $defaultRole;
        } catch (Exception $e) {
            $debug['role_assignment']['error'] = $e->getMessage();
        }
    }
    
    return $debug;
}

// HTML Interface for Debugging
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login Debugging Tool</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        pre { background-color: #f4f4f4; padding: 15px; border-radius: 5px; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
    <h1>Login Debugging Tool</h1>
    <form method="POST">
        <label>Email: <input type="email" name="email" required></label><br>
        <label>Password: <input type="password" name="password" required></label><br>
        <button type="submit" name="debug">Run Diagnostic</button>
    </form>

    <?php
    if (isset($_POST['debug'])) {
        $debugResults = debugLogin($_POST['email'], $_POST['password']);
        
        echo "<h2>Debugging Results</h2>";
        echo "<pre>" . htmlspecialchars(print_r($debugResults, true)) . "</pre>";
        
        // Detailed Analysis
        echo "<h3>Analysis</h3>";
        
        // Input Validation
        echo "<h4>Input Validation</h4>";
        echo "Email Length: " . $debugResults['input_validation']['email_length'] . "<br>";
        echo "Email Format: " . $debugResults['input_validation']['email_format'] . "<br>";
        
        // Database Query
        echo "<h4>Database Query</h4>";
        echo "Users Found: " . $debugResults['database_query']['rows_found'] . "<br>";
        if (isset($debugResults['database_query']['error'])) {
            echo "<span class='error'>Error: " . $debugResults['database_query']['error'] . "</span><br>";
        }
        
        // Password Verification
        echo "<h4>Password Verification</h4>";
        echo "Stored Hash Length: " . $debugResults['password_verification']['stored_hash_length'] . "<br>";
        echo "Password Match: " . 
            ($debugResults['password_verification']['password_verify'] ? 
            "<span class='success'>✓ Verified</span>" : 
            "<span class='error'>✗ Not Verified</span>") . "<br>";
        
        // Role Assignment
        if (isset($debugResults['role_assignment']['assignments'])) {
            echo "<h4>Role Assignments</h4>";
            echo "Default Role: " . $debugResults['role_assignment']['default_role'] . "<br>";
            echo "Assigned Roles: " . count($debugResults['role_assignment']['assignments']) . "<br>";
        }
    }
    ?>
</body>
</html>