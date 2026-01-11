<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';

enforce_role_access(['committee_chair', 'committee_chairperson']);

function formatScheduleLabel(?string $date, ?string $time): string
{
    $date = trim((string)$date);
    $time = trim((string)$time);
    if ($date === '' && $time === '') {
        return 'To be scheduled';
    }
    $combo = trim($date . ' ' . $time);
    $ts = strtotime($combo);
    if ($ts) {
        return date('F d, Y • g:i A', $ts);
    }
    if ($date !== '' && ($d = strtotime($date))) {
        return date('F d, Y', $d);
    }
    if ($time !== '' && ($t = strtotime($time))) {
        return date('g:i A', $t);
    }
    return 'To be scheduled';
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = trim(
    ($_SESSION['firstname'] ?? $_SESSION['first_name'] ?? '') . ' ' .
    ($_SESSION['lastname'] ?? $_SESSION['last_name'] ?? '')
);
$userName = $userName !== '' ? $userName : 'Committee Chair';

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$conditions = [
    "dp.panel_role = 'committee_chair' AND (dp.panel_member_id = ? OR (dp.panel_member_id IS NULL AND dp.panel_member LIKE ?))",
    "NOT (ds.status = 'Pending' AND EXISTS (
        SELECT 1
        FROM defense_schedules ds2
        WHERE ds2.student_id = ds.student_id
          AND ds2.status IN ('Confirmed','Completed')
          AND ds2.id <> ds.id
    ))"
];
$types = 'is';
$params = [$userId, '%' . $userName . '%'];

if ($search !== '') {
    $conditions[] = "(CONCAT_WS(' ', stu.firstname, stu.lastname) LIKE ? OR s.title LIKE ? OR ds.venue LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $types .= 'sss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status !== '' && strtolower($status) !== 'all') {
    $conditions[] = "ds.status = ?";
    $types .= 's';
    $params[] = $status;
}

$sql = "
    SELECT
        ds.id,
        ds.defense_date,
        ds.defense_time,
        ds.venue,
        ds.status,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
        s.title AS submission_title
    FROM defense_schedules ds
    JOIN users stu ON ds.student_id = stu.id
    LEFT JOIN submissions s ON s.student_id = stu.id
    JOIN defense_panels dp ON dp.defense_id = ds.id
    WHERE " . implode(' AND ', $conditions) . "
    GROUP BY ds.id
    ORDER BY ds.defense_date IS NULL, ds.defense_date ASC, ds.defense_time ASC, ds.id DESC
";

$assignments = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $assignments = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
    $stmt->close();
}

$panelMembersStmt = $conn->prepare("SELECT panel_member, panel_role FROM defense_panels WHERE defense_id = ? ORDER BY id");
$roleLabels = [
    'adviser' => 'Adviser',
    'committee_chair' => 'Committee Chair',
    'panel_member' => 'Panel Member',
];

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Defense Assignments - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .panel-chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.65rem; border-radius: 999px; background: rgba(22, 86, 44, 0.08); font-size: 0.8rem; margin-right: 0.35rem; margin-bottom: 0.35rem; }
        .panel-chip i { font-size: 0.9rem; color: #16562c; }
        .filter-card { border-radius: 16px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
        .filter-card .form-control, .filter-card .form-select { border-radius: 999px; }
        .committee-table thead th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #556; }
        .committee-table td { vertical-align: top; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-briefcase me-2"></i>My Defense Assignments</h3>
                <p class="text-muted mb-0">Schedules assigned to you as the committee chair.</p>
            </div>
            <a href="my_committee_defense.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        </div>

        <div class="card filter-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold text-success">Search</label>
                        <input type="search" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control" placeholder="Search student, title, or venue">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold text-success">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <?php foreach (['Pending','Confirmed','Completed','Cancelled'] as $option): ?>
                                <option value="<?= $option; ?>" <?= $status === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button type="submit" class="btn btn-success"><i class="bi bi-search me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($assignments)): ?>
            <div class="alert alert-light border text-center">
                <i class="bi bi-calendar2-x fs-3 d-block mb-2 text-success"></i>
                No defense schedules have been assigned to you yet.
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 committee-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Schedule</th>
                                    <th>Venue</th>
                                    <th>Panel Members</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                    <?php
                                        $scheduleLabel = formatScheduleLabel($assignment['defense_date'] ?? null, $assignment['defense_time'] ?? null);
                                        $statusValue = $assignment['status'] ?? 'Pending';
                                        $badgeClass = match (strtolower($statusValue)) {
                                            'confirmed' => 'bg-success-subtle text-success',
                                            'completed' => 'bg-primary-subtle text-primary',
                                            'cancelled' => 'bg-danger-subtle text-danger',
                                            default => 'bg-secondary-subtle text-secondary',
                                        };
                                        $panelRows = [];
                                        if ($panelMembersStmt) {
                                            $panelMembersStmt->bind_param('i', $assignment['id']);
                                            $panelMembersStmt->execute();
                                            $panelResult = $panelMembersStmt->get_result();
                                            $panelRows = $panelResult ? $panelResult->fetch_all(MYSQLI_ASSOC) : [];
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-success">
                                                <?= htmlspecialchars($assignment['student_name'] ?? 'Student'); ?>
                                            </div>
                                            <div class="text-muted small">
                                                <?= htmlspecialchars($assignment['submission_title'] ?? 'Research Title'); ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($scheduleLabel); ?></td>
                                        <td><?= htmlspecialchars($assignment['venue'] ?? 'To be announced'); ?></td>
                                        <td>
                                            <?php if (empty($panelRows)): ?>
                                                <span class="text-muted small">Panel not assigned yet.</span>
                                            <?php else: ?>
                                                <?php foreach ($panelRows as $memberRow): ?>
                                                    <?php
                                                        $memberName = trim($memberRow['panel_member'] ?? '');
                                                        if ($memberName === '') {
                                                            continue;
                                                        }
                                                        $roleText = $roleLabels[$memberRow['panel_role'] ?? 'panel_member'] ?? 'Panel Member';
                                                    ?>
                                                    <span class="panel-chip">
                                                        <i class="bi bi-person"></i><strong><?= htmlspecialchars($roleText); ?>:</strong> <?= htmlspecialchars($memberName); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($statusValue); ?></span></td>
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

<?php if ($panelMembersStmt) { $panelMembersStmt->close(); } ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
