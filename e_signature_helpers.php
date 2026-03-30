<?php

if (!function_exists('e_signature_user_column_exists')) {
    function e_signature_user_column_exists(mysqli $conn, string $column = 'signature_path'): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $columnEscaped = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = '{$columnEscaped}'
            LIMIT 1
        ";
        $result = $conn->query($sql);
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        $cache[$column] = $exists;
        return $exists;
    }
}

if (!function_exists('e_signature_find_latest_match')) {
    function e_signature_find_latest_match(string $pattern): string
    {
        $matches = glob($pattern) ?: [];
        if (empty($matches)) {
            return '';
        }
        usort($matches, function ($a, $b) {
            return @filemtime($b) <=> @filemtime($a);
        });
        return $matches[0] ?? '';
    }
}

if (!function_exists('get_user_signature_path')) {
    function get_user_signature_path(mysqli $conn, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $signaturePath = '';
        if (e_signature_user_column_exists($conn, 'signature_path')) {
            $stmt = $conn->prepare("SELECT signature_path FROM users WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc();
                    $signaturePath = trim((string)($row['signature_path'] ?? ''));
                    $result->free();
                }
                $stmt->close();
            }
        }

        if ($signaturePath !== '' && is_file($signaturePath)) {
            return $signaturePath;
        }

        $legacyBase = 'uploads/signatures/user_' . $userId;
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $legacyPath = $legacyBase . '.' . $ext;
            if (is_file($legacyPath)) {
                return $legacyPath;
            }
        }

        $latestMatch = e_signature_find_latest_match('uploads/signatures/signature_' . $userId . '_*');
        if ($latestMatch !== '' && is_file($latestMatch)) {
            return $latestMatch;
        }

        return '';
    }
}

if (!function_exists('require_user_signature')) {
    function require_user_signature(mysqli $conn, int $userId, array &$errors, string $message): string
    {
        $signaturePath = get_user_signature_path($conn, $userId);
        if ($signaturePath === '') {
            $errors[] = $message;
        }
        return $signaturePath;
    }
}
