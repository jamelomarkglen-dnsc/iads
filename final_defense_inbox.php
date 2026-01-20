<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'final_defense_submission_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chair', 'committee_chairperson'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureFinalDefenseSubmissionTable($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$conditions = [
    '(fds.adviser_id = ? OR fds.chair_id = ? OR fds.panel_member_one_id = ? OR fds.panel_member_two_id = ?)'
];
$types = 'iiii';
$params = [$userId, $userId, $userId, $userId];

if ($search !== '') {
    $conditions[] = '(s.title LIKE ? OR CONCAT(stu.firstname, " ", stu.lastname) LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
    $conditions[] = 'fds.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

$sql = "
    SELECT fds.*, s.title AS submission_title,
           CONCAT(stu.firstname, ' ', stu.lastname) AS student_name
    FROM final_defense_submissions fds
    LEFT JOIN submissions s ON s.id = fds.submission_id
    LEFT JOIN users stu ON stu.id = fds.student_id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY fds.submitted_at DESC, fds.id DESC
";
$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
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
    <title>Final Defense Inbox</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Defense Inbox</h3>
                <p class="text-muted mb-0">Final defense submissions assigned to you.</p>
            </div>
        </div>

        <div class="card card-shell mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold text-success">Search</label>
                        <input type="search" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control" placeholder="Search student or title">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold text-success">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <?php foreach (['Submitted','Passed','Failed'] as $option): ?>
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

        <div class="card card-shell">
            <div class="card-body">
                <?php if (empty($rows)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-inbox fs-2 mb-2"></i>
                        <p class="mb-0">No final defense submissions yet.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $status = $row['status'] ?? 'Submitted';
                                $badgeClass = $status === 'Passed'
                                    ? 'bg-success-subtle text-success'
                                    : ($status === 'Failed' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning');
                                $isChair = (int)($row['chair_id'] ?? 0) === $userId;
                            ?>
                            <div class="border rounded-4 p-3">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="fw-semibold text-success"><?= htmlspecialchars($row['student_name'] ?? 'Student'); ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($row['submission_title'] ?? 'Submission'); ?></div>
                                        <div class="text-muted small">Submitted <?= htmlspecialchars($row['submitted_at'] ?? ''); ?></div>
                                    </div>
                                    <span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span>
                                </div>
                                <div class="mt-3 d-flex justify-content-end">
                                    <a href="final_defense_review.php?submission_id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-success">
                                        <?= $isChair ? 'Review' : 'View'; ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
