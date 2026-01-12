<?php
session_start();
require_once 'db.php';
require_once 'chair_scope_helper.php';
require_once 'submission_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'program_chairperson') {
    header("Location: login.php");
    exit;
}

$chairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $chairId);
if (!empty($chairScope['program']) && empty($_SESSION['program'])) {
    $_SESSION['program'] = $chairScope['program'];
}
if (!empty($chairScope['department']) && empty($_SESSION['department'])) {
    $_SESSION['department'] = $chairScope['department'];
}
if (!empty($chairScope['college']) && empty($_SESSION['college'])) {
    $_SESSION['college'] = $chairScope['college'];
}

[$scopeClause, $scopeTypes, $scopeParams] = build_scope_condition($chairScope, 'u');

$sql = "
  SELECT s.id, s.title, s.type, s.abstract, s.keywords, s.file_path,
         s.concept_file_1, s.concept_file_2, s.concept_file_3,
         s.status, s.created_at,
         u.firstname, u.lastname, u.email
  FROM submissions s
  JOIN users u ON s.student_id = u.id
";
if ($scopeClause !== '') {
    $sql .= " WHERE {$scopeClause}";
}
$sql .= " ORDER BY s.created_at DESC";

$submissions = [];
if ($scopeClause === '') {
    $result = $conn->query($sql);
    if ($result) {
        $submissions = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
} else {
    $stmt = $conn->prepare($sql);
    if ($stmt && bind_scope_params($stmt, $scopeTypes, $scopeParams)) {
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $submissions = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
        $stmt->close();
    }
}

$availableStatuses = [];
$availableTypes = [];
foreach ($submissions as &$submissionRow) {
    $statusKey = normalize_submission_status($submissionRow['status'] ?? '');
    $typeKey = trim((string)($submissionRow['type'] ?? ''));
    $submissionRow['status'] = $statusKey;
    if ($statusKey !== '') {
        $availableStatuses[$statusKey] = true;
    }
    if ($typeKey !== '') {
        $availableTypes[$typeKey] = true;
    }
}
unset($submissionRow);
ksort($availableStatuses);
ksort($availableTypes);
$availableStatuses = array_keys($availableStatuses);
$availableTypes = array_keys($availableTypes);

$feedbackBySubmission = [];
$submissionIds = array_filter(array_map('intval', array_column($submissions, 'id')));
if (!empty($submissionIds)) {
    $feedbackBySubmission = fetch_submission_feedback_for_submissions($conn, $submissionIds, 5);
}

function safeLower(string $text): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

function truncateText(string $text, int $length = 100): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen')) {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…';
    }
    if (strlen($text) <= $length) {
        return $text;
    }
    return rtrim(substr($text, 0, $length)) . '…';
}

function buildSearchIndex(array $submission): string
{
    $studentName = trim(($submission['firstname'] ?? '') . ' ' . ($submission['lastname'] ?? ''));
    $parts = [
        $submission['title'] ?? '',
        $submission['type'] ?? '',
        $submission['status'] ?? '',
        $submission['abstract'] ?? '',
        $submission['keywords'] ?? '',
        $submission['email'] ?? '',
        $studentName,
    ];
    return safeLower(implode(' ', array_filter($parts)));
}

function renderStatusBadge(?string $status): string
{
    $status = normalize_submission_status($status ?: 'Pending');
    $map = [
        'Approved' => 'bg-success-subtle text-success',
        'Pending' => 'bg-secondary-subtle text-secondary',
        'Reviewing' => 'bg-warning-subtle text-warning-emphasis',
        'In Review' => 'bg-warning-subtle text-warning-emphasis',
        'Under Review' => 'bg-warning-subtle text-warning-emphasis',
        'Reviewer Assigning' => 'bg-info-subtle text-info',
        'Assigning Reviewer' => 'bg-info-subtle text-info',
        'Revision Required' => 'bg-info-subtle text-info',
        'Rejected' => 'bg-danger-subtle text-danger',
        'Returned' => 'bg-warning-subtle text-warning-emphasis',
    ];
    $class = $map[$status] ?? 'bg-secondary-subtle text-secondary';
    return '<span class="badge status-badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function renderTypeBadge(?string $type): string
{
    $type = $type ?: 'Submission';
    $map = [
        'Concept Paper' => 'bg-info-subtle text-info',
        'Thesis' => 'bg-primary-subtle text-primary',
        'Dissertation' => 'bg-danger-subtle text-danger',
    ];
    $class = $map[$type] ?? 'bg-secondary-subtle text-secondary';
    return '<span class="badge type-badge ' . $class . '">' . htmlspecialchars($type) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Submissions - DNSC IAdS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link href="progchair.css" rel="stylesheet">
  <style>
    :root {
      --pc-green: #16562c;
      --pc-green-dark: #0f3d1f;
      --pc-mint: #d9f3e4;
      --pc-ink: #1c1f23;
      --pc-muted: #6c7a76;
    }
    body {
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
    }
    .content {
      margin-left: 220px;
      padding: 32px;
      transition: margin-left .3s;
      background: radial-gradient(circle at 20% 20%, rgba(22,86,44,.12), transparent 50%), #f5f7fb;
      min-height: 100vh;
    }
    #sidebar.collapsed~.content { margin-left: 60px; }
    .page-heading {
      background: #fff;
      border-radius: 20px;
      padding: 28px 32px;
      box-shadow: 0 20px 50px rgba(22, 86, 44, 0.08);
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1.5rem;
      border-left: 6px solid var(--pc-green);
    }
    .page-heading h3 {
      font-weight: 700;
      margin-bottom: 0.4rem;
      color: var(--pc-ink);
    }
    .page-heading p {
      color: var(--pc-muted);
      margin: 0;
    }
    .page-heading .btn {
      border-radius: 999px;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.25rem;
    }
    .stat-card {
      border: none;
      border-radius: 18px;
      padding: 20px 22px;
      box-shadow: 0 16px 28px rgba(13, 92, 56, 0.08);
      position: relative;
      overflow: hidden;
      background: #fff;
    }
    .stat-card::after {
      content: "";
      position: absolute;
      inset: 0;
      opacity: 0.15;
      background: radial-gradient(circle at 70% 0%, var(--pc-green), transparent 55%);
    }
    .stat-card > * { position: relative; z-index: 2; }
    .stat-card .stat-label {
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 0.75rem;
      color: var(--pc-muted);
      font-weight: 600;
    }
    .stat-card h2 {
      font-weight: 700;
      color: var(--pc-ink);
    }
    .stat-card .trend-pill {
      background: rgba(22, 86, 44, 0.08);
      color: var(--pc-green-dark);
      border-radius: 999px;
      padding: 0.2rem 0.9rem;
      font-size: 0.78rem;
      font-weight: 600;
    }
    .toolbar-card {
      border-radius: 22px;
      background: #fff;
      box-shadow: 0 20px 40px rgba(22, 86, 44, 0.08);
      border: none;
    }
    .toolbar-card .pill-label {
      font-size: 0.8rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--pc-muted);
      font-weight: 600;
      margin-bottom: 0.35rem;
    }
    .toolbar-card .form-control,
    .toolbar-card .form-select {
      border-radius: 999px;
      padding: 0.75rem 1.2rem;
      border-color: rgba(22, 86, 44, 0.2);
    }
    .toolbar-card .input-group-text {
      border-radius: 999px 0 0 999px;
      border-color: rgba(22, 86, 44, 0.2);
      background: rgba(22, 86, 44, 0.1);
      color: var(--pc-green-dark);
      font-weight: 600;
    }
    .toolbar-card .form-control:focus,
    .toolbar-card .form-select:focus {
      border-color: var(--pc-green);
      box-shadow: 0 0 0 0.2rem rgba(22, 86, 44, 0.15);
    }
    .table-card {
      border-radius: 24px;
      overflow: hidden;
      border: none;
      box-shadow: 0 35px 60px rgba(23, 35, 61, 0.08);
    }
    .table-card .card-header {
      background: linear-gradient(110deg, var(--pc-green), var(--pc-green-dark));
      color: #fff;
      border: none;
      padding: 22px 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .table-modern thead th {
      background: #f1f4f7;
      color: var(--pc-muted);
      font-size: 0.78rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      border: none;
      padding: 16px 18px;
    }
    .table-modern tbody td {
      padding: 18px 18px;
      border-color: rgba(22, 86, 44, 0.04);
      vertical-align: middle;
      background: #fff;
    }
    .table-modern tbody tr + tr td {
      border-top: 1px solid rgba(22, 86, 44, 0.04);
    }
    .table-modern tbody tr:hover td {
      background: #fefefb;
      box-shadow: inset 0 0 0 1px rgba(22, 86, 44, 0.08);
    }
    .status-badge, .type-badge {
      font-size: 0.75rem;
      border-radius: 999px;
      padding: 0.35rem 0.8rem;
      letter-spacing: 0.02em;
    }
    .avatar-placeholder {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      background: rgba(22, 86, 44, 0.12);
      color: var(--pc-green-dark);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 1.1rem;
    }
    .submission-meta { color: var(--pc-muted); font-size: 0.86rem; }
    .submission-files {
      display: flex;
      flex-wrap: wrap;
      gap: 0.4rem;
    }
    .file-pill {
      border-radius: 999px;
      border: 1px solid rgba(22, 86, 44, 0.15);
      padding: 0.2rem 0.85rem;
      font-size: 0.78rem;
      background: rgba(22, 86, 44, 0.05);
      color: var(--pc-green-dark);
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .file-pill:hover {
      background: var(--pc-green);
      color: #fff;
    }
    .chair-feedback-cell { min-width: 320px; }
    .feedback-bubble {
      background: #fdfdfc;
      border-radius: 14px;
      padding: 0.8rem 1rem;
      border: 1px solid rgba(22, 86, 44, 0.08);
      font-size: 0.85rem;
    }
    .feedback-history {
      max-height: 230px;
      overflow-y: auto;
      padding-right: 0.4rem;
      scrollbar-width: thin;
      scrollbar-color: #a0a6a4 transparent;
    }
    .feedback-history::-webkit-scrollbar {
      width: 6px;
    }
    .feedback-history::-webkit-scrollbar-track {
      background: transparent;
      border-radius: 999px;
    }
    .feedback-history::-webkit-scrollbar-thumb {
      background-color: #9a9f9d;
      border-radius: 999px;
    }
    .feedback-form textarea {
      resize: vertical;
      min-height: 56px;
      max-height: 120px;
      font-size: 0.85rem;
    }
    .feedback-form .btn {
      min-width: 150px;
      border-radius: 999px;
    }
    .action-stack {
      display: flex;
      flex-direction: column;
      gap: 0.6rem;
    }
    .action-stack form { width: 100%; }
    .no-results-row { display: none; }
    .no-results-row td { padding: 36px 16px; }
    .empty-state-icon { font-size: 2.2rem; color: var(--pc-green); }
    @media (max-width: 1199px) {
      .content { padding: 20px; }
      .page-heading { flex-direction: column; }
      .chair-feedback-cell { min-width: 100%; }
    }
  </style>
</head>

<body>
  <?php include 'header.php'; ?>
  <?php include 'sidebar.php'; ?>

  <div class="content">
    <div class="container my-4">

      <!-- ✅ Flash Messages -->
      <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <?= $_SESSION['success']; unset($_SESSION['success']); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php elseif (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <?= $_SESSION['error']; unset($_SESSION['error']); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Header -->
      <div class="page-heading mb-4">
        <div>
          <h3>Student Submissions</h3>
          <p>Track every concept paper, push reviews forward, and keep students in the loop.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a href="status_logs.php" class="btn btn-outline-primary">
            <i class="bi bi-clock-history me-1"></i> View Logs
          </a>
          <button class="btn btn-outline-secondary" onclick="window.location.reload()">
            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
          </button>
          <a href="program_chairperson.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
          </a>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="stats-grid mb-4">
        <div class="stat-card">
          <span class="stat-label">Total Submissions</span>
          <div class="d-flex align-items-center justify-content-between mt-2">
            <h2><?= count($submissions); ?></h2>
            <div class="trend-pill"><i class="bi bi-files me-1"></i> Portfolio</div>
          </div>
          <p class="mb-0 text-muted small mt-3">All concept papers under your program.</p>
        </div>
        <div class="stat-card">
          <span class="stat-label">Awaiting Review</span>
          <?php
            $needsAttentionStatuses = ['Pending', 'Reviewing', 'Reviewer Assigning', 'In Review', 'Assigning Reviewer', 'Under Review'];
            $pendingCount = array_sum(array_map(fn($s) => in_array($s['status'], $needsAttentionStatuses, true) ? 1 : 0, $submissions));
          ?>
          <div class="d-flex align-items-center justify-content-between mt-2">
            <h2 class="text-warning"><?= $pendingCount; ?></h2>
            <div class="trend-pill text-warning bg-warning-subtle"><i class="bi bi-hourglass-split me-1"></i> Pending</div>
          </div>
          <p class="mb-0 text-muted small mt-3">Titles that still need your action.</p>
        </div>
        <div class="stat-card">
          <span class="stat-label">Approved Papers</span>
          <?php $approvedCount = array_sum(array_map(fn($s) => $s['status'] === 'Approved' ? 1 : 0, $submissions)); ?>
          <div class="d-flex align-items-center justify-content-between mt-2">
            <h2 class="text-success"><?= $approvedCount; ?></h2>
            <div class="trend-pill text-success bg-success-subtle"><i class="bi bi-check2-circle me-1"></i> Cleared</div>
          </div>
          <p class="mb-0 text-muted small mt-3">Students ready for the next milestone.</p>
        </div>
        <div class="stat-card">
          <span class="stat-label">Feedback Shared</span>
          <?php $feedbackCount = array_sum(array_map(fn($entries) => count($entries), $feedbackBySubmission)); ?>
          <div class="d-flex align-items-center justify-content-between mt-2">
            <h2 class="text-info"><?= $feedbackCount; ?></h2>
            <div class="trend-pill text-info bg-info-subtle"><i class="bi bi-chat-left-dots me-1"></i> Notes</div>
          </div>
          <p class="mb-0 text-muted small mt-3">Messages already visible to students.</p>
        </div>
      </div>

      <!-- Filters & Search -->
      <div class="card shadow-sm toolbar-card mb-4">
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-xl-4 col-lg-5 col-md-6">
              <div class="pill-label">Search</div>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="submissionSearch" class="form-control" placeholder="Search by title, student, keywords..." aria-label="Search submissions">
              </div>
            </div>
            <div class="col-xl-3 col-lg-4 col-md-6">
              <div class="pill-label">Status</div>
              <select id="statusFilter" class="form-select" aria-label="Filter submissions by status">
                <option value="">All statuses</option>
                <?php foreach ($availableStatuses as $statusOption): ?>
                  <option value="<?= htmlspecialchars($statusOption); ?>"><?= htmlspecialchars($statusOption); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6">
              <div class="pill-label">Submission Type</div>
              <select id="typeFilter" class="form-select" aria-label="Filter submissions by type">
                <option value="">All types</option>
                <?php foreach ($availableTypes as $typeOption): ?>
                  <option value="<?= htmlspecialchars($typeOption); ?>"><?= htmlspecialchars($typeOption); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-xl-2 col-lg-12 col-md-6 d-grid">
              <button type="button" id="resetFilters" class="btn btn-outline-secondary mt-md-3 mt-xl-0">
                <i class="bi bi-x-circle"></i> Reset Filters
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Submissions Table -->
      <div class="card shadow-sm table-card">
        <div class="card-header">
          <h5 class="mb-0 text-white"><i class="bi bi-table me-2"></i>Submission Directory</h5>
          <span class="badge bg-light text-dark px-3 py-2">Showing <?= count($submissions); ?> entries</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-modern" id="submissionsTable">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Submission</th>
                  <th>Type</th>
                  <th>Submitted</th>
                  <th>Status</th>
                  <th>Chair Feedback</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (count($submissions) === 0): ?>
                <tr>
                  <td colspan="7" class="text-center py-5 text-muted">
                    <i class="bi bi-inbox empty-state-icon d-block mb-3"></i>
                    No submissions have been uploaded yet. Encourage students to submit their concept papers to see them here.
                  </td>
                </tr>
              <?php else: foreach ($submissions as $s):
                $studentName = trim(($s['firstname'] ?? '') . ' ' . ($s['lastname'] ?? ''));
                if ($studentName === '') { $studentName = 'Unknown Student'; }
                $email = $s['email'] ?? '';
                $initials = strtoupper(substr($s['firstname'] ?? '', 0, 1) . substr($s['lastname'] ?? '', 0, 1));
                $initials = trim($initials) !== '' ? $initials : 'ST';
                $submittedTimestamp = strtotime($s['created_at'] ?? '') ?: null;
                $submittedDate = $submittedTimestamp ? date("M d, Y", $submittedTimestamp) : 'N/A';
                $submittedTime = $submittedTimestamp ? date("g:i A", $submittedTimestamp) : '';
                $statusValue = trim($s['status'] ?? '');
                $typeValue = trim($s['type'] ?? '');
                $searchIndex = htmlspecialchars(buildSearchIndex($s));
                $keywordsList = array_filter(array_map('trim', explode(',', $s['keywords'] ?? '')));
                $filePath = $s['file_path'] ?? '';
                $conceptFiles = [];
                for ($proposalIndex = 1; $proposalIndex <= 3; $proposalIndex++) {
                    $fileKey = "concept_file_{$proposalIndex}";
                    $proposalPath = trim((string)($s[$fileKey] ?? ''));
                    if ($proposalPath !== '') {
                        $conceptFiles[] = [
                            'label' => "Proposal {$proposalIndex}",
                            'path' => $proposalPath,
                            'name' => basename($proposalPath),
                        ];
                    }
                }
                $assignFilterTarget = trim(($s['title'] ?? '') . ' ' . $studentName);
                if ($assignFilterTarget === '') {
                    $assignFilterTarget = (string)($s['id'] ?? '');
                }
                $assignUrl = 'assign_faculty.php?status=all&source=submissions&q=' . rawurlencode($assignFilterTarget);
              ?>
                <tr class="submission-row"
                    data-status="<?= htmlspecialchars($statusValue); ?>"
                    data-type="<?= htmlspecialchars($typeValue); ?>"
                    data-search="<?= $searchIndex; ?>">
                  <!-- Student -->
                  <td>
                    <div class="d-flex align-items-center gap-3">
                      <div class="avatar-placeholder"><?= htmlspecialchars($initials); ?></div>
                      <div>
                        <div class="fw-semibold text-dark"><?= htmlspecialchars($studentName); ?></div>
                        <?php if ($email !== ''): ?>
                          <div class="submission-meta"><?= htmlspecialchars($email); ?></div>
                        <?php else: ?>
                          <div class="submission-meta text-warning">No email on record</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>

                  <!-- Submission Details -->
                  <td>
                    <?php $titleValue = trim((string)($s['title'] ?? '')); ?>
                    <?php if ($titleValue !== ''): ?>
                      <div class="fw-semibold text-dark mb-1">
                        <?= htmlspecialchars($titleValue); ?>
                      </div>
                    <?php endif; ?>
                    <?php
                      $abstractRaw = trim((string)($s['abstract'] ?? ''));
                      $isPlaceholderAbstract = strcasecmp($abstractRaw, 'Final abstract to follow') === 0;
                      $abstractPreview = ($abstractRaw !== '' && !$isPlaceholderAbstract)
                        ? truncateText($abstractRaw, 110)
                        : '';
                    ?>
                    <?php if ($abstractPreview !== ''): ?>
                      <div class="submission-meta"><?= htmlspecialchars($abstractPreview); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($keywordsList)): ?>
                      <div class="mt-2">
                        <?php foreach (array_slice($keywordsList, 0, 4) as $keyword): ?>
                          <span class="keywords-badge"><?= htmlspecialchars($keyword); ?></span>
                        <?php endforeach; ?>
                        <?php if (count($keywordsList) > 4): ?>
                          <span class="keywords-badge">+<?= count($keywordsList) - 4; ?> more</span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($conceptFiles)): ?>
                      <div class="submission-files mt-2">
                        <?php foreach ($conceptFiles as $conceptFile): ?>
                          <a href="<?= htmlspecialchars($conceptFile['path']); ?>"
                             target="_blank"
                             rel="noopener"
                             title="<?= htmlspecialchars($conceptFile['label'] . ': ' . $conceptFile['name']); ?>"
                             class="file-pill">
                            <i class="bi bi-file-earmark-pdf-fill"></i>
                            <span class="d-none d-xl-inline"><?= htmlspecialchars($conceptFile['label']); ?>:</span>
                            <span><?= htmlspecialchars($conceptFile['name']); ?></span>
                          </a>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <!-- Type -->
                  <td><?= renderTypeBadge($typeValue); ?></td>

                  <!-- Date Submitted -->
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($submittedDate); ?></div>
                    <?php if ($submittedTime !== ''): ?>
                      <div class="submission-meta"><?= htmlspecialchars($submittedTime); ?></div>
                    <?php endif; ?>
                  </td>

                  <!-- Status Badge -->
                  <td>
                    <?= renderStatusBadge($statusValue); ?>
                  </td>

                  <!-- Chair Feedback -->
                  <td class="chair-feedback-cell">
                    <?php $feedbackEntries = $feedbackBySubmission[(int)$s['id']] ?? []; ?>
                    <?php if (empty($feedbackEntries)): ?>
                      <div class="text-muted small">No feedback shared yet.</div>
                    <?php else: ?>
                      <div class="feedback-history" style="max-height: 140px;">
                        <?php foreach ($feedbackEntries as $feedback): ?>
                          <?php
                            $feedbackDate = '';
                            if (!empty($feedback['created_at'])) {
                                $timestamp = strtotime($feedback['created_at']);
                                $feedbackDate = $timestamp ? date('M d, Y g:i A', $timestamp) : '';
                            }
                            $chairName = trim((string)($feedback['chair_name'] ?? 'Program Chair'));
                          ?>
                          <div class="feedback-bubble mb-2">
                            <div class="small text-dark"><?= nl2br(htmlspecialchars($feedback['message'] ?? '')); ?></div>
                            <small class="d-block mt-2">
                              <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($chairName ?: 'Program Chair'); ?>
                              <?php if ($feedbackDate !== ''): ?>
                                &middot; <?= htmlspecialchars($feedbackDate); ?>
                              <?php endif; ?>
                            </small>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <form class="feedback-form mt-3" method="POST" action="submission_feedback.php">
                      <input type="hidden" name="submission_id" value="<?= (int)$s['id']; ?>">
                      <div class="d-flex gap-2 align-items-start w-100">
                        <textarea class="form-control" name="feedback_message" rows="2" maxlength="2000" placeholder="Write a note for the student..." required></textarea>
                        <button type="submit" class="btn btn-outline-success btn-sm d-inline-flex align-items-center gap-2">
                          <i class="bi bi-chat-left-quote"></i>
                          <span>Share Feedback</span>
                        </button>
                      </div>
                    </form>
                  </td>

                  <!-- Actions -->
                  <td>
                    <div class="action-stack">
                      <form method="POST" action="update_status.php">
                        <input type="hidden" name="id" value="<?= (int)$s['id']; ?>">
                        <input type="hidden" name="action_type" value="start_review">
                        <input type="hidden" name="redirect_to" value="review_submission.php?id=<?= (int)$s['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-info w-100">
                          <i class="bi bi-eye"></i> Review
                        </button>
                      </form>
                      <form method="POST" action="update_status.php">
                        <input type="hidden" name="id" value="<?= (int)$s['id']; ?>">
                        <input type="hidden" name="action_type" value="assign_reviewer">
                        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($assignUrl); ?>">
                        <button type="submit" class="btn btn-sm btn-success w-100">
                          <i class="bi bi-people-fill"></i> Assign Reviewer
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
                <tr class="no-results-row" id="noResultsRow">
                  <td colspan="7" class="text-center text-muted">
                    <i class="bi bi-search empty-state-icon d-block mb-2"></i>
                    No submissions match your current filters. Adjust the search or filters to see more results.
                  </td>
                </tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function () {
      const table = document.getElementById('submissionsTable');
      if (!table) {
        return;
      }
      const searchInput = document.getElementById('submissionSearch');
      const statusFilter = document.getElementById('statusFilter');
      const typeFilter = document.getElementById('typeFilter');
      const resetButton = document.getElementById('resetFilters');
      const rows = Array.from(table.querySelectorAll('tbody tr.submission-row'));
      const emptyRow = document.getElementById('noResultsRow');

      const normalise = (value) => value ? value.toString().trim().toLowerCase() : '';

      const applyFilters = () => {
        const query = normalise(searchInput ? searchInput.value : '');
        const status = statusFilter ? statusFilter.value : '';
        const type = typeFilter ? typeFilter.value : '';
        let visibleCount = 0;

        rows.forEach((row) => {
          const matchesStatus = !status || normalise(row.dataset.status) === normalise(status);
          const matchesType = !type || normalise(row.dataset.type) === normalise(type);
          const matchesSearch = !query || (row.dataset.search || '').includes(query);
          const shouldShow = matchesStatus && matchesType && matchesSearch;
          row.style.display = shouldShow ? '' : 'none';
          if (shouldShow) {
            visibleCount += 1;
          }
        });

        if (emptyRow) {
          emptyRow.style.display = visibleCount === 0 ? '' : 'none';
        }
      };

      if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
      }
      if (statusFilter) {
        statusFilter.addEventListener('change', applyFilters);
      }
      if (typeFilter) {
        typeFilter.addEventListener('change', applyFilters);
      }
      if (resetButton) {
        resetButton.addEventListener('click', () => {
          if (searchInput) searchInput.value = '';
          if (statusFilter) statusFilter.value = '';
          if (typeFilter) typeFilter.value = '';
          applyFilters();
        });
      }

      applyFilters();
    })();

    // Notification Integration: Auto-refresh submissions when notifications arrive
    (function () {
      let lastNotificationCount = window.APP_NOTIFICATIONS ? window.APP_NOTIFICATIONS.unread : 0;
      let lastNotificationList = window.APP_NOTIFICATIONS ? (window.APP_NOTIFICATIONS.list || []) : [];

      function refreshSubmissionsTable() {
        location.reload();
      }

      function checkForSubmissionNotifications() {
        fetch('notifications_api.php?action=list&limit=100')
          .then(function (res) { return res.json(); })
          .then(function (payload) {
            if (!payload || payload.error) {
              return;
            }

            const currentNotifications = payload.notifications || [];
            const currentUnread = payload.unread || 0;

            // Check if there are new notifications related to submissions
            const newNotifications = currentNotifications.filter(function (note) {
              return !lastNotificationList.some(function (oldNote) {
                return oldNote.id === note.id;
              });
            });

            // If new notifications exist and contain submission-related keywords, refresh the table
            const submissionKeywords = ['submission', 'submitted', 'concept paper', 'paper', 'student submission'];
            const hasSubmissionNotification = newNotifications.some(function (note) {
              const titleLower = (note.title || '').toLowerCase();
              const messageLower = (note.message || '').toLowerCase();
              return submissionKeywords.some(function (keyword) {
                return titleLower.includes(keyword) || messageLower.includes(keyword);
              });
            });

            if (hasSubmissionNotification) {
              // Show a toast notification to the user
              showSubmissionNotificationToast(newNotifications[0]);
              // Refresh the submissions table after a short delay
              setTimeout(refreshSubmissionsTable, 1500);
            }

            lastNotificationCount = currentUnread;
            lastNotificationList = currentNotifications;
          })
          .catch(function (err) {
            console.error('Failed to check notifications', err);
          });
      }

      function showSubmissionNotificationToast(notification) {
        // Create a toast container if it doesn't exist
        let toastContainer = document.getElementById('submissionNotificationContainer');
        if (!toastContainer) {
          toastContainer = document.createElement('div');
          toastContainer.id = 'submissionNotificationContainer';
          toastContainer.style.cssText = 'position: fixed; top: 80px; right: 20px; z-index: 9999;';
          document.body.appendChild(toastContainer);
        }

        // Create toast element
        const toastEl = document.createElement('div');
        toastEl.className = 'toast show';
        toastEl.style.cssText = 'min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
        toastEl.innerHTML = ''
          + '<div class="toast-header bg-success text-white">'
          + '  <i class="bi bi-bell-fill me-2"></i>'
          + '  <strong class="me-auto">New Submission</strong>'
          + '  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>'
          + '</div>'
          + '<div class="toast-body">'
          + '  <strong>' + escapeHtml(notification.title || 'New Submission') + '</strong>'
          + '  <p class="mb-0 mt-2 text-muted">' + escapeHtml(notification.message || '') + '</p>'
          + '</div>';

        toastContainer.appendChild(toastEl);

        // Auto-remove toast after 5 seconds
        setTimeout(function () {
          toastEl.remove();
        }, 5000);
      }

      function escapeHtml(value) {
        if (value === null || value === undefined) {
          return '';
        }
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      // Check for notifications every 30 seconds
      setInterval(checkForSubmissionNotifications, 30000);

      // Also check when the page becomes visible (user returns to tab)
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          checkForSubmissionNotifications();
        }
      });
    })();
  </script>
</body>
</html>
