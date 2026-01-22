<?php
/**
 * Committee PDF Verdict Handler
 * Processes final verdict submissions from committee chairperson
 */

session_start();
require_once 'db.php';
require_once 'committee_pdf_submission_helpers.php';
require_once 'notifications_helper.php';

// Security: Only committee chairperson can submit verdicts
$allowedRoles = ['committee_chairperson', 'committee_chair'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    $_SESSION['verdict_error'] = 'Unauthorized access. Only committee chairperson can submit verdicts.';
    header('Location: committee_pdf_inbox.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['verdict_error'] = 'Invalid request method.';
    header('Location: committee_pdf_inbox.php');
    exit;
}

ensureCommitteePdfTables($conn);

$submission_id = (int)($_POST['submission_id'] ?? 0);
$verdict = trim($_POST['final_verdict'] ?? '');
$comments = trim($_POST['verdict_comments'] ?? '');
$chairperson_id = (int)$_SESSION['user_id'];

// Validate submission ID
if ($submission_id <= 0) {
    $_SESSION['verdict_error'] = 'Invalid submission ID.';
    header('Location: committee_pdf_inbox.php');
    exit;
}

// Validate verdict
$allowed_verdicts = ['passed', 'passed_minor_revisions', 'passed_major_revisions', 'redefense', 'failed'];
if (!in_array($verdict, $allowed_verdicts, true)) {
    $_SESSION['verdict_error'] = 'Invalid verdict selection.';
    header("Location: committee_pdf_review.php?submission_id={$submission_id}");
    exit;
}

// Get submission details
$submission = fetch_committee_pdf_submission($conn, $submission_id);
if (!$submission) {
    $_SESSION['verdict_error'] = 'Submission not found.';
    header('Location: committee_pdf_inbox.php');
    exit;
}

// Submit verdict
$result = submit_committee_final_verdict($conn, $submission_id, $verdict, $comments, $chairperson_id);

if (!$result['success']) {
    $_SESSION['verdict_error'] = $result['error'] ?? 'Failed to submit verdict.';
    header("Location: committee_pdf_review.php?submission_id={$submission_id}");
    exit;
}

// Send notification to student
$student_id = (int)$submission['student_id'];
$verdict_label = get_verdict_label($verdict);
$chairperson_name = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'Committee Chairperson';

notify_user_for_role(
    $conn,
    $student_id,
    'student',
    'Final Verdict Received',
    "The committee chairperson has submitted the final verdict for your defense: {$verdict_label}",
    "student_committee_pdf_view.php?submission_id={$submission_id}",
    true
);

// Success message
$_SESSION['verdict_success'] = "Final verdict '{$verdict_label}' has been submitted successfully.";
header("Location: committee_pdf_review.php?submission_id={$submission_id}");
exit;
