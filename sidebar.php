<?php
if (!isset($_SESSION)) {
    session_start();
}

if (!isset($conn)) {
    require_once 'db.php';
}
require_once 'role_helpers.php';
ensureRoleInfrastructure($conn);

if (!function_exists('sidebar_user_column_exists')) {
    function sidebar_user_column_exists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
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

if (!function_exists('ensure_sidebar_user_settings')) {
    function ensure_sidebar_user_settings(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $columns = [
            'notify_enabled' => "ALTER TABLE users ADD COLUMN notify_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER email",
            'timezone' => "ALTER TABLE users ADD COLUMN timezone VARCHAR(64) DEFAULT NULL AFTER notify_enabled",
            'sidebar_compact' => "ALTER TABLE users ADD COLUMN sidebar_compact TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone",
        ];
        foreach ($columns as $column => $sql) {
            if (!sidebar_user_column_exists($conn, $column)) {
                $conn->query($sql);
            }
        }
        $ensured = true;
    }
}

ensure_sidebar_user_settings($conn);

$currentPage = basename($_SERVER['PHP_SELF']); 
$role = $_SESSION['role'] ?? ''; 
$requestedSection = $_GET['section'] ?? '';
$isCommitteeChairPage = ($currentPage === 'committee_chair.php');
$userId = $_SESSION['user_id'] ?? null;
$userFullName = $_SESSION['user_fullname'] ?? '';
$userRoleAssignments = $_SESSION['available_roles'] ?? [];

$accountSettingsMessage = null;
$accountSettingsOpen = false;
$userProfile = [
    'firstname' => '',
    'lastname' => '',
    'email' => '',
    'contact' => '',
    'program' => '',
    'department' => '',
    'photo' => '',
    'created_at' => '',
    'notify_enabled' => 1,
    'timezone' => '',
    'sidebar_compact' => 0,
];
$userPasswordHash = '';
$userLastLogin = '';

if ($userFullName === '' && $userId) {
    $nameStmt = $conn->prepare("SELECT CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, '')) AS full_name FROM users WHERE id = ? LIMIT 1");
    if ($nameStmt) {
        $nameStmt->bind_param('i', $userId);
        if ($nameStmt->execute()) {
            $result = $nameStmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $fetchedName = trim($row['full_name'] ?? '');
            if ($fetchedName !== '') {
                $userFullName = $fetchedName;
                $_SESSION['user_fullname'] = $userFullName;
            }
        }
        $nameStmt->close();
    }
}

if ($userId) {
    $columns = ['firstname', 'lastname', 'email', 'contact', 'program', 'department', 'photo', 'created_at', 'password'];
    if (sidebar_user_column_exists($conn, 'notify_enabled')) {
        $columns[] = 'notify_enabled';
    }
    if (sidebar_user_column_exists($conn, 'timezone')) {
        $columns[] = 'timezone';
    }
    if (sidebar_user_column_exists($conn, 'sidebar_compact')) {
        $columns[] = 'sidebar_compact';
    }
    if (sidebar_user_column_exists($conn, 'last_login')) {
        $columns[] = 'last_login';
    } elseif (sidebar_user_column_exists($conn, 'last_login_at')) {
        $columns[] = 'last_login_at';
    }
    $sql = "SELECT " . implode(', ', $columns) . " FROM users WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($row) {
                $userProfile['firstname'] = (string)($row['firstname'] ?? '');
                $userProfile['lastname'] = (string)($row['lastname'] ?? '');
                $userProfile['email'] = (string)($row['email'] ?? '');
                $userProfile['contact'] = (string)($row['contact'] ?? '');
                $userProfile['program'] = (string)($row['program'] ?? '');
                $userProfile['department'] = (string)($row['department'] ?? '');
                $userProfile['photo'] = (string)($row['photo'] ?? '');
                $userProfile['created_at'] = (string)($row['created_at'] ?? '');
                $userProfile['notify_enabled'] = isset($row['notify_enabled']) ? (int)$row['notify_enabled'] : 1;
                $userProfile['timezone'] = (string)($row['timezone'] ?? '');
                $userProfile['sidebar_compact'] = isset($row['sidebar_compact']) ? (int)$row['sidebar_compact'] : 0;
                $userPasswordHash = (string)($row['password'] ?? '');
                $userLastLogin = (string)($row['last_login'] ?? $row['last_login_at'] ?? '');
            }
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_settings_action']) && $userId) {
    $accountSettingsOpen = true;
    $errors = [];
    $updates = [];
    $params = [];
    $types = '';

    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $timezone = trim($_POST['timezone'] ?? '');
    $notifyEnabled = isset($_POST['notify_enabled']) ? 1 : 0;
    $sidebarCompact = isset($_POST['sidebar_compact']) ? 1 : 0;

    if ($firstName === '' || $lastName === '') {
        $errors[] = 'Please provide your first and last name.';
    } else {
        $updates[] = 'firstname = ?';
        $params[] = $firstName;
        $types .= 's';
        $updates[] = 'lastname = ?';
        $params[] = $lastName;
        $types .= 's';
    }

    $updates[] = 'contact = ?';
    $params[] = $contact;
    $types .= 's';

    if (sidebar_user_column_exists($conn, 'notify_enabled')) {
        $updates[] = 'notify_enabled = ?';
        $params[] = $notifyEnabled;
        $types .= 'i';
    }
    if (sidebar_user_column_exists($conn, 'timezone')) {
        $updates[] = 'timezone = ?';
        $params[] = $timezone;
        $types .= 's';
    }
    if (sidebar_user_column_exists($conn, 'sidebar_compact')) {
        $updates[] = 'sidebar_compact = ?';
        $params[] = $sidebarCompact;
        $types .= 'i';
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '') {
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $errors[] = 'Please complete all password fields.';
        } elseif (!password_verify($currentPassword, $userPasswordHash)) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif (
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword) ||
            !preg_match('/[^A-Za-z0-9]/', $newPassword)
        ) {
            $errors[] = 'New password must include uppercase, lowercase, number, and symbol.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password confirmation does not match.';
        } else {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $updates[] = 'password = ?';
            $params[] = $hashed;
            $types .= 's';
        }
    }

    $avatarUpdated = false;
    if (isset($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $fileInfo = $_FILES['avatar'];
        if (($fileInfo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'Unable to upload the avatar image.';
        } else {
            $extension = strtolower(pathinfo($fileInfo['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($extension, $allowed, true)) {
                $errors[] = 'Avatar must be a JPG, PNG, or WEBP image.';
            } else {
                $uploadDir = 'uploads/avatars/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($fileInfo['name']));
                $filename = 'avatar_' . $userId . '_' . date('Ymd_His') . '_' . $safeName;
                $filePath = $uploadDir . $filename;
                if (!move_uploaded_file($fileInfo['tmp_name'], $filePath)) {
                    $errors[] = 'Unable to save the avatar image.';
                } else {
                    if (!empty($userProfile['photo']) && $userProfile['photo'] !== $filePath && file_exists($userProfile['photo'])) {
                        @unlink($userProfile['photo']);
                    }
                    $updates[] = 'photo = ?';
                    $params[] = $filePath;
                    $types .= 's';
                    $avatarUpdated = true;
                }
            }
        }
    }

    if (empty($errors) && !empty($updates)) {
        $params[] = $userId;
        $types .= 'i';
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $accountSettingsMessage = ['type' => 'success', 'text' => 'Account settings updated.'];
                $userProfile['firstname'] = $firstName;
                $userProfile['lastname'] = $lastName;
                $userProfile['contact'] = $contact;
                $userProfile['timezone'] = $timezone;
                $userProfile['notify_enabled'] = $notifyEnabled;
                $userProfile['sidebar_compact'] = $sidebarCompact;
                if ($avatarUpdated) {
                    $userProfile['photo'] = $filePath;
                }
                $userFullName = trim($firstName . ' ' . $lastName);
                $_SESSION['user_fullname'] = $userFullName;
                $_SESSION['firstname'] = $firstName;
                $_SESSION['lastname'] = $lastName;
            } else {
                $accountSettingsMessage = ['type' => 'danger', 'text' => 'Unable to update account settings.'];
            }
            $stmt->close();
        } else {
            $accountSettingsMessage = ['type' => 'danger', 'text' => 'Unable to prepare account settings update.'];
        }
    } elseif (!empty($errors)) {
        $accountSettingsMessage = ['type' => 'danger', 'text' => implode(' ', $errors)];
    }
}

if ($userId && $role !== '') {
    ensureRoleBundleAssignments($conn, (int)$userId, $role);
    $desiredBundle = getAutoAssignableRoles($role);
    $currentCodes = array_map(function ($assignment) {
        return (string)($assignment['code'] ?? '');
    }, $userRoleAssignments);
    $currentCodes = array_filter($currentCodes);
    $needsRefresh = empty($userRoleAssignments) || array_diff($desiredBundle, $currentCodes);
    if (in_array($role, ['faculty', 'program_chairperson'], true)) {
        $needsRefresh = true;
    }
    if ($needsRefresh) {
        $userRoleAssignments = refreshUserSessionRoles($conn, (int)$userId, $role);
    }
}
$roleSwitchToken = $userId ? getRoleSwitchToken() : '';
$roleSwitchError = $_SESSION['role_switch_error'] ?? '';
$roleSwitchSuccess = $_SESSION['role_switch_success'] ?? '';
unset($_SESSION['role_switch_error'], $_SESSION['role_switch_success']);

$dashboardLink = getRoleDashboard($role);

$reviewerDashboardLink = 'subject_specialist_dashboard.php';
if ($role === 'adviser' || $role === 'faculty') {
    $reviewerDashboardLink = 'subject_specialist_dashboard.php';
} elseif (in_array($role, ['committee_chair', 'committee_chairperson'], true)) {
    $reviewerDashboardLink = 'committee_chair_dashboard.php';
}
$reviewerDashboardSlug = basename($reviewerDashboardLink);
$reviewerInboxAllowed = in_array($role, ['committee_chair', 'committee_chairperson'], true);

$facultyMenuPages = ['create_faculty.php', 'assign_adviser.php', 'reviewer_pipeline.php', 'assign_panel.php', 'faculty_reviewer_feedback.php', 'defense_committee.php'];
$studentMenuPages = ['create_student.php', 'student_directory.php', 'submissions.php', 'status_logs.php', 'notice_to_commence.php'];
$recordsMenuPages = ['archive_manager.php', 'receive_payment.php'];
$facultySectionOpen = in_array($currentPage, $facultyMenuPages, true);
$studentSectionOpen = in_array($currentPage, $studentMenuPages, true);
$recordsSectionOpen = in_array($currentPage, $recordsMenuPages, true);
$adviserStudentPages = ['adviser_directory.php', 'advisory.php', 'final_paper_inbox.php'];
$adviserDefensePages = ['my_assigned_defense.php', 'adviser_route_slip.php', 'route_slip_inbox.php'];
$adviserStudentOpen = in_array($currentPage, $adviserStudentPages, true);
$adviserDefenseOpen = in_array($currentPage, $adviserDefensePages, true);
$rolesWithWorkspaceLinks = ['program_chairperson', 'student', 'dean', 'adviser', 'committee_chair', 'committee_chairperson', 'panel'];
$hasWorkspaceLinks = in_array($role, $rolesWithWorkspaceLinks, true);

$userRoleLabel = $role ? getRoleLabel($role) : 'Guest';
$hasRoleAssignments = !empty($userRoleAssignments);
$canSwitchRoles = $hasRoleAssignments && userHasSwitchableOptions($userRoleAssignments);
$avatarPath = $userProfile['photo'] !== '' ? $userProfile['photo'] : 'avatar.png.webp';
$notifyEnabledFlag = (int)($userProfile['notify_enabled'] ?? 1);
$sidebarPreferenceCollapsed = (int)($userProfile['sidebar_compact'] ?? 0);
$timezoneValue = $userProfile['timezone'] !== '' ? $userProfile['timezone'] : 'Asia/Manila';
$accountCreatedAt = $userProfile['created_at'] ?? '';
$accountCreatedLabel = $accountCreatedAt !== '' ? date('M d, Y g:i A', strtotime($accountCreatedAt)) : 'N/A';
$roleList = array_map(function ($assignment) {
    return (string)($assignment['label'] ?? $assignment['code'] ?? '');
}, $userRoleAssignments);
$roleList = array_filter($roleList);
if (empty($roleList) && $role !== '') {
    $roleList = [$userRoleLabel];
}
$userLastLoginLabel = $userLastLogin !== '' ? $userLastLogin : 'Not tracked';
$lastLoginLabel = 'Not tracked';
if ($userLastLogin !== '') {
    $parsedLogin = strtotime($userLastLogin);
    $lastLoginLabel = $parsedLogin ? date('M d, Y g:i A', $parsedLogin) : $userLastLogin;
}
?>
<link href="progchair.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

<!-- SIDEBAR -->
<div id="sidebar" class="sidebar expanded layout-sidebar" data-preference-collapsed="<?php echo $sidebarPreferenceCollapsed ? '1' : '0'; ?>">
    <div class="sidebar-header d-flex align-items-center gap-3">
        <h5 class="sidebar-title text-white mb-0">Menu</h5>
        <button class="btn btn-sm btn-outline-light ms-auto" id="toggleSidebar" aria-expanded="true" aria-label="Toggle sidebar">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>

    <div class="sidebar-body d-flex flex-column mt-3">
        <div class="nav-links flex-grow-1 d-flex flex-column">

            <div class="nav-group nav-quick">
                <div class="nav-group-label">Quick Access</div>
                <div class="nav-group-links">
                    <?php if ($role === 'program_chairperson'): ?>
                        <a href="program_chairperson.php" class="nav-link <?php echo ($currentPage == 'program_chairperson.php') ? 'active' : ''; ?>">
                            <i class="bi bi-speedometer2"></i> <span class="link-text">Dashboard</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($role === 'faculty'): ?>
                        <a href="<?= htmlspecialchars($reviewerDashboardLink); ?>" class="nav-link <?php echo ($currentPage == $reviewerDashboardSlug) ? 'active' : ''; ?>">
                            <i class="bi bi-stars"></i> <span class="link-text">Reviewer Dashboard</span>
                        </a>
                        <?php if ($reviewerInboxAllowed): ?>
                        <a href="reviewer_assignment_inbox.php" class="nav-link <?php echo ($currentPage == 'reviewer_assignment_inbox.php') ? 'active' : ''; ?>">
                            <i class="bi bi-inbox"></i> <span class="link-text">Assignment Inbox</span>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($role === 'student'): ?>
                        <a href="student_dashboard.php" class="nav-link <?php echo ($currentPage == 'student_dashboard.php') ? 'active' : ''; ?>">
                            <i class="bi bi-speedometer2"></i> <span class="link-text">Dashboard</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($role === 'adviser'): ?>
                        <a href="adviser.php" class="nav-link <?php echo ($currentPage == 'adviser.php') ? 'active' : ''; ?>">
                            <i class="bi bi-speedometer2"></i> <span class="link-text">Dashboard</span>
                        </a>
                    <?php endif; ?>

                    <a href="calendar.php" class="nav-link <?php echo ($currentPage == 'calendar.php') ? 'active' : ''; ?>">
                        <i class="bi bi-calendar-event"></i> <span class="link-text">Calendar</span>
                    </a>
                </div>
            </div>

            <?php if ($hasWorkspaceLinks): ?>
            <div class="nav-group nav-role flex-grow-1 d-flex flex-column">
                <div class="nav-group-label nav-group-label-workspace">
                    <span>Work</span>
                    <span>Space</span>
                </div>

                <?php if ($role === 'program_chairperson'): ?>
                    <div class="nav-section<?php echo $facultySectionOpen ? ' open' : ''; ?>" data-section="faculty-management" data-accordion="true">
                        <button type="button" class="nav-section-toggle" aria-expanded="<?php echo $facultySectionOpen ? 'true' : 'false'; ?>">
                            <span class="nav-section-label">Faculty Management</span>
                            <i class="bi bi-chevron-down chevron"></i>
                        </button>
                        <div class="nav-section-links">
                            <a href="create_faculty.php" class="nav-sub-link <?php echo ($currentPage == 'create_faculty.php') ? 'active' : ''; ?>">
                                Create Faculty
                            </a>
                            <a href="assign_adviser.php" class="nav-sub-link <?php echo ($currentPage == 'assign_adviser.php') ? 'active' : ''; ?>">
                                Assign Students
                            </a>
                            <a href="defense_committee.php" class="nav-sub-link <?php echo ($currentPage == 'defense_committee.php') ? 'active' : ''; ?>">
                                Defense Committee
                            </a>
                            <a href="reviewer_pipeline.php" class="nav-sub-link <?php echo ($currentPage == 'reviewer_pipeline.php') ? 'active' : ''; ?>">
                                Reviewer Pipeline
                            </a>
                            <a href="faculty_reviewer_feedback.php" class="nav-sub-link <?php echo ($currentPage == 'faculty_reviewer_feedback.php') ? 'active' : ''; ?>">
                                Reviewer Feedback
                            </a>
                            <a href="assign_panel.php" class="nav-sub-link <?php echo ($currentPage == 'assign_panel.php') ? 'active' : ''; ?>">
                                Panel Assignment
                            </a>
                        </div>
                    </div>

                    <div class="nav-section<?php echo $studentSectionOpen ? ' open' : ''; ?>" data-section="student-management" data-accordion="true">
                        <button type="button" class="nav-section-toggle" aria-expanded="<?php echo $studentSectionOpen ? 'true' : 'false'; ?>">
                            <span class="nav-section-label">Student Management</span>
                            <i class="bi bi-chevron-down chevron"></i>
                        </button>
                        <div class="nav-section-links">
                            <a href="create_student.php" class="nav-sub-link <?php echo ($currentPage == 'create_student.php') ? 'active' : ''; ?>">
                                Create Student
                            </a>
                            <a href="student_directory.php" class="nav-sub-link <?php echo ($currentPage == 'student_directory.php') ? 'active' : ''; ?>">
                                Student Directory
                            </a>
                            <a href="submissions.php?view=all" class="nav-sub-link <?php echo ($currentPage == 'submissions.php') ? 'active' : ''; ?>">
                                Submissions
                            </a>
                            <a href="status_logs.php" class="nav-sub-link <?php echo ($currentPage == 'status_logs.php') ? 'active' : ''; ?>">
                                Status Logs
                            </a>
                            <a href="final_endorsement_inbox.php" class="nav-sub-link <?php echo ($currentPage == 'final_endorsement_inbox.php') ? 'active' : ''; ?>">
                                Final Endorsements
                            </a>
                            <a href="defense_outcome.php" class="nav-sub-link <?php echo ($currentPage == 'defense_outcome.php') ? 'active' : ''; ?>">
                                Defense Outcomes
                            </a>
                            <a href="notice_to_commence.php" class="nav-sub-link <?php echo ($currentPage == 'notice_to_commence.php') ? 'active' : ''; ?>">
                                Notice to Commence
                            </a>
                        </div>
                    </div>

                    <div class="nav-section<?php echo $recordsSectionOpen ? ' open' : ''; ?>" data-section="records-archive" data-accordion="true">
                        <button type="button" class="nav-section-toggle" aria-expanded="<?php echo $recordsSectionOpen ? 'true' : 'false'; ?>">
                            <span class="nav-section-label">Records &amp; Archive</span>
                            <i class="bi bi-chevron-down chevron"></i>
                        </button>
                        <div class="nav-section-links">
                            <a href="archive_manager.php" class="nav-sub-link <?php echo ($currentPage == 'archive_manager.php') ? 'active' : ''; ?>">
                                Archive Manager
                            </a>
                            <a href="receive_payment.php" class="nav-sub-link <?php echo ($currentPage == 'receive_payment.php') ? 'active' : ''; ?>">
                                Received Payments
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($role === 'student'): ?>
                    <a href="view_defense_schedule.php" class="nav-link <?php echo ($currentPage == 'view_defense_schedule.php') ? 'active' : ''; ?>">
                        <i class="bi bi-calendar-event"></i> <span class="link-text">View Defense Schedule</span>
                    </a>
                    <a href="submit_final_paper.php" class="nav-link <?php echo ($currentPage == 'submit_final_paper.php') ? 'active' : ''; ?>">
                        <i class="bi bi-file-earmark-text"></i> <span class="link-text">Outline Defense Submission</span>
                    </a>

                    <a href="student_defense_outcome.php" class="nav-link <?php echo ($currentPage == 'student_defense_outcome.php') ? 'active' : ''; ?>">
                        <i class="bi bi-mortarboard"></i> <span class="link-text">Defense Outcome</span>
                    </a>
                    <a href="proof_of_payment.php" class="nav-link <?php echo ($currentPage == 'proof_of_payment.php') ? 'active' : ''; ?>">
                        <i class="bi bi-cash-coin"></i> <span class="link-text">Proof of Payment</span>
                    </a>
                <?php endif; ?>

                <?php if ($role === 'dean'): ?>
                    <a href="create_progchair.php" class="nav-link <?php echo ($currentPage == 'create_progchair.php') ? 'active' : ''; ?>">
                        <i class="bi bi-person-badge"></i> <span class="link-text">Create Program Chair</span>
                    </a>
                    <a href="dean_defense_committee.php" class="nav-link <?php echo ($currentPage == 'dean_defense_committee.php') ? 'active' : ''; ?>">
                        <i class="bi bi-clipboard-check"></i> <span class="link-text">Defense Committee</span>
                    </a>
                    <a href="dean_notice_commence.php" class="nav-link <?php echo ($currentPage == 'dean_notice_commence.php') ? 'active' : ''; ?>">
                        <i class="bi bi-megaphone"></i> <span class="link-text">Notice to Commence</span>
                    </a>
                    <a href="archive_library.php" class="nav-link <?php echo ($currentPage == 'archive_library.php') ? 'active' : ''; ?>">
                        <i class="bi bi-collection"></i> <span class="link-text">Archive Catalog</span>
                    </a>
                <?php endif; ?>

                <?php if ($role === 'adviser'): ?>
                    <div class="nav-section<?php echo $adviserStudentOpen ? ' open' : ''; ?>" data-section="adviser-students" data-accordion="true">
                        <button type="button" class="nav-section-toggle" aria-expanded="<?php echo $adviserStudentOpen ? 'true' : 'false'; ?>">
                            <span class="nav-section-label">Students</span>
                            <i class="bi bi-chevron-down chevron"></i>
                        </button>
                        <div class="nav-section-links">
                            <a href="adviser_directory.php" class="nav-sub-link <?php echo ($currentPage == 'adviser_directory.php') ? 'active' : ''; ?>">
                                Student Directory
                            </a>
                            <a href="advisory.php" class="nav-sub-link <?php echo ($currentPage == 'advisory.php') ? 'active' : ''; ?>">
                                Advisory Chat
                            </a>
                        </div>
                    </div>
                    <div class="nav-section<?php echo $adviserDefenseOpen ? ' open' : ''; ?>" data-section="adviser-defense" data-accordion="true">
                        <button type="button" class="nav-section-toggle" aria-expanded="<?php echo $adviserDefenseOpen ? 'true' : 'false'; ?>">
                            <span class="nav-section-label">Defense</span>
                            <i class="bi bi-chevron-down chevron"></i>
                        </button>
                        <div class="nav-section-links">
                            <a href="my_assigned_defense.php" class="nav-sub-link <?php echo ($currentPage == 'my_assigned_defense.php') ? 'active' : ''; ?>">
                                My Assigned Defenses
                            </a>
                            <a href="final_paper_inbox.php" class="nav-sub-link <?php echo ($currentPage == 'final_paper_inbox.php') ? 'active' : ''; ?>">
                                Manuscript Inbox
                            </a>
                            <a href="route_slip_inbox.php" class="nav-sub-link <?php echo ($currentPage == 'route_slip_inbox.php') ? 'active' : ''; ?>">
                                Route Slip Inbox
                            </a>
                            <a href="adviser_route_slip.php" class="nav-sub-link <?php echo ($currentPage == 'adviser_route_slip.php') ? 'active' : ''; ?>">
                                Route Slip Issuance
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($role === 'committee_chair'): ?>
                    <a href="my_committee_defense.php" class="nav-link <?php echo ($currentPage == 'my_committee_defense.php') ? 'active' : ''; ?>">
                        <i class="bi bi-briefcase"></i> <span class="link-text">My Assignments</span>
                    </a>
                    <a href="final_paper_inbox.php" class="nav-link <?php echo ($currentPage == 'final_paper_inbox.php') ? 'active' : ''; ?>">
                        <i class="bi bi-inbox"></i> <span class="link-text">Manuscript Inbox</span>
                    </a>
                    <a href="route_slip_inbox.php" class="nav-link <?php echo ($currentPage == 'route_slip_inbox.php') ? 'active' : ''; ?>">
                        <i class="bi bi-file-earmark-check"></i> <span class="link-text">Route Slip Inbox</span>
                    </a>
                <?php endif; ?>

                <?php if ($role === 'committee_chairperson'): ?>
                    <a href="my_committee_defense.php" class="nav-link <?php echo ($currentPage == 'my_committee_defense.php') ? 'active' : ''; ?>">
                        <i class="bi bi-briefcase"></i> <span class="link-text">My Defense Assignments</span>
                    </a>
                    <a href="final_paper_inbox.php" class="nav-link <?php echo ($currentPage == 'final_paper_inbox.php') ? 'active' : ''; ?>">
                        <i class="bi bi-inbox"></i> <span class="link-text">Manuscript Inbox</span>
                    </a>
                    <a href="route_slip_inbox.php" class="nav-link <?php echo ($currentPage == 'route_slip_inbox.php') ? 'active' : ''; ?>">
                        <i class="bi bi-file-earmark-check"></i> <span class="link-text">Route Slip Inbox</span>
                    </a>
                <?php endif; ?>

                <?php if ($role === 'panel'): ?>
                    <a href="my_assign_defense.php" class="nav-link <?php echo ($currentPage == 'my_assign_defense.php') ? 'active' : ''; ?>">
                        <i class="bi bi-briefcase"></i> <span class="link-text">My Assignments</span>
                    </a>
                    <a href="final_paper_inbox.php" class="nav-link <?php echo ($currentPage == 'final_paper_inbox.php') ? 'active' : ''; ?>">
                        <i class="bi bi-inbox"></i> <span class="link-text">Manuscript Inbox</span>
                    </a>
                    <a href="route_slip_inbox.php" class="nav-link <?php echo ($currentPage == 'route_slip_inbox.php') ? 'active' : ''; ?>">
                        <i class="bi bi-file-earmark-check"></i> <span class="link-text">Route Slip Inbox</span>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="sidebar-footer profile-panel mt-auto">
            <button type="button" class="profile-toggle" id="profileMenuToggle" aria-expanded="false" aria-haspopup="true">
                <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Avatar" class="avatar-img">
                <div class="user-label">
                    <span class="user-label-text"><?php echo htmlspecialchars($userFullName ?: 'Welcome'); ?></span>
                    <small class="user-role-text"><?php echo htmlspecialchars($userRoleLabel); ?></small>
                </div>
                <i class="bi bi-chevron-down profile-chevron"></i>
            </button>
            <div class="profile-dropdown" id="profileMenu" role="menu">
                <button type="button" class="profile-dropdown-link profile-dropdown-btn" data-bs-toggle="modal" data-bs-target="#accountSettingsModal" role="menuitem">
                    Account Settings
                </button>
                <?php if ($userId && $hasRoleAssignments): ?>
                <div class="profile-dropdown-section role-switch-dropdown mt-2">
                    <button class="btn btn-sm w-100 d-flex justify-content-between align-items-center text-start text-uppercase fw-semibold role-switch-toggle text-white"
                        type="button"
                        data-role-toggle="switch-role"
                        aria-expanded="false"
                        style="color:#fff;">
                        <span class="text-white">Switch Role</span>
                        <i class="bi bi-chevron-right text-white"></i>
                    </button>
                    <div class="role-switch-collapse collapse" data-role-panel="switch-role">
                        <?php if ($canSwitchRoles): ?>
                            <div class="role-switch-columns px-2 pb-2 mt-3">
                                <?php
                                foreach ($userRoleAssignments as $assignment):
                                    // Skip 'reviewer' role
                                    if ($assignment['code'] === 'reviewer') continue;
                                    if ($assignment['code'] === 'committee_chair') {
                                        continue;
                                    }
                                    
                                    $code = (string)$assignment['code'];
                                    $label = (string)($assignment['label'] ?? $code);
                                    $switchable = !empty($assignment['switchable']);
                                    $isActive = $code === $role;
                                    $btnClass = $isActive ? 'btn-success text-white' : 'btn-outline-success text-white';
                                ?>
                                    <form method="post" action="switch_role.php" class="role-switcher-inline-form" <?php echo $switchable ? '' : 'aria-disabled="true"'; ?>>
                                        <input type="hidden" name="role_switch_token" value="<?php echo htmlspecialchars($roleSwitchToken); ?>">
                                        <input type="hidden" name="origin" value="<?php echo htmlspecialchars($currentPage); ?>">
                                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($code); ?>">
                                        <button type="submit"
                                                class="btn btn-sm w-100 <?php echo $btnClass; ?> d-flex align-items-center justify-content-between role-switch-btn"
                                                <?php echo $switchable ? '' : 'disabled'; ?>>
                                            <span><?php echo htmlspecialchars($label); ?></span>
                                            <i class="bi bi-person-badge"></i>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="px-2 pb-2 mt-3">
                                <small class="text-muted">Current role: <?php echo htmlspecialchars($userRoleLabel); ?></small>
                            </div>
                        <?php endif; ?>
                        <?php if ($roleSwitchError): ?>
                            <div class="alert alert-warning py-1 px-2 mb-0 small"><?php echo htmlspecialchars($roleSwitchError); ?></div>
                        <?php elseif ($roleSwitchSuccess): ?>
                            <div class="alert alert-success py-1 px-2 mb-0 small"><?php echo htmlspecialchars($roleSwitchSuccess); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <a href="logout.php" title="Logout" class="profile-dropdown-link profile-signout-link" role="menuitem">
                    Logout
                </a>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="accountSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content account-settings-modal">
            <form method="post" enctype="multipart/form-data" id="accountSettingsForm">
                <input type="hidden" name="account_settings_action" value="update">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Account Settings</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($accountSettingsMessage): ?>
                        <div class="alert alert-<?php echo htmlspecialchars($accountSettingsMessage['type']); ?>">
                            <?php echo htmlspecialchars($accountSettingsMessage['text']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="account-settings-section">
                        <h6 class="text-uppercase text-muted small">Profile Info</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($userProfile['firstname']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($userProfile['lastname']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" value="<?php echo htmlspecialchars($userProfile['email']); ?>" readonly>
                                <div class="form-text">Email is used for login.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="contact" class="form-control" value="<?php echo htmlspecialchars($userProfile['contact']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Program</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($userProfile['program']); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($userProfile['department']); ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="account-settings-section">
                        <h6 class="text-uppercase text-muted small">Avatar</h6>
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4 text-center">
                                <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Avatar preview" class="rounded-circle border" style="width: 96px; height: 96px; object-fit: cover;">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Upload New Avatar</label>
                                <input type="file" name="avatar" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                                <div class="form-text">Accepted formats: JPG, PNG, WEBP.</div>
                            </div>
                        </div>
                    </div>

                    <div class="account-settings-section">
                        <h6 class="text-uppercase text-muted small">Security</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Current Password</label>
                                <div class="input-group">
                                    <input type="password" id="currentPassword" name="current_password" class="form-control password-field" autocomplete="current-password">
                                    <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#currentPassword" aria-label="Toggle password visibility" aria-pressed="false">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" id="newPassword" name="new_password" class="form-control password-field" autocomplete="new-password" minlength="8">
                                    <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#newPassword" aria-label="Toggle password visibility" aria-pressed="false">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">8+ chars with upper/lowercase, number, and symbol.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Confirm New Password</label>
                                <div class="input-group">
                                    <input type="password" id="confirmPassword" name="confirm_password" class="form-control password-field" autocomplete="new-password" minlength="8">
                                    <button class="btn btn-outline-secondary password-toggle" type="button" data-target="#confirmPassword" aria-label="Toggle password visibility" aria-pressed="false">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Login</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($lastLoginLabel); ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="account-settings-section">
                        <h6 class="text-uppercase text-muted small">Preferences</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="notifyToggle" name="notify_enabled" value="1" <?php echo $notifyEnabledFlag ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="notifyToggle">Notifications</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Timezone</label>
                                <select name="timezone" class="form-select">
                                    <?php
                                        $timezoneOptions = ['Asia/Manila', 'UTC', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'America/New_York'];
                                        foreach ($timezoneOptions as $tzOption):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($tzOption); ?>" <?php echo $timezoneValue === $tzOption ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tzOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="compactToggle" name="sidebar_compact" value="1" <?php echo $sidebarPreferenceCollapsed ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="compactToggle">Compact Sidebar</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="account-settings-section">
                        <h6 class="text-uppercase text-muted small">Account Status</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Roles</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars(implode(', ', $roleList)); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Account Created</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($accountCreatedLabel); ?>" readonly>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success btn-save" form="accountSettingsForm">Save Changes</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Script -->
<script>
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleSidebar');
    const toggleIcon = toggleBtn ? toggleBtn.querySelector('i') : null;
    const bodyElement = document.body;
    const responsiveQuery = window.matchMedia('(max-width: 992px)');
    let isSidebarCollapsed = sidebar ? sidebar.classList.contains('collapsed') : false;
    let prefersCollapsed = sidebar ? sidebar.dataset.preferenceCollapsed === '1' : false;
    const notifyEnabledPref = <?php echo $notifyEnabledFlag ? 'true' : 'false'; ?>;

    const applySidebarState = (collapsed) => {
        if (!sidebar) {
            return;
        }
        isSidebarCollapsed = collapsed;
        sidebar.classList.toggle('collapsed', collapsed);
        sidebar.classList.toggle('expanded', !collapsed);
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', String(!collapsed));
        }
        if (toggleIcon) {
            toggleIcon.classList.toggle('bi-chevron-left', !collapsed);
            toggleIcon.classList.toggle('bi-chevron-right', collapsed);
        }
        if (bodyElement) {
            bodyElement.classList.toggle('sidebar-collapsed', collapsed);
            bodyElement.setAttribute('data-sidebar-state', collapsed ? 'collapsed' : 'expanded');
            bodyElement.setAttribute('data-notify-enabled', notifyEnabledPref ? '1' : '0');
        }
    };

    const syncSidebarState = () => {
        const shouldCollapse = responsiveQuery.matches ? true : prefersCollapsed;
        applySidebarState(shouldCollapse);
    };

    toggleBtn?.addEventListener('click', () => {
        const nextState = !isSidebarCollapsed;
        applySidebarState(nextState);
        if (!responsiveQuery.matches) {
            prefersCollapsed = nextState;
        }
    });

    if (responsiveQuery.addEventListener) {
        responsiveQuery.addEventListener('change', syncSidebarState);
    } else if (responsiveQuery.addListener) {
        responsiveQuery.addListener(syncSidebarState);
    }

    syncSidebarState();
    if (!bodyElement.getAttribute('data-sidebar-state')) {
        bodyElement.setAttribute('data-sidebar-state', isSidebarCollapsed ? 'collapsed' : 'expanded');
    }

    const navLinks = sidebar ? sidebar.querySelectorAll('.nav-link') : [];
    navLinks.forEach((link) => {
        const textSource = link.querySelector('.link-text');
        const label = (textSource ? textSource.textContent : link.textContent || '').trim();
        if (!label) {
            return;
        }
        if (!link.getAttribute('title')) {
            link.setAttribute('title', label);
        }
        if (!link.getAttribute('aria-label')) {
            link.setAttribute('aria-label', label);
        }
    });

    const navSectionToggles = document.querySelectorAll('.nav-section-toggle');
    const accordionSections = Array.from(document.querySelectorAll('.nav-section[data-accordion="true"]'));

    const setSectionState = (section, shouldBeOpen) => {
        if (!section) return;
        section.classList.toggle('open', shouldBeOpen);
        const toggle = section.querySelector('.nav-section-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', String(shouldBeOpen));
        }
    };

    navSectionToggles.forEach((sectionToggle) => {
        sectionToggle.addEventListener('click', () => {
            const section = sectionToggle.closest('.nav-section');
            if (!section) return;

            const shouldOpen = !section.classList.contains('open');
            if (section.dataset.accordion === 'true') {
                accordionSections.forEach((otherSection) => {
                    if (otherSection !== section) {
                        setSectionState(otherSection, false);
                    }
                });
            }
            setSectionState(section, shouldOpen);
        });
    });

    const profileToggle = document.getElementById('profileMenuToggle');
    const profileMenu = document.getElementById('profileMenu');
    let profileMenuOpen = false;

    const closeProfileMenu = () => {
        if (!profileMenu) return;
        profileMenu.classList.remove('open');
        profileMenuOpen = false;
        if (profileToggle) {
            profileToggle.setAttribute('aria-expanded', 'false');
            profileToggle.classList.remove('open');
        }
    };

    const openProfileMenu = () => {
        if (!profileMenu) return;
        profileMenu.classList.add('open');
        profileMenuOpen = true;
        if (profileToggle) {
            profileToggle.setAttribute('aria-expanded', 'true');
            profileToggle.classList.add('open');
        }
    };

    profileToggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        if (profileMenuOpen) {
            closeProfileMenu();
        } else {
            openProfileMenu();
        }
    });

    document.addEventListener('click', (event) => {
        if (!profileMenuOpen) return;
        if (profileMenu && profileMenu.contains(event.target)) return;
        if (profileToggle && profileToggle.contains(event.target)) return;
        closeProfileMenu();
    });

    const passwordToggles = document.querySelectorAll('.password-toggle');
    passwordToggles.forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetSelector = btn.getAttribute('data-target');
            const input = targetSelector ? document.querySelector(targetSelector) : null;
            if (!input) return;
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            const icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye', !isHidden);
                icon.classList.toggle('bi-eye-slash', isHidden);
            }
        });
    });

    const accountSettingsModalEl = document.getElementById('accountSettingsModal');
    const accountSettingsShouldOpen = <?php echo $accountSettingsOpen ? 'true' : 'false'; ?>;
    if (accountSettingsModalEl && accountSettingsShouldOpen && window.bootstrap) {
        const accountModal = bootstrap.Modal.getOrCreateInstance(accountSettingsModalEl);
        accountModal.show();
    }
</script>

<style>
    :root {
        --sidebar-width-expanded: 248px;
        --sidebar-width-collapsed: 84px;
        --sidebar-header-offset: 70px;
        --layout-shell-sidebar: var(--sidebar-width-expanded);
        --layout-shell-sidebar-collapsed: var(--sidebar-width-collapsed);
    }

    .layout-sidebar {
        width: var(--sidebar-width-expanded);
        background: #16562c;
        color: #fff;
        transition: width 0.3s ease, transform 0.3s ease;
        display: flex;
        flex-direction: column;
        height: calc(100vh - var(--sidebar-header-offset));
        position: fixed;
        top: var(--sidebar-header-offset);
        left: 0;
        z-index: 1000;
        border-radius: 0 16px 16px 0;
        box-shadow: 0 4px 18px rgba(0,0,0,0.18);
        overflow: hidden;
        padding-bottom: 1rem;
        -ms-overflow-style: none;
        scrollbar-width: thin;
    }
    .layout-sidebar::-webkit-scrollbar {
        width: 6px;
    }
    .layout-sidebar::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.3);
        border-radius: 4px;
    }
    .sidebar-header { padding: 1rem 1rem 1rem 1.25rem; }
    .sidebar-title { font-size: 1.2rem; letter-spacing: 0.02em; }
    .layout-sidebar a {
        display: flex;
        align-items: center;
        padding: 10px;
        color: #ddd;
        text-decoration: none;
        transition: background 0.3s;
        font-size: 1rem;
        border-radius: 0.65rem;
        margin: 0 0.35rem;
    }
    .layout-sidebar a i { font-size: 1.15rem; width: 34px; text-align: center; transition: transform 0.3s ease; }
    .layout-sidebar a span.link-text { margin-left: 8px; }
    .layout-sidebar .sidebar-body {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        min-height: 0;
        padding: 0.75rem 0 0.75rem;
        gap: 0.75rem;
        position: relative;
    }
    .layout-sidebar .nav-links {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        overflow-y: auto;
        padding: 0 0.6rem 3.4rem 0;
        margin-right: 4px;
        -ms-overflow-style: none;
        scrollbar-width: none;
        min-height: 0;
    }
    .layout-sidebar .nav-links::-webkit-scrollbar { display: none; }
    .nav-group {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        padding: 0 0.35rem 0.2rem;
    }
    .nav-group.nav-role {
        flex: 1 1 auto;
        gap: 0.35rem;
    }
    .nav-group-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.22em;
        color: rgba(255,255,255,0.55);
        padding-left: 0.35rem;
    }
    .nav-group-label-workspace { line-height: 1.2; }
    .nav-group-label-workspace span {
        display: inline;
    }
    .nav-group-label-workspace span + span::before { content: ' '; }
    .layout-sidebar.collapsed .nav-group-label-workspace {
        line-height: 1.05;
    }
    .layout-sidebar.collapsed .nav-group-label-workspace span {
        display: block;
    }
    .layout-sidebar.collapsed .nav-group-label-workspace span + span::before { content: ''; }
    .nav-group-links {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }
    .nav-section {
        padding: 0;
        margin: 0.25rem 0;
        border-bottom: none;
        position: relative;
    }
    .nav-group .nav-section:last-child { margin-bottom: 0.1rem; }
    .nav-section-toggle {
        width: 100%;
        border: none;
        background: transparent;
        color: #f1f1f1;
        font-size: 0.94rem;
        font-weight: 600;
        text-align: left;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.2rem 0.5rem;
        cursor: pointer;
        letter-spacing: 0.05em;
    }
    .nav-section-label {
        text-transform: uppercase;
        font-size: 0.82rem;
        letter-spacing: 0.12em;
        color: inherit;
        flex: 1;
        min-width: 0;
        white-space: nowrap;
    }
    .nav-section .chevron {
        transition: transform 0.2s ease;
        font-size: 0.95rem;
    }
    .nav-section.open .chevron { transform: rotate(180deg); }
    .nav-section.open .nav-section-label,
    .nav-section-toggle:hover .nav-section-label {
        color: #ffc107;
    }
    .nav-section-links {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
        padding: 0.1rem 0 0.35rem 1rem;
        margin-left: 0.15rem;
        border-left: 1px solid rgba(255,255,255,0.15);
        max-height: 420px;
        overflow: hidden;
        transition: max-height 0.25s ease, opacity 0.2s ease;
        opacity: 1;
    }
    .nav-section:not(.open) .nav-section-links {
        max-height: 0;
        opacity: 0;
        pointer-events: none;
    }
    .nav-sub-link {
        color: #dcdcdc;
        font-size: 0.9rem;
        padding: 0.25rem 0;
        text-decoration: none;
        border-radius: 0;
        transition: color 0.2s ease;
    }
    .nav-sub-link:hover { color: #fff; }
    .nav-sub-link.active { color: #ffc107; font-weight: 600; }
    .nav-sub-link.coming-soon { opacity: 0.75; font-style: italic; }
    .layout-sidebar.collapsed { width: var(--sidebar-width-collapsed); }
    .layout-sidebar.collapsed .link-text { display: none; }
    .layout-sidebar.collapsed .sidebar-header { justify-content: center; padding: 0.75rem 0; }
    .layout-sidebar.collapsed .sidebar-title { display: none; }
    .layout-sidebar.collapsed .nav-links {
        padding: 0.5rem 0.35rem 5.5rem 0;
        margin-right: 0;
    }
    .layout-sidebar.collapsed .nav-section {
        margin: 0.15rem;
        padding: 0;
        background: transparent;
        border: 0;
    }
    .layout-sidebar.collapsed .nav-section-toggle {
        justify-content: center;
        padding: 0.45rem 0;
    }
    .layout-sidebar.collapsed .nav-section-label,
    .layout-sidebar.collapsed .nav-section-links,
    .layout-sidebar.collapsed .nav-section .chevron {
        display: none;
    }
    .layout-sidebar.collapsed a { justify-content: center; margin: 0 8px; border-radius: 12px; }
    .layout-sidebar.collapsed a i { transform: scale(1.1); }
    .layout-sidebar.collapsed .sidebar-footer {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 0.5rem 0.25rem 0.75rem;
        margin: 0;
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0.5rem;
        background: #16562c;
        z-index: 2;
    }

    .layout-sidebar.collapsed .sidebar-footer .profile-toggle {
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
    }

    .layout-sidebar.collapsed .profile-toggle .avatar-img {
        width: 42px;
        height: 42px;
        margin: 0;
    }
    .layout-sidebar.collapsed .user-label { opacity: 0; visibility: hidden; margin: 0; max-height: 0; }
    .layout-sidebar.collapsed .avatar-img { width: 42px; height: 42px; }
    .layout-sidebar .nav-links a { margin-bottom: 0; }
    .layout-sidebar a.active { background: rgba(255, 193, 7, 0.15); color: #ffc107; font-weight: 600; }
    .layout-sidebar a:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
    .sidebar-footer {
        position: relative;
        background: transparent;
        border-top: 1px solid rgba(255,255,255,0.15);
        padding: 1.15rem 0.9rem 1.55rem;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
        transition: padding 0.3s ease;
        border-radius: 0;
        width: 100%;
        margin: 1rem 0 0;
        box-shadow: none;
        flex-shrink: 0;
    }
    .sidebar-footer::before { content: none; }
    .profile-toggle {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        background: transparent;
        border: 0;
        width: 100%;
        padding: 0.2rem 0.15rem;
        color: #fff;
        text-align: left;
        cursor: pointer;
    }
    .profile-chevron {
        margin-left: auto;
        transition: transform 0.2s ease;
        font-size: 1rem;
    }
    .profile-toggle.open .profile-chevron { transform: rotate(180deg); }
    .avatar-img {
        width: 46px;
        height: 46px;
        border-radius: 50%;
        border: 2px solid #fff;
        object-fit: cover;
        transition: width 0.3s ease, height 0.3s ease;
        background: rgba(255,255,255,0.18);
        box-shadow: 0 4px 10px rgba(0,0,0,0.25);
    }
    .user-label {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.15rem;
        transition: opacity 0.2s ease, max-height 0.2s ease, margin 0.2s ease;
    }
    .user-label-text { font-size: 0.9rem; font-weight: 600; }
    .user-role-text { font-size: 0.85rem; color: rgba(255,255,255,0.7); letter-spacing: 0.08em; text-transform: uppercase; }
    .profile-dropdown {
        position: absolute;
        left: 0;
        right: 0;
        bottom: calc(100% + 12px);
        background: rgba(5,26,13,0.95);
        color: #fff;
        border-radius: 12px;
        border: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 18px 42px rgba(0,0,0,0.3);
        padding: 0.35rem 0;
        opacity: 0;
        pointer-events: none;
        transform: translateY(8px);
        transition: opacity 0.18s ease, transform 0.18s ease;
        z-index: 1500;
    }
    .profile-dropdown.open {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0);
    }
    .profile-dropdown-link {
        display: block;
        padding: 0.45rem 0.85rem;
        color: #fff;
        text-decoration: none;
        font-size: 0.92rem;
        transition: background 0.2s ease, color 0.2s ease;
    }
    .profile-dropdown-btn {
        background: transparent;
        border: 0;
        width: 100%;
        text-align: left;
        cursor: pointer;
    }
    .profile-dropdown-link.coming-soon { opacity: 0.7; font-style: italic; }
    .profile-dropdown-link:hover {
        background: rgba(255,255,255,0.08);
        color: #ffc107;
    }
    .profile-dropdown-link.profile-signout-link {
        color: #ffb3b3;
        border-top: 1px solid rgba(255,255,255,0.12);
    }
    .profile-dropdown-link.profile-signout-link:hover {
        background: rgba(255,82,82,0.18);
        color: #fff;
    }
    .layout-sidebar.collapsed .profile-toggle {
        justify-content: center;
        padding: 0.35rem 0;
    }
    .layout-sidebar.collapsed .profile-dropdown {
        left: 50%;
        right: auto;
        width: 220px;
        transform: translate(-50%, 8px);
    }
    .layout-sidebar.collapsed .profile-dropdown.open {
        transform: translate(-50%, 0);
    }

    body.sidebar-collapsed .content {
        margin-left: 0;
    }

    body[data-notify-enabled="0"] #notifDropdown,
    body[data-notify-enabled="0"] #notifMenu,
    body[data-notify-enabled="0"] #notifBadge {
        display: none !important;
    }

    #accountSettingsModal .modal-content {
        border-radius: 16px;
        border: 0;
        box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    }
    #accountSettingsModal .modal-body {
        background: #f4f7f5;
        padding: 1.5rem;
        max-height: 70vh;
        overflow-y: auto;
    }
    #accountSettingsModal .modal-body::-webkit-scrollbar {
        width: 8px;
    }
    #accountSettingsModal .modal-body::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.25);
        border-radius: 6px;
    }
    #accountSettingsModal .account-settings-section {
        background: #fff;
        border-radius: 12px;
        border: 1px solid rgba(0,0,0,0.08);
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
    }
    #accountSettingsModal .account-settings-section:last-child {
        margin-bottom: 0;
    }
    #accountSettingsModal .form-control,
    #accountSettingsModal .form-select {
        background-color: #ffffff;
        color: #060606ff;
    }
    #accountSettingsModal .modal-footer {
        background: #fff;
        border-top: 1px solid rgba(0,0,0,0.08);
        position: sticky;
        bottom: 0;
        z-index: 2;
    }
    #accountSettingsModal .btn-save {
        min-width: 140px;
        font-weight: 600;
        letter-spacing: 0.02em;
    }

    @media (max-width: 1400px) {
        :root {
            --sidebar-width-expanded: 220px;
        }
    }

    @media (max-width: 992px) {
        :root {
            --sidebar-width-expanded: min(320px, 82vw);
            --sidebar-width-collapsed: 0px;
            --sidebar-header-offset: 60px;
        }

        .layout-sidebar {
            width: var(--sidebar-width-expanded);
            height: calc(100vh - var(--sidebar-header-offset));
            transform: translateX(-110%);
            border-radius: 0 14px 14px 0;
            box-shadow: 0 10px 28px rgba(0,0,0,0.25);
        }

        body.sidebar-collapsed .layout-sidebar {
            transform: translateX(-110%);
        }

        body:not(.sidebar-collapsed) .layout-sidebar {
            transform: translateX(0);
        }

        .layout-sidebar.collapsed {
            width: var(--sidebar-width-expanded);
        }
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggleButtons = document.querySelectorAll('.role-switch-toggle');
        toggleButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetKey = btn.getAttribute('data-role-toggle');
                const targetPanel = document.querySelector(`.role-switch-collapse[data-role-panel="${targetKey}"]`);
                if (!targetPanel) {
                    return;
                }
                const isOpen = targetPanel.classList.contains('show');
                document.querySelectorAll('.role-switch-collapse').forEach(function (panel) {
                    panel.classList.remove('show');
                });
                document.querySelectorAll('.role-switch-toggle').forEach(function (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                    const icon = toggle.querySelector('i');
                    if (icon) {
                        icon.classList.remove('bi-chevron-down');
                        icon.classList.add('bi-chevron-right');
                    }
                });
                if (!isOpen) {
                    targetPanel.classList.add('show');
                    btn.setAttribute('aria-expanded', 'true');
                    const icon = btn.querySelector('i');
                    if (icon) {
                        icon.classList.remove('bi-chevron-right');
                        icon.classList.add('bi-chevron-down');
                    }
                }
            });
        });
    });
</script>

