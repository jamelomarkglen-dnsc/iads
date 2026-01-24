<?php
/**
 * Final Routing Upload Handler
 */

session_start();
require_once 'db.php';
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

$action = trim($_POST['action'] ?? '');
if (!in_array($action, ['upload', 'upload_revision'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

ensureFinalRoutingTables($conn);

$student_id = (int)$_SESSION['user_id'];
$submission_id = fetch_latest_submission_id_for_student($conn, $student_id);
if ($submission_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No submission found for this student.']);
    exit;
}

$committee = fetch_latest_approved_committee_for_student($conn, $student_id);
if (!$committee) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Defense committee is not approved yet.']);
    exit;
}

$reviewers = build_final_routing_reviewers($committee);
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

$upload_result = upload_final_routing_file($_FILES['pdf_file'], $student_id);
if (!$upload_result['success']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $upload_result['errors']]);
    exit;
}

if ($action === 'upload_revision') {
    $parent_submission_id = (int)($_POST['parent_submission_id'] ?? 0);
    if ($parent_submission_id <= 0) {
        @unlink($upload_result['file_path']);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Parent submission ID is required for revision uploads.']);
        exit;
    }

    $submission_result = create_final_routing_revision_submission(
        $conn,
        $student_id,
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

    $new_submission_id = (int)$submission_result['submission_id'];
    $version_number = (int)$submission_result['version'];
    $success_message = "New version (v{$version_number}) uploaded successfully. The committee has been notified.";
    $redirect_url = "student_final_routing_view.php?submission_id={$new_submission_id}";
} else {
    $version_number = 1;
    $submission_result = create_final_routing_submission(
        $conn,
        $submission_id,
        $student_id,
        $committee,
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

    $new_submission_id = (int)$submission_result['submission_id'];
    replace_final_routing_reviews($conn, $new_submission_id, $reviewers);
    $success_message = 'Final routing PDF uploaded successfully. The committee has been notified.';
    $redirect_url = 'student_final_routing_submission.php';
}

$studentName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'A student';
$notification_link = "committee_final_routing_inbox.php?submission_id={$new_submission_id}";
$notification_title = $action === 'upload_revision' ? 'Final routing revision uploaded' : 'Final routing submission';
$notification_message = $action === 'upload_revision'
    ? "{$studentName} uploaded a new final routing version (v{$version_number})."
    : "{$studentName} submitted a final routing PDF for review.";

foreach ($reviewers as $reviewer) {
    $reviewer_id = (int)($reviewer['reviewer_id'] ?? 0);
    if ($reviewer_id <= 0) {
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

$_SESSION['final_routing_upload_success'] = $success_message;
$_SESSION['final_routing_upload_version'] = $version_number;

header("Location: {$redirect_url}");
exit;
?>
