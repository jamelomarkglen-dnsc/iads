<?php

if (!function_exists('final_endorsement_column_exists')) {
    function final_endorsement_column_exists(mysqli $conn, string $table, string $column): bool
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

if (!function_exists('ensureFinalEndorsementTables')) {
    function ensureFinalEndorsementTables(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'final_endorsement_submissions'");
        $exists = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck) {
            $tableCheck->free();
        }
        if (!$exists) {
            $conn->query("
                CREATE TABLE final_endorsement_submissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    file_name VARCHAR(255) NULL,
                    notes TEXT NULL,
                    status ENUM('Submitted','Approved','Rejected') DEFAULT 'Submitted',
                    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    reviewed_by INT NULL,
                    reviewed_at TIMESTAMP NULL DEFAULT NULL,
                    review_notes TEXT NULL,
                    CONSTRAINT fk_final_endorsement_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_endorsement_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $columns = [
            'file_name' => "ALTER TABLE final_endorsement_submissions ADD COLUMN file_name VARCHAR(255) NULL AFTER file_path",
            'notes' => "ALTER TABLE final_endorsement_submissions ADD COLUMN notes TEXT NULL AFTER file_name",
            'status' => "ALTER TABLE final_endorsement_submissions ADD COLUMN status ENUM('Submitted','Approved','Rejected') DEFAULT 'Submitted' AFTER notes",
            'submitted_at' => "ALTER TABLE final_endorsement_submissions ADD COLUMN submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
            'updated_at' => "ALTER TABLE final_endorsement_submissions ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER submitted_at",
            'reviewed_by' => "ALTER TABLE final_endorsement_submissions ADD COLUMN reviewed_by INT NULL AFTER updated_at",
            'reviewed_at' => "ALTER TABLE final_endorsement_submissions ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_by",
            'review_notes' => "ALTER TABLE final_endorsement_submissions ADD COLUMN review_notes TEXT NULL AFTER reviewed_at",
        ];
        foreach ($columns as $column => $sql) {
            if (!final_endorsement_column_exists($conn, 'final_endorsement_submissions', $column)) {
                $conn->query($sql);
            }
        }

        $ensured = true;
    }
}

if (!function_exists('fetchLatestFinalEndorsementSubmission')) {
    function fetchLatestFinalEndorsementSubmission(mysqli $conn, int $studentId): ?array
    {
        if ($studentId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT *
            FROM final_endorsement_submissions
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

if (!function_exists('fetchFinalEndorsementSubmission')) {
    function fetchFinalEndorsementSubmission(mysqli $conn, int $submissionId): ?array
    {
        if ($submissionId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("SELECT * FROM final_endorsement_submissions WHERE id = ? LIMIT 1");
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

if (!function_exists('finalEndorsementStatusClass')) {
    function finalEndorsementStatusClass(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'approved' => 'bg-success-subtle text-success',
            'rejected' => 'bg-danger-subtle text-danger',
            'submitted' => 'bg-warning-subtle text-warning',
            default => 'bg-secondary-subtle text-secondary',
        };
    }
}
