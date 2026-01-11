<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'program_chairperson') {
    header("Location: login.php");
    exit;
}

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

function bindStatementParams(mysqli_stmt $stmt, string $types, array &$params): bool
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

$hasStudentIdColumn = columnExists($conn, 'users', 'student_id');
$hasProgramColumn = columnExists($conn, 'users', 'program');
$hasYearLevelColumn = columnExists($conn, 'users', 'year_level');
$hasContactColumn = columnExists($conn, 'users', 'contact');

$successMessage = '';
$errorMessage = '';

if (isset($_POST['update_student'])) {
    $studentId = (int)($_POST['user_id'] ?? 0);
    $studentIdValue = trim($_POST['student_id_value'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $yearLevel = trim($_POST['year_level'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    if ($studentId <= 0) {
        $errorMessage = "Invalid student record.";
    } else {
        $updates = [];
        $types = '';
        $params = [];

        if ($hasStudentIdColumn) {
            $updates[] = "student_id = ?";
            $types .= 's';
            $params[] = $studentIdValue;
        }
        if ($hasProgramColumn) {
            $updates[] = "program = ?";
            $types .= 's';
            $params[] = $program;
        }
        if ($hasYearLevelColumn) {
            $updates[] = "year_level = ?";
            $types .= 's';
            $params[] = $yearLevel;
        }
        if ($hasContactColumn) {
            $updates[] = "contact = ?";
            $types .= 's';
            $params[] = $contact;
        }

        if (!empty($updates)) {
            $params[] = $studentId;
            $types .= 'i';

            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                bindStatementParams($stmt, $types, $params);
                if ($stmt->execute()) {
                    $successMessage = "Student profile updated successfully.";
                } else {
                    $errorMessage = "Unable to update student profile.";
                }
                $stmt->close();
            } else {
                $errorMessage = "Failed to prepare update statement.";
            }
        } else {
            $errorMessage = "No editable fields available for this student.";
        }
    }
}

$programCounts = [];
$yearCounts = [];

if ($hasProgramColumn) {
    $programResult = $conn->query("SELECT program, COUNT(*) AS total FROM users WHERE role = 'student' GROUP BY program");
    if ($programResult) {
        while ($row = $programResult->fetch_assoc()) {
            $programCounts[$row['program'] ?? 'Unknown'] = (int)($row['total'] ?? 0);
        }
        $programResult->free();
        if (!empty($programCounts)) {
            arsort($programCounts);
        }
    }
}

if ($hasYearLevelColumn) {
    $yearResult = $conn->query("SELECT year_level, COUNT(*) AS total FROM users WHERE role = 'student' GROUP BY year_level");
    if ($yearResult) {
        while ($row = $yearResult->fetch_assoc()) {
            $yearCounts[$row['year_level'] ?? 'Unspecified'] = (int)($row['total'] ?? 0);
        }
        $yearResult->free();
    }
}

$studentTotal = 0;
$totalResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'student'");
if ($totalResult && $totalRow = $totalResult->fetch_assoc()) {
    $studentTotal = (int)($totalRow['total'] ?? 0);
}
if ($totalResult) {
    $totalResult->free();
}

$programOptions = [];
if ($hasProgramColumn) {
    $programOptionResult = $conn->query("SELECT DISTINCT program FROM users WHERE role = 'student' AND program <> '' ORDER BY program ASC");
    if ($programOptionResult) {
        while ($row = $programOptionResult->fetch_assoc()) {
            if (!empty($row['program'])) {
                $programOptions[] = $row['program'];
            }
        }
        $programOptionResult->free();
    }
}

$yearOptions = [];
if ($hasYearLevelColumn) {
    $yearOptionResult = $conn->query("SELECT DISTINCT year_level FROM users WHERE role = 'student' AND year_level <> '' ORDER BY year_level ASC");
    if ($yearOptionResult) {
        while ($row = $yearOptionResult->fetch_assoc()) {
            if (!empty($row['year_level'])) {
                $yearOptions[] = $row['year_level'];
            }
        }
        $yearOptionResult->free();
    }
}

$search = trim($_GET['search'] ?? '');
$programFilter = $_GET['program'] ?? '';
$yearFilter = $_GET['year_level'] ?? '';

$conditions = ["role = 'student'"];
$params = [];
$types = '';

if ($search !== '') {
    $conditions[] = "(firstname LIKE ? OR lastname LIKE ? OR email LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

if ($hasProgramColumn && $programFilter !== '' && in_array($programFilter, $programOptions, true)) {
    $conditions[] = "program = ?";
    $params[] = $programFilter;
    $types .= 's';
}

if ($hasYearLevelColumn && $yearFilter !== '' && in_array($yearFilter, $yearOptions, true)) {
    $conditions[] = "year_level = ?";
    $params[] = $yearFilter;
    $types .= 's';
}

$selectFields = [
    "id",
    "firstname",
    "lastname",
    "email",
    "(SELECT COUNT(*) FROM concept_papers cp WHERE cp.student_id = users.id) AS concept_count"
];
if ($hasStudentIdColumn) {
    $selectFields[] = "student_id";
}
if ($hasProgramColumn) {
    $selectFields[] = "program";
}
if ($hasYearLevelColumn) {
    $selectFields[] = "year_level";
}
if ($hasContactColumn) {
    $selectFields[] = "contact";
}

$sql = "
    SELECT " . implode(', ', $selectFields) . "
    FROM users
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY lastname ASC, firstname ASC
";

$students = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    bindStatementParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}

$filteredTotal = count($students);
$studentsWithConcepts = 0;
$studentsWithoutConcepts = 0;
$studentsMissingContact = 0;
$filteredProgramSet = [];

foreach ($students as $student) {
    $conceptCount = (int)($student['concept_count'] ?? 0);
    if ($conceptCount > 0) {
        $studentsWithConcepts++;
    } else {
        $studentsWithoutConcepts++;
    }
    $contactValue = trim((string)($student['contact'] ?? ''));
    if ($contactValue === '') {
        $studentsMissingContact++;
    }
    $programName = trim((string)($student['program'] ?? ''));
    if ($programName !== '') {
        $filteredProgramSet[$programName] = true;
    }
}
$uniqueProgramsInView = count($filteredProgramSet);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Management Directory</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
    <style>
        body { background: #f6f8fb; }
        .directory-content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .directory-content { margin-left: 60px; }
        .directory-hero { border-radius: 24px; background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; padding: 32px; box-shadow: 0 24px 48px rgba(22, 86, 44, 0.24); }
        .directory-hero p { opacity: .85; }
        .directory-hero .btn { border-radius: 999px; }
        .stat-badge-card { border: none; border-radius: 20px; padding: 24px; color: #0f3d1f; background: #fff; box-shadow: 0 20px 40px rgba(15, 61, 31, 0.08); min-height: 170px; }
        .stat-badge-card h3 { font-size: 2.3rem; margin: 0; }
        .stat-badge-card small { text-transform: uppercase; letter-spacing: .08em; color: #7f8a9b; }
        .filter-card { border-radius: 20px; border: none; box-shadow: 0 16px 36px rgba(22, 86, 44, 0.1); }
        .filter-summary { gap: .6rem; }
        .filter-pill { border-radius: 999px; background: #e8f5eb; color: #0f3d1f; padding: .35rem .9rem; font-size: .85rem; }
        .student-card { border: none; border-radius: 20px; background: #fff; box-shadow: 0 14px 34px rgba(15, 61, 31, 0.12); }
        .student-card .badge { border-radius: 999px; }
        .student-card .detail-item { font-size: .93rem; color: #576170; display: flex; align-items: center; gap: .45rem; margin-bottom: .25rem; }
        .student-card .detail-item i { color: #16562c; }
        .student-card .progress { height: 6px; border-radius: 999px; }
        .empty-state { text-align: center; padding: 4rem 1rem; color: #7a8594; }
        .empty-state i { font-size: 2.75rem; color: #16562c; margin-bottom: .75rem; display: block; }
        @media (max-width: 992px) {
            .directory-content { margin-left: 0; }
            .directory-hero { text-align: center; }
            .directory-hero .d-flex { justify-content: center !important; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="directory-content">
    <div class="container-fluid py-4">
        <div class="directory-hero mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <p class="mb-1 text-uppercase small fw-semibold">Student Directory</p>
                    <h1 class="h3 fw-bold mb-2">Manage every learner in one polished view.</h1>
                    <p class="mb-0">Search, filter, and update student records while tracking concept submissions and program assignments.</p>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <a href="create_student.php" class="btn btn-light text-success fw-semibold">
                        <i class="bi bi-person-plus-fill me-1"></i> Add Student
                    </a>
                    <a href="assign_panel.php" class="btn btn-outline-light">
                        <i class="bi bi-people me-1"></i> Assign Panels
                    </a>
                    <a href="student_dashboard.php" class="btn btn-outline-light">
                        <i class="bi bi-speedometer2 me-1"></i> Student Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-badge-card h-100">
                    <small>Total Students</small>
                    <h3 class="text-success mt-2"><?= number_format($studentTotal); ?></h3>
                    <p class="mb-0 text-muted small">All active records in the system</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-badge-card h-100">
                    <small>Filtered View</small>
                    <h3 class="text-primary mt-2"><?= number_format($filteredTotal); ?></h3>
                    <p class="mb-0 text-muted small">Matches current search & filters</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-badge-card h-100">
                    <small>Concept Submissions</small>
                    <h3 class="text-warning mt-2"><?= number_format($studentsWithConcepts); ?></h3>
                    <p class="mb-0 text-muted small"><?= number_format($studentsWithoutConcepts); ?> awaiting first draft</p>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-badge-card h-100">
                    <small>Programs Represented</small>
                    <h3 class="text-info mt-2"><?= number_format($uniqueProgramsInView); ?></h3>
                    <p class="mb-0 text-muted small"><?= number_format($studentsMissingContact); ?> missing contact info</p>
                </div>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($successMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($errorMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form class="card filter-card mb-4" method="GET">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-5">
                        <label class="form-label text-muted">Search Students</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent"><i class="bi bi-search text-success"></i></span>
                            <input type="text" name="search" class="form-control" placeholder="Name or email" value="<?= htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <?php if ($hasProgramColumn): ?>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label text-muted">Program</label>
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
                    <?php if ($hasYearLevelColumn): ?>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label text-muted">Year Level</label>
                            <select name="year_level" class="form-select">
                                <option value="">All Levels</option>
                                <?php foreach ($yearOptions as $yearOption): ?>
                                    <option value="<?= htmlspecialchars($yearOption); ?>" <?= $yearFilter === $yearOption ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($yearOption); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-lg-2 col-md-6 d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill"><i class="bi bi-funnel me-1"></i>Apply</button>
                        <a href="student_directory.php" class="btn btn-outline-secondary flex-fill">Reset</a>
                    </div>
                </div>
                <div class="d-flex flex-wrap filter-summary mt-3">
                    <?php if ($search !== ''): ?>
                        <span class="filter-pill"><i class="bi bi-search me-1"></i><?= htmlspecialchars($search); ?></span>
                    <?php endif; ?>
                    <?php if ($programFilter !== ''): ?>
                        <span class="filter-pill"><i class="bi bi-collection me-1"></i><?= htmlspecialchars($programFilter); ?></span>
                    <?php endif; ?>
                    <?php if ($yearFilter !== ''): ?>
                        <span class="filter-pill"><i class="bi bi-layers me-1"></i><?= htmlspecialchars($yearFilter); ?></span>
                    <?php endif; ?>
                    <?php if ($search === '' && $programFilter === '' && $yearFilter === ''): ?>
                        <span class="text-muted small">No filters applied.</span>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-0 text-success">Student Listings</h5>
                <small class="text-muted">Showing <?= number_format($filteredTotal); ?> record(s)<?php if ($filteredTotal !== $studentTotal): ?> from <?= number_format($studentTotal); ?> total<?php endif; ?>.</small>
            </div>
            <div class="text-muted small d-none d-md-block">
                <?= number_format($studentsMissingContact); ?> student<?= $studentsMissingContact === 1 ? '' : 's'; ?> missing contact info
            </div>
        </div>

        <?php if (empty($students)): ?>
            <div class="card student-card">
                <div class="card-body empty-state">
                    <i class="bi bi-people"></i>
                    <p class="mb-2">No students match your current filters.</p>
                    <a href="student_directory.php" class="btn btn-outline-success btn-sm">Clear filters</a>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4 row-cols-1 row-cols-md-2 row-cols-xl-3">
                <?php foreach ($students as $student): ?>
                    <?php
                        $modalId = 'manageStudent' . (int)$student['id'];
                        $conceptCount = (int)($student['concept_count'] ?? 0);
                        $conceptPercent = min(100, max(0, $conceptCount * 20));
                        $programLabel = trim((string)($student['program'] ?? ''));
                        $yearLabel = trim((string)($student['year_level'] ?? ''));
                        $studentIdLabel = $hasStudentIdColumn ? trim((string)($student['student_id'] ?? '')) : '';
                    ?>
                    <div class="col">
                        <div class="student-card h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="mb-1 text-success"><?= htmlspecialchars(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? '')); ?></h5>
                                        <p class="mb-2 text-muted small"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($student['email'] ?? ''); ?></p>
                                    </div>
                                    <?php if ($programLabel !== ''): ?>
                                        <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($programLabel); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <?php if ($studentIdLabel !== ''): ?>
                                        <div class="detail-item"><i class="bi bi-hash"></i>Student ID: <?= htmlspecialchars($studentIdLabel); ?></div>
                                    <?php endif; ?>
                                    <?php if ($yearLabel !== ''): ?>
                                        <div class="detail-item"><i class="bi bi-layers"></i>Year Level: <?= htmlspecialchars($yearLabel); ?></div>
                                    <?php endif; ?>
                                    <?php if ($hasContactColumn): ?>
                                        <div class="detail-item">
                                            <i class="bi bi-telephone"></i><?= htmlspecialchars($student['contact'] ?? 'No contact number'); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small text-muted">
                                        <span>Concept Papers</span>
                                        <span><?= $conceptCount; ?></span>
                                    </div>
                                    <div class="progress bg-light">
                                        <div class="progress-bar bg-success" style="width: <?= $conceptPercent; ?>%;"></div>
                                    </div>
                                </div>
                                <div class="mt-auto d-flex flex-wrap gap-2">
                                    <a href="mailto:<?= htmlspecialchars($student['email'] ?? ''); ?>" class="btn btn-outline-secondary btn-sm flex-fill">
                                        <i class="bi bi-envelope-open me-1"></i> Email
                                    </a>
                                    <button type="button" class="btn btn-success btn-sm flex-fill" data-bs-toggle="modal" data-bs-target="#<?= $modalId; ?>">
                                        <i class="bi bi-pencil-square me-1"></i> Manage
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="<?= $modalId; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Manage Student Profile</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <input type="hidden" name="user_id" value="<?= (int)$student['id']; ?>">
                                        <?php if ($hasStudentIdColumn): ?>
                                            <div class="mb-3">
                                                <label class="form-label">Student ID</label>
                                                <input type="text" name="student_id_value" class="form-control" value="<?= htmlspecialchars($student['student_id'] ?? ''); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($hasProgramColumn): ?>
                                            <div class="mb-3">
                                                <label class="form-label">Program</label>
                                                <input type="text" name="program" class="form-control" value="<?= htmlspecialchars($student['program'] ?? ''); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($hasYearLevelColumn): ?>
                                            <div class="mb-3">
                                                <label class="form-label">Year Level</label>
                                                <input type="text" name="year_level" class="form-control" value="<?= htmlspecialchars($student['year_level'] ?? ''); ?>">
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($hasContactColumn): ?>
                                            <div class="mb-3">
                                                <label class="form-label">Contact</label>
                                                <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($student['contact'] ?? ''); ?>">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="update_student" class="btn btn-success">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar = document.getElementById('sidebar');
    const toggleSidebar = document.getElementById('toggleSidebar');
    if (sidebar && toggleSidebar) {
        toggleSidebar.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });
    }
</script>
</body>
</html>
