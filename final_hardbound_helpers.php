<?php
/**
 * Final Hardbound helpers (upload + verification flow).
 */

require_once 'db.php';
require_once 'final_routing_helpers.php';
require_once 'final_concept_helpers.php';
require_once 'defense_committee_helpers.php';

define('FINAL_HARDBOUND_UPLOAD_DIR', 'uploads/final_hardbound_submissions/');
define('FINAL_HARDBOUND_MAX_SIZE', 52428800);
define('FINAL_HARDBOUND_ALLOWED_MIME', ['application/pdf']);
define('FINAL_HARDBOUND_ALLOWED_EXT', ['pdf']);
define('FINAL_HARDBOUND_ARCHIVE_UPLOAD_DIR', 'uploads/archive_submissions/');

function ensure_final_hardbound_directories(): void
{
    if (!is_dir(FINAL_HARDBOUND_UPLOAD_DIR)) {
        mkdir(FINAL_HARDBOUND_UPLOAD_DIR, 0755, true);
    }
    if (!is_writable(FINAL_HARDBOUND_UPLOAD_DIR)) {
        chmod(FINAL_HARDBOUND_UPLOAD_DIR, 0755);
    }
}

function ensure_final_hardbound_archive_directories(): void
{
    if (!is_dir(FINAL_HARDBOUND_ARCHIVE_UPLOAD_DIR)) {
        mkdir(FINAL_HARDBOUND_ARCHIVE_UPLOAD_DIR, 0755, true);
    }
    if (!is_writable(FINAL_HARDBOUND_ARCHIVE_UPLOAD_DIR)) {
        chmod(FINAL_HARDBOUND_ARCHIVE_UPLOAD_DIR, 0755);
    }
}

function validate_final_hardbound_file(array $file_array): array
{
    $errors = [];

    if (!isset($file_array['tmp_name']) || $file_array['tmp_name'] === '') {
        $errors[] = 'No file was uploaded.';
        return ['valid' => false, 'errors' => $errors];
    }

    if ($file_array['size'] > FINAL_HARDBOUND_MAX_SIZE) {
        $errors[] = 'File size exceeds maximum limit of 50MB.';
    }

    $file_ext = strtolower(pathinfo($file_array['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, FINAL_HARDBOUND_ALLOWED_EXT, true)) {
        $errors[] = 'Only PDF files are allowed.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = $finfo ? finfo_file($finfo, $file_array['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!in_array($mime_type, FINAL_HARDBOUND_ALLOWED_MIME, true)) {
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

function generate_final_hardbound_filename(int $student_id, string $original_filename): string
{
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    $base_name = pathinfo($original_filename, PATHINFO_FILENAME);
    $base_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base_name);
    $base_name = substr($base_name, 0, 30);
    return "final_hardbound_{$student_id}_{$timestamp}_{$random}_{$base_name}.pdf";
}

function generate_final_hardbound_archive_filename(int $student_id, string $original_filename): string
{
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    $base_name = pathinfo($original_filename, PATHINFO_FILENAME);
    $base_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base_name);
    $base_name = substr($base_name, 0, 30);
    return "final_hardbound_archive_{$student_id}_{$timestamp}_{$random}_{$base_name}.pdf";
}

function upload_final_hardbound_file(array $file_array, int $student_id): array
{
    $validation = validate_final_hardbound_file($file_array);
    if (!$validation['valid']) {
        return ['success' => false, 'errors' => $validation['errors']];
    }

    ensure_final_hardbound_directories();

    $filename = generate_final_hardbound_filename($student_id, $file_array['name']);
    $file_path = FINAL_HARDBOUND_UPLOAD_DIR . $filename;

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

function upload_final_hardbound_archive_file(array $file_array, int $student_id): array
{
    $validation = validate_final_hardbound_file($file_array);
    if (!$validation['valid']) {
        return ['success' => false, 'errors' => $validation['errors']];
    }

    ensure_final_hardbound_archive_directories();

    $filename = generate_final_hardbound_archive_filename($student_id, $file_array['name']);
    $file_path = FINAL_HARDBOUND_ARCHIVE_UPLOAD_DIR . $filename;

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

function hardbound_column_exists(mysqli $conn, string $table, string $column): bool
{
    $tableEscaped = $conn->real_escape_string($table);
    $columnEscaped = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$tableEscaped}'
          AND COLUMN_NAME = '{$columnEscaped}'
        LIMIT 1
    ";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

function ensureFinalHardboundTables(mysqli $conn): void
{
    ensureFinalRoutingHardboundTables($conn);
    ensureDefenseCommitteeRequestsTable($conn);
    ensureFinalHardboundArchiveUploadsTable($conn);
    if (!hardbound_column_exists($conn, 'final_routing_submissions', 'parent_submission_id')) {
        $conn->query("ALTER TABLE final_routing_submissions ADD COLUMN parent_submission_id INT NULL AFTER version_number");
    }
}

function ensureFinalHardboundArchiveUploadsTable(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS final_hardbound_archive_uploads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hardbound_submission_id INT NOT NULL,
            submission_id INT NOT NULL,
            student_id INT NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_size INT NULL,
            mime_type VARCHAR(100) NULL,
            status ENUM('Pending','Archived') DEFAULT 'Pending',
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at TIMESTAMP NULL DEFAULT NULL,
            archived_by INT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_hardbound_archive_upload (hardbound_submission_id),
            INDEX idx_archive_submission (submission_id),
            INDEX idx_archive_student (student_id),
            INDEX idx_archive_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

function fetch_final_hardbound_archive_upload(mysqli $conn, int $hardbound_submission_id): ?array
{
    if ($hardbound_submission_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT *
        FROM final_hardbound_archive_uploads
        WHERE hardbound_submission_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $hardbound_submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_pending_final_hardbound_archive_upload_for_submission(mysqli $conn, int $submission_id): ?array
{
    if ($submission_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT fha.*, fhs.id AS hardbound_submission_id
        FROM final_hardbound_archive_uploads fha
        JOIN final_hardbound_submissions fhs ON fhs.id = fha.hardbound_submission_id
        WHERE fhs.submission_id = ?
          AND fhs.status IN ('Passed','Verified')
          AND fha.status = 'Pending'
          AND NOT EXISTS (
            SELECT 1
            FROM final_hardbound_submissions newer
            WHERE newer.submission_id = fhs.submission_id
              AND newer.id > fhs.id
          )
        ORDER BY fha.uploaded_at DESC, fha.id DESC
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

function fetch_final_hardbound_submission(mysqli $conn, int $hardbound_submission_id): ?array
{
    if ($hardbound_submission_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT *
        FROM final_hardbound_submissions
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $hardbound_submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_latest_submission_id_for_student_hardbound(mysqli $conn, int $student_id): int
{
    if ($student_id <= 0) {
        return 0;
    }
    $orderColumn = hardbound_column_exists($conn, 'submissions', 'created_at') ? 'created_at' : 'id';
    $stmt = $conn->prepare("
        SELECT id
        FROM submissions
        WHERE student_id = ?
        ORDER BY {$orderColumn} DESC, id DESC
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

function fetch_latest_passed_final_routing(mysqli $conn, int $student_id): ?array
{
    $stmt = $conn->prepare("
        SELECT frs.*
        FROM final_routing_submissions frs
        WHERE frs.student_id = ?
          AND LOWER(TRIM(frs.status)) = 'passed'
          AND NOT EXISTS (
            SELECT 1 FROM final_routing_submissions child
            WHERE child.parent_submission_id = frs.id
          )
        ORDER BY frs.reviewed_at DESC, frs.submitted_at DESC, frs.id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_latest_final_hardbound_submission(mysqli $conn, int $student_id): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_hardbound_submissions
        WHERE student_id = ?
        ORDER BY submitted_at DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_final_hardbound_submissions_for_student(mysqli $conn, int $student_id): array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_hardbound_submissions
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

function fetch_final_hardbound_submissions_for_adviser(mysqli $conn, int $adviser_id): array
{
    $stmt = $conn->prepare("
        SELECT fhs.*,
               CONCAT(u.firstname, ' ', u.lastname) AS student_name,
               u.program AS student_program,
               u.department AS student_department,
               u.college AS student_college,
               s.title AS submission_title
        FROM final_hardbound_submissions fhs
        JOIN users u ON u.id = fhs.student_id
        LEFT JOIN submissions s ON s.id = fhs.submission_id
        WHERE EXISTS (
            SELECT 1
            FROM defense_committee_requests dcr
            WHERE dcr.student_id = fhs.student_id
              AND dcr.adviser_id = ?
              AND dcr.status = 'Approved'
        )
        ORDER BY fhs.submitted_at DESC, fhs.id DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $adviser_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function create_final_hardbound_submission(
    mysqli $conn,
    int $submission_id,
    int $student_id,
    ?int $routing_submission_id,
    string $file_path,
    string $original_filename,
    int $file_size,
    string $mime_type
): array {
    $stmt = $conn->prepare("
        INSERT INTO final_hardbound_submissions
            (submission_id, routing_submission_id, student_id, file_path, original_filename, file_size, mime_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Submitted')
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $routingValue = $routing_submission_id ?: null;
    $stmt->bind_param(
        'iiissis',
        $submission_id,
        $routingValue,
        $student_id,
        $file_path,
        $original_filename,
        $file_size,
        $mime_type
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create submission: ' . $stmt->error];
    }
    $new_id = (int)$stmt->insert_id;
    $stmt->close();
    return ['success' => true, 'submission_id' => $new_id];
}

function find_existing_signature_path(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $base = 'uploads/signatures/user_' . $userId . '.';
    foreach (['png', 'jpg', 'jpeg'] as $ext) {
        $path = $base . $ext;
        if (is_file($path)) {
            return $path;
        }
    }
    return '';
}

function fetch_latest_hardbound_committee_for_student(mysqli $conn, int $student_id): ?array
{
    if ($student_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT *
        FROM defense_committee_requests
        WHERE student_id = ? AND status = 'Approved'
        ORDER BY reviewed_at DESC, requested_at DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function build_final_hardbound_committee_reviewers(array $committee): array
{
    $reviewers = [];
    $mapping = [
        'chair_id' => 'committee_chairperson',
        'panel_member_one_id' => 'panel',
        'panel_member_two_id' => 'panel',
    ];
    $seen = [];
    foreach ($mapping as $key => $role) {
        $userId = (int)($committee[$key] ?? 0);
        if ($userId <= 0 || isset($seen[$userId])) {
            continue;
        }
        $seen[$userId] = true;
        $reviewers[] = [
            'reviewer_id' => $userId,
            'reviewer_role' => $role,
        ];
    }
    return $reviewers;
}

function fetch_final_hardbound_committee_request(mysqli $conn, int $hardbound_submission_id): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_hardbound_committee_requests
        WHERE hardbound_submission_id = ?
        ORDER BY requested_at DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $hardbound_submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_final_hardbound_committee_request_by_id(mysqli $conn, int $request_id): ?array
{
    $stmt = $conn->prepare("
        SELECT r.*, s.student_id, s.file_path, s.original_filename, s.status AS submission_status,
               s.submitted_at, s.review_notes,
               u.firstname, u.lastname, u.program, u.department, u.college,
               sub.title AS submission_title,
               adv.firstname AS adviser_firstname,
               adv.lastname AS adviser_lastname
        FROM final_hardbound_committee_requests r
        JOIN final_hardbound_submissions s ON s.id = r.hardbound_submission_id
        JOIN users u ON u.id = s.student_id
        LEFT JOIN submissions sub ON sub.id = s.submission_id
        LEFT JOIN users adv ON adv.id = r.adviser_id
        WHERE r.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_final_hardbound_committee_reviews(mysqli $conn, int $request_id): array
{
    $stmt = $conn->prepare("
        SELECT r.*, CONCAT(u.firstname, ' ', u.lastname) AS reviewer_name
        FROM final_hardbound_committee_reviews r
        JOIN users u ON u.id = r.reviewer_id
        WHERE r.request_id = ?
        ORDER BY FIELD(r.reviewer_role, 'committee_chairperson','panel'), u.firstname, u.lastname
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function fetch_final_hardbound_committee_review_row(mysqli $conn, int $request_id, int $reviewer_id): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_hardbound_committee_reviews
        WHERE request_id = ? AND reviewer_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $request_id, $reviewer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_final_hardbound_committee_requests_for_reviewer(mysqli $conn, int $reviewer_id): array
{
    $stmt = $conn->prepare("
        SELECT r.*, s.file_path, s.original_filename, s.status AS submission_status,
               s.submitted_at,
               u.firstname, u.lastname,
               sub.title AS submission_title,
               adv.firstname AS adviser_firstname,
               adv.lastname AS adviser_lastname,
               rv.status AS review_status,
               rv.reviewed_at,
               rv.signature_path
        FROM final_hardbound_committee_reviews rv
        JOIN final_hardbound_committee_requests r ON r.id = rv.request_id
        JOIN final_hardbound_submissions s ON s.id = r.hardbound_submission_id
        JOIN users u ON u.id = s.student_id
        LEFT JOIN submissions sub ON sub.id = s.submission_id
        LEFT JOIN users adv ON adv.id = r.adviser_id
        WHERE rv.reviewer_id = ?
        ORDER BY r.requested_at DESC, r.id DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $reviewer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function create_final_hardbound_committee_request(
    mysqli $conn,
    int $hardbound_submission_id,
    int $adviser_id,
    string $remarks,
    string $adviser_signature_path,
    array $committee
): array {
    $existing = $conn->prepare("
        SELECT id
        FROM final_hardbound_committee_requests
        WHERE hardbound_submission_id = ? AND status IN ('Pending','Passed','Approved')
        ORDER BY requested_at DESC, id DESC
        LIMIT 1
    ");
    if ($existing) {
        $existing->bind_param('i', $hardbound_submission_id);
        $existing->execute();
        $res = $existing->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $existing->close();
        if ($row) {
            return ['success' => false, 'error' => 'A committee request is already pending or approved for this submission.'];
        }
    }

    $reviewers = build_final_hardbound_committee_reviewers($committee);
    if (empty($reviewers)) {
        return ['success' => false, 'error' => 'No committee reviewers found for this student.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO final_hardbound_committee_requests
            (hardbound_submission_id, adviser_id, status, remarks, adviser_signature_path)
        VALUES (?, ?, 'Pending', ?, ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('iiss', $hardbound_submission_id, $adviser_id, $remarks, $adviser_signature_path);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create committee request: ' . $stmt->error];
    }
    $requestId = (int)$stmt->insert_id;
    $stmt->close();
    if (!empty($reviewers)) {
        $reviewStmt = $conn->prepare("
            INSERT INTO final_hardbound_committee_reviews
                (request_id, reviewer_id, reviewer_role, status)
            VALUES (?, ?, ?, 'Pending')
        ");
        if ($reviewStmt) {
            foreach ($reviewers as $reviewer) {
                $reviewStmt->bind_param(
                    'iis',
                    $requestId,
                    $reviewer['reviewer_id'],
                    $reviewer['reviewer_role']
                );
                $reviewStmt->execute();
            }
            $reviewStmt->close();
        }
    }

    $conn->query("
        UPDATE final_hardbound_submissions
        SET status = 'Under Review'
        WHERE id = {$hardbound_submission_id}
    ");

    return ['success' => true, 'request_id' => $requestId];
}

function update_final_hardbound_committee_review(
    mysqli $conn,
    int $request_id,
    int $reviewer_id,
    string $status,
    string $remarks,
    string $signature_path
): array {
    $allowed = ['Passed', 'Needs Revision'];
    if (!in_array($status, $allowed, true)) {
        return ['success' => false, 'error' => 'Invalid review status.'];
    }

    if ($signature_path !== '') {
        $stmt = $conn->prepare("
            UPDATE final_hardbound_committee_reviews
            SET status = ?, remarks = ?, signature_path = ?, reviewed_at = NOW()
            WHERE request_id = ? AND reviewer_id = ?
        ");
        if (!$stmt) {
            return ['success' => false, 'error' => 'Unable to prepare review update.'];
        }
        $stmt->bind_param('sssii', $status, $remarks, $signature_path, $request_id, $reviewer_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE final_hardbound_committee_reviews
            SET status = ?, remarks = ?, reviewed_at = NOW()
            WHERE request_id = ? AND reviewer_id = ?
        ");
        if (!$stmt) {
            return ['success' => false, 'error' => 'Unable to prepare review update.'];
        }
        $stmt->bind_param('ssii', $status, $remarks, $request_id, $reviewer_id);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Unable to save review.'];
    }
    $stmt->close();

    $summaryStmt = $conn->prepare("
        SELECT status
        FROM final_hardbound_committee_reviews
        WHERE request_id = ?
    ");
    $overall = 'Pending';
    if ($summaryStmt) {
        $summaryStmt->bind_param('i', $request_id);
        $summaryStmt->execute();
        $result = $summaryStmt->get_result();
        $statuses = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        if ($result) {
            $result->free();
        }
        $summaryStmt->close();

        $hasNeedsRevision = false;
        $allApproved = true;
        foreach ($statuses as $row) {
            $rowStatus = $row['status'] ?? 'Pending';
            if ($rowStatus === 'Approved') {
                $rowStatus = 'Passed';
            }
            if ($rowStatus === 'Needs Revision') {
                $hasNeedsRevision = true;
            }
            if ($rowStatus !== 'Passed') {
                $allApproved = false;
            }
        }
        if ($hasNeedsRevision) {
            $overall = 'Needs Revision';
        } elseif ($allApproved && !empty($statuses)) {
            $overall = 'Passed';
        }
    }

    $updateReq = $conn->prepare("
        UPDATE final_hardbound_committee_requests
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    if ($updateReq) {
        $updateReq->bind_param('si', $overall, $request_id);
        $updateReq->execute();
        $updateReq->close();
    }

    $submissionStatus = $overall === 'Passed' ? 'Passed' : ($overall === 'Needs Revision' ? 'Needs Revision' : 'Under Review');
    $submissionUpdate = $conn->prepare("
        UPDATE final_hardbound_submissions s
        JOIN final_hardbound_committee_requests r ON r.hardbound_submission_id = s.id
        SET s.status = ?, s.reviewed_at = NOW(), s.reviewed_by = ?, s.review_notes = ?
        WHERE r.id = ?
    ");
    if ($submissionUpdate) {
        $submissionUpdate->bind_param('sisi', $submissionStatus, $reviewer_id, $remarks, $request_id);
        $submissionUpdate->execute();
        $submissionUpdate->close();
    }

    return ['success' => true, 'overall_status' => $overall, 'submission_status' => $submissionStatus];
}

function fetch_final_hardbound_request(mysqli $conn, int $hardbound_submission_id): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_hardbound_requests
        WHERE hardbound_submission_id = ?
        ORDER BY requested_at DESC, id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $hardbound_submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function create_final_hardbound_request(
    mysqli $conn,
    int $hardbound_submission_id,
    int $adviser_id,
    int $program_chair_id,
    string $remarks
): array {
    $existing = $conn->prepare("
        SELECT id
        FROM final_hardbound_requests
        WHERE hardbound_submission_id = ? AND program_chair_id = ? AND status IN ('Pending','Verified')
        LIMIT 1
    ");
    if ($existing) {
        $existing->bind_param('ii', $hardbound_submission_id, $program_chair_id);
        $existing->execute();
        $res = $existing->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $existing->close();
        if ($row) {
            return ['success' => false, 'error' => 'A request is already pending or verified for this submission.'];
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO final_hardbound_requests
            (hardbound_submission_id, adviser_id, program_chair_id, status, remarks)
        VALUES (?, ?, ?, 'Pending', ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('iiis', $hardbound_submission_id, $adviser_id, $program_chair_id, $remarks);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create request: ' . $stmt->error];
    }
    $stmt->close();

    $conn->query("
        UPDATE final_hardbound_submissions
        SET status = 'Under Review'
        WHERE id = {$hardbound_submission_id} AND status = 'Submitted'
    ");

    return ['success' => true];
}

function fetch_pending_hardbound_requests_for_chair(mysqli $conn, int $program_chair_id): array
{
    $stmt = $conn->prepare("
        SELECT r.*, s.file_path, s.original_filename, s.status AS submission_status,
               s.submitted_at, s.review_notes,
               u.firstname, u.lastname,
               sub.title AS submission_title,
               adv.firstname AS adviser_firstname,
               adv.lastname AS adviser_lastname
        FROM final_hardbound_requests r
        JOIN final_hardbound_submissions s ON s.id = r.hardbound_submission_id
        JOIN users u ON u.id = s.student_id
        LEFT JOIN submissions sub ON sub.id = s.submission_id
        LEFT JOIN users adv ON adv.id = r.adviser_id
        WHERE r.program_chair_id = ? AND r.status = 'Pending'
        ORDER BY r.requested_at DESC, r.id DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $program_chair_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function update_final_hardbound_request(
    mysqli $conn,
    int $request_id,
    int $hardbound_submission_id,
    string $status,
    int $program_chair_id,
    string $remarks
): bool {
    $stmt = $conn->prepare("
        UPDATE final_hardbound_requests
        SET status = ?, verified_at = NOW(), remarks = ?
        WHERE id = ? AND program_chair_id = ?
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssii', $status, $remarks, $request_id, $program_chair_id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return false;
    }

    $submissionStatus = $status === 'Verified' ? 'Verified' : 'Rejected';
    $update = $conn->prepare("
        UPDATE final_hardbound_submissions
        SET status = ?, reviewed_at = NOW(), reviewed_by = ?, review_notes = ?
        WHERE id = ?
    ");
    if ($update) {
        $update->bind_param('sisi', $submissionStatus, $program_chair_id, $remarks, $hardbound_submission_id);
        $update->execute();
        $update->close();
    }
    return true;
}

function final_hardbound_status_badge(string $status): string
{
    $status = final_hardbound_display_status($status);
    return match ($status) {
        'Passed' => 'bg-success-subtle text-success',
        'Needs Revision' => 'bg-danger-subtle text-danger',
        'Pending' => 'bg-warning-subtle text-warning',
        'Under Review' => 'bg-warning-subtle text-warning',
        'Submitted' => 'bg-info-subtle text-info',
        default => 'bg-secondary-subtle text-secondary',
    };
}

function final_hardbound_display_status(string $status): string
{
    $status = trim($status);
    if ($status === 'Verified' || $status === 'Approved') {
        return 'Passed';
    }
    if ($status === 'Rejected') {
        return 'Needs Revision';
    }
    return $status !== '' ? $status : 'Pending';
}
