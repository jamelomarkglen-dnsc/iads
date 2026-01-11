<?php
declare(strict_types=1);

/**
 * Collection of helper utilities for managing student submissions, quick status updates,
 * and chair feedback conversations.
 */

/**
 * Central list of statuses that are considered valid for submissions.
 */
function submission_allowed_statuses(): array
{
    static $statuses = null;
    if ($statuses !== null) {
        return $statuses;
    }
    $statuses = [
        'Pending',
        'Reviewing',
        'Reviewer Assigning',
        'Under Review', // legacy support
        'Revision Required',
        'Approved',
        'Rejected',
        'Returned',
    ];
    return $statuses;
}

/**
 * Normalize human-entered statuses into one of the canonical labels.
 */
function normalize_submission_status(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'Pending';
    }

    $lookup = [
        'review' => 'Reviewing',
        'reviewing' => 'Reviewing',
        'in review' => 'Reviewing',
        'under review' => 'Reviewing',
        'assign reviewer' => 'Reviewer Assigning',
        'assigning reviewer' => 'Reviewer Assigning',
        'assign reviewers' => 'Reviewer Assigning',
        'reviewer assigning' => 'Reviewer Assigning',
        'reviewer assining' => 'Reviewer Assigning',
        'reviwer assining' => 'Reviewer Assigning',
        'pending review' => 'Pending',
    ];

    $key = strtolower($value);
    if (isset($lookup[$key])) {
        return $lookup[$key];
    }

    return $value;
}

/**
 * Ensure the submissions.status column is flexible enough for the new status labels.
 */
function ensure_submission_status_schema(mysqli $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $result = $conn->query("SHOW COLUMNS FROM submissions LIKE 'status'");
    if ($result) {
        $column = $result->fetch_assoc();
        $result->free();
        $type = strtolower((string)($column['Type'] ?? ''));
        if (str_contains($type, 'enum(')) {
            $conn->query("ALTER TABLE submissions MODIFY COLUMN status VARCHAR(75) NOT NULL DEFAULT 'Pending'");
        }
    }

    $checked = true;
}

/**
 * Quick helper to determine if a submissions column exists.
 */
function submissions_column_exists(mysqli $conn, string $column): bool
{
    static $cache = [];
    $key = strtolower($column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'submissions'
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        $cache[$key] = false;
        return false;
    }
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    $cache[$key] = $exists;
    return $exists;
}

/**
 * Fetch the essential submission record for downstream logic.
 */
function fetch_submission_summary(mysqli $conn, int $submissionId): ?array
{
    $fields = [
        's.id',
        's.title',
        's.status',
    ];

    if (submissions_column_exists($conn, 'student_id')) {
        $fields[] = 's.student_id';
    } else {
        $fields[] = 'NULL AS student_id';
    }

    if (submissions_column_exists($conn, 'type')) {
        $fields[] = 's.type';
    } else {
        $fields[] = 'NULL AS type';
    }

    $fieldSql = implode(', ', $fields);
    $sql = "SELECT {$fieldSql} FROM submissions s WHERE s.id = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $record ?: null;
}

/**
 * Update the submission status and record the status log entry.
 *
 * @return array{success: bool, error?: string, submission?: array, old_status?: string, new_status?: string}
 */
function change_submission_status(mysqli $conn, int $submissionId, string $newStatus, int $userId): array
{
    ensure_submission_status_schema($conn);
    $normalizedStatus = normalize_submission_status($newStatus);
    $allowed = submission_allowed_statuses();
    if (!in_array($normalizedStatus, $allowed, true)) {
        return ['success' => false, 'error' => 'Invalid status selected.'];
    }

    $submission = fetch_submission_summary($conn, $submissionId);
    if (!$submission) {
        return ['success' => false, 'error' => 'Submission not found.'];
    }

    $oldStatus = $submission['status'] ?? 'Pending';
    if ($oldStatus === $normalizedStatus) {
        return [
            'success' => true,
            'submission' => $submission,
            'old_status' => $oldStatus,
            'new_status' => $normalizedStatus,
        ];
    }

    $updateSql = submissions_column_exists($conn, 'updated_at')
        ? "UPDATE submissions SET status = ?, updated_at = NOW() WHERE id = ?"
        : "UPDATE submissions SET status = ? WHERE id = ?";

    $stmt = $conn->prepare($updateSql);
    if (!$stmt) {
        return ['success' => false, 'error' => 'Unable to prepare update statement.'];
    }
    $stmt->bind_param('si', $normalizedStatus, $submissionId);
    $success = $stmt->execute();
    $stmt->close();
    if (!$success) {
        return ['success' => false, 'error' => 'Unable to update submission status.'];
    }

    $submission['status'] = $normalizedStatus;

    $logStmt = $conn->prepare("
        INSERT INTO status_logs (submission_id, updated_by, old_status, new_status)
        VALUES (?, ?, ?, ?)
    ");
    if ($logStmt) {
        $logStmt->bind_param('iiss', $submissionId, $userId, $oldStatus, $normalizedStatus);
        $logStmt->execute();
        $logStmt->close();
    }

    return [
        'success' => true,
        'submission' => $submission,
        'old_status' => $oldStatus,
        'new_status' => $normalizedStatus,
    ];
}

/**
 * Ensure the submission_feedback table exists.
 */
function ensure_submission_feedback_table(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'submission_feedback'");
    if ($tableCheck) {
        $exists = $tableCheck->num_rows > 0;
        $tableCheck->free();
        if ($exists) {
            $ensured = true;
            return;
        }
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS submission_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            student_id INT NOT NULL,
            chair_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_submission_feedback_submission (submission_id),
            INDEX idx_submission_feedback_student (student_id),
            CONSTRAINT submission_feedback_fk_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            CONSTRAINT submission_feedback_fk_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT submission_feedback_fk_chair FOREIGN KEY (chair_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";

    $conn->query($sql);
    $ensured = true;
}

/**
 * Insert a fresh chair feedback record.
 *
 * @return array{success: bool, error?: string, submission?: array}
 */
function create_submission_feedback(mysqli $conn, int $submissionId, int $chairId, string $message): array
{
    ensure_submission_feedback_table($conn);
    $submission = fetch_submission_summary($conn, $submissionId);
    if (!$submission) {
        return ['success' => false, 'error' => 'Submission not found.'];
    }
    $studentId = isset($submission['student_id']) ? (int)$submission['student_id'] : 0;
    if ($studentId <= 0) {
        return ['success' => false, 'error' => 'The submission is missing a student reference.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO submission_feedback (submission_id, student_id, chair_id, message)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Unable to prepare feedback insert statement.'];
    }
    $stmt->bind_param('iiis', $submissionId, $studentId, $chairId, $message);
    $success = $stmt->execute();
    $stmt->close();

    if (!$success) {
        return ['success' => false, 'error' => 'Unable to record feedback.'];
    }

    return ['success' => true, 'submission' => $submission];
}

/**
 * Fetch the latest feedback entries keyed by submission id.
 *
 * @return array<int, array<int, array<string,mixed>>>
 */
function fetch_submission_feedback_for_submissions(mysqli $conn, array $submissionIds, int $limitPerSubmission = 2): array
{
    if (empty($submissionIds)) {
        return [];
    }
    ensure_submission_feedback_table($conn);
    $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
    $types = str_repeat('i', count($submissionIds));
    $limit = max(1, $limitPerSubmission);

    $sql = "
        SELECT f.id,
               f.submission_id,
               f.message,
               f.created_at,
               CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS chair_name
        FROM submission_feedback f
        LEFT JOIN users u ON u.id = f.chair_id
        WHERE f.submission_id IN ({$placeholders})
        ORDER BY f.submission_id, f.created_at DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$submissionIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $grouped = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $submissionId = (int)$row['submission_id'];
            if (!isset($grouped[$submissionId])) {
                $grouped[$submissionId] = [];
            }
            if (count($grouped[$submissionId]) < $limit) {
                $grouped[$submissionId][] = $row;
            }
        }
        $result->free();
    }
    $stmt->close();

    return $grouped;
}

/**
 * Provide a recent feedback feed for the student dashboard.
 *
 * @return array<int, array<string,mixed>>
 */
function fetch_submission_feedback_for_student(mysqli $conn, int $studentId, int $limit = 5): array
{
    ensure_submission_feedback_table($conn);
    $limit = max(1, $limit);

    $sql = "
        SELECT f.id,
               f.submission_id,
               f.message,
               f.created_at,
               s.title AS submission_title,
               CONCAT(COALESCE(pc.firstname, ''), ' ', COALESCE(pc.lastname, '')) AS chair_name
        FROM submission_feedback f
        JOIN submissions s ON s.id = f.submission_id
        LEFT JOIN users pc ON pc.id = f.chair_id
        WHERE f.student_id = ?
        ORDER BY f.created_at DESC
        LIMIT {$limit}
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $entries;
}
