<?php
require_once 'db.php';
require_once 'role_helpers.php';

// Establish database connection
$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Comprehensive Role Validation Test Suite
class RoleValidationTest {
    private $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    // Test role assignment permissions
    public function testRoleAssignmentPermissions() {
        echo "=== Role Assignment Permissions Test ===\n";
        
        $testCases = [
            // Base role, target role, expected result
            ['faculty', 'reviewer', true],
            ['faculty', 'panel', true],
            ['faculty', 'adviser', true],
            ['student', 'faculty', false],
            ['dean', 'faculty', false],
            ['reviewer', 'faculty', true],
            ['committee_chairperson', 'committee_chair', true],
            ['program_chairperson', 'faculty', true]
        ];

        $successCount = 0;
        $totalTests = count($testCases);

        foreach ($testCases as $case) {
            $baseRole = $case[0];
            $targetRole = $case[1];
            $expectedResult = $case[2];

            // Simulate a user ID (use a valid user ID from your database)
            $userId = $this->findUserWithRole($baseRole);
            
            if ($userId === null) {
                echo "❌ No user found with role: $baseRole\n";
                continue;
            }

            $result = validateRoleAssignment($this->conn, $userId, $baseRole, $targetRole);
            
            echo "Testing role switch from '$baseRole' to '$targetRole': ";
            
            if ($result === $expectedResult) {
                echo "✅ PASS\n";
                $successCount++;
            } else {
                echo "❌ FAIL (Expected: " . 
                    ($expectedResult ? 'true' : 'false') . 
                    ", Got: " . ($result ? 'true' : 'false') . ")\n";
            }
        }

        echo "\nTest Summary:\n";
        echo "Total Tests: $totalTests\n";
        echo "Passed Tests: $successCount\n";
        echo "Failed Tests: " . ($totalTests - $successCount) . "\n";
    }

    // Test role bundle assignments
    public function testRoleBundleAssignments() {
        echo "\n=== Role Bundle Assignments Test ===\n";
        
        $testCases = [
            'faculty' => ['adviser', 'panel', 'committee_chair', 'reviewer', 'faculty'],
            'reviewer' => ['faculty', 'reviewer'],
            'committee_chairperson' => ['committee_chair', 'committee_chairperson', 'faculty'],
            'program_chairperson' => ['program_chairperson', 'faculty']
        ];

        foreach ($testCases as $baseRole => $expectedRoles) {
            $bundleRoles = getAutoAssignableRoles($baseRole);
            
            echo "Testing bundle for '$baseRole':\n";
            echo "Expected Roles: " . implode(', ', $expectedRoles) . "\n";
            echo "Actual Roles: " . implode(', ', $bundleRoles) . "\n";

            $missingRoles = array_diff($expectedRoles, $bundleRoles);
            $extraRoles = array_diff($bundleRoles, $expectedRoles);

            if (empty($missingRoles) && empty($extraRoles)) {
                echo "✅ PASS\n";
            } else {
                echo "❌ FAIL\n";
                if (!empty($missingRoles)) {
                    echo "Missing Roles: " . implode(', ', $missingRoles) . "\n";
                }
                if (!empty($extraRoles)) {
                    echo "Extra Roles: " . implode(', ', $extraRoles) . "\n";
                }
            }
            echo "\n";
        }
    }

    // Helper method to find a user with a specific role
    private function findUserWithRole(string $role): ?int {
        $stmt = $this->conn->prepare("
            SELECT id FROM users 
            WHERE role = ? 
            LIMIT 1
        ");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0 ? $result->fetch_assoc()['id'] : null;
    }

    // Run all tests
    public function runAllTests() {
        $this->testRoleAssignmentPermissions();
        $this->testRoleBundleAssignments();
    }
}

// Execute tests
$roleTest = new RoleValidationTest($conn);
$roleTest->runAllTests();

$conn->close();
?>