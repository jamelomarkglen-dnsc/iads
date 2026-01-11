<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

header('Content-Type: application/json');

// Only Dean or Program Chair can add events
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['program_chairperson', 'dean'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$title = $_POST['title'] ?? '';
$description = $_POST['description'] ?? '';
$start = $_POST['start'] ?? '';
$end = $_POST['end'] ?? '';
$startTime = $_POST['start_time'] ?? '';
$endTime = $_POST['end_time'] ?? '';
$allDay = isset($_POST['allDay']) ? 1 : 0;
$category = $_POST['category'] ?? 'General';
$created_by = $_SESSION['user_id'] ?? null;

if ($title && $start && $end) {
    if ($allDay) {
        $startDateTime = date('Y-m-d 00:00:00', strtotime($start));
        $endDateTime = date('Y-m-d 23:59:59', strtotime($end));
    } else {
        if ($startTime === '' || $endTime === '') {
            echo json_encode(['success' => false, 'error' => 'Please provide start and end time for timed events.']);
            exit;
        }
        $startDateTime = date('Y-m-d H:i:s', strtotime("{$start} {$startTime}"));
        $endDateTime = date('Y-m-d H:i:s', strtotime("{$end} {$endTime}"));
        if ($startDateTime >= $endDateTime) {
            echo json_encode(['success' => false, 'error' => 'End time must be after start time.']);
            exit;
        }
    }

    $stmt = $conn->prepare("INSERT INTO events (title, description, start_date, end_date, all_day, category, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssisi", $title, $description, $startDateTime, $endDateTime, $allDay, $category, $created_by);
    
    if ($stmt->execute()) {
        $eventId = $stmt->insert_id;

        $rolesToNotify = ['student', 'program_chairperson', 'faculty', 'adviser', 'panel', 'committee_chairperson', 'committee_chair', 'dean'];
        notify_roles(
            $conn,
            $rolesToNotify,
            "New event added: {$title}",
            $description !== '' ? $description : 'A new calendar event has been scheduled.',
            'calendar.php'
        );

        echo json_encode(['success' => true, 'id' => $eventId]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
}
