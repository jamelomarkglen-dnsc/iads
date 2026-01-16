<?php
/**
 * PDF Submission Helper Functions
 * Handles PDF file uploads, storage, and submission management
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

require_once 'db.php';

// =====================================================
// CONFIGURATION CONSTANTS
// =====================================================
define('PDF_UPLOAD_DIR', 'uploads/pdf_submissions/');
define('PDF_MAX_SIZE', 52428800); // 50MB in bytes
define('PDF_ALLOWED_MIME', ['application/pdf']);
define('PDF_ALLOWED_EXT', ['pdf']);

// =====================================================
// FUNCTION: Ensure PDF upload directories exist
// =====================================================
function ensure_pdf_directories() {
    $directories = [
        PDF_UPLOAD_DIR,
        'uploads/pdf_revisions/'
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_writable($dir)) {
            chmod($dir, 0755);
        }
    }
}

// =====================================================
// FUNCTION: Validate PDF file before upload
// =====================================================
function validate_pdf_file($file_array) {
    $errors = [];
    
    // Check if file exists
    if (!isset($file_array['tmp_name']) || empty($file_array['tmp_name'])) {
        $errors[] = 'No file was uploaded.';
        return ['valid' => false, 'errors' => $errors];
    }
    
    // Check file size
    if ($file_array['size'] > PDF_MAX_SIZE) {
        $errors[] = 'File size exceeds maximum limit of 50MB.';
    }
    
    // Check file extension
    $file_ext = strtolower(pathinfo($file_array['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, PDF_ALLOWED_EXT)) {
        $errors[] = 'Only PDF files are allowed.';
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_array['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, PDF_ALLOWED_MIME)) {
        $errors[] = 'Invalid file type. Only PDF files are allowed.';
    }
    
    // Check for PDF magic bytes
    $handle = fopen($file_array['tmp_name'], 'r');
    $header = fread($handle, 4);
    fclose($handle);
    
    if ($header !== '%PDF') {
        $errors[] = 'File does not appear to be a valid PDF.';
    }
    
    if (!empty($errors)) {
        return ['valid' => false, 'errors' => $errors];
    }
    
    return ['valid' => true, 'errors' => [], 'mime_type' => $mime_type];
}

// =====================================================
// FUNCTION: Generate unique filename for PDF
// =====================================================
function generate_pdf_filename($student_id, $original_filename) {
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    $ext = 'pdf';
    
    // Sanitize original filename
    $base_name = pathinfo($original_filename, PATHINFO_FILENAME);
    $base_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base_name);
    $base_name = substr($base_name, 0, 30); // Limit length
    
    return "pdf_{$student_id}_{$timestamp}_{$random}_{$base_name}.{$ext}";
}

// =====================================================
// FUNCTION: Upload PDF file to server
// =====================================================
function upload_pdf_file($file_array, $student_id) {
    // Validate file
    $validation = validate_pdf_file($file_array);
    if (!$validation['valid']) {
        return ['success' => false, 'errors' => $validation['errors']];
    }
    
    // Ensure directories exist
    ensure_pdf_directories();
    
    // Generate unique filename
    $filename = generate_pdf_filename($student_id, $file_array['name']);
    $file_path = PDF_UPLOAD_DIR . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file_array['tmp_name'], $file_path)) {
        return ['success' => false, 'errors' => ['Failed to save file to server.']];
    }
    
    // Set proper permissions
    chmod($file_path, 0644);
    
    return [
        'success' => true,
        'filename' => $filename,
        'file_path' => $file_path,
        'file_size' => filesize($file_path),
        'mime_type' => $validation['mime_type'],
        'original_filename' => $file_array['name']
    ];
}

// =====================================================
// FUNCTION: Create PDF submission in database
// =====================================================
function create_pdf_submission(mysqli $conn, $student_id, $adviser_id, $file_path, $original_filename, $file_size, $mime_type) {
    $sql = "
        INSERT INTO pdf_submissions (
            student_id,
            adviser_id,
            file_path,
            original_filename,
            file_size,
            mime_type,
            submission_status,
            version_number
        ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 1)
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('iisssi', $student_id, $adviser_id, $file_path, $original_filename, $file_size, $mime_type);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create submission: ' . $stmt->error];
    }
    
    $submission_id = $stmt->insert_id;
    $stmt->close();
    
    return ['success' => true, 'submission_id' => $submission_id];
}

// =====================================================
// FUNCTION: Fetch PDF submission by ID
// =====================================================
function fetch_pdf_submission(mysqli $conn, $submission_id) {
    $sql = "
        SELECT 
            ps.submission_id,
            ps.student_id,
            ps.adviser_id,
            ps.file_path,
            ps.original_filename,
            ps.submission_timestamp,
            ps.file_size,
            ps.mime_type,
            ps.submission_status,
            ps.version_number,
            ps.parent_submission_id,
            CONCAT(u_student.firstname, ' ', u_student.lastname) AS student_name,
            u_student.email AS student_email,
            CONCAT(u_adviser.firstname, ' ', u_adviser.lastname) AS adviser_name,
            u_adviser.email AS adviser_email
        FROM pdf_submissions ps
        LEFT JOIN users u_student ON ps.student_id = u_student.id
        LEFT JOIN users u_adviser ON ps.adviser_id = u_adviser.id
        WHERE ps.submission_id = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $submission = $result->fetch_assoc();
    $stmt->close();
    
    return $submission ?: null;
}

// =====================================================
// FUNCTION: Fetch all submissions for a student
// =====================================================
function fetch_student_submissions(mysqli $conn, $student_id) {
    $sql = "
        SELECT 
            ps.submission_id,
            ps.student_id,
            ps.adviser_id,
            ps.file_path,
            ps.original_filename,
            ps.submission_timestamp,
            ps.submission_status,
            ps.version_number,
            ps.parent_submission_id,
            CONCAT(u_adviser.firstname, ' ', u_adviser.lastname) AS adviser_name,
            COUNT(pa.annotation_id) AS annotation_count,
            SUM(CASE WHEN pa.annotation_status = 'active' THEN 1 ELSE 0 END) AS unresolved_count
        FROM pdf_submissions ps
        LEFT JOIN users u_adviser ON ps.adviser_id = u_adviser.id
        LEFT JOIN pdf_annotations pa ON ps.submission_id = pa.submission_id
        WHERE ps.student_id = ?
        GROUP BY ps.submission_id
        ORDER BY ps.submission_timestamp DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $submissions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $submissions ?: [];
}

// =====================================================
// FUNCTION: Fetch all submissions for an adviser
// =====================================================
function fetch_adviser_submissions(mysqli $conn, $adviser_id) {
    $sql = "
        SELECT 
            ps.submission_id,
            ps.student_id,
            ps.adviser_id,
            ps.file_path,
            ps.original_filename,
            ps.submission_timestamp,
            ps.submission_status,
            ps.version_number,
            CONCAT(u_student.firstname, ' ', u_student.lastname) AS student_name,
            u_student.email AS student_email,
            COUNT(pa.annotation_id) AS annotation_count,
            SUM(CASE WHEN pa.annotation_status = 'active' THEN 1 ELSE 0 END) AS unresolved_count
        FROM pdf_submissions ps
        LEFT JOIN users u_student ON ps.student_id = u_student.id
        LEFT JOIN pdf_annotations pa ON ps.submission_id = pa.submission_id
        WHERE ps.adviser_id = ?
        GROUP BY ps.submission_id
        ORDER BY ps.submission_timestamp DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param('i', $adviser_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $submissions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $submissions ?: [];
}

// =====================================================
// FUNCTION: Update submission status
// =====================================================
function update_submission_status(mysqli $conn, $submission_id, $new_status) {
    $valid_statuses = ['pending', 'reviewed', 'approved', 'revision_requested'];
    
    if (!in_array($new_status, $valid_statuses)) {
        return ['success' => false, 'error' => 'Invalid status value.'];
    }
    
    $sql = "
        UPDATE pdf_submissions
        SET submission_status = ?
        WHERE submission_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('si', $new_status, $submission_id);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to update status: ' . $stmt->error];
    }
    
    $stmt->close();
    return ['success' => true];
}

// =====================================================
// FUNCTION: Create revision submission
// =====================================================
function create_revision_submission(mysqli $conn, $student_id, $adviser_id, $parent_submission_id, $file_path, $original_filename, $file_size, $mime_type) {
    // Get parent submission version
    $parent = fetch_pdf_submission($conn, $parent_submission_id);
    if (!$parent) {
        return ['success' => false, 'error' => 'Parent submission not found.'];
    }
    
    $new_version = $parent['version_number'] + 1;
    
    $sql = "
        INSERT INTO pdf_submissions (
            student_id,
            adviser_id,
            file_path,
            original_filename,
            file_size,
            mime_type,
            submission_status,
            version_number,
            parent_submission_id
        ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('iisssiii', $student_id, $adviser_id, $file_path, $original_filename, $file_size, $mime_type, $new_version, $parent_submission_id);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create revision: ' . $stmt->error];
    }
    
    $submission_id = $stmt->insert_id;
    $stmt->close();
    
    return ['success' => true, 'submission_id' => $submission_id, 'version' => $new_version];
}

// =====================================================
// FUNCTION: Delete PDF file from server
// =====================================================
function delete_pdf_file($file_path) {
    if (file_exists($file_path)) {
        return unlink($file_path);
    }
    return true;
}

// =====================================================
// FUNCTION: Get submission status label
// =====================================================
function get_submission_status_label($status) {
    $labels = [
        'pending' => 'Pending Review',
        'reviewed' => 'Reviewed',
        'approved' => 'Approved',
        'revision_requested' => 'Revision Requested'
    ];
    
    return $labels[$status] ?? ucfirst($status);
}

// =====================================================
// FUNCTION: Get submission status badge class
// =====================================================
function get_submission_status_class($status) {
    $classes = [
        'pending' => 'badge bg-warning text-dark',
        'reviewed' => 'badge bg-info',
        'approved' => 'badge bg-success',
        'revision_requested' => 'badge bg-danger'
    ];
    
    return $classes[$status] ?? 'badge bg-secondary';
}

// =====================================================
// FUNCTION: Verify adviser-student relationship
// =====================================================
function verify_adviser_student_relationship(mysqli $conn, $adviser_id, $student_id) {
    $sql = "
        SELECT 1
        FROM pdf_submissions
        WHERE adviser_id = ? AND student_id = ?
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param('ii', $adviser_id, $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    
    return $exists;
}

// =====================================================
// FUNCTION: Get submission statistics
// =====================================================
function get_submission_statistics(mysqli $conn, $submission_id) {
    $sql = "
        SELECT 
            COUNT(DISTINCT pa.annotation_id) AS total_annotations,
            SUM(CASE WHEN pa.annotation_status = 'active' THEN 1 ELSE 0 END) AS unresolved_annotations,
            SUM(CASE WHEN pa.annotation_status = 'resolved' THEN 1 ELSE 0 END) AS resolved_annotations,
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
// FUNCTION: Get version chain information
// =====================================================
function get_version_chain_info(mysqli $conn, $submission_id) {
    $current = fetch_pdf_submission($conn, $submission_id);
    if (!$current) {
        return null;
    }
    
    // Get previous version ID
    $previous_id = $current['parent_submission_id'];
    
    // Get next version ID
    $next_id = null;
    $next_sql = "SELECT submission_id FROM pdf_submissions WHERE parent_submission_id = ? LIMIT 1";
    $next_stmt = $conn->prepare($next_sql);
    if ($next_stmt) {
        $next_stmt->bind_param('i', $submission_id);
        $next_stmt->execute();
        $next_result = $next_stmt->get_result();
        if ($next_row = $next_result->fetch_assoc()) {
            $next_id = $next_row['submission_id'];
        }
        $next_stmt->close();
    }
    
    // Get latest version in chain
    $latest_id = $submission_id;
    $temp_id = $submission_id;
    while (true) {
        $check_sql = "SELECT submission_id FROM pdf_submissions WHERE parent_submission_id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) break;
        
        $check_stmt->bind_param('i', $temp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_row = $check_result->fetch_assoc()) {
            $latest_id = $check_row['submission_id'];
            $temp_id = $latest_id;
        } else {
            break;
        }
        $check_stmt->close();
    }
    
    // Count total versions in chain
    $total_versions = 1;
    $count_id = $submission_id;
    
    // Count backwards
    while ($count_id) {
        $parent_sql = "SELECT parent_submission_id FROM pdf_submissions WHERE submission_id = ?";
        $parent_stmt = $conn->prepare($parent_sql);
        if (!$parent_stmt) break;
        
        $parent_stmt->bind_param('i', $count_id);
        $parent_stmt->execute();
        $parent_result = $parent_stmt->get_result();
        
        if ($parent_row = $parent_result->fetch_assoc()) {
            if ($parent_row['parent_submission_id']) {
                $total_versions++;
                $count_id = $parent_row['parent_submission_id'];
            } else {
                break;
            }
        } else {
            break;
        }
        $parent_stmt->close();
    }
    
    // Count forwards from current
    $forward_id = $submission_id;
    while (true) {
        $child_sql = "SELECT submission_id FROM pdf_submissions WHERE parent_submission_id = ? LIMIT 1";
        $child_stmt = $conn->prepare($child_sql);
        if (!$child_stmt) break;
        
        $child_stmt->bind_param('i', $forward_id);
        $child_stmt->execute();
        $child_result = $child_stmt->get_result();
        
        if ($child_row = $child_result->fetch_assoc()) {
            $total_versions++;
            $forward_id = $child_row['submission_id'];
        } else {
            break;
        }
        $child_stmt->close();
    }
    
    $is_latest = ($latest_id == $submission_id);
    
    return [
        'current_version' => $current['version_number'],
        'total_versions' => $total_versions,
        'previous_id' => $previous_id,
        'next_id' => $next_id,
        'latest_id' => $latest_id,
        'is_latest' => $is_latest,
        'has_previous' => !is_null($previous_id),
        'has_next' => !is_null($next_id)
    ];
}

// =====================================================
// FUNCTION: Get latest version in submission chain
// =====================================================
function get_latest_version_id(mysqli $conn, $submission_id) {
    $info = get_version_chain_info($conn, $submission_id);
    return $info ? $info['latest_id'] : $submission_id;
}

// Initialize directories on include
ensure_pdf_directories();
?>
