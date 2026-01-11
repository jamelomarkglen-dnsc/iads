<?php

if (!function_exists('route_slip_column_exists')) {
    function route_slip_column_exists(mysqli $conn, string $table, string $column): bool
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

if (!function_exists('ensureRouteSlipTable')) {
    function ensureRouteSlipTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $check = $conn->query("SHOW TABLES LIKE 'route_slips'");
        $exists = $check && $check->num_rows > 0;
        if ($check) {
            $check->free();
        }
        if (!$exists) {
            $conn->query("
                CREATE TABLE route_slips (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    adviser_id INT NOT NULL,
                    student_id INT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    course VARCHAR(255) NULL,
                    major VARCHAR(255) NULL,
                    panel_member_name VARCHAR(255) NULL,
                    action_taken ENUM('approved','minor_revision','major_revision') NOT NULL,
                    slip_date DATE NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    file_name VARCHAR(255) NOT NULL,
                    signature_path VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_route_slip_adviser FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_route_slip_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $columns = [
            'course' => "ALTER TABLE route_slips ADD COLUMN course VARCHAR(255) NULL AFTER title",
            'major' => "ALTER TABLE route_slips ADD COLUMN major VARCHAR(255) NULL AFTER course",
            'panel_member_name' => "ALTER TABLE route_slips ADD COLUMN panel_member_name VARCHAR(255) NULL AFTER major",
            'action_taken' => "ALTER TABLE route_slips ADD COLUMN action_taken ENUM('approved','minor_revision','major_revision') NOT NULL AFTER panel_member_name",
            'slip_date' => "ALTER TABLE route_slips ADD COLUMN slip_date DATE NOT NULL AFTER action_taken",
            'file_name' => "ALTER TABLE route_slips ADD COLUMN file_name VARCHAR(255) NOT NULL AFTER file_path",
            'signature_path' => "ALTER TABLE route_slips ADD COLUMN signature_path VARCHAR(255) NULL AFTER file_name",
        ];
        foreach ($columns as $column => $sql) {
            if (!route_slip_column_exists($conn, 'route_slips', $column)) {
                $conn->query($sql);
            }
        }

        $ensured = true;
    }
}

if (!function_exists('fetchLatestRouteSlipForStudent')) {
    function fetchLatestRouteSlipForStudent(mysqli $conn, int $studentId): ?array
    {
        if ($studentId <= 0) {
            return null;
        }
        $stmt = $conn->prepare("
            SELECT rs.*, CONCAT(u.firstname, ' ', u.lastname) AS adviser_name
            FROM route_slips rs
            LEFT JOIN users u ON u.id = rs.adviser_id
            WHERE rs.student_id = ?
            ORDER BY rs.created_at DESC, rs.id DESC
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

if (!function_exists('fetchRouteSlipsForAdviser')) {
    function fetchRouteSlipsForAdviser(mysqli $conn, int $adviserId, int $limit = 10): array
    {
        if ($adviserId <= 0) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT rs.*, CONCAT(u.firstname, ' ', u.lastname) AS student_name
            FROM route_slips rs
            JOIN users u ON u.id = rs.student_id
            WHERE rs.adviser_id = ?
            ORDER BY rs.created_at DESC, rs.id DESC
            LIMIT ?
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $adviserId, $limit);
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
