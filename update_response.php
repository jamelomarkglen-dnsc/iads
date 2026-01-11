<?php
require_once 'db.php';
require_once 'notifications_helper.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'panel') {
    exit('Access denied');
}

$panel_id = intval($_POST['panel_id'] ?? 0);
$response = $_POST['response'] ?? '';

if (!$panel_id || !in_array($response, ['Accepted', 'Declined'], true)) {
    exit('Invalid request');
}

$stmt = $conn->prepare("UPDATE defense_panels SET response = ? WHERE id = ?");
$stmt->bind_param("si", $response, $panel_id);

if ($stmt->execute()) {
    $infoQuery = "
        SELECT ds.student_id, ds.defense_date, ds.defense_time, ds.venue,
               CONCAT(stu.firstname, ' ', stu.lastname) AS student_name
        FROM defense_panels dp
        JOIN defense_schedules ds ON dp.defense_id = ds.id
        JOIN users stu ON ds.student_id = stu.id
        WHERE dp.id = ?
        LIMIT 1
    ";
    $infoStmt = $conn->prepare($infoQuery);
    $infoStmt->bind_param("i", $panel_id);
    $infoStmt->execute();
    $scheduleInfo = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();

    $panelNameStmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
    $panelNameStmt->bind_param("i", $_SESSION['user_id']);
    $panelNameStmt->execute();
    $panelData = $panelNameStmt->get_result()->fetch_assoc();
    $panelNameStmt->close();

    $panelName = trim(($panelData['firstname'] ?? '') . ' ' . ($panelData['lastname'] ?? ''));
    if ($panelName === '') {
        $panelName = 'A panel member';
    }

    if ($scheduleInfo) {
        $studentId = (int)($scheduleInfo['student_id'] ?? 0);
        $studentName = trim($scheduleInfo['student_name'] ?? 'the student');
        $formattedDate = !empty($scheduleInfo['defense_date']) ? date('F d, Y', strtotime($scheduleInfo['defense_date'])) : 'the scheduled date';
        $formattedTime = !empty($scheduleInfo['defense_time']) ? date('h:i A', strtotime($scheduleInfo['defense_time'])) : 'the scheduled time';
        $venue = $scheduleInfo['venue'] ?? 'the assigned venue';
        $responseText = strtolower($response);

        if ($studentId) {
            notify_user(
                $conn,
                $studentId,
                'Panel response update',
                "{$panelName} has {$responseText} your defense invitation for {$formattedDate} at {$formattedTime} in {$venue}.",
                'schedule_defense.php'
            );
        }

        $message = "{$panelName} has {$responseText} the defense invitation for {$studentName} on {$formattedDate} at {$formattedTime}.";
        notify_roles(
            $conn,
            ['program_chairperson', 'committee_chairperson', 'committee_chair'],
            'Panel response recorded',
            $message,
            'assign_panel.php'
        );
    }

    echo 'success';
} else {
    echo 'error';
}

$stmt->close();
