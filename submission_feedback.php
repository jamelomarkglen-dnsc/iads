<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'submission_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: submissions.php');
    exit;
}

$submissionId = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
$message = trim($_POST['feedback_message'] ?? '');
$chairId = (int)($_SESSION['user_id'] ?? 0);

if ($submissionId <= 0 || $message === '') {
    $_SESSION['error'] = 'Please select a submission and provide feedback before sending.';
    header('Location: submissions.php');
    exit;
}

$limitedMessage = function_exists('mb_substr') ? mb_substr($message, 0, 2000, 'UTF-8') : substr($message, 0, 2000);
$result = create_submission_feedback($conn, $submissionId, $chairId, $limitedMessage);

if (!$result['success']) {
    $_SESSION['error'] = $result['error'] ?? 'Unable to send feedback right now.';
    header('Location: submissions.php');
    exit;
}

$submission = $result['submission'] ?? [];
$studentId = isset($submission['student_id']) ? (int)$submission['student_id'] : 0;
$submissionTitle = trim((string)($submission['title'] ?? 'Concept Submission'));

$_SESSION['success'] = 'Feedback sent to the student.';

if ($studentId > 0) {
    $snippet = function_exists('mb_substr') ? mb_substr($limitedMessage, 0, 140, 'UTF-8') : substr($limitedMessage, 0, 140);
    notify_user(
        $conn,
        $studentId,
        'New feedback from Program Chair',
        "Feedback on \"{$submissionTitle}\": {$snippet}",
        'student_dashboard.php'
    );
}

header('Location: submissions.php');
exit;
