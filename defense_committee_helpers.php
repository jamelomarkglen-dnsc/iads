<?php

if (!function_exists('defense_committee_column_exists')) {
    function defense_committee_column_exists(mysqli $conn, string $table, string $column): bool
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
}

if (!function_exists('ensureDefenseCommitteeRequestsTable')) {
    function ensureDefenseCommitteeRequestsTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $check = $conn->query("SHOW TABLES LIKE 'defense_committee_requests'");
        $exists = $check && $check->num_rows > 0;
        if ($check) {
            $check->free();
        }
        if (!$exists) {
            $sql = "
                CREATE TABLE IF NOT EXISTS defense_committee_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    defense_id INT NOT NULL,
                    adviser_id INT NOT NULL,
                    chair_id INT NOT NULL,
                    panel_member_one_id INT NOT NULL,
                    panel_member_two_id INT NOT NULL,
                    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
                    request_notes TEXT NULL,
                    requested_by INT NOT NULL,
                    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    reviewed_by INT NULL,
                    reviewed_at TIMESTAMP NULL,
                    review_notes TEXT NULL,
                    CONSTRAINT fk_defense_committee_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_committee_defense FOREIGN KEY (defense_id) REFERENCES defense_schedules(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_committee_adviser FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_committee_chair FOREIGN KEY (chair_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_committee_panel_one FOREIGN KEY (panel_member_one_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_committee_panel_two FOREIGN KEY (panel_member_two_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_committee_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_committee_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ";
            $conn->query($sql);
        }

        $memoColumns = [
            'memo_number' => "ALTER TABLE defense_committee_requests ADD COLUMN memo_number VARCHAR(50) NULL AFTER panel_member_two_id",
            'memo_series_year' => "ALTER TABLE defense_committee_requests ADD COLUMN memo_series_year VARCHAR(10) NULL AFTER memo_number",
            'memo_date' => "ALTER TABLE defense_committee_requests ADD COLUMN memo_date DATE NULL AFTER memo_series_year",
            'memo_subject' => "ALTER TABLE defense_committee_requests ADD COLUMN memo_subject VARCHAR(255) NULL AFTER memo_date",
            'memo_body' => "ALTER TABLE defense_committee_requests ADD COLUMN memo_body TEXT NULL AFTER memo_subject",
            'memo_updated_at' => "ALTER TABLE defense_committee_requests ADD COLUMN memo_updated_at TIMESTAMP NULL DEFAULT NULL AFTER memo_body",
        ];
        foreach ($memoColumns as $column => $statement) {
            if (!defense_committee_column_exists($conn, 'defense_committee_requests', $column)) {
                $conn->query($statement);
            }
        }

        $ensured = true;
    }
}

if (!function_exists('defense_committee_status_class')) {
    function defense_committee_status_class(string $status): string
    {
        return [
            'Approved' => 'badge bg-success-subtle text-success',
            'Rejected' => 'badge bg-danger-subtle text-danger',
            'Pending' => 'badge bg-warning-subtle text-warning',
        ][$status] ?? 'badge bg-secondary-subtle text-secondary';
    }
}

if (!function_exists('fetch_user_fullname')) {
    function fetch_user_fullname(mysqli $conn, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $stmt = $conn->prepare("SELECT CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, '')) AS full_name FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $name = trim($row['full_name'] ?? '');
        return $name !== '' ? $name : '';
    }
}

if (!function_exists('fetch_users_by_roles')) {
    function fetch_users_by_roles(mysqli $conn, array $roles): array
    {
        if (empty($roles)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $types = str_repeat('s', count($roles));
        $sql = "SELECT id, firstname, lastname, role FROM users WHERE role IN ({$placeholders}) ORDER BY firstname, lastname";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$roles);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows ?: [];
    }
}

if (!function_exists('fetch_final_pick_title_for_student')) {
    function fetch_final_pick_title_for_student(mysqli $conn, int $studentId): string
    {
        if ($studentId <= 0) {
            return '';
        }
        $sql = "
            SELECT
                cp.title,
                SUM(CASE WHEN cr.rank_order = 1 THEN 1 ELSE 0 END) AS rank_one_votes,
                SUM(CASE WHEN cr.rank_order = 2 THEN 1 ELSE 0 END) AS rank_two_votes,
                SUM(CASE WHEN cr.rank_order = 3 THEN 1 ELSE 0 END) AS rank_three_votes,
                MAX(cr.updated_at) AS last_ranked_at
            FROM concept_reviews cr
            INNER JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
            INNER JOIN concept_papers cp ON cp.id = cr.concept_paper_id
            WHERE cra.student_id = ?
              AND cr.rank_order IS NOT NULL
            GROUP BY cp.id, cp.title
            HAVING (rank_one_votes > 0 OR rank_two_votes > 0 OR rank_three_votes > 0)
            ORDER BY rank_one_votes DESC, rank_two_votes DESC, rank_three_votes DESC, cp.created_at DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
        $title = trim((string)($row['title'] ?? ''));
        return $title;
    }
}

if (!function_exists('ensureDefensePanelMemberColumns')) {
    function ensureDefensePanelMemberColumns(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $hasMemberId = $conn->query("SHOW COLUMNS FROM defense_panels LIKE 'panel_member_id'");
        if ($hasMemberId && $hasMemberId->num_rows === 0) {
            $conn->query("ALTER TABLE defense_panels ADD COLUMN panel_member_id INT NULL AFTER defense_id");
            $conn->query("ALTER TABLE defense_panels ADD CONSTRAINT fk_panel_member_user FOREIGN KEY (panel_member_id) REFERENCES users(id) ON DELETE CASCADE");
        }
        if ($hasMemberId) {
            $hasMemberId->free();
        }

        $hasRole = $conn->query("SHOW COLUMNS FROM defense_panels LIKE 'panel_role'");
        if ($hasRole && $hasRole->num_rows === 0) {
            $conn->query("ALTER TABLE defense_panels ADD COLUMN panel_role ENUM('adviser','committee_chair','panel_member') DEFAULT 'panel_member' AFTER panel_member_id");
        }
        if ($hasRole) {
            $hasRole->free();
        }

        $ensured = true;
    }
}
