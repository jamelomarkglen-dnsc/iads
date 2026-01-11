<?php
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications_helper.php';

$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

if ($userId === null && ($role === null || $role === '')) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 25)) : 10;
        $notifications = fetch_user_notifications($conn, $userId, $role, $limit);
        $unread = count_unread_notifications($conn, $userId, $role);
        echo json_encode([
            'notifications' => $notifications,
            'unread' => $unread,
        ]);
        break;

    case 'markRead':
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid notification id']);
            break;
        }
        $ok = mark_notification_read($conn, $id, $userId, $role);
        echo json_encode(['success' => $ok]);
        break;

    case 'markAllRead':
        $ok = mark_all_notifications_read($conn, $userId, $role);
        echo json_encode(['success' => $ok]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
