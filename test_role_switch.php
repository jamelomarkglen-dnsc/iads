<?php
session_start();
include 'db.php';
require_once 'role_helpers.php';

// Ensure user is logged in and has multiple roles
if (!isset($_SESSION['user_id'])) {
    die("Please log in first.");
}

$userId = (int)$_SESSION['user_id'];

// Fetch user's available roles
$assignments = fetchUserRoleAssignments($conn, $userId);

// Display available switchable roles
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>Role Switching Test</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='container py-5'>
    <div class='card'>
        <div class='card-header'>
            <h1 class='h4'>Role Switching Test</h1>
        </div>
        <div class='card-body'>
            <h2>Available Roles</h2>
            <form method='POST' action='switch_role.php'>
                <input type='hidden' name='role_switch_token' value='" . getRoleSwitchToken() . "'>
                <div class='list-group'>";

foreach ($assignments as $role) {
    if ($role['switchable'] || $role['code'] === $_SESSION['role']) {
        echo "<button type='submit' name='role' value='" . htmlspecialchars($role['code']) . "' 
                class='list-group-item list-group-item-action " . 
                ($_SESSION['role'] === $role['code'] ? 'active' : '') . "'>
                " . htmlspecialchars($role['label']) . " 
                " . ($_SESSION['role'] === $role['code'] ? "(Current Role)" : "") . "
              </button>";
    }
}

echo "</div>
            </form>
        </div>
        <div class='card-footer'>
            <p>Current Session Role: " . htmlspecialchars($_SESSION['role'] ?? 'Not set') . "</p>
        </div>
    </div>
</body>
</html>";
?>