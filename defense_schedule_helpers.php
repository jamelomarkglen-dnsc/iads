<?php

function ensureDefenseScheduleTimeColumns(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $columns = [
        'start_time' => "ALTER TABLE defense_schedules ADD COLUMN start_time TIME NULL AFTER defense_time",
        'end_time' => "ALTER TABLE defense_schedules ADD COLUMN end_time TIME NULL AFTER start_time"
    ];

    foreach ($columns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM defense_schedules LIKE '{$column}'");
        if ($check && $check->num_rows === 0) {
            $conn->query($alterSql);
        }
        if ($check) {
            $check->free();
        }
    }

    // Normalize legacy rows so calendar queries have data
    $conn->query("
        UPDATE defense_schedules
        SET start_time = defense_time
        WHERE (start_time IS NULL OR start_time = '00:00:00')
          AND defense_time IS NOT NULL
    ");
    $conn->query("
        UPDATE defense_schedules
        SET end_time = COALESCE(end_time, ADDTIME(COALESCE(start_time, defense_time), '01:00:00'))
        WHERE end_time IS NULL
          AND COALESCE(start_time, defense_time) IS NOT NULL
    ");

    $ensured = true;
}

function defenseScheduleHasConflict(mysqli $conn, string $date, string $startTime, string $endTime, int $excludeId = 0): bool
{
    ensureDefenseScheduleTimeColumns($conn);
    $startDateTime = date('Y-m-d H:i:s', strtotime("{$date} {$startTime}"));
    $endDateTime = date('Y-m-d H:i:s', strtotime("{$date} {$endTime}"));

    $sql = "
        SELECT id
        FROM defense_schedules
        WHERE defense_date = ?
          AND TIMESTAMP(defense_date, COALESCE(start_time, defense_time)) < ?
          AND TIMESTAMP(defense_date, COALESCE(end_time, defense_time, start_time)) > ?
    ";
    $types = 'sss';
    $params = [$date, $endDateTime, $startDateTime];
    if ($excludeId > 0) {
        $sql .= " AND id <> ?";
        $types .= 'i';
        $params[] = $excludeId;
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $conflict = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $conflict;
}

function formatDefenseScheduleLabel(string $date, string $startTime, ?string $endTime = null): string
{
    $startLabel = date('F d, Y g:i A', strtotime("{$date} {$startTime}"));
    if (!$endTime) {
        return $startLabel;
    }
    $endLabel = date('g:i A', strtotime("{$date} {$endTime}"));
    return "{$startLabel} - {$endLabel}";
}
