<?php
/**
 * Committee PDF Upload Handler
 * Student uploads a committee-review PDF for annotations
 */

session_start();
require_once 'db.php';
require_once 'committee_pdf_submission_helpers.php';
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

$action = trim($_POST['action'] ?? '');
if (!in_array($action, ['upload', 'upload_revision'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

ensureCommitteePdfTables($conn);

$student_id = (int)$_SESSION['user_id'];
$defense_id = fetch_latest_defense_id_for_student($conn, $student_id);
if ($defense_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Defense committee is not assigned yet.']);
    exit;
}

$reviewers = fetch_committee_reviewers_for_student($conn, $student_id);
if (empty($reviewers)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Committee reviewers are missing. Please contact the program chairperson.']);
    exit;
}

if (!isset($_FILES['pdf_file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

$upload_result = upload_committee_pdf_file($_FILES['pdf_file'], $student_id);
if (!$upload_result['success']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $upload_result['errors']]);
    exit;
}

// Handle revision upload vs new upload
if ($action === 'upload_revision') {
    $parent_submission_id = (int)($_POST['parent_submission_id'] ?? 0);
    if ($parent_submission_id <= 0) {
        @unlink($upload_result['file_path']);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Parent submission ID is required for revision uploads.']);
        exit;
    }
    
    // Create revision submission
    $submission_result = create_committee_revision_submission(
        $conn,
        $student_id,
        $defense_id,
        $parent_submission_id,
        $upload_result['file_path'],
        $upload_result['original_filename'],
        $upload_result['file_size'],
        $upload_result['mime_type']
    );
    
    if (!$submission_result['success']) {
        @unlink($upload_result['file_path']);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $submission_result['error']]);
        exit;
    }
    
    $submission_id = (int)$submission_result['submission_id'];
    $version_number = (int)$submission_result['version'];
    $success_message = "New version (v{$version_number}) uploaded successfully. The committee has been notified.";
    $redirect_url = "student_committee_pdf_view.php?submission_id={$submission_id}";
    
} else {
    // New upload (not a revision) - always starts at version 1
    $version_number = 1;
    
    $submission_result = create_committee_pdf_submission(
        $conn,
        $student_id,
        $defense_id,
        $upload_result['file_path'],
        $upload_result['original_filename'],
        $upload_result['file_size'],
        $upload_result['mime_type'],
        $version_number
    );
    
    if (!$submission_result['success']) {
        @unlink($upload_result['file_path']);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $submission_result['error']]);
        exit;
    }
    
    $submission_id = (int)$submission_result['submission_id'];
    replace_committee_pdf_reviews($conn, $submission_id, $reviewers);
    $success_message = 'Committee PDF uploaded successfully. The committee has been notified.';
    $redirect_url = 'student_committee_pdf_submission.php';
}

// Send notifications to reviewers
$studentName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'A student';
$notification_link = "committee_pdf_inbox.php?submission_id={$submission_id}";
$notification_title = $action === 'upload_revision' ? 'New committee PDF version' : 'Committee PDF submission';
$notification_message = $action === 'upload_revision' 
    ? "{$studentName} uploaded a new version (v{$version_number}) of their committee PDF."
    : "{$studentName} submitted a committee PDF for review.";

foreach ($reviewers as $reviewer) {
    $reviewer_id = (int)($reviewer['reviewer_id'] ?? 0);
    $reviewer_role = trim((string)($reviewer['reviewer_role'] ?? ''));
    if ($reviewer_id <= 0 || $reviewer_role === '') {
        continue;
    }
    notify_user(
        $conn,
        $reviewer_id,
        $notification_title,
        $notification_message,
        $notification_link,
        true
    );
}

$_SESSION['committee_pdf_upload_success'] = $success_message;
$_SESSION['committee_pdf_upload_version'] = $version_number;

header("Location: {$redirect_url}");
exit;
?>
