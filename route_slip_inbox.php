<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'final_paper_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chairperson', 'committee_chair'];
enforce_role_access($allowedRoles);

ensureFinalPaperTables($conn);

$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewerRoleFilter = $roleMap[$role] ?? $role;

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$conditions = ["r.reviewer_id = ?", "s.route_slip_path IS NOT NULL"];
$types = 'i';
$params = [$reviewerId];

if (in_array($reviewerRoleFilter, ['adviser', 'panel', 'committee_chairperson'], true)) {
    $conditions[] = "r.reviewer_role = ?";
    $types .= 's';
    $params[] = $reviewerRoleFilter;
}

if ($search !== '') {
    $conditions[] = "(CONCAT(stu.firstname, ' ', stu.lastname) LIKE ? OR s.final_title LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
    $conditions[] = "r.route_slip_status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

$sql = "
    SELECT
        s.id,
        s.final_title,
        s.submitted_at,
        s.version,
        r.route_slip_status,
        r.reviewer_role,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name
    FROM final_paper_reviews r
    JOIN final_paper_submissions s ON s.id = r.submission_id
    JOIN users stu ON stu.id = s.student_id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY s.submitted_at DESC, s.id DESC
";

$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
    $stmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Slip Inbox</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .filter-card { border-radius: 16px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
        .filter-card .form-control, .filter-card .form-select { border-radius: 999px; }
        .inbox-table thead th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #556; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-file-earmark-check me-2"></i>Route Slip Inbox</h3>
                <p class="text-muted mb-0">Review route slip packets assigned to you.</p>
            </div>
        </div>

        <div class="card filter-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold text-success">Search</label>
                        <input type="search" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control" placeholder="Search student or title">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold text-success">Your Status</label>
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <?php
                                $statusOptions = [
                                    'Pending' => 'Pending',
                                    'Approved' => 'Approved',
                                    'Minor Revision' => 'Minor Revision',
                                    'Major Revision' => 'Major Revision',
                                ];
                            ?>
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= $value; ?>" <?= $statusFilter === $value ? 'selected' : ''; ?>><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button type="submit" class="btn btn-success"><i class="bi bi-search me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="alert alert-light border text-center">
                <i class="bi bi-file-earmark-check fs-3 d-block mb-2 text-success"></i>
                No route slip packets assigned to you yet.
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 inbox-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Final Title</th>
                                    <th>Submitted</th>
                                    <th>Your Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                        $reviewStatus = $row['route_slip_status'] ?? '';
                                        if (strcasecmp($reviewStatus, 'Needs Revision') === 0) {
                                            $reviewStatus = 'Minor Revision';
                                        }
                                        $reviewLabel = $reviewStatus !== '' ? $reviewStatus : 'Pending';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold text-success"><?= htmlspecialchars($row['student_name'] ?? ''); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($row['final_title'] ?? ''); ?></div>
                                            <div class="text-muted small">Version <?= htmlspecialchars((string)($row['version'] ?? 1)); ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($row['submitted_at'] ?? ''); ?></td>
                                        <td><span class="badge <?= finalPaperReviewStatusClass($reviewLabel); ?>"><?= htmlspecialchars($reviewLabel); ?></span></td>
                                        <td class="text-end">
                                            <a href="route_slip_review.php?submission_id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-success">
                                                Review Route Slip
                                            </a>
                                        </td>
                                    </tr>
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
