<?php
session_start();
include 'db.php';
require_once 'concept_review_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_concept_helpers.php';
require_once 'endorsement_helpers.php';
require_once 'chair_scope_helper.php';
require_once 'role_helpers.php';

enforce_role_access(['program_chairperson']);

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);

ensureConceptReviewTables($conn);
syncConceptPapersFromSubmissions($conn);
ensureReviewerInviteFeedbackTable($conn);
ensureFinalConceptSubmissionTable($conn);
ensureEndorsementRequestsTable($conn);

$chairFeedbackAlert = null;
$finalPickAlert = null;
$endorsementAlert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_final_pick_message'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $studentName = trim((string)($_POST['student_name'] ?? 'the student'));
    $finalTitle = trim((string)($_POST['final_title'] ?? ''));
    $messageBody = trim(strip_tags((string)($_POST['final_pick_message'] ?? '')));

    if ($studentId <= 0 || $finalTitle === '') {
        $finalPickAlert = ['type' => 'danger', 'message' => 'Unable to send the final recommendation. Missing student or title details.'];
    } elseif ($messageBody === '') {
        $finalPickAlert = ['type' => 'warning', 'message' => 'Please write a short message before sending.'];
    } else {
        notify_user(
            $conn,
            $studentId,
            'Final concept recommendation',
            $messageBody,
            'student_dashboard.php'
        );
        $finalPickAlert = ['type' => 'success', 'message' => "Final pick message sent to {$studentName}."];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_chair_feedback'])) {
    $conceptId = (int)($_POST['concept_id'] ?? 0);
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $studentIdFromPost = (int)($_POST['student_id'] ?? 0);
    $reviewerIdFromPost = (int)($_POST['reviewer_id'] ?? 0);
    $studentNameInput = trim((string)($_POST['student_name'] ?? 'the student'));
    $conceptTitleInput = trim((string)($_POST['concept_title'] ?? 'the concept'));
    $rawMessage = trim((string)($_POST['chair_feedback_message'] ?? ''));
    $messageBody = trim(strip_tags($rawMessage));
    $feedbackTarget = $_POST['feedback_target'] ?? 'student';
    $programChairId = (int)($_SESSION['user_id'] ?? 0);

    if ($conceptId <= 0 && $assignmentId > 0) {
        $conceptLookup = $conn->prepare("
            SELECT concept_paper_id
            FROM concept_reviews
            WHERE assignment_id = ?
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        if ($conceptLookup) {
            $conceptLookup->bind_param('i', $assignmentId);
            if ($conceptLookup->execute()) {
                $conceptRes = $conceptLookup->get_result();
                if ($conceptRow = $conceptRes->fetch_assoc()) {
                    $conceptId = (int)($conceptRow['concept_paper_id'] ?? $conceptId);
                }
            }
            $conceptLookup->close();
        }
    }

    if ($conceptId <= 0) {
        $chairFeedbackAlert = ['type' => 'danger', 'message' => 'Unable to send feedback. Missing concept details.'];
    } elseif ($messageBody === '') {
        $chairFeedbackAlert = ['type' => 'warning', 'message' => 'Please write a short message before sending.'];
    } else {
        $reviewTarget = null;
        if ($feedbackTarget === 'mentor') {
            $lookupStmt = $conn->prepare("
                SELECT
                    cr.id AS review_id,
                    cra.id AS assignment_id,
                    cra.student_id,
                    cra.reviewer_id,
                    cp.title,
                    CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS student_name
                FROM concept_reviewer_assignments cra
                LEFT JOIN concept_reviews cr ON cr.assignment_id = cra.id
                LEFT JOIN concept_papers cp ON cp.id = cra.concept_paper_id
                LEFT JOIN users u ON u.id = cra.student_id
                WHERE cra.id = ?
                LIMIT 1
            ");
            if ($lookupStmt) {
                $lookupStmt->bind_param('i', $assignmentId);
                if ($lookupStmt->execute()) {
                    $result = $lookupStmt->get_result();
                    $reviewTarget = $result ? $result->fetch_assoc() : null;
                }
                $lookupStmt->close();
            }
        } else {
            $lookupStmt = $conn->prepare("
                SELECT
                    cr.id AS review_id,
                    cra.id AS assignment_id,
                    cra.student_id,
                    cra.reviewer_id,
                    cp.title,
                    CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS student_name
                FROM concept_reviews cr
                INNER JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
                LEFT JOIN concept_papers cp ON cp.id = cr.concept_paper_id
                LEFT JOIN users u ON u.id = cra.student_id
                WHERE cr.concept_paper_id = ?
                  AND cr.reviewer_role = 'adviser'
                ORDER BY cr.updated_at DESC
                LIMIT 1
            ");
            if ($lookupStmt) {
                $lookupStmt->bind_param('i', $conceptId);
                if ($lookupStmt->execute()) {
                    $result = $lookupStmt->get_result();
                    $reviewTarget = $result ? $result->fetch_assoc() : null;
                }
                $lookupStmt->close();
            }
        }

        if (!$reviewTarget) {
            $chairFeedbackAlert = ['type' => 'danger', 'message' => 'Unable to send feedback. No matching review record was found yet.'];
        } elseif ($feedbackTarget === 'mentor' && (int)($reviewTarget['review_id'] ?? 0) <= 0) {
            $chairFeedbackAlert = ['type' => 'warning', 'message' => 'This reviewer has not submitted a ranking yet. Feedback will be available once their review is saved.'];
        } else {
            $reviewId = (int)($reviewTarget['review_id'] ?? 0);
            $studentId = (int)($reviewTarget['student_id'] ?? $studentIdFromPost);
            $reviewerId = (int)($reviewTarget['reviewer_id'] ?? $reviewerIdFromPost);
            $studentNameInput = trim($reviewTarget['student_name'] ?? $studentNameInput);
            $conceptTitleInput = $reviewTarget['title'] ?? $conceptTitleInput;

            $updateStmt = $conn->prepare("
                UPDATE concept_reviews
                SET chair_feedback = ?, chair_feedback_at = NOW(), chair_feedback_by = ?
                WHERE id = ?
            ");
            if ($updateStmt) {
                $updateStmt->bind_param('sii', $messageBody, $programChairId, $reviewId);
                if ($updateStmt->execute()) {
                    if ($feedbackTarget === 'mentor') {
                        if ($reviewerId > 0) {
                            notify_user(
                                $conn,
                                $reviewerId,
                                'Program Chair feedback on mentoring interest',
                                $messageBody,
                                'subject_specialist_dashboard.php',
                                false
                            );
                        }
                        $chairFeedbackAlert = ['type' => 'success', 'message' => 'Feedback sent to the interested reviewer.'];
                    } else {
                        notify_user(
                            $conn,
                            $studentId,
                            'Feedback on your concept titles',
                            $messageBody,
                            'student_dashboard.php'
                        );
                        if ($reviewerId > 0) {
                            notify_user(
                                $conn,
                                $reviewerId,
                                'Program Chair feedback sent',
                                "The Program Chair sent feedback to {$studentNameInput} for {$conceptTitleInput}.",
                                'subject_specialist_dashboard.php',
                                false
                            );
                        }
                        $chairFeedbackAlert = ['type' => 'success', 'message' => 'Feedback sent successfully.'];
                    }
                } else {
                    $chairFeedbackAlert = ['type' => 'danger', 'message' => 'Unable to save feedback right now.'];
                }
                $updateStmt->close();
            } else {
                $chairFeedbackAlert = ['type' => 'danger', 'message' => 'Unable to prepare feedback request.'];
            }
        }
    }
}

$adviserRankingIndex = fetchAdviserConceptRankings($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_endorsement'])) {
    $endorsementId = (int)($_POST['endorsement_id'] ?? 0);
    if ($endorsementId <= 0) {
        $endorsementAlert = ['type' => 'danger', 'message' => 'Invalid endorsement reference.'];
    } else {
        $endorsementStmt = $conn->prepare("
            SELECT er.id, er.status, er.student_id, er.adviser_id,
                   CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
                   CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name
            FROM endorsement_requests er
            JOIN users stu ON stu.id = er.student_id
            LEFT JOIN users adv ON adv.id = er.adviser_id
            WHERE er.id = ?
            LIMIT 1
        ");
        $endorsementRow = null;
        if ($endorsementStmt) {
            $endorsementStmt->bind_param('i', $endorsementId);
            $endorsementStmt->execute();
            $endorsementResult = $endorsementStmt->get_result();
            $endorsementRow = $endorsementResult ? $endorsementResult->fetch_assoc() : null;
            $endorsementStmt->close();
        }

        if (!$endorsementRow) {
            $endorsementAlert = ['type' => 'danger', 'message' => 'Unable to locate that endorsement.'];
        } elseif (!student_matches_scope($conn, (int)($endorsementRow['student_id'] ?? 0), $chairScope)) {
            $endorsementAlert = ['type' => 'danger', 'message' => 'You can only verify endorsements for students in your scope.'];
        } elseif (($endorsementRow['status'] ?? '') === 'Verified') {
            $endorsementAlert = ['type' => 'warning', 'message' => 'This endorsement has already been verified.'];
        } else {
            $update = $conn->prepare("
                UPDATE endorsement_requests
                SET status = 'Verified', verified_by = ?, verified_at = NOW()
                WHERE id = ?
            ");
            if ($update) {
                $update->bind_param('ii', $programChairId, $endorsementId);
                if ($update->execute()) {
                    $studentName = $endorsementRow['student_name'] ?? 'the student';
                    $adviserId = (int)($endorsementRow['adviser_id'] ?? 0);
                    if ($adviserId > 0) {
                        $message = "Your endorsement for {$studentName} has been verified by the Program Chairperson.";
                        notify_user($conn, $adviserId, 'Endorsement verified', $message, 'adviser_endorsement.php', false);
                    }
                    $studentId = (int)($endorsementRow['student_id'] ?? 0);
                    if ($studentId > 0) {
                        $studentMessage = "Your adviser endorsement for outline defense has been verified. Please coordinate with the Program Chairperson for the next steps.";
                        notify_user($conn, $studentId, 'Outline defense endorsement verified', $studentMessage, 'student_dashboard.php', false);
                    }
                    $endorsementAlert = ['type' => 'success', 'message' => 'Endorsement verified successfully.'];
                } else {
                    $endorsementAlert = ['type' => 'danger', 'message' => 'Unable to verify the endorsement.'];
                }
                $update->close();
            } else {
                $endorsementAlert = ['type' => 'danger', 'message' => 'Unable to prepare endorsement verification.'];
            }
        }
    }
}

if (!empty(array_filter($chairScope))) {
    foreach ($adviserRankingIndex as $key => $ranking) {
        $studentId = (int)($ranking['student_id'] ?? 0);
        if (!student_matches_scope($conn, $studentId, $chairScope)) {
            unset($adviserRankingIndex[$key]);
        }
    }
}
$adviserHighlightsMap = [];
foreach ($adviserRankingIndex as $ranking) {
    $studentId = (int)($ranking['student_id'] ?? 0);
    if ($studentId <= 0) {
        continue;
    }
    $current = $adviserHighlightsMap[$studentId] ?? null;
    $replace = false;
    if (!$current) {
        $replace = true;
    } elseif (($ranking['rank_order'] ?? 99) < ($current['rank_order'] ?? 99)) {
        $replace = true;
    } elseif (($ranking['rank_order'] ?? 99) === ($current['rank_order'] ?? 99)) {
        $incomingTime = isset($ranking['updated_at']) ? strtotime((string)$ranking['updated_at']) : 0;
        $currentTime = isset($current['updated_at']) ? strtotime((string)$current['updated_at']) : 0;
        if ($incomingTime >= $currentTime) {
            $replace = true;
        }
    }
    if ($replace) {
        $adviserHighlightsMap[$studentId] = $ranking;
    }
}
$adviserHighlights = array_values($adviserHighlightsMap);
usort($adviserHighlights, function ($a, $b) {
    $rankA = $a['rank_order'] ?? 99;
    $rankB = $b['rank_order'] ?? 99;
    if ($rankA !== $rankB) {
        return $rankA <=> $rankB;
    }
    $timeA = isset($a['updated_at']) ? strtotime((string)$a['updated_at']) : 0;
    $timeB = isset($b['updated_at']) ? strtotime((string)$b['updated_at']) : 0;
    return $timeB <=> $timeA;
});


function columnExists(mysqli $conn, string $table, string $column): bool
{
    $tableEscaped = $conn->real_escape_string($table);
    $columnEscaped = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$tableEscaped}'
          AND COLUMN_NAME = '{$columnEscaped}'
        LIMIT 1
    ";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

if (!function_exists('buildAdvisorUnassignedClause')) {
    function buildAdvisorUnassignedClause(string $alias, array $columns): string
    {
        if (empty($columns)) {
            return '1=1';
        }
        $parts = array_map(
            fn($column) => "({$alias}.{$column} IS NULL OR {$alias}.{$column} = 0)",
            $columns
        );
        return '(' . implode(' AND ', $parts) . ')';
    }
}

$facultyRoles = ["faculty", "adviser", "panel", "committee_chair"];
$roleLabels = [
    "faculty" => "Faculty Member",
    "adviser" => "Thesis Adviser",
    "panel" => "Panel Member",
    "committee_chair" => "Committee Chairperson",
];

$facultyList = [];
$facultyByRole = [];
$roleList = "'" . implode("','", $facultyRoles) . "'";
$facultySql = "
    SELECT id, firstname, lastname, email, role, department, college
    FROM users
    WHERE role IN ({$roleList})
    ORDER BY lastname, firstname
";
if ($facultyResult = $conn->query($facultySql)) {
    while ($row = $facultyResult->fetch_assoc()) {
        $roleKey = $row['role'] ?? '';
        if (!isset($facultyByRole[$roleKey])) {
            $facultyByRole[$roleKey] = 0;
        }
        $facultyByRole[$roleKey]++;
        $facultyList[] = $row;
    }
    $facultyResult->free();
}
$facultyTotal = count($facultyList);

$studentTotal = 0;
 $studentScopeWhere = render_scope_condition($conn, $chairScope, 'users');
$studentSql = "SELECT COUNT(*) AS total FROM users WHERE role = 'student'";
if ($studentScopeWhere !== '') {
    $studentSql .= " AND {$studentScopeWhere}";
}
if ($studentResult = $conn->query($studentSql)) {
    $studentRow = $studentResult->fetch_assoc();
    $studentTotal = (int)($studentRow['total'] ?? 0);
    $studentResult->free();
}

$submissionTotal = 0;
$conceptScopeWhere = render_scope_condition($conn, $chairScope, 'u');
$submissionSql = "
    SELECT COUNT(*) AS total
    FROM concept_papers cp
    LEFT JOIN users u ON u.id = cp.student_id
";
if ($conceptScopeWhere !== '') {
    $submissionSql .= " WHERE {$conceptScopeWhere}";
}
if ($submissionResult = $conn->query($submissionSql)) {
    $submissionRow = $submissionResult->fetch_assoc();
    $submissionTotal = (int)($submissionRow['total'] ?? 0);
    $submissionResult->free();
}

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

$hasStatusColumn = columnExists($conn, 'concept_papers', 'status');
$hasAssignedFacultyColumn = columnExists($conn, 'concept_papers', 'assigned_faculty');

$rankingSql = "
    SELECT
        cp.student_id,
        cp.id AS concept_id,
        cp.title,
        cp.created_at,
        CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name,
        u.email AS student_email,
        SUM(CASE WHEN cr.rank_order = 1 THEN 1 ELSE 0 END) AS rank_one_votes,
        SUM(CASE WHEN cr.rank_order = 2 THEN 1 ELSE 0 END) AS rank_two_votes,
        SUM(CASE WHEN cr.rank_order = 3 THEN 1 ELSE 0 END) AS rank_three_votes
    FROM concept_reviews cr
    JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
    JOIN concept_papers cp ON cp.id = cr.concept_paper_id
    LEFT JOIN users u ON u.id = cp.student_id
    WHERE cr.rank_order IS NOT NULL
";
if ($conceptScopeWhere !== '') {
    $rankingSql .= "      AND ({$conceptScopeWhere} OR cra.assigned_by = {$programChairId})\n";
}
$rankingSql .= "
    GROUP BY cp.id, cp.student_id, cp.title, cp.created_at, student_name, u.email
    HAVING (rank_one_votes > 0 OR rank_two_votes > 0 OR rank_three_votes > 0)
    ORDER BY rank_one_votes DESC, rank_two_votes DESC, rank_three_votes DESC, cp.created_at DESC
    LIMIT 80
";
$rankingBoardFull = [];
$rankingBoardSummary = [
    'top_votes' => 0,
    'concepts' => 0,
    'students' => 0,
];
$rankingBoardDisplay = [];
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
        COUNT(DISTINCT CASE WHEN cr.rank_order IN (1,2,3) THEN cra.id END) AS ranked_assignments
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

$rankingBoardSummary['students'] = count($rankingBoardFull);
$rankingBoardCollection = array_values($rankingBoardFull);
usort($rankingBoardCollection, function ($a, $b) {
    $scoreA = $a['best_rank_one'] ?? 0;
    $scoreB = $b['best_rank_one'] ?? 0;
    if ($scoreA === $scoreB) {
        return strcmp($a['student_name'] ?? '', $b['student_name'] ?? '');
    }
    return $scoreB <=> $scoreA;
});
$rankingBoardDisplay = array_slice($rankingBoardCollection, 0, 4);
$finalPickHighlights = [];
foreach ($rankingBoardCollection as $board) {
    if (empty($board['final_concept'])) {
        continue;
    }
    $totalAssignments = (int)($board['total_assignments'] ?? 0);
    $rankedAssignments = (int)($board['ranked_assignments'] ?? 0);
    $rankingComplete = $totalAssignments > 0 && $rankedAssignments >= $totalAssignments;

    $finalPickHighlights[] = [
        'student_id' => (int)($board['student_id'] ?? 0),
        'student_name' => $board['student_name'] ?? 'Student',
        'student_email' => $board['student_email'] ?? '',
        'concept_id' => $rankingComplete ? (int)($board['final_concept']['concept_id'] ?? 0) : 0,
        'title' => $rankingComplete ? ($board['final_concept']['title'] ?? 'Untitled Concept') : '',
        'rank_one' => $rankingComplete ? (int)($board['final_concept']['rank_one'] ?? 0) : 0,
        'rank_two' => $rankingComplete ? (int)($board['final_concept']['rank_two'] ?? 0) : 0,
        'rank_three' => $rankingComplete ? (int)($board['final_concept']['rank_three'] ?? 0) : 0,
        'has_tie_on_top' => $rankingComplete && !empty($board['has_tie_on_top']),
        'ranking_complete' => $rankingComplete,
        'ranked_assignments' => $rankedAssignments,
        'total_assignments' => $totalAssignments,
    ];
}

$finalPickSubmissionLookup = [];
$hasFinalPickSubmissionTable = columnExists($conn, 'final_concept_submissions', 'id');
if ($hasFinalPickSubmissionTable && !empty($finalPickHighlights)) {
    $finalPickStudentIds = array_values(array_unique(array_map(
        static fn($pick) => (int)($pick['student_id'] ?? 0),
        $finalPickHighlights
    )));
    $finalPickStudentIds = array_values(array_filter($finalPickStudentIds));
    if (!empty($finalPickStudentIds)) {
        $placeholders = implode(',', array_fill(0, count($finalPickStudentIds), '?'));
        $types = str_repeat('i', count($finalPickStudentIds));
        $finalPickSql = "
            SELECT student_id, final_title, status, submitted_at
            FROM final_concept_submissions
            WHERE student_id IN ({$placeholders})
            ORDER BY student_id ASC, submitted_at DESC
        ";
        $finalPickStmt = $conn->prepare($finalPickSql);
        if ($finalPickStmt) {
            $finalPickStmt->bind_param($types, ...$finalPickStudentIds);
            $finalPickStmt->execute();
            $finalPickResult = $finalPickStmt->get_result();
            if ($finalPickResult) {
                while ($row = $finalPickResult->fetch_assoc()) {
                    $sid = (int)($row['student_id'] ?? 0);
                    if ($sid <= 0 || isset($finalPickSubmissionLookup[$sid])) {
                        continue;
                    }
                    $finalPickSubmissionLookup[$sid] = $row;
                }
                $finalPickResult->free();
            }
            $finalPickStmt->close();
        }
    }
}

foreach ($finalPickHighlights as &$pick) {
    $studentId = (int)($pick['student_id'] ?? 0);
    $submission = $finalPickSubmissionLookup[$studentId] ?? null;
    $pick['final_submission_status'] = $submission['status'] ?? '';
    $pick['final_submission_title'] = $submission['final_title'] ?? '';
    $pick['final_submission_at'] = $submission['submitted_at'] ?? null;
}
unset($pick);

$recentSubmissions = [];
$statusSelect = $hasStatusColumn ? ", cp.status" : "";
$recentSql = "
    SELECT cp.id, cp.title, cp.created_at{$statusSelect},
           CONCAT(u.firstname, ' ', u.lastname) AS student_name
    FROM concept_papers cp
    LEFT JOIN users u ON u.id = cp.student_id
    WHERE 1=1
";
if ($conceptScopeWhere !== '') {
    $recentSql .= "      AND {$conceptScopeWhere}\n";
}
$recentSql .= "    ORDER BY cp.created_at DESC
    LIMIT 6
";
if ($recentResult = $conn->query($recentSql)) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentSubmissions[] = $row;
    }
    $recentResult->free();
}

$pendingAssignments = [];
$pendingAssignmentCount = 0;
$pendingStatusSelect = $hasStatusColumn ? ", cp.status" : "";
$legacyExpression = $hasAssignedFacultyColumn ? "(cp.assigned_faculty IS NOT NULL AND cp.assigned_faculty <> '')" : "0";
$pendingSql = "
    SELECT cp.id, cp.title, cp.created_at{$pendingStatusSelect},
           CONCAT(u.firstname, ' ', u.lastname) AS student_name,
           COUNT(cra.id) AS reviewer_count,
           MAX(CASE WHEN {$legacyExpression} THEN 1 ELSE 0 END) AS legacy_assigned
    FROM concept_papers cp
    LEFT JOIN users u ON u.id = cp.student_id
    LEFT JOIN concept_reviewer_assignments cra ON cra.concept_paper_id = cp.id
    WHERE 1=1
";
if ($conceptScopeWhere !== '') {
    $pendingSql .= "      AND {$conceptScopeWhere}\n";
}
$pendingSql .= "    GROUP BY cp.id, cp.title, cp.created_at, u.firstname, u.lastname{$pendingStatusSelect}
    HAVING reviewer_count = 0 AND legacy_assigned = 0
    ORDER BY cp.created_at DESC
    LIMIT 6
";
if ($pendingResult = $conn->query($pendingSql)) {
    while ($row = $pendingResult->fetch_assoc()) {
        $pendingAssignments[] = $row;
    }
    $pendingResult->free();
}

$countLegacyClause = $hasAssignedFacultyColumn ? " AND MAX(CASE WHEN cp.assigned_faculty IS NOT NULL AND cp.assigned_faculty <> '' THEN 1 ELSE 0 END) = 0" : "";
$countSql = "
    SELECT COUNT(*) AS total
    FROM (
        SELECT cp.id
        FROM concept_papers cp
        LEFT JOIN users u ON u.id = cp.student_id
        LEFT JOIN concept_reviewer_assignments cra ON cra.concept_paper_id = cp.id
        WHERE 1=1
";
if ($conceptScopeWhere !== '') {
    $countSql .= "          AND {$conceptScopeWhere}\n";
}
$countSql .= "
        GROUP BY cp.id
        HAVING COUNT(cra.id) = 0{$countLegacyClause}
    ) AS pending_students
";
if ($countResult = $conn->query($countSql)) {
    $countRow = $countResult->fetch_assoc();
    $pendingAssignmentCount = (int)($countRow['total'] ?? 0);
    $countResult->free();
}

$pendingReviews = max($pendingAssignmentCount, $assignmentStats['pending']);
$facultyPreview = array_slice($facultyList, 0, 6);
$finalPendingCount = 0;
$finalCountSql = "
    SELECT COUNT(*) AS total
    FROM final_concept_submissions fcs
    JOIN users u ON u.id = fcs.student_id
    WHERE fcs.status = 'Pending'
";
if ($conceptScopeWhere !== '') {
    $finalCountSql .= " AND {$conceptScopeWhere}";
}
if ($pendingFinalResult = $conn->query($finalCountSql)) {
    $countRow = $pendingFinalResult->fetch_assoc();
    $finalPendingCount = (int)($countRow['total'] ?? 0);
    $pendingFinalResult->free();
}

$endorsements = [];
$endorsementScopeClause = '';
$endorsementScopeTypes = '';
$endorsementScopeParams = [];
[$endorsementScopeClause, $endorsementScopeTypes, $endorsementScopeParams] = build_scope_condition($chairScope, 'stu');
$endorsementSql = "
    SELECT
        er.id,
        er.title,
        er.body,
        er.status,
        er.created_at,
        er.verified_at,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
        CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
        CONCAT(ver.firstname, ' ', ver.lastname) AS verified_by_name
    FROM endorsement_requests er
    JOIN users stu ON stu.id = er.student_id
    LEFT JOIN users adv ON adv.id = er.adviser_id
    LEFT JOIN users ver ON ver.id = er.verified_by
";
if ($endorsementScopeClause !== '') {
    $endorsementSql .= " WHERE {$endorsementScopeClause}";
}
$endorsementSql .= " ORDER BY er.created_at DESC LIMIT 10";
$endorsementStmt = $conn->prepare($endorsementSql);
if ($endorsementStmt) {
    if ($endorsementScopeTypes !== '') {
        bind_scope_params($endorsementStmt, $endorsementScopeTypes, $endorsementScopeParams);
    }
    $endorsementStmt->execute();
    $endorsementResult = $endorsementStmt->get_result();
    if ($endorsementResult) {
        $endorsements = $endorsementResult->fetch_all(MYSQLI_ASSOC);
        $endorsementResult->free();
    }
    $endorsementStmt->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Program Chairperson Dashboard</title>
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
        <div class="mb-4">
            <h1 class="h4 fw-semibold text-success mb-1">Program Chairperson Dashboard</h1>
            <p class="text-muted mb-0">Monitor faculty resources, review student submissions, and coordinate panels in one place.</p>
        </div>
        <?php if ($finalPickAlert): ?>
            <div class="alert alert-<?= htmlspecialchars($finalPickAlert['type']); ?> border-0 shadow-sm">
                <?= htmlspecialchars($finalPickAlert['message']); ?>
            </div>
        <?php endif; ?>
        <?php if ($chairFeedbackAlert): ?>
            <div class="alert alert-<?= htmlspecialchars($chairFeedbackAlert['type']); ?> border-0 shadow-sm">
                <?= htmlspecialchars($chairFeedbackAlert['message']); ?>
            </div>
        <?php endif; ?>
        <?php if ($endorsementAlert): ?>
            <div class="alert alert-<?= htmlspecialchars($endorsementAlert['type']); ?> border-0 shadow-sm">
                <?= htmlspecialchars($endorsementAlert['message']); ?>
            </div>
        <?php endif; ?>
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 stat-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="icon-pill bg-success-subtle text-success">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <a href="faculty_directory.php" class="btn btn-outline-success btn-sm">View Faculty</a>
                        </div>
                        <h6 class="text-uppercase text-muted small mb-1">Active Faculty</h6>
                        <h2 class="fw-bold text-success mb-3"><?php echo number_format($facultyTotal); ?></h2>
                        <?php if ($facultyTotal > 0): ?>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($facultyByRole as $roleKey => $count): ?>
                                    <span class="badge rounded-pill bg-success-subtle text-success">
                                        <?php echo htmlspecialchars($roleLabels[$roleKey] ?? ucfirst(str_replace('_', ' ', $roleKey))); ?>:
                                        <?php echo number_format($count); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-0">No faculty records yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 stat-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="icon-pill bg-primary-subtle text-primary">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                            <a href="student_directory.php" class="btn btn-outline-primary btn-sm">View Students</a>
                        </div>
                        <h6 class="text-uppercase text-muted small mb-1">Enrolled Students</h6>
                        <h2 class="fw-bold text-primary mb-3"><?php echo number_format($studentTotal); ?></h2>
                        <p class="text-muted small mb-0">Students registered across graduate programs.</p>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 stat-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="icon-pill bg-warning-subtle text-warning">
                                <i class="bi bi-clipboard-check"></i>
                            </div>
                            <a href="assign_faculty.php" class="btn btn-outline-warning btn-sm">Assign Reviewers</a>
                        </div>
                        <h6 class="text-uppercase text-muted small mb-1">Concept Review Workload</h6>
                        <h2 class="fw-bold text-warning mb-3"><?php echo number_format($pendingReviews); ?></h2>
                        <p class="text-muted small mb-0">
                            <?php echo number_format($pendingAssignmentCount); ?> students waiting &middot;
                            <?php echo number_format($assignmentStats['due_soon']); ?> due soon
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 stat-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="icon-pill bg-danger-subtle text-danger">
                                <i class="bi bi-journal-check"></i>
                            </div>
                            <div class="btn-group">
                                <a href="final_concept_directory.php" class="btn btn-danger btn-sm text-white">Directory</a>
                            </div>
                        </div>
                        <h6 class="text-uppercase text-muted small mb-1">Final Concepts Pending</h6>
                        <h2 class="fw-bold text-danger mb-3"><?= number_format($finalPendingCount); ?></h2>
                        <p class="text-muted small mb-0">Awaiting your approval before students proceed to defense.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-split dashboard-split--wide mb-4">
            <section class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Concept Ranking Board</h2>
                            <p class="text-muted small mb-0">See where reviewers agree on the best titles per student.</p>
                        </div>
                        <a href="assign_faculty.php" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-people-fill me-1"></i> Adjust Assignments
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($rankingBoardDisplay)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-bar-chart-line fs-2 d-block mb-2"></i>
                                <p class="mb-0">Ranking data will appear once reviewers submit their evaluations.</p>
                            </div>
                        <?php else: ?>
                            <?php $boardCount = count($rankingBoardDisplay); ?>
                            <?php foreach ($rankingBoardDisplay as $index => $board): ?>
                                <?php
                                    $rankedAssignments = (int)($board['ranked_assignments'] ?? 0);
                                    $totalAssignments = (int)($board['total_assignments'] ?? 0);
                                    $rankingComplete = !empty($board['ranking_complete']);
                                    $progressLabel = $totalAssignments > 0
                                        ? "Ranked {$rankedAssignments} of {$totalAssignments} reviews"
                                        : "No reviewer assignments yet";
                                    $progressClass = $rankingComplete ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
                                    $recommendationClass = $rankingComplete
                                        ? 'alert alert-success-subtle border-success text-success'
                                        : 'alert alert-warning-subtle border-warning text-warning';
                                ?>
                                <div class="<?= $index + 1 === $boardCount ? '' : 'border-bottom pb-4 mb-4'; ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h5 class="mb-0 text-success"><?= htmlspecialchars($board['student_name']); ?></h5>
                                            <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                                <small class="text-muted"><?= number_format(count($board['concepts'] ?? [])); ?> titles ranked</small>
                                                <span class="badge <?= $progressClass; ?>"><?= htmlspecialchars($progressLabel); ?></span>
                                            </div>
                                        </div>
                                        <span class="badge bg-success-subtle text-success">
                                            <?= number_format($board['best_rank_one'] ?? 0); ?> total Rank&nbsp;1 votes
                                        </span>
                                    </div>
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
                                    <?php if (!empty($board['final_concept'])): ?>
                                        <div class="<?= $recommendationClass; ?>">
                                            <strong>Recommended concept:</strong> <?= htmlspecialchars($board['final_concept']['title'] ?? ''); ?> (<?= number_format($board['final_concept']['rank_one'] ?? 0); ?> Rank&nbsp;1 vote<?= ($board['final_concept']['rank_one'] ?? 0) === 1 ? '' : 's'; ?>)
                                            <?php if (!empty($board['has_tie_on_top'])): ?>
                                                <span class="badge bg-warning-subtle text-warning ms-2">Tie on Rank&nbsp;1 votes</span>
                                            <?php endif; ?>
                                            <div class="small text-muted mb-0">
                                                <?php if ($rankingComplete): ?>
                                                    Basis: Highest number of Rank&nbsp;1 selections. Ties break via Rank&nbsp;2 then Rank&nbsp;3.
                                                <?php else: ?>
                                                    Preliminary result &mdash; waiting for remaining reviewer rankings.
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
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
                                                                $rankMap = [1 => '—', 2 => '—', 3 => '—'];
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
                                                                        <span class="text-muted">—</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted small mb-0">No reviewer submissions yet.</p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($board['interested_reviewers'])): ?>
                                        <div class="mt-3 p-3 bg-success-subtle border border-success-subtle rounded">
                                            <h6 class="mb-2">Interested in Mentoring</h6>
                                            <?php foreach ($board['interested_reviewers'] as $mentor): ?>
                                                <div class="mb-3">
                                                    <div class="fw-semibold mb-1"><?= htmlspecialchars($mentor['reviewer_name']); ?>
                                                        <span class="text-muted small">(<?= htmlspecialchars(str_replace('_', ' ', $mentor['reviewer_role'] ?? '')); ?>)</span>
                                                    </div>
                                                    <small class="text-muted d-block mb-2">Prefers: <?= htmlspecialchars($mentor['concept_title']); ?></small>
                                                    <?php if (($mentor['review_id'] ?? 0) > 0): ?>
                                                        <form method="POST" class="mb-0">
                                                            <input type="hidden" name="send_chair_feedback" value="1">
                                                            <input type="hidden" name="feedback_target" value="mentor">
                                                            <input type="hidden" name="concept_id" value="<?= (int)($mentor['concept_id'] ?? 0); ?>">
                                                            <input type="hidden" name="assignment_id" value="<?= (int)($mentor['assignment_id'] ?? 0); ?>">
                                                            <input type="hidden" name="target_review_id" value="<?= (int)($mentor['review_id'] ?? 0); ?>">
                                                            <input type="hidden" name="student_id" value="<?= (int)($mentor['student_id'] ?? 0); ?>">
                                                            <input type="hidden" name="reviewer_id" value="<?= (int)($mentor['reviewer_id'] ?? 0); ?>">
                                                            <input type="hidden" name="student_name" value="<?= htmlspecialchars($board['student_name'], ENT_QUOTES); ?>">
                                                            <input type="hidden" name="concept_title" value="<?= htmlspecialchars($mentor['concept_title'], ENT_QUOTES); ?>">
                                                            <textarea class="form-control form-control-sm mb-2" name="chair_feedback_message" rows="2" placeholder="Send a quick note to <?= htmlspecialchars($mentor['reviewer_name']); ?>" required></textarea>
                                                            <button type="submit" class="btn btn-sm btn-success">
                                                                <i class="bi bi-send me-1"></i> Send Feedback
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <div class="text-muted small fst-italic">Awaiting this reviewer&rsquo;s submitted ranking.</div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
            </section>
            <div class="d-flex flex-column gap-4">
                <section class="card shadow-sm border-0">
                        <div class="card-body">
                            <h6 class="text-uppercase text-muted mb-3">Ranking Summary</h6>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <small class="text-muted d-block">Top-choice ballots</small>
                                    <h3 class="mb-0 text-success"><?= number_format($rankingBoardSummary['top_votes']); ?></h3>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted d-block">Titles ranked</small>
                                    <h4 class="mb-0"><?= number_format($rankingBoardSummary['concepts']); ?></h4>
                                </div>
                            </div>
                            <ul class="list-unstyled small text-muted mb-4">
                                <li>Students with reviewer rankings: <strong><?= number_format($rankingBoardSummary['students']); ?></strong></li>
                                <li>Assignments awaiting rank: <strong><?= number_format($assignmentStats['pending']); ?></strong></li>
                                <li>Reviewer deadlines due soon: <strong><?= number_format($assignmentStats['due_soon']); ?></strong></li>
                            </ul>
                            <a href="assign_faculty.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-stars me-1"></i> Accelerate ranking cycle
                            </a>
                        </div>
                </section>
                <section class="card shadow-sm border-0" id="endorsement-inbox">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Endorsement Inbox</h2>
                            <p class="text-muted small mb-0">Adviser endorsements awaiting verification.</p>
                        </div>
                        <span class="badge bg-success-subtle text-success"><?php echo number_format(count($endorsements)); ?> received</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($endorsements)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-2 mb-2"></i>
                                <p class="mb-0">No endorsements received yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($endorsements as $endorsement): ?>
                                    <?php
                                        $endorsementStatus = $endorsement['status'] ?? 'Pending';
                                        $endorsementBadge = $endorsementStatus === 'Verified'
                                            ? 'bg-success-subtle text-success'
                                            : 'bg-warning-subtle text-warning';
                                        $endorsementCreated = $endorsement['created_at']
                                            ? date('M d, Y g:i A', strtotime($endorsement['created_at']))
                                            : 'Not recorded';
                                        $endorsementVerified = $endorsement['verified_at']
                                            ? date('M d, Y g:i A', strtotime($endorsement['verified_at']))
                                            : '';
                                    ?>
                                    <div class="border rounded-4 p-3">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-semibold text-success"><?php echo htmlspecialchars($endorsement['student_name'] ?? 'Student'); ?></div>
                                                <div class="text-muted small">Adviser: <?php echo htmlspecialchars($endorsement['adviser_name'] ?? 'Adviser'); ?></div>
                                                <div class="small mt-1"><strong>Title:</strong> <?php echo htmlspecialchars($endorsement['title'] ?? ''); ?></div>
                                            </div>
                                            <span class="badge <?php echo $endorsementBadge; ?>"><?php echo htmlspecialchars($endorsementStatus); ?></span>
                                        </div>
                                        <div class="text-muted small mt-2">Sent <?php echo htmlspecialchars($endorsementCreated); ?></div>
                                        <?php if ($endorsementStatus === 'Verified'): ?>
                                            <div class="text-muted small mt-1">
                                                Verified <?php echo htmlspecialchars($endorsementVerified); ?>
                                                <?php if (!empty($endorsement['verified_by_name'])): ?>
                                                    by <?php echo htmlspecialchars($endorsement['verified_by_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <details class="mt-2">
                                            <summary class="small text-decoration-underline">View endorsement letter</summary>
                                            <?php $endorsementBody = strip_tags((string)($endorsement['body'] ?? ''), '<u><br>'); ?>
                                            <div class="mt-2 small"><?php echo str_replace(["\r\n", "\n"], "<br>", $endorsementBody); ?></div>
                                        </details>
                                        <?php if ($endorsementStatus !== 'Verified'): ?>
                                            <form method="POST" class="mt-3">
                                                <input type="hidden" name="verify_endorsement" value="1">
                                                <input type="hidden" name="endorsement_id" value="<?php echo (int)$endorsement['id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="bi bi-check2-circle me-1"></i> Verify Endorsement
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Final Picks &amp; Status</h2>
                            <p class="text-muted small mb-0">Auto-generated recommendations based on reviewer rankings.</p>
                        </div>
                        <a href="assign_faculty.php" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-arrow-repeat me-1"></i> Refresh Assignments
                        </a>
                    </div>
                    <div class="card-body pt-0">
                        <?php if (empty($finalPickHighlights)): ?>
                            <p class="text-muted mb-0">No final pick recommendations yet. Ranking data will appear once reviewers submit evaluations.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Email</th>
                                            <th>Final Pick Title</th>
                                            <th class="text-center">Final Pick Basis</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($finalPickHighlights, 0, 8) as $pick): ?>
                                            <?php
                                                $rankingComplete = !empty($pick['ranking_complete']);
                                                $rankedAssignments = (int)($pick['ranked_assignments'] ?? 0);
                                                $totalAssignments = (int)($pick['total_assignments'] ?? 0);
                                                if (!$rankingComplete) {
                                                    $statusLabel = $totalAssignments > 0
                                                        ? "Awaiting rankings ({$rankedAssignments}/{$totalAssignments})"
                                                        : 'Awaiting rankings';
                                                    $statusClass = 'bg-warning-subtle text-warning';
                                                } else {
                                                    $finalStatus = trim((string)($pick['final_submission_status'] ?? ''));
                                                    if ($finalStatus !== '') {
                                                        $statusLabel = $finalStatus;
                                                        $statusClass = finalConceptStatusClass($finalStatus);
                                                    } else {
                                                        $statusLabel = 'Ready for final submission';
                                                        $statusClass = 'bg-info-subtle text-info';
                                                    }
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold text-success"><?= htmlspecialchars($pick['student_name']); ?></div>
                                                    <small class="text-muted">Student ID #<?= (int)$pick['student_id']; ?></small>
                                                </td>
                                                <td>
                                                    <div class="text-muted"><?= htmlspecialchars($pick['student_email'] ?: 'Not available'); ?></div>
                                                </td>
                                                <td>
                                                    <?php if ($rankingComplete): ?>
                                                        <div class="fw-semibold"><?= htmlspecialchars($pick['title']); ?></div>
                                                        <small class="text-muted">Concept ID #<?= (int)$pick['concept_id']; ?></small>
                                                        <?php if (!empty($pick['has_tie_on_top'])): ?>
                                                            <span class="badge bg-warning-subtle text-warning ms-2">Tie on Rank 1</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="fw-semibold text-muted">Awaiting final ranking</div>
                                                        <?php if ($totalAssignments > 0): ?>
                                                            <small class="text-muted">Ranked <?= number_format($rankedAssignments); ?> of <?= number_format($totalAssignments); ?> reviews</small>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($rankingComplete): ?>
                                                        <span class="badge bg-success-subtle text-success">R1: <?= number_format($pick['rank_one']); ?></span>
                                                        <span class="badge bg-info-subtle text-info">R2: <?= number_format($pick['rank_two']); ?></span>
                                                        <span class="badge bg-secondary-subtle text-secondary">R3: <?= number_format($pick['rank_three']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $statusClass; ?>"><?= htmlspecialchars($statusLabel); ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($rankingComplete): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-success btn-sm final-pick-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#finalPickModal"
                                                            data-student-id="<?= (int)$pick['student_id']; ?>"
                                                            data-student-name="<?= htmlspecialchars($pick['student_name'], ENT_QUOTES); ?>"
                                                            data-student-email="<?= htmlspecialchars($pick['student_email'] ?: 'Not available', ENT_QUOTES); ?>"
                                                            data-final-title="<?= htmlspecialchars($pick['title'], ENT_QUOTES); ?>"
                                                            data-concept-id="<?= (int)$pick['concept_id']; ?>"
                                                            data-rank-one="<?= (int)$pick['rank_one']; ?>"
                                                            data-rank-two="<?= (int)$pick['rank_two']; ?>"
                                                            data-rank-three="<?= (int)$pick['rank_three']; ?>"
                                                            data-has-tie="<?= !empty($pick['has_tie_on_top']) ? '1' : '0'; ?>"
                                                        >
                                                            Message student
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                            Waiting rankings
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (count($finalPickHighlights) > 8): ?>
                                <small class="text-muted d-block mt-2">+<?= number_format(count($finalPickHighlights) - 8); ?> more final picks available.</small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-split dashboard-split--wide mb-4">
            <section class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Faculty Roster Snapshot</h2>
                            <p class="text-muted small mb-0">Recently updated faculty members and their assignments.</p>
                        </div>
                        <a href="directory.php" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-list-ul me-1"></i> Full Directory
                        </a>
                    </div>
                    <div class="card-body pt-0">
                        <?php if (!empty($facultyPreview)): ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">Name</th>
                                            <th scope="col">Role</th>
                                            <th scope="col">Program / Institute</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($facultyPreview as $item): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold text-success">
                                                        <?php echo htmlspecialchars(($item['firstname'] ?? '') . ' ' . ($item['lastname'] ?? '')); ?>
                                                    </div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($item['email'] ?? ''); ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success-subtle text-success">
                                                        <?php echo htmlspecialchars($roleLabels[$item['role']] ?? ucfirst(str_replace('_', ' ', (string)$item['role']))); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($item['department'] ?? '—'); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($item['college'] ?? ''); ?></div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No faculty members recorded yet. Encourage departments to complete their profiles.</p>
                        <?php endif; ?>
                    </div>
            </section>
            <section class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0">
                        <h2 class="h6 fw-semibold mb-1">Faculty Coverage by Role</h2>
                        <p class="text-muted small mb-0">Ensure balanced reviewer availability.</p>
                    </div>
                    <div class="card-body pt-0">
                        <?php if ($facultyTotal > 0): ?>
                            <?php foreach ($facultyByRole as $roleKey => $count): ?>
                                <?php $percentage = $facultyTotal ? round(($count / $facultyTotal) * 100) : 0; ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-semibold text-success"><?php echo htmlspecialchars($roleLabels[$roleKey] ?? ucfirst(str_replace('_', ' ', $roleKey))); ?></span>
                                        <span class="text-muted small"><?php echo number_format($count); ?> • <?php echo $percentage; ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percentage; ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">Faculty coverage data will appear once members are added.</p>
                        <?php endif; ?>
                        <div class="mt-4">
                            <a href="assign_faculty.php" class="btn btn-success w-100">
                                <i class="bi bi-person-gear me-2"></i> Manage Faculty Assignments
                            </a>
                        </div>
            </section>
        </div>

        <div class="dashboard-split dashboard-split--wide">
            <section class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Recent Concept Submissions</h2>
                            <p class="text-muted small mb-0">Latest papers filed by graduate students.</p>
                        </div>
                        <a href="submissions.php?view=all" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-box-arrow-up-right me-1"></i> View All
                        </a>
                    </div>
                    <div class="card-body pt-0">
                        <?php if (!empty($recentSubmissions)): ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">Title</th>
                                            <th scope="col">Student</th>
                                            <th scope="col">Submitted</th>
                                            <?php if ($hasStatusColumn): ?>
                                                <th scope="col">Status</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentSubmissions as $submission): ?>
                                            <tr>
                                                <td class="fw-semibold text-dark">
                                                    <?php echo htmlspecialchars($submission['title'] ?? 'Untitled Submission'); ?>
                                                </td>
                                                <td>
                                                    <div class="text-success fw-semibold"><?php echo htmlspecialchars($submission['student_name'] ?? 'Unknown Student'); ?></div>
                                                </td>
                                                <td class="text-muted small">
                                                    <?php
                                                        $submittedAt = $submission['created_at'] ?? '';
                                                        echo $submittedAt ? date("M j, Y g:i A", strtotime($submittedAt)) : '—';
                                                    ?>
                                                </td>
                                                <?php if ($hasStatusColumn): ?>
                                                    <td>
                                                        <span class="badge bg-primary-subtle text-primary text-capitalize">
                                                            <?php echo htmlspecialchars($submission['status'] ?? 'Pending'); ?>
                                                        </span>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No concept submissions found.</p>
                        <?php endif; ?>
                    </div>
            </section>
            <section class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Panel Assignment Queue</h2>
                            <p class="text-muted small mb-0">Concept papers awaiting reviewer/panel assignment.</p>
                        </div>
                        <a href="assign_panel.php" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-people me-1"></i> Assign Now
                        </a>
                    </div>
                    <div class="card-body pt-0">
                        <?php if (!empty($pendingAssignments)): ?>
                            <ul class="list-group list-group-flush queue-list">
                                <?php foreach ($pendingAssignments as $assignment): ?>
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold text-dark">
                                                <?php echo htmlspecialchars($assignment['title'] ?? 'Untitled Concept'); ?>
                                            </div>
                                            <div class="text-muted small">
                                                <?php echo htmlspecialchars($assignment['student_name'] ?? 'Unknown Student'); ?>
                                                •
                                                <?php
                                                    $createdAt = $assignment['created_at'] ?? '';
                                                    echo $createdAt ? date("M j, Y", strtotime($createdAt)) : 'No date';
                                                ?>
                                            </div>
                                        </div>
                                        <a href="assign_faculty.php?paper=<?php echo (int)($assignment['id'] ?? 0); ?>" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-arrow-up-right"></i>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">All concept papers have assigned reviewers. Great work!</p>
                        <?php endif; ?>
                    </div>
            </section>
        </div>
    </div>
</main>
</div>

<div class="modal fade" id="chairFeedbackModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success">Message Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
            <div class="modal-body">
                <input type="hidden" name="send_chair_feedback" value="1">
                <input type="hidden" name="assignment_id" id="feedbackAssignmentId">
                <input type="hidden" name="concept_id" id="feedbackConceptId">
                <input type="hidden" name="student_id" id="feedbackStudentId">
                <input type="hidden" name="reviewer_id" id="feedbackReviewerId">
                <input type="hidden" name="student_name" id="feedbackStudentNameInput">
                <input type="hidden" name="concept_title" id="feedbackConceptTitleInput">
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackStudentNameDisplay">Student</label>
                    <input type="text" class="form-control" id="feedbackStudentNameDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackConceptTitleDisplay">Concept Title</label>
                    <input type="text" class="form-control" id="feedbackConceptTitleDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackAdviserNameDisplay">Adviser</label>
                    <input type="text" class="form-control" id="feedbackAdviserNameDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackConceptRankDisplay">Rank Result</label>
                    <input type="text" class="form-control" id="feedbackConceptRankDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackUpdatedDisplay">Last Updated</label>
                    <input type="text" class="form-control" id="feedbackUpdatedDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="feedbackMessageTextarea">Message</label>
                    <textarea class="form-control" name="chair_feedback_message" id="feedbackMessageTextarea" rows="4" placeholder="Congratulate the student, share reminders, or request revisions." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Send Feedback</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="finalPickModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success">Final Pick Recommendation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="send_final_pick_message" value="1">
                <input type="hidden" name="student_id" id="finalPickStudentId">
                <input type="hidden" name="student_name" id="finalPickStudentNameInput">
                <input type="hidden" name="final_title" id="finalPickTitleInput">
                <input type="hidden" name="concept_id" id="finalPickConceptId">
                <input type="hidden" name="rank_one" id="finalPickRankOneInput">
                <input type="hidden" name="rank_two" id="finalPickRankTwoInput">
                <input type="hidden" name="rank_three" id="finalPickRankThreeInput">
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickStudentNameDisplay">Student</label>
                    <input type="text" class="form-control" id="finalPickStudentNameDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickStudentEmailDisplay">Student Email</label>
                    <input type="text" class="form-control" id="finalPickStudentEmailDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickTitleDisplay">Final Pick Title</label>
                    <input type="text" class="form-control" id="finalPickTitleDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickBasisDisplay">Final Pick Basis</label>
                    <input type="text" class="form-control" id="finalPickBasisDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickTieDisplay">Tie on Rank 1</label>
                    <input type="text" class="form-control" id="finalPickTieDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="finalPickMessageTextarea">Message</label>
                    <textarea class="form-control" name="final_pick_message" id="finalPickMessageTextarea" rows="4" placeholder="Share the final recommendation and next steps." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Send</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const feedbackModal = document.getElementById('chairFeedbackModal');
    if (feedbackModal) {
        const feedbackForm = feedbackModal.querySelector('form');
        const applyFeedbackDetails = (details = {}) => {
            const {
                assignmentId = '',
                conceptId = '',
                studentId = '',
                reviewerId = '',
                studentName = 'Student',
                conceptTitle = 'Concept Title',
                existingFeedback = '',
                rankOrder = '',
                adviserName = 'Not assigned',
                updatedLabel = 'Not recorded'
            } = details;

            feedbackForm.dataset.currentConcept = conceptId;
            feedbackForm.dataset.currentAssignment = assignmentId;
            feedbackForm.dataset.currentStudent = studentId;
            feedbackForm.dataset.currentReviewer = reviewerId;
            feedbackForm.dataset.currentAdviserName = adviserName;
            feedbackForm.dataset.currentUpdated = updatedLabel;

            feedbackModal.querySelector('#feedbackAssignmentId').value = assignmentId;
            feedbackModal.querySelector('#feedbackConceptId').value = conceptId;
            feedbackModal.querySelector('#feedbackStudentId').value = studentId;
            feedbackModal.querySelector('#feedbackReviewerId').value = reviewerId;
            feedbackModal.querySelector('#feedbackStudentNameInput').value = studentName;
            feedbackModal.querySelector('#feedbackConceptTitleInput').value = conceptTitle;
            feedbackModal.querySelector('#feedbackStudentNameDisplay').value = studentName;
            feedbackModal.querySelector('#feedbackConceptTitleDisplay').value = conceptTitle;
            feedbackModal.querySelector('#feedbackAdviserNameDisplay').value = adviserName;
            feedbackModal.querySelector('#feedbackConceptRankDisplay').value = rankOrder ? `Rank #${rankOrder}` : 'Not ranked yet';
            feedbackModal.querySelector('#feedbackUpdatedDisplay').value = updatedLabel || 'Not recorded';

            const textarea = feedbackModal.querySelector('#feedbackMessageTextarea');
            if (existingFeedback) {
                textarea.value = existingFeedback;
            } else if (rankOrder) {
                textarea.value = `Hi ${studentName}, your adviser marked "${conceptTitle}" as Rank #${rankOrder}. Let's continue refining this title for your research work.`;
            } else {
                textarea.value = '';
            }
        };


        document.querySelectorAll('.feedback-btn').forEach((button) => {
            button.addEventListener('click', () => {
                
                const payload = {
                    assignmentId: button.getAttribute('data-assignment-id') || '',
                    conceptId: button.getAttribute('data-concept-id') || '',
                    studentId: button.getAttribute('data-student-id') || '',
                    reviewerId: button.getAttribute('data-reviewer-id') || '',
                    studentName: button.getAttribute('data-student-name') || 'Student',
                    conceptTitle: button.getAttribute('data-concept-title') || 'Concept Title',
                    existingFeedback: button.getAttribute('data-existing-feedback') || '',
                    rankOrder: button.getAttribute('data-rank-order') || '',
                    adviserName: button.getAttribute('data-adviser-name') || 'Not assigned',
                    updatedLabel: button.getAttribute('data-updated-label') || 'Not recorded'
                };
                applyFeedbackDetails(payload);
            });
        });

        feedbackModal.addEventListener('show.bs.modal', (event) => {
            if (event.relatedTarget) {
                return;
            }
            applyFeedbackDetails({
                assignmentId: feedbackForm.dataset.currentAssignment || '',
                conceptId: feedbackForm.dataset.currentConcept || '',
                studentId: feedbackForm.dataset.currentStudent || '',
                reviewerId: feedbackForm.dataset.currentReviewer || '',
                studentName: feedbackModal.querySelector('#feedbackStudentNameDisplay').value || 'Student',
                conceptTitle: feedbackModal.querySelector('#feedbackConceptTitleDisplay').value || 'Concept Title',
                existingFeedback: feedbackModal.querySelector('#feedbackMessageTextarea').value || '',
                rankOrder: (feedbackModal.querySelector('#feedbackConceptRankDisplay').value || '').replace(/\D/g, '') || '',
                adviserName: feedbackForm.dataset.currentAdviserName || 'Not assigned',
                updatedLabel: feedbackForm.dataset.currentUpdated || 'Not recorded'
            });
        });

        feedbackForm.addEventListener('submit', () => {
            const conceptField = document.getElementById('feedbackConceptId');
            if (!conceptField.value && feedbackForm.dataset.currentConcept) {
                conceptField.value = feedbackForm.dataset.currentConcept;
            }
        });
    }

    const finalPickModal = document.getElementById('finalPickModal');
    if (finalPickModal) {
        const finalPickForm = finalPickModal.querySelector('form');
        const applyFinalPickDetails = (details = {}) => {
            const {
                studentId = '',
                studentName = 'Student',
                studentEmail = 'Not available',
                finalTitle = 'Final title',
                conceptId = '',
                rankOne = '0',
                rankTwo = '0',
                rankThree = '0',
                hasTie = '0'
            } = details;

            finalPickForm.dataset.currentStudentId = studentId;
            finalPickForm.dataset.currentStudentName = studentName;
            finalPickForm.dataset.currentStudentEmail = studentEmail;
            finalPickForm.dataset.currentFinalTitle = finalTitle;
            finalPickForm.dataset.currentConceptId = conceptId;
            finalPickForm.dataset.currentRankOne = rankOne;
            finalPickForm.dataset.currentRankTwo = rankTwo;
            finalPickForm.dataset.currentRankThree = rankThree;
            finalPickForm.dataset.currentHasTie = hasTie;

            finalPickModal.querySelector('#finalPickStudentId').value = studentId;
            finalPickModal.querySelector('#finalPickStudentNameInput').value = studentName;
            finalPickModal.querySelector('#finalPickTitleInput').value = finalTitle;
            finalPickModal.querySelector('#finalPickConceptId').value = conceptId;
            finalPickModal.querySelector('#finalPickRankOneInput').value = rankOne;
            finalPickModal.querySelector('#finalPickRankTwoInput').value = rankTwo;
            finalPickModal.querySelector('#finalPickRankThreeInput').value = rankThree;

            finalPickModal.querySelector('#finalPickStudentNameDisplay').value = studentName;
            finalPickModal.querySelector('#finalPickStudentEmailDisplay').value = studentEmail;
            finalPickModal.querySelector('#finalPickTitleDisplay').value = finalTitle;
            finalPickModal.querySelector('#finalPickBasisDisplay').value = `R1: ${rankOne} | R2: ${rankTwo} | R3: ${rankThree}`;
            finalPickModal.querySelector('#finalPickTieDisplay').value = hasTie === '1' ? 'Yes' : 'No';

            const textarea = finalPickModal.querySelector('#finalPickMessageTextarea');
            textarea.value = `Hi ${studentName}, based on the concept ranking board, the recommended title to pursue is "${finalTitle}". Rank breakdown: R1 ${rankOne}, R2 ${rankTwo}, R3 ${rankThree}. Please proceed with this title for your final submission.`;
        };

        document.querySelectorAll('.final-pick-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const payload = {
                    studentId: button.getAttribute('data-student-id') || '',
                    studentName: button.getAttribute('data-student-name') || 'Student',
                    studentEmail: button.getAttribute('data-student-email') || 'Not available',
                    finalTitle: button.getAttribute('data-final-title') || 'Final title',
                    conceptId: button.getAttribute('data-concept-id') || '',
                    rankOne: button.getAttribute('data-rank-one') || '0',
                    rankTwo: button.getAttribute('data-rank-two') || '0',
                    rankThree: button.getAttribute('data-rank-three') || '0',
                    hasTie: button.getAttribute('data-has-tie') || '0'
                };
                applyFinalPickDetails(payload);
            });
        });

        finalPickModal.addEventListener('show.bs.modal', (event) => {
            if (event.relatedTarget) {
                return;
            }
            applyFinalPickDetails({
                studentId: finalPickForm.dataset.currentStudentId || '',
                studentName: finalPickForm.dataset.currentStudentName || 'Student',
                studentEmail: finalPickForm.dataset.currentStudentEmail || 'Not available',
                finalTitle: finalPickForm.dataset.currentFinalTitle || 'Final title',
                conceptId: finalPickForm.dataset.currentConceptId || '',
                rankOne: finalPickForm.dataset.currentRankOne || '0',
                rankTwo: finalPickForm.dataset.currentRankTwo || '0',
                rankThree: finalPickForm.dataset.currentRankThree || '0',
                hasTie: finalPickForm.dataset.currentHasTie || '0'
            });
        });

        finalPickForm.addEventListener('submit', () => {
            const studentField = document.getElementById('finalPickStudentId');
            if (!studentField.value && finalPickForm.dataset.currentStudentId) {
                studentField.value = finalPickForm.dataset.currentStudentId;
            }
        });
    }
</script>
</body>
</html>
