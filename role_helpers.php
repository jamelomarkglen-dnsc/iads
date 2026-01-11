<?php
if (!function_exists('getRoleDefinitions')) {
    function getRoleDefinitions(): array
    {
        return [
            'student' => [
                'label' => 'Student',
                'dashboard' => 'student_dashboard.php',
                'switchable' => false,
            ],
            'dean' => [
                'label' => 'Dean',
                'dashboard' => 'dean.php',
                'switchable' => false,
            ],
            // Removed 'reviewer' role
            'program_chairperson' => [
                'label' => 'Program Chairperson',
                'dashboard' => 'program_chairperson.php',
                'switchable' => true,
            ],
            'faculty' => [
                'label' => 'Faculty',
                'dashboard' => 'subject_specialist_dashboard.php',
                'switchable' => true,
            ],
            'adviser' => [
                'label' => 'Adviser',
                'dashboard' => 'adviser.php',
                'switchable' => true,
            ],
            'committee_chair' => [
                'label' => 'Committee Chair',
                'dashboard' => 'my_committee_defense.php',
                'switchable' => true,
            ],
            'committee_chairperson' => [
                'label' => 'Committee Chairperson',
                'dashboard' => 'my_committee_defense.php',
                'switchable' => true,
            ],
            'panel' => [
                'label' => 'Panel Member',
                'dashboard' => 'my_assign_defense.php',
                'switchable' => true,
            ],
        ];
    }
}

if (!function_exists('roleIsSwitchable')) {
    function roleIsSwitchable(string $roleCode): bool
    {
        $definitions = getRoleDefinitions();
        if (!isset($definitions[$roleCode])) {
            return true;
        }
        return (bool)$definitions[$roleCode]['switchable'];
    }
}

if (!function_exists('getAutoAssignableRoles')) {
    function getAutoAssignableRoles(string $baseRole): array
    {
        $baseRole = trim($baseRole);
        if ($baseRole === '') {
            return [];
        }

        $nonSwitchable = ['student', 'dean'];
        if (in_array($baseRole, $nonSwitchable, true)) {
            return [$baseRole];
        }

        if ($baseRole === 'program_chairperson') {
            return [
                'program_chairperson',
                'faculty',
                'adviser',
                'committee_chairperson',
                'panel',
            ];
        }

        if ($baseRole === 'faculty') {
            return [
                'faculty',
                'adviser',
                'committee_chairperson',
                'panel',
            ];
        }

        $bundle = [$baseRole];
        $facultyLinkedRoles = ['adviser', 'panel', 'committee_chair', 'committee_chairperson'];
        if (in_array($baseRole, $facultyLinkedRoles, true)) {
            $bundle[] = 'faculty';
        }

        // Ensure unique roles and remove any empty values
        $bundle = array_values(array_unique(array_filter($bundle)));
        return $bundle;
    }
}

if (!function_exists('ensureRoleBundleAssignments')) {
    function ensureRoleBundleAssignments(mysqli $conn, int $userId, string $baseRole): void
    {
        if ($userId <= 0 || $baseRole === '') {
            return;
        }
        $roles = getAutoAssignableRoles($baseRole);
        foreach ($roles as $roleCode) {
            ensureUserRoleAssignment($conn, $userId, $roleCode);
        }
    }
}

if (!function_exists('getRoleLabel')) {
    function getRoleLabel(string $roleCode): string
    {
        $definitions = getRoleDefinitions();
        if (isset($definitions[$roleCode])) {
            return $definitions[$roleCode]['label'];
        }
        return ucwords(str_replace('_', ' ', $roleCode));
    }
}

if (!function_exists('getRoleDashboard')) {
    function getRoleDashboard(string $roleCode): string
    {
        $definitions = getRoleDefinitions();
        if (isset($definitions[$roleCode]['dashboard'])) {
            return $definitions[$roleCode]['dashboard'];
        }
        return 'login.php';
    }
}

if (!function_exists('ensureRoleInfrastructure')) {
    function ensureRoleInfrastructure(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS roles (
                code varchar(50) NOT NULL PRIMARY KEY,
                label varchar(100) NOT NULL,
                dashboard varchar(255) NOT NULL,
                is_switchable tinyint(1) NOT NULL DEFAULT 1,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                updated_at timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id int NOT NULL,
                role_code varchar(50) NOT NULL,
                is_primary tinyint(1) NOT NULL DEFAULT 0,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (user_id, role_code),
                KEY idx_user_roles_primary (user_id, is_primary),
                CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_user_roles_role FOREIGN KEY (role_code)
                    REFERENCES roles (code) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS user_role_switch_logs (
                id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id int NOT NULL,
                from_role varchar(50) NOT NULL,
                to_role varchar(50) NOT NULL,
                switched_at timestamp NOT NULL DEFAULT current_timestamp(),
                CONSTRAINT fk_role_switch_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");

        $definitions = getRoleDefinitions();
        $stmt = $conn->prepare("
            INSERT INTO roles (code, label, dashboard, is_switchable)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                dashboard = VALUES(dashboard),
                is_switchable = VALUES(is_switchable)
        ");

        if ($stmt) {
            foreach ($definitions as $code => $data) {
                $label = $data['label'];
                $dashboard = $data['dashboard'];
                $switchable = (int)$data['switchable'];
                $stmt->bind_param('sssi', $code, $label, $dashboard, $switchable);
                $stmt->execute();
            }
            $stmt->close();
        }

        $ensured = true;
    }
}

if (!function_exists('ensureUserRoleAssignment')) {
    function ensureUserRoleAssignment(mysqli $conn, int $userId, string $roleCode): void
    {
        if ($userId <= 0 || $roleCode === '') {
            return;
        }

        $userCheck = $conn->prepare("
            SELECT 1 FROM users
            WHERE id = ? LIMIT 1
        ");
        if ($userCheck) {
            $userCheck->bind_param('i', $userId);
            $userCheck->execute();
            $userCheck->store_result();
            $userExists = $userCheck->num_rows > 0;
            $userCheck->close();
            if (!$userExists) {
                return;
            }
        } else {
            return;
        }

        $checkStmt = $conn->prepare("
            SELECT role_code FROM user_roles
            WHERE user_id = ? AND role_code = ? LIMIT 1
        ");

        if ($checkStmt) {
            $checkStmt->bind_param('is', $userId, $roleCode);
            $checkStmt->execute();
            $checkStmt->store_result();
            $exists = $checkStmt->num_rows > 0;
            $checkStmt->close();
            if ($exists) {
                return;
            }
        }

        $primaryCheck = $conn->prepare("
            SELECT role_code FROM user_roles
            WHERE user_id = ? AND is_primary = 1 LIMIT 1
        ");

        $hasPrimary = false;
        if ($primaryCheck) {
            $primaryCheck->bind_param('i', $userId);
            $primaryCheck->execute();
            $primaryCheck->store_result();
            $hasPrimary = $primaryCheck->num_rows > 0;
            $primaryCheck->close();
        }

        $isPrimary = $hasPrimary ? 0 : 1;
        $insertStmt = $conn->prepare("
            INSERT INTO user_roles (user_id, role_code, is_primary)
            VALUES (?, ?, ?)
        ");

        if ($insertStmt) {
            $insertStmt->bind_param('isi', $userId, $roleCode, $isPrimary);
            $insertStmt->execute();
            $insertStmt->close();
        }
    }
}

if (!function_exists('fetchUserRoleAssignments')) {
    function fetchUserRoleAssignments(mysqli $conn, int $userId): array
    {
        $assignments = [];
        if ($userId <= 0) {
            return $assignments;
        }

        $sql = "
            SELECT
                ur.role_code,
                ur.is_primary,
                r.label,
                r.dashboard,
                r.is_switchable
            FROM user_roles ur
            LEFT JOIN roles r ON r.code = ur.role_code
            WHERE ur.user_id = ?
            ORDER BY ur.is_primary DESC, r.label ASC
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $assignments[] = [
                    'code' => $row['role_code'],
                    'label' => $row['label'] ?: getRoleLabel($row['role_code']),
                    'dashboard' => $row['dashboard'] ?: getRoleDashboard($row['role_code']),
                    'switchable' => (bool)$row['is_switchable'],
                    'is_primary' => (bool)$row['is_primary'],
                ];
            }
            $stmt->close();
        }

        return $assignments;
    }
}

if (!function_exists('determinePreferredRole')) {
    function determinePreferredRole(array $assignments, string $fallbackRole = ''): string
    {
        foreach ($assignments as $assignment) {
            if (!empty($assignment['is_primary'])) {
                return (string)$assignment['code'];
            }
        }
        if ($fallbackRole !== '') {
            foreach ($assignments as $assignment) {
                if ($assignment['code'] === $fallbackRole) {
                    return $fallbackRole;
                }
            }
        }
        return $assignments[0]['code'] ?? $fallbackRole;
    }
}

if (!function_exists('setActiveRole')) {
    function setActiveRole(string $roleCode): void
    {
        $_SESSION['role'] = $roleCode;
        $_SESSION['active_role'] = $roleCode;
    }
}

if (!function_exists('setUserPrimaryRole')) {
    function setUserPrimaryRole(mysqli $conn, int $userId, string $roleCode): void
    {
        if ($userId <= 0 || $roleCode === '') {
            return;
        }
        $stmt = $conn->prepare("
            UPDATE user_roles
            SET is_primary = CASE WHEN role_code = ? THEN 1 ELSE 0 END
            WHERE user_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('si', $roleCode, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('refreshUserSessionRoles')) {
    function refreshUserSessionRoles(mysqli $conn, int $userId, string $fallbackRole = ''): array
    {
        $assignments = fetchUserRoleAssignments($conn, $userId);
        if (empty($assignments) && $fallbackRole !== '') {
            $assignments[] = [
                'code' => $fallbackRole,
                'label' => getRoleLabel($fallbackRole),
                'dashboard' => getRoleDashboard($fallbackRole),
                'switchable' => roleIsSwitchable($fallbackRole),
                'is_primary' => true,
            ];
        }
        $_SESSION['available_roles'] = $assignments;
        return $assignments;
    }
}

if (!function_exists('getRoleSwitchToken')) {
    function getRoleSwitchToken(): string
    {
        if (empty($_SESSION['role_switch_token'])) {
            try {
                $_SESSION['role_switch_token'] = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                $_SESSION['role_switch_token'] = sha1(uniqid('', true));
            }
        }
        return $_SESSION['role_switch_token'];
    }
}

if (!function_exists('sessionRolesContain')) {
    function sessionRolesContain(array $assignments, string $roleCode): bool
    {
        foreach ($assignments as $assignment) {
            if ($assignment['code'] === $roleCode) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('userHasSwitchableOptions')) {
    function userHasSwitchableOptions(array $assignments): bool
    {
        $switchableCount = 0;
        foreach ($assignments as $assignment) {
            if (!empty($assignment['switchable'])) {
                $switchableCount++;
            }
        }
        return $switchableCount > 1;
    }
}

if (!function_exists('logRoleSwitch')) {
    function logRoleSwitch(mysqli $conn, int $userId, string $fromRole, string $toRole): void
    {
        if ($userId <= 0) {
            return;
        }
        $stmt = $conn->prepare("
            INSERT INTO user_role_switch_logs (user_id, from_role, to_role)
            VALUES (?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param('iss', $userId, $fromRole, $toRole);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('getPermittedAssignmentRoles')) {
    function getPermittedAssignmentRoles(string $role): array
    {
        $roleMap = [
            'committee_chairperson' => ['committee_chair', 'committee_chairperson'],
            'faculty' => ['faculty', 'panel', 'adviser'],
            'adviser' => ['faculty', 'adviser'],
            'panel' => ['faculty', 'panel'],
        ];
        if (isset($roleMap[$role])) {
            return $roleMap[$role];
        }
        return [$role];
    }
}

if (!function_exists('validateRoleAssignment')) {
    function validateRoleAssignment(mysqli $conn, int $userId, string $currentRole, string $newRole): bool
    {
        // Prevent role assignment if not permitted
        $permittedRoles = getPermittedAssignmentRoles($currentRole);
        
        if (!in_array($newRole, $permittedRoles, true)) {
            return false;
        }

        // Additional checks can be added here, such as:
        // - Checking user's existing roles
        // - Verifying role-specific permissions
        // - Logging role change attempts

        return true;
    }
}

if (!function_exists('getPermittedAssignmentRoles')) {
    function getPermittedAssignmentRoles(string $role): array
    {
        $roleMap = [
            'committee_chairperson' => ['committee_chair', 'committee_chairperson'],
            'faculty' => ['faculty', 'panel', 'adviser', 'reviewer'],
            'reviewer' => ['faculty', 'reviewer'],
            'adviser' => ['faculty', 'adviser'],
            'panel' => ['faculty', 'panel'],
        ];
        if (isset($roleMap[$role])) {
            return $roleMap[$role];
        }
        return [$role];
    }
}

if (!function_exists('enforce_role_access')) {
    function enforce_role_access(array $allowedRoles): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php');
            exit;
        }

        $role = $_SESSION['role'] ?? '';
        if ($role === '' || !in_array($role, $allowedRoles, true)) {
            if ($role !== '') {
                $target = getRoleDashboard($role);
                $current = basename($_SERVER['PHP_SELF'] ?? '');
                if ($target !== '' && $target !== $current) {
                    header("Location: {$target}");
                    exit;
                }
            }
            header('Location: login.php');
            exit;
        }
    }
}
