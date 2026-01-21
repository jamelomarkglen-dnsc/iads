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
            reply_user_role ENUM('student','adviser') NOT NULL,
            reply_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_committee_annotation (annotation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

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

function fetch_committee_pdf_submissions_for_student(mysqli $conn, int $student_id): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM committee_pdf_submissions
        WHERE student_id = ?
        ORDER BY submitted_at DESC, id DESC
    ");
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

