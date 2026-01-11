<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'final_endorsement_helpers.php';

enforce_role_access(['program_chairperson']);

ensureFinalEndorsementTables($conn);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$conditions = [];
$types = '';
$params = [];

if ($search !== '') {
    $conditions[] = "(CONCAT(stu.firstname, ' ', stu.lastname) LIKE ? OR stu.email LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
    $conditions[] = "s.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

$whereClause = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
$sql = "
    SELECT
        s.id,
        s.status,
        s.submitted_at,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
        stu.email AS student_email
    FROM final_endorsement_submissions s
    JOIN users stu ON stu.id = s.student_id
    {$whereClause}
    ORDER BY s.submitted_at DESC, s.id DESC
";

$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
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
    <title>Final Endorsement Inbox</title>
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
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-inbox me-2"></i>Final Endorsement Inbox</h3>
                <p class="text-muted mb-0">Review final endorsement submissions from students.</p>
            </div>
        </div>

        <div class="card filter-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold text-success">Search</label>
                        <input type="search" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control" placeholder="Search student or email">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold text-success">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <?php foreach (['Submitted','Approved','Rejected'] as $option): ?>
                                <option value="<?= $option; ?>" <?= $statusFilter === $option ? 'selected' : ''; ?>><?= $option; ?></option>
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
                <i class="bi bi-inbox fs-3 d-block mb-2 text-success"></i>
                No final endorsement submissions yet.
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 inbox-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Submitted</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $status = $row['status'] ?? 'Submitted'; ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-success"><?= htmlspecialchars($row['student_name'] ?? ''); ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($row['student_email'] ?? ''); ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($row['submitted_at'] ?? ''); ?></td>
                                        <td><span class="badge <?= finalEndorsementStatusClass($status); ?>"><?= htmlspecialchars($status); ?></span></td>
                                        <td class="text-end">
                                            <a href="final_endorsement_review.php?submission_id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-success">
                                                Review
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
