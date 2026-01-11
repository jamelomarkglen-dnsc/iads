<?php
require_once 'db.php';
require_once 'notifications_helper.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'panel') {
    exit('Access denied');
}

$panel_id = $_SESSION['user_id'];
$defense_id = intval($_POST['defense_id']);
$remarks = trim($_POST['remarks']);

if (!$defense_id || empty($remarks)) {
    exit('Invalid input');
}

// Create remarks table if not existing
$conn->query("
  CREATE TABLE IF NOT EXISTS defense_remarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    defense_id INT NOT NULL,
    panel_user_id INT NOT NULL,
    remarks TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  )
");

$stmt = $conn->prepare("INSERT INTO defense_remarks (defense_id, panel_user_id, remarks) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $defense_id, $panel_id, $remarks);
$stmt->execute();
$stmt->close();


$infoStmt = $conn->prepare("SELECT student_id, defense_date, defense_time, venue FROM defense_schedules WHERE id = ? LIMIT 1");
$infoStmt->bind_param("i", $defense_id);
$infoStmt->execute();
$defenseInfo = $infoStmt->get_result()->fetch_assoc();
$infoStmt->close();

$panelNameStmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
$panelNameStmt->bind_param("i", $panel_id);
$panelNameStmt->execute();
$panelData = $panelNameStmt->get_result()->fetch_assoc();
$panelNameStmt->close();

$panelName = trim(($panelData['firstname'] ?? '') . ' ' . ($panelData['lastname'] ?? ''));
if ($panelName === '') {
    $panelName = 'A panel member';
}

if ($defenseInfo) {
    $studentId = (int)($defenseInfo['student_id'] ?? 0);
    $formattedDate = !empty($defenseInfo['defense_date']) ? date('F d, Y', strtotime($defenseInfo['defense_date'])) : 'the scheduled date';
    $formattedTime = !empty($defenseInfo['defense_time']) ? date('h:i A', strtotime($defenseInfo['defense_time'])) : 'the scheduled time';
    $venue = $defenseInfo['venue'] ?? 'the assigned venue';

    if ($studentId) {
        notify_user(
            $conn,
            $studentId,
            'Defense remarks added',
            "{$panelName} shared new remarks for your defense scheduled on {$formattedDate} at {$formattedTime} in {$venue}.",
            'schedule_defense.php'
        );
    }

    notify_roles(
        $conn,
        ['program_chairperson', 'committee_chairperson', 'committee_chair'],
        'Defense remarks recorded',
        "{$panelName} submitted new remarks for defense ID {$defense_id}.",
        'assign_panel.php'
    );
}
echo "success";
