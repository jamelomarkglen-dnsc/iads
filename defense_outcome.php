<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'chair_scope_helper.php';
require_once 'notifications_helper.php';
require_once 'defense_outcome_helpers.php';

enforce_role_access(['program_chairperson']);

ensureDefenseOutcomeTable($conn);

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);
[$studentScopeClause, $studentScopeTypes, $studentScopeParams] = build_scope_condition($chairScope, 'stu');

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_outcome'])) {
    $defenseId = (int)($_POST['defense_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $outcome = trim((string)($_POST['outcome'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $allowedOutcomes = ['Thesis Defended', 'Capstone Defended', 'Dissertation Defended'];
    if ($defenseId <= 0 || $studentId <= 0 || !in_array($outcome, $allowedOutcomes, true)) {
        $message = ['type' => 'danger', 'text' => 'Please choose a valid defense outcome.'];
    } elseif (!student_matches_scope($conn, $studentId, $chairScope)) {
        $message = ['type' => 'danger', 'text' => 'Student not in your scope.'];
    } else {
        $stmt = $conn->prepare("
            INSERT INTO defense_outcomes (defense_id, student_id, outcome, notes, set_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                outcome = VALUES(outcome),
                notes = VALUES(notes),
                set_by = VALUES(set_by),
                set_at = NOW()
        ");
        if ($stmt) {
            $stmt->bind_param('iissi', $defenseId, $studentId, $outcome, $notes, $programChairId);
            if ($stmt->execute()) {
                $message = ['type' => 'success', 'text' => 'Defense outcome saved.'];
                notify_user(
                    $conn,
                    $studentId,
                    'Defense outcome updated',
                    "Your defense outcome has been recorded as {$outcome}.",
                    'student_defense_outcome.php',
                    false
                );
            } else {
                $message = ['type' => 'danger', 'text' => 'Unable to save defense outcome.'];
            }
            $stmt->close();
        } else {
            $message = ['type' => 'danger', 'text' => 'Unable to prepare defense outcome update.'];
        }
    }
}

$conditions = ["ds.status IN ('Confirmed','Completed')"];
$types = '';
$params = [];
if ($studentScopeClause !== '') {
    $conditions[] = $studentScopeClause;
    $types .= $studentScopeTypes;
    $params = array_merge($params, $studentScopeParams);
}
$whereClause = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$sql = "
    SELECT
        ds.id AS defense_id,
        ds.student_id,
        ds.defense_date,
        ds.defense_time,
        ds.venue,
        ds.status,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
        o.outcome,
        o.notes,
        o.set_at
    FROM defense_schedules ds
    JOIN users stu ON stu.id = ds.student_id
    LEFT JOIN defense_outcomes o ON o.defense_id = ds.id
    {$whereClause}
    ORDER BY ds.defense_date DESC, ds.defense_time DESC, ds.id DESC
";

$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        bind_scope_params($stmt, $types, $params);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
        }
    }
    $stmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Defense Outcomes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .table thead th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #556; }
        .card { border-radius: 18px; border: none; box-shadow: 0 16px 32px rgba(22, 86, 44, 0.1); }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-check2-circle me-2"></i>Defense Outcomes</h3>
                <p class="text-muted mb-0">Record the final outcome after each defense.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message['type']); ?>">
                <?= htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student</th>
                                <th>Schedule</th>
                                <th>Venue</th>
                                <th>Status</th>
                                <th>Outcome</th>
                                <th>Set Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No defense schedules available.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                        $dateLabel = !empty($row['defense_date']) ? date('M d, Y', strtotime($row['defense_date'])) : 'TBA';
                                        $timeLabel = !empty($row['defense_time']) && $row['defense_time'] !== '00:00:00'
                                            ? ' • ' . date('g:i A', strtotime($row['defense_time']))
                                            : '';
                                        $outcome = $row['outcome'] ?? '';
                                    ?>
                                    <tr>
                                        <td class="fw-semibold text-success"><?= htmlspecialchars($row['student_name'] ?? ''); ?></td>
                                        <td><?= htmlspecialchars($dateLabel . $timeLabel); ?></td>
                                        <td><?= htmlspecialchars($row['venue'] ?? ''); ?></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($row['status'] ?? 'Pending'); ?></span></td>
                                        <td>
                                            <?php if ($outcome): ?>
                                                <span class="badge <?= defense_outcome_badge_class($outcome); ?>"><?= htmlspecialchars($outcome); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" class="d-flex flex-column gap-2">
                                                <input type="hidden" name="defense_id" value="<?= (int)$row['defense_id']; ?>">
                                                <input type="hidden" name="student_id" value="<?= (int)$row['student_id']; ?>">
                                                <select name="outcome" class="form-select form-select-sm" required>
                                                    <option value="">Select outcome</option>
                                                    <?php foreach (['Thesis Defended','Capstone Defended','Dissertation Defended'] as $option): ?>
                                                        <option value="<?= $option; ?>" <?= $outcome === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional notes" value="<?= htmlspecialchars($row['notes'] ?? ''); ?>">
                                                <button type="submit" name="save_outcome" class="btn btn-sm btn-success">Save</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
