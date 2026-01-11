<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';

enforce_role_access(['adviser']);

if (!function_exists('defenseTableExists')) {
    function defenseTableExists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $sql = "
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
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

if (!function_exists('defenseColumnExists')) {
    function defenseColumnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
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

if (!function_exists('buildAdvisorWhereClause')) {
    function buildAdvisorWhereClause(string $alias, array $columns): string
    {
        $parts = array_map(fn($column) => "{$alias}.{$column} = ?", $columns);
        return '(' . implode(' OR ', $parts) . ')';
    }
}

if (!function_exists('formatDefenseScheduleLabel')) {
    function formatDefenseScheduleLabel(?string $date, ?string $time): string
    {
        $date = $date ? trim($date) : '';
        $time = $time ? trim($time) : '';
        if ($date === '' && $time === '') {
            return 'Schedule to be determined';
        }
        $timestamp = trim($date . ' ' . $time);
        $parsed = strtotime($timestamp);
        if ($parsed) {
            return date('F d, Y • g:i A', $parsed);
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
        return 'Schedule to be determined';
    }
}

$advisorId = (int)($_SESSION['user_id'] ?? 0);
$advisorName = trim(
    ($_SESSION['firstname'] ?? $_SESSION['first_name'] ?? '') . ' ' .
    ($_SESSION['lastname'] ?? $_SESSION['last_name'] ?? '')
);
$advisorName = $advisorName !== '' ? $advisorName : 'Adviser';

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$assignments = [];
$useDefensePanels = defenseTableExists($conn, 'defense_panels')
    && defenseColumnExists($conn, 'defense_panels', 'panel_role');

if ($useDefensePanels) {
    $conditions = [
        "dp.panel_role = 'adviser'",
        "(dp.panel_member_id = ? OR (dp.panel_member_id IS NULL AND dp.panel_member LIKE ?))",
        "NOT (ds.status = 'Pending' AND EXISTS (
            SELECT 1
            FROM defense_schedules ds2
            WHERE ds2.student_id = ds.student_id
              AND ds2.status IN ('Confirmed','Completed')
              AND ds2.id <> ds.id
        ))"
    ];
    $types = 'is';
    $params = [$advisorId, '%' . $advisorName . '%'];

    if ($search !== '') {
        $conditions[] = "(CONCAT_WS(' ', stu.firstname, stu.lastname) LIKE ? OR s.title LIKE ? OR ds.venue LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $types .= 'sss';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $conditions[] = "ds.status = ?";
        $types .= 's';
        $params[] = $statusFilter;
    }

    $panelsSql = "
        SELECT ds.id,
               ds.defense_date,
               ds.defense_time,
               ds.venue,
               ds.status,
               CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
               s.title AS submission_title,
               GROUP_CONCAT(DISTINCT dp_all.panel_member ORDER BY dp_all.panel_member SEPARATOR ', ') AS panel_members
        FROM defense_schedules ds
        JOIN users stu ON ds.student_id = stu.id
        LEFT JOIN submissions s ON s.student_id = stu.id
        JOIN defense_panels dp ON dp.defense_id = ds.id
        LEFT JOIN defense_panels dp_all ON dp_all.defense_id = ds.id
        WHERE " . implode(' AND ', $conditions) . "
        GROUP BY ds.id
        ORDER BY ds.defense_date IS NULL, ds.defense_date ASC, ds.defense_time ASC, ds.id DESC
    ";
    $stmt = $conn->prepare($panelsSql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        $stmt->close();
    }
} else {
    $advisorColumns = [];
    if (adviserUsersColumnExists($conn, 'adviser_id')) {
        $advisorColumns[] = 'adviser_id';
    }
    if (adviserUsersColumnExists($conn, 'advisor_id')) {
        $advisorColumns[] = 'advisor_id';
    }
    if (!empty($advisorColumns)) {
        $advisorWhere = buildAdvisorWhereClause('stu', $advisorColumns);
        $advisorParamTypes = str_repeat('i', count($advisorColumns));
        $advisorParams = array_fill(0, count($advisorColumns), $advisorId);

        $conditions = ["{$advisorWhere}"];
        $conditions[] = "NOT (ds.status = 'Pending' AND EXISTS (
            SELECT 1
            FROM defense_schedules ds2
            WHERE ds2.student_id = ds.student_id
              AND ds2.status IN ('Confirmed','Completed')
              AND ds2.id <> ds.id
        ))";
        $types = $advisorParamTypes;
        $params = $advisorParams;

        if ($search !== '') {
            $conditions[] = "(CONCAT_WS(' ', stu.firstname, stu.lastname) LIKE ? OR s.title LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $types .= 'ss';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $conditions[] = "ds.status = ?";
            $types .= 's';
            $params[] = $statusFilter;
        }

        $panelsSql = "
            SELECT ds.id,
                   ds.defense_date,
                   ds.defense_time,
                   ds.venue,
                   ds.status,
                   CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
                   s.title AS submission_title,
                   GROUP_CONCAT(DISTINCT dp.panel_member ORDER BY dp.panel_member SEPARATOR ', ') AS panel_members
            FROM defense_schedules ds
            JOIN users stu ON ds.student_id = stu.id
            LEFT JOIN submissions s ON s.student_id = stu.id
            LEFT JOIN defense_panels dp ON dp.defense_id = ds.id
            WHERE " . implode(' AND ', $conditions) . "
            GROUP BY ds.id
            ORDER BY ds.defense_date IS NULL, ds.defense_date ASC, ds.defense_time ASC, ds.id DESC
        ";
        $stmt = $conn->prepare($panelsSql);
        if ($stmt) {
            if ($types !== '' && !empty($params)) {
                $bindParams = [$types];
                foreach ($params as $key => $value) {
                    $bindParams[] = &$params[$key];
                }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $assignments[] = $row;
            }
            $stmt->close();
        }
    }
}

$upcomingCount = 0;
$completedCount = 0;
$confirmedCount = 0;
$today = new DateTimeImmutable('today');

foreach ($assignments as $assignment) {
    $status = strtolower($assignment['status'] ?? '');
    if ($status === 'completed') {
        $completedCount++;
    } elseif ($status === 'confirmed') {
        $confirmedCount++;
    }

    $defenseDate = trim((string)($assignment['defense_date'] ?? ''));
    $defenseTime = trim((string)($assignment['defense_time'] ?? ''));
    $timestamp = trim($defenseDate . ' ' . $defenseTime);
    $parsed = strtotime($timestamp);
    if ($parsed && $parsed >= $today->getTimestamp()) {
        $upcomingCount++;
    }
}

$statusOptions = ['Pending', 'Confirmed', 'Completed', 'Postponed'];

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Assigned Defenses - Adviser</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f7fb; }
        .page-wrapper { margin-left: 220px; min-height: 100vh; padding: 24px; transition: margin-left .3s; }
        #sidebar.collapsed ~ .page-wrapper { margin-left: 60px; }
        .hero-banner { border-radius: 24px; padding: 32px; background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; box-shadow: 0 22px 48px rgba(15,61,31,.25); }
        .hero-banner h1 { font-size: 2rem; }
        .hero-banner .btn { border-radius: 999px; }
        .stat-chip { border-radius: 20px; padding: 1rem 1.25rem; background: #fff; box-shadow: 0 16px 32px rgba(15,61,31,.12); }
        .stat-chip small { text-transform: uppercase; color: #7a8699; letter-spacing: .05em; }
        .assignment-card { border: none; border-radius: 20px; box-shadow: 0 18px 40px rgba(15,61,31,.12); transition: transform .2s ease; background: #fff; }
        .assignment-card:hover { transform: translateY(-3px); }
        .assignment-card .badge { border-radius: 999px; }
        .panel-chip { border-radius: 999px; padding: .25rem .8rem; background: #eaf5ee; color: #155732; font-size: .85rem; margin-right: .4rem; margin-bottom: .4rem; display: inline-flex; align-items: center; gap: .3rem; }
        .filter-card { border-radius: 18px; border: none; box-shadow: 0 14px 30px rgba(15,61,31,.12); }
        .assignment-table thead th { font-size: .82rem; text-transform: uppercase; letter-spacing: .05em; color: #556; }
        .assignment-table td { vertical-align: top; }
        .empty-state { text-align: center; padding: 4rem 1rem; color: #7a8594; }
        .empty-state i { font-size: 2.7rem; color: #16562c; margin-bottom: .75rem; display: block; }
        @media (max-width: 992px) {
            .page-wrapper { margin-left: 0; }
            .hero-banner { text-align: center; }
        }
    </style>
</head>
<body>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="hero-banner mb-4">
            <p class="text-uppercase small fw-semibold mb-1">Adviser View</p>
            <h1 class="fw-bold mb-2">My Assigned Defenses</h1>
            <p class="mb-0">Monitor every defense your advisees are scheduled for—complete with venues, panel members, and real-time statuses.</p>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-chip">
                    <small>Total Assignments</small>
                    <h3 class="mb-0"><?= number_format(count($assignments)); ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-chip">
                    <small>Upcoming</small>
                    <h3 class="mb-0 text-success"><?= number_format($upcomingCount); ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-chip">
                    <small>Confirmed</small>
                    <h3 class="mb-0 text-primary"><?= number_format($confirmedCount); ?></h3>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-chip">
                    <small>Completed</small>
                    <h3 class="mb-0 text-muted"><?= number_format($completedCount); ?></h3>
                </div>
            </div>
        </div>

        <form method="GET" class="card filter-card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label text-muted">Search Students / Titles</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent"><i class="bi bi-search text-success"></i></span>
                            <input type="text" name="search" class="form-control" placeholder="e.g. Dela Cruz or Capstone" value="<?= htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label text-muted">Status</label>
                        <select name="status" class="form-select">
                            <option value="all">All statuses</option>
                            <?php foreach ($statusOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option); ?>" <?= strcasecmp($statusFilter, $option) === 0 ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6 d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <a href="my_assigned_defense.php" class="btn btn-outline-secondary flex-fill">Reset</a>
                    </div>
                </div>
            </div>
        </form>

        <?php if (empty($assignments)): ?>
            <div class="card">
                <div class="card-body empty-state">
                    <i class="bi bi-calendar2-x"></i>
                    <p class="mb-2">No defense assignments found for your advisees.</p>
                    <span class="text-muted small">Once schedules are set, they will appear here automatically.</span>
                </div>
            </div>
        <?php else: ?>
            <?php
                $upcomingList = array_filter($assignments, function ($assignment) use ($today) {
                    $date = trim((string)($assignment['defense_date'] ?? ''));
                    $time = trim((string)($assignment['defense_time'] ?? ''));
                    $parsed = strtotime(trim($date . ' ' . $time));
                    return $parsed && $parsed >= $today->getTimestamp();
                });
            ?>
            <div class="row g-4 mb-4">
                <div class="col-12 col-lg-5">
                    <div class="card h-100">
                        <div class="card-header bg-white border-0">
                            <h5 class="mb-0 text-success">Upcoming Timeline</h5>
                            <small class="text-muted">Soonest schedules are listed first</small>
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcomingList)): ?>
                                <div class="empty-state py-3">
                                    <i class="bi bi-calendar-event"></i>
                                    <p class="mb-0">No upcoming defenses. Check back later.</p>
                                </div>
                            <?php else: ?>
                                <ol class="timeline list-unstyled mb-0">
                                    <?php
                                        usort($upcomingList, function ($a, $b) {
                                            $ta = strtotime(trim(($a['defense_date'] ?? '') . ' ' . ($a['defense_time'] ?? ''))) ?: PHP_INT_MAX;
                                            $tb = strtotime(trim(($b['defense_date'] ?? '') . ' ' . ($b['defense_time'] ?? ''))) ?: PHP_INT_MAX;
                                            return $ta <=> $tb;
                                        });
                                        $upcomingList = array_slice($upcomingList, 0, 5);
                                    ?>
                                    <?php foreach ($upcomingList as $item): ?>
                                        <li class="mb-3">
                                            <div class="fw-semibold"><?= htmlspecialchars($item['student_name'] ?? 'Student'); ?></div>
                                            <div class="small text-muted mb-1"><?= htmlspecialchars($item['submission_title'] ?? 'Research Title'); ?></div>
                                            <div class="badge bg-success-subtle text-success"><?= htmlspecialchars(formatDefenseScheduleLabel($item['defense_date'] ?? null, $item['defense_time'] ?? null)); ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-7">
                    <div class="card h-100">
                        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0 text-success">Status Snapshot</h5>
                                <small class="text-muted">Quick overview by state</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php
                                $statusGroups = [];
                                foreach ($assignments as $assignment) {
                                    $key = $assignment['status'] ?? 'Pending';
                                    $statusGroups[$key] = ($statusGroups[$key] ?? 0) + 1;
                                }
                            ?>
                            <?php if (empty($statusGroups)): ?>
                                <p class="text-muted mb-0">No status data available.</p>
                            <?php else: ?>
                                <?php foreach ($statusGroups as $status => $count): ?>
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <div class="progress-bar" style="width: <?= max(10, ($count / max(1, count($assignments))) * 100); ?>%;"></div>
                                        </div>
                                        <span class="ms-3 text-muted small"><?= htmlspecialchars($status); ?> (<?= $count; ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 assignment-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Title</th>
                                    <th>Schedule</th>
                                    <th>Venue</th>
                                    <th>Panel Members</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <?php
                                        $scheduleLabel = formatDefenseScheduleLabel($assignment['defense_date'] ?? null, $assignment['defense_time'] ?? null);
                                        $panelMembers = array_filter(array_map('trim', explode(',', $assignment['panel_members'] ?? '')));
                                        $status = $assignment['status'] ?? 'Pending';
                                        $badgeClass = match (strtolower($status)) {
                                            'confirmed' => 'bg-success-subtle text-success',
                                            'completed' => 'bg-primary-subtle text-primary',
                                            'postponed' => 'bg-warning-subtle text-warning',
                                            default => 'bg-secondary-subtle text-secondary',
                                        };
                                    ?>
                                    <tr>
                                        <td class="fw-semibold text-success"><?= htmlspecialchars($assignment['student_name'] ?? 'Student'); ?></td>
                                        <td><?= htmlspecialchars($assignment['submission_title'] ?? 'Research Title'); ?></td>
                                        <td><?= htmlspecialchars($scheduleLabel); ?></td>
                                        <td><?= htmlspecialchars($assignment['venue'] ?? 'Venue to be announced'); ?></td>
                                        <td>
                                            <?php if (empty($panelMembers)): ?>
                                                <span class="text-muted small">Panel not assigned yet.</span>
                                            <?php else: ?>
                                                <?php foreach ($panelMembers as $member): ?>
                                                    <span class="panel-chip"><i class="bi bi-person"></i><?= htmlspecialchars($member); ?></span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
