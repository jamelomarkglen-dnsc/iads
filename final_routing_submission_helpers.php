<?php
/**
 * Final Routing submission helpers (upload + review flow).
 */

require_once 'db.php';
require_once 'final_routing_helpers.php';
require_once 'defense_committee_helpers.php';

define('FINAL_ROUTING_UPLOAD_DIR', 'uploads/final_routing_submissions/');
define('FINAL_ROUTING_MAX_SIZE', 52428800);
define('FINAL_ROUTING_ALLOWED_MIME', ['application/pdf']);
define('FINAL_ROUTING_ALLOWED_EXT', ['pdf']);

function ensure_final_routing_directories(): void
{
    if (!is_dir(FINAL_ROUTING_UPLOAD_DIR)) {
        mkdir(FINAL_ROUTING_UPLOAD_DIR, 0755, true);
    }
    if (!is_writable(FINAL_ROUTING_UPLOAD_DIR)) {
        chmod(FINAL_ROUTING_UPLOAD_DIR, 0755);
    }
}

function validate_final_routing_file(array $file_array): array
{
    $errors = [];

    if (!isset($file_array['tmp_name']) || $file_array['tmp_name'] === '') {
        $errors[] = 'No file was uploaded.';
        return ['valid' => false, 'errors' => $errors];
    }

    if ($file_array['size'] > FINAL_ROUTING_MAX_SIZE) {
        $errors[] = 'File size exceeds maximum limit of 50MB.';
    }

    $file_ext = strtolower(pathinfo($file_array['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, FINAL_ROUTING_ALLOWED_EXT, true)) {
        $errors[] = 'Only PDF files are allowed.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = $finfo ? finfo_file($finfo, $file_array['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    if (!in_array($mime_type, FINAL_ROUTING_ALLOWED_MIME, true)) {
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

function generate_final_routing_filename(int $student_id, string $original_filename): string
{
    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    $base_name = pathinfo($original_filename, PATHINFO_FILENAME);
    $base_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base_name);
    $base_name = substr($base_name, 0, 30);
    return "final_routing_{$student_id}_{$timestamp}_{$random}_{$base_name}.pdf";
}

function upload_final_routing_file(array $file_array, int $student_id): array
{
    $validation = validate_final_routing_file($file_array);
    if (!$validation['valid']) {
        return ['success' => false, 'errors' => $validation['errors']];
    }

    ensure_final_routing_directories();

    $filename = generate_final_routing_filename($student_id, $file_array['name']);
    $file_path = FINAL_ROUTING_UPLOAD_DIR . $filename;

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

function final_routing_column_exists(mysqli $conn, string $table, string $column): bool
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

function ensureFinalRoutingTables(mysqli $conn): void
{
    ensureFinalRoutingHardboundTables($conn);
    ensureDefenseCommitteeRequestsTable($conn);

    if (!final_routing_column_exists($conn, 'final_routing_submissions', 'parent_submission_id')) {
        $conn->query("ALTER TABLE final_routing_submissions ADD COLUMN parent_submission_id INT NULL AFTER version_number");
    }
}

function fetch_latest_submission_id_for_student(mysqli $conn, int $student_id): int
{
    if ($student_id <= 0) {
        return 0;
    }
    $orderColumn = final_routing_column_exists($conn, 'submissions', 'created_at') ? 'created_at' : 'id';
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

function fetch_latest_approved_committee_for_student(mysqli $conn, int $student_id): ?array
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

function build_final_routing_reviewers(array $committee): array
{
    $reviewers = [];
    $mapping = [
        'adviser_id' => 'adviser',
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

function create_final_routing_submission(
    mysqli $conn,
    int $submission_id,
    int $student_id,
    array $committee,
    string $file_path,
    string $original_filename,
    int $file_size,
    string $mime_type,
    int $version_number,
    ?int $parent_submission_id = null
): array {
    $stmt = $conn->prepare("
        INSERT INTO final_routing_submissions
            (submission_id, student_id, adviser_id, chair_id, panel_member_one_id, panel_member_two_id,
             file_path, original_filename, file_size, mime_type, status, version_number, parent_submission_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?, NULLIF(?, 0))
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $parentValue = (int)($parent_submission_id ?? 0);
    $stmt->bind_param(
        'iiiiiissisii',
        $submission_id,
        $student_id,
        $committee['adviser_id'],
        $committee['chair_id'],
        $committee['panel_member_one_id'],
        $committee['panel_member_two_id'],
        $file_path,
        $original_filename,
        $file_size,
        $mime_type,
        $version_number,
        $parentValue
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create submission: ' . $stmt->error];
    }
    $submission_id = (int)$stmt->insert_id;
    $stmt->close();
    return ['success' => true, 'submission_id' => $submission_id];
}

function fetch_final_routing_submission(mysqli $conn, int $submission_id): ?array
{
    if ($submission_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT
            s.*,
            CONCAT(u.firstname, ' ', u.lastname) AS student_name,
            u.email AS student_email
        FROM final_routing_submissions s
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

function fetch_final_routing_submissions_for_student(mysqli $conn, int $student_id, bool $latest_only = true): array
{
    if ($latest_only) {
        $stmt = $conn->prepare("
            SELECT *
            FROM final_routing_submissions
            WHERE student_id = ?
              AND NOT EXISTS (
                SELECT 1 FROM final_routing_submissions child
                WHERE child.parent_submission_id = final_routing_submissions.id
              )
            ORDER BY submitted_at DESC, id DESC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT *
            FROM final_routing_submissions
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

function fetch_final_routing_submissions_for_reviewer(mysqli $conn, int $reviewer_id, string $reviewer_role): array
{
    $stmt = $conn->prepare("
        SELECT
            s.*,
            r.status AS review_status,
            r.reviewer_role,
            CONCAT(u.firstname, ' ', u.lastname) AS student_name
        FROM final_routing_reviews r
        JOIN final_routing_submissions s ON s.id = r.submission_id
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

function replace_final_routing_reviews(mysqli $conn, int $submission_id, array $reviewers): void
{
    if ($submission_id <= 0) {
        return;
    }
    $conn->query("DELETE FROM final_routing_reviews WHERE submission_id = {$submission_id}");
    if (empty($reviewers)) {
        return;
    }
    $stmt = $conn->prepare("
        INSERT INTO final_routing_reviews (submission_id, reviewer_id, reviewer_role, status)
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

function fetch_final_routing_review_row(mysqli $conn, int $submission_id, int $reviewer_id, string $reviewer_role): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_routing_reviews
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

function mark_final_routing_review_status(mysqli $conn, int $submission_id, int $reviewer_id): void
{
    $stmt = $conn->prepare("
        UPDATE final_routing_reviews
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

function create_final_routing_revision_submission(
    mysqli $conn,
    int $student_id,
    int $parent_submission_id,
    string $file_path,
    string $original_filename,
    int $file_size,
    string $mime_type
): array {
    $parent = fetch_final_routing_submission($conn, $parent_submission_id);
    if (!$parent) {
        return ['success' => false, 'error' => 'Parent submission not found.'];
    }
    if ((int)$parent['student_id'] !== $student_id) {
        return ['success' => false, 'error' => 'You do not have permission to revise this submission.'];
    }

    $new_version = (int)($parent['version_number'] ?? 1) + 1;
    $committee = [
        'adviser_id' => (int)$parent['adviser_id'],
        'chair_id' => (int)$parent['chair_id'],
        'panel_member_one_id' => (int)$parent['panel_member_one_id'],
        'panel_member_two_id' => (int)$parent['panel_member_two_id'],
    ];

    $result = create_final_routing_submission(
        $conn,
        (int)$parent['submission_id'],
        $student_id,
        $committee,
        $file_path,
        $original_filename,
        $file_size,
        $mime_type,
        $new_version,
        $parent_submission_id
    );

    if (!$result['success']) {
        return $result;
    }

    $reviewers = build_final_routing_reviewers($committee);
    if (!empty($reviewers)) {
        replace_final_routing_reviews($conn, (int)$result['submission_id'], $reviewers);
    }

    return ['success' => true, 'submission_id' => $result['submission_id'], 'version' => $new_version];
}

function get_final_routing_version_chain_info(mysqli $conn, int $submission_id): ?array
{
    $current = fetch_final_routing_submission($conn, $submission_id);
    if (!$current) {
        return null;
    }

    $previous_id = $current['parent_submission_id'] ?? null;

    $next_id = null;
    $next_stmt = $conn->prepare("
        SELECT id FROM final_routing_submissions
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

    $latest_id = $submission_id;
    $temp_id = $submission_id;
    $safety_counter = 0;
    while ($safety_counter < 100) {
        $check_stmt = $conn->prepare("
            SELECT id FROM final_routing_submissions
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
        'has_next' => !is_null($next_id),
    ];
}

function get_final_routing_latest_version_id(mysqli $conn, int $submission_id): int
{
    $info = get_final_routing_version_chain_info($conn, $submission_id);
    return $info ? (int)$info['latest_id'] : $submission_id;
}

function set_final_routing_verdict(
    mysqli $conn,
    int $submission_id,
    string $status,
    string $review_notes,
    int $chairperson_id
): array {
    $allowed = ['Passed', 'Needs Revision'];
    if (!in_array($status, $allowed, true)) {
        return ['success' => false, 'error' => 'Invalid verdict value.'];
    }

    $stmt = $conn->prepare("
        UPDATE final_routing_submissions
        SET status = ?,
            review_notes = ?,
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('ssii', $status, $review_notes, $chairperson_id, $submission_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to submit verdict: ' . $stmt->error];
    }
    $stmt->close();
    return ['success' => true];
}

function final_routing_status_badge(string $status): string
{
    $status = trim($status);
    return match ($status) {
        'Passed' => 'bg-success-subtle text-success',
        'Needs Revision' => 'bg-warning-subtle text-warning',
        default => 'bg-secondary-subtle text-secondary',
    };
}

function final_routing_verdict_label(string $status): string
{
    return match (trim($status)) {
        'Passed' => 'Passed',
        'Needs Revision' => 'Needs Revision',
        default => 'Submitted',
    };
}
