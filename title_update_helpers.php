<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/final_paper_helpers.php';

if (!defined('TITLE_UPDATE_STAGE_PRE_OUTLINE')) {
    define('TITLE_UPDATE_STAGE_PRE_OUTLINE', 'pre_outline');
}
if (!defined('TITLE_UPDATE_STAGE_POST_OUTLINE')) {
    define('TITLE_UPDATE_STAGE_POST_OUTLINE', 'post_outline');
}

if (!function_exists('title_update_table_exists')) {
    function title_update_table_exists(mysqli $conn, string $table): bool
    {
        $tableEscaped = $conn->real_escape_string($table);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$tableEscaped}'
            LIMIT 1
        ";
        $result = $conn->query($sql);
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    }
}

if (!function_exists('ensureTitleUpdateTable')) {
    function ensureTitleUpdateTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'title_update_requests'");
        $exists = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck) {
            $tableCheck->free();
        }
        if (!$exists) {
            $conn->query("
                CREATE TABLE IF NOT EXISTS title_update_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    stage ENUM('pre_outline','post_outline') NOT NULL,
                    old_title VARCHAR(255) NOT NULL,
                    new_title VARCHAR(255) NOT NULL,
                    status ENUM('applied','pending') NOT NULL DEFAULT 'applied',
                    applied_to VARCHAR(50) NULL,
                    source_submission_id INT NULL,
                    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    applied_at TIMESTAMP NULL DEFAULT NULL,
                    UNIQUE KEY uniq_title_update_stage (student_id, stage),
                    INDEX idx_title_update_student (student_id),
                    CONSTRAINT fk_title_update_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $ensured = true;
    }
}

if (!function_exists('title_update_clean_title')) {
    function title_update_clean_title(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/\s+/', ' ', $title);
        return trim($title);
    }
}

if (!function_exists('title_update_has_stage')) {
    function title_update_has_stage(mysqli $conn, int $studentId, string $stage): bool
    {
        if ($studentId <= 0 || $stage === '') {
            return false;
        }
        ensureTitleUpdateTable($conn);
        $stmt = $conn->prepare("
            SELECT 1
            FROM title_update_requests
            WHERE student_id = ? AND stage = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $studentId, $stage);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('title_update_fetch_history')) {
    function title_update_fetch_history(mysqli $conn, int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }
        ensureTitleUpdateTable($conn);
        $stmt = $conn->prepare("
            SELECT stage, old_title, new_title, status, applied_to, submitted_at, applied_at
            FROM title_update_requests
            WHERE student_id = ?
            ORDER BY submitted_at ASC, id ASC
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        if ($result) {
            $result->free();
        }
        $stmt->close();
        return $rows ?: [];
    }
}

if (!function_exists('title_update_has_final_pick_recommendation')) {
    function title_update_has_final_pick_recommendation(mysqli $conn, int $studentId): bool
    {
        if ($studentId <= 0) {
            return false;
        }
        if (!title_update_table_exists($conn, 'final_pick_messages')) {
            return false;
        }
        $stmt = $conn->prepare("
            SELECT 1
            FROM final_pick_messages
            WHERE student_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('title_update_has_outline_verdict')) {
    function title_update_has_outline_verdict(mysqli $conn, int $studentId): bool
    {
        if ($studentId <= 0) {
            return false;
        }

        if (title_update_table_exists($conn, 'progress_tracker_steps')) {
            $stmt = $conn->prepare("
                SELECT status
                FROM progress_tracker_steps
                WHERE student_id = ? AND step_key = 'outline_verdict_released'
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!empty($row['status']) && $row['status'] === 'complete') {
                    return true;
                }
            }
        }

        if (!title_update_table_exists($conn, 'final_paper_submissions')) {
            return false;
        }

        if (function_exists('ensureFinalPaperTables')) {
            ensureFinalPaperTables($conn);
        }
        $stmt = $conn->prepare("
            SELECT outline_defense_verdict, outline_defense_verdict_at
            FROM final_paper_submissions
            WHERE student_id = ?
            ORDER BY submitted_at DESC, id DESC
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $verdict = trim((string)($row['outline_defense_verdict'] ?? ''));
        $verdictAt = $row['outline_defense_verdict_at'] ?? null;
        return $verdict !== '' || !empty($verdictAt);
    }
}

if (!function_exists('title_update_get_current_title')) {
    function title_update_get_current_title(mysqli $conn, int $studentId): string
    {
        if ($studentId <= 0) {
            return '';
        }

        if (title_update_table_exists($conn, 'final_paper_submissions')) {
            if (function_exists('ensureFinalPaperTables')) {
                ensureFinalPaperTables($conn);
            }
            $stmt = $conn->prepare("
                SELECT final_title
                FROM final_paper_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $title = trim((string)($row['final_title'] ?? ''));
                if ($title !== '') {
                    return $title;
                }
            }
        }

        if (title_update_table_exists($conn, 'final_pick_messages')) {
            $stmt = $conn->prepare("
                SELECT final_title
                FROM final_pick_messages
                WHERE student_id = ?
                ORDER BY sent_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $title = trim((string)($row['final_title'] ?? ''));
                if ($title !== '') {
                    return $title;
                }
            }
        }

        if (title_update_table_exists($conn, 'final_concept_submissions')) {
            $stmt = $conn->prepare("
                SELECT final_title
                FROM final_concept_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $title = trim((string)($row['final_title'] ?? ''));
                if ($title !== '') {
                    return $title;
                }
            }
        }

        if (function_exists('fetch_final_pick_title_for_student')) {
            $title = trim((string)fetch_final_pick_title_for_student($conn, $studentId));
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }
}

if (!function_exists('title_update_get_window')) {
    function title_update_get_window(mysqli $conn, int $studentId): array
    {
        $hasFinalPick = title_update_has_final_pick_recommendation($conn, $studentId);
        $hasOutlineVerdict = title_update_has_outline_verdict($conn, $studentId);
        $preUsed = title_update_has_stage($conn, $studentId, TITLE_UPDATE_STAGE_PRE_OUTLINE);
        $postUsed = title_update_has_stage($conn, $studentId, TITLE_UPDATE_STAGE_POST_OUTLINE);

        if (!$hasOutlineVerdict) {
            if (!$hasFinalPick) {
                return [
                    'can_update' => false,
                    'stage' => null,
                    'reason' => 'Final pick recommendation is not recorded yet.',
                ];
            }
            if ($preUsed) {
                return [
                    'can_update' => false,
                    'stage' => null,
                    'reason' => 'First title update already used. Next window opens after outline defense.',
                ];
            }
            return [
                'can_update' => true,
                'stage' => TITLE_UPDATE_STAGE_PRE_OUTLINE,
                'reason' => '',
            ];
        }

        if ($postUsed) {
            return [
                'can_update' => false,
                'stage' => null,
                'reason' => 'Second title update already used.',
            ];
        }

        return [
            'can_update' => true,
            'stage' => TITLE_UPDATE_STAGE_POST_OUTLINE,
            'reason' => '',
        ];
    }
}

if (!function_exists('title_update_apply_for_student')) {
    function title_update_apply_for_student(mysqli $conn, int $studentId, string $newTitle, ?string $stage = null): array
    {
        if ($studentId <= 0) {
            return ['success' => false, 'error' => 'Invalid student.'];
        }

        $newTitle = title_update_clean_title($newTitle);
        if ($newTitle === '') {
            return ['success' => false, 'error' => 'Title cannot be empty.'];
        }
        if (strlen($newTitle) > 255) {
            return ['success' => false, 'error' => 'Title is too long (max 255 characters).'];
        }

        ensureTitleUpdateTable($conn);

        if ($stage === null || $stage === '') {
            $window = title_update_get_window($conn, $studentId);
            if (empty($window['can_update'])) {
                return ['success' => false, 'error' => $window['reason'] ?? 'Title update is not available.'];
            }
            $stage = $window['stage'] ?? '';
        }

        if (!in_array($stage, [TITLE_UPDATE_STAGE_PRE_OUTLINE, TITLE_UPDATE_STAGE_POST_OUTLINE], true)) {
            return ['success' => false, 'error' => 'Invalid update stage.'];
        }

        if (title_update_has_stage($conn, $studentId, $stage)) {
            return ['success' => false, 'error' => 'This title update stage is already used.'];
        }

        $currentTitle = title_update_get_current_title($conn, $studentId);
        if ($currentTitle === '') {
            return ['success' => false, 'error' => 'Unable to determine the current title.'];
        }
        if (strcasecmp($currentTitle, $newTitle) === 0) {
            return ['success' => false, 'error' => 'The new title matches the current title.'];
        }

        $appliedTo = null;
        $sourceSubmissionId = null;

        if (title_update_table_exists($conn, 'final_paper_submissions')) {
            $stmt = $conn->prepare("
                SELECT id
                FROM final_paper_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!empty($row['id'])) {
                    $sourceSubmissionId = (int)$row['id'];
                    $updateStmt = $conn->prepare("
                        UPDATE final_paper_submissions
                        SET final_title = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if ($updateStmt) {
                        $updateStmt->bind_param('si', $newTitle, $sourceSubmissionId);
                        $updateStmt->execute();
                        $updateStmt->close();
                        $appliedTo = 'final_paper_submissions';
                    }
                }
            }
        }

        if ($appliedTo === null && title_update_table_exists($conn, 'final_pick_messages')) {
            $stmt = $conn->prepare("
                UPDATE final_pick_messages
                SET final_title = ?, sent_at = CURRENT_TIMESTAMP
                WHERE student_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('si', $newTitle, $studentId);
                $stmt->execute();
                $stmt->close();
                $appliedTo = 'final_pick_messages';
            }
        }

        $insertStmt = $conn->prepare("
            INSERT INTO title_update_requests
                (student_id, stage, old_title, new_title, status, applied_to, source_submission_id, applied_at)
            VALUES (?, ?, ?, ?, 'applied', ?, ?, NOW())
        ");
        if (!$insertStmt) {
            return ['success' => false, 'error' => 'Unable to record title update.'];
        }
        $appliedToValue = $appliedTo ?? 'none';
        $insertStmt->bind_param('issssi', $studentId, $stage, $currentTitle, $newTitle, $appliedToValue, $sourceSubmissionId);
        $ok = $insertStmt->execute();
        $insertStmt->close();
        if (!$ok) {
            return ['success' => false, 'error' => 'Unable to record title update.'];
        }

        return [
            'success' => true,
            'stage' => $stage,
            'old_title' => $currentTitle,
            'new_title' => $newTitle,
            'applied_to' => $appliedToValue,
            'source_submission_id' => $sourceSubmissionId,
        ];
    }
}

if (!function_exists('title_update_stage_label')) {
    function title_update_stage_label(string $stage): string
    {
        return match ($stage) {
            TITLE_UPDATE_STAGE_PRE_OUTLINE => 'Before Outline Defense',
            TITLE_UPDATE_STAGE_POST_OUTLINE => 'After Outline Defense',
            default => $stage,
        };
    }
}
