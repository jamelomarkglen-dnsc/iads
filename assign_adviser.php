<?php
session_start();
require_once 'db.php';
require_once 'chair_scope_helper.php';
require_once 'notifications_helper.php';
require_once 'role_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header('Location: login.php');
    exit;
}

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);
ensureRoleInfrastructure($conn);

function columnExists(mysqli $conn, string $table, string $column): bool
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

function tableExists(mysqli $conn, string $table): bool
{
    $tableEscaped = $conn->real_escape_string($table);
    $sql = "
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$tableEscaped}'
        LIMIT 1
    ";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

if (!function_exists('buildAdvisorUnassignedClause')) {
    function buildAdvisorUnassignedClause(string $alias, array $columns): string
    {
        if (empty($columns)) {
            return '1=1';
        }
        $parts = array_map(
            static fn($column) => "({$alias}.{$column} IS NULL OR {$alias}.{$column} = 0)",
            $columns
        );
        return '(' . implode(' AND ', $parts) . ')';
    }
}

$chairAssignAlert = null;
$chairAdviserOptions = [];
$chairUnassignedStudents = [];
$chairUnassignedTotal = 0;
$adviserAssignmentLimit = 75;
$hasAdviserIdColumn = columnExists($conn, 'users', 'adviser_id');
$hasAdvisorIdColumn = columnExists($conn, 'users', 'advisor_id');
$advisorColumns = [];
if ($hasAdviserIdColumn) {
    $advisorColumns[] = 'adviser_id';
}
if ($hasAdvisorIdColumn) {
    $advisorColumns[] = 'advisor_id';
}
$adviserAssignmentEnabled = !empty($advisorColumns);
$unassignedClause = buildAdvisorUnassignedClause('u', $advisorColumns);
$studentScopeCondition = '';
if (!empty(array_filter($chairScope))) {
    $scopeParts = [];
    $programScope = trim((string)($chairScope['program'] ?? ''));
    $departmentScope = trim((string)($chairScope['department'] ?? ''));
    $collegeScope = trim((string)($chairScope['college'] ?? ''));
    if ($programScope !== '') {
        $scopeParts[] = "u.program = '" . $conn->real_escape_string($programScope) . "'";
    }
    if ($departmentScope !== '') {
        $scopeParts[] = "u.department = '" . $conn->real_escape_string($departmentScope) . "'";
    }
    if ($collegeScope !== '') {
        $scopeParts[] = "u.college = '" . $conn->real_escape_string($collegeScope) . "'";
    }
    if (!empty($scopeParts)) {
        $studentScopeCondition = '(' . implode(' OR ', $scopeParts) . ')';
    }
}

function fetchInterestedAdviserIdsByStudent(mysqli $conn, array $studentIds, array $eligibleAdviserIds): array
{
    $studentIds = array_values(array_unique(array_filter(
        array_map(static fn($value) => (int)$value, $studentIds),
        static fn($id) => $id > 0
    )));
    $eligibleAdviserIds = array_values(array_unique(array_filter(
        array_map(static fn($value) => (int)$value, $eligibleAdviserIds),
        static fn($id) => $id > 0
    )));
    if (empty($studentIds) || empty($eligibleAdviserIds)) {
        return [];
    }
    if (!tableExists($conn, 'concept_reviews') || !tableExists($conn, 'concept_reviewer_assignments')) {
        return [];
    }

    $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
    $adviserPlaceholders = implode(',', array_fill(0, count($eligibleAdviserIds), '?'));
    $sql = "
        SELECT DISTINCT cra.student_id, cra.reviewer_id
        FROM concept_reviews cr
        JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
        WHERE cr.adviser_interest = 1
          AND cra.student_id IN ({$studentPlaceholders})
          AND cra.reviewer_id IN ({$adviserPlaceholders})
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $types = str_repeat('i', count($studentIds) + count($eligibleAdviserIds));
    $params = array_merge($studentIds, $eligibleAdviserIds);
    $stmt->bind_param($types, ...$params);
    $map = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $studentId = (int)($row['student_id'] ?? 0);
                $adviserId = (int)($row['reviewer_id'] ?? 0);
                if ($studentId <= 0 || $adviserId <= 0) {
                    continue;
                }
                if (!isset($map[$studentId])) {
                    $map[$studentId] = [];
                }
                $map[$studentId][$adviserId] = true;
            }
            $result->free();
        }
    }
    $stmt->close();

    foreach ($map as $studentId => $adviserSet) {
        $map[$studentId] = array_values(array_map('intval', array_keys($adviserSet)));
    }

    return $map;
}
$adviserScopeCondition = render_scope_condition($conn, $chairScope, 'a');
$restrictAdvisersToScope = false;
$chairQuickLookupValue = '';
$adviserSql = "
    SELECT DISTINCT a.id,
           CONCAT(COALESCE(a.firstname, ''), ' ', COALESCE(a.lastname, '')) AS full_name,
           COALESCE(a.program, '') AS program,
           COALESCE(a.department, '') AS department
    FROM users a
    LEFT JOIN user_roles ar ON ar.user_id = a.id AND ar.role_code = 'adviser'
    WHERE a.role NOT IN ('student', 'program_chairperson')
      AND (a.role IN ('adviser', 'faculty') OR ar.user_id IS NOT NULL)
";
if ($adviserScopeCondition !== '' && $restrictAdvisersToScope) {
    $adviserSql .= " AND {$adviserScopeCondition}";
}
$adviserSql .= " ORDER BY a.lastname, a.firstname";
if ($adviserResult = $conn->query($adviserSql)) {
    while ($row = $adviserResult->fetch_assoc()) {
        $row['full_name'] = trim((string)($row['full_name'] ?? '')) ?: 'Adviser #' . (int)($row['id'] ?? 0);
        $chairAdviserOptions[] = $row;
    }
    $adviserResult->free();
}
$adviserOptionLookup = [];
foreach ($chairAdviserOptions as $option) {
    $optionId = (int)($option['id'] ?? 0);
    if ($optionId > 0) {
        $adviserOptionLookup[$optionId] = $option;
    }
}
$eligibleAdviserIds = array_values(array_map(
    static fn($option) => (int)($option['id'] ?? 0),
    $chairAdviserOptions
));

if (isset($_GET['chair_assign_search']) && $_GET['chair_assign_search'] === '1') {
    header('Content-Type: application/json');
    if (!$adviserAssignmentEnabled) {
        echo json_encode(['success' => false, 'error' => 'Adviser assignment columns are missing.']);
        exit;
    }

    $searchTerm = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($searchTerm) < 2) {
        echo json_encode(['success' => true, 'results' => [], 'minLength' => 2]);
        exit;
    }

    $searchLimit = 25;
    $searchSql = "
        SELECT
            u.id,
            COALESCE(u.student_id, '') AS student_id,
            COALESCE(u.firstname, '') AS firstname,
            COALESCE(u.lastname, '') AS lastname,
            COALESCE(u.email, '') AS email,
            COALESCE(u.program, '') AS program,
            COALESCE(u.year_level, '') AS year_level
        FROM users u
        WHERE u.role = 'student'
          AND {$unassignedClause}
    ";
    if ($studentScopeCondition !== '') {
        $searchSql .= " AND {$studentScopeCondition}";
    }
    $searchSql .= "
          AND (
                u.firstname LIKE ?
                OR u.lastname LIKE ?
                OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?
                OR u.student_id LIKE ?
                OR u.email LIKE ?
            )
        ORDER BY u.lastname, u.firstname
        LIMIT {$searchLimit}
    ";

    $pattern = '%' . $searchTerm . '%';
    $searchStmt = $conn->prepare($searchSql);
    if (!$searchStmt) {
        echo json_encode(['success' => false, 'error' => 'Unable to prepare search query.']);
        exit;
    }
    $searchStmt->bind_param('sssss', $pattern, $pattern, $pattern, $pattern, $pattern);
    $searchStmt->execute();
    $searchResult = $searchStmt->get_result();
    $results = [];
    while ($row = $searchResult->fetch_assoc()) {
        $studentId = (int)($row['id'] ?? 0);
        if ($studentId <= 0) {
            continue;
        }
        $fullName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
        if ($fullName === '') {
            $fullName = 'Student #' . $studentId;
        }
        $results[] = [
            'id' => $studentId,
            'name' => $fullName,
            'student_id' => trim((string)($row['student_id'] ?? '')),
            'program' => trim((string)($row['program'] ?? '')),
            'year_level' => trim((string)($row['year_level'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
        ];
    }
    if ($searchResult) {
        $searchResult->free();
    }
    $searchStmt->close();

    if (!empty($results) && !empty($eligibleAdviserIds)) {
        $searchStudentIds = array_column($results, 'id');
        $interestLookup = fetchInterestedAdviserIdsByStudent($conn, $searchStudentIds, $eligibleAdviserIds);
        foreach ($results as &$result) {
            $studentId = (int)($result['id'] ?? 0);
            $result['interested_adviser_ids'] = $interestLookup[$studentId] ?? [];
        }
        unset($result);
    }

    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['chair_assign_advisee'] ?? '') === '1') {
    if (!$adviserAssignmentEnabled) {
        $chairAssignAlert = ['type' => 'danger', 'message' => 'Advisor tracking columns are missing in the users table.'];
    } else {
        $studentIdInput = $_POST['chair_assign_students'] ?? [];
        if (!is_array($studentIdInput)) {
            $studentIdInput = [$studentIdInput];
        }
        $selectedStudentIds = array_values(array_unique(array_filter(
            array_map(static fn($value) => (int)$value, $studentIdInput),
            static fn($id) => $id > 0
        )));
        $chairQuickLookupValue = trim((string)($_POST['chair_assign_student_lookup'] ?? ''));
        $quickLookupSuccessNote = '';
        $quickLookupError = '';

        if ($chairQuickLookupValue !== '') {
            $quickLookupRow = null;
            $quickLookupSql = "
                SELECT u.id,
                       CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS full_name
                FROM users u
                WHERE u.role = 'student'
                  AND {$unassignedClause}
                  AND (
                        u.student_id = ?
                        OR u.email = ?
                        OR u.username = ?
                  )
            ";
            if ($studentScopeCondition !== '') {
                $quickLookupSql .= " AND {$studentScopeCondition}";
            }
            $quickLookupSql .= " LIMIT 1";
            $quickLookupStmt = $conn->prepare($quickLookupSql);
            if ($quickLookupStmt) {
                $quickLookupStmt->bind_param('sss', $chairQuickLookupValue, $chairQuickLookupValue, $chairQuickLookupValue);
                if ($quickLookupStmt->execute()) {
                    $quickLookupResult = $quickLookupStmt->get_result();
                    $quickLookupRow = $quickLookupResult ? $quickLookupResult->fetch_assoc() : null;
                    if ($quickLookupResult) {
                        $quickLookupResult->free();
                    }
                } else {
                    $quickLookupError = 'Unable to process the quick entry lookup right now. Please try again.';
                }
                $quickLookupStmt->close();
            } else {
                $quickLookupError = 'Unable to prepare the quick entry lookup.';
            }

            if ($quickLookupRow) {
                $quickLookupStudentId = (int)($quickLookupRow['id'] ?? 0);
                if ($quickLookupStudentId > 0) {
                    $selectedStudentIds[] = $quickLookupStudentId;
                    $quickLookupStudentName = trim((string)($quickLookupRow['full_name'] ?? ''));
                    if ($quickLookupStudentName === '') {
                        $quickLookupStudentName = 'Student #' . $quickLookupStudentId;
                    }
                    $quickLookupSuccessNote = $quickLookupStudentName . ' added via quick entry.';
                    $quickLookupError = '';
                }
            } elseif ($quickLookupError === '') {
                $quickLookupError = sprintf(
                    'The quick entry "%s" could not be matched to an unassigned student within your scope.',
                    $chairQuickLookupValue
                );
            }
        }

        $selectedStudentIds = array_values(array_unique($selectedStudentIds));

        if (empty($selectedStudentIds)) {
            if ($chairQuickLookupValue !== '' && $quickLookupError !== '') {
                $chairAssignAlert = ['type' => 'warning', 'message' => $quickLookupError];
            } else {
                $chairAssignAlert = ['type' => 'warning', 'message' => 'Select at least one student to link.'];
            }
        } else {
            $selectionInput = $_POST['chair_assign_adviser_for'] ?? [];
            if (!is_array($selectionInput)) {
                $selectionInput = [];
            }
            $adviserSelections = [];
            foreach ($selectionInput as $studentId => $adviserId) {
                $sid = (int)$studentId;
                $aid = (int)$adviserId;
                if ($sid > 0 && $aid > 0) {
                    $adviserSelections[$sid] = $aid;
                }
            }

            $interestLookup = fetchInterestedAdviserIdsByStudent($conn, $selectedStudentIds, $eligibleAdviserIds);
            $missingSelections = [];
            $invalidSelections = [];
            $assignmentsByAdviser = [];
            foreach ($selectedStudentIds as $studentId) {
                $adviserId = (int)($adviserSelections[$studentId] ?? 0);
                if ($adviserId <= 0) {
                    $interested = $interestLookup[$studentId] ?? [];
                    if (count($interested) === 1) {
                        $adviserId = (int)$interested[0];
                    }
                }
                if ($adviserId <= 0) {
                    $missingSelections[] = $studentId;
                    continue;
                }
                if (!isset($adviserOptionLookup[$adviserId])) {
                    $invalidSelections[] = $studentId;
                    continue;
                }
                if (!isset($assignmentsByAdviser[$adviserId])) {
                    $assignmentsByAdviser[$adviserId] = [];
                }
                $assignmentsByAdviser[$adviserId][] = $studentId;
            }

            if (!empty($missingSelections)) {
                $chairAssignAlert = [
                    'type' => 'warning',
                    'message' => sprintf(
                        'Select an adviser for %d student%s before submitting.',
                        count($missingSelections),
                        count($missingSelections) === 1 ? '' : 's'
                    ),
                ];
            } elseif (!empty($invalidSelections)) {
                $chairAssignAlert = [
                    'type' => 'warning',
                    'message' => 'Some adviser selections are no longer available. Please reselect advisers for the highlighted students.',
                ];
            } else {
                $updateFields = [];
                $updateTypesBase = '';
                $updateParamCount = 0;
                if ($hasAdviserIdColumn) {
                    $updateFields[] = 'adviser_id = ?';
                    $updateTypesBase .= 'i';
                    $updateParamCount++;
                }
                if ($hasAdvisorIdColumn) {
                    $updateFields[] = 'advisor_id = ?';
                    $updateTypesBase .= 'i';
                    $updateParamCount++;
                }

                if (empty($updateFields)) {
                    $chairAssignAlert = ['type' => 'danger', 'message' => 'Advisor columns are unavailable for updates.'];
                } else {
                    $totalAssigned = 0;
                    $assignedByAdviser = [];
                    $updateFailures = 0;
                    foreach ($assignmentsByAdviser as $adviserId => $studentIds) {
                        $studentIds = array_values(array_unique(array_filter(
                            array_map(static fn($value) => (int)$value, $studentIds),
                            static fn($id) => $id > 0
                        )));
                        if (empty($studentIds)) {
                            continue;
                        }
                        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                        $setClause = implode(', ', $updateFields);
                        $updateSql = "
                            UPDATE users u
                            SET {$setClause}
                            WHERE u.id IN ({$placeholders})
                              AND u.role = 'student'
                              AND {$unassignedClause}
                        ";
                        if ($studentScopeCondition !== '') {
                            $updateSql .= " AND {$studentScopeCondition}";
                        }
                        $updateStmt = $conn->prepare($updateSql);
                        if (!$updateStmt) {
                            $updateFailures++;
                            continue;
                        }
                        $updateTypes = $updateTypesBase . str_repeat('i', count($studentIds));
                        $updateParams = array_merge(array_fill(0, $updateParamCount, $adviserId), $studentIds);
                        $updateStmt->bind_param($updateTypes, ...$updateParams);
                        $assignedCount = 0;
                        if ($updateStmt->execute()) {
                            $assignedCount = (int)$updateStmt->affected_rows;
                        }
                        $updateStmt->close();

                        if ($assignedCount <= 0) {
                            continue;
                        }

                        $adviserName = trim((string)($adviserOptionLookup[$adviserId]['full_name'] ?? '')) ?: 'Adviser';
                        $hadAdviserRole = false;
                        $roleCheckStmt = $conn->prepare("
                            SELECT 1 FROM user_roles
                            WHERE user_id = ? AND role_code = 'adviser'
                            LIMIT 1
                        ");
                        if ($roleCheckStmt) {
                            $roleCheckStmt->bind_param('i', $adviserId);
                            $roleCheckStmt->execute();
                            $roleCheckStmt->store_result();
                            $hadAdviserRole = $roleCheckStmt->num_rows > 0;
                            $roleCheckStmt->close();
                        }
                        ensureUserRoleAssignment($conn, $adviserId, 'adviser');
                        if (!$hadAdviserRole) {
                            $roleConfirmStmt = $conn->prepare("
                                SELECT 1 FROM user_roles
                                WHERE user_id = ? AND role_code = 'adviser'
                                LIMIT 1
                            ");
                            if ($roleConfirmStmt) {
                                $roleConfirmStmt->bind_param('i', $adviserId);
                                $roleConfirmStmt->execute();
                                $roleConfirmStmt->store_result();
                                $roleAssigned = $roleConfirmStmt->num_rows > 0;
                                $roleConfirmStmt->close();
                                if ($roleAssigned) {
                                    notify_user(
                                        $conn,
                                        $adviserId,
                                        'Adviser role assigned',
                                        'You have been granted the Adviser role. Switch to Adviser to view your advisees and advisory chat.',
                                        'adviser.php'
                                    );
                                }
                            }
                        }

                        $assignedStudents = [];
                        $assignedPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
                        $adviserMatch = [];
                        $adviserMatchTypes = '';
                        $adviserMatchParams = [];
                        if ($hasAdviserIdColumn) {
                            $adviserMatch[] = 'u.adviser_id = ?';
                            $adviserMatchTypes .= 'i';
                            $adviserMatchParams[] = $adviserId;
                        }
                        if ($hasAdvisorIdColumn) {
                            $adviserMatch[] = 'u.advisor_id = ?';
                            $adviserMatchTypes .= 'i';
                            $adviserMatchParams[] = $adviserId;
                        }
                        $assignedSql = "
                            SELECT u.id, CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS full_name
                            FROM users u
                            WHERE u.id IN ({$assignedPlaceholders})
                              AND u.role = 'student'
                        ";
                        if (!empty($adviserMatch)) {
                            $assignedSql .= " AND (" . implode(' OR ', $adviserMatch) . ")";
                        }
                        if ($studentScopeCondition !== '') {
                            $assignedSql .= " AND {$studentScopeCondition}";
                        }
                        $assignedStmt = $conn->prepare($assignedSql);
                        if ($assignedStmt) {
                            $assignedTypes = str_repeat('i', count($studentIds)) . $adviserMatchTypes;
                            $assignedParams = array_merge($studentIds, $adviserMatchParams);
                            $assignedStmt->bind_param($assignedTypes, ...$assignedParams);
                            if ($assignedStmt->execute()) {
                                $assignedResult = $assignedStmt->get_result();
                                if ($assignedResult) {
                                    $assignedStudents = $assignedResult->fetch_all(MYSQLI_ASSOC);
                                    $assignedResult->free();
                                }
                            }
                            $assignedStmt->close();
                        }

                        if (!empty($assignedStudents)) {
                            $studentNames = array_map(
                                static fn($student) => trim((string)($student['full_name'] ?? '')),
                                $assignedStudents
                            );
                            $studentNames = array_values(array_filter($studentNames));
                            $studentPreview = '';
                            if (!empty($studentNames)) {
                                $studentPreview = count($studentNames) <= 3
                                    ? implode(', ', $studentNames)
                                    : implode(', ', array_slice($studentNames, 0, 3)) . ' and others';
                            }
                            $adviserMessage = $studentPreview !== ''
                                ? "You have been assigned {$assignedCount} advisee(s): {$studentPreview}."
                                : "You have been assigned {$assignedCount} advisee(s).";
                            notify_user(
                                $conn,
                                $adviserId,
                                'New advisee assignment',
                                $adviserMessage,
                                'adviser.php'
                            );

                            foreach ($assignedStudents as $student) {
                                $studentId = (int)($student['id'] ?? 0);
                                if ($studentId <= 0) {
                                    continue;
                                }
                                notify_user(
                                    $conn,
                                    $studentId,
                                    'Adviser assigned',
                                    "You have been assigned to {$adviserName} as your adviser.",
                                    'student_dashboard.php'
                                );
                            }
                        }

                        $totalAssigned += $assignedCount;
                        $assignedByAdviser[$adviserId] = ($assignedByAdviser[$adviserId] ?? 0) + $assignedCount;
                    }

                    if ($totalAssigned > 0) {
                        $message = sprintf(
                            '%d student%s linked across %d adviser%s.',
                            $totalAssigned,
                            $totalAssigned === 1 ? '' : 's',
                            count($assignedByAdviser),
                            count($assignedByAdviser) === 1 ? '' : 's'
                        );
                        if ($quickLookupSuccessNote !== '') {
                            $message .= ' ' . $quickLookupSuccessNote;
                        }
                        if ($quickLookupError !== '') {
                            $message .= ' Note: ' . $quickLookupError;
                        }
                        if ($updateFailures > 0) {
                            $message .= ' Some adviser updates could not be prepared.';
                        }
                        $chairAssignAlert = [
                            'type' => $quickLookupError === '' ? 'success' : 'warning',
                            'message' => $message,
                        ];
                        if ($quickLookupError === '') {
                            $chairQuickLookupValue = '';
                        }
                    } else {
                        $failureMessage = 'No students were updated. They might already have advisers or fall outside your scope.';
                        if ($quickLookupError !== '') {
                            $failureMessage .= ' ' . $quickLookupError;
                        }
                        $chairAssignAlert = ['type' => 'warning', 'message' => $failureMessage];
                    }
                }
            }
        }
    }
}

if ($adviserAssignmentEnabled) {
    $countSql = "
        SELECT COUNT(*) AS total
        FROM users u
        WHERE u.role = 'student'
          AND {$unassignedClause}
    ";
    if ($studentScopeCondition !== '') {
        $countSql .= " AND {$studentScopeCondition}";
    }
    if ($countResult = $conn->query($countSql)) {
        $countRow = $countResult->fetch_assoc();
        $chairUnassignedTotal = (int)($countRow['total'] ?? 0);
        $countResult->free();
    }

    $listSql = "
        SELECT
            u.id,
            u.student_id,
            u.firstname,
            u.lastname,
            u.program,
            u.year_level,
            u.email
        FROM users u
        WHERE u.role = 'student'
          AND {$unassignedClause}
    ";
    if ($studentScopeCondition !== '') {
        $listSql .= " AND {$studentScopeCondition}";
    }
    $listSql .= " ORDER BY u.lastname, u.firstname LIMIT {$adviserAssignmentLimit}";
    if ($listResult = $conn->query($listSql)) {
        while ($row = $listResult->fetch_assoc()) {
            $chairUnassignedStudents[] = $row;
        }
        $listResult->free();
    }
}

$interestedAdviserIdsByStudent = [];
if ($adviserAssignmentEnabled && !empty($chairUnassignedStudents) && !empty($eligibleAdviserIds)) {
    $studentIds = array_map(static fn($student) => (int)($student['id'] ?? 0), $chairUnassignedStudents);
    $interestedAdviserIdsByStudent = fetchInterestedAdviserIdsByStudent($conn, $studentIds, $eligibleAdviserIds);
}

$adviserCount = count($chairAdviserOptions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assign Students to an Adviser</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
    <style>
        .adviser-required select {
            border-color: #f0ad4e;
            box-shadow: 0 0 0 0.2rem rgba(240, 173, 78, 0.15);
        }
        .adviser-required .adviser-required-note {
            color: #b47a2a;
        }
    </style>
</head>
<body class="bg-light program-chair-layout">
<?php include 'header.php'; ?>
<div class="dashboard-shell">
<?php include 'sidebar.php'; ?>

<main class="content dashboard-content" role="main">
    <div class="container-fluid py-4">
        <div class="mb-4">
            <h1 class="h4 fw-semibold text-success mb-1">Assign Students to an Adviser</h1>
            <p class="text-muted mb-0">Stage unassigned students and link them to an adviser roster in one streamlined workspace.</p>
        </div>

        <?php if ($chairAssignAlert): ?>
            <div class="alert alert-<?= htmlspecialchars($chairAssignAlert['type']); ?> border-0 shadow-sm">
                <?= htmlspecialchars($chairAssignAlert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-lg-4 col-md-6">
                <div class="card shadow-sm border-0 stat-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="icon-pill bg-success-subtle text-success">
                                <i class="bi bi-person-lines-fill"></i>
                            </div>
                            <span class="badge bg-light text-success fw-semibold">Within scope</span>
                        </div>
                        <h6 class="text-uppercase text-muted small mb-1">Students Missing Advisers</h6>
                        <h2 class="fw-bold text-success mb-0"><?= number_format($chairUnassignedTotal); ?></h2>
                        <p class="text-muted small mb-0 mt-2">Limited to the first <?= number_format($adviserAssignmentLimit); ?> results in the table below.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="card shadow-sm border-0 stat-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="icon-pill bg-primary-subtle text-primary">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                            <span class="badge bg-light text-primary fw-semibold">Advisers</span>
                        </div>
                        <h6 class="text-uppercase text-muted small mb-1">Available Advisers</h6>
                        <h2 class="fw-bold text-primary mb-0"><?= number_format($adviserCount); ?></h2>
                        <p class="text-muted small mb-0 mt-2">Only advisers inside your assigned scope appear in the selector.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 stat-card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="icon-pill bg-warning-subtle text-warning">
                                <i class="bi bi-lightning-charge"></i>
                            </div>
                            <span class="badge bg-light text-warning fw-semibold">Quick Tips</span>
                        </div>
                        <h6 class="text-uppercase text-muted small mb-1">Fast Assignment Workflow</h6>
                        <ul class="text-muted small ps-3 mb-0">
                            <li>Stage students via the quick search to mix with checkbox selections.</li>
                            <li>Assignments respect your program, department, or college scope.</li>
                            <li>Use the adviser directory for deeper roster management.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" class="mb-4" id="chairAssignForm">
            <input type="hidden" name="chair_assign_advisee" value="1">
            <div class="dashboard-split dashboard-split--wide">
                <section class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Students Without Advisers</h2>
                            <p class="text-muted small mb-0">Students within your scope that do not have adviser assignments yet.</p>
                        </div>
                        <?php if ($chairUnassignedTotal > 0): ?>
                            <span class="badge bg-light text-success"><?= number_format($chairUnassignedTotal); ?> total</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body pt-0">
                        <?php if (!$adviserAssignmentEnabled): ?>
                            <div class="alert alert-warning mb-0">
                                Adviser assignment is unavailable because the <code>users</code> table is missing the
                                <code>adviser_id</code> or <code>advisor_id</code> column. Please add these columns to continue.
                            </div>
                        <?php elseif (empty($chairUnassignedStudents)): ?>
                            <div class="empty-state text-center py-4">
                                <i class="bi bi-mortarboard display-5 text-success mb-3"></i>
                                <p class="mb-0 text-muted">All students currently have advisers assigned. Great job!</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <small class="text-muted">
                                    Showing <?= number_format(min($chairUnassignedTotal, $adviserAssignmentLimit)); ?> of
                                    <?= number_format($chairUnassignedTotal); ?> students without advisers.
                                </small>
                                <div class="text-muted small">
                                    Select students and confirm an adviser per row.
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col" style="width: 55px;">
                                                <input
                                                    type="checkbox"
                                                    class="form-check-input"
                                                    id="chairAssignSelectAllToggle"
                                                    <?= !$adviserAssignmentEnabled ? 'disabled' : ''; ?>
                                                >
                                            </th>
                                            <th scope="col">Student</th>
                                            <th scope="col">Interested Adviser</th>
                                            <th scope="col">Program</th>
                                            <th scope="col">Year Level</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($chairUnassignedStudents as $student): ?>
                                            <?php
                                                $studentId = (int)($student['id'] ?? 0);
                                                $fullName = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
                                                $fullName = $fullName !== '' ? $fullName : 'Student #' . $studentId;
                                                $studentNumber = trim((string)($student['student_id'] ?? ''));
                                                $programLabel = trim((string)($student['program'] ?? '')) ?: 'Not specified';
                                                $yearLevel = trim((string)($student['year_level'] ?? '')) ?: 'N/A';
                                                $email = trim((string)($student['email'] ?? ''));
                                                $interestedAdviserIds = $interestedAdviserIdsByStudent[$studentId] ?? [];
                                                $interestedCount = count($interestedAdviserIds);
                                                $autoAdviserId = $interestedCount === 1 ? (int)($interestedAdviserIds[0] ?? 0) : 0;
                                                $adviserOptionsForStudent = [];
                                                if ($interestedCount > 0) {
                                                    foreach ($interestedAdviserIds as $adviserId) {
                                                        $adviserId = (int)$adviserId;
                                                        if (isset($adviserOptionLookup[$adviserId])) {
                                                            $adviserOptionsForStudent[] = $adviserOptionLookup[$adviserId];
                                                        }
                                                    }
                                                } else {
                                                    $adviserOptionsForStudent = $chairAdviserOptions;
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <input
                                                        class="form-check-input chair-assign-checkbox"
                                                        type="checkbox"
                                                        name="chair_assign_students[]"
                                                        value="<?= $studentId; ?>"
                                                        <?= !$adviserAssignmentEnabled ? 'disabled' : ''; ?>
                                                    >
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-success"><?= htmlspecialchars($fullName); ?></div>
                                                    <small class="text-muted d-block">
                                                        <?= $studentNumber !== '' ? 'ID: ' . htmlspecialchars($studentNumber) : 'ID not set'; ?>
                                                    </small>
                                                    <?php if ($email !== ''): ?>
                                                        <small class="text-muted d-block"><?= htmlspecialchars($email); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="adviser-select-cell">
                                                    <select
                                                        class="form-select form-select-sm"
                                                        name="chair_assign_adviser_for[<?= $studentId; ?>]"
                                                        <?= (!$adviserAssignmentEnabled || $adviserCount === 0) ? 'disabled' : ''; ?>
                                                    >
                                                        <option value="">-- Select adviser --</option>
                                                        <?php foreach ($adviserOptionsForStudent as $adviserOption): ?>
                                                            <?php
                                                                $adviserId = (int)($adviserOption['id'] ?? 0);
                                                                $adviserName = trim((string)($adviserOption['full_name'] ?? 'Adviser'));
                                                                $assignmentContext = trim((string)($adviserOption['program'] ?? ''));
                                                                if ($assignmentContext === '') {
                                                                    $assignmentContext = trim((string)($adviserOption['department'] ?? ''));
                                                                }
                                                                $label = $adviserName;
                                                                if ($assignmentContext !== '') {
                                                                    $label .= ' | ' . $assignmentContext;
                                                                }
                                                            ?>
                                                            <option value="<?= $adviserId; ?>" <?= $autoAdviserId === $adviserId ? 'selected' : ''; ?>>
                                                                <?= htmlspecialchars($label); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ($interestedCount === 1): ?>
                                                        <small class="text-muted d-block">Auto-selected (only interested faculty).</small>
                                                    <?php elseif ($interestedCount > 1): ?>
                                                        <small class="text-muted d-block"><?= htmlspecialchars((string)$interestedCount); ?> interested faculty.</small>
                                                    <?php else: ?>
                                                        <small class="text-muted d-block">No interested faculty yet.</small>
                                                    <?php endif; ?>
                                                    <small class="adviser-required-note d-none">Please choose an adviser.</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($programLabel); ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($yearLevel); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($chairUnassignedTotal > $adviserAssignmentLimit): ?>
                                <p class="text-muted small mt-3 mb-0">
                                    Showing the first <?= number_format($adviserAssignmentLimit); ?> students. Apply filters in the student directory for a detailed view.
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0">
                        <h2 class="h6 fw-semibold mb-1">Assign Students to an Adviser</h2>
                        <p class="text-muted small mb-0">Link selected students to an adviser roster instantly.</p>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small">
                            Select advisers per student in the table. When only one faculty is interested, the adviser is auto-selected.
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small">Quick Add Student</label>
                            <div class="position-relative">
                                <div class="input-group">
                                    <span class="input-group-text bg-success-subtle text-success">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="chairAssignQuickSearch"
                                        name="chair_assign_student_lookup"
                                        value="<?= htmlspecialchars($chairQuickLookupValue); ?>"
                                        placeholder="Search students without advisers..."
                                        autocomplete="off"
                                        <?= !$adviserAssignmentEnabled ? 'disabled' : ''; ?>
                                    >
                                </div>
                                <div
                                    id="chairAssignQuickResults"
                                    class="list-group shadow-sm position-absolute w-100 d-none"
                                    style="z-index: 1050;"
                                ></div>
                            </div>
                            <div class="form-text">
                                Type at least two characters to find students across your scope who do not yet have advisers. Click a result to stage it for assignment.
                            </div>
                        </div>
                        <div class="mb-3 d-none" id="chairAssignQuickSelectedWrapper">
                            <label class="form-label text-muted small">Quick Added Students</label>
                            <div class="d-flex flex-wrap gap-2" id="chairAssignQuickSelectedList"></div>
                        </div>
                        <div id="chairAssignQuickHiddenInputs"></div>
                        <div class="mb-3">
                            <label class="form-label text-muted small">Students Selected</label>
                            <div class="p-3 bg-success-subtle rounded text-success fw-semibold">
                                <span id="chairAssignSelectedCount">0</span> student(s) ready for assignment
                            </div>
                        </div>
                        <div class="alert alert-info small">
                            Selected students will immediately appear in the chosen adviser's advisee list.
                        </div>
                        <div class="d-flex gap-2">
                            <button
                                type="submit"
                                class="btn btn-success flex-fill"
                                <?= (!$adviserAssignmentEnabled || empty($chairAdviserOptions)) ? 'disabled' : ''; ?>
                            >
                                <i class="bi bi-link-45deg me-1"></i> Assign Selected
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-secondary flex-fill"
                                id="chairAssignClearSelection"
                                <?= !$adviserAssignmentEnabled ? 'disabled' : ''; ?>
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </form>
    </div>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php
    $adviserOptionsPayload = array_map(static function ($option) {
        $adviserId = (int)($option['id'] ?? 0);
        $adviserName = trim((string)($option['full_name'] ?? 'Adviser'));
        $assignmentContext = trim((string)($option['program'] ?? ''));
        if ($assignmentContext === '') {
            $assignmentContext = trim((string)($option['department'] ?? ''));
        }
        $label = $adviserName;
        if ($assignmentContext !== '') {
            $label .= ' | ' . $assignmentContext;
        }
        return ['id' => $adviserId, 'label' => $label];
    }, $chairAdviserOptions);
?>
<script>
(function() {
    const chairAssignForm = document.getElementById('chairAssignForm');
    if (!chairAssignForm) {
        return;
    }
    const selectAllToggle = document.getElementById('chairAssignSelectAllToggle');
    const selectedCountElement = document.getElementById('chairAssignSelectedCount');
    const clearSelectionButton = document.getElementById('chairAssignClearSelection');
    const quickSearchInput = document.getElementById('chairAssignQuickSearch');
    const quickResultsContainer = document.getElementById('chairAssignQuickResults');
    const quickSelectedWrapper = document.getElementById('chairAssignQuickSelectedWrapper');
    const quickSelectedList = document.getElementById('chairAssignQuickSelectedList');
    const quickHiddenInputs = document.getElementById('chairAssignQuickHiddenInputs');
    const adviserOptions = <?php echo json_encode($adviserOptionsPayload ?? []); ?>;
    const adviserOptionsById = new Map(adviserOptions.map((option) => [option.id.toString(), option]));
    const QUICK_MIN_CHARACTERS = 2;
    let quickSearchTimer = null;
    let quickSearchAbortController = null;

    const getCheckboxes = () => Array.from(chairAssignForm.querySelectorAll('.chair-assign-checkbox:not(:disabled)'));
    const getQuickSelectedInputs = () => quickHiddenInputs ? Array.from(quickHiddenInputs.querySelectorAll('[data-quick-selected="1"]')) : [];

    const updateSelectedCount = () => {
        if (!selectedCountElement) {
            return;
        }
        const selectedTotal = chairAssignForm.querySelectorAll('.chair-assign-checkbox:checked').length + getQuickSelectedInputs().length;
        selectedCountElement.textContent = selectedTotal.toString();
    };

    const updateRowAdviserRequirement = (row) => {
        if (!row) {
            return;
        }
        const checkbox = row.querySelector('.chair-assign-checkbox');
        const select = row.querySelector('select[name^="chair_assign_adviser_for"]');
        const note = row.querySelector('.adviser-required-note');
        if (!checkbox || !select || !note) {
            return;
        }
        const needsSelection = checkbox.checked && !select.value;
        row.classList.toggle('adviser-required', needsSelection);
        note.classList.toggle('d-none', !needsSelection);
    };

    const updateAllRowAdviserRequirements = () => {
        chairAssignForm.querySelectorAll('tbody tr').forEach((row) => {
            updateRowAdviserRequirement(row);
        });
    };

    const getQuickSelectedValues = () => {
        const selections = {};
        if (!quickSelectedList) {
            return selections;
        }
        quickSelectedList.querySelectorAll('select[data-quick-select]').forEach((select) => {
            const studentId = select.getAttribute('data-quick-select');
            if (studentId) {
                selections[studentId] = select.value;
            }
        });
        return selections;
    };

    const renderQuickSelectedList = () => {
        if (!quickSelectedWrapper || !quickSelectedList) {
            return;
        }
        const previousSelections = getQuickSelectedValues();
        const selected = getQuickSelectedInputs();
        quickSelectedList.innerHTML = '';
        if (selected.length === 0) {
            quickSelectedWrapper.classList.add('d-none');
            return;
        }
        quickSelectedWrapper.classList.remove('d-none');
        selected.forEach((input) => {
            const studentId = input.value;
            const wrapper = document.createElement('div');
            wrapper.className = 'border rounded p-2 d-flex flex-column gap-2 bg-white';
            wrapper.setAttribute('data-quick-pill', studentId);
            const nameText = input.getAttribute('data-quick-name') || `Student #${studentId}`;
            const metaText = input.getAttribute('data-quick-meta') || '';
            const header = document.createElement('div');
            header.className = 'd-flex align-items-start justify-content-between gap-2';
            const label = document.createElement('div');
            label.className = 'fw-semibold text-success';
            label.textContent = nameText;
            header.appendChild(label);
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-link text-success p-0';
            removeBtn.setAttribute('data-quick-remove', studentId);
            removeBtn.setAttribute('aria-label', 'Remove student');
            removeBtn.innerHTML = '<i class="bi bi-x-circle"></i>';
            header.appendChild(removeBtn);
            wrapper.appendChild(header);
            if (metaText) {
                const meta = document.createElement('small');
                meta.className = 'text-muted text-lowercase';
                meta.textContent = metaText;
                wrapper.appendChild(meta);
            }

            let interestedIds = [];
            const interestedRaw = input.getAttribute('data-quick-interested') || '[]';
            try {
                const parsed = JSON.parse(interestedRaw);
                if (Array.isArray(parsed)) {
                    interestedIds = parsed.map((value) => parseInt(value, 10)).filter((value) => Number.isFinite(value));
                }
            } catch (error) {
                interestedIds = [];
            }
            const availableOptions = interestedIds.length > 0
                ? interestedIds.map((id) => adviserOptionsById.get(id.toString())).filter(Boolean)
                : adviserOptions;
            const select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            select.name = `chair_assign_adviser_for[${studentId}]`;
            select.setAttribute('data-quick-select', studentId);
            if (!adviserOptions.length) {
                select.disabled = true;
            }
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- Select adviser --';
            select.appendChild(placeholder);
            availableOptions.forEach((option) => {
                const opt = document.createElement('option');
                opt.value = option.id;
                opt.textContent = option.label;
                select.appendChild(opt);
            });
            const previous = previousSelections[studentId] || '';
            if (previous) {
                select.value = previous;
            } else if (interestedIds.length === 1) {
                select.value = interestedIds[0].toString();
            }
            wrapper.appendChild(select);

            const note = document.createElement('small');
            note.className = 'text-muted';
            if (interestedIds.length === 1) {
                note.textContent = 'Auto-selected (only interested faculty).';
            } else if (interestedIds.length > 1) {
                note.textContent = `${interestedIds.length} interested faculty.`;
            } else {
                note.textContent = 'No interested faculty yet.';
            }
            wrapper.appendChild(note);

            const requiredNote = document.createElement('small');
            requiredNote.className = 'adviser-required-note d-none';
            requiredNote.textContent = 'Please choose an adviser.';
            wrapper.appendChild(requiredNote);

            quickSelectedList.appendChild(wrapper);
        });

        quickSelectedList.querySelectorAll('select[data-quick-select]').forEach((select) => {
            const note = select.parentElement?.querySelector('.adviser-required-note');
            if (note) {
                const needsSelection = !select.value;
                note.classList.toggle('d-none', !needsSelection);
            }
        });
    };

    const removeQuickSelection = (studentId) => {
        if (!studentId || !quickHiddenInputs) {
            return;
        }
        const targetInput = quickHiddenInputs.querySelector(`[data-quick-input="${studentId}"]`);
        if (targetInput) {
            targetInput.remove();
            renderQuickSelectedList();
            updateSelectedCount();
        }
    };

    const addQuickSelection = (student) => {
        if (!student || !quickHiddenInputs) {
            return;
        }
        const numericId = parseInt(student.id, 10);
        if (Number.isNaN(numericId) || numericId <= 0) {
            return;
        }
        const studentId = numericId.toString();
        if (quickHiddenInputs.querySelector(`[data-quick-input="${studentId}"]`)) {
            return;
        }
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'chair_assign_students[]';
        hiddenInput.value = studentId;
        hiddenInput.dataset.quickSelected = '1';
        hiddenInput.setAttribute('data-quick-input', studentId);
        hiddenInput.setAttribute('data-quick-name', student.name || '');
        hiddenInput.setAttribute('data-quick-meta', student.meta || '');
        const interestedIds = Array.isArray(student.interested_adviser_ids) ? student.interested_adviser_ids : [];
        hiddenInput.setAttribute('data-quick-interested', JSON.stringify(interestedIds));
        quickHiddenInputs.appendChild(hiddenInput);
        renderQuickSelectedList();
        updateSelectedCount();
    };

    const hideQuickResults = () => {
        if (!quickResultsContainer) {
            return;
        }
        quickResultsContainer.classList.add('d-none');
        quickResultsContainer.innerHTML = '';
    };

    const showQuickMessage = (message) => {
        if (!quickResultsContainer) {
            return;
        }
        quickResultsContainer.innerHTML = `<div class="list-group-item text-muted small">${message}</div>`;
        quickResultsContainer.classList.remove('d-none');
    };

    const renderQuickResults = (items) => {
        if (!quickResultsContainer) {
            return;
        }
        quickResultsContainer.innerHTML = '';
        if (!items || items.length === 0) {
            showQuickMessage('No unassigned students found for this search.');
            return;
        }
        quickResultsContainer.classList.remove('d-none');
        items.forEach((item) => {
            const entry = document.createElement('button');
            entry.type = 'button';
            entry.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-start';
            entry.setAttribute('data-result-id', item.id);
            entry.setAttribute('data-result-name', item.name || '');
            entry.setAttribute('data-result-interested', JSON.stringify(item.interested_adviser_ids || []));
            const metaParts = [];
            if (item.student_id) {
                metaParts.push(`ID: ${item.student_id}`);
            }
            if (item.program) {
                metaParts.push(item.program);
            }
            if (item.year_level) {
                metaParts.push(item.year_level);
            }
            if (item.email) {
                metaParts.push(item.email);
            }
            const metaText = metaParts.join(' | ');
            entry.setAttribute('data-result-meta', metaText);
            entry.innerHTML = `
                <div>
                    <div class="fw-semibold">${item.name || 'Student'}</div>
                    <div class="small text-muted">${metaText || 'No additional details available'}</div>
                </div>
                <i class="bi bi-plus-circle text-success"></i>
            `;
            quickResultsContainer.appendChild(entry);
        });
    };

    const fetchQuickResults = (query) => {
        if (!quickResultsContainer) {
            return;
        }
        if (quickSearchAbortController) {
            quickSearchAbortController.abort();
        }
        quickSearchAbortController = new AbortController();
        showQuickMessage('Searching...');
        fetch(`assign_adviser.php?chair_assign_search=1&q=${encodeURIComponent(query)}`, {
            signal: quickSearchAbortController.signal
        })
            .then((response) => response.json())
            .then((data) => {
                if (!data.success) {
                    showQuickMessage(data.error || 'Unable to search for students right now.');
                    return;
                }
                renderQuickResults(data.results || []);
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }
                showQuickMessage('Unable to search for students right now.');
            });
    };

    const syncSelectAllToggle = () => {
        if (!selectAllToggle) {
            return;
        }
        const checkboxes = getCheckboxes();
        if (checkboxes.length === 0) {
            selectAllToggle.checked = false;
            selectAllToggle.indeterminate = false;
            return;
        }
        const checkedCount = checkboxes.filter((box) => box.checked).length;
        selectAllToggle.checked = checkedCount > 0 && checkedCount === checkboxes.length;
        selectAllToggle.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
    };

    chairAssignForm.addEventListener('change', (event) => {
        if (event.target.classList.contains('chair-assign-checkbox')) {
            updateSelectedCount();
            syncSelectAllToggle();
            updateRowAdviserRequirement(event.target.closest('tr'));
            return;
        }
        if (event.target.matches('select[name^="chair_assign_adviser_for"]')) {
            updateRowAdviserRequirement(event.target.closest('tr'));
        }
    });

    if (selectAllToggle) {
        selectAllToggle.addEventListener('change', () => {
            const checkboxes = getCheckboxes();
            checkboxes.forEach((checkbox) => {
                checkbox.checked = selectAllToggle.checked;
                updateRowAdviserRequirement(checkbox.closest('tr'));
            });
            updateSelectedCount();
            syncSelectAllToggle();
        });
    }

    if (clearSelectionButton) {
        clearSelectionButton.addEventListener('click', () => {
            getCheckboxes().forEach((checkbox) => {
                checkbox.checked = false;
                updateRowAdviserRequirement(checkbox.closest('tr'));
            });
            getQuickSelectedInputs().forEach((input) => input.remove());
            renderQuickSelectedList();
            if (selectAllToggle) {
                selectAllToggle.checked = false;
                selectAllToggle.indeterminate = false;
            }
            updateSelectedCount();
        });
    }

    updateSelectedCount();
    syncSelectAllToggle();
    renderQuickSelectedList();
    updateAllRowAdviserRequirements();

    if (quickSearchInput) {
        quickSearchInput.addEventListener('input', () => {
            const query = quickSearchInput.value.trim();
            if (query.length < QUICK_MIN_CHARACTERS) {
                if (quickSearchAbortController) {
                    quickSearchAbortController.abort();
                }
                hideQuickResults();
                return;
            }
            if (quickSearchTimer) {
                clearTimeout(quickSearchTimer);
            }
            quickSearchTimer = setTimeout(() => {
                fetchQuickResults(query);
            }, 250);
        });
    }

    if (quickResultsContainer) {
        quickResultsContainer.addEventListener('click', (event) => {
            const target = event.target.closest('[data-result-id]');
            if (!target) {
                return;
            }
            let interestedIds = [];
            const interestedRaw = target.getAttribute('data-result-interested') || '[]';
            try {
                const parsed = JSON.parse(interestedRaw);
                if (Array.isArray(parsed)) {
                    interestedIds = parsed;
                }
            } catch (error) {
                interestedIds = [];
            }
            addQuickSelection({
                id: target.getAttribute('data-result-id'),
                name: target.getAttribute('data-result-name') || '',
                meta: target.getAttribute('data-result-meta') || '',
                interested_adviser_ids: interestedIds
            });
            hideQuickResults();
            if (quickSearchInput) {
                quickSearchInput.value = '';
                quickSearchInput.focus();
            }
        });
    }

    if (quickSelectedList) {
        quickSelectedList.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-quick-remove]');
            if (!removeButton) {
                return;
            }
            removeQuickSelection(removeButton.getAttribute('data-quick-remove'));
        });
        quickSelectedList.addEventListener('change', (event) => {
            const target = event.target;
            if (!target.matches('select[data-quick-select]')) {
                return;
            }
            const wrapper = target.closest('[data-quick-pill]');
            const note = wrapper ? wrapper.querySelector('.adviser-required-note') : null;
            if (note) {
                const needsSelection = !target.value;
                note.classList.toggle('d-none', !needsSelection);
            }
        });
    }

    document.addEventListener('click', (event) => {
        if (!quickResultsContainer || !quickSearchInput) {
            return;
        }
        if (quickResultsContainer.contains(event.target) || quickSearchInput.contains(event.target)) {
            return;
        }
        hideQuickResults();
    });

})();
</script>
</body>
</html>
