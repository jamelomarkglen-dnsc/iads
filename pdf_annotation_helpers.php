<?php
/**
 * PDF Annotation Helper Functions
 * Handles annotation creation, retrieval, and management
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

require_once 'db.php';

// =====================================================
// FUNCTION: Create annotation
// =====================================================
function create_annotation(mysqli $conn, $submission_id, $adviser_id, $annotation_type, $annotation_content, $page_number, $x_coordinate, $y_coordinate, $selected_text = null, $position_width = 5, $position_height = 5) {
    // Validate annotation type
    $valid_types = ['comment', 'highlight', 'suggestion'];
    if (!in_array($annotation_type, $valid_types)) {
        return ['success' => false, 'error' => 'Invalid annotation type.'];
    }
    
    // Validate coordinates
    if ($x_coordinate < 0 || $x_coordinate > 100 || $y_coordinate < 0 || $y_coordinate > 100) {
        return ['success' => false, 'error' => 'Invalid coordinates. Must be between 0 and 100.'];
    }
    
    // Validate page number
    if ($page_number < 1) {
        return ['success' => false, 'error' => 'Invalid page number.'];
    }
    
    // Sanitize content
    $annotation_content = trim($annotation_content);
    if (empty($annotation_content)) {
        return ['success' => false, 'error' => 'Annotation content cannot be empty.'];
    }
    
    $sql = "
        INSERT INTO pdf_annotations (
            submission_id,
            adviser_id,
            annotation_type,
            annotation_content,
            page_number,
            x_coordinate,
            y_coordinate,
            selected_text,
            position_width,
            position_height,
            annotation_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('iissiddsdd', $submission_id, $adviser_id, $annotation_type, $annotation_content, $page_number, $x_coordinate, $y_coordinate, $selected_text, $position_width, $position_height);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create annotation: ' . $stmt->error];
    }
    
    $annotation_id = $stmt->insert_id;
    $stmt->close();
    
    // Record in history
    record_annotation_history($conn, $annotation_id, 'created', $adviser_id, null, $annotation_content);
    
    return ['success' => true, 'annotation_id' => $annotation_id];
}

// =====================================================
// FUNCTION: Fetch annotation by ID
// =====================================================
function fetch_annotation(mysqli $conn, $annotation_id) {
    $sql = "
        SELECT
            pa.annotation_id,
            pa.submission_id,
            pa.adviser_id,
            pa.annotation_type,
            pa.annotation_content,
            pa.page_number,
            pa.x_coordinate,
            pa.y_coordinate,
            pa.selected_text,
            pa.position_width,
            pa.position_height,
            pa.creation_timestamp,
            pa.annotation_status,
            CONCAT(u.firstname, ' ', u.lastname) AS adviser_name,
            u.email AS adviser_email
        FROM pdf_annotations pa
        LEFT JOIN users u ON pa.adviser_id = u.id
        WHERE pa.annotation_id = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param('i', $annotation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $annotation = $result->fetch_assoc();
    $stmt->close();
    
    return $annotation ?: null;
}

// =====================================================
// FUNCTION: Fetch all annotations for a submission
// =====================================================
function fetch_submission_annotations(mysqli $conn, $submission_id) {
    $sql = "
        SELECT
            pa.annotation_id,
            pa.submission_id,
            pa.adviser_id,
            pa.annotation_type,
            pa.annotation_content,
            pa.page_number,
            pa.x_coordinate,
            pa.y_coordinate,
            pa.selected_text,
            pa.position_width,
            pa.position_height,
            pa.creation_timestamp,
            pa.annotation_status,
            CONCAT(u.firstname, ' ', u.lastname) AS adviser_name,
            u.email AS adviser_email,
            COUNT(ar.reply_id) AS reply_count
        FROM pdf_annotations pa
        LEFT JOIN users u ON pa.adviser_id = u.id
        LEFT JOIN annotation_replies ar ON pa.annotation_id = ar.annotation_id
        WHERE pa.submission_id = ?
        GROUP BY pa.annotation_id
        ORDER BY pa.page_number ASC, pa.creation_timestamp ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $annotations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $annotations ?: [];
}

// =====================================================
// FUNCTION: Fetch annotations for a specific page
// =====================================================
function fetch_page_annotations(mysqli $conn, $submission_id, $page_number) {
    $sql = "
        SELECT
            pa.annotation_id,
            pa.submission_id,
            pa.adviser_id,
            pa.annotation_type,
            pa.annotation_content,
            pa.page_number,
            pa.x_coordinate,
            pa.y_coordinate,
            pa.selected_text,
            pa.position_width,
            pa.position_height,
            pa.creation_timestamp,
            pa.annotation_status,
            CONCAT(u.firstname, ' ', u.lastname) AS adviser_name,
            COUNT(ar.reply_id) AS reply_count
        FROM pdf_annotations pa
        LEFT JOIN users u ON pa.adviser_id = u.id
        LEFT JOIN annotation_replies ar ON pa.annotation_id = ar.annotation_id
        WHERE pa.submission_id = ? AND pa.page_number = ?
        GROUP BY pa.annotation_id
        ORDER BY pa.creation_timestamp ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param('ii', $submission_id, $page_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $annotations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $annotations ?: [];
}

// =====================================================
// FUNCTION: Update annotation
// =====================================================
function update_annotation(mysqli $conn, $annotation_id, $annotation_content, $changed_by) {
    // Get old value
    $old_annotation = fetch_annotation($conn, $annotation_id);
    if (!$old_annotation) {
        return ['success' => false, 'error' => 'Annotation not found.'];
    }
    
    $old_content = $old_annotation['annotation_content'];
    
    $sql = "
        UPDATE pdf_annotations
        SET annotation_content = ?
        WHERE annotation_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('si', $annotation_content, $annotation_id);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to update annotation: ' . $stmt->error];
    }
    
    $stmt->close();
    
    // Record in history
    record_annotation_history($conn, $annotation_id, 'updated', $changed_by, $old_content, $annotation_content);
    
    return ['success' => true];
}

// =====================================================
// FUNCTION: Resolve annotation
// =====================================================
function resolve_annotation(mysqli $conn, $annotation_id, $resolved_by) {
    $sql = "
        UPDATE pdf_annotations
        SET annotation_status = 'resolved'
        WHERE annotation_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('i', $annotation_id);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to resolve annotation: ' . $stmt->error];
    }
    
    $stmt->close();
    
    // Record in history
    record_annotation_history($conn, $annotation_id, 'resolved', $resolved_by, 'active', 'resolved');
    
    return ['success' => true];
}

// =====================================================
// FUNCTION: Delete annotation
// =====================================================
function delete_annotation(mysqli $conn, $annotation_id, $deleted_by) {
    // Record in history before deletion
    record_annotation_history($conn, $annotation_id, 'deleted', $deleted_by, null, null);
    
    $sql = "
        DELETE FROM pdf_annotations
        WHERE annotation_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('i', $annotation_id);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to delete annotation: ' . $stmt->error];
    }
    
    $stmt->close();
    return ['success' => true];
}

// =====================================================
// FUNCTION: Record annotation history
// =====================================================
function record_annotation_history(mysqli $conn, $annotation_id, $action_type, $changed_by, $old_value = null, $new_value = null) {
    $sql = "
        INSERT INTO annotation_history (
            annotation_id,
            action_type,
            changed_by,
            old_value,
            new_value
        ) VALUES (?, ?, ?, ?, ?)
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param('isiss', $annotation_id, $action_type, $changed_by, $old_value, $new_value);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// =====================================================
// FUNCTION: Add reply to annotation
// =====================================================
function add_annotation_reply(mysqli $conn, $annotation_id, $user_id, $reply_content, $user_role) {
    // Validate user role
    $valid_roles = ['student', 'adviser'];
    if (!in_array($user_role, $valid_roles)) {
        return ['success' => false, 'error' => 'Invalid user role.'];
    }
    
    // Sanitize content
    $reply_content = trim($reply_content);
    if (empty($reply_content)) {
        return ['success' => false, 'error' => 'Reply content cannot be empty.'];
    }
    
    $sql = "
        INSERT INTO annotation_replies (
            annotation_id,
            user_id,
            reply_content,
            user_role
        ) VALUES (?, ?, ?, ?)
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('iiss', $annotation_id, $user_id, $reply_content, $user_role);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to add reply: ' . $stmt->error];
    }
    
    $reply_id = $stmt->insert_id;
    $stmt->close();
    
    return ['success' => true, 'reply_id' => $reply_id];
}

// =====================================================
// FUNCTION: Fetch annotation replies
// =====================================================
function fetch_annotation_replies(mysqli $conn, $annotation_id) {
    $sql = "
        SELECT 
            ar.reply_id,
            ar.annotation_id,
            ar.user_id,
            ar.reply_content,
            ar.reply_timestamp,
            ar.user_role,
            CONCAT(u.firstname, ' ', u.lastname) AS user_name,
            u.email AS user_email
        FROM annotation_replies ar
        LEFT JOIN users u ON ar.user_id = u.id
        WHERE ar.annotation_id = ?
        ORDER BY ar.reply_timestamp ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param('i', $annotation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $replies = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $replies ?: [];
}

// =====================================================
// FUNCTION: Get annotation type label
// =====================================================
function get_annotation_type_label($type) {
    $labels = [
        'comment' => 'Comment',
        'highlight' => 'Highlight',
        'suggestion' => 'Suggestion'
    ];
    
    return $labels[$type] ?? ucfirst($type);
}

// =====================================================
// FUNCTION: Get annotation type badge class
// =====================================================
function get_annotation_type_class($type) {
    $classes = [
        'comment' => 'badge bg-primary',
        'highlight' => 'badge bg-warning text-dark',
        'suggestion' => 'badge bg-info'
    ];
    
    return $classes[$type] ?? 'badge bg-secondary';
}

// =====================================================
// FUNCTION: Get annotation status label
// =====================================================
function get_annotation_status_label($status) {
    $labels = [
        'active' => 'Active',
        'resolved' => 'Resolved',
        'archived' => 'Archived'
    ];
    
    return $labels[$status] ?? ucfirst($status);
}

// =====================================================
// FUNCTION: Get annotation status badge class
// =====================================================
function get_annotation_status_class($status) {
    $classes = [
        'active' => 'badge bg-danger',
        'resolved' => 'badge bg-success',
        'archived' => 'badge bg-secondary'
    ];
    
    return $classes[$status] ?? 'badge bg-secondary';
}

// =====================================================
// FUNCTION: Get annotation color code
// =====================================================
function get_annotation_color($type) {
    $colors = [
        'comment' => '#FFD700',    // Gold
        'highlight' => '#FFFF00',  // Yellow
        'suggestion' => '#87CEEB'  // Sky Blue
    ];
    
    return $colors[$type] ?? '#CCCCCC';
}

// =====================================================
// FUNCTION: Get annotation statistics for submission
// =====================================================
function get_annotation_statistics(mysqli $conn, $submission_id) {
    $sql = "
        SELECT 
            COUNT(DISTINCT pa.annotation_id) AS total_annotations,
            SUM(CASE WHEN pa.annotation_status = 'active' THEN 1 ELSE 0 END) AS active_annotations,
            SUM(CASE WHEN pa.annotation_status = 'resolved' THEN 1 ELSE 0 END) AS resolved_annotations,
            SUM(CASE WHEN pa.annotation_type = 'comment' THEN 1 ELSE 0 END) AS comment_count,
            SUM(CASE WHEN pa.annotation_type = 'highlight' THEN 1 ELSE 0 END) AS highlight_count,
            SUM(CASE WHEN pa.annotation_type = 'suggestion' THEN 1 ELSE 0 END) AS suggestion_count,
            COUNT(DISTINCT ar.reply_id) AS total_replies
        FROM pdf_submissions ps
        LEFT JOIN pdf_annotations pa ON ps.submission_id = pa.submission_id
        LEFT JOIN annotation_replies ar ON pa.annotation_id = ar.annotation_id
        WHERE ps.submission_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    return $stats ?: null;
}

// =====================================================
// FUNCTION: Get annotations by adviser
// =====================================================
function get_adviser_annotations(mysqli $conn, $submission_id, $adviser_id) {
    $sql = "
        SELECT
            pa.annotation_id,
            pa.annotation_type,
            pa.annotation_content,
            pa.page_number,
            pa.x_coordinate,
            pa.y_coordinate,
            pa.selected_text,
            pa.position_width,
            pa.position_height,
            pa.creation_timestamp,
            pa.annotation_status,
            COUNT(ar.reply_id) AS reply_count
        FROM pdf_annotations pa
        LEFT JOIN annotation_replies ar ON pa.annotation_id = ar.annotation_id
        WHERE pa.submission_id = ? AND pa.adviser_id = ?
        GROUP BY pa.annotation_id
        ORDER BY pa.creation_timestamp DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param('ii', $submission_id, $adviser_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $annotations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $annotations ?: [];
}
?>
