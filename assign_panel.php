
<?php
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'chair_scope_helper.php';
require_once 'defense_schedule_helpers.php';
require_once 'role_helpers.php';

if (!isset($_SESSION)) {
    session_start();
}

$sessionRole = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || $sessionRole !== 'program_chairperson') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unauthorized';
        exit;
    }
    header("Location: login.php");
    exit;
}

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);
[$studentScopeClause, $studentScopeTypes, $studentScopeParams] = build_scope_condition($chairScope, 'u');

function respond_json(string $message): void
{
    header('Content-Type: text/plain');
    echo $message;
    exit;
}

function respond_json_payload(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function sanitize_member(string $name): string
{
    return trim($name);
}

function resolve_venue_input(?string $selectedVenue, ?string $customVenue): string
{
    $choice = trim((string)$selectedVenue);
    if ($choice === 'custom') {
        return trim((string)$customVenue);
    }
    return $choice;
}

function map_panel_role_for_notification(string $panelRole): string
{
    $panelRole = trim($panelRole);
    if ($panelRole === 'committee_chair') {
        return 'committee_chairperson';
    }
    if ($panelRole === 'panel_member') {
        return 'panel';
    }
    return $panelRole;
}

function map_notification_link_for_role(string $role): string
{
    return 'calendar.php';
}

function fetch_user_fullname(mysqli $conn, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $stmt = $conn->prepare("SELECT CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, '')) AS full_name FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $name = trim($row['full_name'] ?? '');
    return $name !== '' ? $name : '';
}

function fetch_users_by_roles(mysqli $conn, array $roles): array
{
    if (empty($roles)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $types = str_repeat('s', count($roles));
    $sql = "SELECT id, firstname, lastname, role FROM users WHERE role IN ({$placeholders}) ORDER BY firstname, lastname";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$roles);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows ?: [];
}

function collect_panel_assignments(mysqli $conn, array $panelRoleInputs, array $source): array
{
    $assignments = [];
    foreach ($panelRoleInputs as $input) {
        $field = $input['name'];
        $role = $input['role'];
        $userId = (int)($source[$field] ?? 0);
        if ($userId <= 0) {
            return [];
        }
        $name = fetch_user_fullname($conn, $userId);
        if ($name === '') {
            return [];
        }
        $assignments[] = [
            'id' => $userId,
            'name' => $name,
            'role' => $role,
        ];
    }
    return $assignments;
}

$panelRoleMap = [
    ['label' => 'Adviser Reviewer', 'name' => 'adviser_id', 'role' => 'adviser'],
    ['label' => 'Committee Chairperson', 'name' => 'chair_id', 'role' => 'committee_chair'],
    ['label' => 'Panel Member 1', 'name' => 'panel_member_one_id', 'role' => 'panel_member'],
    ['label' => 'Panel Member 2', 'name' => 'panel_member_two_id', 'role' => 'panel_member'],
];

function ensureDefensePanelMemberColumns(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $hasMemberId = $conn->query("SHOW COLUMNS FROM defense_panels LIKE 'panel_member_id'");
    if ($hasMemberId && $hasMemberId->num_rows === 0) {
        $conn->query("ALTER TABLE defense_panels ADD COLUMN panel_member_id INT NULL AFTER defense_id");
        $conn->query("ALTER TABLE defense_panels ADD CONSTRAINT fk_panel_member_user FOREIGN KEY (panel_member_id) REFERENCES users(id) ON DELETE CASCADE");
    }
    if ($hasMemberId) {
        $hasMemberId->free();
    }

    $hasRole = $conn->query("SHOW COLUMNS FROM defense_panels LIKE 'panel_role'");
    if ($hasRole && $hasRole->num_rows === 0) {
        $conn->query("ALTER TABLE defense_panels ADD COLUMN panel_role ENUM('adviser','committee_chair','panel_member') DEFAULT 'panel_member' AFTER panel_member_id");
    }
    if ($hasRole) {
        $hasRole->free();
    }

    $ensured = true;
}
ensureDefensePanelMemberColumns($conn);
ensureDefenseScheduleTimeColumns($conn);

function user_events_column_exists(mysqli $conn, string $column): bool
{
    $check = $conn->query("SHOW COLUMNS FROM user_events LIKE '{$column}'");
    $exists = $check && $check->num_rows > 0;
    if ($check) {
        $check->free();
    }
    return $exists;
}

function ensure_user_events_table(mysqli $conn): void
{
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_events'");
    $exists = $tableCheck && $tableCheck->num_rows > 0;
    if ($tableCheck) {
        $tableCheck->free();
    }
    if (!$exists) {
        $createTableQuery = "
            CREATE TABLE user_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                role VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                start_datetime DATETIME NOT NULL,
                end_datetime DATETIME,
                category ENUM('Defense', 'Meeting', 'Call', 'Academic', 'Personal', 'Other') DEFAULT 'Other',
                color VARCHAR(7) DEFAULT '#16562c',
                source VARCHAR(50) NULL,
                source_id INT NULL,
                is_locked BOOLEAN DEFAULT FALSE,
                is_all_day BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_role_events (user_id, role),
                UNIQUE KEY uniq_user_source (user_id, source, source_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $conn->query($createTableQuery);
    }

    $columns = [
        'source' => "ALTER TABLE user_events ADD COLUMN source VARCHAR(50) NULL AFTER color",
        'source_id' => "ALTER TABLE user_events ADD COLUMN source_id INT NULL AFTER source",
        'is_locked' => "ALTER TABLE user_events ADD COLUMN is_locked BOOLEAN DEFAULT FALSE AFTER source_id",
    ];
    foreach ($columns as $column => $sql) {
        if (!user_events_column_exists($conn, $column)) {
            $conn->query($sql);
        }
    }

    $indexCheck = $conn->query("SHOW INDEX FROM user_events WHERE Key_name = 'uniq_user_source'");
    if ($indexCheck && $indexCheck->num_rows === 0) {
        $conn->query("ALTER TABLE user_events ADD UNIQUE KEY uniq_user_source (user_id, source, source_id)");
    }
    if ($indexCheck) {
        $indexCheck->free();
    }
}

function fetch_user_role(mysqli $conn, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
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

function sync_defense_calendar_events(
    mysqli $conn,
    int $defenseId,
    array $userIds,
    string $title,
    string $description,
    string $startDateTime,
    string $endDateTime
): void {
    if ($defenseId <= 0) {
        return;
    }
    ensure_user_events_table($conn);

    $deleteStmt = $conn->prepare("DELETE FROM user_events WHERE source = 'defense' AND source_id = ?");
    if ($deleteStmt) {
        $deleteStmt->bind_param('i', $defenseId);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (empty($userIds)) {
        return;
    }

    $insertStmt = $conn->prepare("
        INSERT INTO user_events
            (user_id, role, title, description, start_datetime, end_datetime, category, color, source, source_id, is_locked)
        VALUES (?, ?, ?, ?, ?, ?, 'Defense', '#16562c', 'defense', ?, 1)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            start_datetime = VALUES(start_datetime),
            end_datetime = VALUES(end_datetime),
            category = VALUES(category),
            color = VALUES(color),
            role = VALUES(role),
            is_locked = 1
    ");
    if (!$insertStmt) {
        return;
    }

    foreach ($userIds as $userId) {
        $role = fetch_user_role($conn, $userId);
        if ($role === '') {
            $role = 'student';
        }
        $insertStmt->bind_param(
            'isssssi',
            $userId,
            $role,
            $title,
            $description,
            $startDateTime,
            $endDateTime,
            $defenseId
        );
        $insertStmt->execute();
    }
    $insertStmt->close();
}

function remove_defense_calendar_events(mysqli $conn, int $defenseId): void
{
    if ($defenseId <= 0) {
        return;
    }
    ensure_user_events_table($conn);
    $deleteStmt = $conn->prepare("DELETE FROM user_events WHERE source = 'defense' AND source_id = ?");
    if ($deleteStmt) {
        $deleteStmt->bind_param('i', $defenseId);
        $deleteStmt->execute();
        $deleteStmt->close();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'committee_lookup') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId <= 0) {
        respond_json_payload(['found' => false, 'message' => 'No student selected.']);
    }
    if (!student_matches_scope($conn, $studentId, $chairScope)) {
        respond_json_payload(['found' => false, 'message' => 'Student not in scope.']);
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'defense_committee_requests'");
    $hasTable = $tableCheck && $tableCheck->num_rows > 0;
    if ($tableCheck) {
        $tableCheck->free();
    }
    if (!$hasTable) {
        respond_json_payload(['found' => false, 'message' => 'Defense committee data unavailable.']);
    }

    $stmt = $conn->prepare("
        SELECT
            r.adviser_id,
            r.chair_id,
            r.panel_member_one_id,
            r.panel_member_two_id,
            ds.venue,
            CONCAT(COALESCE(adviser.firstname, ''), ' ', COALESCE(adviser.lastname, '')) AS adviser_name,
            CONCAT(COALESCE(chair.firstname, ''), ' ', COALESCE(chair.lastname, '')) AS chair_name,
            CONCAT(COALESCE(panel_one.firstname, ''), ' ', COALESCE(panel_one.lastname, '')) AS panel_member_one_name,
            CONCAT(COALESCE(panel_two.firstname, ''), ' ', COALESCE(panel_two.lastname, '')) AS panel_member_two_name
        FROM defense_committee_requests r
        JOIN defense_schedules ds ON ds.id = r.defense_id
        LEFT JOIN users adviser ON adviser.id = r.adviser_id
        LEFT JOIN users chair ON chair.id = r.chair_id
        LEFT JOIN users panel_one ON panel_one.id = r.panel_member_one_id
        LEFT JOIN users panel_two ON panel_two.id = r.panel_member_two_id
        WHERE r.student_id = ?
          AND r.status = 'Approved'
        ORDER BY r.reviewed_at DESC, r.requested_at DESC
        LIMIT 1
    ");
    if (!$stmt) {
        respond_json_payload(['found' => false, 'message' => 'Unable to load committee data.']);
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!$row) {
        respond_json_payload(['found' => false, 'message' => 'No approved committee request found.']);
    }

    respond_json_payload([
        'found' => true,
        'data' => [
            'adviser_id' => (int)($row['adviser_id'] ?? 0),
            'chair_id' => (int)($row['chair_id'] ?? 0),
            'panel_member_one_id' => (int)($row['panel_member_one_id'] ?? 0),
            'panel_member_two_id' => (int)($row['panel_member_two_id'] ?? 0),
            'adviser_name' => trim((string)($row['adviser_name'] ?? '')),
            'chair_name' => trim((string)($row['chair_name'] ?? '')),
            'panel_member_one_name' => trim((string)($row['panel_member_one_name'] ?? '')),
            'panel_member_two_name' => trim((string)($row['panel_member_two_name'] ?? '')),
            'venue' => $row['venue'] ?? '',
        ],
    ]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $defenseDate = trim($_POST['defense_date'] ?? '');
    $startTimeInput = trim($_POST['start_time'] ?? '');
    $endTimeInput = trim($_POST['end_time'] ?? '');
    $venue = resolve_venue_input($_POST['venue'] ?? '', $_POST['custom_venue'] ?? '');
    $panelAssignments = collect_panel_assignments($conn, $panelRoleMap, $_POST);

    if (
        !$studentId
        || $defenseDate === ''
        || $startTimeInput === ''
        || $endTimeInput === ''
        || $venue === ''
        || count($panelAssignments) !== count($panelRoleMap)
    ) {
        respond_json('Please complete all required fields.');
    }
    if (!student_matches_scope($conn, $studentId, $chairScope)) {
        respond_json('You can only schedule defenses for students under your program.');
    }

    $date = date('Y-m-d', strtotime($defenseDate));
    $startTime = date('H:i:s', strtotime($startTimeInput));
    $endTime = date('H:i:s', strtotime($endTimeInput));
    if (strtotime($endTime) <= strtotime($startTime)) {
        respond_json('End time must be later than the start time.');
    }
    if (defenseScheduleHasConflict($conn, $date, $startTime, $endTime)) {
        respond_json('Another defense is already scheduled in this time slot.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO defense_schedules (student_id, defense_date, defense_time, start_time, end_time, venue, status)
         VALUES (?, ?, ?, ?, ?, ?, 'Pending')"
    );
    $stmt->bind_param('isssss', $studentId, $date, $startTime, $startTime, $endTime, $venue);
    $stmt->execute();
    $defenseId = $stmt->insert_id;
    $stmt->close();

    $insertPanel = $conn->prepare(
        "INSERT INTO defense_panels (defense_id, panel_member, panel_member_id, panel_role) VALUES (?, ?, ?, ?)"
    );
    $committeeNames = [
        'adviser' => '',
        'committee_chair' => '',
        'panel_member' => [],
    ];
    foreach ($panelAssignments as $assignment) {
        if ($assignment['role'] === 'panel_member') {
            $committeeNames['panel_member'][] = $assignment['name'];
        } elseif (isset($committeeNames[$assignment['role']])) {
            $committeeNames[$assignment['role']] = $assignment['name'];
        }
        $insertPanel->bind_param('isis', $defenseId, $assignment['name'], $assignment['id'], $assignment['role']);
        $insertPanel->execute();
    }
    $insertPanel->close();

    $studentQuery = $conn->prepare("SELECT CONCAT(firstname, ' ', lastname) AS full_name FROM users WHERE id = ?");
    $studentQuery->bind_param('i', $studentId);
    $studentQuery->execute();
    $studentRow = $studentQuery->get_result()->fetch_assoc();
    $studentQuery->close();
    $studentName = trim($studentRow['full_name'] ?? '');
    if ($studentName === '') {
        $studentName = 'the student';
    }

    $readableSchedule = formatDefenseScheduleLabel($date, $startTime, $endTime);
    $panelList = $committeeNames['panel_member'] ? implode(', ', $committeeNames['panel_member']) : 'Not assigned';
    $description = "Student: {$studentName}\nVenue: {$venue}\nAdviser: {$committeeNames['adviser']}\nChair: {$committeeNames['committee_chair']}\nPanel: {$panelList}";
    $title = "Defense: {$studentName}";
    $startDateTime = "{$date} {$startTime}";
    $endDateTime = "{$date} {$endTime}";
    $calendarUserIds = array_merge([$programChairId, $studentId], array_column($panelAssignments, 'id'));
    sync_defense_calendar_events($conn, $defenseId, $calendarUserIds, $title, $description, $startDateTime, $endDateTime);

    notify_user_for_role(
        $conn,
        $studentId,
        'student',
        'Defense schedule created',
        "Your defense has been scheduled on {$readableSchedule} at {$venue}.",
        'calendar.php'
    );
    foreach ($panelAssignments as $assignment) {
        $notifyRole = map_panel_role_for_notification($assignment['role']);
        $link = map_notification_link_for_role($notifyRole);
        notify_user_for_role(
            $conn,
            (int)$assignment['id'],
            $notifyRole,
            'New panel assignment',
            "{$studentName}'s defense is scheduled on {$readableSchedule} at {$venue}.",
            $link
        );
    }

    respond_json('success');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $defenseId = (int)($_POST['id'] ?? 0);
    $venue = resolve_venue_input($_POST['venue'] ?? '', $_POST['custom_venue'] ?? '');
    $submittedStatus = trim($_POST['status'] ?? '');
    $defenseDate = trim($_POST['defense_date'] ?? '');
    $startTimeInput = trim($_POST['start_time'] ?? '');
    $endTimeInput = trim($_POST['end_time'] ?? '');
    $panelAssignments = collect_panel_assignments($conn, $panelRoleMap, $_POST);

    if (
        !$defenseId
        || $defenseDate === ''
        || $startTimeInput === ''
        || $endTimeInput === ''
        || $venue === ''
        || $submittedStatus === ''
        || count($panelAssignments) !== count($panelRoleMap)
    ) {
        respond_json('Please supply all fields before saving.');
    }

    $scheduleInfoStmt = $conn->prepare(
        "SELECT ds.student_id, ds.status, CONCAT(u.firstname, ' ', u.lastname) AS full_name
         FROM defense_schedules ds
         JOIN users u ON ds.student_id = u.id
         WHERE ds.id = ?
         LIMIT 1"
    );
    $scheduleInfoStmt->bind_param('i', $defenseId);
    $scheduleInfoStmt->execute();
    $scheduleInfo = $scheduleInfoStmt->get_result()->fetch_assoc();
    $scheduleInfoStmt->close();

    if (!$scheduleInfo) {
        respond_json('Unable to find that defense schedule.');
    }

    $studentId = (int)($scheduleInfo['student_id'] ?? 0);
    $studentName = trim($scheduleInfo['full_name'] ?? '');
    $currentStatus = trim((string)($scheduleInfo['status'] ?? ''));
    if ($currentStatus === '') {
        $currentStatus = 'Pending';
    }
    if (!student_matches_scope($conn, $studentId, $chairScope)) {
        respond_json('You can only modify schedules for students under your program.');
    }
    if ($studentName === '') {
        $studentName = 'the student';
    }
    if ($currentStatus === 'Confirmed') {
        $status = 'Confirmed';
    } else {
        $allowedStatuses = ['Pending', 'Completed', 'Cancelled'];
        if (!in_array($submittedStatus, $allowedStatuses, true)) {
            respond_json('Confirmed status is set by the dean.');
        }
        $status = $submittedStatus;
    }

    $date = date('Y-m-d', strtotime($defenseDate));
    $startTime = date('H:i:s', strtotime($startTimeInput));
    $endTime = date('H:i:s', strtotime($endTimeInput));
    if (strtotime($endTime) <= strtotime($startTime)) {
        respond_json('End time must be later than the start time.');
    }
    if (defenseScheduleHasConflict($conn, $date, $startTime, $endTime, $defenseId)) {
        respond_json('Another defense is already scheduled in this time slot.');
    }

    $stmt = $conn->prepare(
        "UPDATE defense_schedules
         SET defense_date = ?, defense_time = ?, start_time = ?, end_time = ?, venue = ?, status = ?
         WHERE id = ?"
    );
    $stmt->bind_param('ssssssi', $date, $startTime, $startTime, $endTime, $venue, $status, $defenseId);
    $stmt->execute();
    $stmt->close();

    $conn->query("DELETE FROM defense_panels WHERE defense_id = {$defenseId}");

    $insertPanel = $conn->prepare(
        "INSERT INTO defense_panels (defense_id, panel_member, panel_member_id, panel_role) VALUES (?, ?, ?, ?)"
    );
    $committeeNames = [
        'adviser' => '',
        'committee_chair' => '',
        'panel_member' => [],
    ];
    foreach ($panelAssignments as $assignment) {
        if ($assignment['role'] === 'panel_member') {
            $committeeNames['panel_member'][] = $assignment['name'];
        } elseif (isset($committeeNames[$assignment['role']])) {
            $committeeNames[$assignment['role']] = $assignment['name'];
        }
        $insertPanel->bind_param('isis', $defenseId, $assignment['name'], $assignment['id'], $assignment['role']);
        $insertPanel->execute();
    }
    $insertPanel->close();

    $readableSchedule = formatDefenseScheduleLabel($date, $startTime, $endTime);
    $panelList = $committeeNames['panel_member'] ? implode(', ', $committeeNames['panel_member']) : 'Not assigned';
    $description = "Student: {$studentName}\nVenue: {$venue}\nAdviser: {$committeeNames['adviser']}\nChair: {$committeeNames['committee_chair']}\nPanel: {$panelList}";
    $title = "Defense: {$studentName}";
    $startDateTime = "{$date} {$startTime}";
    $endDateTime = "{$date} {$endTime}";
    $calendarUserIds = array_merge([$programChairId, $studentId], array_column($panelAssignments, 'id'));
    sync_defense_calendar_events($conn, $defenseId, $calendarUserIds, $title, $description, $startDateTime, $endDateTime);

    if ($studentId) {
        notify_user_for_role(
            $conn,
            $studentId,
            'student',
            'Defense schedule updated',
            "Your defense schedule has been updated to {$readableSchedule} at {$venue} ({$status}).",
            'calendar.php'
        );
    }

    foreach ($panelAssignments as $assignment) {
        $notifyRole = map_panel_role_for_notification($assignment['role']);
        $link = map_notification_link_for_role($notifyRole);
        notify_user_for_role(
            $conn,
            (int)$assignment['id'],
            $notifyRole,
            'Panel assignment updated',
            "{$studentName}'s defense has been updated to {$readableSchedule} at {$venue} ({$status}).",
            $link
        );
    }

    respond_json('success');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $defenseId = (int)($_POST['id'] ?? 0);
    if (!$defenseId) {
        respond_json('Invalid assignment reference.');
    }

    $scheduleStmt = $conn->prepare(
        "SELECT ds.student_id,
                ds.defense_date,
                ds.defense_time,
                ds.start_time,
                ds.end_time,
                ds.venue,
                CONCAT(u.firstname, ' ', u.lastname) AS full_name
         FROM defense_schedules ds
         JOIN users u ON ds.student_id = u.id
         WHERE ds.id = ?
         LIMIT 1"
    );
    if (!$scheduleStmt) {
        respond_json('Unable to load the requested defense schedule.');
    }
    $scheduleStmt->bind_param('i', $defenseId);
    $scheduleStmt->execute();
    $schedule = $scheduleStmt->get_result()->fetch_assoc();
    $scheduleStmt->close();

    if (!$schedule) {
        respond_json('Unable to find the requested defense schedule.');
    }

    $studentId = (int)($schedule['student_id'] ?? 0);
    if (!student_matches_scope($conn, $studentId, $chairScope)) {
        respond_json('You can only cancel schedules for students under your program.');
    }

    $panelMembers = [];
    $panelStmt = $conn->prepare("SELECT panel_member_id, panel_role FROM defense_panels WHERE defense_id = ?");
    $panelStmt->bind_param('i', $defenseId);
    $panelStmt->execute();
    $panelResult = $panelStmt->get_result();
    while ($panelRow = $panelResult->fetch_assoc()) {
        $panelMembers[] = [
            'id' => (int)($panelRow['panel_member_id'] ?? 0),
            'role' => (string)($panelRow['panel_role'] ?? ''),
        ];
    }
    $panelStmt->close();

    remove_defense_calendar_events($conn, $defenseId);

    $conn->query("DELETE FROM defense_panels WHERE defense_id = {$defenseId}");
    $conn->query("DELETE FROM defense_schedules WHERE id = {$defenseId}");

    if ($schedule) {
        $studentId = (int)($schedule['student_id'] ?? 0);
        $studentName = trim($schedule['full_name'] ?? 'the student');
        $venue = $schedule['venue'] ?? 'the assigned venue';
        $dateValue = $schedule['defense_date'] ?? date('Y-m-d');
        $startValue = $schedule['start_time'] ?? $schedule['defense_time'] ?? '00:00:00';
        $endValue = $schedule['end_time'] ?? $startValue;
        $readableSchedule = formatDefenseScheduleLabel($dateValue, $startValue, $endValue);

        if ($studentId) {
        notify_user_for_role(
            $conn,
            $studentId,
            'student',
            'Defense schedule cancelled',
            "Your defense on {$readableSchedule} at {$venue} has been cancelled.",
            'calendar.php'
        );
    }

    foreach ($panelMembers as $member) {
        if (empty($member['id'])) {
            continue;
        }
        $notifyRole = map_panel_role_for_notification($member['role'] ?? '');
        $link = map_notification_link_for_role($notifyRole);
        notify_user_for_role(
            $conn,
            (int)$member['id'],
            $notifyRole,
            'Panel assignment cancelled',
            "{$studentName}'s defense on {$readableSchedule} at {$venue} has been cancelled.",
            $link
        );
    }
    }

    respond_json('success');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch') {
    header('Content-Type: text/html; charset=utf-8');
    $search = trim(mb_strtolower($_POST['search'] ?? '', 'UTF-8'));
    $statusFilter = trim($_POST['status'] ?? '');

    $sql = "
        SELECT ds.id,
               ds.defense_date,
               ds.defense_time,
               ds.start_time,
               ds.end_time,
               ds.venue,
               ds.status,
               CONCAT(u.firstname, ' ', u.lastname) AS student_name,
               GROUP_CONCAT(dp.panel_member ORDER BY dp.id SEPARATOR ', ') AS panel_members
        FROM defense_schedules ds
        JOIN users u ON ds.student_id = u.id
        LEFT JOIN defense_panels dp ON dp.defense_id = ds.id
    ";

    $conditions = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $conditions[] = "(LOWER(CONCAT(u.firstname, ' ', u.lastname)) LIKE ? OR LOWER(dp.panel_member) LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $types .= 'ss';
    }

    if ($statusFilter !== '') {
        $conditions[] = "ds.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($studentScopeClause !== '') {
        $conditions[] = $studentScopeClause;
        $params = array_merge($params, $studentScopeParams);
        $types .= $studentScopeTypes;
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' GROUP BY ds.id ORDER BY ds.defense_date ASC, COALESCE(ds.start_time, ds.defense_time) ASC';

    $stmt = $conn->prepare($sql);
    $panelDetailStmt = $conn->prepare("SELECT panel_member, panel_member_id, panel_role FROM defense_panels WHERE defense_id = ? ORDER BY id");

    if (!$stmt) {
        echo "<tr><td colspan='6' class='text-center text-muted py-4'>Unable to load panel assignments right now.</td></tr>";
        if ($panelDetailStmt) {
            $panelDetailStmt->close();
        }
        exit;
    }

    bind_scope_params($stmt, $types, $params);
    if (!$stmt->execute()) {
        echo "<tr><td colspan='6' class='text-center text-muted py-4'>Unable to load panel assignments right now.</td></tr>";
        if ($panelDetailStmt) {
            $panelDetailStmt->close();
        }
        $stmt->close();
        exit;
    }

    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        echo "<tr><td colspan='6'><div class='empty-state'><i class='bi bi-inboxes'></i><p class='mb-0'>No panel assignments match your filters.</p></div></td></tr>";
    } else {
        while ($a = $result->fetch_assoc()) {
            $studentRaw = trim($a['student_name'] ?? 'Unknown Student');
            if ($studentRaw === '') {
                $studentRaw = 'Unknown Student';
            }
            $panelRows = [];
            if ($panelDetailStmt) {
                $panelDetailStmt->bind_param('i', $a['id']);
                $panelDetailStmt->execute();
                $panelResult = $panelDetailStmt->get_result();
                $panelRows = $panelResult ? $panelResult->fetch_all(MYSQLI_ASSOC) : [];
            }
            $panelRaw = implode(', ', array_map(fn($row) => trim($row['panel_member'] ?? ''), $panelRows));
            $studentDisplay = htmlspecialchars($studentRaw);
            $highlightPattern = null;
            if ($search !== '') {
                $highlightPattern = '/' . preg_quote($search, '/') . '/iu';
                $studentDisplay = preg_replace($highlightPattern, '<mark class=\"bg-warning text-dark fw-bold\">$0</mark>', $studentDisplay);
            }

            $status = $a['status'] ?? 'Pending';
            $statusClasses = [
                'Confirmed' => 'bg-success-subtle text-success',
                'Pending' => 'bg-warning-subtle text-warning-emphasis',
                'Completed' => 'bg-primary-subtle text-primary',
                'Cancelled' => 'bg-danger-subtle text-danger',
            ];
            $statusBadgeClass = $statusClasses[$status] ?? 'bg-secondary-subtle text-secondary';
            $scheduleDate = $a['defense_date'] ? date('M d, Y', strtotime($a['defense_date'])) : 'TBA';
            $startValue = $a['start_time'] ?? $a['defense_time'];
            $endValue = $a['end_time'] ?? $startValue;
            $scheduleTime = $startValue ? date('h:i A', strtotime($startValue)) : '';
            if ($endValue && $endValue !== $startValue) {
                $scheduleTime .= ' - ' . date('h:i A', strtotime($endValue));
            }

            $venueDisplay = htmlspecialchars($a['venue'] ?? '');
            $venueAttr = htmlspecialchars($a['venue'] ?? '', ENT_QUOTES);
            $dateAttr = htmlspecialchars($a['defense_date'] ?? '', ENT_QUOTES);
            $startAttr = htmlspecialchars($startValue ?? '', ENT_QUOTES);
            $endAttr = htmlspecialchars($endValue ?? '', ENT_QUOTES);
            $panelAttr = htmlspecialchars(json_encode($panelRows, JSON_UNESCAPED_UNICODE), ENT_QUOTES);

            $panelMembers = array_filter(array_map(fn($row) => trim($row['panel_member'] ?? ''), $panelRows));
            $roleLabels = [
                'adviser' => 'Adviser',
                'committee_chair' => 'Chair',
                'panel_member' => 'Panel',
            ];
            $panelChips = '';
            foreach ($panelRows as $memberRow) {
                $memberName = trim($memberRow['panel_member'] ?? '');
                if ($memberName === '') {
                    continue;
                }
                $chipText = htmlspecialchars($memberName);
                if ($highlightPattern) {
                    $chipText = preg_replace($highlightPattern, '<mark class=\"bg-warning text-dark fw-bold\">$0</mark>', $chipText);
                }
                $roleLabel = $roleLabels[$memberRow['panel_role'] ?? 'panel_member'] ?? 'Panel';
                $panelChips .= "<span class='panel-chip'><i class='bi bi-person-badge'></i><strong>{$roleLabel}:</strong> {$chipText}</span>";
            }
            if ($panelChips === '') {
                $panelChips = "<span class='text-muted'>No members assigned</span>";
            }

            echo "
            <tr data-id='{$a['id']}' data-date='{$dateAttr}' data-start='{$startAttr}' data-end='{$endAttr}' data-venue='{$venueAttr}' data-status='{$status}' data-panel='{$panelAttr}'>
                <td><strong>{$studentDisplay}</strong></td>
                <td>
                    <div class='fw-semibold text-dark'>{$scheduleDate}</div>
                    <div class='text-muted small'>{$scheduleTime}</div>
                </td>
                <td>{$panelChips}</td>
                <td class='text-break'>{$venueDisplay}</td>
                <td><span class='status-badge {$statusBadgeClass}'>".htmlspecialchars($status)."</span></td>
                <td>
                    <div class='btn-group btn-group-sm'>
                        <button class='btn btn-outline-primary edit-btn' title='Edit assignment'><i class='bi bi-pencil'></i></button>
                        <button class='btn btn-outline-danger delete-btn' title='Remove assignment'><i class='bi bi-trash'></i></button>
                    </div>
                </td>
            </tr>";
        }
    }

    if ($result) {
        $result->free();
    }
    if ($panelDetailStmt) {
        $panelDetailStmt->close();
    }
    $stmt->close();
    exit;
}
$studentsSql = "SELECT id, firstname, lastname FROM users u WHERE role = 'student'";
if ($studentScopeClause !== '') {
    $studentsSql .= " AND {$studentScopeClause}";
}
$studentsSql .= " ORDER BY firstname";
$students = [];
if ($studentScopeClause === '') {
    if ($studentResult = $conn->query($studentsSql)) {
        $students = $studentResult->fetch_all(MYSQLI_ASSOC);
        $studentResult->free();
    }
} else {
    $studentStmt = $conn->prepare($studentsSql);
    if ($studentStmt && bind_scope_params($studentStmt, $studentScopeTypes, $studentScopeParams)) {
        if ($studentStmt->execute()) {
            $studentResult = $studentStmt->get_result();
            if ($studentResult) {
                $students = $studentResult->fetch_all(MYSQLI_ASSOC);
                $studentResult->free();
            }
        }
        $studentStmt->close();
    }
}
$today = date('Y-m-d');

include 'header.php';
include 'sidebar.php';

$adviserOptions = fetch_users_by_roles($conn, ['adviser']);
$chairOptions = fetch_users_by_roles($conn, ['committee_chair', 'committee_chairperson']);
$panelOptions = fetch_users_by_roles($conn, ['panel', 'faculty']);

$statusTotals = [
    'Confirmed' => 0,
    'Pending' => 0,
    'Completed' => 0,
    'Cancelled' => 0,
];
$totalAssignments = 0;
$statusSql = "
    SELECT ds.status, COUNT(*) AS total
    FROM defense_schedules ds
    JOIN users u ON ds.student_id = u.id
";
if ($studentScopeClause !== '') {
    $statusSql .= " WHERE {$studentScopeClause}";
}
$statusSql .= " GROUP BY ds.status";
if ($studentScopeClause === '') {
    if ($statusResult = $conn->query($statusSql)) {
        while ($row = $statusResult->fetch_assoc()) {
            $status = $row['status'] ?? '';
            $statusTotals[$status] = (int)($row['total'] ?? 0);
            $totalAssignments += (int)($row['total'] ?? 0);
        }
        $statusResult->free();
    }
} else {
    $statusStmt = $conn->prepare($statusSql);
    if ($statusStmt && bind_scope_params($statusStmt, $studentScopeTypes, $studentScopeParams)) {
        if ($statusStmt->execute()) {
            $statusResult = $statusStmt->get_result();
            if ($statusResult) {
                while ($row = $statusResult->fetch_assoc()) {
                    $status = $row['status'] ?? '';
                    $statusTotals[$status] = (int)($row['total'] ?? 0);
                    $totalAssignments += (int)($row['total'] ?? 0);
                }
                $statusResult->free();
            }
        }
        $statusStmt->close();
    }
}
$upcomingAssignments = 0;
$upcomingSql = "
    SELECT COUNT(*) AS total
    FROM defense_schedules ds
    JOIN users u ON ds.student_id = u.id
    WHERE ds.defense_date >= CURDATE()
";
if ($studentScopeClause !== '') {
    $upcomingSql .= " AND {$studentScopeClause}";
}
if ($studentScopeClause === '') {
    if ($upcomingResult = $conn->query($upcomingSql)) {
        $upcomingRow = $upcomingResult->fetch_assoc();
        $upcomingAssignments = (int)($upcomingRow['total'] ?? 0);
        $upcomingResult->free();
    }
} else {
    $upcomingStmt = $conn->prepare($upcomingSql);
    if ($upcomingStmt && bind_scope_params($upcomingStmt, $studentScopeTypes, $studentScopeParams)) {
        if ($upcomingStmt->execute()) {
            $upcomingResult = $upcomingStmt->get_result();
            if ($upcomingResult) {
                $upcomingRow = $upcomingResult->fetch_assoc();
                $upcomingAssignments = (int)($upcomingRow['total'] ?? 0);
                $upcomingResult->free();
            }
        }
        $upcomingStmt->close();
    }
}
$panelRoleInputs = [
    [
        'label' => 'Adviser Reviewer',
        'name' => 'adviser_id',
        'role' => 'adviser',
        'options' => $adviserOptions,
    ],
    [
        'label' => 'Committee Chairperson',
        'name' => 'chair_id',
        'role' => 'committee_chair',
        'options' => $chairOptions,
    ],
    [
        'label' => 'Panel Member 1',
        'name' => 'panel_member_one_id',
        'role' => 'panel_member',
        'options' => $panelOptions,
    ],
    [
        'label' => 'Panel Member 2',
        'name' => 'panel_member_two_id',
        'role' => 'panel_member',
        'options' => $panelOptions,
    ],
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Panel Assignment - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f6f8fb; }
        .content {
            margin-left: var(--sidebar-width-expanded, 240px);
            padding: 28px 24px;
            background: #f6f8fb;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }
        body.sidebar-collapsed .content { margin-left: var(--sidebar-width-collapsed, 70px); }
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .page-title {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            color: #16562c;
        }
        .page-title i { font-size: 2.5rem; }
        .page-title h3 { margin: 0; font-weight: 700; }
        .page-title p { margin: 0; color: #6c757d; font-size: 0.9rem; }
        .action-region { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .action-region .btn { border-radius: 999px; padding-inline: 1.4rem; }
        .stats-row .card {
            border: none;
            border-radius: 18px;
            background: linear-gradient(135deg, #16562c, #0f3d1f);
            color: #fff;
            box-shadow: 0 18px 36px rgba(22, 86, 44, 0.16);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stats-row .card:hover { transform: translateY(-4px); box-shadow: 0 24px 48px rgba(22, 86, 44, 0.2); }
        .stats-row .card .card-body { padding: 20px 22px; }
        .stats-row .card .stat-label { text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.06em; opacity: 0.9; }
        .stats-row .card .stat-value { font-size: 2rem; font-weight: 700; }
        .assistant-card {
            border-radius: 18px;
            border: none;
            background: #ffffff;
            box-shadow: 0 16px 32px rgba(22, 86, 44, 0.08);
        }
        .assistant-card .card-header {
            background: linear-gradient(135deg, #16562c, #0f3d1f);
            color: #fff;
            border-radius: 18px 18px 0 0;
            font-weight: 600;
            padding: 1.1rem 1.5rem;
        }
        .assistant-card .card-body { padding: 1.75rem; }
        .form-label { font-weight: 600; color: #16562c; }
        .form-control, .form-select {
            border-radius: 12px;
            border-color: rgba(22, 86, 44, 0.25);
        }
        .form-control:focus, .form-select:focus {
            border-color: #16562c;
            box-shadow: 0 0 0 0.2rem rgba(22, 86, 44, 0.15);
        }
        .btn-success {
            background: linear-gradient(135deg, #16562c, #1b7942);
            border: none;
            border-radius: 12px;
            padding-inline: 1.5rem;
        }
        .btn-success:hover { background: linear-gradient(135deg, #1b7942, #1d8649); }
        .filters-card {
            border-radius: 18px;
            border: none;
            background: #ffffff;
            box-shadow: 0 12px 24px rgba(22, 86, 44, 0.08);
        }
        .filters-card .card-body { padding: 1.5rem; }
        .filters-card .input-group-text {
            background: transparent;
            border-right: 0;
            color: #16562c;
        }
        .filters-card .form-control { border-left: 0; }
        .filters-card .btn { border-radius: 10px; }
        .table-card {
            border-radius: 20px;
            border: none;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(22, 86, 44, 0.12);
        }
        .table-card .card-header {
            background: linear-gradient(135deg, #16562c, #0f3d1f);
            color: #fff;
            font-weight: 600;
            padding: 1.1rem 1.5rem;
        }
        table.table thead {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
        }
        table.table tbody tr { transition: transform 0.15s ease, box-shadow 0.15s ease; }
        table.table tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: inset 0 0 0 1px rgba(22, 86, 44, 0.08);
        }
        table.table td { vertical-align: middle; padding: 18px 16px; }
        .status-badge {
            border-radius: 999px;
            font-size: 0.75rem;
            padding: 0.35rem 0.7rem;
            letter-spacing: 0.02em;
        }
        .panel-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            background: rgba(22, 86, 44, 0.08);
            color: #16562c;
            padding: 0.3rem 0.6rem;
            border-radius: 999px;
            font-size: 0.78rem;
            margin-right: 0.4rem;
            margin-bottom: 0.4rem;
        }
        .panel-chip i { font-size: 0.9rem; }
        mark { padding: 2px 4px; border-radius: 4px; background: rgba(247, 202, 24, 0.35); }
        .empty-state { text-align: center; padding: 50px 20px; color: #6c757d; }
        .empty-state i { font-size: 2.8rem; color: #16562c; margin-bottom: 0.75rem; }
        @media (max-width: 992px) {
            .content { padding: 20px; }
            .stats-row .card { margin-bottom: 1rem; }
        }
    </style>
</head>
<body>
<div class="content">
    <div class="container my-4">
        <div class="page-header">
            <div class="page-title">
                <i class="bi bi-people-fill"></i>
                <div>
                    <h3>Panel Assignment</h3>
                    <p>Assign chairpersons and panel members to student defenses and monitor upcoming schedules.</p>
                </div>
            </div>
            <div class="action-region">
                <a href="program_chairperson.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
                <button type="button" class="btn btn-outline-primary" onclick="fetchAssignments();">
                    <i class="bi bi-arrow-clockwise"></i> Refresh List
                </button>
            </div>
        </div>

        <div class="row stats-row g-3 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="stat-label">Total Assignments</div>
                        <div class="stat-value"><?= number_format($totalAssignments); ?></div>
                        <div class="small text-white-50">All scheduled defenses</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="stat-label">Upcoming</div>
                        <div class="stat-value"><?= number_format($upcomingAssignments); ?></div>
                        <div class="small text-white-50">Defense date from today</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="stat-label">Confirmed</div>
                        <div class="stat-value"><?= number_format($statusTotals['Confirmed'] ?? 0); ?></div>
                        <div class="small text-white-50">Ready &amp; acknowledged</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="stat-label">Pending</div>
                        <div class="stat-value"><?= number_format($statusTotals['Pending'] ?? 0); ?></div>
                        <div class="small text-white-50">Awaiting confirmation</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card assistant-card mb-4">
            <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Assign New Panel</div>
            <div class="card-body">
                <form id="assignForm">
                    <input type="hidden" name="action" value="add">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Select Student</label>
                            <select name="student_id" class="form-select" required>
                                <option value="">Choose...</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= $student['id']; ?>">
                                        <?= htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Defense Date</label>
                            <input type="date" name="defense_date" class="form-control" min="<?= $today; ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Start Time</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">End Time</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <?php foreach ($panelRoleInputs as $input): ?>
                            <div class="col-md-6 col-lg-3">
                                <label class="form-label"><?= htmlspecialchars($input['label']); ?></label>
                                <select name="<?= htmlspecialchars($input['name']); ?>" class="form-select panel-select" data-role="<?= htmlspecialchars($input['role']); ?>" required>
                                    <option value="">Select <?= htmlspecialchars($input['label']); ?>...</option>
                                    <?php foreach ($input['options'] as $option): ?>
                                        <?php $fullName = htmlspecialchars(trim(($option['firstname'] ?? '') . ' ' . ($option['lastname'] ?? ''))); ?>
                                        <option value="<?= (int)($option['id'] ?? 0); ?>"><?= $fullName; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Venue / Platform</label>
                        <select name="venue" id="assignVenue" class="form-select" required>
                            <option value="">Select venue...</option>
                            <option value="IAdS Conference Room">IAdS Conference Room</option>
                            <option value="Online Platform (MS Teams)">Online Platform (MS Teams)</option>
                            <option value="custom">Others (Specify)</option>
                        </select>
                        <input type="text" name="custom_venue" id="assignCustomVenue" class="form-control mt-2 d-none" placeholder="Enter the venue or platform">
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-send-check me-2"></i>Assign Panel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card filters-card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by student, panel member, or venue">
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Status</label>
                        <select id="statusFilter" class="form-select">
                            <option value="">All statuses</option>
                            <option value="Pending">Pending</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button type="button" class="btn btn-outline-secondary" id="clearFilter">
                            <i class="bi bi-eraser me-1"></i>Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card table-card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-calendar-event me-2"></i>Scheduled Defense Panels</span>
                    <span class="badge bg-light text-dark px-3 py-2" id="assignmentCountBadge">Loading...</span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 20%">Student</th>
                                <th style="width: 18%">Schedule</th>
                                <th style="width: 30%">Panel Members</th>
                                <th style="width: 18%">Venue</th>
                                <th style="width: 8%">Status</th>
                                <th style="width: 6%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="assignmentsTable">
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="bi bi-hourglass-split"></i>
                                        <p class="mb-0">Fetching assigned panels...</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Edit / Reassign Defense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date" name="defense_date" id="editDate" class="form-control" min="<?= $today; ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Start Time</label>
                            <input type="time" name="start_time" id="editStartTime" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">End Time</label>
                            <input type="time" name="end_time" id="editEndTime" class="form-control" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Venue</label>
                            <select name="venue" id="editVenue" class="form-select" required>
                                <option value="IAdS Conference Room">IAdS Conference Room</option>
                                <option value="Online Platform (MS Teams)">Online Platform (MS Teams)</option>
                                <option value="custom">Others (Specify)</option>
                            </select>
                            <input type="text" name="custom_venue" id="editCustomVenue" class="form-control mt-2 d-none" placeholder="Enter the venue or platform">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <?php foreach ($panelRoleInputs as $input): ?>
                            <div class="col-md-6 col-lg-3">
                                <label class="form-label fw-bold"><?= htmlspecialchars($input['label']); ?></label>
                                <select name="<?= htmlspecialchars($input['name']); ?>" class="form-select edit-member" data-role="<?= htmlspecialchars($input['role']); ?>" required>
                                    <option value="">Select <?= htmlspecialchars($input['label']); ?>...</option>
                                    <?php foreach ($input['options'] as $option): ?>
                                        <?php $fullName = htmlspecialchars(trim(($option['firstname'] ?? '') . ' ' . ($option['lastname'] ?? ''))); ?>
                                        <option value="<?= (int)($option['id'] ?? 0); ?>"><?= $fullName; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" id="editStatus" class="form-select">
                            <option value="Pending">Pending</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                        <div class="form-text">Confirmed is set by the dean.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Save Changes</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const assignmentsTable = document.getElementById('assignmentsTable');
    const assignmentCountBadge = document.getElementById('assignmentCountBadge');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const clearFilterBtn = document.getElementById('clearFilter');
    const assignForm = document.getElementById('assignForm');
    const editForm = document.getElementById('editForm');
    const editModalElement = document.getElementById('editModal');
    const editModal = editModalElement ? new bootstrap.Modal(editModalElement) : null;
    const editStartTimeInput = editModalElement ? editModalElement.querySelector('#editStartTime') : null;
    const editEndTimeInput = editModalElement ? editModalElement.querySelector('#editEndTime') : null;
    const assignVenueSelect = document.getElementById('assignVenue');
    const assignCustomVenueInput = document.getElementById('assignCustomVenue');
    const assignStudentSelect = assignForm ? assignForm.querySelector('select[name="student_id"]') : null;
    const assignAdviserSelect = assignForm ? assignForm.querySelector('select[name="adviser_id"]') : null;
    const assignChairSelect = assignForm ? assignForm.querySelector('select[name="chair_id"]') : null;
    const assignPanelOneSelect = assignForm ? assignForm.querySelector('select[name="panel_member_one_id"]') : null;
    const assignPanelTwoSelect = assignForm ? assignForm.querySelector('select[name="panel_member_two_id"]') : null;
    const editVenueSelect = editModalElement ? editModalElement.querySelector('#editVenue') : null;
    const editCustomVenueInput = editModalElement ? editModalElement.querySelector('#editCustomVenue') : null;

    const updateCustomVenueVisibility = (select, input) => {
        if (!select || !input) return;
        const shouldShow = select.value === 'custom';
        input.classList.toggle('d-none', !shouldShow);
        input.required = shouldShow;
        if (!shouldShow && select.value !== 'custom') {
            input.value = '';
        }
    };

    const attachCustomVenueHandler = (select, input) => {
        if (!select || !input) return;
        select.addEventListener('change', () => updateCustomVenueVisibility(select, input));
        updateCustomVenueVisibility(select, input);
    };

    const setVenueValue = (select, input, venueValue) => {
        if (!select || !input) return;
        const value = (venueValue || '').trim();
        const hasMatchingOption = Array.from(select.options).some(
            (option) => option.value !== 'custom' && option.value === value
        );
        if (value && hasMatchingOption) {
            select.value = value;
            input.value = '';
        } else if (value) {
            select.value = 'custom';
            input.value = value;
        } else {
            select.value = '';
            input.value = '';
        }
        updateCustomVenueVisibility(select, input);
    };

    const setSelectValue = (select, value, label) => {
        if (!select) return;
        const stringValue = value ? String(value) : '';
        if (!stringValue) {
            select.value = '';
            return;
        }
        let option = Array.from(select.options).find((item) => item.value === stringValue);
        if (!option) {
            const fallbackLabel = label && label.trim() ? label : `Assigned member #${stringValue}`;
            option = document.createElement('option');
            option.value = stringValue;
            option.textContent = fallbackLabel;
            select.appendChild(option);
        }
        select.value = stringValue;
    };

    const clearCommitteeFields = () => {
        setSelectValue(assignAdviserSelect, '');
        setSelectValue(assignChairSelect, '');
        setSelectValue(assignPanelOneSelect, '');
        setSelectValue(assignPanelTwoSelect, '');
        setVenueValue(assignVenueSelect, assignCustomVenueInput, '');
    };

    const populateCommitteeFields = (data) => {
        setSelectValue(assignAdviserSelect, data.adviser_id || '', data.adviser_name || '');
        setSelectValue(assignChairSelect, data.chair_id || '', data.chair_name || '');
        setSelectValue(assignPanelOneSelect, data.panel_member_one_id || '', data.panel_member_one_name || '');
        setSelectValue(assignPanelTwoSelect, data.panel_member_two_id || '', data.panel_member_two_name || '');
        setVenueValue(assignVenueSelect, assignCustomVenueInput, data.venue || '');
    };

    attachCustomVenueHandler(assignVenueSelect, assignCustomVenueInput);
    attachCustomVenueHandler(editVenueSelect, editCustomVenueInput);

    const updateBadgeCount = () => {
        if (!assignmentsTable || !assignmentCountBadge) return;
        const rows = assignmentsTable.querySelectorAll('tr[data-id]');
        assignmentCountBadge.textContent = rows.length ? `${rows.length} assignment${rows.length === 1 ? '' : 's'}` : '0 assignments';
    };

    window.fetchAssignments = () => {
        const fd = new FormData();
        fd.append('action', 'fetch');
        fd.append('search', searchInput ? searchInput.value : '');
        fd.append('status', statusFilter ? statusFilter.value : '');

        fetch('assign_panel.php', { method: 'POST', body: fd })
            .then((response) => response.text())
            .then((html) => {
                if (assignmentsTable) {
                    assignmentsTable.innerHTML = html;
                    updateBadgeCount();
                }
            })
            .catch(() => {
                if (assignmentsTable) {
                    assignmentsTable.innerHTML = "<tr><td colspan='6'><div class='empty-state'><i class='bi bi-exclamation-circle'></i><p class='mb-0'>Unable to load assignments right now.</p></div></td></tr>";
                }
                if (assignmentCountBadge) {
                    assignmentCountBadge.textContent = 'Error';
                }
            });
    };

    if (assignForm) {
        assignForm.addEventListener('submit', (event) => {
            event.preventDefault();
            fetch('assign_panel.php', { method: 'POST', body: new FormData(assignForm) })
                .then((response) => response.text())
                .then((result) => {
                    if (result.trim() === 'success') {
                        alert('Panel assignment saved.');
                        assignForm.reset();
                        updateCustomVenueVisibility(assignVenueSelect, assignCustomVenueInput);
                        window.fetchAssignments();
                    } else {
                        alert(result);
                    }
                })
                .catch(() => alert('Unable to save the assignment right now.'));
        });
    }

    if (assignStudentSelect) {
        assignStudentSelect.addEventListener('change', () => {
            const studentId = assignStudentSelect.value;
            if (!studentId) {
                clearCommitteeFields();
                return;
            }
            const fd = new FormData();
            fd.append('action', 'committee_lookup');
            fd.append('student_id', studentId);
            fetch('assign_panel.php', { method: 'POST', body: fd })
                .then((response) => response.json())
                .then((payload) => {
                    if (payload && payload.found && payload.data) {
                        populateCommitteeFields(payload.data);
                    } else {
                        clearCommitteeFields();
                    }
                })
                .catch(() => {
                    clearCommitteeFields();
                });
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', window.fetchAssignments);
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', window.fetchAssignments);
    }

    if (clearFilterBtn) {
        clearFilterBtn.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            window.fetchAssignments();
        });
    }

    document.addEventListener('click', (event) => {
        const row = event.target.closest('tr[data-id]');
        if (!row) return;

        if (event.target.closest('.edit-btn')) {
            if (!editModalElement) return;
            editModalElement.querySelector('#editId').value = row.dataset.id || '';
            editModalElement.querySelector('#editStatus').value = row.dataset.status || 'Pending';
            const dateValue = (row.dataset.date || '').trim();
            editModalElement.querySelector('#editDate').value = dateValue;
            const normalizeTime = (value) => value ? value.substring(0,5) : '';
            if (editStartTimeInput) {
                editStartTimeInput.value = normalizeTime(row.dataset.start || '');
            }
            if (editEndTimeInput) {
                editEndTimeInput.value = normalizeTime(row.dataset.end || '');
            }
            setVenueValue(editVenueSelect, editCustomVenueInput, row.dataset.venue || '');

            let panelData = [];
            if (row.dataset.panel) {
                try { panelData = JSON.parse(row.dataset.panel); } catch (e) { panelData = []; }
            }
            const memberSelects = editModalElement.querySelectorAll('.edit-member');
            const grouped = {};
            panelData.forEach((item) => {
                const role = (item.panel_role || item.role || '').trim();
                if (!role) return;
                if (!grouped[role]) {
                    grouped[role] = [];
                }
                grouped[role].push(item);
            });
            memberSelects.forEach((select) => {
                const role = select.dataset.role;
                const pool = grouped[role] || [];
                const match = pool.shift();
                select.value = match ? (match.panel_member_id || '') : '';
            });

            editModal?.show();
        }

        if (event.target.closest('.delete-btn')) {
            if (!confirm('Remove this panel assignment?')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', row.dataset.id || '');
            fetch('assign_panel.php', { method: 'POST', body: fd })
                .then((response) => response.text())
                .then((result) => {
                    if (result.trim() === 'success') {
                        alert('Panel assignment removed.');
                        window.fetchAssignments();
                    } else {
                        alert(result);
                    }
                })
                .catch(() => alert('Unable to delete the assignment right now.'));
        }
    });

    if (editForm) {
        editForm.addEventListener('submit', (event) => {
            event.preventDefault();
            fetch('assign_panel.php', { method: 'POST', body: new FormData(editForm) })
                .then((response) => response.text())
                .then((result) => {
                    if (result.trim() === 'success') {
                        alert('Panel assignment updated.');
                        window.fetchAssignments();
                        editModal?.hide();
                    } else {
                        alert(result);
                    }
                })
                .catch(() => alert('Unable to update the assignment right now.'));
        });
    }

    window.fetchAssignments();
})();
</script>
</body>
</html>
