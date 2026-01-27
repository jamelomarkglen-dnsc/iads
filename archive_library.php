<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';

$role = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['dean', 'program_chairperson'], true)) {
    header('Location: login.php');
    exit;
}

ensureResearchArchiveSupport($conn);

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type_filter'] ?? '');
$publicationFilter = trim($_GET['publication'] ?? '');

$conditions = [];
$params = [];
$types = '';

$conditions[] = "(ra.status IS NULL OR ra.status = 'Archived')";

if ($search !== '') {
    $conditions[] = "(ra.title LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ss';
}

if ($typeFilter !== '') {
    $conditions[] = "ra.doc_type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}

if ($publicationFilter !== '') {
    $conditions[] = "ra.publication_type = ?";
    $params[] = $publicationFilter;
    $types .= 's';
}

$sql = "
    SELECT ra.*, CONCAT(u.firstname, ' ', u.lastname) AS student_name,
           CONCAT(arch.firstname, ' ', arch.lastname) AS archived_by_name
    FROM research_archive ra
    LEFT JOIN users u ON ra.student_id = u.id
    LEFT JOIN users arch ON ra.archived_by = arch.id
";
if (!empty($conditions)) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY ra.archived_at DESC';

$stmt = $conn->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $archiveEntries = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
} else {
    $archiveEntries = [];
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archive Catalog - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .archive-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.08); }
        .archive-card .card-header { background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; }
        .table thead { text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h2 class="fw-bold text-success mb-1"><i class="bi bi-collection me-2"></i>Archive Catalog</h2>
                <p class="text-muted mb-0">Approved research documents ready for reference and publication routing.</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3" method="get">
                    <div class="col-lg-5">
                        <label class="form-label text-success fw-semibold">Search</label>
                        <input type="search" class="form-control" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Search by student or title">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label text-success fw-semibold">Document Type</label>
                        <select name="type_filter" class="form-select">
                            <option value="">All types</option>
                            <?php foreach (['Concept Paper','Thesis','Dissertation','Capstone'] as $type): ?>
                                <option value="<?= $type; ?>" <?= $typeFilter === $type ? 'selected' : ''; ?>><?= $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label text-success fw-semibold">Publication</label>
                        <input type="text" class="form-control" name="publication" value="<?= htmlspecialchars($publicationFilter); ?>" placeholder="e.g., Journal, Hardbound">
                    </div>
                    <div class="col-lg-1 d-grid">
                        <button class="btn btn-outline-success"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card archive-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Archived Documents</h5>
                <span class="badge bg-light text-success"><?= number_format(count($archiveEntries)); ?> items</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($archiveEntries)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-archive fs-2 mb-2"></i>
                        <p class="mb-0">No archived entries found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Student</th>
                                    <th>Type</th>
                                    <th>Publication</th>
                                    <th>Archived</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archiveEntries as $entry): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($entry['title']); ?></strong>
                                            <?php if (!empty($entry['keywords'])): ?>
                                                <div class="text-muted small"><?= htmlspecialchars($entry['keywords']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($entry['student_name'] ?? 'Student'); ?></td>
                                        <td><?= htmlspecialchars($entry['doc_type']); ?></td>
                                        <td><?= htmlspecialchars($entry['publication_type'] ?? '—'); ?></td>
                                        <td>
                                            <?= htmlspecialchars(date('M d, Y g:i A', strtotime($entry['archived_at']))); ?>
                                            <?php if (!empty($entry['archived_by_name'])): ?>
                                                <div class="text-muted small">By <?= htmlspecialchars($entry['archived_by_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!empty($entry['file_path'])): ?>
                                                <a href="<?= htmlspecialchars($entry['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
