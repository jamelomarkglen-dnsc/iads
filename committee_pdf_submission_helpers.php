<?php
/**
 * Committee PDF Submission Helper Functions
 * Separate submission/annotation flow for defense committee review
 */

require_once 'db.php';

define('COMMITTEE_PDF_UPLOAD_DIR', 'uploads/committee_pdf_submissions/');
define('COMMITTEE_PDF_MAX_SIZE', 52428800);
define('COMMITTEE_PDF_ALLOWED_MIME', ['application/pdf']);
define('COMMITTEE_PDF_ALLOWED_EXT', ['pdf']);

function ensure_committee_pdf_directories(): void
{
    if (!is_dir(COMMITTEE_PDF_UPLOAD_DIR)) {
        mkdir(COMMITTEE_PDF_UPLOAD_DIR, 0755, true);
    }
    if (!is_writable(COMMITTEE_PDF_UPLOAD_DIR)) {
        chmod(COMMITTEE_PDF_UPLOAD_DIR, 0755);
    }
}

function validate_committee_pdf_file(array $file_array): array
{
    $errors = [];

    if (!isset($file_array['tmp_name']) || $file_array['tmp_name'] === '') {
        $errors[] = 'No file was uploaded.';
        return ['valid' => false, 'errors' => $errors];
    }

    if ($file_array['size'] > COMMITTEE_PDF_MAX_SIZE) {
        $errors[] = 'File size exceeds maximum limit of 50MB.';
    }

    $file_ext = strtolower(pathinfo($file_array['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, COMMITTEE_PDF_ALLOWED_EXT, true)) {
        $errors[] = 'Only PDF files are allowed.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_array['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, COMMITTEE_PDF_ALLOWED_MIME, true)) {
        $errors[] = 'Invalid file type. Only PDF files are allowed.';
    }

    $handle = fopen($file_array['tmp_name'], 'r');
    $header = $handle ? fread($handle, 4) : '';
    if ($handle) {
        fclose($handle);
    }
    if ($header !== '%PDF') {
        $errors[] = 'File does not appear to be a valid PDF.';
    }

    if ($errors) {
        return ['valid' => false, 'errors' => $errors];
    }

    return ['valid' => true, 'errors' => [], 'mime_type' => $mime_type];
}

function generate_committee_pdf_filename(int $student_id, string $original_filename): string
{
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    $base_name = pathinfo($original_filename, PATHINFO_FILENAME);
    $base_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base_name);
    $base_name = substr($base_name, 0, 30);
    return "committee_pdf_{$student_id}_{$timestamp}_{$random}_{$base_name}.pdf";
}

function upload_committee_pdf_file(array $file_array, int $student_id): array
{
    $validation = validate_committee_pdf_file($file_array);
    if (!$validation['valid']) {
        return ['success' => false, 'errors' => $validation['errors']];
    }

    ensure_committee_pdf_directories();

    $filename = generate_committee_pdf_filename($student_id, $file_array['name']);
    $file_path = COMMITTEE_PDF_UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file_array['tmp_name'], $file_path)) {
        return ['success' => false, 'errors' => ['Failed to save file to server.']];
    }

    chmod($file_path, 0644);

    return [
        'success' => true,
        'filename' => $filename,
        'file_path' => $file_path,
        'file_size' => filesize($file_path),
        'mime_type' => $validation['mime_type'],
        'original_filename' => $file_array['name'],
    ];
}

function ensureCommitteePdfTables(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS committee_pdf_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            defense_id INT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_size INT NULL,
            mime_type VARCHAR(100) NULL,
            submission_status ENUM('pending','reviewed') DEFAULT 'pending',
            version_number INT NOT NULL DEFAULT 1,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_committee_pdf_student (student_id),
            INDEX idx_committee_pdf_defense (defense_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS committee_pdf_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            reviewer_role ENUM('adviser','committee_chairperson','panel') NOT NULL,
            status ENUM('Pending','Reviewed') DEFAULT 'Pending',
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_committee_pdf_review (submission_id, reviewer_id),
            INDEX idx_committee_pdf_reviewer (reviewer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS committee_pdf_annotations (
            annotation_id INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            reviewer_role ENUM('adviser','committee_chairperson','panel') NOT NULL,
            annotation_type ENUM('comment','highlight','suggestion') NOT NULL,
            annotation_content TEXT NOT NULL,
            page_number INT NOT NULL,
            x_coordinate FLOAT NOT NULL,
            y_coordinate FLOAT NOT NULL,
            selected_text TEXT NULL,
            position_width FLOAT DEFAULT 5,
            position_height FLOAT DEFAULT 5,
            annotation_status ENUM('active','resolved') DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_committee_pdf_submission (submission_id),
            INDEX idx_committee_pdf_reviewer (reviewer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS committee_annotation_replies (
            reply_id INT AUTO_INCREMENT PRIMARY KEY,
            annotation_id INT NOT NULL,
            user_id INT NOT NULL,
            reply_content TEXT NOT NULL,
            reply_user_role ENUM('student','adviser','committee_chairperson','panel') NOT NULL,
            reply_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_committee_annotation (annotation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $replyRoleEnum = "ENUM('student','adviser','committee_chairperson','panel')";
    $needsUpdate = false;
    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'committee_annotation_replies'
          AND COLUMN_NAME = 'reply_user_role'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
        $columnType = $row['COLUMN_TYPE'] ?? '';
        if ($columnType !== '' && (strpos($columnType, "'committee_chairperson'") === false || strpos($columnType, "'panel'") === false)) {
            $needsUpdate = true;
        }
    }
    if ($needsUpdate) {
        $conn->query("ALTER TABLE committee_annotation_replies MODIFY COLUMN reply_user_role {$replyRoleEnum} NOT NULL");
    }

    $verdict_columns = [
        'final_verdict' => "ALTER TABLE committee_pdf_submissions
            ADD COLUMN final_verdict ENUM(
                'pending',
                'passed',
                'passed_minor_revisions',
                'passed_major_revisions',
                'redefense',
                'failed'
            ) DEFAULT 'pending' AFTER submission_status",
        'final_verdict_comments' => "ALTER TABLE committee_pdf_submissions
            ADD COLUMN final_verdict_comments TEXT NULL AFTER final_verdict",
        'final_verdict_by' => "ALTER TABLE committee_pdf_submissions
            ADD COLUMN final_verdict_by INT NULL AFTER final_verdict_comments",
        'final_verdict_at' => "ALTER TABLE committee_pdf_submissions
            ADD COLUMN final_verdict_at TIMESTAMP NULL AFTER final_verdict_by",
    ];

    foreach ($verdict_columns as $column => $alter_sql) {
        $column_stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'committee_pdf_submissions'
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if ($column_stmt) {
            $column_stmt->bind_param('s', $column);
            $column_stmt->execute();
            $column_result = $column_stmt->get_result();
            $exists = $column_result && $column_result->num_rows > 0;
            if ($column_result) {
                $column_result->free();
            }
            $column_stmt->close();
            if (!$exists) {
                $conn->query($alter_sql);
            }
        }
    }

    $index_stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'committee_pdf_submissions'
          AND INDEX_NAME = 'idx_final_verdict'
        LIMIT 1
    ");
    if ($index_stmt) {
        $index_stmt->execute();
        $index_result = $index_stmt->get_result();
        $index_exists = $index_result && $index_result->num_rows > 0;
        if ($index_result) {
            $index_result->free();
        }
        $index_stmt->close();
        if (!$index_exists) {
            $conn->query("CREATE INDEX idx_final_verdict ON committee_pdf_submissions (final_verdict)");
        }
    }

    $ensured = true;
}

function fetch_latest_defense_id_for_student(mysqli $conn, int $student_id): int
{
    if ($student_id <= 0) {
        return 0;
    }
    $stmt = $conn->prepare("
        SELECT id
        FROM defense_schedules
        WHERE student_id = ?
        ORDER BY defense_date DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return (int)($row['id'] ?? 0);
}

function fetch_committee_reviewers_for_student(mysqli $conn, int $student_id): array
{
    $defense_id = fetch_latest_defense_id_for_student($conn, $student_id);
    if ($defense_id <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT panel_member_id, panel_role
        FROM defense_panels
        WHERE defense_id = ?
        ORDER BY id
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $defense_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reviewers = [];
    $seen = [];
    $roleMap = [
        'adviser' => 'adviser',
        'committee_chair' => 'committee_chairperson',
        'panel_member' => 'panel',
    ];
    while ($row = $result->fetch_assoc()) {
        $userId = (int)($row['panel_member_id'] ?? 0);
        $panelRole = $row['panel_role'] ?? '';
        $reviewRole = $roleMap[$panelRole] ?? '';
        if ($userId <= 0 || $reviewRole === '' || isset($seen[$userId])) {
            continue;
        }
        $seen[$userId] = true;
        $reviewers[] = [
            'reviewer_id' => $userId,
            'reviewer_role' => $reviewRole,
        ];
    }
    $result->free();
    $stmt->close();

    return $reviewers;
}

function fetch_latest_committee_pdf_version(mysqli $conn, int $student_id): int
{
    $stmt = $conn->prepare("
        SELECT version_number
        FROM committee_pdf_submissions
        WHERE student_id = ?
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return (int)($row['version_number'] ?? 0);
}

function create_committee_pdf_submission(
    mysqli $conn,
    int $student_id,
    int $defense_id,
    string $file_path,
    string $original_filename,
    int $file_size,
    string $mime_type,
    int $version_number
): array {
    $stmt = $conn->prepare("
        INSERT INTO committee_pdf_submissions
            (student_id, defense_id, file_path, original_filename, file_size, mime_type, submission_status, version_number)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('iissisi', $student_id, $defense_id, $file_path, $original_filename, $file_size, $mime_type, $version_number);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create submission: ' . $stmt->error];
    }
    $submission_id = (int)$stmt->insert_id;
    $stmt->close();
    return ['success' => true, 'submission_id' => $submission_id];
}

function fetch_committee_pdf_submission(mysqli $conn, int $submission_id): ?array
{
    if ($submission_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT
            s.*,
            CONCAT(u.firstname, ' ', u.lastname) AS student_name,
            u.email AS student_email
        FROM committee_pdf_submissions s
        JOIN users u ON u.id = s.student_id
        WHERE s.id = ?
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

function fetch_committee_pdf_submissions_for_student(mysqli $conn, int $student_id, bool $latest_only = true): array
{
    if ($latest_only) {
        // Fetch only latest versions (submissions that don't have a child/newer version)
        $stmt = $conn->prepare("
            SELECT *
            FROM committee_pdf_submissions
            WHERE student_id = ?
            AND NOT EXISTS (
                SELECT 1 FROM committee_pdf_submissions child
                WHERE child.parent_submission_id = committee_pdf_submissions.id
            )
            ORDER BY submitted_at DESC, id DESC
        ");
    } else {
        // Fetch all versions
        $stmt = $conn->prepare("
            SELECT *
            FROM committee_pdf_submissions
            WHERE student_id = ?
            ORDER BY submitted_at DESC, id DESC
        ");
    }
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function fetch_committee_pdf_submissions_for_reviewer(mysqli $conn, int $reviewer_id, string $reviewer_role): array
{
    $stmt = $conn->prepare("
        SELECT
            s.*,
            r.status AS review_status,
            r.reviewer_role,
            CONCAT(u.firstname, ' ', u.lastname) AS student_name
        FROM committee_pdf_reviews r
        JOIN committee_pdf_submissions s ON s.id = r.submission_id
        JOIN users u ON u.id = s.student_id
        WHERE r.reviewer_id = ? AND r.reviewer_role = ?
        ORDER BY s.submitted_at DESC, s.id DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('is', $reviewer_id, $reviewer_role);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function replace_committee_pdf_reviews(mysqli $conn, int $submission_id, array $reviewers): void
{
    if ($submission_id <= 0) {
        return;
    }
    $conn->query("DELETE FROM committee_pdf_reviews WHERE submission_id = {$submission_id}");
    if (empty($reviewers)) {
        return;
    }
    $stmt = $conn->prepare("
        INSERT INTO committee_pdf_reviews (submission_id, reviewer_id, reviewer_role, status)
        VALUES (?, ?, ?, 'Pending')
    ");
    if (!$stmt) {
        return;
    }
    foreach ($reviewers as $reviewer) {
        $reviewer_id = (int)($reviewer['reviewer_id'] ?? 0);
        $reviewer_role = trim((string)($reviewer['reviewer_role'] ?? ''));
        if ($reviewer_id <= 0 || $reviewer_role === '') {
            continue;
        }
        $stmt->bind_param('iis', $submission_id, $reviewer_id, $reviewer_role);
        $stmt->execute();
    }
    $stmt->close();
}

function fetch_committee_review_row(mysqli $conn, int $submission_id, int $reviewer_id, string $reviewer_role): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM committee_pdf_reviews
        WHERE submission_id = ? AND reviewer_id = ? AND reviewer_role = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('iis', $submission_id, $reviewer_id, $reviewer_role);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function mark_committee_submission_reviewed(mysqli $conn, int $submission_id): void
{
    $conn->query("UPDATE committee_pdf_submissions SET submission_status = 'reviewed' WHERE id = {$submission_id}");
}

function mark_committee_review_status(mysqli $conn, int $submission_id, int $reviewer_id): void
{
    $stmt = $conn->prepare("
        UPDATE committee_pdf_reviews
        SET status = 'Reviewed', reviewed_at = NOW()
        WHERE submission_id = ? AND reviewer_id = ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ii', $submission_id, $reviewer_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Create a revision (new version) of an existing committee PDF submission
 * Similar to create_revision_submission() in pdf_submission_helpers.php
 */
function create_committee_revision_submission(
    mysqli $conn,
    int $student_id,
    int $defense_id,
    int $parent_submission_id,
    string $file_path,
    string $original_filename,
    int $file_size,
    string $mime_type
): array {
    // Get parent submission to determine version number
    $parent = fetch_committee_pdf_submission($conn, $parent_submission_id);
    if (!$parent) {
        return ['success' => false, 'error' => 'Parent submission not found.'];
    }
    
    // Verify student owns the parent submission
    if ((int)$parent['student_id'] !== $student_id) {
        return ['success' => false, 'error' => 'You do not have permission to create a revision of this submission.'];
    }
    
    $new_version = (int)($parent['version_number'] ?? 1) + 1;
    
    $stmt = $conn->prepare("
        INSERT INTO committee_pdf_submissions
            (student_id, defense_id, file_path, original_filename, file_size, mime_type, submission_status, version_number, parent_submission_id)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('iissisii', $student_id, $defense_id, $file_path, $original_filename, $file_size, $mime_type, $new_version, $parent_submission_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create revision: ' . $stmt->error];
    }
    $submission_id = (int)$stmt->insert_id;
    $stmt->close();
    
    // Create review assignments for the new version (copy from parent)
    $reviewers = fetch_committee_reviewers_for_student($conn, $student_id);
    if (!empty($reviewers)) {
        replace_committee_pdf_reviews($conn, $submission_id, $reviewers);
    }
    
    return ['success' => true, 'submission_id' => $submission_id, 'version' => $new_version];
}

/**
 * Get version chain information for a committee PDF submission
 * Similar to get_version_chain_info() in pdf_submission_helpers.php
 */
function get_committee_version_chain_info(mysqli $conn, int $submission_id): ?array
{
    $current = fetch_committee_pdf_submission($conn, $submission_id);
    if (!$current) {
        return null;
    }
    
    // Get previous version ID
    $previous_id = $current['parent_submission_id'] ?? null;
    
    // Get next version ID (child submission)
    $next_id = null;
    $next_stmt = $conn->prepare("
        SELECT id FROM committee_pdf_submissions 
        WHERE parent_submission_id = ? 
        LIMIT 1
    ");
    if ($next_stmt) {
        $next_stmt->bind_param('i', $submission_id);
        $next_stmt->execute();
        $next_result = $next_stmt->get_result();
        if ($next_row = $next_result->fetch_assoc()) {
            $next_id = (int)$next_row['id'];
        }
        if ($next_result) {
            $next_result->free();
        }
        $next_stmt->close();
    }
    
    // Get latest version in chain
    $latest_id = $submission_id;
    $temp_id = $submission_id;
    $safety_counter = 0;
    while ($safety_counter < 100) {
        $check_stmt = $conn->prepare("
            SELECT id FROM committee_pdf_submissions 
            WHERE parent_submission_id = ? 
            LIMIT 1
        ");
        if (!$check_stmt) {
            break;
        }
        $check_stmt->bind_param('i', $temp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_row = $check_result->fetch_assoc()) {
            $latest_id = (int)$check_row['id'];
            $temp_id = $latest_id;
        } else {
            $check_stmt->close();
            break;
        }
        if ($check_result) {
            $check_result->free();
        }
        $check_stmt->close();
        $safety_counter++;
    }
    
    $is_latest = ($latest_id === $submission_id);
    
    return [
        'current_version' => (int)($current['version_number'] ?? 1),
        'previous_id' => $previous_id,
        'next_id' => $next_id,
        'latest_id' => $latest_id,
        'is_latest' => $is_latest,
        'has_previous' => !is_null($previous_id),
        'has_next' => !is_null($next_id)
    ];
}

/**
 * Get the latest version ID in a submission chain
 */
function get_committee_latest_version_id(mysqli $conn, int $submission_id): int
{
    $info = get_committee_version_chain_info($conn, $submission_id);
    return $info ? (int)$info['latest_id'] : $submission_id;
}

/**
 * Submit final verdict for a committee PDF submission
 * Only committee chairperson can submit verdicts
 */
function submit_committee_final_verdict(
    mysqli $conn,
    int $submission_id,
    string $verdict,
    string $comments,
    int $chairperson_id
): array {
    $allowed_verdicts = ['passed', 'passed_minor_revisions', 'passed_major_revisions', 'redefense', 'failed'];
    
    if (!in_array($verdict, $allowed_verdicts, true)) {
        return ['success' => false, 'error' => 'Invalid verdict value.'];
    }
    
    $stmt = $conn->prepare("
        UPDATE committee_pdf_submissions
        SET final_verdict = ?,
            final_verdict_comments = ?,
            final_verdict_by = ?,
            final_verdict_at = NOW()
        WHERE id = ?
    ");
    
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param('ssii', $verdict, $comments, $chairperson_id, $submission_id);
    
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to submit verdict: ' . $stmt->error];
    }
    
    $stmt->close();
    return ['success' => true];
}

/**
 * Get verdict display configuration
 */
function get_verdict_config(string $verdict): array
{
    $configs = [
        'passed' => [
            'class' => 'alert-success',
            'icon' => 'bi-check-circle-fill',
            'title' => 'PASSED',
            'color' => 'success',
            'description' => 'Congratulations! Your defense has been approved.'
        ],
        'passed_minor_revisions' => [
            'class' => 'alert-info',
            'icon' => 'bi-pencil-square',
            'title' => 'PASSED WITH MINOR REVISIONS',
            'color' => 'info',
            'description' => 'Your defense passed, but minor revisions are required.'
        ],
        'passed_major_revisions' => [
            'class' => 'alert-warning',
            'icon' => 'bi-exclamation-triangle-fill',
            'title' => 'PASSED WITH MAJOR REVISIONS',
            'color' => 'warning',
            'description' => 'Your defense passed, but significant revisions are required.'
        ],
        'redefense' => [
            'class' => 'alert-warning',
            'icon' => 'bi-arrow-repeat',
            'title' => 'REDEFENSE REQUIRED',
            'color' => 'warning',
            'description' => 'You need to schedule and conduct another defense.'
        ],
        'failed' => [
            'class' => 'alert-danger',
            'icon' => 'bi-x-circle-fill',
            'title' => 'FAILED',
            'color' => 'danger',
            'description' => 'Your defense did not meet the required standards.'
        ]
    ];
    
    return $configs[$verdict] ?? $configs['passed'];
}

/**
 * Get verdict label for display
 */
function get_verdict_label(string $verdict): string
{
    $labels = [
        'pending' => 'Pending Review',
        'passed' => 'Passed',
        'passed_minor_revisions' => 'Passed with Minor Revisions',
        'passed_major_revisions' => 'Passed with Major Revisions',
        'redefense' => 'Redefense Required',
        'failed' => 'Failed'
    ];
    
    return $labels[$verdict] ?? ucwords(str_replace('_', ' ', $verdict));
}
