<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';

function formatDateToReadable(?string $date): string
{
    if (!$date) {
        return 'Not specified';
    }
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format('F d, Y g:i A');
    } catch (Exception $e) {
        return $date;
    }
}

// Allow adviser, committee chairperson, and panel member accounts to rank concept titles.
$roleLabels = [
    'adviser' => 'Adviser',
    'committee_chair' => 'Committee Chairperson',
    'panel' => 'Panel Member',
];

$currentRole = $_SESSION['role'] ?? '';
if (!isset($roleLabels[$currentRole])) {
    header('Location: login.php');
    exit;
}

ensureConceptReviewTables($conn);

$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$roleKey = $currentRole;
$roleLabel = $roleLabels[$roleKey];

$message = '';
$messageClass = '';

// Fetch assignments for this reviewer/role.
$assignments = fetchReviewerAssignments($conn, $reviewerId, $roleKey);
$assignmentLookup = [];
foreach ($assignments as $assignment) {
    $assignmentLookup[(int)$assignment['assignment_id']] = $assignment;
}
$grouped = groupReviewerAssignmentsByStudent($assignments);
$assignmentStats = summarizeReviewerAssignments($assignments);
$rankingStats = summarizeReviewerRankingProgress($assignments);
$totalStudents = count($grouped);

// Handle rating submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ratings'])) {
    $ratings = $_POST['rating'] ?? [];
    $validAssignments = array_keys($assignmentLookup);

    $stmtSelect = $conn->prepare("SELECT id FROM concept_reviews WHERE assignment_id = ? LIMIT 1");
    $stmtInsert = $conn->prepare("INSERT INTO concept_reviews (assignment_id, concept_paper_id, reviewer_id, rank_order, is_preferred, updated_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmtUpdate = $conn->prepare("UPDATE concept_reviews SET rank_order = ?, is_preferred = ?, updated_at = NOW() WHERE id = ?");

    foreach ($ratings as $assignmentId => $rank) {
        $assignmentId = (int)$assignmentId;
        $rank = (int)$rank;
        if (!in_array($assignmentId, $validAssignments, true)) {
            continue;
        }
        if ($rank < 1 || $rank > 3) {
            continue;
        }

        $assignment = $assignmentLookup[$assignmentId];
        $paperId = (int)($assignment['concept_paper_id'] ?? 0);
        if (!$paperId) {
            continue;
        }
        $isPreferred = $rank === 1 ? 1 : 0;

        $existingId = null;
        if ($stmtSelect) {
            $stmtSelect->bind_param('i', $assignmentId);
            $stmtSelect->execute();
            $stmtSelect->bind_result($existingId);
            if (!$stmtSelect->fetch()) {
                $existingId = null;
            }
            $stmtSelect->free_result();
        }

        if ($existingId && $stmtUpdate) {
            $stmtUpdate->bind_param('iii', $rank, $isPreferred, $existingId);
            $stmtUpdate->execute();
        } elseif ($stmtInsert) {
            $stmtInsert->bind_param('iiiii', $assignmentId, $paperId, $reviewerId, $rank, $isPreferred);
            $stmtInsert->execute();
        }
    }

    if ($stmtSelect) { $stmtSelect->close(); }
    if ($stmtInsert) { $stmtInsert->close(); }
    if ($stmtUpdate) { $stmtUpdate->close(); }

    $message = 'Ratings saved. Your top choice is marked when you select rank 1.';
    $messageClass = 'success';
    // Refresh assignments for updated rankings.
    $assignments = fetchReviewerAssignments($conn, $reviewerId, $roleKey);
    $grouped = groupReviewerAssignmentsByStudent($assignments);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($roleLabel); ?> Reviewer Workspace</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body { background: linear-gradient(180deg, #f6f8fb 0%, #eef3f1 100%); }
        .hero-card {
            border-radius: 22px;
            background: linear-gradient(135deg, #16562c, #0f3d1f);
            color: #fff;
            padding: 24px;
            box-shadow: 0 20px 48px rgba(15, 61, 31, 0.25);
        }
        .work-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 16px 32px rgba(15, 61, 31, 0.08);
        }
        .title-chip { background: rgba(22,86,44,.08); border-radius: 12px; padding: 8px 12px; }
        .rank-pill { border-radius: 999px; padding: 4px 10px; background: #eaf5ec; color: #16562c; }
    </style>
</head>
<body>
<div class="container-xxl py-4">
    <div class="hero-card mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-2">
            <div>
                <p class="text-uppercase small mb-1">Reviewer Workspace · <?= htmlspecialchars($roleLabel); ?></p>
                <h3 class="fw-bold mb-1">Rank Student Concepts</h3>
                <p class="mb-0 text-white-50">Review the three concept titles per student and mark Rank 1 for your top recommendation.</p>
            </div>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <div class="text-end">
                    <small class="text-white-50 d-block">Assignments</small>
                    <span class="fs-4 fw-bold text-white"><?= number_format($assignmentStats['total'] ?? 0); ?></span>
                </div>
                <div class="text-end">
                    <small class="text-white-50 d-block">Ranked</small>
                    <span class="fs-4 fw-bold text-white"><?= number_format($rankingStats['ranked'] ?? 0); ?></span>
                </div>
                <div class="text-end">
                    <small class="text-white-50 d-block">Pending</small>
                    <span class="fs-4 fw-bold text-white"><?= number_format($rankingStats['pending'] ?? 0); ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($messageClass); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (empty($grouped)): ?>
        <div class="alert alert-info">No reviewer assignments yet. The Program Chairperson will route concepts to you.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($grouped as $studentId => $group): ?>
                <?php $studentName = $group['student_name'] ?? 'Student'; ?>
                <div class="col-12 col-lg-6">
                    <div class="card work-card h-100">
                        <div class="card-body d-flex flex-column gap-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="mb-1"><?= htmlspecialchars($studentName); ?></h5>
                                    <small class="text-muted"><?= htmlspecialchars($group['student_email'] ?? ''); ?></small>
                                </div>
                                <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($roleLabel); ?></span>
                            </div>
                            <form method="POST" class="d-flex flex-column gap-3 flex-grow-1">
                                <input type="hidden" name="save_ratings" value="1">
                                <?php
                                    $items = array_slice($group['items'] ?? [], 0, 3);
                                    $existingRanks = [];
                                    foreach ($items as $item) {
                                        if (isset($item['review_rank_order']) && (int)$item['review_rank_order'] > 0) {
                                            $existingRanks[$item['assignment_id']] = (int)$item['review_rank_order'];
                                        }
                                    }
                                ?>
                                <?php foreach ($items as $item): ?>
                                    <div class="border rounded-3 p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="fw-semibold"><?= htmlspecialchars($item['title']); ?></div>
                                            <small class="text-muted">Submitted <?= htmlspecialchars(formatDateToReadable($item['concept_created_at'] ?? '')); ?></small>
                                        </div>
                                        <div class="d-flex flex-wrap align-items-center gap-3">
                                            <?php for ($r = 1; $r <= 3; $r++): ?>
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="radio" name="rating[<?= (int)$item['assignment_id']; ?>]" id="rank<?= $r; ?>_<?= (int)$item['assignment_id']; ?>" value="<?= $r; ?>" <?= (isset($existingRanks[$item['assignment_id']]) && $existingRanks[$item['assignment_id']] === $r) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="rank<?= $r; ?>_<?= (int)$item['assignment_id']; ?>">Rank <?= $r; ?></label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-success">Save Rankings</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
