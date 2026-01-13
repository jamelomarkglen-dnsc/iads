<?php
/**
 * Utility helpers for working with application notifications.
 *
 * Provides small wrappers to create, fetch and update notification records.
 * Designed so feature files can include once and call the helper functions.
 */

if (!function_exists('notifications_bootstrap')) {
    /**
     * Ensure the notifications table exists.
     */
    function notifications_bootstrap(mysqli $conn): void
    {
        static $initialised = false;
        if ($initialised) {
            return;
        }

        $createSql = "
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                role VARCHAR(50) NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                link VARCHAR(255) DEFAULT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_role (role),
                INDEX idx_is_read (is_read),
                INDEX idx_created_at (created_at),
                CONSTRAINT fk_notifications_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";

        $conn->query($createSql);
        $initialised = true;
    }
}

if (!function_exists('create_notification')) {
    /**
     * Create a notification targeted to a specific user, a role, or globally.
     */
    function create_notification(
        mysqli $conn,
        ?int $userId,
        ?string $role,
        string $title,
        string $message,
        ?string $link = null
    ): bool {
        notifications_bootstrap($conn);

        if ($userId === null && ($role === null || $role === '')) {
            // Reject writes that would notify nobody.
            return false;
        }

        $sql = "INSERT INTO notifications (user_id, role, title, message, link)
                VALUES (NULLIF(?, 0), NULLIF(?, ''), ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $userParam = $userId ?? 0;
        $roleParam = $role ?? '';
        $stmt->bind_param(
            'issss',
            $userParam,
            $roleParam,
            $title,
            $message,
            $link
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('notifications_current_user_id')) {
    function notifications_current_user_id(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('notifications_current_user_role')) {
    function notifications_current_user_role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }
}

if (!function_exists('fetch_user_default_role')) {
    function fetch_user_default_role(mysqli $conn, ?int $userId): string
    {
        $userId = (int)($userId ?? 0);
        if ($userId <= 0) {
            return '';
        }
        $stmt = $conn->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return trim((string)($row['role'] ?? ''));
    }
}

if (!function_exists('notify_user')) {
    function notify_user(
        mysqli $conn,
        int $userId,
        string $title,
        string $message,
        ?string $link = null,
        bool $skipSelf = true
    ): bool {
        if ($skipSelf && notifications_current_user_id() !== null && (int)notifications_current_user_id() === (int)$userId) {
            return false;
        }

        return create_notification($conn, $userId, null, $title, $message, $link);
    }
}

if (!function_exists('notify_user_for_role')) {
    function notify_user_for_role(
        mysqli $conn,
        int $userId,
        string $role,
        string $title,
        string $message,
        ?string $link = null,
        bool $skipSelf = true
    ): bool {
        if ($skipSelf && notifications_current_user_id() !== null && (int)notifications_current_user_id() === (int)$userId) {
            return false;
        }

        $role = trim($role);
        return create_notification($conn, $userId, $role !== '' ? $role : null, $title, $message, $link);
    }
}

if (!function_exists('notify_users')) {
    /**
     * Notify multiple users by id. Returns number of notifications created.
     */
    function notify_users(
        mysqli $conn,
        array $userIds,
        string $title,
        string $message,
        ?string $link = null,
        bool $skipSelf = true
    ): int {
        $count = 0;
        foreach ($userIds as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            if (notify_user($conn, $id, $title, $message, $link, $skipSelf)) {
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('notify_role')) {
    function notify_role(
        mysqli $conn,
        string $role,
        string $title,
        string $message,
        ?string $link = null,
        bool $skipSelf = true
    ): int {
        $role = trim($role);
        if ($role === '') {
            return 0;
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE role = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $userIds = [];
        while ($row = $result->fetch_assoc()) {
            $userIds[] = (int)$row['id'];
        }
        $stmt->close();

        return notify_users($conn, $userIds, $title, $message, $link, $skipSelf);
    }
}

if (!function_exists('notify_roles')) {
    function notify_roles(
        mysqli $conn,
        array $roles,
        string $title,
        string $message,
        ?string $link = null,
        bool $skipSelf = true
    ): int {
        $total = 0;
        $seen = [];
        foreach ($roles as $role) {
            $role = trim((string)$role);
            if ($role === '') {
                continue;
            }
            $stmt = $conn->prepare("SELECT id FROM users WHERE role = ?");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('s', $role);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $id = (int)$row['id'];
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if (notify_user($conn, $id, $title, $message, $link, $skipSelf)) {
                    $total++;
                }
            }
            $stmt->close();
        }
        return $total;
    }
}

if (!function_exists('notify_members_by_names')) {
    /**
     * Resolve full name strings to user IDs and send notifications.
     */
    function notify_members_by_names(
        mysqli $conn,
        array $names,
        string $title,
        string $message,
        ?string $link = null,
        bool $skipSelf = true
    ): int {
        $total = 0;
        $seen = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $memberId = resolve_user_id_by_name($conn, $name);
            if ($memberId !== null && !isset($seen[$memberId])) {
                $seen[$memberId] = true;
                if (notify_user($conn, $memberId, $title, $message, $link, $skipSelf)) {
                    $total++;
                }
            }
        }
        return $total;
    }
}

if (!function_exists('fetch_user_notifications')) {
    /**
     * Fetch notifications for a user and/or their role.
     *
     * @return array<int, array<string,mixed>>
     */
    function fetch_user_notifications(
        mysqli $conn,
        ?int $userId,
        ?string $role,
        int $limit = 10,
        ?string $defaultRole = null
    ): array {
        notifications_bootstrap($conn);

        if ($userId === null && ($role === null || $role === '')) {
            return [];
        }

        $conditions = [];
        $params = [];
        $types = '';
        $role = trim((string)($role ?? ''));
        if ($userId !== null) {
            $userConditions = [];
            if ($role !== '') {
                $userConditions[] = '(user_id = ? AND role = ?)';
                $params[] = (int)$userId;
                $params[] = $role;
                $types .= 'is';
            }
            // Always include user-targeted notifications regardless of active role.
            $userConditions[] = "(user_id = ? AND (role IS NULL OR role = ''))";
            $params[] = (int)$userId;
            $types .= 'i';
            $conditions[] = '(' . implode(' OR ', $userConditions) . ')';
        }
        if ($role !== null && $role !== '') {
            $conditions[] = '(role = ? AND (user_id IS NULL OR user_id = 0))';
            $params[] = $role;
            $types .= 's';
        }

        // Optional broadcast notifications (no specific target).
        $conditions[] = '(user_id IS NULL AND role IS NULL)';

        $where = implode(' OR ', $conditions);
        $sql = "
            SELECT id, user_id, role, title, message, link, is_read, created_at
            FROM notifications
            WHERE $where
            ORDER BY is_read ASC, created_at DESC
            LIMIT ?
        ";

        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $rows ?: [];
    }
}

if (!function_exists('count_unread_notifications')) {
    function count_unread_notifications(
        mysqli $conn,
        ?int $userId,
        ?string $role,
        ?string $defaultRole = null
    ): int {
        notifications_bootstrap($conn);

        if ($userId === null && ($role === null || $role === '')) {
            return 0;
        }

        $conditions = ['is_read = 0'];
        $params = [];
        $types = '';

        $targetConditions = [];
        $role = trim((string)($role ?? ''));
        if ($userId !== null) {
            $userConditions = [];
            if ($role !== '') {
                $userConditions[] = '(user_id = ? AND role = ?)';
                $params[] = (int)$userId;
                $params[] = $role;
                $types .= 'is';
            }
            // Always include user-targeted notifications regardless of active role.
            $userConditions[] = "(user_id = ? AND (role IS NULL OR role = ''))";
            $params[] = (int)$userId;
            $types .= 'i';
            $targetConditions[] = '(' . implode(' OR ', $userConditions) . ')';
        }
        if ($role !== null && $role !== '') {
            $targetConditions[] = '(role = ? AND (user_id IS NULL OR user_id = 0))';
            $params[] = $role;
            $types .= 's';
        }
        $targetConditions[] = '(user_id IS NULL AND role IS NULL)';

        $conditions[] = '(' . implode(' OR ', $targetConditions) . ')';

        $sql = 'SELECT COUNT(*) AS total FROM notifications WHERE ' . implode(' AND ', $conditions);
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result ? (int)($result->fetch_assoc()['total'] ?? 0) : 0;
        $stmt->close();
        return $count;
    }
}

if (!function_exists('mark_notification_read')) {
    function mark_notification_read(mysqli $conn, int $notificationId, ?int $userId, ?string $role): bool
    {
        notifications_bootstrap($conn);

        if ($userId === null && ($role === null || $role === '')) {
            return false;
        }

        $sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND (
                    (user_id IS NOT NULL AND user_id = ?)
                 OR (role IS NOT NULL AND role = ?)
                 OR (user_id IS NULL AND role IS NULL)
              )
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $userParam = $userId ?? 0;
        $roleParam = $role ?? '';
        $stmt->bind_param('iss', $notificationId, $userParam, $roleParam);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('mark_all_notifications_read')) {
    function mark_all_notifications_read(mysqli $conn, ?int $userId, ?string $role): bool
    {
        notifications_bootstrap($conn);

        if ($userId === null && ($role === null || $role === '')) {
            return false;
        }

        $conditions = [];
        $types = '';
        $params = [];

        if ($userId !== null) {
            $conditions[] = '(user_id = ?)';
            $types .= 'i';
            $params[] = (int)$userId;
        }
        if ($role !== null && $role !== '') {
            $conditions[] = '(role = ?)';
            $types .= 's';
            $params[] = $role;
        }
        $conditions[] = '(user_id IS NULL AND role IS NULL)';

        $where = implode(' OR ', $conditions);
        $sql = "UPDATE notifications SET is_read = 1 WHERE ($where) AND is_read = 0";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('resolve_user_id_by_name')) {
    /**
     * Attempt to resolve a user id by a full name string.
     */
    function resolve_user_id_by_name(mysqli $conn, string $fullName): ?int
    {
        $name = trim($fullName);
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) {
            return null;
        }

        $first = $parts[0];
        $last = $parts[count($parts) - 1];

        $sql = "SELECT id FROM users WHERE LOWER(firstname) = LOWER(?) AND LOWER(lastname) = LOWER(?) LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $first, $last);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $user ? (int)$user['id'] : null;
    }
}

if (!function_exists('notify_outline_defense_submission')) {
    /**
     * Notify reviewers when a student submits an outline defense manuscript.
     */
    function notify_outline_defense_submission(
        mysqli $conn,
        int $studentId,
        int $submissionId,
        string $studentName,
        array $reviewerIds
    ): int {
        $count = 0;
        $link = 'outline_defense_review.php?submission_id=' . $submissionId;
        
        foreach ($reviewerIds as $reviewerId) {
            $reviewerId = (int)$reviewerId;
            if ($reviewerId <= 0) {
                continue;
            }
            if (notify_user(
                $conn,
                $reviewerId,
                'Outline Defense Manuscript Submitted',
                "{$studentName} has submitted an outline defense manuscript for your review.",
                $link,
                true
            )) {
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('notify_outline_defense_review_completed')) {
    /**
     * Notify student when a reviewer completes their review.
     */
    function notify_outline_defense_review_completed(
        mysqli $conn,
        int $studentId,
        string $reviewerName,
        string $reviewerRole,
        string $reviewStatus
    ): bool {
        $roleLabel = match ($reviewerRole) {
            'adviser' => 'Thesis Adviser',
            'committee_chairperson' => 'Committee Chairperson',
            'panel' => 'Panel Member',
            default => ucfirst(str_replace('_', ' ', $reviewerRole)),
        };

        $statusLabel = match ($reviewStatus) {
            'Approved' => 'Approved',
            'Rejected' => 'Rejected',
            'Needs Revision' => 'Needs Revision',
            'Minor Revision' => 'Passed with Minor Revision',
            'Major Revision' => 'Passed with Major Revision',
            default => $reviewStatus,
        };

        return notify_user(
            $conn,
            $studentId,
            'Outline Defense Review Completed',
            "{$reviewerName} ({$roleLabel}) has completed their review. Status: {$statusLabel}",
            'submit_final_paper.php',
            false
        );
    }
}

if (!function_exists('notify_outline_defense_decision')) {
    /**
     * Notify student when the committee chairperson makes a final decision.
     */
    function notify_outline_defense_decision(
        mysqli $conn,
        int $studentId,
        string $chairName,
        string $verdict,
        string $decisionNotes = ''
    ): bool {
        $verdictLabel = match ($verdict) {
            'Passed' => 'Passed',
            'Passed with Revision' => 'Passed with Revision',
            'Failed' => 'Failed',
            default => $verdict,
        };

        $message = "{$chairName} has made the final decision on your outline defense manuscript. Verdict: {$verdictLabel}.";
        if ($decisionNotes !== '') {
            $message .= " Please check your submission page for the detailed decision notes.";
        }

        return notify_user(
            $conn,
            $studentId,
            'Outline Defense Final Decision',
            $message,
            'submit_final_paper.php',
            false
        );
    }
}

if (!function_exists('notify_outline_defense_route_slip_submitted')) {
    /**
     * Notify reviewers when a student submits a route slip packet.
     */
    function notify_outline_defense_route_slip_submitted(
        mysqli $conn,
        int $studentId,
        int $submissionId,
        string $studentName,
        array $reviewerIds,
        bool $hasRevision = false
    ): int {
        $count = 0;
        $link = 'outline_defense_review.php?submission_id=' . $submissionId;
        $message = "{$studentName} submitted the route slip" . ($hasRevision ? ' and revised manuscript' : '') . " for review.";
        
        foreach ($reviewerIds as $reviewerId) {
            $reviewerId = (int)$reviewerId;
            if ($reviewerId <= 0) {
                continue;
            }
            if (notify_user(
                $conn,
                $reviewerId,
                'Route Slip Packet Submitted',
                $message,
                $link,
                true
            )) {
                $count++;
            }
        }
        return $count;
    }
}
