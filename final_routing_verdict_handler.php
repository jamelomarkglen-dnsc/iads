<?php
/**
 * Final Routing Verdict Handler
 */

session_start();
require_once 'db.php';
require_once 'final_routing_submission_helpers.php';
require_once 'final_concept_helpers.php';
require_once 'notifications_helper.php';

$allowedRoles = ['committee_chairperson', 'committee_chair'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    $_SESSION['final_routing_verdict_error'] = 'Unauthorized access. Only committee chairperson can submit verdicts.';
    header('Location: committee_final_routing_inbox.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['final_routing_verdict_error'] = 'Invalid request method.';
    header('Location: committee_final_routing_inbox.php');
    exit;
}

ensureFinalRoutingTables($conn);

$submission_id = (int)($_POST['submission_id'] ?? 0);
$verdict = trim($_POST['routing_verdict'] ?? '');
$comments = trim($_POST['verdict_comments'] ?? '');
$chairperson_id = (int)($_SESSION['user_id']);

if ($submission_id <= 0) {
    $_SESSION['final_routing_verdict_error'] = 'Invalid submission ID.';
    header('Location: committee_final_routing_inbox.php');
    exit;
}

$allowed_verdicts = ['Passed', 'Needs Revision'];
if (!in_array($verdict, $allowed_verdicts, true)) {
    $_SESSION['final_routing_verdict_error'] = 'Invalid verdict selection.';
    header("Location: committee_final_routing_review.php?submission_id={$submission_id}");
    exit;
}

$submission = fetch_final_routing_submission($conn, $submission_id);
if (!$submission) {
    $_SESSION['final_routing_verdict_error'] = 'Submission not found.';
    header('Location: committee_final_routing_inbox.php');
    exit;
}

if ((int)($submission['chair_id'] ?? 0) !== $chairperson_id) {
    $_SESSION['final_routing_verdict_error'] = 'Only the committee chairperson assigned to this submission can submit the verdict.';
    header("Location: committee_final_routing_review.php?submission_id={$submission_id}");
    exit;
}

$result = set_final_routing_verdict($conn, $submission_id, $verdict, $comments, $chairperson_id);
if (!$result['success']) {
    $_SESSION['final_routing_verdict_error'] = $result['error'] ?? 'Failed to submit verdict.';
    header("Location: committee_final_routing_review.php?submission_id={$submission_id}");
    exit;
}

$student_id = (int)$submission['student_id'];
$student_name = $submission['student_name'] ?? 'the student';
$verdict_label = final_routing_verdict_label($verdict);

notify_user_for_role(
    $conn,
    $student_id,
    'student',
    'Final routing verdict',
    "Your final routing submission has been marked as {$verdict_label}.",
    "student_final_routing_view.php?submission_id={$submission_id}",
    true
);

$committee_ids = array_unique(array_filter([
    (int)($submission['adviser_id'] ?? 0),
    (int)($submission['panel_member_one_id'] ?? 0),
    (int)($submission['panel_member_two_id'] ?? 0),
]));
foreach ($committee_ids as $member_id) {
    notify_user(
        $conn,
        $member_id,
        'Final routing verdict updated',
        "Final routing verdict for {$student_name} is {$verdict_label}.",
        "committee_final_routing_review.php?submission_id={$submission_id}",
        true
    );
}

$chairIds = getProgramChairsForStudent($conn, $student_id);
foreach ($chairIds as $chairId) {
    notify_user(
        $conn,
        (int)$chairId,
        'Final routing verdict',
        "Final routing verdict for {$student_name} is {$verdict_label}.",
        'program_chairperson.php',
        true
    );
}

$_SESSION['final_routing_verdict_success'] = "Final routing verdict '{$verdict_label}' has been submitted successfully.";
header("Location: committee_final_routing_review.php?submission_id={$submission_id}");
exit;
?>
