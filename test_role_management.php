<?php
require_once 'db.php';
require_once 'role_helpers.php';

// Establish database connection
$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Test getPermittedAssignmentRoles function
function testGetPermittedAssignmentRoles() {
    $testCases = [
        'faculty' => ['faculty', 'panel', 'adviser', 'reviewer'],
        'reviewer' => ['faculty', 'reviewer'],
        'committee_chairperson' => ['committee_chair', 'committee_chairperson'],
        'adviser' => ['faculty', 'adviser'],
        'panel' => ['faculty', 'panel'],
        'unknown_role' => ['unknown_role']
    ];

    foreach ($testCases as $role => $expectedRoles) {
        $result = getPermittedAssignmentRoles($role);
        echo "Testing getPermittedAssignmentRoles for '$role':\n";
        echo "Expected: " . implode(', ', $expectedRoles) . "\n";
        echo "Actual: " . implode(', ', $result) . "\n";
        
        $diff = array_diff($result, $expectedRoles);
        if (!empty($diff)) {
            echo "❌ FAIL: Unexpected roles found: " . implode(', ', $diff) . "\n";
        } else {
            echo "✅ PASS\n";
        }
        echo "\n";
    }
}

// Test getAutoAssignableRoles function
function testGetAutoAssignableRoles() {
    $testCases = [
        'reviewer' => ['adviser', 'panel', 'committee_chair', 'reviewer', 'faculty'],
        'faculty' => ['adviser', 'panel', 'committee_chair', 'reviewer', 'faculty'],
        'committee_chairperson' => ['adviser', 'panel', 'committee_chair', 'reviewer', 'faculty', 'committee_chairperson'],
        'program_chairperson' => ['adviser', 'panel', 'committee_chair', 'reviewer', 'faculty', 'program_chairperson']
    ];

    foreach ($testCases as $role => $expectedRoles) {
        $result = getAutoAssignableRoles($role);
        echo "Testing getAutoAssignableRoles for '$role':\n";
        echo "Expected: " . implode(', ', $expectedRoles) . "\n";
        echo "Actual: " . implode(', ', $result) . "\n";
        
        $diff = array_diff($result, $expectedRoles);
        if (!empty($diff)) {
            echo "❌ FAIL: Unexpected roles found: " . implode(', ', $diff) . "\n";
        } else {
            echo "✅ PASS\n";
        }
        echo "\n";
    }
}

// Test validateRoleAssignment function
function testValidateRoleAssignment($conn) {
    // Simulate a user ID (replace with an actual user ID from your database)
    $userId = 1;

    $testCases = [
        ['currentRole' => 'faculty', 'newRole' => 'reviewer', 'expected' => true],
        ['currentRole' => 'faculty', 'newRole' => 'panel', 'expected' => true],
        ['currentRole' => 'reviewer', 'newRole' => 'faculty', 'expected' => true],
        ['currentRole' => 'student', 'newRole' => 'faculty', 'expected' => false],
        ['currentRole' => 'dean', 'newRole' => 'faculty', 'expected' => false]
    ];

    foreach ($testCases as $test) {
        $result = validateRoleAssignment($conn, $userId, $test['currentRole'], $test['newRole']);
        echo "Testing validateRoleAssignment from '{$test['currentRole']}' to '{$test['newRole']}':\n";
        echo "Expected: " . ($test['expected'] ? 'true' : 'false') . "\n";
        echo "Actual: " . ($result ? 'true' : 'false') . "\n";
        
        if ($result === $test['expected']) {
            echo "✅ PASS\n";
        } else {
            echo "❌ FAIL\n";
        }
        echo "\n";
    }
}

// Run tests
echo "Starting Role Management Tests\n";
echo "===========================\n\n";

testGetPermittedAssignmentRoles();
testGetAutoAssignableRoles();
testValidateRoleAssignment($conn);

$conn->close();
?>