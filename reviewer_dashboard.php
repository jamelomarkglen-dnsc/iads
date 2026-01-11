<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';

$allowedRoles = ['faculty', 'panel', 'committee_chair', 'committee_chairperson', 'adviser'];
$sessionRole = $_SESSION['role'] ?? '';
$role = isset($forceAssignmentRole) && in_array($forceAssignmentRole, $allowedRoles, true)
    ? $forceAssignmentRole
    : $sessionRole;
$reviewerId = (int)($_SESSION['user_id'] ?? 0);

if (!$reviewerId || !in_array($role, $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureConceptReviewTables($conn);
ensureConceptReviewMessagesTable($conn);
$assignmentRoleKey = $role === 'committee_chairperson' ? 'committee_chair' : $role;
if ($role === 'adviser') {
    syncAdviserAssignmentsFromUserLinks($conn, $reviewerId);
}
$isAdviserView = ($role === 'adviser');
$permittedAssignmentRoles = getPermittedAssignmentRoles($role);

// Rest of the code remains the same as in the previous submission