<?php
/**
 * Adviser -> Committee PDF Send Handler
 * Sends the latest adviser-reviewed PDF to the defense committee.
 */

session_start();
require_once 'db.php';
require_once 'pdf_submission_helpers.php';
require_once 'committee_pdf_submission_helpers.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'adviser') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: adviser.php');
    exit;
}

$submission_id = (int)($_POST['submission_id'] ?? 0);
if ($submission_id <= 0) {
    $_SESSION['committee_send_error'] = 'Invalid submission selected.';
    header('Location: adviser.php');
    exit;
}

$submission = fetch_pdf_submission($conn, $submission_id);
if (!$submission) {
    $_SESSION['committee_send_error'] = 'Submission not found.';
    header('Location: adviser.php');
    exit;
}

if ((int)($submission['adviser_id'] ?? 0) !== (int)$_SESSION['user_id']) {
    $_SESSION['committee_send_error'] = 'You do not have permission to send this submission.';
    header('Location: adviser.php');
    exit;
}

$latest_id = get_latest_version_id($conn, $submission_id);
if ($latest_id !== $submission_id) {
    $_SESSION['committee_send_error'] = 'Please open the latest version before sending to the committee.';
    header("Location: adviser_pdf_review.php?submission_id={$latest_id}");
    exit;
}

ensureCommitteePdfTables($conn);

$student_id = (int)($submission['student_id'] ?? 0);
$defense_id = fetch_latest_defense_id_for_student($conn, $student_id);
if ($defense_id <= 0) {
    $_SESSION['committee_send_error'] = 'Defense committee is not assigned yet.';
    header("Location: adviser_pdf_review.php?submission_id={$submission_id}");
    exit;
}

$reviewers = fetch_committee_reviewers_for_student($conn, $student_id);
if (empty($reviewers)) {
    $_SESSION['committee_send_error'] = 'Committee reviewers are missing. Please contact the program chairperson.';
    header("Location: adviser_pdf_review.php?submission_id={$submission_id}");
    exit;
}

$existing = fetch_committee_submission_by_source($conn, $submission_id);
if ($existing) {
    $existingVersion = (int)($existing['version_number'] ?? 1);
    $existingDate = $existing['submitted_at'] ? date('M d, Y g:i A', strtotime($existing['submitted_at'])) : 'recently';
    $_SESSION['committee_send_info'] = "This PDF was already sent to the committee as version v{$existingVersion} ({$existingDate}).";
    header("Location: adviser_pdf_review.php?submission_id={$submission_id}");
    exit;
}

$copy_result = copy_committee_pdf_from_existing(
    (string)($submission['file_path'] ?? ''),
    $student_id,
    (string)($submission['original_filename'] ?? 'committee_submission.pdf')
);
if (!$copy_result['success']) {
    $error = $copy_result['errors'][0] ?? 'Failed to prepare the committee PDF.';
    $_SESSION['committee_send_error'] = $error;
    header("Location: adviser_pdf_review.php?submission_id={$submission_id}");
    exit;
}

$latest_committee = fetch_committee_pdf_submissions_for_student($conn, $student_id, true);
$parent_id = (int)($latest_committee[0]['id'] ?? 0);
$version_number = $parent_id > 0
    ? ((int)($latest_committee[0]['version_number'] ?? 1) + 1)
    : 1;

$submission_status = 'pending';
$source_submission_id = $submission_id;

if ($parent_id > 0) {
    $stmt = $conn->prepare("
        INSERT INTO committee_pdf_submissions
            (student_id, defense_id, file_path, original_filename, file_size, mime_type, submission_status, version_number, source_pdf_submission_id, parent_submission_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param(
            'iississiii',
            $student_id,
            $defense_id,
            $copy_result['file_path'],
            $copy_result['original_filename'],
            $copy_result['file_size'],
            $copy_result['mime_type'],
            $submission_status,
            $version_number,
            $source_submission_id,
            $parent_id
        );
    }
} else {
    $stmt = $conn->prepare("
        INSERT INTO committee_pdf_submissions
            (student_id, defense_id, file_path, original_filename, file_size, mime_type, submission_status, version_number, source_pdf_submission_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if ($stmt) {
        $stmt->bind_param(
            'iississii',
            $student_id,
            $defense_id,
            $copy_result['file_path'],
            $copy_result['original_filename'],
            $copy_result['file_size'],
            $copy_result['mime_type'],
            $submission_status,
            $version_number,
            $source_submission_id
        );
    }
}

if (!$stmt || !$stmt->execute()) {
    @unlink($copy_result['file_path']);
    $_SESSION['committee_send_error'] = 'Unable to send the PDF to the committee. Please try again.';
    if ($stmt) {
        $stmt->close();
    }
    header("Location: adviser_pdf_review.php?submission_id={$submission_id}");
    exit;
}

$committee_submission_id = (int)$stmt->insert_id;
$stmt->close();

replace_committee_pdf_reviews($conn, $committee_submission_id, $reviewers);

if (function_exists('update_submission_status')) {
    update_submission_status($conn, $submission_id, 'approved');
}

$studentName = trim((string)($submission['student_name'] ?? '')) ?: 'A student';
$notification_link = "committee_pdf_inbox.php?submission_id={$committee_submission_id}";
$notification_title = $version_number > 1 ? 'New committee PDF version' : 'Committee PDF submission';
$notification_message = $version_number > 1
    ? "{$studentName} submitted a new committee PDF version (v{$version_number}) via adviser."
    : "{$studentName}'s adviser submitted a committee PDF for review.";

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

$_SESSION['committee_send_success'] = "Sent to committee as version v{$version_number}.";
header("Location: adviser_pdf_review.php?submission_id={$submission_id}");
exit;
?>
