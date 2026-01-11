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

    $checkStmt = $conn->prepare("
        SELECT id, firstname, lastname, email, adviser_id, advisor_id
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
    if (!empty($studentRow['adviser_id']) || !empty($studentRow['advisor_id'])) {
        safe_json_response(['success' => false, 'error' => 'Student is already assigned to an adviser']);
    }

    $assignStmt = $conn->prepare("UPDATE users SET adviser_id = ?, advisor_id = ? WHERE id = ?");
    $assignStmt->bind_param("iii", $adviserId, $adviserId, $studentId);
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

    safe_json_response(['success' => true]);
}

safe_json_response(['success' => false, 'error' => 'Unsupported request']);
