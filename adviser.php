<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';

enforce_role_access(['adviser']);

$advisorId = (int)$_SESSION['user_id'];
$advisorName = trim(
    ($_SESSION['first_name'] ?? $_SESSION['firstname'] ?? '') . ' ' .
    ($_SESSION['last_name'] ?? $_SESSION['lastname'] ?? '')
) ?: 'Adviser';

if (!function_exists('formatDefenseSchedule')) {
    function formatDefenseSchedule(?string $date, ?string $time): string
    {
        $date = $date ? trim($date) : '';
        $time = $time ? trim($time) : '';
        if ($date === '' && $time === '') {
            return 'Schedule to be announced';
        }
        $timestamp = trim($date . ' ' . $time);
        $parsed = strtotime($timestamp);
        if ($parsed) {
            return date('F d, Y | g:i A', $parsed);
        }
        if ($date !== '') {
            $parsedDate = strtotime($date);
            if ($parsedDate) {
                return date('F d, Y', $parsedDate);
            }
        }
        if ($time !== '') {
            $parsedTime = strtotime($time);
            if ($parsedTime) {
                return date('g:i A', $parsedTime);
            }
        }
        return 'Schedule to be announced';
    }
}

if (!function_exists('adviserUsersColumnExists')) {
    function adviserUsersColumnExists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = '{$column}'
            LIMIT 1
        ";
        $result = $conn->query($sql);
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    }
}

$hasAdviserIdColumn = adviserUsersColumnExists($conn, 'adviser_id');
$hasAdvisorIdColumn = adviserUsersColumnExists($conn, 'advisor_id');
$advisorColumns = [];

if ($hasAdviserIdColumn) {
    $advisorColumns[] = 'adviser_id';
}
if ($hasAdvisorIdColumn) {
    $advisorColumns[] = 'advisor_id';
}

if (empty($advisorColumns)) {
    die('Advisor tracking columns are missing in the users table.');
}

$advisorParamTypes = str_repeat('i', count($advisorColumns));
$advisorParams = array_fill(0, count($advisorColumns), $advisorId);

if (!function_exists('buildAdvisorWhereClause')) {
    function buildAdvisorWhereClause(string $alias, array $columns): string
    {
        $parts = array_map(fn($column) => "{$alias}.{$column} = ?", $columns);
        return '(' . implode(' OR ', $parts) . ')';
    }
}

if (!function_exists('adviserBindParams')) {
    function adviserBindParams(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '' || empty($params)) {
            return true;
        }
        $bindParams = [$types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        return call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }
}

// Fetch advisees
$students = [];
$advisorWhereStudents = buildAdvisorWhereClause('u', $advisorColumns);
$studentSql = "
    SELECT u.id,
           u.firstname,
           u.lastname,
           u.email,
           u.contact,
           CASE WHEN cp.id IS NOT NULL THEN 'Submitted' ELSE 'Not Submitted' END AS submission_status,
           cp.title AS concept_title,
           cp.created_at AS submission_date
    FROM users u
    LEFT JOIN concept_papers cp ON cp.student_id = u.id
    WHERE u.role = 'student' AND {$advisorWhereStudents}
    ORDER BY u.lastname, u.firstname
";
$studentStmt = $conn->prepare($studentSql);
if ($studentStmt) {
    $studentBindParams = $advisorParams;
    adviserBindParams($studentStmt, $advisorParamTypes, $studentBindParams);
    $studentStmt->execute();
    $studentResult = $studentStmt->get_result();
    if ($studentResult) {
        while ($row = $studentResult->fetch_assoc()) {
            $students[] = $row;
        }
        $studentResult->free();
    }
    $studentStmt->close();
}

// Fetch defense schedules & panel assignments for advisees
$panelAssignments = [];
$advisorWherePanels = buildAdvisorWhereClause('stu', $advisorColumns);
$panelSql = "
    SELECT ds.id,
           ds.defense_date,
           ds.defense_time,
           ds.venue,
           ds.status,
           CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
           GROUP_CONCAT(DISTINCT dp.panel_member ORDER BY dp.panel_member SEPARATOR ', ') AS panel_members
    FROM defense_schedules ds
    JOIN users stu ON ds.student_id = stu.id
    LEFT JOIN defense_panels dp ON dp.defense_id = ds.id
    WHERE {$advisorWherePanels}
    GROUP BY ds.id
    ORDER BY ds.defense_date IS NULL, ds.defense_date ASC, ds.defense_time ASC
";
$panelStmt = $conn->prepare($panelSql);
if ($panelStmt) {
    $panelBindParams = $advisorParams;
    adviserBindParams($panelStmt, $advisorParamTypes, $panelBindParams);
    $panelStmt->execute();
    $panelResult = $panelStmt->get_result();
    if ($panelResult) {
        while ($row = $panelResult->fetch_assoc()) {
            $panelAssignments[] = $row;
        }
        $panelResult->free();
    }
    $panelStmt->close();
}

$totalStudents = count($students);
$submittedCount = count(array_filter($students, fn($s) => ($s['submission_status'] ?? '') === 'Submitted'));
$pendingCount = $totalStudents - $submittedCount;
$scheduledDefenses = count(array_filter($panelAssignments, fn($a) => !empty($a['defense_date'])));
$panelizedStudents = count(array_filter($panelAssignments, fn($a) => trim((string)($a['panel_members'] ?? '')) !== ''));
$submissionProgress = $totalStudents > 0 ? round(($submittedCount / $totalStudents) * 100) : 0;
$followUpProgress = $totalStudents > 0 ? round((max($pendingCount, 0) / $totalStudents) * 100) : 0;
$nextDefense = null;
foreach ($panelAssignments as $assignment) {
    if (!empty($assignment['defense_date'])) {
        $nextDefense = $assignment;
        break;
    }
}
$nextPendingAdvisee = null;
foreach ($students as $student) {
    if (($student['submission_status'] ?? '') !== 'Submitted') {
        $nextPendingAdvisee = $student;
        break;
    }
}
$latestSubmission = null;
foreach ($students as $student) {
    if (($student['submission_status'] ?? '') === 'Submitted') {
        $latestSubmission = $student;
        break;
    }
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Adviser Dashboard - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --bg: #f3f6f2;
            --bg-soft: #fbfdf9;
            --surface: #ffffff;
            --surface-muted: #f4f7f1;
            --border: #e2e8df;
            --ink: #0f1f12;
            --muted: #5f6f63;
            --accent: #16562c;
            --accent-strong: #0f3d1f;
            --teal: #1f6f3a;
            --teal-soft: #e7f2ea;
            --gold-soft: #fff2cc;
            --shadow: 0 18px 40px rgba(22, 86, 44, 0.12);
        }
        body {
            background: radial-gradient(circle at top right, rgba(22, 86, 44, 0.12), transparent 45%),
                        linear-gradient(180deg, var(--bg), var(--bg-soft));
            color: var(--ink);
        }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .hero-card {
            position: relative;
            border-radius: 24px;
            padding: 32px;
            background: linear-gradient(135deg, #0f3d1f 0%, #16562c 60%, #1f6f3a 100%);
            color: #f8fafc;
            box-shadow: 0 28px 60px rgba(15, 61, 31, 0.35);
            overflow: hidden;
        }
        .hero-card::before {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 193, 7, 0.18), transparent 70%);
            top: -80px;
            right: -60px;
        }
        .hero-card::after {
            content: '';
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(22, 86, 44, 0.35), transparent 70%);
            bottom: -140px;
            left: -120px;
        }
        .hero-card > * { position: relative; z-index: 1; }
        .hero-card p { margin-bottom: 0; color: rgba(248, 250, 252, 0.82); }
        .hero-card .text-muted { color: rgba(248, 250, 252, 0.65) !important; }
        .stat-card {
            border-radius: 18px;
            padding: 22px 24px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--ink);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--accent);
        }
        .stat-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(255, 193, 7, 0.12), transparent 55%);
            pointer-events: none;
        }
        .stat-card.light { background: var(--teal-soft); }
        .stat-card.light::before { background: var(--teal); }
        .stat-card.accent { background: var(--gold-soft); }
        .stat-card.accent::before { background: #f59e0b; }
        .stat-card h3 { font-size: 2.2rem; margin: 0; }
        .stat-card small {
            color: var(--muted);
            letter-spacing: .12em;
            text-transform: uppercase;
            font-weight: 600;
        }
        .stat-card p { margin-bottom: 0; color: var(--muted); }
        .advisor-highlights { display: flex; flex-direction: column; gap: 16px; min-width: 260px; }
        .highlight-card {
            background: rgba(15, 61, 31, 0.22);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 18px;
            padding: 16px 20px;
            color: #f8fafc;
            box-shadow: 0 18px 30px rgba(15, 61, 31, 0.2);
            backdrop-filter: blur(6px);
        }
        .highlight-card small { opacity: .8; }
        .progress-track {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
            overflow: hidden;
        }
        .progress-track span {
            display: block;
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #c7f9cc, #22c55e);
        }
        .progress-track span.follow-up {
            background: linear-gradient(90deg, #ffd166, #f59e0b);
        }
        .advisor-overview {
            margin-top: 24px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .overview-card {
            background: rgba(15, 23, 42, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 16px;
            padding: 16px 20px;
            color: #f8fafc;
            min-height: 120px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
        }
        .overview-card small { color: rgba(248, 250, 252, 0.75); }
        .overview-card .value { font-size: 1.4rem; font-weight: 600; color: #fff; }
        .overview-card i { color: rgba(248, 250, 252, 0.6); }
        .card { border: 1px solid var(--border); border-radius: 20px; box-shadow: var(--shadow); }
        .card h5 { font-weight: 600; color: var(--ink); }
        .table thead { background: var(--surface-muted); }
        .table thead th {
            border: none;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--muted);
        }
        .table tbody td { vertical-align: middle; border-color: #edf1f7; }
        .table tbody tr:hover { background: #f8fafc; }
        .badge-status { border-radius: 999px; padding: .35rem .85rem; font-size: .82rem; font-weight: 600; }
        .panel-card { border-left: 5px solid var(--accent); background: #fbfdf9; }
        .panel-chip {
            background: var(--teal-soft);
            color: var(--accent);
            border-radius: 999px;
            border: 1px solid rgba(22, 86, 44, 0.2);
            padding: .2rem .75rem;
            font-size: .85rem;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            margin: 0 .35rem .35rem 0;
        }
        .empty-state { text-align: center; padding: 3rem 1rem; color: var(--muted); }
        .empty-state i { font-size: 2.5rem; color: var(--teal); margin-bottom: .5rem; display: block; }
        .form-control {
            border-radius: 12px;
            border-color: var(--border);
            background: #fff;
        }
        .form-control:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 .2rem rgba(22, 86, 44, 0.15);
        }
        .btn-outline-success {
            border-color: var(--accent);
            color: var(--accent);
        }
        .btn-outline-success:hover {
            background: var(--accent);
            color: #fff;
        }
        .text-success { color: var(--accent) !important; }
        @media (max-width: 992px) {
            .content { margin-left: 0; }
            .hero-card { text-align: center; }
            .advisor-highlights { width: 100%; }
            .advisor-overview { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                <div>
                    <p class="text-uppercase fw-semibold mb-1">Adviser Workspace</p>
                    <h2 class="fw-bold mb-2">Good day, <?= htmlspecialchars($advisorName); ?>!</h2>
                    <p>Monitor your advisees, track their submissions, and stay aligned with upcoming panel assignments in one glance.</p>
                    <div class="advisor-overview">
                        <div class="overview-card">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <p class="text-uppercase small mb-0">Next defense</p>
                                <i class="bi bi-calendar-event"></i>
                            </div>

                            <?php if ($nextDefense): ?>
                                <?php
                                    $defenseDateRaw = trim(($nextDefense['defense_date'] ?? '') . ' ' . ($nextDefense['defense_time'] ?? ''));
                                    $defenseDateTs = $defenseDateRaw ? strtotime($defenseDateRaw) : null;
                                    $defenseDateLabel = $defenseDateTs ? date('M j, Y g:i A', $defenseDateTs) : 'Schedule TBA';
                                    $defenseVenue = trim($nextDefense['venue'] ?? '') !== '' ? $nextDefense['venue'] : 'Venue TBA';
                                ?>
                                <div class="value mb-1"><?= htmlspecialchars($defenseDateLabel); ?></div>
                                <small><?= htmlspecialchars($nextDefense['student_name'] ?? 'Student TBA'); ?> - <?= htmlspecialchars($defenseVenue); ?></small>
                            <?php else: ?>
                                <div class="value mb-1">No schedule yet</div>
                                <small>We&#39;ll ping you once a defense is booked.</small>
                            <?php endif; ?>
                        </div>
                        <div class="overview-card">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <p class="text-uppercase small mb-0">Next follow-up</p>
                                <i class="bi bi-flag"></i>
                            </div>
                            <?php if ($nextPendingAdvisee): ?>
                                <?php $pendingName = trim(($nextPendingAdvisee['firstname'] ?? '') . ' ' . ($nextPendingAdvisee['lastname'] ?? '')) ?: 'Unnamed student'; ?>
                                <div class="value mb-1"><?= htmlspecialchars($pendingName); ?></div>
                                <small><?= htmlspecialchars($nextPendingAdvisee['email'] ?? 'No email listed'); ?> - awaiting draft</small>
                            <?php else: ?>
                                <div class="value mb-1">All clear</div>
                                <small>Every advisee has submitted their latest draft.</small>
                            <?php endif; ?>
                        </div>
                        <div class="overview-card">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <p class="text-uppercase small mb-0">Latest submission</p>
                                <i class="bi bi-check2-circle"></i>
                            </div>
                            <?php if ($latestSubmission): ?>
                                <?php
                                    $latestName = trim(($latestSubmission['firstname'] ?? '') . ' ' . ($latestSubmission['lastname'] ?? '')) ?: 'Unnamed student';
                                    $latestTitle = trim($latestSubmission['concept_title'] ?? '');
                                ?>
                                <div class="value mb-1"><?= htmlspecialchars($latestName); ?></div>
                                <small><?= htmlspecialchars($latestTitle !== '' ? $latestTitle : 'Concept title pending'); ?></small>
                            <?php else: ?>
                                <div class="value mb-1">No submissions yet</div>
                                <small>Encourage advisees to send their drafts.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="advisor-highlights">
                    <div class="highlight-card">
                        <p class="text-uppercase small mb-1">Submission momentum</p>
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0"><?= number_format($submittedCount); ?> submitted</h4>
                            <span class="badge bg-light text-success"><?= $submissionProgress; ?>%</span>
                        </div>
                        <small class="d-block mb-2">of <?= number_format($totalStudents); ?> advisees shared drafts</small>
                        <div class="progress-track mb-2">
                            <span style="width: <?= $submissionProgress; ?>%;"></span>
                        </div>
                        <small class="text-white-50">Great pace - keep the feedback loop moving.</small>
                    </div>
                    <div class="highlight-card">
                        <p class="text-uppercase small mb-1">Follow-up queue</p>
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0"><?= number_format(max($pendingCount, 0)); ?> awaiting</h4>
                            <span class="badge bg-warning text-dark"><?= $followUpProgress; ?>%</span>
                        </div>
                        <small class="d-block mb-2">need drafts or quick check-ins</small>
                        <div class="progress-track mb-2">
                            <span class="follow-up" style="width: <?= $followUpProgress; ?>%;"></span>
                        </div>
                        <small class="text-white-50"><i class="bi bi-calendar2-check me-1"></i><?= number_format($scheduledDefenses); ?> defense<?= $scheduledDefenses === 1 ? '' : 's'; ?> locked in.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <small>Advisees</small>
                    <h3><?= number_format($totalStudents); ?></h3>
                    <p class="mb-0">Active students under your guidance</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card light">
                    <small>Drafts Submitted</small>
                    <h3><?= number_format($submittedCount); ?></h3>
                    <p class="mb-0">Awaiting your feedback</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card accent">
                    <small>Awaiting Drafts</small>
                    <h3><?= number_format(max($pendingCount, 0)); ?></h3>
                    <p class="mb-0 text-dark">Students to follow-up</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <small>Scheduled Defenses</small>
                    <h3><?= number_format($scheduledDefenses); ?></h3>
                    <p class="mb-0">With <?= number_format($panelizedStudents); ?> panel-ready students</p>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <h5 class="mb-1">Advisee Tracker</h5>
                                <p class="text-muted mb-0">Stay current on submissions and reach out with a click.</p>
                            </div>
                            <input type="search" class="form-control form-control-sm w-auto" id="adviseeSearch" placeholder="Search advisee...">
                        </div>
                        <?php if (empty($students)): ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <p class="mb-0">No students assigned yet. New advisees will appear here automatically.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0" id="adviseeTable">
                                    <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Contact</th>
                                        <th>Submission</th>
                                        <th>Action</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <?php
                                            $status = $student['submission_status'] ?? 'Not Submitted';
                                            $badgeClass = $status === 'Submitted' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
                                            $submissionDate = $student['submission_date'] ? date('M d, Y', strtotime($student['submission_date'])) : null;
                                            $conceptTitle = $student['concept_title'] ?? '';
                                            $rowSearch = strtolower(
                                                trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? '') . ' ' . $status . ' ' . ($conceptTitle ?? ''))
                                            );
                                        ?>
                                        <tr data-advisee-row data-search="<?= htmlspecialchars($rowSearch); ?>">
                                            <td>
                                                <div class="fw-semibold text-success"><?= htmlspecialchars(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? '')); ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($conceptTitle ?: 'No concept paper yet'); ?></small>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($student['email'] ?? 'No email'); ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($student['contact'] ?? 'No contact'); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-status <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span>
                                                <?php if ($submissionDate): ?>
                                                    <div class="small text-muted">Submitted <?= htmlspecialchars($submissionDate); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($status === 'Submitted'): ?>
                                                    <a href="review_paper.php?student_id=<?= (int)$student['id']; ?>" class="btn btn-sm btn-outline-success">
                                                        <i class="bi bi-search"></i> Review
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled>
                                                        <i class="bi bi-hourglass-split"></i> Waiting
                                                    </button>
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
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h5 class="mb-1">Outline Defense Endorsement</h5>
                                <p class="text-muted mb-0">Send the endorsement letter once an advisee is ready.</p>
                            </div>
                            <i class="bi bi-file-earmark-text text-success fs-4"></i>
                        </div>
                        <a class="btn btn-success btn-sm" href="adviser_endorsement.php">
                            <i class="bi bi-send me-1"></i> Create Endorsement
                        </a>
                    </div>
                </div>
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="mb-1">Panel Assignments</h5>
                                <p class="text-muted mb-0">Students already endorsed to panels and upcoming defenses.</p>
                            </div>
                            <span class="badge bg-success-subtle text-success"><?= number_format($panelizedStudents); ?> ready</span>
                        </div>
                        <?php if (empty($panelAssignments)): ?>
                            <div class="empty-state">
                                <i class="bi bi-clipboard-check"></i>
                                <p class="mb-0">Once panel members are assigned to your students, details will show up here.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($panelAssignments as $assignment): ?>
                                    <?php
                                        $scheduleLabel = formatDefenseSchedule($assignment['defense_date'] ?? null, $assignment['defense_time'] ?? null);
                                        $panelMembers = array_filter(array_map('trim', explode(',', $assignment['panel_members'] ?? '')));
                                        $status = $assignment['status'] ?? 'Pending';
                                        $badgeClass = $status === 'Confirmed'
                                            ? 'bg-success-subtle text-success'
                                            : ($status === 'Completed' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary');
                                    ?>
                                    <div class="border rounded-4 p-4 panel-card">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="mb-1 text-success"><?= htmlspecialchars($assignment['student_name'] ?? 'Student'); ?></h6>
                                            </div>
                                            <span class="badge badge-status <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span>
                                        </div>
                                        <p class="mb-1"><i class="bi bi-calendar-event me-1 text-success"></i><?= htmlspecialchars($scheduleLabel); ?></p>
                                        <p class="mb-3"><i class="bi bi-geo-alt me-1 text-success"></i><?= htmlspecialchars($assignment['venue'] ?? 'Venue TBA'); ?></p>
                                        <div>
                                            <?php if (empty($panelMembers)): ?>
                                                <span class="text-muted small"><i class="bi bi-people"></i> Panel members not assigned yet.</span>
                                            <?php else: ?>
                                                <div class="small text-muted mb-1">Panel Members</div>
                                                <?php foreach ($panelMembers as $member): ?>
                                                    <span class="panel-chip"><i class="bi bi-person"></i><?= htmlspecialchars($member); ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const adviseeSearch = document.getElementById('adviseeSearch');
    if (adviseeSearch) {
        adviseeSearch.addEventListener('input', () => {
            const value = adviseeSearch.value.toLowerCase();
            document.querySelectorAll('[data-advisee-row]').forEach(row => {
                const haystack = (row.dataset.search || '').toLowerCase();
                row.style.display = haystack.includes(value) ? '' : 'none';
            });
        });
    }
</script>
</body>
</html>

