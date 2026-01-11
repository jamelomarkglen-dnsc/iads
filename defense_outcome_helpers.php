<?php

if (!function_exists('defense_outcome_column_exists')) {
    function defense_outcome_column_exists(mysqli $conn, string $table, string $column): bool
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

if (!function_exists('ensureDefenseOutcomeTable')) {
    function ensureDefenseOutcomeTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'defense_outcomes'");
        $exists = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck) {
            $tableCheck->free();
        }
        if (!$exists) {
            $conn->query("
                CREATE TABLE defense_outcomes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    defense_id INT NOT NULL,
                    student_id INT NOT NULL,
                    outcome ENUM('Thesis Defended','Capstone Defended','Dissertation Defended') NOT NULL,
                    notes TEXT NULL,
                    set_by INT NULL,
                    set_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_defense_outcome (defense_id),
                    CONSTRAINT fk_defense_outcome_defense FOREIGN KEY (defense_id) REFERENCES defense_schedules(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_outcome_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_defense_outcome_set_by FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $columns = [
            'notes' => "ALTER TABLE defense_outcomes ADD COLUMN notes TEXT NULL AFTER outcome",
            'set_by' => "ALTER TABLE defense_outcomes ADD COLUMN set_by INT NULL AFTER notes",
            'set_at' => "ALTER TABLE defense_outcomes ADD COLUMN set_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER set_by",
            'updated_at' => "ALTER TABLE defense_outcomes ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER set_at",
        ];
        foreach ($columns as $column => $sql) {
            if (!defense_outcome_column_exists($conn, 'defense_outcomes', $column)) {
                $conn->query($sql);
            }
        }

        $ensured = true;
    }
}

if (!function_exists('fetch_latest_defense_outcome')) {
    function fetch_latest_defense_outcome(mysqli $conn, int $studentId): ?array
    {
        if ($studentId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT o.*, ds.defense_date, ds.defense_time, ds.venue
            FROM defense_outcomes o
            JOIN defense_schedules ds ON ds.id = o.defense_id
            WHERE o.student_id = ?
            ORDER BY o.set_at DESC, o.id DESC
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

if (!function_exists('defense_outcome_badge_class')) {
    function defense_outcome_badge_class(string $outcome): string
    {
        $outcome = strtolower(trim($outcome));
        return match ($outcome) {
            'thesis defended', 'capstone defended', 'dissertation defended' => 'bg-success-subtle text-success',
            default => 'bg-secondary-subtle text-secondary',
        };
    }
}
