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

function ensure_final_hardbound_directories(): void
{
    if (!is_dir(FINAL_HARDBOUND_UPLOAD_DIR)) {
        mkdir(FINAL_HARDBOUND_UPLOAD_DIR, 0755, true);
    }
    if (!is_writable(FINAL_HARDBOUND_UPLOAD_DIR)) {
        chmod(FINAL_HARDBOUND_UPLOAD_DIR, 0755);
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
}

function fetch_latest_submission_id_for_student(mysqli $conn, int $student_id): int
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
          AND frs.status = 'Passed'
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
        SELECT fhs.*, CONCAT(u.firstname, ' ', u.lastname) AS student_name, s.title AS submission_title
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
    return match (trim($status)) {
        'Verified' => 'bg-success-subtle text-success',
        'Rejected' => 'bg-danger-subtle text-danger',
        'Under Review' => 'bg-warning-subtle text-warning',
        'Submitted' => 'bg-info-subtle text-info',
        default => 'bg-secondary-subtle text-secondary',
    };
}
