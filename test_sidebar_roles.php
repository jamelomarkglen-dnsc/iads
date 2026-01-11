<?php
// Ensure no active session interferes with testing
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
session_start();

require_once 'role_helpers.php';

// Simulate different user role scenarios
function testSidebarRoles() {
    $testRoles = getRoleDefinitions();
    $results = [];

    foreach ($testRoles as $roleCode => $roleData) {
        // Simulate session setup for each role
        $_SESSION['available_roles'] = [
            [
                'code' => $roleCode,
                'label' => $roleData['label'],
                'dashboard' => $roleData['dashboard'],
                'switchable' => $roleData['switchable']
            ]
        ];
        $_SESSION['active_role'] = $roleCode;
        $_SESSION['user_name'] = 'Test User';
        $_SESSION['user_avatar'] = 'default_avatar.png';

        // Capture sidebar output
        ob_start();
        include 'sidebar.php';
        $sidebarContent = ob_get_clean();

        // Basic validation checks
        $results[$roleCode] = [
            'role' => $roleCode,
            'dashboard_link_present' => strpos($sidebarContent, $roleData['dashboard']) !== false,
            'role_label_present' => strpos($sidebarContent, $roleData['label']) !== false,
            'switchable' => $roleData['switchable'],
            'menu_items_count' => substr_count($sidebarContent, 'sidebar-menu-item')
        ];
    }

    return $results;
}

// Run tests
$testResults = testSidebarRoles();

// Display results
echo "<!DOCTYPE html><html><head><title>Sidebar Role Test</title>";
echo "<style>
    body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .pass { color: green; }
    .fail { color: red; }
</style>";
echo "</head><body>";
echo "<h1>Sidebar Role Functionality Test</h1>";
echo "<table>";
echo "<tr><th>Role</th><th>Dashboard Link</th><th>Role Label</th><th>Switchable</th><th>Menu Items</th></tr>";

foreach ($testResults as $roleCode => $result) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($roleCode) . "</td>";
    echo "<td class='" . ($result['dashboard_link_present'] ? 'pass' : 'fail') . "'>" . 
         ($result['dashboard_link_present'] ? '✓' : '✗') . "</td>";
    echo "<td class='" . ($result['role_label_present'] ? 'pass' : 'fail') . "'>" . 
         ($result['role_label_present'] ? '✓' : '✗') . "</td>";
    echo "<td class='" . ($result['switchable'] ? 'pass' : 'fail') . "'>" . 
         ($result['switchable'] ? '✓' : '✗') . "</td>";
    echo "<td>" . htmlspecialchars($result['menu_items_count']) . "</td>";
    echo "</tr>";
}

echo "</table>";

// Detailed logging
$logContent = print_r($testResults, true);
file_put_contents('sidebar_role_test_log.txt', $logContent);

echo "<h2>Test Log</h2>";
echo "<pre>" . htmlspecialchars($logContent) . "</pre>";
echo "</body></html>";
?>