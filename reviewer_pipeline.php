<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';
require_once 'chair_scope_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header('Location: login.php');
    exit;
}

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);

ensureConceptReviewTables($conn);

function formatDateDisplay(?string $value, string $format = 'M d, Y'): string
{
    if (!$value) {
        return 'Date not set';
    }
    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Exception $e) {
        return $value;
    }
}

function formatRelativeDue(?string $value): string
{
    if (!$value) {
        return 'Date not set';
    }
    try {
        $due = new DateTimeImmutable($value);
        $today = new DateTimeImmutable('today');
        $diffDays = (int)$today->diff($due)->format('%r%a');
        if ($diffDays > 1) {
            return "{$diffDays} days remaining";
        }
        if ($diffDays === 1) {
            return 'Due tomorrow';
        }
        if ($diffDays === 0) {
            return 'Due today';
        }
        $overdue = abs($diffDays);
        return "{$overdue} day" . ($overdue === 1 ? '' : 's') . ' overdue';
    } catch (Exception $e) {
        return $value;
    }
}

$conceptScopeWhere = render_scope_condition($conn, $chairScope, 'u');

$assignmentStats = getConceptAssignmentStats($conn);
if ($conceptScopeWhere !== '') {
    $assignmentStats = [
        'total' => 0,
        'pending' => 0,
        'completed' => 0,
        'due_soon' => 0,
    ];
    $assignmentSql = "
        SELECT cra.status, COUNT(*) AS total
        FROM concept_reviewer_assignments cra
        JOIN users u ON u.id = cra.student_id
        WHERE {$conceptScopeWhere}
        GROUP BY cra.status
    ";
    if ($assignmentResult = $conn->query($assignmentSql)) {
        while ($row = $assignmentResult->fetch_assoc()) {
            $status = $row['status'] ?? 'pending';
            $count = (int)($row['total'] ?? 0);
            $assignmentStats['total'] += $count;
            if ($status === 'completed') {
                $assignmentStats['completed'] += $count;
            } elseif (in_array($status, ['pending', 'in_progress'], true)) {
                $assignmentStats['pending'] += $count;
            }
        }
        $assignmentResult->free();
    }
    $dueSoonSql = "
        SELECT COUNT(*) AS due_total
        FROM concept_reviewer_assignments cra
        JOIN users u ON u.id = cra.student_id
        WHERE {$conceptScopeWhere}
          AND cra.status IN ('pending','in_progress')
          AND cra.due_at IS NOT NULL
          AND cra.due_at <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ";
    if ($dueSoonScoped = $conn->query($dueSoonSql)) {
        $dueRow = $dueSoonScoped->fetch_assoc();
        $assignmentStats['due_soon'] = (int)($dueRow['due_total'] ?? 0);
        $dueSoonScoped->free();
    }
}

$overdueAssignments = 0;
$overdueSql = "
    SELECT COUNT(*) AS total
    FROM concept_reviewer_assignments cra
    JOIN users u ON u.id = cra.student_id
    WHERE cra.status IN ('pending','in_progress')
      AND cra.due_at IS NOT NULL
      AND cra.due_at < CURDATE()
";
if ($conceptScopeWhere !== '') {
    $overdueSql .= "  AND {$conceptScopeWhere}";
}
if ($overdueResult = $conn->query($overdueSql)) {
    $row = $overdueResult->fetch_assoc();
    $overdueAssignments = (int)($row['total'] ?? 0);
    $overdueResult->free();
}

$roleAssignmentSummary = [];
$roleSummarySql = "
    SELECT cra.reviewer_role,
           COUNT(*) AS total,
           SUM(CASE WHEN cra.status = 'completed' THEN 1 ELSE 0 END) AS completed,
           SUM(CASE WHEN cra.status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS active
    FROM concept_reviewer_assignments cra
    JOIN users u ON u.id = cra.student_id
    WHERE 1=1
";
if ($conceptScopeWhere !== '') {
    $roleSummarySql .= "      AND {$conceptScopeWhere}\n";
}
$roleSummarySql .= "    GROUP BY cra.reviewer_role";
if ($summaryResult = $conn->query($roleSummarySql)) {
    while ($row = $summaryResult->fetch_assoc()) {
        $role = $row['reviewer_role'] ?? '';
        if ($role === '') {
            continue;
        }
        $roleAssignmentSummary[$role] = [
            'total' => (int)($row['total'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'active' => (int)($row['active'] ?? 0),
        ];
    }
    $summaryResult->free();
}

$roleMeta = [
    'faculty' => ['label' => 'Faculty Reviewers', 'icon' => 'bi-people-fill', 'accent' => 'success'],
    'panel' => ['label' => 'Panel Members', 'icon' => 'bi-diagram-3', 'accent' => 'info'],
    'adviser' => ['label' => 'Thesis Advisers', 'icon' => 'bi-person-workspace', 'accent' => 'primary'],
    'committee_chair' => ['label' => 'Committee Chairs', 'icon' => 'bi-award', 'accent' => 'warning'],
];

$rolePerformance = [];
foreach ($roleMeta as $roleKey => $meta) {
    $summary = $roleAssignmentSummary[$roleKey] ?? ['total' => 0, 'completed' => 0, 'active' => 0];
    $percent = $summary['total'] > 0 ? round(($summary['completed'] / $summary['total']) * 100) : 0;
    if ($percent >= 70) {
        $statusLabel = 'On track';
        $statusClass = 'success';
    } elseif ($percent >= 40) {
        $statusLabel = 'In progress';
        $statusClass = 'warning';
    } else {
        $statusLabel = $summary['total'] === 0 ? 'No assignments' : 'Needs attention';
        $statusClass = $summary['total'] === 0 ? 'secondary' : 'danger';
    }
    $rolePerformance[] = [
        'key' => $roleKey,
        'label' => $meta['label'],
        'icon' => $meta['icon'],
        'accent' => $meta['accent'],
        'total' => $summary['total'],
        'completed' => $summary['completed'],
        'active' => $summary['active'],
        'percent' => $percent,
        'status_label' => $statusLabel,
        'status_class' => $statusClass,
    ];
}

$engagedReviewerCount = 0;
$reviewerCountSql = "
    SELECT COUNT(DISTINCT cra.reviewer_id) AS total
    FROM concept_reviewer_assignments cra
    JOIN users u ON u.id = cra.student_id
    WHERE cra.reviewer_id IS NOT NULL
";
if ($conceptScopeWhere !== '') {
    $reviewerCountSql .= "      AND {$conceptScopeWhere}\n";
}
if ($reviewerCountResult = $conn->query($reviewerCountSql)) {
    $reviewerCountRow = $reviewerCountResult->fetch_assoc();
    $engagedReviewerCount = (int)($reviewerCountRow['total'] ?? 0);
    $reviewerCountResult->free();
}

$reviewerLoadList = [];
$reviewerLoadSql = "
    SELECT
        cra.reviewer_id,
        cra.reviewer_role,
        COUNT(*) AS total_assignments,
        SUM(CASE WHEN cra.status = 'completed' THEN 1 ELSE 0 END) AS completed_assignments,
        SUM(CASE WHEN cra.status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS active_assignments,
        MAX(cra.updated_at) AS last_activity,
        CONCAT(COALESCE(r.firstname, ''), ' ', COALESCE(r.lastname, '')) AS reviewer_name
    FROM concept_reviewer_assignments cra
    LEFT JOIN users r ON r.id = cra.reviewer_id
    JOIN users u ON u.id = cra.student_id
    WHERE cra.reviewer_id IS NOT NULL
";
if ($conceptScopeWhere !== '') {
    $reviewerLoadSql .= "      AND {$conceptScopeWhere}\n";
}
$reviewerLoadSql .= "
    GROUP BY cra.reviewer_id, cra.reviewer_role, reviewer_name
    HAVING COUNT(*) > 0
    ORDER BY active_assignments DESC, total_assignments DESC
    LIMIT 6
";
if ($reviewerLoadResult = $conn->query($reviewerLoadSql)) {
    while ($row = $reviewerLoadResult->fetch_assoc()) {
        $reviewerLoadList[] = $row;
    }
    $reviewerLoadResult->free();
}

$upcomingReviewerDeadlines = [];
$deadlineSql = "
    SELECT
        cra.id,
        cra.due_at,
        cra.reviewer_role,
        cra.status,
        cp.title,
        CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name
    FROM concept_reviewer_assignments cra
    LEFT JOIN concept_papers cp ON cp.id = cra.concept_paper_id
    JOIN users u ON u.id = cra.student_id
    WHERE cra.due_at IS NOT NULL
";
if ($conceptScopeWhere !== '') {
    $deadlineSql .= "      AND {$conceptScopeWhere}\n";
}
$deadlineSql .= "
      AND cra.status IN ('pending','in_progress')
    ORDER BY cra.due_at ASC
    LIMIT 8
";
if ($deadlineResult = $conn->query($deadlineSql)) {
    while ($row = $deadlineResult->fetch_assoc()) {
        $upcomingReviewerDeadlines[] = $row;
    }
    $deadlineResult->free();
}

$totalAssignments = $assignmentStats['total'];
$completedAssignments = $assignmentStats['completed'];
$pendingAssignments = $assignmentStats['pending'];
$dueSoonAssignments = $assignmentStats['due_soon'];
$completionPercent = $totalAssignments > 0 ? round(($completedAssignments / $totalAssignments) * 100) : 0;
$activeLoadPerReviewer = $engagedReviewerCount > 0 ? round($pendingAssignments / $engagedReviewerCount, 1) : 0;
$perReviewerCompleted = $engagedReviewerCount > 0 ? round($completedAssignments / $engagedReviewerCount, 1) : 0;
$roleCoverageCount = count(array_filter($rolePerformance, static fn($role) => ($role['total'] ?? 0) > 0));
$dueSoonShare = $pendingAssignments > 0 ? round(($dueSoonAssignments / $pendingAssignments) * 100) : 0;

$insightBullets = [
    [
        'title' => 'Completion rate',
        'value' => "{$completionPercent}%",
        'description' => $completionPercent >= 65
            ? 'Reviews are closing at a solid pace. Keep momentum by queuing fresh titles.'
            : 'Completion rate could improve. Consider nudging reviewers who are mid-stream.',
    ],
    [
        'title' => 'Role coverage',
        'value' => "{$roleCoverageCount}/" . count($roleMeta),
        'description' => $roleCoverageCount === count($roleMeta)
            ? 'All reviewer groups are active across current concepts.'
            : 'Some reviewer groups are idle. Balance assignments to expand coverage.',
    ],
    [
        'title' => 'Due soon load',
        'value' => "{$dueSoonAssignments} tasks",
        'description' => $dueSoonShare > 40
            ? 'A large portion of pending work is due inside seven days. Prioritize outreach.'
            : 'Due-soon tasks are manageable at the moment.',
    ],
    [
        'title' => 'Per-reviewer output',
        'value' => "{$perReviewerCompleted} completed",
        'description' => $perReviewerCompleted >= 3
            ? 'Top performers are wrapping multiple titles each.'
            : 'Throughput per reviewer is light. Offer more assignments or guidance.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reviewer Pipeline</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
    <style>
        .pipeline-hero {
            border-radius: 18px;
            padding: 1.75rem;
            background: radial-gradient(circle at top, rgba(20,82,44,0.25), rgba(20,82,44,0.05)),
                        linear-gradient(135deg, #0b4619, #124d77);
            color: #fff;
        }
        .pipeline-hero h1 {
            font-size: 1.8rem;
            font-weight: 600;
        }
        .pipeline-hero p {
            color: rgba(255,255,255,0.85);
        }
        .metric-card {
            border-radius: 16px;
            border: none;
            box-shadow: 0 24px 60px rgba(15,61,31,0.08);
        }
        .metric-card .icon-pill {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.4rem;
        }
        .metric-trend {
            font-size: 0.85rem;
        }
        .role-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.9rem;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .progress-thin {
            height: 8px;
            border-radius: 6px;
            background-color: rgba(4,39,24,0.08);
        }
        .progress-thin .progress-bar {
            border-radius: 6px;
        }
        .timeline-item {
            border-left: 3px solid rgba(13,110,253,0.25);
            padding-left: 1rem;
            margin-left: 0.7rem;
            margin-bottom: 1.5rem;
        }
        .timeline-item:last-child {
            margin-bottom: 0;
        }
        .timeline-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #0d6efd;
            display: inline-block;
            margin-left: -1.45rem;
            margin-right: 0.5rem;
        }
        .load-card {
            border-radius: 14px;
            border: 1px solid rgba(22,86,44,0.08);
        }
        .load-card .reviewer-name {
            font-weight: 600;
        }
        .insight-card {
            border-radius: 16px;
            background: linear-gradient(150deg, rgba(18,86,44,0.07), rgba(18,86,44,0.02));
        }
        .insight-card .insight-item + .insight-item {
            border-top: 1px solid rgba(4,39,24,0.08);
            padding-top: 1rem;
        }
        .insight-item {
            padding: 0.75rem 0;
        }
        @media (max-width: 768px) {
            .pipeline-hero {
                padding: 1.25rem;
            }
            .pipeline-hero h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content dashboard-content">
    <div class="container-fluid py-4">
        <div class="pipeline-hero mb-4">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <div>
                    <p class="text-uppercase small mb-2 fw-semibold text-white-50">Reviewer Operations</p>
                    <h1 class="mb-2">Reviewer Pipeline Health</h1>
                    <p class="mb-0">Monitor how every reviewer group is progressing with assigned concept titles and quickly spot bottlenecks.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="assign_faculty.php" class="btn btn-warning text-dark fw-semibold">
                        <i class="bi bi-diagram-3 me-1"></i> Assign Reviewers
                    </a>
                    <a href="assign_panel.php" class="btn btn-outline-light fw-semibold">
                        <i class="bi bi-people me-1"></i> Coordinate Panel
                    </a>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-pill bg-success-subtle text-success">
                            <i class="bi bi-stack"></i>
                        </div>
                        <span class="badge bg-success-subtle text-success">Pipeline</span>
                    </div>
                    <h6 class="text-muted text-uppercase small mb-1">Assignments in flight</h6>
                    <h2 class="fw-semibold mb-0"><?= number_format($totalAssignments); ?></h2>
                    <p class="text-muted small mb-0"> <?= number_format($completedAssignments); ?> completed &middot; <?= number_format($pendingAssignments); ?> active</p>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-pill bg-primary-subtle text-primary">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <span class="badge bg-primary-subtle text-primary"><?= htmlspecialchars($completionPercent); ?>%</span>
                    </div>
                    <h6 class="text-muted text-uppercase small mb-1">Completion rate</h6>
                    <h2 class="fw-semibold mb-0"><?= htmlspecialchars($completionPercent); ?>%</h2>
                    <p class="text-muted small mb-0">Average <?= htmlspecialchars($perReviewerCompleted); ?> reviews per reviewer</p>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-pill bg-info-subtle text-info">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <span class="badge bg-info-subtle text-info"><?= number_format($engagedReviewerCount); ?></span>
                    </div>
                    <h6 class="text-muted text-uppercase small mb-1">Engaged reviewers</h6>
                    <h2 class="fw-semibold mb-0"><?= number_format($engagedReviewerCount); ?></h2>
                    <p class="text-muted small mb-0">~<?= htmlspecialchars($activeLoadPerReviewer); ?> active tasks each</p>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="icon-pill bg-danger-subtle text-danger">
                            <i class="bi bi-alarm"></i>
                        </div>
                        <span class="badge bg-danger-subtle text-danger"><?= number_format($dueSoonAssignments); ?> due soon</span>
                    </div>
                    <h6 class="text-muted text-uppercase small mb-1">Time-sensitive</h6>
                    <h2 class="fw-semibold mb-0"><?= number_format($dueSoonAssignments); ?></h2>
                    <p class="text-muted small mb-0"><?= number_format($overdueAssignments); ?> overdue tasks flagged</p>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-7">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Reviewer Progress by Role</h2>
                            <p class="text-muted small mb-0">Track how each reviewer group is balancing active work versus completed titles.</p>
                        </div>
                        <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($roleCoverageCount); ?> roles engaged</span>
                    </div>
                    <div class="card-body">
                        <?php if (array_sum(array_column($rolePerformance, 'total')) === 0): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-clipboard-data fs-2 d-block mb-2"></i>
                                No reviewer assignments yet. Start by routing concept titles to your reviewer pool.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Reviewer Group</th>
                                        <th class="text-center">Active</th>
                                        <th class="text-center">Completed</th>
                                        <th style="width: 35%;">Progress</th>
                                        <th>Status</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($rolePerformance as $roleRow): ?>
                                        <tr>
                                            <td>
                                                <span class="role-chip bg-<?= htmlspecialchars($roleRow['accent']); ?>-subtle text-<?= htmlspecialchars($roleRow['accent']); ?>">
                                                    <i class="bi <?= htmlspecialchars($roleRow['icon']); ?>"></i>
                                                    <?= htmlspecialchars($roleRow['label']); ?>
                                                </span>
                                            </td>
                                            <td class="text-center fw-semibold"><?= number_format($roleRow['active']); ?></td>
                                            <td class="text-center text-success fw-semibold"><?= number_format($roleRow['completed']); ?></td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    <div class="progress progress-thin">
                                                        <div class="progress-bar bg-<?= htmlspecialchars($roleRow['accent']); ?>" style="width: <?= (int)$roleRow['percent']; ?>%;"></div>
                                                    </div>
                                                    <small class="text-muted"><?= (int)$roleRow['percent']; ?>% complete</small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= htmlspecialchars($roleRow['status_class']); ?>-subtle text-<?= htmlspecialchars($roleRow['status_class']); ?>">
                                                    <?= htmlspecialchars($roleRow['status_label']); ?>
                                                </span>
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
            <div class="col-xl-5">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Upcoming Reviewer Deadlines</h2>
                            <p class="text-muted small mb-0">Assignments due within the next seven days.</p>
                        </div>
                        <span class="badge bg-danger-subtle text-danger"><?= number_format($dueSoonAssignments); ?> due soon</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcomingReviewerDeadlines)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-calendar2-check fs-2 d-block mb-2"></i>
                                No reviewer deadlines scheduled this week.
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcomingReviewerDeadlines as $deadline): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot"></div>
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="fw-semibold text-dark">
                                                <?= htmlspecialchars($deadline['student_name'] ?: 'Student'); ?>
                                            </div>
                                            <small class="text-muted d-block mb-1">
                                                <?= htmlspecialchars($deadline['title'] ?? 'Untitled Concept'); ?>
                                            </small>
                                            <span class="badge bg-secondary-subtle text-capitalize text-secondary">
                                                <?= htmlspecialchars($deadline['reviewer_role'] ?? 'reviewer'); ?>
                                            </span>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold"><?= htmlspecialchars(formatDateDisplay($deadline['due_at'] ?? null)); ?></div>
                                            <small class="text-muted"><?= htmlspecialchars(formatRelativeDue($deadline['due_at'] ?? null)); ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Reviewer Load Monitor</h2>
                            <p class="text-muted small mb-0">Top reviewers by active workload and throughput.</p>
                        </div>
                        <a href="create_faculty.php" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-person-plus"></i> Expand Pool
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reviewerLoadList)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-person-gear fs-2 d-block mb-2"></i>
                                No active reviewer workload yet. Once assignments go out, you will see capacity indicators here.
                            </div>
                        <?php else: ?>
                            <?php foreach ($reviewerLoadList as $reviewer): ?>
                                <?php
                                    $active = (int)($reviewer['active_assignments'] ?? 0);
                                    $total = (int)($reviewer['total_assignments'] ?? 0);
                                    $completed = (int)($reviewer['completed_assignments'] ?? 0);
                                    $loadPercent = $total > 0 ? round(($active / $total) * 100) : 0;
                                ?>
                                <div class="load-card p-3 mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <div class="reviewer-name">
                                                <?= htmlspecialchars(trim($reviewer['reviewer_name'] ?? '') ?: 'Reviewer'); ?>
                                            </div>
                                            <small class="text-muted text-capitalize"><?= htmlspecialchars($reviewer['reviewer_role'] ?? 'role'); ?></small>
                                        </div>
                                        <span class="badge bg-primary-subtle text-primary"><?= number_format($active); ?> active</span>
                                    </div>
                                    <div class="progress progress-thin mb-2">
                                        <div class="progress-bar bg-success" style="width: <?= $loadPercent; ?>%;"></div>
                                    </div>
                                    <div class="d-flex justify-content-between text-muted small">
                                        <span><?= number_format($completed); ?> completed</span>
                                        <span>Last touch: <?= htmlspecialchars(formatDateDisplay($reviewer['last_activity'] ?? null)); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card shadow-sm border-0 insight-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="h6 fw-semibold mb-1">Pipeline Insights</h2>
                                <p class="text-muted small mb-0">Quick recommendations based on current workload.</p>
                            </div>
                            <i class="bi bi-lightbulb text-warning fs-3"></i>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php foreach ($insightBullets as $insight): ?>
                            <div class="insight-item">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold text-dark"><?= htmlspecialchars($insight['title']); ?></span>
                                    <span class="badge bg-dark-subtle text-dark"><?= htmlspecialchars($insight['value']); ?></span>
                                </div>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($insight['description']); ?></p>
                            </div>
                        <?php endforeach; ?>
                        <div class="alert border-0 bg-warning-subtle text-warning mt-4 mb-0">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-exclamation-triangle"></i>
                                <div>
                                    <strong><?= number_format($overdueAssignments); ?> overdue</strong> reviews need attention. Consider reassigning if reviewers are unavailable.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
