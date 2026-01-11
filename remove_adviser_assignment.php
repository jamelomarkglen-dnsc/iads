<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

// Find the student
$result = $conn->query("SELECT u.id, u.firstname, u.lastname, u.email, u.adviser_id, u.advisor_id FROM users WHERE firstname='Markglen' AND lastname='Jamelo' LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    $studentId = (int)$row['id'];
    $adviserIdOld = (int)($row['adviser_id'] ?? 0);
    
    echo "Found student: " . $row['firstname'] . " " . $row['lastname'] . " (ID: " . $studentId . ")<br>";
    echo "Current adviser_id: " . ($row['adviser_id'] ?? 'NULL') . "<br>";
    echo "Current advisor_id: " . ($row['advisor_id'] ?? 'NULL') . "<br>";
    
    // Remove adviser assignment
    $updateStmt = $conn->prepare("UPDATE users SET adviser_id = NULL, advisor_id = NULL WHERE id = ?");
    if ($updateStmt) {
        $updateStmt->bind_param('i', $studentId);
        if ($updateStmt->execute()) {
            echo "Adviser assignment removed successfully!<br>";
            
            // Delete related notifications
            $deleteStmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND title LIKE '%Adviser assigned%'");
            if ($deleteStmt) {
                $deleteStmt->bind_param('i', $studentId);
                if ($deleteStmt->execute()) {
                    echo "Notifications deleted: " . $deleteStmt->affected_rows . " record(s)<br>";
                }
                $deleteStmt->close();
            }
        } else {
            echo "Error updating student: " . $updateStmt->error . "<br>";
        }
        $updateStmt->close();
    }
} else {
    echo "Student not found<br>";
}

$conn->close();
?>
