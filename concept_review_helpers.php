<?php

/**
 * Shared helper utilities for concept paper reviewer assignments and evaluations.
 */

/**
 * Ensure the reviewer assignment and review tables exist.
 */
function ensureConceptReviewTables(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $assignmentSql = "
        CREATE TABLE IF NOT EXISTS concept_reviewer_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            concept_paper_id INT NOT NULL,
            student_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            reviewer_role VARCHAR(50) NOT NULL,
            status ENUM('pending','in_progress','completed','declined') NOT NULL DEFAULT 'pending',
            assigned_by INT DEFAULT NULL,
            instructions TEXT NULL,
            due_at DATE NULL,
            decline_reason TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_assignment (concept_paper_id, reviewer_id, reviewer_role),
            INDEX idx_reviewer (reviewer_id),
            INDEX idx_student (student_id),
            INDEX idx_role_status (reviewer_role, status),
            INDEX idx_due (due_at),
            CONSTRAINT fk_assignment_concept
                FOREIGN KEY (concept_paper_id) REFERENCES concept_papers(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_assignment_reviewer
                FOREIGN KEY (reviewer_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_assignment_student
                FOREIGN KEY (student_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";

    $reviewSql = "
        CREATE TABLE IF NOT EXISTS concept_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            concept_paper_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            reviewer_role VARCHAR(50) NOT NULL,
            score TINYINT NULL,
            recommendation VARCHAR(20) NULL,
            rank_order TINYINT NULL,
            is_preferred TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_review (assignment_id, concept_paper_id),
            INDEX idx_review_reviewer (reviewer_id),
            INDEX idx_review_concept (concept_paper_id),
            CONSTRAINT fk_review_assignment
                FOREIGN KEY (assignment_id) REFERENCES concept_reviewer_assignments(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";

    if (!$conn->query($assignmentSql)) {
        error_log('Unable to ensure concept_reviewer_assignments table: ' . $conn->error);
    } else {
        $declineColumnSql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'concept_reviewer_assignments'
              AND COLUMN_NAME = 'decline_reason'
            LIMIT 1
        ";
        $declineCheck = $conn->query($declineColumnSql);
        $hasDeclineColumn = $declineCheck && $declineCheck->num_rows > 0;
        if ($declineCheck) {
            $declineCheck->free();
        }
        if (!$hasDeclineColumn) {
            $alterAssignmentSql = "ALTER TABLE concept_reviewer_assignments ADD COLUMN decline_reason TEXT NULL AFTER due_at";
            if (!$conn->query($alterAssignmentSql)) {
                error_log('Unable to add decline_reason column to concept_reviewer_assignments: ' . $conn->error);
            }
        }
    }

    if (!$conn->query($reviewSql)) {
        error_log('Unable to ensure concept_reviews table: ' . $conn->error);
    } else {
        $rankColumnSql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'concept_reviews'
              AND COLUMN_NAME = 'rank_order'
            LIMIT 1
        ";
        $rankCheck = $conn->query($rankColumnSql);
        $hasRankColumn = $rankCheck && $rankCheck->num_rows > 0;
        if ($rankCheck) {
            $rankCheck->free();
        }
        if (!$hasRankColumn) {
            $alterSql = "ALTER TABLE concept_reviews ADD COLUMN rank_order TINYINT NULL AFTER recommendation";
            if (!$conn->query($alterSql)) {
                error_log('Unable to add rank_order column to concept_reviews: ' . $conn->error);
            }
        }

        if (!conceptReviewColumnExists($conn, 'concept_reviews', 'comment_suggestions')) {
            if (!$conn->query("ALTER TABLE concept_reviews ADD COLUMN comment_suggestions TEXT NULL AFTER notes")) {
                error_log('Unable to add comment_suggestions column to concept_reviews: ' . $conn->error);
            }
        }
        if (!conceptReviewColumnExists($conn, 'concept_reviews', 'adviser_interest')) {
            if (!$conn->query("ALTER TABLE concept_reviews ADD COLUMN adviser_interest TINYINT(1) NOT NULL DEFAULT 0 AFTER comment_suggestions")) {
                error_log('Unable to add adviser_interest column to concept_reviews: ' . $conn->error);
            }
        }
        if (!conceptReviewColumnExists($conn, 'concept_reviews', 'chair_feedback')) {
            if (!$conn->query("ALTER TABLE concept_reviews ADD COLUMN chair_feedback TEXT NULL AFTER adviser_interest")) {
                error_log('Unable to add chair_feedback column to concept_reviews: ' . $conn->error);
            }
        }
        if (!conceptReviewColumnExists($conn, 'concept_reviews', 'chair_feedback_at')) {
            if (!$conn->query("ALTER TABLE concept_reviews ADD COLUMN chair_feedback_at TIMESTAMP NULL DEFAULT NULL AFTER chair_feedback")) {
                error_log('Unable to add chair_feedback_at column to concept_reviews: ' . $conn->error);
            }
        }
        if (!conceptReviewColumnExists($conn, 'concept_reviews', 'chair_feedback_by')) {
            if (!$conn->query("ALTER TABLE concept_reviews ADD COLUMN chair_feedback_by INT NULL AFTER chair_feedback_at")) {
                error_log('Unable to add chair_feedback_by column to concept_reviews: ' . $conn->error);
            }
        }
    }

    $ensured = true;
}

function ensureResearchArchiveSupport(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS research_archive (
            id INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            student_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            doc_type VARCHAR(50) NOT NULL,
            publication_type VARCHAR(100) DEFAULT NULL,
            file_path VARCHAR(255) NOT NULL,
            keywords VARCHAR(255) DEFAULT NULL,
            abstract TEXT NULL,
            notes TEXT NULL,
            archived_by INT DEFAULT NULL,
            archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_submission (submission_id),
            INDEX idx_student (student_id),
            INDEX idx_doc_type (doc_type),
            CONSTRAINT fk_archive_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            CONSTRAINT fk_archive_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_archive_user FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $hasArchivedColumn = $conn->query("SHOW COLUMNS FROM submissions LIKE 'archived_at'");
    if ($hasArchivedColumn) {
        if ($hasArchivedColumn->num_rows === 0) {
            $conn->query("ALTER TABLE submissions ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL");
        }
        $hasArchivedColumn->free();
    }

    $ensured = true;
}
function ensureReviewerInviteFeedbackTable(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS reviewer_invite_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            student_id INT NOT NULL,
            concept_paper_id INT DEFAULT NULL,
            reviewer_role VARCHAR(50) DEFAULT NULL,
            reason TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_assignment (assignment_id),
            INDEX idx_student (student_id),
            INDEX idx_reviewer (reviewer_id),
            CONSTRAINT fk_feedback_assignment FOREIGN KEY (assignment_id) REFERENCES concept_reviewer_assignments(id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $conn->query($sql);
    $ensured = true;
}

function recordReviewerInviteFeedback(mysqli $conn, array $payload): void
{
    ensureReviewerInviteFeedbackTable($conn);
    $assignmentId = (int)($payload['assignment_id'] ?? 0);
    $reviewerId = (int)($payload['reviewer_id'] ?? 0);
    $studentId = (int)($payload['student_id'] ?? 0);
    $conceptId = isset($payload['concept_paper_id']) ? (int)$payload['concept_paper_id'] : null;
    $role = $payload['reviewer_role'] ?? null;
    $reason = trim((string)($payload['reason'] ?? ''));
    if ($assignmentId <= 0 || $reviewerId <= 0 || $studentId <= 0 || $reason === '') {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO reviewer_invite_feedback (assignment_id, reviewer_id, student_id, concept_paper_id, reviewer_role, reason)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }
    $conceptIdParam = $conceptId > 0 ? $conceptId : null;
    $roleParam = $role ?? null;
    $stmt->bind_param(
        'iiiiss',
        $assignmentId,
        $reviewerId,
        $studentId,
        $conceptIdParam,
        $roleParam,
        $reason
    );
    $stmt->execute();
    $stmt->close();
}

function ensureConceptReviewMessagesTable(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS concept_review_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            concept_paper_id INT NOT NULL,
            student_id INT NOT NULL,
            sender_id INT NOT NULL,
            sender_role VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_assignment (assignment_id),
            INDEX idx_student (student_id),
            CONSTRAINT fk_crm_assignment FOREIGN KEY (assignment_id) REFERENCES concept_reviewer_assignments(id) ON DELETE CASCADE,
            CONSTRAINT fk_crm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";

    $conn->query($sql);
    $ensured = true;
}

function saveConceptReviewMessage(mysqli $conn, array $payload): bool
{
    ensureConceptReviewMessagesTable($conn);

    $assignmentId = (int)($payload['assignment_id'] ?? 0);
    $conceptPaperId = (int)($payload['concept_paper_id'] ?? 0);
    $studentId = (int)($payload['student_id'] ?? 0);
    $senderId = (int)($payload['sender_id'] ?? 0);
    $senderRole = trim((string)($payload['sender_role'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));

    if ($assignmentId <= 0 || $conceptPaperId <= 0 || $studentId <= 0 || $senderId <= 0 || $senderRole === '' || $message === '') {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO concept_review_messages (assignment_id, concept_paper_id, student_id, sender_id, sender_role, message)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iiiiis', $assignmentId, $conceptPaperId, $studentId, $senderId, $senderRole, $message);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

function fetchConceptReviewMessagesByAssignments(mysqli $conn, array $assignmentIds): array
{
    ensureConceptReviewMessagesTable($conn);

    $assignmentIds = array_values(array_unique(array_filter(array_map('intval', $assignmentIds))));
    if (empty($assignmentIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
    $types = str_repeat('i', count($assignmentIds));
    $sql = "
        SELECT
            crm.id,
            crm.assignment_id,
            crm.concept_paper_id,
            crm.student_id,
            crm.sender_id,
            crm.sender_role,
            crm.message,
            crm.created_at,
            crm.updated_at,
            CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS sender_name,
            COALESCE(u.role, '') AS sender_account_role
        FROM concept_review_messages crm
        LEFT JOIN users u ON u.id = crm.sender_id
        WHERE crm.assignment_id IN ($placeholders)
        ORDER BY crm.assignment_id ASC, crm.created_at ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$assignmentIds);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $assignmentId = (int)($row['assignment_id'] ?? 0);
            if (!isset($messages[$assignmentId])) {
                $messages[$assignmentId] = [];
            }
            $messages[$assignmentId][] = $row;
        }
        $result->free();
    }
    $stmt->close();

    return $messages;
}

function fetchRemainingReviewerFeedback(mysqli $conn, int $limit = 6, ?int $reviewerId = null): array
{
    ensureReviewerInviteFeedbackTable($conn);

    $limit = max(1, min(50, $limit));
    $whereClause = '';
    $types = '';
    $params = [];

    if ($reviewerId !== null && $reviewerId > 0) {
        $whereClause = 'WHERE rif.reviewer_id = ?';
        $types = 'i';
        $params[] = $reviewerId;
    }

    $sql = "
        SELECT
            rif.id,
            rif.assignment_id,
            rif.reason,
            rif.created_at,
            rif.reviewer_role,
            rif.student_id,
            rif.reviewer_id,
            rif.concept_paper_id,
            cp.title AS concept_title,
            CONCAT(COALESCE(stu.firstname, ''), ' ', COALESCE(stu.lastname, '')) AS student_name,
            CONCAT(COALESCE(r.firstname, ''), ' ', COALESCE(r.lastname, '')) AS reviewer_name,
            COALESCE(r.email, '') AS reviewer_email
        FROM reviewer_invite_feedback rif
        LEFT JOIN concept_papers cp ON cp.id = rif.concept_paper_id
        LEFT JOIN users stu ON stu.id = rif.student_id
        LEFT JOIN users r ON r.id = rif.reviewer_id
        {$whereClause}
        ORDER BY rif.created_at DESC
        LIMIT ?
    ";

    $types .= 'i';
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * Lightweight helper to check if a column exists on a table (cached per request).
 */
function conceptReviewColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = "{$table}.{$column}";
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
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
    $cache[$key] = $exists;
    return $exists;
}

/**
 * Mirror concept titles from submissions into concept_papers so legacy features
 * (assignments, rankings) can keep referencing a consistent table.
 */
function syncConceptPapersFromSubmissions(mysqli $conn): void
{
    static $synced = false;
    if ($synced) {
        return;
    }
    $synced = true;

    $requiredColumns = [
        ['concept_papers', 'id'],
        ['concept_papers', 'description'],
        ['submissions', 'id'],
        ['submissions', 'student_id'],
        ['submissions', 'type'],
        ['submissions', 'concept_proposal_1'],
    ];
    foreach ($requiredColumns as [$table, $column]) {
        if (!conceptReviewColumnExists($conn, $table, $column)) {
            return;
        }
    }

    $hasProposal2 = conceptReviewColumnExists($conn, 'submissions', 'concept_proposal_2');
    $hasProposal3 = conceptReviewColumnExists($conn, 'submissions', 'concept_proposal_3');
    $hasCreatedAt = conceptReviewColumnExists($conn, 'submissions', 'created_at');

    $slotFields = [1];
    $proposalSelects = ["COALESCE(concept_proposal_1, '') AS concept_proposal_1"];
    if ($hasProposal2) {
        $slotFields[] = 2;
        $proposalSelects[] = "COALESCE(concept_proposal_2, '') AS concept_proposal_2";
    }
    if ($hasProposal3) {
        $slotFields[] = 3;
        $proposalSelects[] = "COALESCE(concept_proposal_3, '') AS concept_proposal_3";
    }

    $proposalSql = implode(",\n               ", $proposalSelects);
    $createdAtSql = $hasCreatedAt
        ? "COALESCE(created_at, CURRENT_TIMESTAMP) AS submission_created_at"
        : "CURRENT_TIMESTAMP AS submission_created_at";

    $submissionSql = "
        SELECT id,
               student_id,
               {$createdAtSql},
               {$proposalSql}
        FROM submissions
        WHERE type = 'Concept Paper'
    ";
    $submissionResult = $conn->query($submissionSql);
    if (!$submissionResult) {
        return;
    }

    $markerPrefix = 'submission_ref:';
    $existingMap = [];
    $existingStmt = $conn->prepare("
        SELECT id, description, title, student_id
        FROM concept_papers
        WHERE description LIKE CONCAT(?, '%')
    ");
    if ($existingStmt) {
        $existingStmt->bind_param('s', $markerPrefix);
        if ($existingStmt->execute()) {
            $existingRes = $existingStmt->get_result();
            if ($existingRes) {
                while ($row = $existingRes->fetch_assoc()) {
                    $description = $row['description'] ?? '';
                    if ($description === '' || strpos($description, $markerPrefix) !== 0) {
                        continue;
                    }
                    $existingMap[$description] = [
                        'id' => (int)($row['id'] ?? 0),
                        'title' => $row['title'] ?? '',
                        'student_id' => (int)($row['student_id'] ?? 0),
                    ];
                }
                $existingRes->free();
            }
        }
        $existingStmt->close();
    }

    $insertStmt = $conn->prepare("
        INSERT INTO concept_papers (title, description, student_id, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $updateStmt = $conn->prepare("
        UPDATE concept_papers
        SET title = ?, student_id = ?, created_at = ?
        WHERE id = ?
    ");
    $deleteStmt = $conn->prepare("DELETE FROM concept_papers WHERE id = ?");

    while ($row = $submissionResult->fetch_assoc()) {
        $submissionId = (int)($row['id'] ?? 0);
        $studentId = (int)($row['student_id'] ?? 0);
        if ($submissionId <= 0) {
            continue;
        }
        $createdAt = $row['submission_created_at'] ?? date('Y-m-d H:i:s');
        if (!$createdAt) {
            $createdAt = date('Y-m-d H:i:s');
        }

        foreach ($slotFields as $slot) {
            $field = "concept_proposal_{$slot}";
            $title = trim((string)($row[$field] ?? ''));
            $marker = "{$markerPrefix}{$submissionId}:{$slot}";

            if ($title === '' || $studentId <= 0) {
                if (isset($existingMap[$marker]) && $deleteStmt) {
                    $deleteStmt->bind_param('i', $existingMap[$marker]['id']);
                    $deleteStmt->execute();
                    unset($existingMap[$marker]);
                }
                continue;
            }

            if (isset($existingMap[$marker])) {
                $existing = $existingMap[$marker];
                $needsUpdate = ($existing['title'] !== $title) || ($existing['student_id'] !== $studentId);
                if ($needsUpdate && $updateStmt) {
                    $updateStmt->bind_param('sisi', $title, $studentId, $createdAt, $existing['id']);
                    $updateStmt->execute();
                }
                unset($existingMap[$marker]);
                continue;
            }

            if ($insertStmt) {
                $insertStmt->bind_param('ssis', $title, $marker, $studentId, $createdAt);
                $insertStmt->execute();
            }
        }
    }
    $submissionResult->free();

    if ($deleteStmt && !empty($existingMap)) {
        foreach ($existingMap as $record) {
            $deleteStmt->bind_param('i', $record['id']);
            $deleteStmt->execute();
        }
    }

    if ($insertStmt) {
        $insertStmt->close();
    }
    if ($updateStmt) {
        $updateStmt->close();
    }
    if ($deleteStmt) {
        $deleteStmt->close();
    }
}

/**
 * Summarize adviser ranking results per concept paper.
 *
 * @return array<int, array{
 *   concept_paper_id:int,
 *   student_id:int,
 *   student_name:string,
 *   title:string,
 *   rank_order:int,
 *   adviser_name:string,
 *   updated_at:?string,
 *   notes:?string
 * }>
 */
function fetchAdviserConceptRankings(mysqli $conn): array
{
    ensureConceptReviewTables($conn);

    $sql = "
        SELECT
            cr.assignment_id,
            cr.concept_paper_id,
            cra.student_id,
            cra.reviewer_id,
            cra.reviewer_role,
            cr.rank_order,
            cr.notes,
            cr.updated_at,
            cr.chair_feedback,
            cr.chair_feedback_at,
            cr.chair_feedback_by,
            cp.title,
            CONCAT(COALESCE(s.firstname, ''), ' ', COALESCE(s.lastname, '')) AS student_name,
            CONCAT(COALESCE(r.firstname, ''), ' ', COALESCE(r.lastname, '')) AS adviser_name
        FROM concept_reviews cr
        INNER JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
        LEFT JOIN concept_papers cp ON cp.id = cr.concept_paper_id
        LEFT JOIN users s ON s.id = cra.student_id
        LEFT JOIN users r ON r.id = cra.reviewer_id
        WHERE cr.rank_order IS NOT NULL
          AND (cra.reviewer_role = 'adviser' OR cr.reviewer_role = 'adviser')
    ";

    $result = $conn->query($sql);
    $rankings = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $paperId = (int)($row['concept_paper_id'] ?? 0);
            if ($paperId <= 0) {
                continue;
            }
            $rankOrder = (int)($row['rank_order'] ?? 0);
            if ($rankOrder <= 0) {
                continue;
            }
            $current = $rankings[$paperId] ?? null;
            $updatedAt = $row['updated_at'] ?? null;
            $shouldReplace = false;
            if (!$current) {
                $shouldReplace = true;
            } elseif ($rankOrder < $current['rank_order']) {
                $shouldReplace = true;
            } elseif ($rankOrder === $current['rank_order']) {
                $currentUpdated = $current['updated_at'] ?? null;
                $shouldReplace = $updatedAt && (!$currentUpdated || strtotime($updatedAt) >= strtotime($currentUpdated));
            }

            if ($shouldReplace) {
                $rankings[$paperId] = [
                    'assignment_id' => (int)($row['assignment_id'] ?? 0),
                    'concept_paper_id' => $paperId,
                    'student_id' => (int)($row['student_id'] ?? 0),
                    'student_name' => trim($row['student_name'] ?? '') ?: 'Student',
                    'title' => $row['title'] ?? 'Untitled Concept',
                    'rank_order' => $rankOrder,
                    'adviser_name' => trim($row['adviser_name'] ?? '') ?: 'Adviser',
                    'updated_at' => $updatedAt,
                    'notes' => $row['notes'] ?? null,
                    'reviewer_id' => (int)($row['reviewer_id'] ?? 0),
                    'chair_feedback' => $row['chair_feedback'] ?? null,
                    'chair_feedback_at' => $row['chair_feedback_at'] ?? null,
                    'chair_feedback_by' => isset($row['chair_feedback_by']) ? (int)$row['chair_feedback_by'] : null,
                ];
            }
        }
        $result->free();
    }

    return $rankings;
}

/**
 * Bind a dynamic list of parameters to a prepared statement.
 *
 * @param array<int,mixed> $values
 */
function conceptReviewBindParams(mysqli_stmt $stmt, string $types, array &$values): bool
{
    if ($types === '' || empty($values)) {
        return true;
    }
    $bindParams = [$types];
    foreach ($values as $key => $value) {
        $bindParams[] = &$values[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

/**
 * Some campuses still map advisers to students via users.adviser_id/users.advisor_id.
 * This helper mirrors those relationships into concept_reviewer_assignments so that
 * advisers automatically get pending review invitations when concept titles exist.
 */
function syncAdviserAssignmentsFromUserLinks(mysqli $conn, int $adviserId): void
{
    static $synced = [];
    if ($adviserId <= 0 || isset($synced[$adviserId])) {
        return;
    }
    $synced[$adviserId] = true;

    $linkColumns = [];
    foreach (['adviser_id', 'advisor_id'] as $column) {
        if (conceptReviewColumnExists($conn, 'users', $column)) {
            $linkColumns[] = $column;
        }
    }
    if (empty($linkColumns)) {
        return;
    }

    $studentIds = [];
    foreach ($linkColumns as $column) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'student' AND {$column} = ?");
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $adviserId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $studentIds[] = (int)($row['id'] ?? 0);
                }
                $result->free();
            }
        }
        $stmt->close();
    }
    $studentIds = array_values(array_unique(array_filter($studentIds)));
    if (empty($studentIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $paperSql = "
        SELECT id AS concept_paper_id, student_id
        FROM concept_papers
        WHERE student_id IN ({$placeholders})
    ";
    $paperStmt = $conn->prepare($paperSql);
    if (!$paperStmt) {
        return;
    }
    $paperTypes = str_repeat('i', count($studentIds));
    conceptReviewBindParams($paperStmt, $paperTypes, $studentIds);
    $conceptPapers = [];
    if ($paperStmt->execute()) {
        $paperRes = $paperStmt->get_result();
        if ($paperRes) {
            while ($row = $paperRes->fetch_assoc()) {
                $conceptId = (int)($row['concept_paper_id'] ?? 0);
                $studentId = (int)($row['student_id'] ?? 0);
                if ($conceptId > 0 && $studentId > 0) {
                    $conceptPapers[$conceptId] = $studentId;
                }
            }
            $paperRes->free();
        }
    }
    $paperStmt->close();
    if (empty($conceptPapers)) {
        return;
    }

    $conceptIds = array_keys($conceptPapers);
    $conceptPlaceholders = implode(',', array_fill(0, count($conceptIds), '?'));
    $existingSql = "
        SELECT concept_paper_id
        FROM concept_reviewer_assignments
        WHERE reviewer_id = ? AND reviewer_role = 'adviser' AND concept_paper_id IN ({$conceptPlaceholders})
    ";
    $existingStmt = $conn->prepare($existingSql);
    if (!$existingStmt) {
        return;
    }
    $existingTypes = 'i' . str_repeat('i', count($conceptIds));
    $existingValues = array_merge([$adviserId], $conceptIds);
    conceptReviewBindParams($existingStmt, $existingTypes, $existingValues);
    $existingConcepts = [];
    if ($existingStmt->execute()) {
        $existingRes = $existingStmt->get_result();
        if ($existingRes) {
            while ($row = $existingRes->fetch_assoc()) {
                $existingConcepts[(int)($row['concept_paper_id'] ?? 0)] = true;
            }
            $existingRes->free();
        }
    }
    $existingStmt->close();

    $missingConcepts = array_diff_key($conceptPapers, $existingConcepts);
    if (empty($missingConcepts)) {
        return;
    }

    $insertStmt = $conn->prepare("
        INSERT INTO concept_reviewer_assignments (concept_paper_id, student_id, reviewer_id, reviewer_role, status, assigned_by)
        VALUES (?, ?, ?, 'adviser', 'pending', NULL)
        ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)
    ");
    if (!$insertStmt) {
        return;
    }
    foreach ($missingConcepts as $conceptId => $studentId) {
        $insertStmt->bind_param('iii', $conceptId, $studentId, $adviserId);
        $insertStmt->execute();
    }
    $insertStmt->close();
}

/**
 * Fetch concept paper previews for students linked to an adviser (used when no assignments yet).
 *
 * @return array<int,array{student_id:int,student_name:string,student_email:string,concepts:array<int,array{title:string,created_at:?string,has_title:bool}>}>
 */
function fetchAdviserConceptPreview(mysqli $conn, int $adviserId, int $studentLimit = 1, int $conceptLimit = 3): array
{
    if ($adviserId <= 0) {
        return [];
    }
    $linkColumns = [];
    foreach (['adviser_id', 'advisor_id'] as $column) {
        if (conceptReviewColumnExists($conn, 'users', $column)) {
            $linkColumns[] = $column;
        }
    }
    if (empty($linkColumns)) {
        return [];
    }

    $whereParts = array_map(fn($col) => "u.{$col} = ?", $linkColumns);
    $studentSql = "
        SELECT u.id,
               CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name,
               COALESCE(u.email, '') AS student_email
        FROM users u
        WHERE u.role = 'student' AND (" . implode(' OR ', $whereParts) . ")
        ORDER BY u.lastname, u.firstname
        LIMIT {$studentLimit}
    ";
    $studentStmt = $conn->prepare($studentSql);
    if (!$studentStmt) {
        return [];
    }
    $studentParams = array_fill(0, count($linkColumns), $adviserId);
    $studentTypes = str_repeat('i', count($linkColumns));
    conceptReviewBindParams($studentStmt, $studentTypes, $studentParams);
    $students = [];
    if ($studentStmt->execute()) {
        $studentRes = $studentStmt->get_result();
        if ($studentRes) {
            while ($row = $studentRes->fetch_assoc()) {
                $studentId = (int)($row['id'] ?? 0);
                if ($studentId > 0) {
                    $students[$studentId] = [
                        'student_id' => $studentId,
                        'student_name' => trim($row['student_name'] ?? '') ?: 'Student',
                        'student_email' => $row['student_email'] ?? '',
                        'concepts' => [],
                    ];
                }
            }
            $studentRes->free();
        }
    }
    $studentStmt->close();
    if (empty($students)) {
        return [];
    }

    $studentIds = array_keys($students);
    $placeholder = implode(',', array_fill(0, count($studentIds), '?'));
    $paperSql = "
        SELECT cp.id, cp.student_id, cp.title, cp.created_at
        FROM concept_papers cp
        WHERE cp.student_id IN ({$placeholder})
        ORDER BY cp.student_id ASC, cp.created_at ASC
    ";
    $paperStmt = $conn->prepare($paperSql);
    if ($paperStmt) {
        $paperTypes = str_repeat('i', count($studentIds));
        conceptReviewBindParams($paperStmt, $paperTypes, $studentIds);
        if ($paperStmt->execute()) {
            $paperRes = $paperStmt->get_result();
            if ($paperRes) {
                while ($row = $paperRes->fetch_assoc()) {
                    $studentId = (int)($row['student_id'] ?? 0);
                    if (!isset($students[$studentId])) {
                        continue;
                    }
                    if (count($students[$studentId]['concepts']) >= $conceptLimit) {
                        continue;
                    }
                    $students[$studentId]['concepts'][] = [
                        'title' => $row['title'] ?? 'Untitled Concept',
                        'created_at' => $row['created_at'] ?? null,
                        'has_title' => true,
                    ];
                }
                $paperRes->free();
            }
        }
        $paperStmt->close();
    }

    foreach ($students as &$student) {
        while (count($student['concepts']) < $conceptLimit) {
            $student['concepts'][] = [
                'title' => '',
                'created_at' => null,
                'has_title' => false,
            ];
        }
    }
    unset($student);

    return array_values($students);
}

/**
 * Build an index of reviewer assignments keyed by concept paper.
 *
 * @return array<int, array{all: array<int, array>, by_role: array<string, array>, latest_instructions:?string, latest_due_at:?string}>
 */
function fetchConceptAssignmentIndex(mysqli $conn): array
{
    ensureConceptReviewTables($conn);

    $sql = "
        SELECT id, concept_paper_id, student_id, reviewer_id, reviewer_role,
               status, instructions, due_at, updated_at
        FROM concept_reviewer_assignments
    ";
    $result = $conn->query($sql);
    $index = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $paperId = (int)($row['concept_paper_id'] ?? 0);
            if (!$paperId) {
                continue;
            }
            $role = $row['reviewer_role'] ?? 'faculty';
            $roleKey = $role === 'committee_chairperson' ? 'committee_chair' : $role;
            if (!isset($index[$paperId])) {
                $index[$paperId] = [
                    'all' => [],
                    'by_role' => [],
                    'latest_instructions' => null,
                    'latest_due_at' => null,
                ];
            }
            $assignment = [
                'id' => (int)($row['id'] ?? 0),
                'student_id' => (int)($row['student_id'] ?? 0),
                'reviewer_id' => (int)($row['reviewer_id'] ?? 0),
                'reviewer_role' => $role,
                'status' => $row['status'] ?? 'pending',
                'instructions' => $row['instructions'] ?? null,
                'due_at' => $row['due_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
            $index[$paperId]['all'][] = $assignment;
            if (!isset($index[$paperId]['by_role'][$roleKey])) {
                $index[$paperId]['by_role'][$roleKey] = [];
            }
            $index[$paperId]['by_role'][$roleKey][] = $assignment;

            if (!$index[$paperId]['latest_instructions'] && !empty($assignment['instructions'])) {
                $index[$paperId]['latest_instructions'] = $assignment['instructions'];
            }
            if (!$index[$paperId]['latest_due_at'] && !empty($assignment['due_at'])) {
                $index[$paperId]['latest_due_at'] = $assignment['due_at'];
            }
        }
        $result->free();
    }

    return $index;
}

/**
 * Aggregate assignment stats for dashboard widgets.
 *
 * @return array{total:int,pending:int,completed:int,due_soon:int}
 */
function getConceptAssignmentStats(mysqli $conn): array
{
    ensureConceptReviewTables($conn);

    $sql = "
        SELECT status,
               SUM(CASE WHEN status = 'pending' AND due_at IS NOT NULL AND due_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_soon
        FROM concept_reviewer_assignments
    ";

    $stats = [
        'total' => 0,
        'pending' => 0,
        'completed' => 0,
        'due_soon' => 0,
    ];

    $result = $conn->query("SELECT status, COUNT(*) AS total FROM concept_reviewer_assignments GROUP BY status");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $status = $row['status'] ?? 'pending';
            $count = (int)($row['total'] ?? 0);
            $stats['total'] += $count;
            if ($status === 'completed') {
                $stats['completed'] += $count;
            } elseif (in_array($status, ['pending', 'in_progress'], true)) {
                $stats['pending'] += $count;
            }
        }
        $result->free();
    }

    $dueSoonResult = $conn->query("
        SELECT COUNT(*) AS due_soon
        FROM concept_reviewer_assignments
        WHERE status IN ('pending', 'in_progress')
          AND due_at IS NOT NULL
          AND due_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    if ($dueSoonResult) {
        $row = $dueSoonResult->fetch_assoc();
        $stats['due_soon'] = (int)($row['due_soon'] ?? 0);
        $dueSoonResult->free();
    }

    return $stats;
}

/**
 * Build a reviewer directory grouped by role for quick lookups.
 *
 * @param array<int, string> $roles
 * @return array{flat: array<int, array>, by_role: array<string, array<int, array>>}
 */
function fetchReviewerDirectory(mysqli $conn, array $roles): array
{
    if (empty($roles)) {
        return ['flat' => [], 'by_role' => []];
    }

    $sanitizedRoles = array_map(static fn($role) => "'" . $conn->real_escape_string($role) . "'", $roles);
    $inClause = implode(',', $sanitizedRoles);
    $sql = "
        SELECT id, firstname, lastname, email, role, department, college
        FROM users
        WHERE role IN ({$inClause})
        ORDER BY role, lastname, firstname
    ";
    $directory = ['flat' => [], 'by_role' => []];
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['id'] ?? 0);
            $role = $row['role'] ?? '';
            if (!$id || !$role) {
                continue;
            }
            $directory['flat'][$id] = $row;
            if (!isset($directory['by_role'][$role])) {
                $directory['by_role'][$role] = [];
            }
            $directory['by_role'][$role][$id] = $row;
        }
        $result->free();
    }

    return $directory;
}

function parseSubmissionReference(?string $description): ?array
{
    $description = trim((string)$description);
    if ($description === '' || strpos($description, 'submission_ref:') !== 0) {
        return null;
    }
    $parts = explode(':', $description);
    if (count($parts) < 3) {
        return null;
    }
    $submissionId = (int)$parts[1];
    $slot = (int)$parts[2];
    if ($submissionId <= 0 || $slot < 1 || $slot > 3) {
        return null;
    }
    return ['submission_id' => $submissionId, 'slot' => $slot];
}

/**
 * Fetch reviewer assignments for a specific user (optionally by reviewer role).
 *
 * @return array<int,array<string,mixed>>
 */
function fetchReviewerAssignments(mysqli $conn, int $reviewerId, ?string $roleFilter = null): array
{
    ensureConceptReviewTables($conn);
    if ($reviewerId <= 0) {
        return [];
    }

    $sql = "
        SELECT
            cra.id AS assignment_id,
            cra.concept_paper_id,
            cra.student_id,
            cra.reviewer_id,
            cra.reviewer_role,
            cra.status,
            cra.instructions,
            cra.due_at,
            cra.created_at,
            cra.updated_at,
            cra.assigned_by,
            cra.decline_reason,
            cp.title,
            cp.created_at AS concept_created_at,
            cp.assigned_faculty,
            CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name,
            COALESCE(u.email, '') AS student_email,
            CONCAT(COALESCE(assigner.firstname, ''), ' ', COALESCE(assigner.lastname, '')) AS assigned_by_name,
            cr.score AS review_score,
            cr.recommendation AS review_recommendation,
            cr.rank_order AS review_rank_order,
            cr.is_preferred AS review_is_preferred,
            cr.notes AS review_notes,
            cr.comment_suggestions AS review_comment_suggestions,
            cr.adviser_interest AS review_adviser_interest,
            cr.updated_at AS review_updated_at,
            cp.description AS concept_description
        FROM concept_reviewer_assignments cra
        LEFT JOIN concept_papers cp ON cp.id = cra.concept_paper_id
        LEFT JOIN users u ON u.id = cra.student_id
        LEFT JOIN users assigner ON assigner.id = cra.assigned_by
        LEFT JOIN concept_reviews cr ON cr.assignment_id = cra.id
        WHERE cra.reviewer_id = ?
    ";
    if ($roleFilter !== null) {
        $sql .= " AND cra.reviewer_role = ?";
    }
    $sql .= "
        ORDER BY
            CASE WHEN cra.status = 'completed' THEN 1 ELSE 0 END,
            CASE WHEN cra.due_at IS NULL THEN 1 ELSE 0 END,
            cra.due_at ASC,
            cp.created_at ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($roleFilter !== null) {
        $stmt->bind_param('is', $reviewerId, $roleFilter);
    } else {
        $stmt->bind_param('i', $reviewerId);
    }

    $assignments = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $ref = parseSubmissionReference($row['concept_description'] ?? null);
                $submissionId = $ref['submission_id'] ?? 0;
                $proposalSlot = $ref['slot'] ?? 0;
                $assignments[] = [
                    'assignment_id' => (int)($row['assignment_id'] ?? 0),
                    'concept_paper_id' => (int)($row['concept_paper_id'] ?? 0),
                    'student_id' => (int)($row['student_id'] ?? 0),
                    'reviewer_role' => $row['reviewer_role'] ?? '',
                    'status' => $row['status'] ?? 'pending',
                    'instructions' => $row['instructions'] ?? null,
                    'due_at' => $row['due_at'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'assigned_by' => isset($row['assigned_by']) ? (int)$row['assigned_by'] : null,
                    'title' => $row['title'] ?? 'Untitled Concept',
                    'concept_created_at' => $row['concept_created_at'] ?? null,
                    'student_name' => trim($row['student_name'] ?? '') ?: 'Unknown Student',
                    'student_email' => $row['student_email'] ?? '',
                    'assigned_by_name' => trim($row['assigned_by_name'] ?? ''),
                    'review_score' => isset($row['review_score']) ? (int)$row['review_score'] : null,
                    'review_recommendation' => $row['review_recommendation'] ?? null,
                    'review_rank_order' => isset($row['review_rank_order']) ? (int)$row['review_rank_order'] : null,
                    'review_is_preferred' => isset($row['review_is_preferred']) ? (int)$row['review_is_preferred'] : 0,
                    'review_notes' => $row['review_notes'] ?? null,
                    'review_comment_suggestions' => $row['review_comment_suggestions'] ?? null,
                    'review_adviser_interest' => isset($row['review_adviser_interest']) ? (int)$row['review_adviser_interest'] : 0,
                    'review_updated_at' => $row['review_updated_at'] ?? null,
                    'decline_reason' => $row['decline_reason'] ?? null,
                    'submission_id' => $submissionId,
                    'proposal_slot' => $proposalSlot,
                    'manuscript_path' => '',
                    'manuscript_available' => false,
                ];
            }
            $result->free();
        }
    }
    $stmt->close();

    if (!empty($assignments) && conceptReviewColumnExists($conn, 'submissions', 'id')) {
        $submissionIds = array_values(array_unique(array_filter(array_map(
            static fn($assignment) => (int)($assignment['submission_id'] ?? 0),
            $assignments
        ))));
        $fileColumns = [];
        foreach ([1, 2, 3] as $slot) {
            $column = "concept_file_{$slot}";
            if (conceptReviewColumnExists($conn, 'submissions', $column)) {
                $fileColumns[$slot] = $column;
            }
        }
        if (!empty($submissionIds) && !empty($fileColumns)) {
            $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
            $columnSelect = implode(', ', array_map(
                static fn($column) => "s.{$column}",
                $fileColumns
            ));
            $fileSql = "SELECT s.id, {$columnSelect} FROM submissions s WHERE s.id IN ({$placeholders})";
            $fileStmt = $conn->prepare($fileSql);
            if ($fileStmt) {
                $types = str_repeat('i', count($submissionIds));
                $fileStmt->bind_param($types, ...$submissionIds);
                $fileStmt->execute();
                $fileRes = $fileStmt->get_result();
                $submissionFiles = [];
                if ($fileRes) {
                    while ($row = $fileRes->fetch_assoc()) {
                        $sid = (int)($row['id'] ?? 0);
                        if ($sid <= 0) {
                            continue;
                        }
                        foreach ($fileColumns as $slot => $column) {
                            $submissionFiles[$sid][$slot] = $row[$column] ?? '';
                        }
                    }
                    $fileRes->free();
                }
                $fileStmt->close();

                foreach ($assignments as &$assignment) {
                    $submissionId = (int)($assignment['submission_id'] ?? 0);
                    $slot = (int)($assignment['proposal_slot'] ?? 0);
                    $filePath = '';
                    if ($submissionId > 0 && $slot > 0 && isset($submissionFiles[$submissionId][$slot])) {
                        $filePath = (string)$submissionFiles[$submissionId][$slot];
                    }
                    $assignment['manuscript_path'] = $filePath;
                    $assignment['manuscript_available'] = ($filePath !== '' && is_file($filePath));
                }
                unset($assignment);
            }
        }
    }

    return $assignments;
}

/**
 * Group reviewer assignments per student for easier display.
 *
 * @param array<int,array<string,mixed>> $assignments
 * @return array<int,array<string,mixed>>
 */
function groupReviewerAssignmentsByStudent(array $assignments): array
{
    $groups = [];
    foreach ($assignments as $assignment) {
        $studentId = (int)($assignment['student_id'] ?? 0);
        if (!isset($groups[$studentId])) {
            $groups[$studentId] = [
                'student_id' => $studentId,
                'student_name' => $assignment['student_name'] ?? 'Unknown Student',
                'student_email' => $assignment['student_email'] ?? '',
                'items' => [],
            ];
        }
        $groups[$studentId]['items'][] = $assignment;
    }
    return $groups;
}

/**
 * Summarize reviewer assignments into counts for dashboard widgets.
 *
 * @param array<int,array<string,mixed>> $assignments
 * @return array{total:int,pending:int,completed:int,due_soon:int}
 */
function summarizeReviewerAssignments(array $assignments): array
{
    $stats = [
        'total' => count($assignments),
        'pending' => 0,
        'completed' => 0,
        'due_soon' => 0,
    ];

    foreach ($assignments as $assignment) {
        $status = $assignment['status'] ?? 'pending';
        if ($status === 'completed') {
            $stats['completed']++;
        } else {
            $stats['pending']++;
        }
        if (reviewerAssignmentDueSoon($assignment)) {
            $stats['due_soon']++;
        }
    }

    return $stats;
}

/**
 * Summarize how many assignments already have ranking data plus capture top choices.
 *
 * @param array<int,array<string,mixed>> $assignments
 * @return array{ranked:int,pending:int,top:int,top_picks:array<int,array<string,mixed>>}
 */
function summarizeReviewerRankingProgress(array $assignments): array
{
    $stats = [
        'ranked' => 0,
        'pending' => 0,
        'top' => 0,
        'top_picks' => [],
    ];

    foreach ($assignments as $assignment) {
        $rankOrder = isset($assignment['review_rank_order']) ? (int)$assignment['review_rank_order'] : 0;
        if ($rankOrder > 0) {
            $stats['ranked']++;
            if ($rankOrder === 1) {
                $stats['top']++;
                $stats['top_picks'][] = $assignment;
            }
        }
    }

    $stats['pending'] = max(0, count($assignments) - $stats['ranked']);

    return $stats;
}

/**
 * Identify assignments that are due soon (default 7 days).
 *
 * @param array<int,array<string,mixed>> $assignments
 * @return array<int,array<string,mixed>>
 */
function filterDueSoonReviewerAssignments(array $assignments, int $days = 7): array
{
    return array_values(array_filter(
        $assignments,
        fn(array $assignment): bool => reviewerAssignmentDueSoon($assignment, $days)
    ));
}

function reviewerAssignmentDueSoon(array $assignment, int $days = 7): bool
{
    if (($assignment['status'] ?? '') === 'completed') {
        return false;
    }
    $dueAt = $assignment['due_at'] ?? null;
    if (!$dueAt) {
        return false;
    }
    $timestamp = strtotime($dueAt);
    if (!$timestamp) {
        return false;
    }
    $now = time();
    $window = $days * 24 * 60 * 60;
    return $timestamp >= $now && ($timestamp - $now) <= $window;
}
