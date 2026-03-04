
<?php
session_start();
include 'db.php';
require_once 'concept_review_helpers.php';
require_once 'chair_scope_helper.php';
require_once 'role_helpers.php';

enforce_role_access(['program_chairperson']);

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);

ensureConceptReviewTables($conn);
syncConceptPapersFromSubmissions($conn);
ensureFinalPickMessagesTable($conn);

function ensureFinalPickMessagesTable(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'final_pick_messages'");
    $exists = $tableCheck && $tableCheck->num_rows > 0;
    if ($tableCheck) {
        $tableCheck->free();
    }
    if (!$exists) {
        $sql = "
            CREATE TABLE IF NOT EXISTS final_pick_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                concept_paper_id INT NULL,
                final_title VARCHAR(255) NOT NULL,
                message_body TEXT NOT NULL,
                sent_by INT NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_final_pick_student (student_id),
                INDEX idx_final_pick_sent_at (sent_at),
                INDEX idx_final_pick_sender (sent_by),
                CONSTRAINT fk_final_pick_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_final_pick_concept FOREIGN KEY (concept_paper_id) REFERENCES concept_papers(id) ON DELETE SET NULL,
                CONSTRAINT fk_final_pick_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        $conn->query($sql);
    }

    $ensured = true;
}

function buildCompletedPageUrl(int $page): string
{
    $params = $_GET;
    $params['completed_page'] = $page;
    $query = http_build_query($params);
    return $query ? ('?' . $query . '#completed-ranking-directory') : '#completed-ranking-directory';
}

$conceptScopeWhere = render_scope_condition($conn, $chairScope, 'u');

$rankingSql = "
    SELECT
        cp.student_id,
        cp.id AS concept_id,
        cp.title,
        cp.created_at,
        CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name,
        u.email AS student_email,
        SUM(CASE WHEN cr.rank_order = 1 OR (cr.rank_order IS NULL AND cr.is_preferred = 1) THEN 1 ELSE 0 END) AS rank_one_votes,
        SUM(CASE WHEN cr.rank_order = 2 THEN 1 ELSE 0 END) AS rank_two_votes,
        SUM(CASE WHEN cr.rank_order = 3 THEN 1 ELSE 0 END) AS rank_three_votes
    FROM concept_reviews cr
    JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
    JOIN concept_papers cp ON cp.id = cr.concept_paper_id
    LEFT JOIN users u ON u.id = cp.student_id
    WHERE (cr.rank_order IS NOT NULL OR cr.is_preferred = 1)
";
if ($conceptScopeWhere !== '') {
    $rankingSql .= "      AND ({$conceptScopeWhere} OR cra.assigned_by = {$programChairId})\n";
}
$rankingSql .= "
    GROUP BY cp.id, cp.student_id, cp.title, cp.created_at, student_name, u.email
    HAVING (rank_one_votes > 0 OR rank_two_votes > 0 OR rank_three_votes > 0)
    ORDER BY rank_one_votes DESC, rank_two_votes DESC, rank_three_votes DESC, cp.created_at DESC
    LIMIT 200
";

$rankingBoardFull = [];
$rankingBoardSummary = [
    'top_votes' => 0,
    'concepts' => 0,
    'students' => 0,
];

$rankingResult = $conn->query($rankingSql);
if ($rankingResult) {
    while ($row = $rankingResult->fetch_assoc()) {
        $studentId = (int)($row['student_id'] ?? 0);
        if ($studentId <= 0) {
            continue;
        }
        if (!isset($rankingBoardFull[$studentId])) {
            $rankingBoardFull[$studentId] = [
                'student_id' => $studentId,
                'student_name' => trim($row['student_name'] ?? 'Student'),
                'student_email' => trim($row['student_email'] ?? ''),
                'concepts' => [],
                'best_rank_one' => 0,
                'reviewers' => [],
                'interested_reviewers' => [],
                'interest_keys' => [],
            ];
        }

        $concept = [
            'concept_id' => (int)($row['concept_id'] ?? 0),
            'title' => $row['title'] ?? 'Untitled Concept',
            'rank_one' => (int)($row['rank_one_votes'] ?? 0),
            'rank_two' => (int)($row['rank_two_votes'] ?? 0),
            'rank_three' => (int)($row['rank_three_votes'] ?? 0),
            'score_key' => [
                (int)($row['rank_one_votes'] ?? 0),
                (int)($row['rank_two_votes'] ?? 0),
                (int)($row['rank_three_votes'] ?? 0),
            ],
        ];
        $rankingBoardFull[$studentId]['concepts'][] = $concept;
        $rankingBoardFull[$studentId]['best_rank_one'] = max($rankingBoardFull[$studentId]['best_rank_one'], $concept['rank_one']);
        $rankingBoardSummary['top_votes'] += $concept['rank_one'];
        if ($concept['rank_one'] > 0 || $concept['rank_two'] > 0 || $concept['rank_three'] > 0) {
            $rankingBoardSummary['concepts']++;
        }
    }
    $rankingResult->free();
}

$rankingProgress = [];
$progressSql = "
    SELECT
        cra.student_id,
        COUNT(DISTINCT cra.id) AS total_assignments,
        COUNT(DISTINCT CASE WHEN cr.rank_order IN (1,2,3) OR (cr.rank_order IS NULL AND cr.is_preferred = 1) THEN cra.id END) AS ranked_assignments
    FROM concept_reviewer_assignments cra
    LEFT JOIN concept_reviews cr ON cr.assignment_id = cra.id
    JOIN users u ON u.id = cra.student_id
";
if ($conceptScopeWhere !== '') {
    $progressSql .= " WHERE ({$conceptScopeWhere} OR cra.assigned_by = {$programChairId})\n";
}
$progressSql .= " GROUP BY cra.student_id";
$progressResult = $conn->query($progressSql);
if ($progressResult) {
    while ($row = $progressResult->fetch_assoc()) {
        $studentId = (int)($row['student_id'] ?? 0);
        if ($studentId <= 0) {
            continue;
        }
        $rankingProgress[$studentId] = [
            'total_assignments' => (int)($row['total_assignments'] ?? 0),
            'ranked_assignments' => (int)($row['ranked_assignments'] ?? 0),
        ];
    }
    $progressResult->free();
}

$reviewerSql = "
    SELECT
        cra.student_id,
        cra.id AS assignment_id,
        cra.reviewer_id,
        cra.reviewer_role,
        cra.status,
        cp.id AS concept_id,
        cp.title AS concept_title,
        cr.id AS review_id,
        cr.rank_order,
        cr.is_preferred,
        cr.adviser_interest,
        CONCAT(COALESCE(r.firstname, ''), ' ', COALESCE(r.lastname, '')) AS reviewer_name
    FROM concept_reviewer_assignments cra
    LEFT JOIN concept_reviews cr ON cr.assignment_id = cra.id
    LEFT JOIN concept_papers cp ON cp.id = cra.concept_paper_id
    LEFT JOIN users r ON r.id = cra.reviewer_id
    JOIN users u ON u.id = cra.student_id
";
if ($conceptScopeWhere !== '') {
    $reviewerSql .= "    WHERE ({$conceptScopeWhere} OR cra.assigned_by = {$programChairId})\n";
}
$reviewerSql .= "    ORDER BY u.lastname, u.firstname, r.lastname, r.firstname\n";
$reviewerResult = $conn->query($reviewerSql);
if ($reviewerResult) {
    while ($row = $reviewerResult->fetch_assoc()) {
        $studentId = (int)($row['student_id'] ?? 0);
        if ($studentId <= 0 || !isset($rankingBoardFull[$studentId])) {
            continue;
        }
        $reviewerId = (int)($row['reviewer_id'] ?? 0);
        $reviewerKey = $reviewerId > 0 ? $reviewerId : ('assignment_' . (int)($row['assignment_id'] ?? 0));
        if (!isset($rankingBoardFull[$studentId]['reviewers'][$reviewerKey])) {
            $rankingBoardFull[$studentId]['reviewers'][$reviewerKey] = [
                'reviewer_id' => $reviewerId,
                'reviewer_name' => trim($row['reviewer_name'] ?? 'Reviewer'),
                'reviewer_role' => $row['reviewer_role'] ?? '',
                'ranks' => [],
                'has_interest' => false,
                'primary_assignment_id' => (int)($row['assignment_id'] ?? 0),
                'primary_review_id' => isset($row['review_id']) ? (int)$row['review_id'] : 0,
                'student_id' => $studentId,
            ];
        }
        $entry =& $rankingBoardFull[$studentId]['reviewers'][$reviewerKey];
        $rankOrder = isset($row['rank_order']) ? (int)$row['rank_order'] : null;
        if (($rankOrder === null || $rankOrder === 0) && (int)($row['is_preferred'] ?? 0) === 1) {
            $rankOrder = 1;
        }
        if ($rankOrder !== null && $rankOrder >= 1 && $rankOrder <= 3) {
            $entry['ranks'][$rankOrder] = [
                'concept_id' => (int)($row['concept_id'] ?? 0),
                'title' => $row['concept_title'] ?? 'Untitled Concept',
            ];
        }
        $interestFlag = (int)($row['adviser_interest'] ?? 0) === 1;
        if ($interestFlag) {
            $entry['has_interest'] = true;
            $interestKey = $reviewerKey . ':' . (int)($row['concept_id'] ?? 0);
            if (!isset($rankingBoardFull[$studentId]['interest_keys'][$interestKey])) {
                $rankingBoardFull[$studentId]['interested_reviewers'][] = [
                    'reviewer_name' => trim($row['reviewer_name'] ?? 'Reviewer'),
                    'reviewer_role' => $row['reviewer_role'] ?? '',
                    'assignment_id' => (int)($row['assignment_id'] ?? 0),
                    'review_id' => isset($row['review_id']) ? (int)$row['review_id'] : 0,
                    'reviewer_id' => $reviewerId,
                    'student_id' => $studentId,
                    'concept_id' => (int)($row['concept_id'] ?? 0),
                    'concept_title' => $row['concept_title'] ?? 'Untitled Concept',
                ];
                $rankingBoardFull[$studentId]['interest_keys'][$interestKey] = true;
            }
        }
    }
    $reviewerResult->free();
}

foreach ($rankingBoardFull as $studentId => &$board) {
    $progress = $rankingProgress[$studentId] ?? null;
    $totalAssignments = isset($progress['total_assignments']) ? (int)$progress['total_assignments'] : 0;
    $rankedAssignments = isset($progress['ranked_assignments']) ? (int)$progress['ranked_assignments'] : 0;
    $board['total_assignments'] = $totalAssignments;
    $board['ranked_assignments'] = $rankedAssignments;
    $board['ranking_complete'] = $totalAssignments > 0 && $rankedAssignments >= $totalAssignments;

    if (!empty($board['concepts'])) {
        usort($board['concepts'], function ($a, $b) {
            $cmp = $b['score_key'][0] <=> $a['score_key'][0];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['score_key'][1] <=> $a['score_key'][1];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $b['score_key'][2] <=> $a['score_key'][2];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['title'] ?? '', $b['title'] ?? '');
        });
        $board['final_concept'] = $board['concepts'][0] ?? null;
        $board['has_tie_on_top'] = false;
        if (count($board['concepts']) > 1 && isset($board['final_concept']['score_key'])) {
            $topScore = $board['final_concept']['score_key'][0];
            $secondScore = $board['concepts'][1]['score_key'][0] ?? 0;
            $board['has_tie_on_top'] = ($topScore === $secondScore && $topScore > 0);
        }
    } else {
        $board['final_concept'] = null;
        $board['has_tie_on_top'] = false;
    }
    $board['reviewers'] = array_values($board['reviewers']);
    unset($board['interest_keys']);
}
unset($board);

$rankingBoardCollection = array_values($rankingBoardFull);
usort($rankingBoardCollection, function ($a, $b) {
    $scoreA = $a['best_rank_one'] ?? 0;
    $scoreB = $b['best_rank_one'] ?? 0;
    if ($scoreA === $scoreB) {
        return strcmp($a['student_name'] ?? '', $b['student_name'] ?? '');
    }
    return $scoreB <=> $scoreA;
});

$finalPickSentLookup = [];
$rankingStudentIds = array_values(array_unique(array_map(
    static fn($board) => (int)($board['student_id'] ?? 0),
    $rankingBoardCollection
)));
$rankingStudentIds = array_values(array_filter($rankingStudentIds));
if (!empty($rankingStudentIds)) {
    $placeholders = implode(',', array_fill(0, count($rankingStudentIds), '?'));
    $types = str_repeat('i', count($rankingStudentIds));
    $finalPickSentSql = "
        SELECT student_id, concept_paper_id, final_title, message_body, sent_by, sent_at
        FROM final_pick_messages
        WHERE student_id IN ({$placeholders})
        ORDER BY sent_at DESC
    ";
    $finalPickSentStmt = $conn->prepare($finalPickSentSql);
    if ($finalPickSentStmt) {
        $finalPickSentStmt->bind_param($types, ...$rankingStudentIds);
        $finalPickSentStmt->execute();
        $finalPickSentResult = $finalPickSentStmt->get_result();
        if ($finalPickSentResult) {
            while ($row = $finalPickSentResult->fetch_assoc()) {
                $sid = (int)($row['student_id'] ?? 0);
                if ($sid <= 0 || isset($finalPickSentLookup[$sid])) {
                    continue;
                }
                $finalPickSentLookup[$sid] = $row;
            }
            $finalPickSentResult->free();
        }
        $finalPickSentStmt->close();
    }
}

$completedRankingBoards = array_values(array_filter(
    $rankingBoardCollection,
    static fn($board) => !empty($finalPickSentLookup[(int)($board['student_id'] ?? 0)])
));

$completedRange = $_GET['completed_range'] ?? 'all';
$completedFilterStart = null;
switch ($completedRange) {
    case '7d':
        $completedFilterStart = strtotime('-7 days');
        break;
    case '30d':
        $completedFilterStart = strtotime('-30 days');
        break;
    case '90d':
        $completedFilterStart = strtotime('-90 days');
        break;
    default:
        $completedRange = 'all';
        break;
}

$completedFilteredBoards = array_values(array_filter(
    $completedRankingBoards,
    static function ($board) use ($finalPickSentLookup, $completedFilterStart) {
        if ($completedFilterStart === null) {
            return true;
        }
        $studentId = (int)($board['student_id'] ?? 0);
        $sentAt = $finalPickSentLookup[$studentId]['sent_at'] ?? null;
        if (!$sentAt) {
            return false;
        }
        return strtotime((string)$sentAt) >= $completedFilterStart;
    }
));

$completedTotal = count($completedFilteredBoards);
$completedPerPage = 12;
$completedPages = max(1, (int)ceil($completedTotal / $completedPerPage));
$completedPage = max(1, (int)($_GET['completed_page'] ?? 1));
if ($completedPage > $completedPages) {
    $completedPage = $completedPages;
}
$completedOffset = ($completedPage - 1) * $completedPerPage;
$completedDirectoryPage = array_slice($completedFilteredBoards, $completedOffset, $completedPerPage);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Completed Rankings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
</head>
<body class="bg-light program-chair-layout">
<?php include 'header.php'; ?>
<div class="dashboard-shell">
<?php include 'sidebar.php'; ?>

<main class="content dashboard-content" role="main">
    <div class="container-fluid py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div>
                <h1 class="h4 fw-semibold text-success mb-1">Completed Ranking Directory</h1>
                <p class="text-muted mb-0">Students who already received the final pick message.</p>
            </div>
            <a href="program_chairperson.php" class="btn btn-outline-success btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>

        <section class="card shadow-sm border-0" id="completed-ranking-directory">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h6 fw-semibold mb-1">Completed Rankings</h2>
                    <p class="text-muted small mb-0">Directory of students with finalized titles.</p>
                </div>
                <span class="badge bg-success-subtle text-success"><?= number_format(count($completedRankingBoards)); ?> total</span>
            </div>
            <div class="card-body">
                <?php if ($completedTotal === 0): ?>
                    <p class="text-muted mb-0">
                        <?= empty($completedRankingBoards) ? 'No finalized rankings yet.' : 'No finalized rankings match this filter.'; ?>
                    </p>
                <?php else: ?>
                    <div class="ranking-board-shell ranking-board-shell--compact">
                        <div class="ranking-board-list">
                            <div class="ranking-board-tools">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" placeholder="Search name or final title" data-directory-search>
                                </div>
                                <form method="GET" class="d-flex gap-2 align-items-center">
                                    <?php foreach ($_GET as $key => $value): ?>
                                        <?php if (in_array($key, ['completed_range', 'completed_page'], true)) { continue; } ?>
                                        <input type="hidden" name="<?= htmlspecialchars($key); ?>" value="<?= htmlspecialchars((string)$value, ENT_QUOTES); ?>">
                                    <?php endforeach; ?>
                                    <input type="hidden" name="completed_page" value="1">
                                    <select class="form-select form-select-sm" name="completed_range" onchange="this.form.submit()">
                                        <option value="all" <?= $completedRange === 'all' ? 'selected' : ''; ?>>All time</option>
                                        <option value="7d" <?= $completedRange === '7d' ? 'selected' : ''; ?>>Last 7 days</option>
                                        <option value="30d" <?= $completedRange === '30d' ? 'selected' : ''; ?>>Last 30 days</option>
                                        <option value="90d" <?= $completedRange === '90d' ? 'selected' : ''; ?>>Last 90 days</option>
                                    </select>
                                </form>
                            </div>
                            <div class="ranking-accordion-list" data-directory-list>
                                <div class="text-muted small fst-italic d-none" data-directory-empty>No students match your search.</div>
                                <div class="accordion" id="completedRankingAccordion">
                                <?php foreach ($completedDirectoryPage as $board): ?>
                                    <?php
                                        $studentId = (int)($board['student_id'] ?? 0);
                                        $sentInfo = $finalPickSentLookup[$studentId] ?? [];
                                        $sentAtLabel = !empty($sentInfo['sent_at'])
                                            ? date('M d, Y g:i A', strtotime((string)$sentInfo['sent_at']))
                                            : 'Not recorded';
                                        $finalTitle = $sentInfo['final_title'] ?? ($board['final_concept']['title'] ?? '');
                                        $searchKey = strtolower(trim(($board['student_name'] ?? '') . ' ' . $finalTitle));
                                    ?>
                                    <?php
                                        $headingId = "completedHeading{$studentId}";
                                        $collapseId = "completedCollapse{$studentId}";
                                    ?>
                                    <div class="accordion-item ranking-student-item" data-directory-item data-search="<?= htmlspecialchars($searchKey, ENT_QUOTES); ?>">
                                        <h2 class="accordion-header" id="<?= $headingId; ?>">
                                            <button
                                                class="accordion-button collapsed"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= $collapseId; ?>"
                                                aria-expanded="false"
                                                aria-controls="<?= $collapseId; ?>"
                                            >
                                                <span class="fw-semibold text-success"><?= htmlspecialchars($board['student_name']); ?></span>
                                            </button>
                                        </h2>
                                        <div
                                            id="<?= $collapseId; ?>"
                                            class="accordion-collapse collapse"
                                            aria-labelledby="<?= $headingId; ?>"
                                            data-bs-parent="#completedRankingAccordion"
                                        >
                                            <div class="accordion-body">
                                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-2">
                                                    <div>
                                                        <h5 class="mb-1 text-success"><?= htmlspecialchars($board['student_name']); ?></h5>
                                                        <div class="text-muted small"><?= htmlspecialchars($board['student_email'] ?? ''); ?></div>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge bg-success-subtle text-success">Message sent</span>
                                                        <div class="small text-muted mt-1"><?= htmlspecialchars($sentAtLabel); ?></div>
                                                    </div>
                                                </div>
                                                <?php if ($finalTitle !== ''): ?>
                                                    <div class="small text-muted mb-3"><strong>Final title:</strong> <?= htmlspecialchars($finalTitle); ?></div>
                                                <?php endif; ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle mb-3">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Concept Title</th>
                                                                <th class="text-center">Rank&nbsp;1</th>
                                                                <th class="text-center">Rank&nbsp;2</th>
                                                                <th class="text-center">Rank&nbsp;3</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($board['concepts'] as $concept): ?>
                                                                <?php $isWinner = isset($board['final_concept']['concept_id']) && $board['final_concept']['concept_id'] === ($concept['concept_id'] ?? null); ?>
                                                                <tr class="<?= $isWinner ? 'table-success-subtle' : ''; ?>">
                                                                    <td class="fw-semibold">
                                                                        <?= htmlspecialchars($concept['title']); ?>
                                                                        <?php if ($isWinner): ?>
                                                                            <span class="badge bg-success ms-2">Final pick</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td class="text-center"><span class="badge bg-success-subtle text-success"><?= number_format($concept['rank_one']); ?></span></td>
                                                                    <td class="text-center"><span class="badge bg-info-subtle text-info"><?= number_format($concept['rank_two']); ?></span></td>
                                                                    <td class="text-center"><span class="badge bg-secondary-subtle text-secondary"><?= number_format($concept['rank_three']); ?></span></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <div class="mt-3">
                                                    <h6 class="text-uppercase text-muted mb-3">Reviewer Breakdown</h6>
                                                    <?php if (!empty($board['reviewers'])): ?>
                                                        <div class="table-responsive">
                                                            <table class="table table-striped table-sm align-middle">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Reviewer</th>
                                                                        <th>Role</th>
                                                                        <th>Rank&nbsp;1</th>
                                                                        <th>Rank&nbsp;2</th>
                                                                        <th>Rank&nbsp;3</th>
                                                                        <th class="text-center">Mentor Interest</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($board['reviewers'] as $reviewer): ?>
                                                                        <?php
                                                                            $rankMap = [1 => 'Ã¢â‚¬â€', 2 => 'Ã¢â‚¬â€', 3 => 'Ã¢â‚¬â€'];
                                                                            foreach ($reviewer['ranks'] as $rankNumber => $rankData) {
                                                                                $rankMap[$rankNumber] = htmlspecialchars($rankData['title']);
                                                                            }
                                                                        ?>
                                                                        <tr>
                                                                            <td class="fw-semibold"><?= htmlspecialchars($reviewer['reviewer_name']); ?></td>
                                                                            <td class="text-muted small text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $reviewer['reviewer_role'] ?? '')); ?></td>
                                                                            <td><?= $rankMap[1]; ?></td>
                                                                            <td><?= $rankMap[2]; ?></td>
                                                                            <td><?= $rankMap[3]; ?></td>
                                                                            <td class="text-center">
                                                                                <?php if (!empty($reviewer['has_interest'])): ?>
                                                                                    <span class="badge bg-success-subtle text-success">Yes</span>
                                                                                <?php else: ?>
                                                                                    <span class="text-muted">Ã¢â‚¬â€</span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted small fst-italic">No reviewer submissions recorded.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if ($completedPages > 1): ?>
                                <?php
                                    $window = 2;
                                    $startPage = max(1, $completedPage - $window);
                                    $endPage = min($completedPages, $completedPage + $window);
                                ?>
                                <nav class="mt-3" aria-label="Completed ranking pages">
                                    <ul class="pagination pagination-sm mb-0 flex-wrap">
                                        <li class="page-item <?= $completedPage <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?= buildCompletedPageUrl(max(1, $completedPage - 1)); ?>">Previous</a>
                                        </li>
                                        <?php if ($startPage > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= buildCompletedPageUrl(1); ?>">1</a>
                                            </li>
                                            <?php if ($startPage > 2): ?>
                                                <li class="page-item disabled"><span class="page-link">Ã¢â‚¬Â¦</span></li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                                            <li class="page-item <?= $page === $completedPage ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?= buildCompletedPageUrl($page); ?>"><?= $page; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <?php if ($endPage < $completedPages): ?>
                                            <?php if ($endPage < ($completedPages - 1)): ?>
                                                <li class="page-item disabled"><span class="page-link">Ã¢â‚¬Â¦</span></li>
                                            <?php endif; ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?= buildCompletedPageUrl($completedPages); ?>"><?= $completedPages; ?></a>
                                            </li>
                                        <?php endif; ?>
                                        <li class="page-item <?= $completedPage >= $completedPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?= buildCompletedPageUrl(min($completedPages, $completedPage + 1)); ?>">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function() {
        const list = document.querySelector('[data-directory-list]');
        const emptyState = document.querySelector('[data-directory-empty]');
        const searchInput = document.querySelector('[data-directory-search]');

        if (!list) {
            return;
        }

        const items = Array.from(list.querySelectorAll('[data-directory-item]'));

        const closeItem = (item) => {
            const collapse = item.querySelector('.accordion-collapse');
            if (collapse) {
                collapse.classList.remove('show');
            }
            const toggle = item.querySelector('.accordion-button');
            if (toggle) {
                toggle.classList.add('collapsed');
                toggle.setAttribute('aria-expanded', 'false');
            }
        };

        const applyFilters = () => {
            const query = (searchInput?.value || '').trim().toLowerCase();
            let visibleCount = 0;
            items.forEach((item) => {
                const searchKey = (item.dataset.search || '').toLowerCase();
                const matchesQuery = !query || searchKey.includes(query);
                item.classList.toggle('d-none', !matchesQuery);
                if (matchesQuery) {
                    visibleCount += 1;
                } else {
                    closeItem(item);
                }
            });
            if (emptyState) {
                emptyState.classList.toggle('d-none', visibleCount > 0);
            }
        };

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }

        applyFilters();
    })();
</script>
</body>
</html>



