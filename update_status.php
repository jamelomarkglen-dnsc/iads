<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'submission_helpers.php';

if (!function_exists('str_starts_with')) {
    /**
     * Polyfill for PHP versions below 8.
     *
     * @param string $haystack
     * @param string $needle
     */
    function str_starts_with($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: submissions.php");
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$submissionId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$actionType = trim($_POST['action_type'] ?? '');
$requestedStatus = trim($_POST['status'] ?? '');
$redirectTarget = trim($_POST['redirect_to'] ?? '');

if ($submissionId <= 0) {
    $_SESSION['error'] = "Invalid submission reference.";
    header("Location: submissions.php");
    exit;
}

/**
 * Determine if we can safely redirect to the provided path.
 */
$resolveRedirect = static function (string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '') {
        return '';
    }
    if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://') || str_starts_with($candidate, '//')) {
        return '';
    }
    if (str_contains($candidate, "\n") || str_contains($candidate, "\r")) {
        return '';
    }
    return $candidate;
};

$redirectPath = $resolveRedirect($redirectTarget);
$statusToApply = $requestedStatus;
$flashMessage = 'Status updated successfully.';

$quickActions = [
    'start_review' => [
        'status' => 'Reviewing',
        'message' => 'Submission moved to reviewing.',
        'fallback_redirect' => function (int $id): string {
            return "review_submission.php?id={$id}";
        },
    ],
    'assign_reviewer' => [
        'status' => 'Reviewer Assigning',
        'message' => 'Submission flagged for reviewer assignment.',
        'fallback_redirect' => function (int $id): string {
            $query = rawurlencode("submission {$id}");
            return "assign_faculty.php?status=all&source=submissions&q={$query}";
        },
    ],
];

if ($actionType !== '' && isset($quickActions[$actionType])) {
    $statusToApply = $quickActions[$actionType]['status'];
    $flashMessage = $quickActions[$actionType]['message'];
    if ($redirectPath === '') {
        $fallback = $quickActions[$actionType]['fallback_redirect'];
        $redirectPath = $fallback($submissionId);
    }
}

if ($statusToApply === '') {
    $_SESSION['error'] = 'Please choose a new status.';
    header('Location: submissions.php');
    exit;
}

$result = change_submission_status($conn, $submissionId, $statusToApply, $userId);
if (!$result['success']) {
    $_SESSION['error'] = $result['error'] ?? 'Failed to update status.';
    header('Location: submissions.php');
    exit;
}

$oldStatus = $result['old_status'] ?? 'Pending';
$newStatus = $result['new_status'] ?? $statusToApply;
$submission = $result['submission'] ?? [];
$submissionTitle = trim((string)($submission['title'] ?? 'your submission'));
$studentId = isset($submission['student_id']) ? (int)$submission['student_id'] : 0;

if ($oldStatus === $newStatus) {
    $flashMessage = 'Status already up to date.';
}

$_SESSION['success'] = $flashMessage;

if ($studentId > 0) {
    $statusMessage = "Your submission \"{$submissionTitle}\" status was updated from {$oldStatus} to {$newStatus}.";
    notify_user(
        $conn,
        $studentId,
        'Submission status updated',
        $statusMessage,
        'student_dashboard.php'
    );
}

notify_roles(
    $conn,
    ['adviser', 'committee_chairperson', 'committee_chair'],
    'Submission status updated',
    "The submission \"{$submissionTitle}\" status changed from {$oldStatus} to {$newStatus}.",
    'submissions.php'
);

$destination = $redirectPath !== '' ? $redirectPath : 'submissions.php';
header("Location: {$destination}");
exit;
