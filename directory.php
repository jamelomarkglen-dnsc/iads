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

$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$roles = [];
$distinctResult = $conn->query("SELECT DISTINCT role FROM users ORDER BY role ASC");
if ($distinctResult) {
    while ($row = $distinctResult->fetch_assoc()) {
        if (!empty($row['role'])) {
            $roles[] = $row['role'];
        }
    }
    $distinctResult->free();
}

$conditions = [];
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

if ($roleFilter !== '' && in_array($roleFilter, $roles, true)) {
    $conditions[] = "role = ?";
    $params[] = $roleFilter;
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
    {$whereClause}
    ORDER BY role ASC, lastname ASC, firstname ASC
";

$directory = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    bindStatementParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $directory[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unified Directory</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content dashboard-content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h4 fw-semibold text-success mb-1">Institution Directory</h1>
                <p class="text-muted mb-0">Search across faculty, staff, and student accounts for quick coordination.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="faculty_directory.php" class="btn btn-outline-success">
                    <i class="bi bi-people-fill me-2"></i> Faculty Directory
                </a>
                <a href="student_dashboard.php" class="btn btn-outline-primary">
                    <i class="bi bi-mortarboard me-2"></i> Student Dashboard
                </a>
            </div>
        </div>

        <form class="card shadow-sm border-0 mb-4" method="GET">
            <div class="card-body row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label text-muted">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Name or email"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label text-muted">Role</label>
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <?php foreach ($roles as $roleOption): ?>
                            <option value="<?php echo htmlspecialchars($roleOption); ?>" <?php echo $roleFilter === $roleOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $roleOption))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-search me-2"></i> Filter
                    </button>
                    <a href="directory.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </div>
        </form>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0">
                <h2 class="h6 fw-semibold mb-1">Directory Listings</h2>
                <p class="text-muted small mb-0">Displaying <?php echo count($directory); ?> record(s).</p>
            </div>
            <div class="card-body pt-0">
                <?php if (!empty($directory)): ?>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($directory as $entry): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-success">
                                                <?php echo htmlspecialchars(($entry['firstname'] ?? '') . ' ' . ($entry['lastname'] ?? '')); ?>
                                            </div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($entry['email'] ?? ''); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success text-wrap">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$entry['role']))); ?>
                                            </span>
                                        </td>
                                        <?php if ($hasDepartmentColumn || $hasCollegeColumn): ?>
                                            <td>
                                                <div class="small fw-semibold text-dark">
                                                    <?php echo htmlspecialchars($entry['department'] ?? '—'); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?php echo htmlspecialchars($entry['college'] ?? ''); ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        <?php if ($hasContactColumn): ?>
                                            <td class="small text-muted">
                                                <?php echo htmlspecialchars($entry['contact'] ?? '—'); ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No directory entries match your filters.</p>
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
