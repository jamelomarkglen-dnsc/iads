<?php
/**
 * PDF Annotation API Endpoints
 * Handles annotation CRUD operations via AJAX
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

session_start();
require_once 'db.php';
require_once 'pdf_annotation_helpers.php';
require_once 'pdf_submission_helpers.php';
require_once 'notifications_helper.php';

// =====================================================
// SECURITY: Verify user is logged in
// =====================================================
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// =====================================================
// SECURITY: Verify request method
// =====================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// =====================================================
// EXTRACT REQUEST DATA
// =====================================================
$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

// =====================================================
// ACTION: Create annotation
// =====================================================
if ($action === 'create_annotation') {
    // Verify user is adviser
    if (!in_array($user_role, ['adviser', 'committee_chairperson', 'panel'])) {
        error_log("ERROR: User role '{$user_role}' not authorized to create annotations");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only advisers can create annotations.', 'debug' => ['user_role' => $user_role]]);
        exit;
    }
    
    // Extract parameters
    $submission_id = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
    $annotation_type = isset($_POST['annotation_type']) ? trim($_POST['annotation_type']) : '';
    $annotation_content = isset($_POST['annotation_content']) ? trim($_POST['annotation_content']) : '';
    $page_number = isset($_POST['page_number']) ? (int)$_POST['page_number'] : 0;
    $x_coordinate = isset($_POST['x_coordinate']) ? (float)$_POST['x_coordinate'] : 0;
    $y_coordinate = isset($_POST['y_coordinate']) ? (float)$_POST['y_coordinate'] : 0;
    $position_width = isset($_POST['position_width']) ? (float)$_POST['position_width'] : 5;
    $position_height = isset($_POST['position_height']) ? (float)$_POST['position_height'] : 5;
    $selected_text = isset($_POST['selected_text']) ? trim($_POST['selected_text']) : null;
    
    // Log received parameters
    error_log("DEBUG: Create annotation - submission_id={$submission_id}, type={$annotation_type}, page={$page_number}, user_id={$user_id}");
    
    // Validate submission exists and adviser has access
    $submission = fetch_pdf_submission($conn, $submission_id);
    
    if (!$submission) {
        error_log("ERROR: Submission {$submission_id} not found");
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.', 'debug' => ['submission_id' => $submission_id]]);
        exit;
    }
    
    if ($submission['adviser_id'] != $user_id) {
        error_log("ERROR: User {$user_id} not authorized for submission {$submission_id} (adviser_id={$submission['adviser_id']})");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to submission.', 'debug' => ['user_id' => $user_id, 'adviser_id' => $submission['adviser_id']]]);
        exit;
    }
    
    // Validate required fields
    if (empty($annotation_content)) {
        error_log("ERROR: Empty annotation content");
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Annotation content is required.']);
        exit;
    }
    
    // Create annotation
    $result = create_annotation(
        $conn,
        $submission_id,
        $user_id,
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
        error_log("ERROR: Failed to create annotation - " . ($result['error'] ?? 'Unknown error'));
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    
    error_log("SUCCESS: Created annotation {$result['annotation_id']} for submission {$submission_id}");
    
    // Update submission status if not already reviewed
    if ($submission['submission_status'] === 'pending') {
        update_submission_status($conn, $submission_id, 'reviewed');
    }
    
    // Notify student
    $student_name = $submission['adviser_name'];
    $notification_message = "{$student_name} added feedback to your PDF submission.";
    notify_user(
        $conn,
        $submission['student_id'],
        'New Feedback on Your PDF',
        $notification_message,
        "student_dashboard.php?action=view_feedback&submission_id={$submission_id}"
    );
    
    http_response_code(200);
    echo json_encode(['success' => true, 'annotation_id' => $result['annotation_id']]);
    exit;
}

// =====================================================
// ACTION: Fetch annotations for submission
// =====================================================
if ($action === 'fetch_annotations') {
    $submission_id = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
    
    // Validate submission exists
    $submission = fetch_pdf_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }
    
    // Verify access (student can view own, adviser can view assigned)
    if ($user_role === 'student' && $submission['student_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    
    if (in_array($user_role, ['adviser', 'committee_chairperson', 'panel']) && $submission['adviser_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    
    // Fetch annotations
    $annotations = fetch_submission_annotations($conn, $submission_id);
    
    // Fetch replies for each annotation
    foreach ($annotations as &$annotation) {
        $annotation['replies'] = fetch_annotation_replies($conn, $annotation['annotation_id']);
    }
    
    http_response_code(200);
    echo json_encode(['success' => true, 'annotations' => $annotations]);
    exit;
}

// =====================================================
// ACTION: Fetch page annotations
// =====================================================
if ($action === 'fetch_page_annotations') {
    $submission_id = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
    $page_number = isset($_POST['page_number']) ? (int)$_POST['page_number'] : 0;
    
    // Validate submission exists
    $submission = fetch_pdf_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }
    
    // Verify access
    if ($user_role === 'student' && $submission['student_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    
    // Fetch page annotations
    $annotations = fetch_page_annotations($conn, $submission_id, $page_number);
    
    // Fetch replies for each annotation
    foreach ($annotations as &$annotation) {
        $annotation['replies'] = fetch_annotation_replies($conn, $annotation['annotation_id']);
    }
    
    http_response_code(200);
    echo json_encode(['success' => true, 'annotations' => $annotations]);
    exit;
}

// =====================================================
// ACTION: Update annotation
// =====================================================
if ($action === 'update_annotation') {
    $annotation_id = isset($_POST['annotation_id']) ? (int)$_POST['annotation_id'] : 0;
    $annotation_content = isset($_POST['annotation_content']) ? trim($_POST['annotation_content']) : '';
    
    // Fetch annotation
    $annotation = fetch_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }
    
    // Verify user is the annotation creator
    if ($annotation['adviser_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized to update this annotation.']);
        exit;
    }
    
    // Update annotation
    $result = update_annotation($conn, $annotation_id, $annotation_content, $user_id);
    
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

// =====================================================
// ACTION: Resolve annotation
// =====================================================
if ($action === 'resolve_annotation') {
    $annotation_id = isset($_POST['annotation_id']) ? (int)$_POST['annotation_id'] : 0;
    
    // Fetch annotation
    $annotation = fetch_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }
    
    // Verify access (adviser or student can resolve)
    if ($annotation['adviser_id'] != $user_id && $user_role !== 'student') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized to resolve this annotation.']);
        exit;
    }
    
    // Resolve annotation
    $result = resolve_annotation($conn, $annotation_id, $user_id);
    
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

// =====================================================
// ACTION: Delete annotation
// =====================================================
if ($action === 'delete_annotation') {
    $annotation_id = isset($_POST['annotation_id']) ? (int)$_POST['annotation_id'] : 0;
    
    // Fetch annotation
    $annotation = fetch_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }
    
    // Verify user is the annotation creator
    if ($annotation['adviser_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized to delete this annotation.']);
        exit;
    }
    
    // Delete annotation
    $result = delete_annotation($conn, $annotation_id, $user_id);
    
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

// =====================================================
// ACTION: Add reply to annotation
// =====================================================
if ($action === 'add_reply') {
    $annotation_id = isset($_POST['annotation_id']) ? (int)$_POST['annotation_id'] : 0;
    $reply_content = isset($_POST['reply_content']) ? trim($_POST['reply_content']) : '';
    
    // Fetch annotation
    $annotation = fetch_annotation($conn, $annotation_id);
    if (!$annotation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Annotation not found.']);
        exit;
    }
    
    // Determine user role for reply
    $reply_user_role = $user_role === 'student' ? 'student' : 'adviser';
    
    // Add reply
    $result = add_annotation_reply($conn, $annotation_id, $user_id, $reply_content, $reply_user_role);
    
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    
    // Notify relevant user
    if ($reply_user_role === 'student') {
        // Notify adviser
        notify_user(
            $conn,
            $annotation['adviser_id'],
            'Student Reply to Your Annotation',
            'A student replied to your annotation.',
            "adviser.php?action=review_pdf&submission_id={$annotation['submission_id']}"
        );
    } else {
        // Notify student
        $submission = fetch_pdf_submission($conn, $annotation['submission_id']);
        notify_user(
            $conn,
            $submission['student_id'],
            'Adviser Reply to Your Annotation',
            'Your adviser replied to an annotation.',
            "student_dashboard.php?action=view_feedback&submission_id={$annotation['submission_id']}"
        );
    }
    
    http_response_code(200);
    echo json_encode(['success' => true, 'reply_id' => $result['reply_id']]);
    exit;
}

// =====================================================
// ACTION: Fetch annotation statistics
// =====================================================
if ($action === 'fetch_statistics') {
    $submission_id = isset($_POST['submission_id']) ? (int)$_POST['submission_id'] : 0;
    
    // Validate submission exists
    $submission = fetch_pdf_submission($conn, $submission_id);
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Submission not found.']);
        exit;
    }
    
    // Verify access
    if ($user_role === 'student' && $submission['student_id'] != $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
        exit;
    }
    
    // Get statistics
    $stats = get_annotation_statistics($conn, $submission_id);
    
    http_response_code(200);
    echo json_encode(['success' => true, 'statistics' => $stats]);
    exit;
}

// =====================================================
// INVALID ACTION
// =====================================================
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action.']);
exit;
?>
