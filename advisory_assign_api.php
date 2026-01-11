<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'adviser') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$adviserId = (int)$_SESSION['user_id'];

function safe_json_response(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function columnExists(mysqli $conn, string $column): bool
{
    $column = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = '{$column}'
        LIMIT 1
    ";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'search';
    if ($action !== 'search') {
        safe_json_response(['success' => false, 'error' => 'Invalid action']);
    }

    $query = trim($_GET['query'] ?? '');

    $baseSql = "
        SELECT id, firstname, lastname, email
        FROM users
        WHERE role = 'student'
          AND (adviser_id IS NULL OR adviser_id = 0)
          AND (advisor_id IS NULL OR advisor_id = 0)
    ";

    $conditions = [];
    $params = [];
    $types = '';

    if ($query !== '') {
        $conditions[] = "(CONCAT(firstname, ' ', lastname) LIKE ? OR email LIKE ?)";
        $like = '%' . $query . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    if ($conditions) {
        $baseSql .= ' AND ' . implode(' AND ', $conditions);
    }
    $baseSql .= ' ORDER BY firstname, lastname LIMIT 25';

    $stmt = $conn->prepare($baseSql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $formatted = array_map(static function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => trim($row['firstname'] . ' ' . $row['lastname']),
            'email' => $row['email'],
        ];
    }, $rows);

    safe_json_response(['success' => true, 'results' => $formatted]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    if ($studentId <= 0) {
        safe_json_response(['success' => false, 'error' => 'Invalid student identifier']);
    }

    // Determine which adviser columns exist
    $advisorColumns = [];
    if (columnExists($conn, 'adviser_id')) {
        $advisorColumns[] = 'adviser_id';
    }
    if (columnExists($conn, 'advisor_id')) {
        $advisorColumns[] = 'advisor_id';
    }
    if (empty($advisorColumns)) {
        safe_json_response(['success' => false, 'error' => 'System configuration error']);
    }

    // Build dynamic SELECT query
    $selectColumns = 'id, firstname, lastname, email, ' . implode(', ', $advisorColumns);
    $checkStmt = $conn->prepare("
        SELECT {$selectColumns}
        FROM users
        WHERE id = ? AND role = 'student'
        LIMIT 1
    ");
    $checkStmt->bind_param("i", $studentId);
    $checkStmt->execute();
    $studentRow = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$studentRow) {
        safe_json_response(['success' => false, 'error' => 'Student record not found']);
    }
    
    // Check if student is already assigned
    foreach ($advisorColumns as $col) {
        if (!empty($studentRow[$col])) {
            safe_json_response(['success' => false, 'error' => 'Student is already assigned to an adviser']);
        }
    }

    // Build dynamic UPDATE query
    $updates = [];
    $bindTypes = '';
    $bindValues = [];
    
    foreach ($advisorColumns as $col) {
        $updates[] = "{$col} = ?";
        $bindTypes .= 'i';
        $bindValues[] = $adviserId;
    }
    
    $bindTypes .= 'i';
    $bindValues[] = $studentId;
    
    $updateSql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
    $assignStmt = $conn->prepare($updateSql);
    
    if (!$assignStmt) {
        safe_json_response(['success' => false, 'error' => 'Failed to prepare assignment']);
    }
    
    $bindParams = [$bindTypes];
    foreach ($bindValues as $key => $value) {
        $bindParams[] = &$bindValues[$key];
    }
    call_user_func_array([$assignStmt, 'bind_param'], $bindParams);
    
    $ok = $assignStmt->execute();
    $assignStmt->close();

    if (!$ok) {
        safe_json_response(['success' => false, 'error' => 'Failed to assign advisee']);
    }

    $studentName = trim(($studentRow['firstname'] ?? '') . ' ' . ($studentRow['lastname'] ?? ''));
    if ($studentName === '') {
        $studentName = 'Student';
    }

    notify_user(
        $conn,
        $studentId,
        'Adviser assigned',
        'You have been added to an adviser in the DNSC IAdS system. Open your dashboard to start collaborating.',
        'student_dashboard.php'
    );

    notify_user(
        $conn,
        $adviserId,
        'New advisee added',
        'You have successfully added ' . $studentName . ' to your advisory list.',
        'adviser_directory.php',
        false
    );

    safe_json_response(['success' => true]);
}

safe_json_response(['success' => false, 'error' => 'Unsupported request']);
