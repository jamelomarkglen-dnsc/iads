<?php
/**
 * PDF Upload Handler
 * Processes PDF file uploads and creates submissions
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

session_start();
require_once 'db.php';
require_once 'pdf_submission_helpers.php';
require_once 'notifications_helper.php';

// =====================================================
// SECURITY: Verify user is logged in
// =====================================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
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
// SECURITY: Verify CSRF token (if implemented)
// =====================================================
// Uncomment if CSRF protection is implemented
// if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
//     http_response_code(403);
//     echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
//     exit;
// }

// =====================================================
// EXTRACT REQUEST DATA
// =====================================================
$student_id = (int)$_SESSION['user_id'];
$adviser_id = isset($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

// =====================================================
// VALIDATE ADVISER ID
// =====================================================
if ($adviser_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid adviser ID.']);
    exit;
}

// =====================================================
// VERIFY ADVISER EXISTS
// =====================================================
// Check if user exists and has adviser-capable roles in user_roles table
$adviser_check = $conn->prepare("
    SELECT DISTINCT u.id
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    WHERE u.id = ?
    AND ur.role_code IN ('adviser', 'faculty', 'program_chairperson', 'committee_chairperson', 'committee_chair', 'panel')
    LIMIT 1
");
if (!$adviser_check) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
    exit;
}

$adviser_check->bind_param('i', $adviser_id);
$adviser_check->execute();
$adviser_result = $adviser_check->get_result();

if ($adviser_result->num_rows === 0) {
    $adviser_check->close();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid adviser.']);
    exit;
}

$adviser_check->close();

// =====================================================
// HANDLE FILE UPLOAD
// =====================================================
if ($action === 'upload' && isset($_FILES['pdf_file'])) {
    // Upload and validate file
    $upload_result = upload_pdf_file($_FILES['pdf_file'], $student_id);
    
    if (!$upload_result['success']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $upload_result['errors']]);
        exit;
    }
    
    // Create submission in database
    $submission_result = create_pdf_submission(
        $conn,
        $student_id,
        $adviser_id,
        $upload_result['file_path'],
        $upload_result['original_filename'],
        $upload_result['file_size'],
        $upload_result['mime_type']
    );
    
    if (!$submission_result['success']) {
        // Delete uploaded file if database insert fails
        delete_pdf_file($upload_result['file_path']);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $submission_result['error']]);
        exit;
    }
    
    $submission_id = $submission_result['submission_id'];
    
    // Get student and adviser names for notification
    $student_query = $conn->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
    $student_query->bind_param('i', $student_id);
    $student_query->execute();
    $student_data = $student_query->get_result()->fetch_assoc();
    $student_query->close();
    
    $student_name = $student_data['firstname'] . ' ' . $student_data['lastname'];
    
    // Create notification for adviser
    $notification_message = "{$student_name} submitted a new PDF for review.";
    $notification_link = "adviser.php?action=review_pdf&submission_id={$submission_id}";
    
    notify_user(
        $conn,
        $adviser_id,
        'New PDF Submission',
        $notification_message,
        $notification_link
    );
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'submission_id' => $submission_id,
        'message' => 'PDF uploaded successfully. Your adviser has been notified.'
    ]);
    exit;
}

// =====================================================
// HANDLE REVISION UPLOAD
// =====================================================
if ($action === 'upload_revision' && isset($_FILES['pdf_file'])) {
    $parent_submission_id = isset($_POST['parent_submission_id']) ? (int)$_POST['parent_submission_id'] : 0;
    
    if ($parent_submission_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid parent submission ID.']);
        exit;
    }
    
    // Verify parent submission belongs to student
    $parent_check = $conn->prepare("SELECT submission_id FROM pdf_submissions WHERE submission_id = ? AND student_id = ?");
    if (!$parent_check) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error.']);
        exit;
    }
    
    $parent_check->bind_param('ii', $parent_submission_id, $student_id);
    $parent_check->execute();
    $parent_result = $parent_check->get_result();
    
    if ($parent_result->num_rows === 0) {
        $parent_check->close();
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to parent submission.']);
        exit;
    }
    
    $parent_check->close();
    
    // Upload and validate file
    $upload_result = upload_pdf_file($_FILES['pdf_file'], $student_id);
    
    if (!$upload_result['success']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $upload_result['errors']]);
        exit;
    }
    
    // Create revision submission
    $revision_result = create_revision_submission(
        $conn,
        $student_id,
        $adviser_id,
        $parent_submission_id,
        $upload_result['file_path'],
        $upload_result['original_filename'],
        $upload_result['file_size'],
        $upload_result['mime_type']
    );
    
    if (!$revision_result['success']) {
        // Delete uploaded file if database insert fails
        delete_pdf_file($upload_result['file_path']);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $revision_result['error']]);
        exit;
    }
    
    $submission_id = $revision_result['submission_id'];
    
    // Get student name for notification
    $student_query = $conn->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
    $student_query->bind_param('i', $student_id);
    $student_query->execute();
    $student_data = $student_query->get_result()->fetch_assoc();
    $student_query->close();
    
    $student_name = $student_data['firstname'] . ' ' . $student_data['lastname'];
    
    // Create notification for adviser
    $notification_message = "{$student_name} submitted a revised PDF (Version {$revision_result['version']}).";
    $notification_link = "adviser.php?action=review_pdf&submission_id={$submission_id}";
    
    notify_user(
        $conn,
        $adviser_id,
        'PDF Revision Submitted',
        $notification_message,
        $notification_link
    );
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'submission_id' => $submission_id,
        'version' => $revision_result['version'],
        'message' => 'Revised PDF uploaded successfully. Your adviser has been notified.'
    ]);
    exit;
}

// =====================================================
// INVALID ACTION - VERBOSE ERROR
// =====================================================
http_response_code(400);
$debug_info = [
    'success' => false,
    'error' => 'Invalid action.',
    'debug' => [
        'action_received' => $action,
        'post_data' => array_keys($_POST),
        'files_data' => array_keys($_FILES),
        'expected_action' => 'upload or upload_revision',
        'has_pdf_file' => isset($_FILES['pdf_file']),
        'adviser_id' => $adviser_id
    ]
];
echo json_encode($debug_info);
exit;
?>
