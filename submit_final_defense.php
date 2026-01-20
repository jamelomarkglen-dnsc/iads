<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'defense_committee_helpers.php';
require_once 'final_defense_submission_helpers.php';

enforce_role_access(['student']);

ensureDefenseCommitteeRequestsTable($conn);
ensureFinalDefenseSubmissionTable($conn);

$studentId = (int)($_SESSION['user_id'] ?? 0);
$studentName = trim(
    ($_SESSION['firstname'] ?? $_SESSION['first_name'] ?? '') . ' ' .
    ($_SESSION['lastname'] ?? $_SESSION['last_name'] ?? '')
) ?: 'Student';

function submission_column_exists(mysqli $conn, string $column): bool
{
    $column = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'submissions'
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

function fetch_latest_approved_committee(mysqli $conn, int $studentId): ?array
{
    $stmt = $conn->prepare("
        SELECT r.*, 
               CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
               CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name,
               CONCAT(p1.firstname, ' ', p1.lastname) AS panel_one_name,
               CONCAT(p2.firstname, ' ', p2.lastname) AS panel_two_name
        FROM defense_committee_requests r
        LEFT JOIN users adv ON adv.id = r.adviser_id
        LEFT JOIN users ch ON ch.id = r.chair_id
        LEFT JOIN users p1 ON p1.id = r.panel_member_one_id
        LEFT JOIN users p2 ON p2.id = r.panel_member_two_id
        WHERE r.student_id = ? AND r.status = 'Approved'
        ORDER BY r.reviewed_at DESC, r.requested_at DESC, r.id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

$eligibleStatuses = ['Approved', 'Completed', 'Published', 'Accepted'];
$statusPlaceholders = implode(',', array_fill(0, count($eligibleStatuses), '?'));
$statusTypes = str_repeat('s', count($eligibleStatuses));
$orderColumn = submission_column_exists($conn, 'submissions', 'created_at') ? 'created_at' : 'id';

$eligibleSubmissions = [];
$eligSql = "
    SELECT id, title, status
    FROM submissions
    WHERE student_id = ? AND status IN ({$statusPlaceholders})
    ORDER BY {$orderColumn} DESC, id DESC
";
$eligStmt = $conn->prepare($eligSql);
if ($eligStmt) {
    $types = 'i' . $statusTypes;
    $params = array_merge([$studentId], $eligibleStatuses);
    $eligStmt->bind_param($types, ...$params);
    $eligStmt->execute();
    $result = $eligStmt->get_result();
    if ($result) {
        $eligibleSubmissions = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $eligStmt->close();
}

$committee = fetch_latest_approved_committee($conn, $studentId);
$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_final_defense'])) {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $errors = [];

    if ($submissionId <= 0) {
        $errors[] = 'Please select an eligible submission.';
    }
    if (!$committee) {
        $errors[] = 'No approved defense committee request is available yet.';
    }

    $submissionRow = null;
    if (!$errors) {
        $checkSql = "
            SELECT id, title
            FROM submissions
            WHERE id = ? AND student_id = ? AND status IN ({$statusPlaceholders})
            LIMIT 1
        ";
        $checkStmt = $conn->prepare($checkSql);
        if ($checkStmt) {
            $types = 'ii' . $statusTypes;
            $params = array_merge([$submissionId, $studentId], $eligibleStatuses);
            $checkStmt->bind_param($types, ...$params);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $submissionRow = $checkResult ? $checkResult->fetch_assoc() : null;
            $checkStmt->close();
        }
        if (!$submissionRow) {
            $errors[] = 'Selected submission is not eligible for final defense.';
        }
    }

    $uploadError = '';
    $uploadData = null;
    if (!$errors) {
        $uploadData = store_final_defense_file($_FILES['final_defense_file'] ?? null, $uploadError);
        if ($uploadError !== '') {
            $errors[] = $uploadError;
        }
    }

    if (!$errors && $uploadData) {
        $duplicateStmt = $conn->prepare("
            SELECT id
            FROM final_defense_submissions
            WHERE submission_id = ? AND student_id = ? AND status = 'Submitted'
            LIMIT 1
        ");
        if ($duplicateStmt) {
            $duplicateStmt->bind_param('ii', $submissionId, $studentId);
            $duplicateStmt->execute();
            $duplicateResult = $duplicateStmt->get_result();
            $duplicate = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
            $duplicateStmt->close();
            if ($duplicate) {
                $errors[] = 'You already have a pending final defense submission for this entry.';
            }
        }
    }

    if ($errors) {
        $alert = ['type' => 'danger', 'message' => implode(' ', $errors)];
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO final_defense_submissions
                (submission_id, student_id, adviser_id, chair_id, panel_member_one_id, panel_member_two_id, defense_id, file_path, file_name, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($insertStmt) {
            $insertStmt->bind_param(
                'iiiiiiisss',
                $submissionId,
                $studentId,
                $committee['adviser_id'],
                $committee['chair_id'],
                $committee['panel_member_one_id'],
                $committee['panel_member_two_id'],
                $committee['defense_id'],
                $uploadData['path'],
                $uploadData['name'],
                $notes
            );
            if ($insertStmt->execute()) {
                $submissionTitle = $submissionRow['title'] ?? 'the submission';
                $message = "{$studentName} submitted a final defense file for \"{$submissionTitle}\".";
                $link = 'final_defense_inbox.php';
                $notifyIds = array_unique(array_filter([
                    (int)$committee['adviser_id'],
                    (int)$committee['chair_id'],
                    (int)$committee['panel_member_one_id'],
                    (int)$committee['panel_member_two_id'],
                ]));
                foreach ($notifyIds as $userId) {
                    notify_user($conn, $userId, 'Final defense submission received', $message, $link, false);
                }
                $alert = ['type' => 'success', 'message' => 'Final defense submission sent to your committee.'];
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to submit the final defense file.'];
            }
            $insertStmt->close();
        } else {
            $alert = ['type' => 'danger', 'message' => 'Unable to prepare the final defense submission.'];
        }
    }
}

$recentSubmissions = [];
$recentStmt = $conn->prepare("
    SELECT fds.id, fds.status, fds.submitted_at, fds.reviewed_at, fds.review_notes,
           s.title
    FROM final_defense_submissions fds
    LEFT JOIN submissions s ON s.id = fds.submission_id
    WHERE fds.student_id = ?
    ORDER BY fds.submitted_at DESC
    LIMIT 8
");
if ($recentStmt) {
    $recentStmt->bind_param('i', $studentId);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    if ($recentResult) {
        $recentSubmissions = $recentResult->fetch_all(MYSQLI_ASSOC);
        $recentResult->free();
    }
    $recentStmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Defense Submission</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Defense Submission</h3>
                <p class="text-muted mb-0">Upload your final defense file for committee review.</p>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']); ?>">
                <?= htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card card-shell p-4">
                    <h5 class="fw-semibold text-success mb-3">Submit Final Defense File</h5>
                    <?php if (!$committee): ?>
                        <div class="alert alert-warning">
                            No approved defense committee request is available yet. Please wait for committee approval.
                        </div>
                    <?php elseif (empty($eligibleSubmissions)): ?>
                        <div class="alert alert-info">
                            No eligible submissions found. Your submission must be approved before final defense.
                        </div>
                    <?php else: ?>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="submit_final_defense" value="1">
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-success">Select Approved Submission</label>
                                <select name="submission_id" class="form-select" required>
                                    <option value="">Choose...</option>
                                    <?php foreach ($eligibleSubmissions as $submission): ?>
                                        <option value="<?= (int)$submission['id']; ?>">
                                            <?= htmlspecialchars($submission['title']); ?> (<?= htmlspecialchars($submission['status']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-success">Final Defense File (PDF/DOC/DOCX)</label>
                                <input type="file" name="final_defense_file" class="form-control" accept=".pdf,.doc,.docx" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold text-success">Notes (optional)</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Notes for the committee"></textarea>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-upload me-1"></i>Submit for Final Defense
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card card-shell p-4">
                    <h5 class="fw-semibold text-success mb-3">Recent Final Defense Submissions</h5>
                    <?php if (empty($recentSubmissions)): ?>
                        <p class="text-muted mb-0">No final defense submissions yet.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($recentSubmissions as $row): ?>
                                <?php
                                    $status = $row['status'] ?? 'Submitted';
                                    $badgeClass = $status === 'Passed'
                                        ? 'bg-success-subtle text-success'
                                        : ($status === 'Failed' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning');
                                ?>
                                <div class="border rounded-3 p-3">
                                    <div class="fw-semibold"><?= htmlspecialchars($row['title'] ?? 'Submission'); ?></div>
                                    <div class="small text-muted">Submitted <?= htmlspecialchars($row['submitted_at'] ?? ''); ?></div>
                                    <span class="badge <?= $badgeClass; ?> mt-2"><?= htmlspecialchars($status); ?></span>
                                    <?php if (!empty($row['review_notes'])): ?>
                                        <div class="text-muted small mt-2">Notes: <?= htmlspecialchars($row['review_notes']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
