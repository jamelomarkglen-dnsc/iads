<?php
/**
 * Final Defense Annotation API
 */

session_start();
require_once 'db.php';
require_once 'final_defense_submission_helpers.php';
require_once 'final_defense_annotation_helpers.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$action = trim($_POST['action'] ?? '');

ensureFinalDefenseSubmissionTable($conn);
ensureFinalDefenseAnnotationTables($conn);

$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewerRole = $roleMap[$user_role] ?? $user_role;

function fetch_final_defense_submission(mysqli $conn, int $submission_id): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_defense_submissions
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function final_defense_reviewer_has_access(array $submission, int $reviewer_id, string $reviewer_role): bool
{
    if ($reviewer_id <= 0) {
        return false;
    }

    $allowedRoles = ['committee_chairperson', 'panel', 'adviser'];
    if (!in_array($reviewer_role, $allowedRoles, true)) {
        return false;
    }

    return in_array($reviewer_id, [
        (int)($submission['adviser_id'] ?? 0),
        (int)($submission['chair_id'] ?? 0),
        (int)($submission['panel_member_one_id'] ?? 0),
        (int)($submission['panel_member_two_id'] ?? 0),
    ], true);
}

if ($action === 'create_annotation') {
    if (!in_array($reviewerRole, ['committee_chairperson', 'panel', 'adviser'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only committee members can create annotations.']);
        exit;
    }

    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $annotation_type = trim($_POST['annotation_type'] ?? '');
    $annotation_content = trim($_POST['annotation_content'] ?? '');
    $page_number = (int)($_POST['page_number'] ?? 0);
    $x_coordinate = (float)($_POST['x_coordinate'] ?? 0);
    $y_coordinate = (float)($_POST['y_coordinate'] ?? 0);
    $position_width = (float)($_POST['position_width'] ?? 5);
    $position_height = (float)($_POST['position_height'] ?? 5);
    $selected_text = isset($_POST['selected_text']) ? trim($_POST['selected_text']) : null;

    $submission = fetch_final_defense_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }

    if (!final_defense_reviewer_has_access($submission, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to submission.']);
        exit;
    }

    if ($annotation_content === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Annotation content is required.']);
        exit;
    }

    $result = create_final_defense_annotation(
        $conn,
        $submission_id,
        $user_id,
        $reviewerRole,
        $annotation_type,
        $annotation_content,
        $page_number,
        $x_coordinate,
        $y_coordinate,
        $selected_text,
        $position_width,
        $position_height
    );

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    $studentId = (int)($submission['student_id'] ?? 0);
    if ($studentId > 0) {
        $reviewerName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'Committee Reviewer';
        $message = "{$reviewerName} added feedback to your final defense submission.";
        notify_user(
            $conn,
            $studentId,
            'Final defense feedback added',
            $message,
            "final_defense_pdf_view.php?submission_id={$submission_id}"
        );
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'annotation_id' => $result['annotation_id']]);
    exit;
}

if ($action === 'fetch_annotations') {
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $submission = fetch_final_defense_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }

    if ($user_role === 'student' && (int)$submission['student_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    if ($user_role !== 'student' && !final_defense_reviewer_has_access($submission, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }

    $annotations = fetch_final_defense_submission_annotations($conn, $submission_id);
    foreach ($annotations as &$annotation) {
        $annotation['replies'] = fetch_final_defense_annotation_replies($conn, (int)$annotation['annotation_id']);
    }
    unset($annotation);

    http_response_code(200);
    echo json_encode(['success' => true, 'annotations' => $annotations]);
    exit;
}

if ($action === 'fetch_page_annotations') {
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $page_number = (int)($_POST['page_number'] ?? 0);
    $submission = fetch_final_defense_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }

    if ($user_role === 'student' && (int)$submission['student_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    if ($user_role !== 'student' && !final_defense_reviewer_has_access($submission, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }

    $annotations = fetch_final_defense_page_annotations($conn, $submission_id, $page_number);
    foreach ($annotations as &$annotation) {
        $annotation['replies'] = fetch_final_defense_annotation_replies($conn, (int)$annotation['annotation_id']);
    }
    unset($annotation);

    http_response_code(200);
    echo json_encode(['success' => true, 'annotations' => $annotations]);
    exit;
}

if ($action === 'update_annotation') {
    $annotation_id = (int)($_POST['annotation_id'] ?? 0);
    $annotation_content = trim($_POST['annotation_content'] ?? '');
    $annotation = fetch_final_defense_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }
    if ((int)$annotation['reviewer_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized to update this annotation.']);
        exit;
    }
    $result = update_final_defense_annotation($conn, $annotation_id, $annotation_content, $user_id);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'resolve_annotation') {
    $annotation_id = (int)($_POST['annotation_id'] ?? 0);
    $annotation = fetch_final_defense_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }
    if ((int)$annotation['reviewer_id'] !== $user_id && $user_role !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized to resolve this annotation.']);
        exit;
    }
    $result = resolve_final_defense_annotation($conn, $annotation_id);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_annotation') {
    $annotation_id = (int)($_POST['annotation_id'] ?? 0);
    $annotation = fetch_final_defense_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }
    if ((int)$annotation['reviewer_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized to delete this annotation.']);
        exit;
    }
    $result = delete_final_defense_annotation($conn, $annotation_id);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'add_reply') {
    $annotation_id = (int)($_POST['annotation_id'] ?? 0);
    $reply_content = trim($_POST['reply_content'] ?? '');
    $annotation = fetch_final_defense_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }

    if ($user_role === 'student') {
        $reply_user_role = 'student';
    } else {
        $roleMap = ['committee_chair' => 'committee_chairperson'];
        $reply_user_role = $roleMap[$user_role] ?? $user_role;
        if (!in_array($reply_user_role, ['adviser', 'committee_chairperson', 'panel'], true)) {
            $reply_user_role = 'adviser';
        }
    }
    $result = add_final_defense_annotation_reply($conn, $annotation_id, $user_id, $reply_content, $reply_user_role);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    $submission = fetch_final_defense_submission($conn, (int)$annotation['submission_id']);
    if ($submission) {
        if ($reply_user_role === 'student') {
            $notifyIds = array_unique(array_filter([
                (int)($submission['adviser_id'] ?? 0),
                (int)($submission['chair_id'] ?? 0),
                (int)($submission['panel_member_one_id'] ?? 0),
                (int)($submission['panel_member_two_id'] ?? 0),
            ]));
            foreach ($notifyIds as $notifyId) {
                notify_user(
                    $conn,
                    $notifyId,
                    'Student reply to final defense annotation',
                    'A student replied to a final defense annotation.',
                    "final_defense_pdf_view.php?submission_id={$annotation['submission_id']}",
                    true
                );
            }
        } else {
            notify_user(
                $conn,
                (int)$submission['student_id'],
                'Committee reply to your annotation',
                'A committee reviewer replied to an annotation.',
                "final_defense_pdf_view.php?submission_id={$annotation['submission_id']}"
            );
        }
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'reply_id' => $result['reply_id']]);
    exit;
}

if ($action === 'fetch_statistics') {
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $submission = fetch_final_defense_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }
    if ($user_role === 'student' && (int)$submission['student_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    if ($user_role !== 'student' && !final_defense_reviewer_has_access($submission, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    $stats = get_final_defense_annotation_statistics($conn, $submission_id);
    http_response_code(200);
    echo json_encode(['success' => true, 'statistics' => $stats]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
exit;
?>

