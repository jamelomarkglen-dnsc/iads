<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

header('Content-Type: application/json');

$allowedRoles = ['adviser', 'student'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

function advisory_bootstrap(mysqli $conn): void
{
    static $initialised = false;
    if ($initialised) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS advisory_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            adviser_id INT NOT NULL,
            student_id INT NOT NULL,
            sender_id INT NOT NULL,
            sender_role ENUM('adviser','student') NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_adviser_student (adviser_id, student_id),
            INDEX idx_created_at (created_at),
            CONSTRAINT fk_advisory_adviser FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_advisory_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $initialised = true;
}

advisory_bootstrap($conn);

$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];

$studentId = null;
$adviserId = null;

if ($role === 'adviser') {
    $adviserId = $userId;
    $studentId = isset($_REQUEST['student_id']) ? (int)$_REQUEST['student_id'] : 0;
    if ($studentId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Missing student identifier']);
        exit;
    }
    $checkStmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'student' AND (adviser_id = ? OR advisor_id = ?) LIMIT 1");
    $checkStmt->bind_param("iii", $studentId, $adviserId, $adviserId);
    $checkStmt->execute();
    $isLinked = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    if (!$isLinked) {
        echo json_encode(['success' => false, 'error' => 'Student is not linked to this adviser']);
        exit;
    }
} else {
    $studentId = $userId;
    $advisorQuery = $conn->prepare("SELECT COALESCE(adviser_id, advisor_id) AS adviser_id FROM users WHERE id = ? LIMIT 1");
    $advisorQuery->bind_param("i", $studentId);
    $advisorQuery->execute();
    $advisorData = $advisorQuery->get_result()->fetch_assoc();
    $advisorQuery->close();
    if (empty($advisorData['adviser_id'])) {
        echo json_encode(['success' => false, 'error' => 'No adviser assigned']);
        exit;
    }
    $adviserId = (int)$advisorData['adviser_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
        exit;
    }

    $insertStmt = $conn->prepare("
        INSERT INTO advisory_messages (adviser_id, student_id, sender_id, sender_role, message)
        VALUES (?, ?, ?, ?, ?)
    ");
    $senderRole = $role;
    $insertStmt->bind_param("iiiss", $adviserId, $studentId, $userId, $senderRole, $message);
    $success = $insertStmt->execute();
    $insertStmt->close();

    if (!$success) {
        echo json_encode(['success' => false, 'error' => 'Failed to send message']);
        exit;
    }

    $counterpartRole = $role === 'adviser' ? 'student' : 'adviser';
    $counterpartId = $counterpartRole === 'student' ? $studentId : $adviserId;

    $snippet = mb_strlen($message) > 120 ? mb_substr($message, 0, 117) . '...' : $message;
    $link = $counterpartRole === 'student' ? 'student_dashboard.php' : 'advisory.php';
    notify_user_for_role(
        $conn,
        $counterpartId,
        $counterpartRole,
        'New advisory message',
        $snippet,
        $link
    );

    echo json_encode(['success' => true]);
    exit;
}

$limit = isset($_GET['limit']) ? max(10, min(200, (int)$_GET['limit'])) : 100;

$messagesStmt = $conn->prepare("
    SELECT id, sender_id, sender_role, message, created_at
    FROM advisory_messages
    WHERE adviser_id = ? AND student_id = ?
    ORDER BY created_at ASC
    LIMIT ?
");
$messagesStmt->bind_param("iii", $adviserId, $studentId, $limit);
$messagesStmt->execute();
$result = $messagesStmt->get_result();
$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => (int)$row['id'],
        'sender_id' => (int)$row['sender_id'],
        'sender_role' => $row['sender_role'],
        'message' => nl2br(htmlspecialchars($row['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        'created_at' => $row['created_at'],
    ];
}
$messagesStmt->close();

echo json_encode(['success' => true, 'messages' => $messages]);

