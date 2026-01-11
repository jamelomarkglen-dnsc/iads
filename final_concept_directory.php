<?php
session_start();
require_once 'db.php';
require_once 'final_concept_helpers.php';
require_once 'chair_scope_helper.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header("Location: login.php");
    exit;
}


ensureFinalConceptSubmissionTable($conn);
$chairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $chairId);
$directoryStatusAlert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['final_action'] ?? '') === 'update_final_submission') {
    $submissionId = (int)($_POST['final_submission_id'] ?? 0);
    $newStatus = trim($_POST['final_status'] ?? '');
    $remarks = trim((string)($_POST['final_remarks'] ?? ''));
    $allowedStatuses = ['Pending', 'Under Review', 'Approved', 'Returned'];

    if ($submissionId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        $directoryStatusAlert = ['type' => 'danger', 'message' => 'Invalid final submission update request.'];
    } elseif ($newStatus === 'Returned' && $remarks === '') {
        $directoryStatusAlert = ['type' => 'warning', 'message' => 'Please add remarks when returning a submission.'];
    } else {
        $detailStmt = $conn->prepare("
            SELECT fcs.id, fcs.student_id, fcs.final_title, fcs.remarks
            FROM final_concept_submissions fcs
            WHERE fcs.id = ?
            LIMIT 1
        ");
        $submission = null;
        if ($detailStmt) {
            $detailStmt->bind_param('i', $submissionId);
            if ($detailStmt->execute()) {
                $result = $detailStmt->get_result();
                $submission = $result ? $result->fetch_assoc() : null;
                if ($result) {
                    $result->free();
                }
            }
            $detailStmt->close();
        }

        if (!$submission) {
            $directoryStatusAlert = ['type' => 'danger', 'message' => 'Final concept submission not found.'];
        } elseif (!student_matches_scope($conn, (int)$submission['student_id'], $chairScope)) {
            $directoryStatusAlert = ['type' => 'danger', 'message' => 'You do not have permission to update this record.'];
        } else {
            $updateStmt = $conn->prepare("
                UPDATE final_concept_submissions
                SET status = ?, remarks = ?, reviewed_at = NOW()
                WHERE id = ?
            ");
            if ($updateStmt) {
                $finalRemarks = ($remarks !== '' || $newStatus === 'Returned') ? $remarks : ($submission['remarks'] ?? '');
                $updateStmt->bind_param('ssi', $newStatus, $finalRemarks, $submissionId);
                if ($updateStmt->execute()) {
                    $directoryStatusAlert = ['type' => 'success', 'message' => 'Final concept entry updated.'];
                    notify_user(
                        $conn,
                        (int)$submission['student_id'],
                        'Final concept review update',
                        "Your final concept titled \"{$submission['final_title']}\" is now {$newStatus}." . ($finalRemarks ? " Remarks: {$finalRemarks}" : ''),
                        'submit_paper.php'
                    );
                } else {
                    $directoryStatusAlert = ['type' => 'danger', 'message' => 'Unable to update the record right now.'];
                }
                $updateStmt->close();
            } else {
                $directoryStatusAlert = ['type' => 'danger', 'message' => 'Unable to prepare the update command.'];
            }
        }
    }
}

$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$conceptScopeWhere = render_scope_condition($conn, $chairScope, 'u');

$conditions = [];
if ($conceptScopeWhere !== '') {
    $conditions[] = $conceptScopeWhere;
}
if ($statusFilter !== '' && in_array($statusFilter, ['Pending', 'Under Review', 'Approved', 'Returned'], true)) {
    $conditions[] = "fcs.status = '" . $conn->real_escape_string($statusFilter) . "'";
}
if ($search !== '') {
    $searchEscaped = $conn->real_escape_string('%' . $search . '%');
    $conditions[] = "(fcs.final_title LIKE '{$searchEscaped}' OR CONCAT(u.firstname, ' ', u.lastname) LIKE '{$searchEscaped}')";
}

$whereClause = '';
if (!empty($conditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
}

$statusCounts = [
    'Pending' => 0,
    'Under Review' => 0,
    'Approved' => 0,
    'Returned' => 0,
];
$statusCountSql = "
    SELECT status, COUNT(*) AS total
    FROM final_concept_submissions fcs
    JOIN users u ON u.id = fcs.student_id
    " . ($conceptScopeWhere ? "WHERE {$conceptScopeWhere}" : "") . "
    GROUP BY status
";
if ($countResult = $conn->query($statusCountSql)) {
    while ($row = $countResult->fetch_assoc()) {
        $key = $row['status'] ?? '';
        if (isset($statusCounts[$key])) {
            $statusCounts[$key] = (int)$row['total'];
        }
    }
    $countResult->free();
}

$submissionsSql = "
    SELECT
        fcs.id,
        fcs.final_title,
        fcs.status,
        fcs.submitted_at,
        fcs.reviewed_at,
        fcs.remarks,
        fcs.file_path,
        cp.title AS proposal_title,
        CONCAT(u.firstname, ' ', u.lastname) AS student_name
    FROM final_concept_submissions fcs
    JOIN users u ON u.id = fcs.student_id
    LEFT JOIN concept_papers cp ON cp.id = fcs.concept_paper_id
    {$whereClause}
    ORDER BY fcs.submitted_at DESC
";
$submissions = [];
if ($result = $conn->query($submissionsSql)) {
    while ($row = $result->fetch_assoc()) {
        $submissions[] = $row;
    }
    $result->free();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Concept Directory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .content { margin-left: 220px; padding: 24px; background: #f5f7fb; min-height: 100vh; }
        #sidebar.collapsed ~ .content { margin-left: 70px; }
        .card { border-radius: 20px; border: none; box-shadow: 0 12px 35px rgba(22, 86, 44, 0.1); }
        .badge-status { border-radius: 999px; padding: .35rem .75rem; font-size: .78rem; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>
<div class="content">
    <div class="container-fluid py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 gap-3">
            <div>
                <p class="text-uppercase text-muted small mb-1">Program chair tools</p>
                <h1 class="h4 mb-1 text-success">Final Concept Title Directory</h1>
                <p class="text-muted mb-0">Browse every final concept submission filed under your program.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="program_chairperson.php#finalConceptSection" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left-circle me-2"></i>Back to Dashboard
                </a>
                <button id="refreshDirectory" class="btn btn-success"><i class="bi bi-arrow-repeat me-1"></i>Refresh</button>
            </div>
        </div>

        <?php if ($directoryStatusAlert): ?>
            <div class="alert alert-<?= htmlspecialchars($directoryStatusAlert['type']); ?> border-0 shadow-sm">
                <?= htmlspecialchars($directoryStatusAlert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <?php
                        $statusBadges = [
                            'Pending' => 'bg-warning-subtle text-warning',
                            'Under Review' => 'bg-info-subtle text-info',
                            'Approved' => 'bg-success-subtle text-success',
                            'Returned' => 'bg-danger-subtle text-danger',
                        ];
                    ?>
                    <?php foreach ($statusCounts as $label => $count): ?>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-4 p-3 d-flex justify-content-between align-items-center shadow-sm bg-white">
                                <div>
                                    <p class="text-muted text-uppercase small mb-1"><?= htmlspecialchars($label); ?></p>
                                    <h4 class="mb-0"><?= number_format($count); ?></h4>
                                </div>
                                <span class="badge <?= $statusBadges[$label] ?? 'bg-light text-muted'; ?>">
                                    <i class="bi bi-circle-fill me-1"></i>Status
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Search student or title</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control" placeholder="Type name or concept title">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <?php foreach (['Pending','Under Review','Approved','Returned'] as $statusOption): ?>
                                <option value="<?= $statusOption; ?>" <?= $statusFilter === $statusOption ? 'selected' : ''; ?>>
                                    <?= $statusOption; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-success w-100"><i class="bi bi-search me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <?php if (empty($submissions)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-collection fs-2 mb-2"></i>
                        <p class="mb-0">No final concept submissions found for the selected filter.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Final Title</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Remarks</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submissions as $row): ?>
                                    <?php
                                        $status = $row['status'] ?? 'Pending';
                                        $badge = finalConceptStatusClass($status);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($row['student_name'] ?? 'Student'); ?></strong><br>
                                            <small class="text-muted">Proposal: <?= htmlspecialchars($row['proposal_title'] ?? 'n/a'); ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($row['final_title'] ?? 'Untitled'); ?></td>
                                        <td>
                                            <form method="POST" class="d-flex gap-2 align-items-center">
                                                <input type="hidden" name="final_submission_id" value="<?= (int)$row['id']; ?>">
                                                <input type="hidden" name="final_action" value="update_final_submission">
                                                <input type="hidden" name="final_remarks" value="<?= htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES); ?>">
                                                <select name="final_status" class="form-select form-select-sm">
                                                    <?php foreach (['Pending','Under Review','Approved','Returned'] as $statusOption): ?>
                                                        <option value="<?= $statusOption; ?>" <?= $status === $statusOption ? 'selected' : ''; ?>>
                                                            <?= $statusOption; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-success">Update</button>
                                            </form>
                                            <span class="badge badge-status <?= $badge; ?> mt-2"><?= htmlspecialchars($status); ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($row['submitted_at'] ?? 'Not recorded'); ?></td>
                                        <td>
                                            <form method="POST" class="d-flex flex-column flex-lg-row gap-2 align-items-stretch">
                                                <input type="hidden" name="final_submission_id" value="<?= (int)$row['id']; ?>">
                                                <input type="hidden" name="final_action" value="update_final_submission">
                                                <input type="hidden" name="final_status" value="<?= htmlspecialchars($row['status'] ?? 'Pending'); ?>">
                                                <div class="flex-grow-1">
                                                    <input type="text" name="final_remarks" class="form-control form-control-sm" placeholder="Add remarks" value="<?= htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES); ?>">
                                                </div>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-save me-1"></i>Save
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!empty($row['file_path'])): ?>
                                                <a href="<?= htmlspecialchars($row['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-file-earmark-arrow-down"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="program_chairperson.php#finalConceptSection" class="btn btn-sm btn-outline-success">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const refreshBtn = document.getElementById('refreshDirectory');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Refreshing';
            window.location.reload();
        });
    }
</script>
</body>
</html>
