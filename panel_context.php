<?php
require_once 'db.php';

if (!isset($_SESSION)) {
    session_start();
}

/**
 * Helpers
 */
if (!function_exists('panel_fetch_assignments')) {
    function panel_fetch_assignments(mysqli $conn, string $panelName): array
    {
        $sql = "
            SELECT ds.id AS defense_id,
                   ds.defense_date,
                   ds.defense_time,
                   ds.venue,
                   ds.status,
                   dp.id AS panel_entry_id,
                   dp.response,
                   CONCAT(u.firstname, ' ', u.lastname) AS student_name,
                   GROUP_CONCAT(dp2.panel_member SEPARATOR ', ') AS panel_members,
                   s.title AS submission_title
            FROM defense_schedules ds
            JOIN users u ON ds.student_id = u.id
            JOIN defense_panels dp ON dp.defense_id = ds.id
            JOIN defense_panels dp2 ON dp2.defense_id = ds.id
            LEFT JOIN submissions s ON s.student_id = ds.student_id
            WHERE dp.panel_member LIKE CONCAT('%', ?, '%')
            GROUP BY ds.id
            ORDER BY ds.defense_date DESC
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $panelName);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $records;
    }
}

if (!function_exists('panel_normalise_response')) {
    function panel_normalise_response(?string $response): string
    {
        $value = strtolower(trim((string)$response));
        return match ($value) {
            'accepted' => 'Accepted',
            'declined' => 'Declined',
            default => 'Pending',
        };
    }
}

if (!function_exists('panel_fetch_program_chair')) {
    function panel_fetch_program_chair(mysqli $conn): array
    {
        $hasUpdatedAt = false;
        if ($columns = $conn->query("SHOW COLUMNS FROM users LIKE 'updated_at'")) {
            $hasUpdatedAt = $columns->num_rows > 0;
            $columns->free();
        }

        $orderClause = $hasUpdatedAt ? 'ORDER BY updated_at DESC, id DESC' : 'ORDER BY id DESC';
        $sql = "
            SELECT firstname, lastname
            FROM users
            WHERE role = 'program_chairperson'
            {$orderClause}
            LIMIT 1
        ";
        $result = $conn->query($sql);

        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }

        $fullName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Program Chairperson';
        }

        return [
            'full_name' => $fullName,
            'title'     => 'Program Chairperson',
        ];
    }
}

if (!function_exists('panel_format_schedule_label')) {
    function panel_format_schedule_label(?string $date, ?string $time): string
    {
        $date = $date ? trim($date) : '';
        $time = $time ? trim($time) : '';

        if ($date === '' && $time === '') {
            return 'a schedule to be announced';
        }

        $timestamp = trim($date . ' ' . $time);
        $parsed = strtotime($timestamp);
        if ($parsed) {
            return date('F d, Y g:i A', $parsed);
        }

        if ($date !== '') {
            $parsedDate = strtotime($date);
            if ($parsedDate) {
                return date('F d, Y', $parsedDate);
            }
        }

        if ($time !== '') {
            $parsedTime = strtotime($time);
            if ($parsedTime) {
                return date('g:i A', $parsedTime);
            }
        }

        return 'a schedule to be announced';
    }
}

if (!function_exists('panel_extract_other_members')) {
    function panel_extract_other_members(array $invite, string $panelName): array
    {
        $rawMembers = $invite['panel_members'] ?? '';
        if ($rawMembers === '') {
            return [];
        }
        $current = strtolower(trim($panelName));
        $members = array_filter(array_map('trim', explode(',', $rawMembers)));

        return array_values(array_filter(
            $members,
            fn(string $member): bool => strtolower($member) !== $current
        ));
    }
}

if (!function_exists('panel_format_member_list')) {
    function panel_format_member_list(array $members): string
    {
        $count = count($members);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $members[0];
        }
        $last = array_pop($members);
        return implode(', ', $members) . ' and ' . $last;
    }
}

if (!function_exists('panel_build_invitation_letter')) {
    function panel_build_invitation_letter(array $invite, array $programChairInfo, string $panelName): string
    {
        $chairName = $programChairInfo['full_name'] ?? 'the Program Chairperson';
        $studentName = trim($invite['student_name'] ?? 'the student');
        $studyTitle = trim($invite['submission_title'] ?? 'the research study');
        $schedule = panel_format_schedule_label($invite['defense_date'] ?? null, $invite['defense_time'] ?? null);
        $venue = trim($invite['venue'] ?? '');
        $venueText = $venue !== '' ? $venue : 'the assigned venue';
        $collaborators = panel_extract_other_members($invite, $panelName);

        $paragraphs = [];
        $paragraphs[] = 'Dear ' . ($panelName ?: 'Panel Member') . ',';
        $paragraphs[] = "{$chairName} respectfully invites you to serve on the defense panel for {$studentName}'s study titled \"{$studyTitle}\".";
        $paragraphs[] = "The defense is scheduled on {$schedule} at {$venueText}. Kindly review the manuscript ahead of time and confirm your availability.";

        if (!empty($collaborators)) {
            $paragraphs[] = 'You will be collaborating with ' . panel_format_member_list($collaborators) . ' for this engagement.';
        }

        $paragraphs[] = 'Please let us know if you accept this assignment by using the buttons below so we can finalize the official panel line-up.';
        $paragraphs[] = 'Thank you for supporting the graduate students of DNSC.';

        return implode("\n\n", $paragraphs);
    }
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'panel') {
    header('Location: login.php');
    exit;
}

$panelProfile = [
    'id'          => (int)($_SESSION['user_id'] ?? 0),
    'first_name'  => $_SESSION['first_name'] ?? $_SESSION['firstname'] ?? '',
    'last_name'   => $_SESSION['last_name'] ?? $_SESSION['lastname'] ?? '',
];
$panelProfile['display_name'] = trim(($panelProfile['first_name'] ?? '') . ' ' . ($panelProfile['last_name'] ?? ''));
if ($panelProfile['display_name'] === '') {
    $panelProfile['display_name'] = $_SESSION['username'] ?? 'Panel Member';
}

$defenses = panel_fetch_assignments($conn, $panelProfile['display_name']);
$pendingInvites = array_values(array_filter(
    $defenses,
    fn(array $record): bool => panel_normalise_response($record['response'] ?? null) === 'Pending'
));
$acceptedInvites = array_values(array_filter(
    $defenses,
    fn(array $record): bool => panel_normalise_response($record['response'] ?? null) === 'Accepted'
));

$stats = [
    'pending'   => count($pendingInvites),
    'accepted'  => count($acceptedInvites),
    'scheduled' => count(array_filter(
        $acceptedInvites,
        fn(array $record): bool => ($record['status'] ?? '') === 'Confirmed'
    )),
    'completed' => count(array_filter(
        $acceptedInvites,
        fn(array $record): bool => ($record['status'] ?? '') === 'Completed'
    )),
];

$programChairInfo = panel_fetch_program_chair($conn);
