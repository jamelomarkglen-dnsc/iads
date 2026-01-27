<?php
/**
 * Student archive upload handler (post-final hardbound endorsement).
 */

session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';
require_once 'final_concept_helpers.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: student_final_hardbound_submission.php');
    exit;
}

ensureFinalHardboundTables($conn);

$student_id = (int)$_SESSION['user_id'];
$hardbound_id = (int)($_POST['hardbound_id'] ?? 0);

if ($hardbound_id <= 0) {
    $_SESSION['final_hardbound_archive_upload_error'] = 'Invalid hardbound submission.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$hardbound = fetch_final_hardbound_submission($conn, $hardbound_id);
if (!$hardbound || (int)($hardbound['student_id'] ?? 0) !== $student_id) {
    $_SESSION['final_hardbound_archive_upload_error'] = 'Hardbound submission not found.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$request = fetch_final_hardbound_committee_request($conn, $hardbound_id);
$submissionPassed = final_hardbound_display_status($hardbound['status'] ?? '') === 'Passed';
$requestPassed = $request && final_hardbound_display_status($request['status'] ?? '') === 'Passed';
if (!$submissionPassed || !$requestPassed) {
    $_SESSION['final_hardbound_archive_upload_error'] = 'Archive upload is available only after the committee endorsement is Passed.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

if (!isset($_FILES['archive_file'])) {
    $_SESSION['final_hardbound_archive_upload_error'] = 'No file uploaded.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$upload_result = upload_final_hardbound_archive_file($_FILES['archive_file'], $student_id);
if (!$upload_result['success']) {
    $_SESSION['final_hardbound_archive_upload_error'] = implode(' ', $upload_result['errors']);
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$submission_id = (int)($hardbound['submission_id'] ?? 0);
if ($submission_id <= 0) {
    @unlink($upload_result['file_path']);
    $_SESSION['final_hardbound_archive_upload_error'] = 'Unable to locate submission record.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$existing = fetch_final_hardbound_archive_upload($conn, $hardbound_id);
if ($existing && ($existing['status'] ?? '') === 'Archived') {
    @unlink($upload_result['file_path']);
    $_SESSION['final_hardbound_archive_upload_error'] = 'Your archive copy has already been archived.';
    header('Location: student_final_hardbound_submission.php');
    exit;
}

$filePath = $upload_result['file_path'];
$originalFilename = $upload_result['original_filename'];
$fileSize = (int)$upload_result['file_size'];
$mimeType = $upload_result['mime_type'];

if ($existing) {
    $existingId = (int)$existing['id'];
    $stmt = $conn->prepare("
        UPDATE final_hardbound_archive_uploads
        SET file_path = ?, original_filename = ?, file_size = ?, mime_type = ?, status = 'Pending', uploaded_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) {
        @unlink($upload_result['file_path']);
        $_SESSION['final_hardbound_archive_upload_error'] = 'Unable to update archive upload.';
        header('Location: student_final_hardbound_submission.php');
        exit;
    }
    $stmt->bind_param('ssisi', $filePath, $originalFilename, $fileSize, $mimeType, $existingId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        @unlink($upload_result['file_path']);
        $_SESSION['final_hardbound_archive_upload_error'] = 'Unable to save archive upload.';
        header('Location: student_final_hardbound_submission.php');
        exit;
    }

    $oldPath = (string)($existing['file_path'] ?? '');
    if ($oldPath !== '' && $oldPath !== $filePath && strpos($oldPath, FINAL_HARDBOUND_ARCHIVE_UPLOAD_DIR) === 0 && is_file($oldPath)) {
        @unlink($oldPath);
    }
} else {
    $stmt = $conn->prepare("
        INSERT INTO final_hardbound_archive_uploads
            (hardbound_submission_id, submission_id, student_id, file_path, original_filename, file_size, mime_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')
    ");
    if (!$stmt) {
        @unlink($upload_result['file_path']);
        $_SESSION['final_hardbound_archive_upload_error'] = 'Unable to create archive upload.';
        header('Location: student_final_hardbound_submission.php');
        exit;
    }
    $stmt->bind_param(
        'iiissis',
        $hardbound_id,
        $submission_id,
        $student_id,
        $filePath,
        $originalFilename,
        $fileSize,
        $mimeType
    );
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        @unlink($upload_result['file_path']);
        $_SESSION['final_hardbound_archive_upload_error'] = 'Unable to save archive upload.';
        header('Location: student_final_hardbound_submission.php');
        exit;
    }
}

$studentName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'A student';
$chairIds = getProgramChairsForStudent($conn, $student_id);
if (!empty($chairIds)) {
    notify_users(
        $conn,
        $chairIds,
        'Archive copy uploaded',
        "{$studentName} uploaded the archive PDF after final hardbound endorsement.",
        'archive_manager.php',
        true
    );
}

$_SESSION['final_hardbound_archive_upload_success'] = 'Archive PDF uploaded successfully. Please wait for the program chairperson to archive it.';
header('Location: student_final_hardbound_submission.php');
exit;
?>
