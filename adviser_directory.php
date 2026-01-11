<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'adviser') {
    header('Location: login.php');
    exit;
}

if (!function_exists('adviserUsersColumnExists')) {
    function adviserUsersColumnExists(mysqli $conn, string $column): bool
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

if (!function_exists('buildAdvisorWhereClause')) {
    function buildAdvisorWhereClause(string $alias, array $columns): string
    {
        $parts = array_map(fn($column) => "{$alias}.{$column} = ?", $columns);
        return '(' . implode(' OR ', $parts) . ')';
    }
}

if (!function_exists('buildAdvisorUnassignedClause')) {
    function buildAdvisorUnassignedClause(string $alias, array $columns): string
    {
        if (empty($columns)) {
            return '1=1';
        }
        $parts = array_map(
            fn($column) => "({$alias}.{$column} IS NULL OR {$alias}.{$column} = 0)",
            $columns
        );
        return '(' . implode(' AND ', $parts) . ')';
    }
}

if (!function_exists('adviserBindParams')) {
    function adviserBindParams(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '' || empty($params)) {
            return true;
        }
        $bindParams = [$types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        return call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }
}

if (!function_exists('formatDefenseSchedule')) {
    function formatDefenseSchedule(?string $date, ?string $time): string
    {
        $date = $date ? trim($date) : '';
        $time = $time ? trim($time) : '';
        if ($date === '' && $time === '') {
            return 'Schedule to be announced';
        }
        $timestamp = trim($date . ' ' . $time);
        $parsed = strtotime($timestamp);
        if ($parsed) {
            return date('F d, Y | g:i A', $parsed);
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
        return 'Schedule to be announced';
    }
}

function deriveProgressStatus(array $student): string
{
    $defenseStatus = trim((string)($student['defense_status'] ?? ''));
    $submissionStatus = trim((string)($student['submission_status'] ?? ''));
    $hasTitle = trim((string)($student['research_title'] ?? '')) !== '';

    if ($defenseStatus !== '') {
        return match (strtolower($defenseStatus)) {
            'confirmed', 'scheduled' => 'Defense Scheduled',
            'completed' => 'Completed',
            default => 'Under Review',
        };
    }

    if ($submissionStatus !== '') {
        $statusLower = strtolower($submissionStatus);
        if (str_contains($statusLower, 'complete')) {
            return 'Completed';
        }
        if (str_contains($statusLower, 'review') || str_contains($statusLower, 'pending')) {
            return 'Under Review';
        }
    }

    if ($hasTitle) {
        return 'Proposal Stage';
    }

    return 'Awaiting Proposal';
}

$advisorId = (int)($_SESSION['user_id'] ?? 0);
$advisorName = trim(
    ($_SESSION['first_name'] ?? $_SESSION['firstname'] ?? '') . ' ' .
    ($_SESSION['last_name'] ?? $_SESSION['lastname'] ?? '')
) ?: 'Adviser';

$hasAdviserIdColumn = adviserUsersColumnExists($conn, 'adviser_id');
$hasAdvisorIdColumn = adviserUsersColumnExists($conn, 'advisor_id');
$advisorColumns = [];

if ($hasAdviserIdColumn) {
    $advisorColumns[] = 'adviser_id';
}
if ($hasAdvisorIdColumn) {
    $advisorColumns[] = 'advisor_id';
}

if (empty($advisorColumns)) {
    die('Advisor tracking columns are missing in the users table.');
}

$advisorWhere = buildAdvisorWhereClause('u', $advisorColumns);
$advisorParamTypes = str_repeat('i', count($advisorColumns));
$advisorParams = array_fill(0, count($advisorColumns), $advisorId);
$unassignedClause = buildAdvisorUnassignedClause('u', $advisorColumns);

$assignSuccess = '';
$assignError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_advisee'])) {
    $selectedStudentId = (int)($_POST['student_id'] ?? 0);
    if ($selectedStudentId <= 0) {
        $assignError = 'Please choose a student before linking.';
    } else {
        $checkSql = "
            SELECT u.id
            FROM users u
            WHERE u.id = ? AND u.role = 'student' AND {$unassignedClause}
            LIMIT 1
        ";
        $checkStmt = $conn->prepare($checkSql);
        if ($checkStmt) {
            $checkStmt->bind_param('i', $selectedStudentId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $studentExists = $checkResult && $checkResult->num_rows > 0;
            $checkStmt->close();
            if ($studentExists) {
                $updateFields = [];
                $updateTypes = '';
                $updateParams = [];
                if ($hasAdviserIdColumn) {
                    $updateFields[] = 'adviser_id = ?';
                    $updateTypes .= 'i';
                    $updateParams[] = $advisorId;
                }
                if ($hasAdvisorIdColumn) {
                    $updateFields[] = 'advisor_id = ?';
                    $updateTypes .= 'i';
                    $updateParams[] = $advisorId;
                }
                $updateTypes .= 'i';
                $updateParams[] = $selectedStudentId;

                $updateSql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                if ($updateStmt) {
                    $updateStmt->bind_param($updateTypes, ...$updateParams);
                    if ($updateStmt->execute()) {
                        $assignSuccess = 'Student successfully linked to your advisee list.';
                    } else {
                        $assignError = 'Unable to link the student right now. Please try again.';
                    }
                    $updateStmt->close();
                } else {
                    $assignError = 'Failed to prepare the assignment request.';
                }
            } else {
                $assignError = 'The selected student is no longer available for assignment.';
            }
        } else {
            $assignError = 'Unable to verify the student record.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$programFilter = trim($_GET['program'] ?? '');

$hasProgramColumn = adviserUsersColumnExists($conn, 'program');

$conditions = ["u.role = 'student'", $advisorWhere];
$types = $advisorParamTypes;
$params = $advisorParams;

if ($search !== '') {
    $conditions[] = "(CONCAT_WS(' ', u.firstname, u.lastname) LIKE ? OR u.email LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$programOptions = [];
if ($hasProgramColumn) {
    $optionsSql = "
        SELECT DISTINCT u.program
        FROM users u
        WHERE u.role = 'student' AND u.program IS NOT NULL AND u.program <> '' AND {$advisorWhere}
        ORDER BY u.program ASC
    ";
    $programStmt = $conn->prepare($optionsSql);
    if ($programStmt) {
        adviserBindParams($programStmt, $advisorParamTypes, $advisorParams);
        $programStmt->execute();
        $result = $programStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $programOptions[] = $row['program'];
        }
        $programStmt->close();
    }

    if ($programFilter !== '' && in_array($programFilter, $programOptions, true)) {
        $conditions[] = "u.program = ?";
        $types .= 's';
        $params[] = $programFilter;
    } else {
        $programFilter = '';
    }
} else {
    $programFilter = '';
}

$selectFields = [
    "u.id",
    "u.firstname",
    "u.lastname",
    "u.email",
    "u.contact",
    $hasProgramColumn ? "u.program" : "NULL AS program",
    "(SELECT title FROM concept_papers cp WHERE cp.student_id = u.id ORDER BY cp.created_at DESC LIMIT 1) AS research_title",
    "(SELECT status FROM submissions sub WHERE sub.student_id = u.id ORDER BY sub.created_at DESC LIMIT 1) AS submission_status",
    "(SELECT defense_date FROM defense_schedules ds WHERE ds.student_id = u.id ORDER BY ds.defense_date IS NULL, ds.defense_date ASC, ds.id DESC LIMIT 1) AS defense_date",
    "(SELECT defense_time FROM defense_schedules ds WHERE ds.student_id = u.id ORDER BY ds.defense_date IS NULL, ds.defense_date ASC, ds.id DESC LIMIT 1) AS defense_time",
    "(SELECT status FROM defense_schedules ds WHERE ds.student_id = u.id ORDER BY ds.defense_date IS NULL, ds.defense_date ASC, ds.id DESC LIMIT 1) AS defense_status"
];

$sql = "
    SELECT " . implode(', ', $selectFields) . "
    FROM users u
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY u.lastname ASC, u.firstname ASC
";

$students = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    adviserBindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}

$unassignedStudents = [];
$unassignedSql = "
    SELECT u.id,
           CONCAT(u.firstname, ' ', u.lastname) AS full_name,
           u.email,
           " . ($hasProgramColumn ? "COALESCE(u.program, 'Undeclared')" : "'Unspecified Program'") . " AS program
    FROM users u
    WHERE u.role = 'student' AND {$unassignedClause}
    ORDER BY u.lastname ASC, u.firstname ASC
    LIMIT 50
";
$unassignedResult = $conn->query($unassignedSql);
if ($unassignedResult) {
    while ($row = $unassignedResult->fetch_assoc()) {
        $unassignedStudents[] = $row;
    }
    $unassignedResult->free();
}

$statusOptions = ['Awaiting Proposal', 'Proposal Stage', 'Under Review', 'Defense Scheduled', 'Completed'];
$filteredStudents = [];
$stats = [
    'total' => 0,
    'upcoming' => 0,
    'activeTitles' => 0,
    'awaitingTopics' => 0,
];
$today = new DateTimeImmutable('today');

foreach ($students as $student) {
    $progressStatus = deriveProgressStatus($student);
    if ($statusFilter !== '' && $statusFilter !== 'all' && strcasecmp($statusFilter, $progressStatus) !== 0) {
        continue;
    }

    $defenseDate = $student['defense_date'] ?? null;
    $defenseTime = $student['defense_time'] ?? null;
    $defenseLabel = formatDefenseSchedule($defenseDate, $defenseTime);

    $hasTitle = trim((string)($student['research_title'] ?? '')) !== '';
    if ($hasTitle) {
        $stats['activeTitles']++;
    } else {
        $stats['awaitingTopics']++;
    }

    $scheduleDateTime = null;
    if (!empty($defenseDate) || !empty($defenseTime)) {
        $timestamp = trim(($defenseDate ?? '') . ' ' . ($defenseTime ?? ''));
        $parsed = strtotime($timestamp);
        if ($parsed) {
            $scheduleDateTime = new DateTimeImmutable('@' . $parsed);
        }
    }

    if ($scheduleDateTime && $scheduleDateTime >= $today) {
        $stats['upcoming']++;
    }

    $student['progress_status'] = $progressStatus;
    $student['defense_schedule_label'] = $defenseLabel;
    $filteredStudents[] = $student;
}

$stats['total'] = count($filteredStudents);

$heroSummary = [
    'Assigned Students' => $stats['total'],
    'Upcoming Defenses' => $stats['upcoming'],
    'Active Research Titles' => $stats['activeTitles'],
    'Awaiting Topics' => $stats['awaitingTopics'],
];

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Directory - Adviser View</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f5f7fb; }
        .directory-wrapper { margin-left: 220px; min-height: 100vh; padding: 24px; transition: margin-left .3s; }
        #sidebar.collapsed ~ .directory-wrapper { margin-left: 60px; }
        .hero-card { border-radius: 26px; padding: 32px; color: #fff; background: linear-gradient(135deg, #16562c, #0f3d1f); box-shadow: 0 24px 48px rgba(22,86,44,0.25); }
        .hero-card h1 { font-size: 2rem; margin-bottom: .25rem; }
        .hero-card .btn { border-radius: 999px; }
        .stat-card { border: none; border-radius: 18px; background: #fff; padding: 20px; box-shadow: 0 18px 36px rgba(15,61,31,.12); height: 100%; }
        .stat-card small { text-transform: uppercase; letter-spacing: .08em; color: #7f8a9b; }
        .stat-card h3 { font-size: 2rem; margin: .35rem 0; }
        .filter-card, .add-advisee-card { border-radius: 20px; border: none; box-shadow: 0 16px 32px rgba(22,86,44,0.1); }
        .filter-pill { border-radius: 999px; background: #eaf6ee; padding: .25rem .85rem; font-size: .85rem; margin-right: .4rem; display: inline-flex; align-items: center; gap: .35rem; color: #155732; }
        .directory-table thead { background: #f0f3f6; text-transform: uppercase; font-size: .85rem; letter-spacing: .05em; }
        .directory-table td { vertical-align: middle; }
        .status-chip { border-radius: 999px; padding: .35rem .9rem; font-size: .85rem; font-weight: 600; }
        .status-awaiting { background: #fff5e6; color: #c17d00; }
        .status-proposal { background: #e3f5ff; color: #0c63a7; }
        .status-review { background: #fff0f3; color: #b42318; }
        .status-defense { background: #eaf6ee; color: #16562c; }
        .status-complete { background: #e8e4ff; color: #4b2db6; }
        .empty-state { text-align: center; padding: 4rem 1rem; color: #7a8594; }
        .empty-state i { font-size: 2.6rem; color: #16562c; margin-bottom: .75rem; display: block; }
        .action-buttons .btn { border-radius: 999px; }
        @media (max-width: 992px) {
            .directory-wrapper { margin-left: 0; }
            .hero-card { text-align: center; }
        }
    </style>
</head>
<body>
<div class="directory-wrapper">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                <div>
                    <p class="text-uppercase small fw-semibold mb-1">Student Directory</p>
                    <h1 class="fw-bold mb-2">Hi <?= htmlspecialchars($advisorName); ?>, here’s your advisee roster.</h1>
                    <p class="mb-0">Monitor thesis topics, program placement, review statuses, and upcoming defense schedules in one responsive dashboard.</p>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php foreach ($heroSummary as $label => $value): ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <small><?= htmlspecialchars($label); ?></small>
                        <h3><?= number_format($value); ?></h3>
                        <p class="text-muted mb-0"><?= $label === 'Assigned Students'
                                ? 'Advisees linked to you'
                                : ($label === 'Upcoming Defenses'
                                    ? 'Future schedules tracked'
                                    : ($label === 'Active Research Titles'
                                        ? 'Students with working titles'
                                        : 'Students needing topics')); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($assignSuccess !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($assignSuccess); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($assignError !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($assignError); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" class="card add-advisee-card mb-4">
            <input type="hidden" name="assign_advisee" value="1">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                    <div>
                        <h5 class="mb-1 text-success"><i class="bi bi-person-plus-fill me-2"></i>Add a Student to Your Advisory List</h5>
                        <p class="text-muted mb-0">Select a student without an adviser and link them to your roster.</p>
                    </div>
                    <?php if (!empty($unassignedStudents)): ?>
                        <div class="d-flex flex-column flex-lg-row gap-2">
                            <select name="student_id" class="form-select" required>
                                <option value="">-- Choose a student --</option>
                                <?php foreach ($unassignedStudents as $candidate): ?>
                                    <option value="<?= (int)$candidate['id']; ?>">
                                        <?= htmlspecialchars($candidate['full_name']); ?> - <?= htmlspecialchars($candidate['program']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-success"><i class="bi bi-link-45deg me-1"></i>Link Student</button>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">No unassigned students are available right now.</div>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <form method="GET" class="card filter-card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-5">
                        <label class="form-label text-muted">Search by student or email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent"><i class="bi bi-search text-success"></i></span>
                            <input type="text" class="form-control" name="search" placeholder="e.g. Dela Cruz" value="<?= htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <?php if ($hasProgramColumn && !empty($programOptions)): ?>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label text-muted">Program / Course</label>
                            <select name="program" class="form-select">
                                <option value="">All Programs</option>
                                <?php foreach ($programOptions as $programOption): ?>
                                    <option value="<?= htmlspecialchars($programOption); ?>" <?= $programFilter === $programOption ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($programOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label text-muted">Progress Status</label>
                        <select name="status" class="form-select">
                            <option value="all">All Statuses</option>
                            <?php foreach ($statusOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option); ?>" <?= (strcasecmp($statusFilter, $option) === 0) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill"><i class="bi bi-funnel me-1"></i>Apply</button>
                        <a href="adviser_directory.php" class="btn btn-outline-secondary flex-fill">Reset</a>
                    </div>
                </div>
                <div class="mt-3">
                    <?php if ($search !== ''): ?>
                        <span class="filter-pill"><i class="bi bi-search"></i><?= htmlspecialchars($search); ?></span>
                    <?php endif; ?>
                    <?php if ($programFilter !== ''): ?>
                        <span class="filter-pill"><i class="bi bi-collection"></i><?= htmlspecialchars($programFilter); ?></span>
                    <?php endif; ?>
                    <?php if ($statusFilter !== '' && $statusFilter !== 'all'): ?>
                        <span class="filter-pill"><i class="bi bi-check2-circle"></i><?= htmlspecialchars($statusFilter); ?></span>
                    <?php endif; ?>
                    <?php if ($search === '' && $programFilter === '' && ($statusFilter === '' || $statusFilter === 'all')): ?>
                        <span class="text-muted small">No additional filters applied.</span>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if (empty($filteredStudents)): ?>
            <div class="card">
                <div class="card-body empty-state">
                    <i class="bi bi-people"></i>
                    <p class="mb-2">No students match your current filters.</p>
                    <a href="adviser_directory.php" class="btn btn-outline-success btn-sm">Clear filters</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 text-success">Advisee Listing</h5>
                        <small class="text-muted">Displaying <?= number_format($stats['total']); ?> student<?= $stats['total'] === 1 ? '' : 's'; ?>.</small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 directory-table">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Program / Course</th>
                                    <th>Title / Research Topic</th>
                                    <th>Status</th>
                                    <th>Defense Schedule</th>
                                    <th class="text-center">Action Buttons</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filteredStudents as $student): ?>
                                    <?php
                                        $studentId = (int)$student['id'];
                                        $studentName = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
                                        $programLabel = $hasProgramColumn ? trim((string)($student['program'] ?? '')) : '';
                                        $programDisplay = $programLabel !== '' ? $programLabel : 'Not specified';
                                        $researchTitle = trim((string)($student['research_title'] ?? ''));
                                        $researchDisplay = $researchTitle !== '' ? $researchTitle : 'Awaiting research topic';
                                        $progress = $student['progress_status'];
                                        $defenseLabel = $student['defense_schedule_label'] ?? 'Schedule to be announced';

                                        $statusClass = match ($progress) {
                                            'Awaiting Proposal' => 'status-chip status-awaiting',
                                            'Proposal Stage' => 'status-chip status-proposal',
                                            'Under Review' => 'status-chip status-review',
                                            'Defense Scheduled' => 'status-chip status-defense',
                                            'Completed' => 'status-chip status-complete',
                                            default => 'status-chip status-proposal',
                                        };
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-success"><?= htmlspecialchars($studentName); ?></div>
                                            <div class="small text-muted"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($student['email'] ?? ''); ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($programDisplay); ?></td>
                                        <td>
                                            <div><?= htmlspecialchars($researchDisplay); ?></div>
                                            <?php if ($researchTitle !== ''): ?>
                                                <small class="text-muted d-block">Last update ready for review</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?= $statusClass; ?>"><?= htmlspecialchars($progress); ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($defenseLabel); ?></div>
                                            <?php if (!empty($student['defense_status'])): ?>
                                                <small class="text-muted"><?= htmlspecialchars($student['defense_status']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center action-buttons">
                                            <div class="d-flex flex-column flex-lg-row gap-2 justify-content-center">
                                                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#profileModal<?= $studentId; ?>">
                                                    <i class="bi bi-person-vcard"></i> View Profile
                                                </button>
                                                <a href="mailto:<?= htmlspecialchars($student['email'] ?? ''); ?>" class="btn btn-outline-secondary btn-sm">
                                                    <i class="bi bi-envelope-open"></i> Message
                                                </a>
                                            </div>
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="profileModal<?= $studentId; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Student Profile</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p class="mb-1 fw-semibold text-success"><?= htmlspecialchars($studentName); ?></p>
                                                    <p class="text-muted small mb-3"><?= htmlspecialchars($programDisplay); ?></p>
                                                    <dl class="row mb-0">
                                                        <dt class="col-5">Email</dt>
                                                        <dd class="col-7"><?= htmlspecialchars($student['email'] ?? ''); ?></dd>
                                                        <dt class="col-5">Contact</dt>
                                                        <dd class="col-7"><?= htmlspecialchars($student['contact'] ?? 'No contact number'); ?></dd>
                                                        <dt class="col-5">Research Title</dt>
                                                        <dd class="col-7"><?= htmlspecialchars($researchDisplay); ?></dd>
                                                        <dt class="col-5">Progress Status</dt>
                                                        <dd class="col-7"><?= htmlspecialchars($progress); ?></dd>
                                                        <dt class="col-5">Defense Schedule</dt>
                                                        <dd class="col-7"><?= htmlspecialchars($defenseLabel); ?></dd>
                                                    </dl>
                                                </div>
                                                <div class="modal-footer">
                                                    <a href="mailto:<?= htmlspecialchars($student['email'] ?? ''); ?>" class="btn btn-outline-secondary">
                                                        <i class="bi bi-envelope"></i> Message
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
