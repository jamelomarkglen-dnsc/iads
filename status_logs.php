<?php
session_start();
require_once 'db.php';

// ✅ Restrict access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'program_chairperson') {
    header("Location: login.php");
    exit;
}

// Fetch logs
$sql = "
    SELECT l.id, l.old_status, l.new_status, l.changed_at,
           s.title, s.type,
           u.firstname, u.lastname, u.email
    FROM status_logs l
    JOIN submissions s ON l.submission_id = s.id
    JOIN users u ON l.updated_by = u.id
    ORDER BY l.changed_at DESC
";
$result = $conn->query($sql);
$logs = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Status Update Logs - DNSC IAdS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    body { background: #f6f8fb; }
    .content {
      margin-left: 220px;
      padding: 24px;
      background: #f6f8fb;
      min-height: 100vh;
      transition: margin-left .3s;
    }
    #sidebar.collapsed~.content { margin-left: 60px; }
    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }
    .page-title {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      color: #134e27;
    }
    .page-title i {
      font-size: 2.4rem;
      color: #16562c;
    }
    .action-region {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .action-region .btn {
      border-radius: 999px;
      padding-inline: 1.3rem;
    }
    .filters-card {
      border-radius: 18px;
      border: none;
      box-shadow: 0 12px 24px rgba(22, 86, 44, 0.08);
      background: #ffffff;
    }
    .filters-card .form-label {
      font-weight: 600;
      color: #16562c;
    }
    .filters-card .form-select,
    .filters-card .form-control {
      border-radius: 12px;
      border-color: rgba(22, 86, 44, 0.2);
    }
    .filters-card .form-select:focus,
    .filters-card .form-control:focus {
      border-color: #16562c;
      box-shadow: 0 0 0 0.2rem rgba(22, 86, 44, 0.15);
    }
    .timeline-card {
      border-radius: 18px;
      border: none;
      overflow: hidden;
      box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12);
    }
    .timeline-header {
      background: linear-gradient(135deg, #16562c, #0f3d1f);
      color: #fff;
      padding: 1.25rem 1.75rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 0.75rem;
    }
    .timeline-header h5 {
      margin: 0;
      font-weight: 600;
    }
    .timeline-list {
      list-style: none;
      margin: 0;
      padding: 1.75rem;
      background: #ffffff;
    }
    .timeline-item {
      position: relative;
      padding-left: 2.5rem;
      padding-bottom: 1.75rem;
      border-left: 2px solid rgba(22, 86, 44, 0.15);
    }
    .timeline-item:last-child { padding-bottom: 0; border-left-color: transparent; }
    .timeline-dot {
      position: absolute;
      left: -9px;
      top: 6px;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: #ffffff;
      border: 3px solid #16562c;
      box-shadow: 0 0 0 3px rgba(22, 86, 44, 0.15);
    }
    .log-card {
      background: #fdfdfd;
      border-radius: 12px;
      padding: 1rem 1.25rem;
      box-shadow: 0 12px 24px rgba(22, 86, 44, 0.08);
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }
    .log-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 16px 30px rgba(22, 86, 44, 0.12);
    }
    .log-header {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }
    .log-title {
      font-weight: 600;
      color: #134e27;
    }
    .log-meta {
      font-size: 0.85rem;
      color: #6c757d;
    }
    .status-pill {
      border-radius: 999px;
      font-size: 0.75rem;
      padding: 0.35rem 0.7rem;
      letter-spacing: 0.02em;
    }
    .user-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: rgba(22, 86, 44, 0.08);
      border-radius: 999px;
      padding: 0.35rem 0.9rem;
      color: #16562c;
      font-weight: 500;
    }
    .user-badge i { color: #16562c; }
    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: #6c757d;
    }
    .empty-state i {
      font-size: 3rem;
      color: #16562c;
      margin-bottom: 0.75rem;
    }
    @media (max-width: 992px) {
      .content { padding: 18px; }
      .filters-card { margin-bottom: 1rem; }
      .page-header { align-items: flex-start; }
      .action-region { width: 100%; justify-content: flex-start; }
    }
  </style>
</head>

<body>
  <?php include 'header.php'; ?>
  <?php include 'sidebar.php'; ?>

  <div class="content">
    <div class="container my-4">
      <div class="page-header">
        <div class="page-title">
          <i class="bi bi-clock-history"></i>
          <div>
            <h3 class="fw-bold mb-1">Status Update Logs</h3>
            <p class="text-muted mb-0">Track every status transition across student submissions.</p>
          </div>
        </div>
        <div class="action-region">
          <a href="submissions.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Submissions
          </a>
          <button type="button" class="btn btn-outline-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Export / Print
          </button>
        </div>
      </div>

      <div class="card filters-card mb-4">
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-lg-4 col-md-6">
              <label for="statusFilter" class="form-label">Filter by New Status</label>
              <select id="statusFilter" class="form-select">
                <option value="">All statuses</option>
                <option value="Approved">Approved</option>
                <option value="Pending">Pending</option>
                <option value="Reviewing">Reviewing</option>
                <option value="Reviewer Assigning">Reviewer Assigning</option>
                <option value="In Review">In Review</option>
                <option value="Assigning Reviewer">Assigning Reviewer</option>
                <option value="Under Review">Under Review</option>
                <option value="Revision Required">Revision Required</option>
                <option value="Rejected">Rejected</option>
              </select>
            </div>
            <div class="col-lg-4 col-md-6">
              <label for="typeFilter" class="form-label">Filter by Submission Type</label>
              <select id="typeFilter" class="form-select">
                <option value="">All types</option>
                <?php
                  $types = array_unique(array_map(fn($log) => $log['type'] ?? '', $logs));
                  sort($types);
                  foreach ($types as $typeOption):
                    if ($typeOption === '') continue;
                ?>
                  <option value="<?= htmlspecialchars($typeOption); ?>"><?= htmlspecialchars($typeOption); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-4">
              <label for="searchInput" class="form-label">Search</label>
              <input type="search" id="searchInput" class="form-control" placeholder="Search by submission title or reviewer...">
            </div>
          </div>
        </div>
      </div>

      <div class="card timeline-card">
        <div class="timeline-header">
          <h5><i class="bi bi-activity me-2"></i>Status Change Timeline</h5>
          <span class="badge bg-light text-dark px-3 py-2"><?= count($logs); ?> entries</span>
        </div>
        <ul class="timeline-list" id="timelineList">
          <?php if (count($logs) === 0): ?>
            <li class="empty-state">
              <i class="bi bi-inboxes"></i>
              <p class="mb-0">No status changes logged yet. Updates you make from the submissions page will appear here.</p>
            </li>
          <?php else: foreach ($logs as $log):
              $timestamp = strtotime($log['changed_at'] ?? '');
              $dateLabel = $timestamp ? date("M d, Y", $timestamp) : 'Unknown date';
              $timeLabel = $timestamp ? date("g:i A", $timestamp) : '';
              $newStatus = $log['new_status'] ?? '';
              $oldStatus = $log['old_status'] ?? '';
              $statusClasses = [
                  'Approved' => 'bg-success-subtle text-success',
                  'Pending' => 'bg-secondary-subtle text-secondary',
                  'Reviewing' => 'bg-warning-subtle text-warning-emphasis',
                  'In Review' => 'bg-warning-subtle text-warning-emphasis',
                  'Under Review' => 'bg-warning-subtle text-warning-emphasis',
                  'Reviewer Assigning' => 'bg-info-subtle text-info',
                  'Assigning Reviewer' => 'bg-info-subtle text-info',
                  'Revision Required' => 'bg-info-subtle text-info',
                  'Rejected' => 'bg-danger-subtle text-danger',
              ];
              $newStatusClass = $statusClasses[$newStatus] ?? 'bg-secondary-subtle text-secondary';
              $oldStatusClass = $statusClasses[$oldStatus] ?? 'bg-secondary-subtle text-secondary';
              $title = $log['title'] ?? 'Untitled Submission';
              $type = $log['type'] ?? 'Submission';
              $updatedBy = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? '')) ?: 'Unknown user';
              $email = $log['email'] ?? '';
          ?>
            <li class="timeline-item" data-status="<?= htmlspecialchars($newStatus); ?>" data-type="<?= htmlspecialchars($type); ?>" data-search="<?= htmlspecialchars(strtolower($title . ' ' . $updatedBy)); ?>">
              <div class="timeline-dot"></div>
              <div class="log-card">
                <div class="log-header">
                  <div>
                    <div class="log-title"><?= htmlspecialchars($title); ?></div>
                    <div class="log-meta"><?= htmlspecialchars($type); ?></div>
                  </div>
                  <div class="text-end">
                    <div class="log-meta"><?= htmlspecialchars($dateLabel); ?></div>
                    <?php if ($timeLabel !== ''): ?>
                      <div class="log-meta"><?= htmlspecialchars($timeLabel); ?></div>
                    <?php endif; ?>
                  </div>
                </div>
                <hr class="my-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="status-pill <?= $oldStatusClass; ?>">From <?= htmlspecialchars($oldStatus ?: 'N/A'); ?></span>
                    <i class="bi bi-arrow-right text-muted"></i>
                    <span class="status-pill <?= $newStatusClass; ?>">To <?= htmlspecialchars($newStatus ?: 'N/A'); ?></span>
                  </div>
                  <span class="user-badge">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($updatedBy); ?>
                    <?php if ($email !== ''): ?>
                      <span class="text-muted">· <?= htmlspecialchars($email); ?></span>
                    <?php endif; ?>
                  </span>
                </div>
              </div>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function () {
      const list = document.getElementById('timelineList');
      if (!list) return;

      const statusFilter = document.getElementById('statusFilter');
      const typeFilter = document.getElementById('typeFilter');
      const searchInput = document.getElementById('searchInput');
      const items = Array.from(list.querySelectorAll('.timeline-item'));

      const handleFilters = () => {
        const statusValue = (statusFilter?.value || '').toLowerCase();
        const typeValue = (typeFilter?.value || '').toLowerCase();
        const searchValue = (searchInput?.value || '').toLowerCase();
        let visibleCount = 0;

        items.forEach((item) => {
          const matchesStatus = !statusValue || (item.dataset.status || '').toLowerCase() === statusValue;
          const matchesType = !typeValue || (item.dataset.type || '').toLowerCase() === typeValue;
          const matchesSearch = !searchValue || (item.dataset.search || '').includes(searchValue);
          const shouldShow = matchesStatus && matchesType && matchesSearch;
          item.style.display = shouldShow ? '' : 'none';
          if (shouldShow) visibleCount++;
        });

        const emptyState = list.querySelector('.empty-state');
        if (emptyState) {
          emptyState.style.display = visibleCount === 0 ? '' : 'none';
        }
      };

      statusFilter?.addEventListener('change', handleFilters);
      typeFilter?.addEventListener('change', handleFilters);
      searchInput?.addEventListener('input', handleFilters);

      handleFilters();
    })();
  </script>
</body>
</html>
