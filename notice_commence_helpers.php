<?php

if (!function_exists('notice_commence_column_exists')) {
    function notice_commence_column_exists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'notice_to_commence_requests'
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

if (!function_exists('ensureNoticeCommenceTable')) {
    function ensureNoticeCommenceTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'notice_to_commence_requests'");
        $exists = $tableCheck && $tableCheck->num_rows > 0;
        if ($tableCheck) {
            $tableCheck->free();
        }
        if (!$exists) {
            $conn->query("
                CREATE TABLE notice_to_commence_requests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    submission_id INT NULL,
                    program_chair_id INT NOT NULL,
                    dean_id INT NULL,
                    status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
                    notice_date DATE NULL,
                    start_date DATE NULL,
                    subject VARCHAR(255) NOT NULL,
                    body TEXT NULL,
                    dean_notes TEXT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    reviewed_at TIMESTAMP NULL DEFAULT NULL,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_notice_student (student_id),
                    INDEX idx_notice_status (status),
                    CONSTRAINT fk_notice_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_notice_submission FOREIGN KEY (submission_id) REFERENCES final_paper_submissions(id) ON DELETE SET NULL,
                    CONSTRAINT fk_notice_program_chair FOREIGN KEY (program_chair_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_notice_dean FOREIGN KEY (dean_id) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $columns = [
            'submission_id' => "ALTER TABLE notice_to_commence_requests ADD COLUMN submission_id INT NULL AFTER student_id",
            'program_chair_id' => "ALTER TABLE notice_to_commence_requests ADD COLUMN program_chair_id INT NOT NULL AFTER submission_id",
            'dean_id' => "ALTER TABLE notice_to_commence_requests ADD COLUMN dean_id INT NULL AFTER program_chair_id",
            'status' => "ALTER TABLE notice_to_commence_requests ADD COLUMN status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending' AFTER dean_id",
            'notice_date' => "ALTER TABLE notice_to_commence_requests ADD COLUMN notice_date DATE NULL AFTER status",
            'start_date' => "ALTER TABLE notice_to_commence_requests ADD COLUMN start_date DATE NULL AFTER notice_date",
            'subject' => "ALTER TABLE notice_to_commence_requests ADD COLUMN subject VARCHAR(255) NOT NULL AFTER start_date",
            'body' => "ALTER TABLE notice_to_commence_requests ADD COLUMN body TEXT NULL AFTER subject",
            'dean_notes' => "ALTER TABLE notice_to_commence_requests ADD COLUMN dean_notes TEXT NULL AFTER body",
            'reviewed_at' => "ALTER TABLE notice_to_commence_requests ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER dean_notes",
        ];
        foreach ($columns as $column => $sql) {
            if (!notice_commence_column_exists($conn, $column)) {
                $conn->query($sql);
            }
        }

        $ensured = true;
    }
}

if (!function_exists('save_notice_signature_upload')) {
    function save_notice_signature_upload(array $file, int $userId, ?string &$error = null): string
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

        $dir = 'uploads/signatures/';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $error = 'Unable to create signature folder.';
            return '';
        }

        $base = $dir . 'user_' . $userId . '.';
        foreach (['png', 'jpg', 'jpeg'] as $oldExt) {
            $oldPath = $base . $oldExt;
            if (is_file($oldPath) && $oldExt !== $extMap[$mime]) {
                unlink($oldPath);
            }
        }

        $path = $base . $extMap[$mime];
        if (!move_uploaded_file($tmpName, $path)) {
            $error = 'Unable to save signature image.';
            return '';
        }

        return $path;
    }
}

if (!function_exists('notice_commence_format_date')) {
    function notice_commence_format_date(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if ($dt) {
            return $dt->format('F d, Y');
        }
        return $date;
    }
}

if (!function_exists('build_notice_commence_body')) {
    function build_notice_commence_body(string $studentName, string $title, string $program, ?string $startDate): string
    {
        $startLabel = notice_commence_format_date($startDate ?: date('Y-m-d'));

        return trim(
            "We are pleased to inform you that your research proposal entitled, \"{$title}\" has been approved. " .
            "With the approval in place, you are now authorized to commence your study.\n\n" .
            "The official start date of your research is {$startLabel}, and we anticipate its completion within one year. " .
            "Please ensure that you adhere to the approved protocols and methodologies as outlined in your proposal. " .
            "Should you require any resources or support, do not hesitate to reach out to your research adviser.\n\n" .
            "This is an exciting opportunity to contribute valuable insights to your field of study, and we are confident " .
            "in your ability to execute this research with diligence and rigor. If you need further assistance, please feel " .
            "free to visit the office.\n\n" .
            "For your guidance and commitment."
        );
    }
}
