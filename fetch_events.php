<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

function calendar_column_exists(mysqli $conn, string $table, string $column): bool
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

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

// Validate and sanitize input dates
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-1 month'));
$end = $_GET['end'] ?? date('Y-m-d', strtotime('+2 months'));

try {
    $hasSourceColumn = calendar_column_exists($conn, 'user_events', 'source');
    $hasSourceIdColumn = calendar_column_exists($conn, 'user_events', 'source_id');
    $hasLockedColumn = calendar_column_exists($conn, 'user_events', 'is_locked');

    $selectColumns = "
            id,
            title,
            start_datetime AS start,
            end_datetime AS end,
            category,
            color,
            description
    ";
    if ($hasSourceColumn) {
        $selectColumns .= ", source";
    }
    if ($hasSourceIdColumn) {
        $selectColumns .= ", source_id";
    }
    if ($hasLockedColumn) {
        $selectColumns .= ", is_locked";
    }

    // Prepare statement to fetch user-specific events
    $whereClause = $hasSourceColumn
        ? "(user_id = ? AND (role = ? OR source = 'defense'))"
        : "(user_id = ? AND role = ?)";

    $stmt = $conn->prepare("
        SELECT {$selectColumns}
        FROM user_events
        WHERE (
            ({$whereClause})
            OR (user_id IS NULL AND role IS NULL)
        )
        AND (
            (start_datetime BETWEEN ? AND ?)
            OR (end_datetime BETWEEN ? AND ?)
            OR (? BETWEEN start_datetime AND end_datetime)
        )
    ");

    // Bind parameters
    $stmt->bind_param(
        'issssss',
        $user_id,
        $user_role,
        $start,
        $end,
        $start,
        $end,
        $start
    );

    // Execute query
    $stmt->execute();
    $result = $stmt->get_result();

    // Prepare events array
    $events = [];
    while ($row = $result->fetch_assoc()) {
        // Transform row for FullCalendar
        $source = $hasSourceColumn ? ($row['source'] ?? null) : null;
        $sourceId = $hasSourceIdColumn ? ($row['source_id'] ?? null) : null;
        $isLocked = $hasLockedColumn ? (bool)$row['is_locked'] : false;
        $isDefense = ($source === 'defense');
        $locked = $isLocked || $isDefense;

        $event = [
            'id' => $row['id'],
            'title' => $row['title'],
            'start' => $row['start'],
            'end' => $row['end'],
            'allDay' => false,
            'className' => $row['category'],
            'backgroundColor' => $row['color'] ?? '#16562c',
            'borderColor' => $row['color'] ?? '#16562c',
            'editable' => !$locked,
            'startEditable' => !$locked,
            'durationEditable' => !$locked,
            'extendedProps' => [
                'description' => $row['description'] ?? '',
                'category' => $row['category'],
                'source' => $source,
                'source_id' => $sourceId,
                'is_locked' => $locked
            ]
        ];
        $events[] = $event;
    }

    // Return events as JSON
    echo json_encode($events);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error', 
        'message' => $e->getMessage()
    ]);
}
