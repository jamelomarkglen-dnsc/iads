<?php

if (!function_exists('final_paper_column_exists')) {
    function final_paper_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND COLUMN_NAME = '{$column}'
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

if (!function_exists('final_paper_enum_has_value')) {
    function final_paper_enum_has_value(mysqli $conn, string $table, string $column, string $value): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $stmt = $conn->prepare("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
        $columnType = $row['COLUMN_TYPE'] ?? '';
        return $columnType !== '' && strpos($columnType, "'" . $value . "'") !== false;
    }
}

if (!function_exists('ensureFinalPaperTables')) {
    function ensureFinalPaperTables(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $submissionsCheck = $conn->query("SHOW TABLES LIKE 'final_paper_submissions'");
        $hasSubmissions = $submissionsCheck && $submissionsCheck->num_rows > 0;
        if ($submissionsCheck) {
            $submissionsCheck->free();
        }
        if (!$hasSubmissions) {
            $conn->query("
                CREATE TABLE final_paper_submissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    final_title VARCHAR(255) NOT NULL,
                    introduction TEXT NULL,
                    background TEXT NULL,
                    methodology TEXT NULL,
                    submission_notes TEXT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    file_name VARCHAR(255) NULL,
                    route_slip_path VARCHAR(255) NULL,
                    route_slip_name VARCHAR(255) NULL,
                    status ENUM('Submitted','Under Review','Needs Revision','Minor Revision','Major Revision','Approved','Rejected') DEFAULT 'Submitted',
                    version INT NOT NULL DEFAULT 1,
                    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    final_decision_by INT NULL,
                    final_decision_notes TEXT NULL,
                    final_decision_at TIMESTAMP NULL DEFAULT NULL,
                    committee_reviews_completed_at TIMESTAMP NULL DEFAULT NULL,
                    outline_defense_verdict VARCHAR(50) NULL,
                    outline_defense_verdict_at TIMESTAMP NULL DEFAULT NULL,
                    CONSTRAINT fk_final_paper_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_paper_decider FOREIGN KEY (final_decision_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $columns = [
            'introduction' => "ALTER TABLE final_paper_submissions ADD COLUMN introduction TEXT NULL AFTER final_title",
            'background' => "ALTER TABLE final_paper_submissions ADD COLUMN background TEXT NULL AFTER introduction",
            'methodology' => "ALTER TABLE final_paper_submissions ADD COLUMN methodology TEXT NULL AFTER background",
            'submission_notes' => "ALTER TABLE final_paper_submissions ADD COLUMN submission_notes TEXT NULL AFTER methodology",
            'file_name' => "ALTER TABLE final_paper_submissions ADD COLUMN file_name VARCHAR(255) NULL AFTER file_path",
            'route_slip_path' => "ALTER TABLE final_paper_submissions ADD COLUMN route_slip_path VARCHAR(255) NULL AFTER file_name",
            'route_slip_name' => "ALTER TABLE final_paper_submissions ADD COLUMN route_slip_name VARCHAR(255) NULL AFTER route_slip_path",
            'status' => "ALTER TABLE final_paper_submissions ADD COLUMN status ENUM('Submitted','Under Review','Needs Revision','Minor Revision','Major Revision','Approved','Rejected') DEFAULT 'Submitted' AFTER file_name",
            'version' => "ALTER TABLE final_paper_submissions ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER status",
            'final_decision_by' => "ALTER TABLE final_paper_submissions ADD COLUMN final_decision_by INT NULL AFTER updated_at",
            'final_decision_notes' => "ALTER TABLE final_paper_submissions ADD COLUMN final_decision_notes TEXT NULL AFTER final_decision_by",
            'final_decision_at' => "ALTER TABLE final_paper_submissions ADD COLUMN final_decision_at TIMESTAMP NULL DEFAULT NULL AFTER final_decision_notes",
            'committee_reviews_completed_at' => "ALTER TABLE final_paper_submissions ADD COLUMN committee_reviews_completed_at TIMESTAMP NULL DEFAULT NULL AFTER final_decision_at",
            'outline_defense_verdict' => "ALTER TABLE final_paper_submissions ADD COLUMN outline_defense_verdict VARCHAR(50) NULL AFTER committee_reviews_completed_at",
            'outline_defense_verdict_at' => "ALTER TABLE final_paper_submissions ADD COLUMN outline_defense_verdict_at TIMESTAMP NULL DEFAULT NULL AFTER outline_defense_verdict",
        ];
        foreach ($columns as $column => $sql) {
            if (!final_paper_column_exists($conn, 'final_paper_submissions', $column)) {
                $conn->query($sql);
            }
        }

        $reviewsCheck = $conn->query("SHOW TABLES LIKE 'final_paper_reviews'");
        $hasReviews = $reviewsCheck && $reviewsCheck->num_rows > 0;
        if ($reviewsCheck) {
            $reviewsCheck->free();
        }
        if (!$hasReviews) {
            $conn->query("
                CREATE TABLE final_paper_reviews (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    submission_id INT NOT NULL,
                    reviewer_id INT NOT NULL,
                    reviewer_role ENUM('adviser','committee_chairperson','panel') NOT NULL,
                    status ENUM('Pending','Approved','Rejected','Needs Revision','Minor Revision','Major Revision') DEFAULT 'Pending',
                    comments TEXT NULL,
                    route_slip_status ENUM('Pending','Approved','Rejected','Needs Revision','Minor Revision','Major Revision') DEFAULT 'Pending',
                    route_slip_comments TEXT NULL,
                    reviewed_at TIMESTAMP NULL DEFAULT NULL,
                    route_slip_reviewed_at TIMESTAMP NULL DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_submission_reviewer (submission_id, reviewer_id),
                    INDEX idx_final_paper_reviewer (reviewer_id),
                    CONSTRAINT fk_final_paper_review_submission FOREIGN KEY (submission_id) REFERENCES final_paper_submissions(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_paper_review_user FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $reviewColumns = [
            'route_slip_status' => "ALTER TABLE final_paper_reviews ADD COLUMN route_slip_status ENUM('Pending','Approved','Rejected','Needs Revision','Minor Revision','Major Revision') DEFAULT 'Pending' AFTER comments",
            'route_slip_comments' => "ALTER TABLE final_paper_reviews ADD COLUMN route_slip_comments TEXT NULL AFTER route_slip_status",
            'route_slip_reviewed_at' => "ALTER TABLE final_paper_reviews ADD COLUMN route_slip_reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_at",
        ];
        foreach ($reviewColumns as $column => $sql) {
            if (!final_paper_column_exists($conn, 'final_paper_reviews', $column)) {
                $conn->query($sql);
            }
        }

        $submissionStatusEnum = "ENUM('Submitted','Under Review','Needs Revision','Minor Revision','Major Revision','Approved','Rejected')";
        $reviewStatusEnum = "ENUM('Pending','Approved','Rejected','Needs Revision','Minor Revision','Major Revision')";
        if (final_paper_column_exists($conn, 'final_paper_submissions', 'status')
            && !final_paper_enum_has_value($conn, 'final_paper_submissions', 'status', 'Minor Revision')) {
            $conn->query("ALTER TABLE final_paper_submissions MODIFY COLUMN status {$submissionStatusEnum} DEFAULT 'Submitted'");
        }
        if (final_paper_column_exists($conn, 'final_paper_reviews', 'status')
            && !final_paper_enum_has_value($conn, 'final_paper_reviews', 'status', 'Minor Revision')) {
            $conn->query("ALTER TABLE final_paper_reviews MODIFY COLUMN status {$reviewStatusEnum} DEFAULT 'Pending'");
        }
        if (final_paper_column_exists($conn, 'final_paper_reviews', 'route_slip_status')
            && !final_paper_enum_has_value($conn, 'final_paper_reviews', 'route_slip_status', 'Minor Revision')) {
            $conn->query("ALTER TABLE final_paper_reviews MODIFY COLUMN route_slip_status {$reviewStatusEnum} DEFAULT 'Pending'");
        }

        $ensured = true;
    }
}

if (!function_exists('fetchLatestFinalPaperSubmission')) {
    function fetchLatestFinalPaperSubmission(mysqli $conn, int $studentId): ?array
    {
        if ($studentId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT *
            FROM final_paper_submissions
            WHERE student_id = ?
            ORDER BY submitted_at DESC, id DESC
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('fetchFinalPaperSubmission')) {
    function fetchFinalPaperSubmission(mysqli $conn, int $submissionId): ?array
    {
        if ($submissionId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT * FROM final_paper_submissions WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('fetchFinalPaperReviews')) {
    function fetchFinalPaperReviews(mysqli $conn, int $submissionId): array
    {
        if ($submissionId <= 0) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT r.*, CONCAT(u.firstname, ' ', u.lastname) AS reviewer_name
            FROM final_paper_reviews r
            LEFT JOIN users u ON u.id = r.reviewer_id
            WHERE r.submission_id = ?
            ORDER BY r.reviewer_role, r.reviewer_id
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $submissionId);
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

if (!function_exists('fetchFinalPaperReviewForUser')) {
    function fetchFinalPaperReviewForUser(mysqli $conn, int $submissionId, int $reviewerId): ?array
    {
        if ($submissionId <= 0 || $reviewerId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT *
            FROM final_paper_reviews
            WHERE submission_id = ? AND reviewer_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $submissionId, $reviewerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('fetchFinalPaperReviewersForStudent')) {
    function fetchFinalPaperReviewersForStudent(mysqli $conn, int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }

        $defenseStmt = $conn->prepare("
            SELECT id
            FROM defense_schedules
            WHERE student_id = ?
            ORDER BY defense_date DESC, id DESC
            LIMIT 1
        ");
        if (!$defenseStmt) {
            return [];
        }
        $defenseStmt->bind_param('i', $studentId);
        $defenseStmt->execute();
        $defenseResult = $defenseStmt->get_result();
        $defenseRow = $defenseResult ? $defenseResult->fetch_assoc() : null;
        if ($defenseResult) {
            $defenseResult->free();
        }
        $defenseStmt->close();
        $defenseId = (int)($defenseRow['id'] ?? 0);
        if ($defenseId <= 0) {
            return [];
        }

        $panelStmt = $conn->prepare("
            SELECT panel_member_id, panel_role
            FROM defense_panels
            WHERE defense_id = ?
            ORDER BY id
        ");
        if (!$panelStmt) {
            return [];
        }
        $panelStmt->bind_param('i', $defenseId);
        $panelStmt->execute();
        $panelResult = $panelStmt->get_result();
        $reviewers = [];
        $seen = [];
        $roleMap = [
            'adviser' => 'adviser',
            'committee_chair' => 'committee_chairperson',
            'panel_member' => 'panel',
        ];
        while ($row = $panelResult->fetch_assoc()) {
            $userId = (int)($row['panel_member_id'] ?? 0);
            $panelRole = $row['panel_role'] ?? '';
            $reviewRole = $roleMap[$panelRole] ?? '';
            if ($userId <= 0 || $reviewRole === '') {
                continue;
            }
            if (isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;
            $reviewers[] = [
                'reviewer_id' => $userId,
                'reviewer_role' => $reviewRole,
            ];
        }
        if ($panelResult) {
            $panelResult->free();
        }
        $panelStmt->close();

        return $reviewers;
    }
}

if (!function_exists('replaceFinalPaperReviews')) {
    function replaceFinalPaperReviews(mysqli $conn, int $submissionId, array $reviewers): void
    {
        if ($submissionId <= 0) {
            return;
        }
        $conn->query("DELETE FROM final_paper_reviews WHERE submission_id = {$submissionId}");
        if (empty($reviewers)) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO final_paper_reviews (submission_id, reviewer_id, reviewer_role, status)
            VALUES (?, ?, ?, 'Pending')
        ");
        if (!$stmt) {
            return;
        }
        foreach ($reviewers as $reviewer) {
            $reviewerId = (int)($reviewer['reviewer_id'] ?? 0);
            $reviewerRole = trim((string)($reviewer['reviewer_role'] ?? ''));
            if ($reviewerId <= 0 || $reviewerRole === '') {
                continue;
            }
            $stmt->bind_param('iis', $submissionId, $reviewerId, $reviewerRole);
            $stmt->execute();
        }
        $stmt->close();
    }
}

if (!function_exists('finalPaperStatusClass')) {
    function finalPaperStatusClass(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'approved' => 'bg-success-subtle text-success',
            'needs revision', 'minor revision', 'major revision' => 'bg-warning-subtle text-warning',
            'rejected' => 'bg-danger-subtle text-danger',
            'under review' => 'bg-info-subtle text-info',
            'submitted' => 'bg-secondary-subtle text-secondary',
            default => 'bg-secondary-subtle text-secondary',
        };
    }
}

if (!function_exists('finalPaperStatusLabel')) {
    function finalPaperStatusLabel(string $status): string
    {
        $status = trim($status);
        $normalized = strtolower($status);
        return match ($normalized) {
            'minor revision' => 'Passed with Minor Revision',
            'major revision' => 'Passed with Major Revision',
            'needs revision' => 'Passed with Minor Revision',
            'under review' => 'Under Review',
            'submitted' => 'Submitted',
            'approved' => 'Passed',
            'rejected' => 'Failed',
            'pending' => 'Pending',
            default => $status !== '' ? ucwords($status) : 'Pending',
        };
    }
}

if (!function_exists('finalPaperReviewStatusClass')) {
    function finalPaperReviewStatusClass(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'approved' => 'bg-success-subtle text-success',
            'needs revision', 'minor revision', 'major revision' => 'bg-warning-subtle text-warning',
            'rejected' => 'bg-danger-subtle text-danger',
            default => 'bg-secondary-subtle text-secondary',
        };
    }
}

if (!function_exists('setOutlineDefenseVerdict')) {
    function setOutlineDefenseVerdict(mysqli $conn, int $submissionId, string $verdict): bool
    {
        if ($submissionId <= 0 || $verdict === '') {
            return false;
        }
        $stmt = $conn->prepare("
            UPDATE final_paper_submissions
            SET outline_defense_verdict = ?,
                outline_defense_verdict_at = NOW()
            WHERE id = ?
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $verdict, $submissionId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('getOutlineDefenseVerdict')) {
    function getOutlineDefenseVerdict(mysqli $conn, int $submissionId): ?array
    {
        if ($submissionId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT outline_defense_verdict, outline_defense_verdict_at
            FROM final_paper_submissions
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('outlineDefenseVerdictClass')) {
    function outlineDefenseVerdictClass(string $verdict): string
    {
        $verdict = strtolower(trim($verdict));
        return match ($verdict) {
            'passed' => 'bg-success-subtle text-success',
            'passed with revision' => 'bg-warning-subtle text-warning',
            'failed' => 'bg-danger-subtle text-danger',
            'pending' => 'bg-secondary-subtle text-secondary',
            default => 'bg-secondary-subtle text-secondary',
        };
    }
}

if (!function_exists('outlineDefenseVerdictLabel')) {
    function outlineDefenseVerdictLabel(string $verdict): string
    {
        $verdict = trim($verdict);
        $normalized = strtolower($verdict);
        return match ($normalized) {
            'passed' => 'Passed',
            'passed with revision' => 'Passed with Revision',
            'failed' => 'Failed',
            'pending' => 'Pending',
            default => $verdict !== '' ? ucwords($verdict) : 'Pending',
        };
    }
}
