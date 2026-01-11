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

$hasDepartmentColumn = columnExists($conn, 'users', 'department');
$hasCollegeColumn = columnExists($conn, 'users', 'college');
$hasContactColumn = columnExists($conn, 'users', 'contact');

$allowedRoles = ['faculty', 'adviser', 'panel', 'committee_chair'];
$roleLabels = [
    'faculty' => 'Faculty Member',
    'adviser' => 'Thesis Adviser',
    'panel' => 'Panel Member',
    'committee_chair' => 'Committee Chairperson',
];

$successMessage = '';
$errorMessage = '';

if (isset($_POST['update_faculty'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    $department = trim($_POST['department'] ?? '');
    $college = trim($_POST['college'] ?? '');
    $contact = trim($_POST['contact'] ?? '');

    if ($userId <= 0 || !in_array($role, $allowedRoles, true)) {
        $errorMessage = "Invalid faculty record.";
    } else {
        $updates = ['role = ?'];
        $params = [$role];
        $types = 's';

        if ($hasDepartmentColumn) {
            $updates[] = 'department = ?';
            $params[] = $department;
            $types .= 's';
        }
        if ($hasCollegeColumn) {
            $updates[] = 'college = ?';
            $params[] = $college;
            $types .= 's';
        }
        if ($hasContactColumn) {
            $updates[] = 'contact = ?';
            $params[] = $contact;
            $types .= 's';
        }

        $params[] = $userId;
        $types .= 'i';

        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            bindStatementParams($stmt, $types, $params);
            if ($stmt->execute()) {
                $successMessage = "Faculty record updated successfully.";
            } else {
                $errorMessage = "Failed to update faculty record.";
            }
            $stmt->close();
        } else {
            $errorMessage = "Unable to prepare update statement.";
        }
    }
}

$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';

$conditions = [];
$parameters = [];
$types = '';

if ($search !== '') {
    $conditions[] = "(firstname LIKE ? OR lastname LIKE ? OR email LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $parameters[] = $searchTerm;
    $parameters[] = $searchTerm;
    $parameters[] = $searchTerm;
    $types .= 'sss';
}

if ($roleFilter !== '' && in_array($roleFilter, $allowedRoles, true)) {
    $conditions[] = "role = ?";
    $parameters[] = $roleFilter;
    $types .= 's';
}

$whereClause = '';
if (!empty($conditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
}

$sql = "
    SELECT id, firstname, lastname, email, role
    " . ($hasDepartmentColumn ? ", department" : "") . "
    " . ($hasCollegeColumn ? ", college" : "") . "
    " . ($hasContactColumn ? ", contact" : "") . "
    FROM users
    WHERE role IN ('" . implode("','", $allowedRoles) . "')
";

if ($whereClause !== '') {
    $sql .= " AND " . substr($whereClause, 6);
}

$sql .= " ORDER BY lastname, firstname";

$facultyList = [];
$stmt = $conn->prepare($sql);

if ($stmt) {
    bindStatementParams($stmt, $types, $parameters);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $facultyList[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Directory</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
    <style>
        .content {
            margin-left: var(--sidebar-width-expanded, 240px);
            transition: margin-left 0.3s ease;
        }
        #sidebar.collapsed ~ .content {
            margin-left: var(--sidebar-width-collapsed, 84px);
        }
        @media (max-width: 992px) {
            .content {
                margin-left: 0;
            }
            #sidebar.collapsed ~ .content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content dashboard-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h4 fw-semibold text-success mb-1">Faculty Management Directory</h1>
                <p class="text-muted mb-0">Review and update faculty, adviser, committee, and panel assignments.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="assign_faculty.php" class="btn btn-success">
                    <i class="bi bi-person-gear me-2"></i> Assign Reviewers
                </a>
                <a href="create_faculty.php" class="btn btn-outline-success">
                    <i class="bi bi-person-plus-fill me-2"></i> Add Faculty
                </a>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($successMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($errorMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form class="card shadow-sm border-0 mb-4" method="GET">
            <div class="card-body row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label text-muted">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search by name or email"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label text-muted">Role</label>
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <?php foreach ($allowedRoles as $role): ?>
                            <option value="<?php echo htmlspecialchars($role); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($roleLabels[$role] ?? ucfirst($role)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-search me-2"></i> Filter
                    </button>
                    <a href="faculty_directory.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </div>
        </form>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h6 fw-semibold mb-1">Faculty Listings</h2>
                    <p class="text-muted small mb-0">Showing <?php echo count($facultyList); ?> record(s).</p>
                </div>
            </div>
            <div class="card-body pt-0">
                <?php if (!empty($facultyList)): ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Name &amp; Email</th>
                                    <th scope="col">Role</th>
                                    <?php if ($hasDepartmentColumn || $hasCollegeColumn): ?>
                                        <th scope="col">Program / Institute</th>
                                    <?php endif; ?>
                                    <?php if ($hasContactColumn): ?>
                                        <th scope="col">Contact</th>
                                    <?php endif; ?>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($facultyList as $user): ?>
                                    <?php $modalId = 'manageFaculty' . (int)$user['id']; ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-success">
                                                <?php echo htmlspecialchars(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')); ?>
                                            </div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success text-wrap">
                                                <?php echo htmlspecialchars($roleLabels[$user['role']] ?? ucfirst((string)$user['role'])); ?>
                                            </span>
                                        </td>
                                        <?php if ($hasDepartmentColumn || $hasCollegeColumn): ?>
                                            <td>
                                                <div class="small fw-semibold text-dark">
                                                    <?php echo htmlspecialchars($user['department'] ?? '—'); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?php echo htmlspecialchars($user['college'] ?? ''); ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <?php if ($hasContactColumn): ?>
                                            <td class="small text-muted">
                                                <?php echo htmlspecialchars($user['contact'] ?? '—'); ?>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="d-flex flex-wrap gap-2">
                                                <a href="assign_panel.php?faculty=<?php echo (int)$user['id']; ?>" class="btn btn-outline-warning btn-sm">
                                                    <i class="bi bi-people-fill me-1"></i> Assign Panel
                                                </a>
                                                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#<?php echo $modalId; ?>">
                                                    <i class="bi bi-pencil-square me-1"></i> Manage
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="<?php echo $modalId; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Manage Faculty Profile</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">Role</label>
                                                            <select name="role" class="form-select" required>
                                                                <?php foreach ($allowedRoles as $roleOption): ?>
                                                                    <option value="<?php echo htmlspecialchars($roleOption); ?>" <?php echo $user['role'] === $roleOption ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($roleLabels[$roleOption] ?? ucfirst($roleOption)); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <?php if ($hasDepartmentColumn): ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Program</label>
                                                                <input type="text" name="department" class="form-control"
                                                                       value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>">
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($hasCollegeColumn): ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Institute</label>
                                                                <input type="text" name="college" class="form-control"
                                                                       value="<?php echo htmlspecialchars($user['college'] ?? ''); ?>">
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($hasContactColumn): ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Contact</label>
                                                                <input type="text" name="contact" class="form-control"
                                                                       value="<?php echo htmlspecialchars($user['contact'] ?? ''); ?>">
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="update_faculty" class="btn btn-success">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No faculty members match your filters.</p>
                <?php endif; ?>
            </div>
        </div>
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
