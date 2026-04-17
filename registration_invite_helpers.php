<?php
if (!function_exists('registration_invite_allowed_roles')) {
    function registration_invite_allowed_roles(): array
    {
        return ['faculty', 'program_chairperson'];
    }
}

if (!function_exists('registration_invite_role_label')) {
    function registration_invite_role_label(string $role): string
    {
        $role = trim($role);
        if ($role === 'faculty') {
            return 'Faculty';
        }
        if ($role === 'program_chairperson') {
            return 'Program Chairperson';
        }
        if ($role === 'dean') {
            return 'Dean';
        }
        return ucwords(str_replace('_', ' ', $role));
    }
}

if (!function_exists('registration_invite_column_exists')) {
    function registration_invite_column_exists(mysqli $conn, string $table, string $column): bool
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

if (!function_exists('ensure_registration_account_status_column')) {
    function ensure_registration_account_status_column(mysqli $conn): bool
    {
        if (registration_invite_column_exists($conn, 'users', 'account_status')) {
            return true;
        }
        return (bool)$conn->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER role");
    }
}

if (!function_exists('ensure_registration_invites_table')) {
    function ensure_registration_invites_table(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS registration_invites (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                invite_token_hash CHAR(64) NOT NULL UNIQUE,
                email VARCHAR(100) NOT NULL,
                role VARCHAR(50) NOT NULL,
                created_by INT NULL,
                used_user_id INT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_registration_invites_email (email),
                INDEX idx_registration_invites_role (role),
                INDEX idx_registration_invites_status (used_at, expires_at),
                CONSTRAINT fk_registration_invites_creator FOREIGN KEY (created_by)
                    REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_registration_invites_user FOREIGN KEY (used_user_id)
                    REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
        $ensured = true;
    }
}

if (!function_exists('create_registration_invite')) {
    function create_registration_invite(
        mysqli $conn,
        string $email,
        string $role,
        int $createdBy = 0,
        int $validDays = 7
    ): ?array {
        $email = trim($email);
        $role = trim($role);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (!in_array($role, registration_invite_allowed_roles(), true)) {
            return null;
        }
        $validDays = max(1, min(30, $validDays));
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = (new DateTimeImmutable('now'))->modify("+{$validDays} days")->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO registration_invites (invite_token_hash, email, role, created_by, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('sssis', $tokenHash, $email, $role, $createdBy, $expiresAt);
        $ok = $stmt->execute();
        $inviteId = $conn->insert_id;
        $stmt->close();
        if (!$ok) {
            return null;
        }

        return [
            'id' => (int)$inviteId,
            'token' => $token,
            'token_hash' => $tokenHash,
            'email' => $email,
            'role' => $role,
            'expires_at' => $expiresAt,
        ];
    }
}

if (!function_exists('get_registration_invite_by_token')) {
    function get_registration_invite_by_token(mysqli $conn, string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $tokenHash = hash('sha256', $token);
        $stmt = $conn->prepare("
            SELECT id, email, role, created_by, used_user_id, expires_at, used_at, created_at
            FROM registration_invites
            WHERE invite_token_hash = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $expiresAt = strtotime((string)($row['expires_at'] ?? ''));
        $usedAt = trim((string)($row['used_at'] ?? ''));
        $row['is_expired'] = $expiresAt ? ($expiresAt < time()) : true;
        $row['is_used'] = $usedAt !== '';
        return $row;
    }
}

if (!function_exists('consume_registration_invite')) {
    function consume_registration_invite(mysqli $conn, int $inviteId, int $userId): bool
    {
        if ($inviteId <= 0 || $userId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("
            UPDATE registration_invites
            SET used_at = COALESCE(used_at, NOW()),
                used_user_id = COALESCE(used_user_id, ?)
            WHERE id = ?
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $userId, $inviteId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
