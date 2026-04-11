<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'submission_helpers.php';
enforce_role_access(['dean']);

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

$submissionRows = [];
$submissionResult = $conn->query("SELECT status, type, created_at FROM submissions");
if ($submissionResult) {
    $submissionRows = $submissionResult->fetch_all(MYSQLI_ASSOC);
    $submissionResult->free();
}

$statusLabels = submission_allowed_statuses();
$statusTotals = array_fill_keys($statusLabels, 0);
$statusTotals['Other'] = 0;
$typeTotals = [];
$totalSubmissions = 0;
$needsAttentionStatuses = ['Pending', 'Reviewing', 'Reviewer Assigning', 'Revision Required'];
$needsAttentionCount = 0;
$recentSubmissionsCount = 0;
$createdAtCutoff = new DateTime('-30 days');
$hasCreatedAt = submissions_column_exists($conn, 'created_at');

foreach ($submissionRows as $row) {
    $totalSubmissions++;
    $status = normalize_submission_status($row['status'] ?? '');
    if (isset($statusTotals[$status])) {
        $statusTotals[$status]++;
    } else {
        $statusTotals['Other']++;
    }
    if (in_array($status, $needsAttentionStatuses, true)) {
        $needsAttentionCount++;
    }

    $type = trim((string)($row['type'] ?? ''));
    $typeLabel = $type !== '' ? $type : 'Unspecified';
    $typeTotals[$typeLabel] = ($typeTotals[$typeLabel] ?? 0) + 1;

    if ($hasCreatedAt && !empty($row['created_at'])) {
        $createdAt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$row['created_at'])
            ?: new DateTime((string)$row['created_at']);
        if ($createdAt >= $createdAtCutoff) {
            $recentSubmissionsCount++;
        }
    }
}

arsort($typeTotals);
$typeLabels = array_keys($typeTotals);
$typeData = array_values($typeTotals);
if (count($typeLabels) > 6) {
    $topLabels = array_slice($typeLabels, 0, 5);
    $topData = array_slice($typeData, 0, 5);
    $otherSum = array_sum(array_slice($typeData, 5));
    $typeLabels = array_merge($topLabels, ['Other']);
    $typeData = array_merge($topData, [$otherSum]);
}

// Activity for last 7 days
$activityMap = [];
$activityStmt = $conn->prepare("
    SELECT DATE(changed_at) AS day, COUNT(*) AS total
    FROM status_logs
    WHERE changed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(changed_at)
");
if ($activityStmt && $activityStmt->execute()) {
    $activityResult = $activityStmt->get_result();
    if ($activityResult) {
        while ($row = $activityResult->fetch_assoc()) {
            $activityMap[$row['day']] = (int)$row['total'];
        }
    }
    $activityStmt->close();
}

$activityLabels = [];
$activityData = [];
$activityTotal = 0;
$today = new DateTime('today');
for ($i = 6; $i >= 0; $i--) {
    $day = (clone $today)->modify("-{$i} days");
    $key = $day->format('Y-m-d');
    $activityLabels[] = $day->format('M d');
    $count = $activityMap[$key] ?? 0;
    $activityData[] = $count;
    $activityTotal += $count;
}

// Approvals in the last 30 days (based on status logs)
$approvedRecentCount = 0;
$approvalStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM status_logs
    WHERE new_status = 'Approved'
      AND changed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
if ($approvalStmt && $approvalStmt->execute()) {
    $approvalResult = $approvalStmt->get_result();
    if ($approvalResult) {
        $approvedRecentCount = (int)($approvalResult->fetch_assoc()['total'] ?? 0);
    }
    $approvalStmt->close();
}

// Upcoming defenses (next 30 days)
$defenseStatusTotals = [];
$upcomingDefenseTotal = 0;
$hasDefenseSchedule = table_exists($conn, 'defense_schedules');
if ($hasDefenseSchedule) {
    $defenseStmt = $conn->prepare("
        SELECT status, COUNT(*) AS total
        FROM defense_schedules
        WHERE defense_date >= CURDATE()
          AND defense_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        GROUP BY status
    ");
    if ($defenseStmt && $defenseStmt->execute()) {
        $defenseResult = $defenseStmt->get_result();
        if ($defenseResult) {
            while ($row = $defenseResult->fetch_assoc()) {
                $label = trim((string)($row['status'] ?? 'Unspecified'));
                if ($label === '') {
                    $label = 'Unspecified';
                }
                $defenseStatusTotals[$label] = (int)$row['total'];
                $upcomingDefenseTotal += (int)$row['total'];
            }
        }
        $defenseStmt->close();
    }
}

$defenseLabels = array_keys($defenseStatusTotals);
$defenseData = array_values($defenseStatusTotals);

$statusChartLabels = array_values(array_filter($statusLabels, fn($label) => $label !== 'Returned'));
$statusChartData = array_map(fn($label) => $statusTotals[$label] ?? 0, $statusChartLabels);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dean Summary Reports - DNSC IAdS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --report-green: #16562c;
      --report-green-dark: #0f3d1f;
      --report-gold: #f9b234;
      --report-soft: #f4f8f4;
      --report-ink: #1e2c23;
      --report-muted: #6a7b6f;
    }
    body {
      background: radial-gradient(circle at top right, rgba(22, 86, 44, 0.08), transparent 45%), #f7faf8;
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
      color: var(--report-ink);
    }
    .content {
      margin-left: 220px;
      padding: 32px;
      min-height: 100vh;
      transition: margin-left .3s ease;
    }
    #sidebar.collapsed ~ .content { margin-left: 60px; }
    .page-header {
      display: flex;
      justify-content: space-between;
      gap: 1.5rem;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 1.75rem;
    }
    .page-title h1 {
      font-weight: 700;
      color: var(--report-green-dark);
    }
    .page-title p {
      margin: 0;
      color: var(--report-muted);
    }
    .action-buttons .btn {
      border-radius: 999px;
      padding-inline: 1.3rem;
    }
    .report-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.25rem;
    }
    .report-card {
      background: #fff;
      border-radius: 20px;
      padding: 1.25rem 1.4rem;
      box-shadow: 0 18px 36px rgba(22, 86, 44, 0.08);
      border: 1px solid rgba(22, 86, 44, 0.08);
      position: relative;
      overflow: hidden;
    }
    .report-card::after {
      content: "";
      position: absolute;
      inset: 0;
      opacity: 0.1;
      background: radial-gradient(circle at 80% 0%, var(--report-green), transparent 55%);
      pointer-events: none;
    }
    .report-card > * { position: relative; z-index: 1; }
    .card-label {
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-size: 0.7rem;
      color: var(--report-muted);
      font-weight: 600;
    }
    .card-value {
      font-size: 2rem;
      font-weight: 700;
      margin: 0.3rem 0 0.4rem;
    }
    .card-subtext {
      color: var(--report-muted);
      font-size: 0.85rem;
      margin: 0;
    }
    .chart-card {
      min-height: 300px;
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    .chart-title {
      font-weight: 600;
      color: var(--report-green-dark);
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin: 0;
    }
    .chart-wrap {
      flex: 1;
      min-height: 180px;
    }
    .chart-empty {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 180px;
      border-radius: 14px;
      background: var(--report-soft);
      color: var(--report-muted);
      font-size: 0.9rem;
    }
    .badge-soft {
      background: rgba(22, 86, 44, 0.1);
      color: var(--report-green-dark);
      border-radius: 999px;
      padding: 0.2rem 0.75rem;
      font-size: 0.75rem;
      font-weight: 600;
    }
    @media (max-width: 992px) {
      .content { margin-left: 0; padding: 20px; }
    }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
  <div class="page-header">
    <div class="page-title">
      <h1 class="mb-2">Summary Reports</h1>
      <p>At-a-glance insights for submissions, activity, and upcoming defenses.</p>
    </div>
    <div class="action-buttons d-flex gap-2">
      <a href="dean.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Back to Dashboard
      </a>
      <button type="button" class="btn btn-outline-primary" onclick="window.print()">
        <i class="bi bi-printer"></i> Export / Print
      </button>
    </div>
  </div>

  <div class="report-grid mb-4">
    <div class="report-card">
      <div class="card-label">Total Submissions</div>
      <div class="card-value"><?= number_format($totalSubmissions); ?></div>
      <p class="card-subtext">All submissions across the system.</p>
    </div>
    <div class="report-card">
      <div class="card-label">Needs Attention</div>
      <div class="card-value text-warning"><?= number_format($needsAttentionCount); ?></div>
      <p class="card-subtext">Pending, reviewing, or revisions required.</p>
    </div>
    <div class="report-card">
      <div class="card-label">Activity (Last 7 Days)</div>
      <div class="card-value text-success"><?= number_format($approvedRecentCount); ?></div>
      <p class="card-subtext">Approved submissions in the last 30 days.</p>
    </div>
    <div class="report-card">
      <div class="card-label">New Submissions (30 Days)</div>
      <div class="card-value"><?= number_format($recentSubmissionsCount); ?></div>
      <p class="card-subtext">Recently submitted records.</p>
    </div>
  </div>

  <div class="report-grid">
    <div class="report-card chart-card">
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="chart-title"><i class="bi bi-pie-chart"></i> Status Breakdown</h6>
        <span class="badge-soft">Current pipeline</span>
      </div>
      <div class="chart-wrap">
        <canvas id="statusChart"></canvas>
      </div>
    </div>
    <div class="report-card chart-card">
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="chart-title"><i class="bi bi-bar-chart"></i> Submissions by Type</h6>
        <span class="badge-soft"><?= count($typeLabels); ?> types</span>
      </div>
      <div class="chart-wrap">
        <canvas id="typeChart"></canvas>
      </div>
    </div>
    <div class="report-card chart-card">
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="chart-title"><i class="bi bi-activity"></i> Activity Trend</h6>
        <span class="badge-soft">Last 7 days</span>
      </div>
      <div class="chart-wrap">
        <canvas id="activityChart"></canvas>
      </div>
    </div>
    <div class="report-card chart-card">
      <div class="d-flex align-items-center justify-content-between">
        <h6 class="chart-title"><i class="bi bi-calendar-event"></i> Upcoming Defenses</h6>
        <span class="badge-soft"><?= $hasDefenseSchedule ? number_format($upcomingDefenseTotal) . ' scheduled' : 'No data'; ?></span>
      </div>
      <div class="chart-wrap">
        <?php if (!$hasDefenseSchedule): ?>
          <div class="chart-empty">Defense schedule data not available.</div>
        <?php elseif ($upcomingDefenseTotal === 0): ?>
          <div class="chart-empty">No defenses scheduled in the next 30 days.</div>
        <?php else: ?>
          <canvas id="defenseChart"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const statusLabels = <?= json_encode($statusChartLabels); ?>;
  const statusData = <?= json_encode($statusChartData); ?>;
  const typeLabels = <?= json_encode($typeLabels); ?>;
  const typeData = <?= json_encode($typeData); ?>;
  const activityLabels = <?= json_encode($activityLabels); ?>;
  const activityData = <?= json_encode($activityData); ?>;
  const defenseLabels = <?= json_encode($defenseLabels); ?>;
  const defenseData = <?= json_encode($defenseData); ?>;

  const chartColors = [
    '#16562c',
    '#4c9f70',
    '#f9b234',
    '#e07a5f',
    '#588157',
    '#8d99ae',
    '#3d5a80'
  ];

  const statusCtx = document.getElementById('statusChart');
  if (statusCtx) {
    new Chart(statusCtx, {
      type: 'doughnut',
      data: {
        labels: statusLabels,
        datasets: [{
          data: statusData,
          backgroundColor: chartColors,
          borderWidth: 0,
        }]
      },
      options: {
        plugins: {
          legend: { position: 'bottom' }
        },
        cutout: '68%'
      }
    });
  }

  const typeCtx = document.getElementById('typeChart');
  if (typeCtx) {
    new Chart(typeCtx, {
      type: 'bar',
      data: {
        labels: typeLabels,
        datasets: [{
          label: 'Submissions',
          data: typeData,
          backgroundColor: chartColors,
          borderRadius: 8,
        }]
      },
      options: {
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true }
        }
      }
    });
  }

  const activityCtx = document.getElementById('activityChart');
  if (activityCtx) {
    new Chart(activityCtx, {
      type: 'line',
      data: {
        labels: activityLabels,
        datasets: [{
          label: 'Status Changes',
          data: activityData,
          borderColor: '#16562c',
          backgroundColor: 'rgba(22, 86, 44, 0.15)',
          fill: true,
          tension: 0.4
        }]
      },
      options: {
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true }
        }
      }
    });
  }

  const defenseCtx = document.getElementById('defenseChart');
  if (defenseCtx) {
    new Chart(defenseCtx, {
      type: 'doughnut',
      data: {
        labels: defenseLabels,
        datasets: [{
          data: defenseData,
          backgroundColor: chartColors,
          borderWidth: 0
        }]
      },
      options: {
        plugins: {
          legend: { position: 'bottom' }
        },
        cutout: '70%'
      }
    });
  }
</script>
</body>
</html>
