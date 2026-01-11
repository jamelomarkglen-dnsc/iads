<?php

function get_program_chair_scope(mysqli $conn, int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $scope = [
        'program' => '',
        'department' => '',
        'college' => '',
    ];

    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT program, department, college FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $scope['program'] = trim((string)($row['program'] ?? ''));
                    $scope['department'] = trim((string)($row['department'] ?? ''));
                    $scope['college'] = trim((string)($row['college'] ?? ''));
                }
                if ($result) {
                    $result->free();
                }
            }
            $stmt->close();
        }
    }

    return $cache[$userId] = $scope;
}

function build_scope_condition(array $scope, string $alias = 'u'): array
{
    $program = trim((string)($scope['program'] ?? ''));
    $department = trim((string)($scope['department'] ?? ''));
    $college = trim((string)($scope['college'] ?? ''));

    if ($program !== '') {
        return ["{$alias}.program = ?", 's', [$program]];
    }
    if ($department !== '') {
        return ["{$alias}.department = ?", 's', [$department]];
    }
    if ($college !== '') {
        return ["{$alias}.college = ?", 's', [$college]];
    }

    return ['', '', []];
}

function student_matches_scope(mysqli $conn, int $studentId, array $scope): bool
{
    if ($studentId <= 0) {
        return false;
    }

    $program = trim((string)($scope['program'] ?? ''));
    $department = trim((string)($scope['department'] ?? ''));
    $college = trim((string)($scope['college'] ?? ''));

    if ($program === '' && $department === '' && $college === '') {
        return true;
    }

    static $studentCache = [];
    if (!isset($studentCache[$studentId])) {
        $stmt = $conn->prepare("SELECT program, department, college FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $studentId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
        $result = $stmt->get_result();
        $studentCache[$studentId] = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
    }

    $student = $studentCache[$studentId] ?? null;
    if (!$student) {
        return false;
    }

    $studentProgram = trim((string)($student['program'] ?? ''));
    $studentDepartment = trim((string)($student['department'] ?? ''));
    $studentCollege = trim((string)($student['college'] ?? ''));

    if ($program !== '') {
        return strcasecmp($studentProgram, $program) === 0;
    }
    if ($department !== '') {
        return strcasecmp($studentDepartment, $department) === 0;
    }
    if ($college !== '') {
        return strcasecmp($studentCollege, $college) === 0;
    }

    return true;
}

function bind_scope_params(mysqli_stmt $stmt, string $types, array $params): bool
{
    if ($types === '' || empty($params)) {
        return true;
    }

    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = &$params[$index];
    }
    array_unshift($refs, $types);
    return (bool)call_user_func_array([$stmt, 'bind_param'], $refs);
}

function render_scope_condition(mysqli $conn, array $scope, string $alias = 'u'): string
{
    [$clause, , $params] = build_scope_condition($scope, $alias);
    if ($clause === '') {
        return '';
    }
    $value = $params[0] ?? '';
    $escaped = $conn->real_escape_string($value);
    return str_replace('?', "'" . $escaped . "'", $clause);
}
