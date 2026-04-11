<?php
session_start();
include 'db.php';
require_once 'role_helpers.php';
enforce_role_access(['dean']);

// Fetch Program Chairpersons
$sql = "SELECT id, firstname, lastname, email, contact, position, department FROM users WHERE role='program_chairperson'";
$result = $conn->query($sql);

// Statistics
$total_chairs = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role='program_chairperson'")->fetch_assoc()['count'];
$total_students = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role='student'")->fetch_assoc()['count'];
$total_advisers = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role='faculty'")->fetch_assoc()['count'];

// Recent activity logs (system-wide)
$recent_logs = [];
$recent_limit = 8;
$recentStmt = $conn->prepare(
    "SELECT l.id, l.old_status, l.new_status, l.changed_at,
            s.title, s.type,
            u.firstname, u.lastname
     FROM status_logs l
     JOIN submissions s ON l.submission_id = s.id
     LEFT JOIN users u ON l.updated_by = u.id
     ORDER BY l.changed_at DESC
     LIMIT ?"
);
if ($recentStmt) {
    $recentStmt->bind_param('i', $recent_limit);
    if ($recentStmt->execute()) {
        $recentResult = $recentStmt->get_result();
        if ($recentResult) {
            $recent_logs = $recentResult->fetch_all(MYSQLI_ASSOC);
        }
    }
    $recentStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dean Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://kit.fontawesome.com/a2e0a1e6d3.js" crossorigin="anonymous"></script>
  <style>
    :root {
      --dnsc-green: #16562C;
      --dnsc-green-dark: #0f3e1f;
      --dnsc-gold: #f9b234;
      --dnsc-soft: #f4f8f4;
    }
    body {
      background: linear-gradient(135deg, #eff6f0, #fdfefd);
      font-family: 'Inter', sans-serif;
      color: #27372b;
    }
    .content {
      margin-left: var(--sidebar-width-expanded, 240px);
      padding: 2.5rem;
      min-height: 100vh;
      transition: margin-left .3s ease;
    }
    #sidebar.collapsed ~ .content {
      margin-left: var(--sidebar-width-collapsed, 84px);
    }
    .dashboard-wrapper {
      max-width: 1200px;
      margin: 0 auto;
    }
    .hero-card {
      background: radial-gradient(circle at top right, rgba(255,255,255,.2), transparent), linear-gradient(130deg, #16562c, #0f3e1f);
      border-radius: 24px;
      padding: 2.5rem;
      color: #fff;
      box-shadow: 0 30px 60px rgba(15, 61, 31, 0.22);
      position: relative;
      overflow: hidden;
    }
    .hero-card::after {
      content: '';
      position: absolute;
      width: 320px;
      height: 320px;
      border-radius: 50%;
      background: rgba(255,255,255,0.08);
      top: -140px;
      right: -80px;
    }
    .hero-card::before {
      content: '';
      position: absolute;
      width: 200px;
      height: 200px;
      border-radius: 50%;
      background: rgba(255,255,255,0.04);
      bottom: -80px;
      left: -40px;
    }
    .hero-card > * {
      position: relative;
      z-index: 2;
    }
    .hero-badge {
      background: rgba(255,255,255,0.15);
      padding: .35rem .9rem;
      border-radius: 999px;
      font-size: .75rem;
      letter-spacing: .1em;
      text-transform: uppercase;
      display: inline-flex;
      align-items: center;
      gap: .35rem;
    }
    .hero-metrics {
      gap: 1rem;
      flex-wrap: wrap;
    }
    .hero-metric {
      min-width: 150px;
      padding: 0.95rem 1.1rem;
      border-radius: 18px;
      background: rgba(255,255,255,0.14);
      border: 1px solid rgba(255,255,255,0.18);
      color: #fff;
      flex: 1 1 160px;
    }
    .hero-metric span {
      display: block;
      font-size: .75rem;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.7);
    }
    .hero-metric strong {
      font-size: 1.8rem;
      font-weight: 700;
    }
    .hero-subtext {
      color: rgba(255,255,255,0.92);
      letter-spacing: 0.03em;
      font-weight: 500;
    }
    .stat-card {
      background: #fff;
      border-radius: 18px;
      padding: 1.4rem;
      box-shadow: 0 18px 32px rgba(15, 61, 31, 0.08);
      border: 1px solid rgba(22, 86, 44, 0.1);
      height: 100%;
      position: relative;
      overflow: hidden;
    }
    .stat-card::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(140deg, rgba(22, 86, 44, 0.06), transparent 55%);
      pointer-events: none;
    }
    .icon-pill {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(22, 86, 44, 0.12);
      color: var(--dnsc-green);
      font-size: 1.4rem;
      margin-bottom: 1rem;
    }
    .stat-title {
      font-size: .85rem;
      letter-spacing: .08em;
      color: #4f6456;
      text-transform: uppercase;
      margin-bottom: .35rem;
      font-weight: 600;
    }
    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: #1a2a20;
    }
    .filter-card,
    .list-card {
      border-radius: 20px;
      border: 1px solid rgba(22, 86, 44, 0.1);
      box-shadow: 0 14px 30px rgba(15,61,31,0.08);
      background: #fff;
    }
    .filter-card label {
      color: #304736;
      font-weight: 600;
      letter-spacing: .06em;
    }
    .filter-card .form-control {
      border-radius: 999px;
      border-color: rgba(22, 86, 44, 0.2);
      padding: .65rem 1.15rem;
      color: #1e2c23;
    }
    .filter-card .form-control:focus {
      box-shadow: 0 0 0 .2rem rgba(22,86,44,.15);
      border-color: var(--dnsc-green);
    }
    .filter-card .btn {
      border-radius: 999px;
      padding: .65rem 1.35rem;
    }
    .list-card .card-header {
      background: transparent;
      border-bottom: 1px solid rgba(22, 86, 44, 0.08);
      padding: 1.5rem 1.5rem 1rem;
    }
    .chair-list-item {
      padding: 1.1rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid rgba(22, 86, 44, 0.08);
      transition: background .2s ease, transform .2s ease;
      flex-wrap: wrap;
      gap: .75rem;
    }
    .chair-list-item:last-child {
      border-bottom: none;
    }
    .chair-list-item:hover {
      background: var(--dnsc-soft);
      transform: translateX(3px);
    }
    .chair-list-item .chair-name {
      font-weight: 600;
      color: var(--dnsc-green-dark);
      margin-bottom: .25rem;
    }
    .chair-meta {
      color: #495b4c;
      font-size: .9rem;
    }
    .card-header .text-muted {
      color: #4a5a50 !important;
    }
    .quote-text {
      color: rgba(255,255,255,0.95);
      max-width: 520px;
    }
    .view-btn {
      border-radius: 999px;
      padding: 0.45rem 1.1rem;
      font-size: 0.9rem;
    }
    .modal-header {
      background: var(--dnsc-green);
      color: #fff;
    }
    .summary-callout {
      border-radius: 22px;
      border: 1px solid rgba(22, 86, 44, 0.12);
      box-shadow: 0 18px 36px rgba(22, 86, 44, 0.08);
      background: linear-gradient(120deg, #ffffff, #f6faf7);
    }
    .summary-callout .btn {
      border-radius: 999px;
      padding: 0.5rem 1.2rem;
    }
    .activity-item {
      padding: 1rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1rem;
      border-bottom: 1px solid rgba(22, 86, 44, 0.08);
      flex-wrap: wrap;
    }
    .activity-item:last-child { border-bottom: none; }
    .activity-title { font-weight: 600; color: #163423; }
    .activity-meta {
      color: #5b6d60;
      font-size: 0.9rem;
      margin-top: 0.15rem;
    }
    .activity-status {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
    .status-pill {
      border-radius: 999px;
      font-size: 0.75rem;
      padding: 0.35rem 0.7rem;
      letter-spacing: 0.02em;
      font-weight: 600;
    }
    @media (max-width: 991.98px) {
      .content {
        margin-left: 0;
        padding: 1.5rem;
      }
      #sidebar.collapsed ~ .content {
        margin-left: 0;
      }
      .hero-card {
        padding: 2rem;
      }
    }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>
<div class="content">
  <main class="dashboard-wrapper py-2">
    <div class="hero-card mb-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start align-items-lg-center">
        <div>
          <span class="hero-badge"><i class="fas fa-crown"></i> Dean Workspace</span>
          <h1 class="display-6 fw-bold mt-3 mb-2">Welcome, <?= htmlspecialchars($_SESSION['name'] ?? 'Dean'); ?></h1>
          <p class="lead mb-3 quote-text" id="quoteText">Supporting program chairs with clear oversight and guidance.</p>
          <p class="hero-subtext mb-0">Institute of Advanced Studies &middot; Davao del Norte State College</p>
        </div>
        <div class="hero-metrics d-flex flex-column flex-sm-row">
          <div class="hero-metric">
            <span>Chairs</span>
            <strong><?= number_format($total_chairs); ?></strong>
          </div>
          <div class="hero-metric">
            <span>Students</span>
            <strong><?= number_format($total_students); ?></strong>
          </div>
          <div class="hero-metric">
            <span>Faculty</span>
            <strong><?= number_format($total_advisers); ?></strong>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="stat-card h-100">
          <div class="icon-pill bg-success-subtle text-success"><i class="fas fa-people-group"></i></div>
          <div class="stat-title">Program Chairpersons</div>
          <div class="stat-value"><?= $total_chairs ?></div>
          <p class="text-muted small mb-0">Active across the institutes</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card h-100">
          <div class="icon-pill bg-primary-subtle text-primary"><i class="fas fa-user-graduate"></i></div>
          <div class="stat-title">Students</div>
          <div class="stat-value"><?= $total_students ?></div>
          <p class="text-muted small mb-0">Enrolled within the program</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card h-100">
          <div class="icon-pill bg-warning-subtle text-warning"><i class="fas fa-chalkboard-teacher"></i></div>
          <div class="stat-title">Faculty</div>
          <div class="stat-value"><?= $total_advisers ?></div>
          <p class="text-muted small mb-0">Faculty assigned</p>
        </div>
      </div>
    </div>

    <div class="card summary-callout mb-4">
      <div class="card-body d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
        <div>
          <p class="text-uppercase small text-muted mb-1">Summary Reports</p>
          <h5 class="mb-2 fw-semibold">Dashboard Summary Reports</h5>
          <p class="text-muted mb-0">Quick charts for pipeline status, activity trends, and upcoming defenses.</p>
        </div>
        <a href="dean_summary_reports.php" class="btn btn-success">
          <i class="fas fa-chart-line me-2"></i> Open Reports
        </a>
      </div>
    </div>

    <div class="card list-card border-0 mb-4">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <p class="text-uppercase small text-muted mb-1">Monitoring</p>
          <h5 class="mb-0 fw-semibold"><i class="fas fa-clipboard-list me-2 text-success"></i>Recent System Activity</h5>
        </div>
        <a href="dean_activity_log.php" class="btn btn-outline-success btn-sm">
          <i class="fas fa-clock me-1"></i> View All Logs
        </a>
      </div>
      <div class="list-group list-group-flush">
        <?php
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
        ?>
        <?php if (empty($recent_logs)): ?>
          <div class="p-4 text-center text-muted">
            <i class="fas fa-inbox d-block mb-2"></i>No activity logs yet.
          </div>
        <?php else: ?>
          <?php foreach ($recent_logs as $log): ?>
            <?php
              $title = $log['title'] ?? 'Untitled Submission';
              $type = $log['type'] ?? 'Submission';
              $newStatus = $log['new_status'] ?? 'Pending';
              $oldStatus = $log['old_status'] ?? 'Pending';
              $changedAt = $log['changed_at'] ?? '';
              $dateLabel = $changedAt ? date('M d, Y g:i A', strtotime($changedAt)) : 'Unknown time';
              $updatedBy = trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? '')) ?: 'System';
              $newStatusClass = $statusClasses[$newStatus] ?? 'bg-secondary-subtle text-secondary';
              $oldStatusClass = $statusClasses[$oldStatus] ?? 'bg-secondary-subtle text-secondary';
            ?>
            <div class="activity-item list-group-item">
              <div>
                <div class="activity-title"><?= htmlspecialchars($title); ?></div>
                <div class="activity-meta">
                  <?= htmlspecialchars($type); ?> &middot; Updated by <?= htmlspecialchars($updatedBy); ?> &middot; <?= htmlspecialchars($dateLabel); ?>
                </div>
              </div>
              <div class="activity-status">
                <span class="status-pill <?= $oldStatusClass; ?>">From <?= htmlspecialchars($oldStatus ?: 'N/A'); ?></span>
                <span class="status-pill <?= $newStatusClass; ?>">To <?= htmlspecialchars($newStatus ?: 'N/A'); ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card filter-card border-0 p-3 mb-4">
      <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-3">
        <div class="flex-grow-1">
          <label class="form-label text-uppercase small mb-1">Find a program chair</label>
          <input type="text" id="searchInput" class="form-control" placeholder="Search by name, department, or email">
        </div>
        <button class="btn btn-outline-success align-self-end align-self-lg-center" onclick="window.print()">
          <i class="fas fa-file-export me-2"></i>Export Snapshot
        </button>
      </div>
    </div>

    <div class="card list-card border-0">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <p class="text-uppercase small text-muted mb-1">Directory</p>
          <h5 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-success"></i>Program Chairpersons</h5>
        </div>
        <span class="badge text-bg-light text-success px-3 py-2 rounded-pill"><?= $total_chairs ?> active</span>
      </div>
      <div class="list-group list-group-flush" id="chairList">
        <?php if ($result->num_rows > 0): ?>
          <?php while ($row = $result->fetch_assoc()): ?>
            <div class="chair-list-item list-group-item">
              <div>
                <div class="chair-name"><?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="chair-meta"><?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="chair-meta"><?= htmlspecialchars($row['department'] ?? 'Department not set', ENT_QUOTES, 'UTF-8'); ?></div>
              </div>
              <button class="btn btn-outline-success view-btn"
                data-name="<?= htmlspecialchars($row['firstname'] . ' ' . $row['lastname'], ENT_QUOTES, 'UTF-8'); ?>"
                data-email="<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?>"
                data-contact="<?= htmlspecialchars($row['contact'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                data-position="<?= htmlspecialchars($row['position'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                data-department="<?= htmlspecialchars($row['department'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fas fa-eye me-1"></i> View
              </button>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="p-4 text-center text-muted">
            <i class="fas fa-user-slash d-block mb-2"></i>No chairpersons available.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Chairperson Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>Name:</strong> <span id="modalName"></span></p>
        <p><strong>Email:</strong> <span id="modalEmail"></span></p>
        <p><strong>Contact:</strong> <span id="modalContact"></span></p>
        <p><strong>Position:</strong> <span id="modalPosition"></span></p>
        <p><strong>Department:</strong> <span id="modalDept"></span></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
  const quotes = [
    "Leadership is not about being in charge. It is about taking care of those in your charge.",
    "The function of education is to teach one to think intensively and to think critically." - Martin Luther King Jr.,
    "Great leaders do not set out to be a leader; they set out to make a difference.",
    "Education is the passport to the future, for tomorrow belongs to those who prepare for it today.",
    "A good head and a good heart are always a formidable combination." - Nelson Mandela,
    "Your role as a leader is to bring out the best in others by inspiring, guiding, and empowering.",
    "True leadership is about creating more leaders, not followers.",
    "Success in education comes when we ignite the fire of curiosity in our students.",
    "Academic excellence begins with a leader who believes in possibilities.",
    "A dean's vision shapes the future of every student and faculty they guide."
  ];

  let quoteIndex = 0;
  const quoteText = document.getElementById("quoteText");

  function rotateQuotes() {
    if (!quoteText) return;
    quoteText.textContent = quotes[quoteIndex];
    quoteIndex = (quoteIndex + 1) % quotes.length;
  }

  if (quoteText) {
    rotateQuotes(); // Show the first quote on page load
    setInterval(rotateQuotes, 6000); // Rotate every 6 seconds
  }
</script>



<script>
  // Search filter
  document.getElementById('searchInput').addEventListener('input', function () {
    const filter = this.value.toLowerCase();
    document.querySelectorAll('#chairList .list-group-item').forEach(item => {
      const text = item.textContent.toLowerCase();
      item.style.display = text.includes(filter) ? '' : 'none';
    });
  });

  // View button
  document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      document.getElementById('modalName').textContent = this.dataset.name;
      document.getElementById('modalEmail').textContent = this.dataset.email;
      document.getElementById('modalContact').textContent = this.dataset.contact;
      document.getElementById('modalPosition').textContent = this.dataset.position;
      document.getElementById('modalDept').textContent = this.dataset.department;
      new bootstrap.Modal(document.getElementById('viewModal')).show();
    });
  });
</script>
</body>
</html>







