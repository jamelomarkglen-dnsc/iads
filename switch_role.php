<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

ensureRoleInfrastructure($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';
$requestedRole = trim((string)($_POST['role'] ?? ''));
$seedRole = $currentRole !== '' ? $currentRole : $requestedRole;
if ($seedRole !== '') {
    ensureRoleBundleAssignments($conn, $userId, $seedRole);
}
$token = $_POST['role_switch_token'] ?? '';
$originInput = trim((string)($_POST['origin'] ?? ''));
$storedToken = $_SESSION['role_switch_token'] ?? '';
unset($_SESSION['role_switch_token']);

$redirectOnError = 'login.php';
if ($originInput !== '' && preg_match('/^[A-Za-z0-9_.-]+$/', $originInput)) {
    $redirectOnError = $originInput;
} elseif ($currentRole !== '') {
    $redirectOnError = getRoleDashboard($currentRole);
}

if ($token === '' || $storedToken === '' || !hash_equals($storedToken, $token)) {
    $_SESSION['role_switch_error'] = 'Role switch request expired. Please try again.';
    header("Location: {$redirectOnError}");
    exit;
}

$assignments = refreshUserSessionRoles($conn, $userId, $currentRole);
if ($requestedRole === '' || !sessionRolesContain($assignments, $requestedRole)) {
    $_SESSION['role_switch_error'] = 'You are not assigned to that role.';
    header("Location: {$redirectOnError}");
    exit;
}

if (!roleIsSwitchable($requestedRole) && $requestedRole !== $currentRole) {
    $_SESSION['role_switch_error'] = 'That role is locked and cannot be selected.';
    header("Location: {$redirectOnError}");
    exit;
}

if ($requestedRole !== $currentRole) {
    logRoleSwitch($conn, $userId, $currentRole, $requestedRole);
    setActiveRole($requestedRole);
    setUserPrimaryRole($conn, $userId, $requestedRole);
    session_regenerate_id(true);
}

$_SESSION['role_switch_success'] = 'You are now viewing the ' . getRoleLabel($requestedRole) . ' dashboard.';
$target = getRoleDashboard($requestedRole);
header("Location: {$target}");
exit;
