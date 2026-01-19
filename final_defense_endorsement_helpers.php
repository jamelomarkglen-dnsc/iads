<?php

if (!function_exists('final_defense_endorsement_column_exists')) {
    function final_defense_endorsement_column_exists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'final_defense_endorsements'
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

if (!function_exists('ensureFinalDefenseEndorsementTable')) {
    function ensureFinalDefenseEndorsementTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'final_defense_endorsements'");
        $exists = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck) {
            $tableCheck->free();
        }
        if (!$exists) {
            $conn->query("
                CREATE TABLE final_defense_endorsements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    adviser_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    body TEXT NOT NULL,
                    signature_path VARCHAR(255) NULL,
                    status ENUM('Submitted','Verified','Rejected') DEFAULT 'Submitted',
                    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    reviewed_at TIMESTAMP NULL DEFAULT NULL,
                    reviewed_by INT NULL,
                    review_notes TEXT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_final_endorse_student (student_id),
                    INDEX idx_final_endorse_adviser (adviser_id),
                    INDEX idx_final_endorse_status (status),
                    INDEX idx_final_endorse_reviewed_by (reviewed_by),
                    CONSTRAINT fk_final_endorse_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_endorse_adviser FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_final_endorse_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $columns = [
            'title' => "ALTER TABLE final_defense_endorsements ADD COLUMN title VARCHAR(255) NOT NULL AFTER adviser_id",
            'body' => "ALTER TABLE final_defense_endorsements ADD COLUMN body TEXT NOT NULL AFTER title",
            'signature_path' => "ALTER TABLE final_defense_endorsements ADD COLUMN signature_path VARCHAR(255) NULL AFTER body",
            'status' => "ALTER TABLE final_defense_endorsements ADD COLUMN status ENUM('Submitted','Verified','Rejected') DEFAULT 'Submitted' AFTER signature_path",
            'submitted_at' => "ALTER TABLE final_defense_endorsements ADD COLUMN submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
            'reviewed_at' => "ALTER TABLE final_defense_endorsements ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER submitted_at",
            'reviewed_by' => "ALTER TABLE final_defense_endorsements ADD COLUMN reviewed_by INT NULL AFTER reviewed_at",
            'review_notes' => "ALTER TABLE final_defense_endorsements ADD COLUMN review_notes TEXT NULL AFTER reviewed_by",
        ];
        foreach ($columns as $column => $sql) {
            if (!final_defense_endorsement_column_exists($conn, $column)) {
                $conn->query($sql);
            }
        }

        $ensured = true;
    }
}

if (!function_exists('save_final_defense_signature_upload')) {
    function save_final_defense_signature_upload(array $file, int $userId, ?string &$error = null): string
    {
        $error = '';
        if ($userId <= 0) {
            $error = 'Invalid user for signature upload.';
            return '';
        }

        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            $error = 'Signature upload failed.';
            return '';
        }

        $tmpName = $file['tmp_name'] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $error = 'Invalid signature upload.';
            return '';
        }

        $imageInfo = getimagesize($tmpName);
        if (!$imageInfo || empty($imageInfo['mime'])) {
            $error = 'Signature must be a PNG or JPG image.';
            return '';
        }

        $mime = $imageInfo['mime'];
        $extMap = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
        ];
        if (!isset($extMap[$mime])) {
            $error = 'Signature must be a PNG or JPG image.';
            return '';
        }

        $dir = 'uploads/final_endorsement_signatures/';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $error = 'Unable to create signature folder.';
            return '';
        }

        $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$userId);
        $fileName = 'final_endorsement_sig_' . $safeId . '_' . date('Ymd_His') . '_' . uniqid('', true) . '.' . $extMap[$mime];
        $path = $dir . $fileName;
        if (!move_uploaded_file($tmpName, $path)) {
            $error = 'Unable to save signature image.';
            return '';
        }

        return $path;
    }
}
