<?php
session_start();
require_once 'db.php';
require_once 'final_hardbound_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header('Location: login.php');
    exit;
}

ensureFinalHardboundTables($conn);

$search = trim($_GET['search'] ?? '');
$programFilter = trim($_GET['program'] ?? '');

$conditions = [];
$params = [];
$types = '';

if ($search !== '') {
    $conditions[] = "(s.title LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ss';
}

if ($programFilter !== '') {
    $conditions[] = "u.program = ?";
    $params[] = $programFilter;
    $types .= 's';
}

$sql = "
    SELECT ifc.*, s.title, s.type,
           CONCAT(u.firstname, ' ', u.lastname) AS student_name,
           u.program, u.department, u.college,
           ra.id AS archive_id, ra.status AS archive_status
    FROM institutional_final_copies ifc
    LEFT JOIN submissions s ON s.id = ifc.submission_id
    LEFT JOIN users u ON u.id = ifc.student_id
    LEFT JOIN research_archive ra ON ra.submission_id = ifc.submission_id
";
if (!empty($conditions)) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY ifc.stored_at DESC';

$stmt = $conn->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
} else {
    $entries = [];
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Institutional Final Research Copy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .archive-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.08); }
        .archive-card .card-header { background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; }
        .table thead { text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; }
        .badge { font-weight: 600; }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h2 class="fw-bold text-success mb-1"><i class="bi bi-archive me-2"></i>Institutional Final Research Copy</h2>
                <p class="text-muted mb-0">Final PDF copies stored immediately after student archive upload. Archive Manager unlocks them after 5 years.</p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3" method="get">
                    <div class="col-lg-5">
                        <label class="form-label text-success fw-semibold">Search</label>
                        <input type="search" class="form-control" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Search by student or title">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label text-success fw-semibold">Program</label>
                        <input type="text" class="form-control" name="program" value="<?= htmlspecialchars($programFilter); ?>" placeholder="e.g., BSIT">
                    </div>
                    <div class="col-lg-3 d-grid">
                        <button class="btn btn-outline-success align-self-end"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card archive-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Stored Institutional Copies</h5>
                <span class="badge bg-light text-success"><?= number_format(count($entries)); ?> items</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($entries)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-archive fs-2 mb-2"></i>
                        <p class="mb-0">No institutional copies found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Student</th>
                                    <th>Program</th>
                                    <th>Stored</th>
                                    <th>Archive Eligibility</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($entries as $entry): ?>
                                    <?php
                                        $storedAt = $entry['stored_at'] ?? '';
                                        $storedTs = $storedAt ? strtotime($storedAt) : 0;
                                        $eligibleTs = $storedTs ? strtotime('+5 years', $storedTs) : 0;
                                        $eligibleNow = $eligibleTs > 0 && $eligibleTs <= time();
                                        $eligibleLabel = $eligibleTs ? date('M d, Y', $eligibleTs) : 'N/A';
                                        $archiveId = (int)($entry['archive_id'] ?? 0);
                                        $archiveStatus = $entry['archive_status'] ?? null;
                                        $isArchived = $archiveId > 0 && ($archiveStatus === null || $archiveStatus === '' || $archiveStatus === 'Archived');
                                        $isRestored = $archiveId > 0 && $archiveStatus === 'Restored';
                                        $statusLabel = $isArchived ? 'Archived' : ($eligibleNow ? 'Eligible' : 'Eligible on ' . $eligibleLabel);
                                        $statusBadge = $isArchived ? 'bg-secondary-subtle text-secondary' : ($eligibleNow ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning');
                                        if ($isRestored) {
                                            $statusLabel = 'Restored';
                                            $statusBadge = 'bg-info-subtle text-info';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($entry['title'] ?? 'Research document'); ?></strong>
                                            <?php if (!empty($entry['type'])): ?>
                                                <div class="text-muted small"><?= htmlspecialchars($entry['type']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($entry['student_name'] ?? 'Student'); ?></td>
                                        <td>
                                            <?= htmlspecialchars($entry['program'] ?? ''); ?>
                                            <?php if (!empty($entry['department'])): ?>
                                                <div class="text-muted small"><?= htmlspecialchars($entry['department']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $storedAt ? htmlspecialchars(date('M d, Y g:i A', $storedTs)) : 'N/A'; ?></td>
                                        <td>
                                            <span class="badge <?php echo $statusBadge; ?>">
                                                <?php echo htmlspecialchars($statusLabel); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!$isArchived): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-success archive-trigger"
                                                        data-submission-id="<?php echo (int)($entry['submission_id'] ?? 0); ?>"
                                                        data-title="<?php echo htmlspecialchars($entry['title'] ?? 'Research document', ENT_QUOTES); ?>"
                                                        data-eligible="<?php echo $eligibleNow ? '1' : '0'; ?>"
                                                        data-eligible-label="<?php echo htmlspecialchars($eligibleLabel, ENT_QUOTES); ?>">
                                                    Archive Now
                                                </button>
                                            <?php endif; ?>
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
<div class="modal fade" id="archiveConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="archive_manager.php" class="modal-content" id="archiveConfirmForm">
            <div class="modal-header">
                <h5 class="modal-title">Archive Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="archive_submission" value="1">
                <input type="hidden" name="submission_id" id="archiveConfirmSubmissionId" value="">
                <input type="hidden" name="archive_title" id="archiveConfirmTitleInput" value="">
                <input type="hidden" name="force_archive" id="archiveConfirmForce" value="0">
                <p class="mb-2" id="archiveConfirmMessage"></p>
                <div class="fw-semibold" id="archiveConfirmTitle"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No</button>
                <button type="submit" class="btn btn-success">Yes, Archive Now</button>
            </div>
        </form>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modalEl = document.getElementById('archiveConfirmModal');
        if (!modalEl) {
            return;
        }
        const modal = new bootstrap.Modal(modalEl);
        const submissionInput = document.getElementById('archiveConfirmSubmissionId');
        const titleInput = document.getElementById('archiveConfirmTitleInput');
        const forceInput = document.getElementById('archiveConfirmForce');
        const messageEl = document.getElementById('archiveConfirmMessage');
        const titleEl = document.getElementById('archiveConfirmTitle');

        document.querySelectorAll('.archive-trigger').forEach((button) => {
            button.addEventListener('click', () => {
                const submissionId = button.getAttribute('data-submission-id') || '';
                const title = button.getAttribute('data-title') || 'Research document';
                const eligible = button.getAttribute('data-eligible') === '1';
                const eligibleLabel = button.getAttribute('data-eligible-label') || 'N/A';

                submissionInput.value = submissionId;
                titleInput.value = title;
                titleEl.textContent = title;

                if (eligible) {
                    messageEl.textContent = 'This file is eligible for archiving. Would you like to archive it now?';
                    forceInput.value = '0';
                } else {
                    messageEl.textContent = `This file has not reached the 5-year retention period (eligible on ${eligibleLabel}). Would you like to archive it now?`;
                    forceInput.value = '1';
                }

                modal.show();
            });
        });
    });
</script>
</body>
</html>
