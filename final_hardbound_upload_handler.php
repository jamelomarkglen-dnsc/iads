<?php
/**
 * Final Hardbound Upload Handler
 */

session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';
require_once 'final_routing_submission_helpers.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

ensureFinalHardboundTables($conn);

$student_id = (int)$_SESSION['user_id'];
$routing_submission = fetch_latest_passed_final_routing($conn, $student_id);
if (!$routing_submission) {
    $_SESSION['final_hardbound_upload_error'] = 'Final routing must be marked as Passed before uploading the hardbound copy.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$latest = fetch_latest_final_hardbound_submission($conn, $student_id);
if ($latest && !in_array(($latest['status'] ?? ''), ['Rejected', 'Needs Revision'], true)) {
    $_SESSION['final_hardbound_upload_error'] = 'A hardbound submission is already in progress.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

if (!isset($_FILES['pdf_file'])) {
    $_SESSION['final_hardbound_upload_error'] = 'No file uploaded.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$upload_result = upload_final_hardbound_file($_FILES['pdf_file'], $student_id);
if (!$upload_result['success']) {
    $_SESSION['final_hardbound_upload_error'] = implode(' ', $upload_result['errors']);
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$submission_id = fetch_latest_submission_id_for_student_hardbound($conn, $student_id);
if ($submission_id <= 0) {
    @unlink($upload_result['file_path']);
    $_SESSION['final_hardbound_upload_error'] = 'No submission record found for this student.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$create = create_final_hardbound_submission(
    $conn,
    $submission_id,
    $student_id,
    (int)($routing_submission['id'] ?? 0),
    $upload_result['file_path'],
    $upload_result['original_filename'],
    $upload_result['file_size'],
    $upload_result['mime_type']
);
if (!$create['success']) {
    @unlink($upload_result['file_path']);
    $_SESSION['final_hardbound_upload_error'] = $create['error'] ?? 'Unable to save the hardbound submission.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

// Notify adviser
$committee = fetch_latest_approved_committee_for_student($conn, $student_id);
$adviser_id = (int)($committee['adviser_id'] ?? 0);
if ($adviser_id > 0) {
    $studentName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'A student';
    notify_user(
        $conn,
        $adviser_id,
        'Final hardbound submission uploaded',
        "{$studentName} uploaded the final hardbound PDF. Please review and forward the endorsement to the committee.",
        'adviser_final_hardbound_request.php',
        true
    );
}

$_SESSION['final_hardbound_upload_success'] = 'Final hardbound PDF uploaded successfully. Please wait for adviser endorsement and committee signatures.';
header('Location: student_final_hardbound_submission.php');
exit;
?>
