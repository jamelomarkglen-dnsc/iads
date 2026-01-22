<?php
/**
 * Committee PDF Annotation API
 * Separate endpoint for defense committee annotations
 */

session_start();
require_once 'db.php';
require_once 'committee_pdf_submission_helpers.php';
require_once 'committee_pdf_annotation_helpers.php';
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

ensureCommitteePdfTables($conn);

$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewerRole = $roleMap[$user_role] ?? $user_role;

function committee_reviewer_has_access(mysqli $conn, int $submission_id, int $reviewer_id, string $reviewer_role): bool
{
    $stmt = $conn->prepare("
        SELECT id
        FROM committee_pdf_reviews
        WHERE submission_id = ? AND reviewer_id = ? AND reviewer_role = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iis', $submission_id, $reviewer_id, $reviewer_role);
    $stmt->execute();
    $result = $stmt->get_result();
    $hasAccess = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $hasAccess;
}

if ($action === 'create_annotation') {
    if (!in_array($reviewerRole, ['adviser', 'committee_chairperson', 'panel'], true)) {
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

    $submission = fetch_committee_pdf_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }

    if (!committee_reviewer_has_access($conn, $submission_id, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to submission.']);
        exit;
    }

    if ($annotation_content === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Annotation content is required.']);
        exit;
    }

    $result = create_committee_annotation(
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

    mark_committee_submission_reviewed($conn, $submission_id);
    mark_committee_review_status($conn, $submission_id, $user_id);

    $reviewerName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'Committee Reviewer';
    $message = "{$reviewerName} added feedback to your committee PDF submission.";
    notify_user(
        $conn,
        (int)$submission['student_id'],
        'Committee feedback added',
        $message,
        "student_committee_pdf_view.php?submission_id={$submission_id}"
    );

    http_response_code(200);
    echo json_encode(['success' => true, 'annotation_id' => $result['annotation_id']]);
    exit;
}

if ($action === 'fetch_annotations') {
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $submission = fetch_committee_pdf_submission($conn, $submission_id);
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
    if ($user_role !== 'student' && !committee_reviewer_has_access($conn, $submission_id, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }

    $annotations = fetch_committee_submission_annotations($conn, $submission_id);
    foreach ($annotations as &$annotation) {
        $annotation['replies'] = fetch_committee_annotation_replies($conn, (int)$annotation['annotation_id']);
    }
    unset($annotation);

    http_response_code(200);
    echo json_encode(['success' => true, 'annotations' => $annotations]);
    exit;
}

if ($action === 'fetch_page_annotations') {
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $page_number = (int)($_POST['page_number'] ?? 0);
    $submission = fetch_committee_pdf_submission($conn, $submission_id);
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
    if ($user_role !== 'student' && !committee_reviewer_has_access($conn, $submission_id, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }

    $annotations = fetch_committee_page_annotations($conn, $submission_id, $page_number);
    foreach ($annotations as &$annotation) {
        $annotation['replies'] = fetch_committee_annotation_replies($conn, (int)$annotation['annotation_id']);
    }
    unset($annotation);

    http_response_code(200);
    echo json_encode(['success' => true, 'annotations' => $annotations]);
    exit;
}

if ($action === 'update_annotation') {
    $annotation_id = (int)($_POST['annotation_id'] ?? 0);
    $annotation_content = trim($_POST['annotation_content'] ?? '');
    $annotation = fetch_committee_annotation($conn, $annotation_id);
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
    $result = update_committee_annotation($conn, $annotation_id, $annotation_content, $user_id);
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
    $annotation = fetch_committee_annotation($conn, $annotation_id);
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
    $result = resolve_committee_annotation($conn, $annotation_id);
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
    $annotation = fetch_committee_annotation($conn, $annotation_id);
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
    $result = delete_committee_annotation($conn, $annotation_id);
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
    $annotation = fetch_committee_annotation($conn, $annotation_id);
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
    $result = add_committee_annotation_reply($conn, $annotation_id, $user_id, $reply_content, $reply_user_role);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    $submission = fetch_committee_pdf_submission($conn, (int)$annotation['submission_id']);
    if ($submission) {
        if ($reply_user_role === 'student') {
            $stmt = $conn->prepare("
                SELECT reviewer_id, reviewer_role
                FROM committee_pdf_reviews
                WHERE submission_id = ?
            ");
            if ($stmt) {
                $sid = (int)$annotation['submission_id'];
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $rid = (int)($row['reviewer_id'] ?? 0);
                    $rrole = trim((string)($row['reviewer_role'] ?? ''));
                    if ($rid <= 0 || $rrole === '') {
                        continue;
                    }
                    notify_user(
                        $conn,
                        $rid,
                        'Student reply to committee annotation',
                        'A student replied to a committee annotation.',
                        "committee_pdf_review.php?submission_id={$annotation['submission_id']}",
                        true
                    );
                }
                $stmt->close();
            }
        } else {
            notify_user(
                $conn,
                (int)$submission['student_id'],
                'Committee reply to your annotation',
                'A committee reviewer replied to an annotation.',
                "student_committee_pdf_view.php?submission_id={$annotation['submission_id']}"
            );
        }
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'reply_id' => $result['reply_id']]);
    exit;
}

if ($action === 'fetch_statistics') {
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $submission = fetch_committee_pdf_submission($conn, $submission_id);
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
    if ($user_role !== 'student' && !committee_reviewer_has_access($conn, $submission_id, $user_id, $reviewerRole)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    $stats = get_committee_annotation_statistics($conn, $submission_id);
    http_response_code(200);
    echo json_encode(['success' => true, 'statistics' => $stats]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
exit;
?>
