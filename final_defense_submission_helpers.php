<?php

if (!function_exists('final_defense_submission_column_exists')) {
    function final_defense_submission_column_exists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'final_defense_submissions'
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

if (!function_exists('ensureFinalDefenseSubmissionTable')) {
    function ensureFinalDefenseSubmissionTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $check = $conn->query("SHOW TABLES LIKE 'final_defense_submissions'");
        $exists = $check && $check->num_rows > 0;
        if ($check) {
            $check->free();
        }
        if (!$exists) {
            $conn->query("
                CREATE TABLE final_defense_submissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    submission_id INT NOT NULL,
                    student_id INT NOT NULL,
                    adviser_id INT NOT NULL,
                    chair_id INT NOT NULL,
                    panel_member_one_id INT NOT NULL,
                    panel_member_two_id INT NOT NULL,
                    defense_id INT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    file_name VARCHAR(255) NULL,
                    notes TEXT NULL,
                    status ENUM('Submitted','Passed','Failed') DEFAULT 'Submitted',
                    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    reviewed_at TIMESTAMP NULL DEFAULT NULL,
                    reviewed_by INT NULL,
                    review_notes TEXT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_final_defense_submission (submission_id),
                    INDEX idx_final_defense_student (student_id),
                    INDEX idx_final_defense_chair (chair_id),
                    INDEX idx_final_defense_status (status),
                    CONSTRAINT fk_final_defense_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_defense_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_defense_adviser FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_defense_chair FOREIGN KEY (chair_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_defense_panel_one FOREIGN KEY (panel_member_one_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_defense_panel_two FOREIGN KEY (panel_member_two_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_defense_schedule FOREIGN KEY (defense_id) REFERENCES defense_schedules(id) ON DELETE SET NULL,
                    CONSTRAINT fk_final_defense_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $columns = [
            'submission_id' => "ALTER TABLE final_defense_submissions ADD COLUMN submission_id INT NOT NULL AFTER id",
            'adviser_id' => "ALTER TABLE final_defense_submissions ADD COLUMN adviser_id INT NOT NULL AFTER student_id",
            'chair_id' => "ALTER TABLE final_defense_submissions ADD COLUMN chair_id INT NOT NULL AFTER adviser_id",
            'panel_member_one_id' => "ALTER TABLE final_defense_submissions ADD COLUMN panel_member_one_id INT NOT NULL AFTER chair_id",
            'panel_member_two_id' => "ALTER TABLE final_defense_submissions ADD COLUMN panel_member_two_id INT NOT NULL AFTER panel_member_one_id",
            'defense_id' => "ALTER TABLE final_defense_submissions ADD COLUMN defense_id INT NULL AFTER panel_member_two_id",
            'file_path' => "ALTER TABLE final_defense_submissions ADD COLUMN file_path VARCHAR(255) NOT NULL AFTER defense_id",
            'file_name' => "ALTER TABLE final_defense_submissions ADD COLUMN file_name VARCHAR(255) NULL AFTER file_path",
            'notes' => "ALTER TABLE final_defense_submissions ADD COLUMN notes TEXT NULL AFTER file_name",
            'status' => "ALTER TABLE final_defense_submissions ADD COLUMN status ENUM('Submitted','Passed','Failed') DEFAULT 'Submitted' AFTER notes",
            'submitted_at' => "ALTER TABLE final_defense_submissions ADD COLUMN submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
            'reviewed_at' => "ALTER TABLE final_defense_submissions ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER submitted_at",
            'reviewed_by' => "ALTER TABLE final_defense_submissions ADD COLUMN reviewed_by INT NULL AFTER reviewed_at",
            'review_notes' => "ALTER TABLE final_defense_submissions ADD COLUMN review_notes TEXT NULL AFTER reviewed_by",
        ];
        foreach ($columns as $column => $sql) {
            if (!final_defense_submission_column_exists($conn, $column)) {
                $conn->query($sql);
            }
        }

        $ensured = true;
    }
}

if (!function_exists('store_final_defense_file')) {
    function store_final_defense_file(?array $upload, ?string &$error = null): ?array
    {
        $error = '';
        if (!$upload || !isset($upload['tmp_name']) || $upload['tmp_name'] === '') {
            $error = 'Please upload the final defense document.';
            return null;
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Unable to upload the final defense document.';
            return null;
        }

        $allowed = ['pdf', 'doc', 'docx'];
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            $error = 'Only PDF, DOC, or DOCX files are allowed.';
            return null;
        }

        $targetDir = __DIR__ . '/uploads/final_defense_submissions';
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                $error = 'Unable to create upload folder.';
                return null;
            }
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($upload['name'], PATHINFO_FILENAME));
        $filename = uniqid('final_defense_', true) . '_' . $safeBase . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
            $error = 'Unable to save the uploaded file.';
            return null;
        }

        return [
            'path' => 'uploads/final_defense_submissions/' . $filename,
            'name' => $upload['name'],
        ];
    }
}
