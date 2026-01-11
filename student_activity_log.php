<?php
session_start();
require_once "db.php";
require_once 'submission_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: login.php");
    exit;
}

$studentId = (int)($_SESSION['user_id'] ?? 0);
$logs = [];
$statusTotals = [
    'Approved' => 0,
    'Pending' => 0,
    'Reviewing' => 0,
    'Reviewer Assigning' => 0,
    'Revision Required' => 0,
    'Rejected' => 0,
];

$logStmt = $conn->prepare(
    "SELECT l.id, l.old_status, l.new_status, l.changed_at,
            s.title, s.type,
            updater.firstname, updater.lastname, updater.role AS updater_role
     FROM status_logs l
     JOIN submissions s ON l.submission_id = s.id
     LEFT JOIN users updater ON l.updated_by = updater.id
     WHERE s.student_id = ?
     ORDER BY l.changed_at DESC"
);

if ($logStmt) {
    $logStmt->bind_param('i', $studentId);
    if ($logStmt->execute()) {
        $result = $logStmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
                $status = $row['new_status'] ?? 'Pending';
                $normalizedStatus = normalize_submission_status($status);
                if (isset($statusTotals[$normalizedStatus])) {
                    $statusTotals[$normalizedStatus]++;
                }
            }
        }
    }
    $logStmt->close();
}

$totalLogs = count($logs);
$lastUpdated = $totalLogs > 0 ? $logs[0]['changed_at'] : null;

function statusBadgeClass(string $status): string {
    $normalized = normalize_submission_status($status);
    return match ($normalized) {
        'Approved' => 'bg-success-subtle text-success',
        'Pending' => 'bg-secondary-subtle text-secondary',
        'Reviewing' => 'bg-warning-subtle text-warning-emphasis',
        'Reviewer Assigning' => 'bg-info-subtle text-info',
        'Revision Required' => 'bg-info-subtle text-info',
        'Rejected' => 'bg-danger-subtle text-danger',
        default => 'bg-secondary-subtle text-secondary',
    };
}

function formatRole(?string $role): string {
    return match ($role) {
        'program_chairperson' => 'Program Chairperson',
        'committee_chairperson' => 'Committee Chair',
        'committee_chair' => 'Committee Chair',
        'adviser' => 'Adviser',
        'panel' => 'Panel Member',
        'dean' => 'Dean',
        'student' => 'Student',
        default => 'System',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activity Log - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .timeline-card { border-radius: 18px; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.08); border: none; }
    .timeline-header { background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; padding: 1.1rem 1.6rem; border-radius: 18px 18px 0 0; }
    .timeline-list { list-style: none; margin: 0; padding: 1.5rem; background: #fff; color: #1b1f24; }
    .timeline-item { position: relative; padding-left: 2.4rem; padding-bottom: 1.5rem; border-left: 2px solid rgba(22, 86, 44, 0.12); }
        .timeline-item:last-child { border-left-color: transparent; padding-bottom: 0; }
        .timeline-dot { position: absolute; left: -10px; top: 6px; width: 18px; height: 18px; border-radius: 50%; background: #fff; border: 3px solid #16562c; box-shadow: 0 0 0 3px rgba(22, 86, 44, 0.15); }
        .log-card { background: #fdfdfd; border-radius: 12px; padding: 1rem 1.25rem; box-shadow: 0 12px 24px rgba(22, 86, 44, 0.08); transition: transform 0.18s ease, box-shadow 0.18s ease; color: #1b1f24; }
        .log-card:hover { transform: translateY(-2px); box-shadow: 0 16px 30px rgba(22, 86, 44, 0.12); }
        .status-pill { border-radius: 999px; font-size: 0.75rem; padding: 0.35rem 0.7rem; letter-spacing: 0.02em; }
.filter-card { border-radius: 18px; border: none; box-shadow: 0 12px 24px rgba(22, 86, 44, 0.08); background: #fff; }
.filter-card .form-control,
.filter-card .form-select { border-radius: 10px; color: #1b1f24; }
        .stat-card { border-radius: 16px; border: none; background: #fff; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.12); }
        .stat-card .card-body { color: #173d2b; }
        .stat-card small { color: #516162; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card h3 { color: #0f5c36; }
        .empty-state { text-align: center; padding: 48px 16px; color: #6c757d; }
        .empty-state i { font-size: 3rem; color: #16562c; margin-bottom: 0.75rem; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>
    <div class="content">
        <div class="container-fluid">
            <div class="page-header mb-4">
                <div>
                    <h2 class="fw-bold mb-1">Activity Log</h2>
                    <p class="text-muted mb-0">Track every status update made to your submissions.</p>
                </div>
                <a href="student_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            </div>

            <div class="row g-3 mb-4 text-dark">
                <div class="col-md-3">
                    <div class="card stat-card h-100">
                        <div class="card-body">
                            <small>Total Updates</small>
                            <h3 class="fw-bold mb-0"><?= number_format($totalLogs); ?></h3>
                            <small class="d-block mt-1 text-muted">Latest: <?= $lastUpdated ? date('M d, Y g:i A', strtotime($lastUpdated)) : 'No updates yet'; ?></small>
                        </div>
                    </div>
                </div>
                <?php foreach ($statusTotals as $label => $count): ?>
                    <div class="col-md-3">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <small><?= htmlspecialchars($label); ?></small>
                                <h3 class="fw-bold mb-0"><?= number_format($count); ?></h3>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card filter-card mb-4">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label fw-semibold">Search Activity</label>
                            <input type="search" id="searchInput" class="form-control" placeholder="Search by status, title, or reviewer...">
                        </div>
                        <div class="col-lg-3 col-md-4">
                            <label class="form-label fw-semibold">Filter by Status</label>
                            <select id="statusFilter" class="form-select">
                                <option value="">All statuses</option>
                                <option value="Pending">Pending</option>
                                <option value="Reviewing">Reviewing</option>
                                <option value="Reviewer Assigning">Reviewer Assigning</option>
                                <option value="Under Review">Under Review</option>
                                <option value="Revision Required">Revision Required</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-4">
                            <label class="form-label fw-semibold">Filter by Reviewer</label>
                            <input type="search" id="reviewerFilter" class="form-control" placeholder="Reviewer name...">
                        </div>
                        <div class="col-lg-2 col-md-4 d-grid">
                            <button type="button" id="resetFilters" class="btn btn-outline-secondary"><i class="bi bi-eraser"></i> Reset</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card timeline-card">
                <div class="timeline-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="bi bi-activity me-2"></i>Activity Timeline</h5>
                    <span class="badge bg-light text-dark px-3 py-2" id="timelineCount"><?= number_format($totalLogs); ?> updates</span>
                </div>
                <ul class="timeline-list" id="timelineList">
                    <?php if (empty($logs)): ?>
                        <li class="empty-state alert alert-info mb-0">
                            <p class="mb-0">No concept papers were found. Encourage students to submit their titles.</p>
                        </li>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                                $newStatus = $log['new_status'] ?? 'Pending';
                                $oldStatus = $log['old_status'] ?? 'Pending';
                                $title = $log['title'] ?? 'Untitled Submission';
                                $type = $log['type'] ?? 'Submission';
                                $changedAt = $log['changed_at'] ?? '';
                                $changeDate = $changedAt ? date('M d, Y', strtotime($changedAt)) : 'Unknown date';
                                $changeTime = $changedAt ? date('g:i A', strtotime($changedAt)) : '';
                                $reviewerName = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? '')) ?: 'System';
                                $reviewerRole = formatRole($log['updater_role'] ?? null);
                                $badgeClass = statusBadgeClass($newStatus);
                                $fromClass = statusBadgeClass($oldStatus);
                                $dataSearch = strtolower($newStatus . ' ' . $oldStatus . ' ' . $title . ' ' . $type . ' ' . $reviewerName . ' ' . $reviewerRole);
                            ?>
                            <li class="timeline-item" data-status="<?= htmlspecialchars($newStatus); ?>" data-reviewer="<?= htmlspecialchars(strtolower($reviewerName)); ?>" data-search="<?= htmlspecialchars($dataSearch); ?>">
                                <div class="timeline-dot"></div>
                                <div class="log-card">
                                    <div class="d-flex justify-content-between flex-wrap gap-2">
                                        <div>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($title); ?></div>
                                            <div class="text-muted small">Type: <?= htmlspecialchars($type); ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold text-muted"><?= htmlspecialchars($changeDate); ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($changeTime); ?></div>
                                        </div>
                                    </div>
                                    <hr class="my-3">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="status-pill <?= $fromClass; ?>">From <?= htmlspecialchars($oldStatus); ?></span>
                                            <i class="bi bi-arrow-right text-muted"></i>
                                            <span class="status-pill <?= $badgeClass; ?>">To <?= htmlspecialchars($newStatus); ?></span>
                                        </div>
                                        <span class="badge bg-light text-dark border">
                                            <i class="bi bi-person-badge me-1"></i>
                                            <?= htmlspecialchars($reviewerName); ?>
                                            <span class="text-muted">� <?= htmlspecialchars($reviewerRole); ?></span>
                                        </span>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const timelineList = document.getElementById('timelineList');
            if (!timelineList) return;

            const searchInput = document.getElementById('searchInput');
            const statusFilter = document.getElementById('statusFilter');
            const reviewerFilter = document.getElementById('reviewerFilter');
            const resetBtn = document.getElementById('resetFilters');
            const timelineCount = document.getElementById('timelineCount');
            const items = Array.from(timelineList.querySelectorAll('.timeline-item'));

            const applyFilters = () => {
                const searchValue = (searchInput?.value || '').toLowerCase();
                const statusValue = (statusFilter?.value || '').toLowerCase();
                const reviewerValue = (reviewerFilter?.value || '').toLowerCase();
                let visible = 0;

                items.forEach((item) => {
                    const matchesStatus = !statusValue || (item.dataset.status || '').toLowerCase() === statusValue;
                    const matchesReviewer = !reviewerValue || (item.dataset.reviewer || '').includes(reviewerValue);
                    const matchesSearch = !searchValue || (item.dataset.search || '').includes(searchValue);
                    const shouldShow = matchesStatus && matchesReviewer && matchesSearch;
                    item.style.display = shouldShow ? '' : 'none';
                    if (shouldShow) visible++;
                });

                const emptyState = timelineList.querySelector('.empty-state');
                if (emptyState) {
                    emptyState.style.display = visible === 0 ? '' : 'none';
                }

                if (timelineCount) {
                    timelineCount.textContent = visible + ' update' + (visible === 1 ? '' : 's');
                }
            };

            searchInput?.addEventListener('input', applyFilters);
            statusFilter?.addEventListener('change', applyFilters);
            reviewerFilter?.addEventListener('input', applyFilters);
            resetBtn?.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                if (statusFilter) statusFilter.value = '';
                if (reviewerFilter) reviewerFilter.value = '';
                applyFilters();
            });

            applyFilters();
        })();
    </script>
</body>
</html>
