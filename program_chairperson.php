<?php
session_start();
include 'db.php';
require_once 'concept_review_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_concept_helpers.php';
require_once 'endorsement_helpers.php';
require_once 'chair_scope_helper.php';
require_once 'role_helpers.php';
require_once 'progress_tracker_helper.php';

enforce_role_access(['program_chairperson']);

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);

ensureConceptReviewTables($conn);
syncConceptPapersFromSubmissions($conn);
ensureReviewerInviteFeedbackTable($conn);
ensureConceptReviewMessagesTable($conn);
ensureFinalConceptSubmissionTable($conn);
ensureFinalPickMessagesTable($conn);
ensureEndorsementRequestsTable($conn);

$chairFeedbackAlert = null;
$finalPickAlert = null;
$endorsementAlert = null;
$reviewerMessageAlert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_final_pick_message'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $studentName = trim((string)($_POST['student_name'] ?? 'the student'));
    $finalTitle = trim((string)($_POST['final_title'] ?? ''));
    $conceptId = (int)($_POST['concept_id'] ?? 0);
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

        $saveStmt = $conn->prepare("
            INSERT INTO final_pick_messages (student_id, concept_paper_id, final_title, message_body, sent_by)
            VALUES (?, NULLIF(?, 0), ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                concept_paper_id = VALUES(concept_paper_id),
                final_title = VALUES(final_title),
                message_body = VALUES(message_body),
                sent_by = VALUES(sent_by),
                sent_at = CURRENT_TIMESTAMP
        ");
        if ($saveStmt) {
            $saveStmt->bind_param('iissi', $studentId, $conceptId, $finalTitle, $messageBody, $programChairId);
            $saveStmt->execute();
            $saveStmt->close();
            if (function_exists('progress_tracker_mark_step_complete')) {
                progress_tracker_mark_step_complete($conn, $studentId, 'final_concept_recommended', 'final_pick_messages', null);
            }
            $finalPickAlert = ['type' => 'success', 'message' => "Final pick message sent to {$studentName}."];
        } else {
            $finalPickAlert = ['type' => 'warning', 'message' => "Final pick message sent to {$studentName}, but the directory status was not updated."];
        }
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
            $chairFeedbackAlert = ['type' => 'warning', 'message' => 'This reviewer has not submitted a score yet. Feedback will be available once their review is saved.'];
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
        } elseif (!student_matches_scope_any($conn, (int)($endorsementRow['student_id'] ?? 0), $chairScope)) {
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
                        if (function_exists('progress_tracker_mark_step_complete')) {
                            progress_tracker_mark_step_complete($conn, $studentId, 'endorsement_verified', 'endorsement_requests', $endorsementId);
                        }
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
        AVG(CASE WHEN cr.score IS NOT NULL AND cr.score > 0 THEN cr.score END) AS avg_score,
        COUNT(CASE WHEN cr.score IS NOT NULL AND cr.score > 0 THEN 1 END) AS score_count
    FROM concept_reviews cr
    JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
    JOIN concept_papers cp ON cp.id = cr.concept_paper_id
    LEFT JOIN users u ON u.id = cp.student_id
    WHERE cr.score IS NOT NULL AND cr.score > 0
";
if ($conceptScopeWhere !== '') {
    $rankingSql .= "      AND ({$conceptScopeWhere} OR cra.assigned_by = {$programChairId})\n";
}
$rankingSql .= "
    GROUP BY cp.id, cp.student_id, cp.title, cp.created_at, student_name, u.email
    HAVING score_count > 0
    ORDER BY avg_score DESC, score_count DESC, cp.created_at DESC
    LIMIT 80
";
$rankingBoardFull = [];
$rankingBoardSummary = [
    'score_sum' => 0,
    'review_count' => 0,
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
                'best_avg_score' => 0,
                'best_review_count' => 0,
                'reviewers' => [],
                'interested_reviewers' => [],
                'interest_keys' => [],
            ];
        }

        $avgScore = isset($row['avg_score']) ? (float)$row['avg_score'] : 0.0;
        $scoreCount = (int)($row['score_count'] ?? 0);
        $concept = [
            'concept_id' => (int)($row['concept_id'] ?? 0),
            'title' => $row['title'] ?? 'Untitled Concept',
            'avg_score' => $avgScore,
            'score_count' => $scoreCount,
            'score_key' => [
                $avgScore,
                $scoreCount,
                (int)($row['concept_id'] ?? 0),
            ],
        ];
        $rankingBoardFull[$studentId]['concepts'][] = $concept;
        $bestAvg = (float)($rankingBoardFull[$studentId]['best_avg_score'] ?? 0);
        $bestCount = (int)($rankingBoardFull[$studentId]['best_review_count'] ?? 0);
        if ($avgScore > $bestAvg || ($avgScore === $bestAvg && $scoreCount > $bestCount)) {
            $rankingBoardFull[$studentId]['best_avg_score'] = $avgScore;
            $rankingBoardFull[$studentId]['best_review_count'] = $scoreCount;
        }
        if ($scoreCount > 0) {
            $rankingBoardSummary['concepts']++;
        }
        $rankingBoardSummary['review_count'] += $scoreCount;
        $rankingBoardSummary['score_sum'] += $avgScore * $scoreCount;
    }
    $rankingResult->free();
}

$rankingProgress = [];
$progressSql = "
    SELECT
        reviewer_progress.student_id,
        COUNT(DISTINCT reviewer_progress.reviewer_id) AS total_assignments,
        COUNT(DISTINCT CASE WHEN reviewer_progress.reviewer_started = 1 THEN reviewer_progress.reviewer_id END) AS ranked_assignments
    FROM (
        SELECT
            cra.student_id,
            cra.reviewer_id,
            COUNT(DISTINCT cra.id) AS total_review_assignments,
            COUNT(DISTINCT CASE WHEN cr.id IS NOT NULL THEN cra.id END) AS reviewed_assignments,
            COUNT(DISTINCT CASE WHEN cr.score IS NOT NULL AND cr.score > 0 THEN cra.id END) AS scored_review_assignments,
            CASE
                WHEN COUNT(DISTINCT CASE WHEN cr.score IS NOT NULL AND cr.score > 0 THEN cra.id END) > 0
                THEN 1 ELSE 0 END AS reviewer_started
        FROM concept_reviewer_assignments cra
        LEFT JOIN concept_reviews cr ON cr.assignment_id = cra.id
        JOIN users u ON u.id = cra.student_id
";
if ($conceptScopeWhere !== '') {
    $progressSql .= " WHERE ({$conceptScopeWhere} OR cra.assigned_by = {$programChairId})\n";
}
$progressSql .= "
        GROUP BY cra.student_id, cra.reviewer_id
    ) AS reviewer_progress
    GROUP BY reviewer_progress.student_id
";
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
        cr.score AS review_score,
        cr.recommendation AS review_recommendation,
        cr.rank_order,
        cr.is_preferred,
        cr.adviser_interest,
        cr.comment_suggestions,
        cr.notes AS review_notes,
        cr.updated_at AS review_updated_at,
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
$reviewerAssignmentMeta = [];
$reviewerAssignmentIds = [];
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
                'scores' => [],
                'has_interest' => false,
                'primary_assignment_id' => (int)($row['assignment_id'] ?? 0),
                'primary_review_id' => isset($row['review_id']) ? (int)$row['review_id'] : 0,
                'student_id' => $studentId,
            ];
        }
        $entry =& $rankingBoardFull[$studentId]['reviewers'][$reviewerKey];
        $scoreValue = isset($row['review_score']) ? (int)$row['review_score'] : 0;
        $conceptIdValue = (int)($row['concept_id'] ?? 0);
        if ($scoreValue > 0 && $conceptIdValue > 0) {
            $entry['scores'][$conceptIdValue] = [
                'concept_id' => $conceptIdValue,
                'title' => $row['concept_title'] ?? 'Untitled Concept',
                'score' => $scoreValue,
                'recommendation' => $row['review_recommendation'] ?? '',
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

        $assignmentId = (int)($row['assignment_id'] ?? 0);
        if ($assignmentId > 0) {
            $reviewerAssignmentIds[] = $assignmentId;
            $comment = trim((string)($row['comment_suggestions'] ?? ''));
            $notes = trim((string)($row['review_notes'] ?? ''));
            if ($comment === '' && $notes !== '') {
                $comment = $notes;
            }
            $reviewerAssignmentMeta[$studentId][] = [
                'assignment_id' => $assignmentId,
                'concept_id' => (int)($row['concept_id'] ?? 0),
                'concept_title' => $row['concept_title'] ?? 'Untitled Concept',
                'student_id' => $studentId,
                'reviewer_id' => $reviewerId,
                'reviewer_name' => trim($row['reviewer_name'] ?? 'Reviewer'),
                'reviewer_role' => $row['reviewer_role'] ?? '',
                'comment' => $comment,
                'has_interest' => $interestFlag,
                'review_updated_at' => $row['review_updated_at'] ?? null,
            ];
        }
    }
    $reviewerResult->free();
}

$reviewerAssignmentIds = array_values(array_unique(array_filter($reviewerAssignmentIds)));
$conversationLookup = fetchConceptReviewMessagesByAssignments($conn, $reviewerAssignmentIds);

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
    $feedbackEntries = [];
    foreach (($reviewerAssignmentMeta[$studentId] ?? []) as $assignmentMeta) {
        $assignmentId = (int)($assignmentMeta['assignment_id'] ?? 0);
        $reviewerId = (int)($assignmentMeta['reviewer_id'] ?? 0);
        $commentText = trim((string)($assignmentMeta['comment'] ?? ''));
        $messages = $conversationLookup[$assignmentId] ?? [];
        $reviewerMessageCount = 0;
        $lastReviewerMessageAt = null;
        $lastReviewerMessageText = '';
        foreach ($messages as $message) {
            if ((int)($message['sender_id'] ?? 0) === $reviewerId) {
                $reviewerMessageCount++;
                $createdAt = $message['created_at'] ?? null;
                if ($createdAt && (!$lastReviewerMessageAt || strtotime($createdAt) > strtotime($lastReviewerMessageAt))) {
                    $lastReviewerMessageAt = $createdAt;
                    $lastReviewerMessageText = (string)($message['message'] ?? '');
                }
            }
        }
        $hasReviewerMessage = $reviewerMessageCount > 0;
        $hasComment = $commentText !== '';
        $hasInterest = !empty($assignmentMeta['has_interest']);
        if (!$hasReviewerMessage && !$hasComment && !$hasInterest) {
            continue;
        }
        $lastActivity = null;
        $reviewUpdated = $assignmentMeta['review_updated_at'] ?? null;
        if ($lastReviewerMessageAt && $reviewUpdated) {
            $lastActivity = strtotime($lastReviewerMessageAt) >= strtotime($reviewUpdated) ? $lastReviewerMessageAt : $reviewUpdated;
        } elseif ($lastReviewerMessageAt) {
            $lastActivity = $lastReviewerMessageAt;
        } elseif ($reviewUpdated) {
            $lastActivity = $reviewUpdated;
        }
        $feedbackEntries[] = [
            'assignment_id' => $assignmentId,
            'concept_id' => (int)($assignmentMeta['concept_id'] ?? 0),
            'concept_title' => $assignmentMeta['concept_title'] ?? 'Untitled Concept',
            'student_id' => (int)($assignmentMeta['student_id'] ?? 0),
            'reviewer_id' => $reviewerId,
            'reviewer_name' => $assignmentMeta['reviewer_name'] ?? 'Reviewer',
            'reviewer_role' => $assignmentMeta['reviewer_role'] ?? '',
            'comment' => $commentText,
            'has_interest' => $hasInterest,
            'reviewer_message_count' => $reviewerMessageCount,
            'last_reviewer_message' => $lastReviewerMessageText,
            'last_activity' => $lastActivity,
        ];
    }
    if (!empty($feedbackEntries)) {
        usort($feedbackEntries, function ($a, $b) {
            $timeA = $a['last_activity'] ? strtotime($a['last_activity']) : 0;
            $timeB = $b['last_activity'] ? strtotime($b['last_activity']) : 0;
            if ($timeA === $timeB) {
                return strcmp($a['reviewer_name'] ?? '', $b['reviewer_name'] ?? '');
            }
            return $timeB <=> $timeA;
        });
    }
    $board['reviewer_feedback_entries'] = $feedbackEntries;
    $board['reviewers'] = array_values($board['reviewers']);
    unset($board['interest_keys']);
}
unset($board);

$rankingBoardSummary['students'] = count($rankingBoardFull);
$overallAvgScore = $rankingBoardSummary['review_count'] > 0
    ? ($rankingBoardSummary['score_sum'] / $rankingBoardSummary['review_count'])
    : 0;
$rankingBoardCollection = array_values($rankingBoardFull);
usort($rankingBoardCollection, function ($a, $b) {
    $scoreA = $a['best_avg_score'] ?? 0;
    $scoreB = $b['best_avg_score'] ?? 0;
    if ($scoreA === $scoreB) {
        $countA = $a['best_review_count'] ?? 0;
        $countB = $b['best_review_count'] ?? 0;
        if ($countA !== $countB) {
            return $countB <=> $countA;
        }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reviewer_message'])) {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $conceptId = (int)($_POST['concept_id'] ?? 0);
    $studentIdInput = (int)($_POST['student_id'] ?? 0);
    $reviewerIdInput = (int)($_POST['reviewer_id'] ?? 0);
    $messageText = trim(strip_tags((string)($_POST['reviewer_message'] ?? '')));

    if ($assignmentId <= 0 || $conceptId <= 0) {
        $reviewerMessageAlert = ['type' => 'danger', 'message' => 'Missing assignment details for the reviewer message.'];
    } elseif ($messageText === '') {
        $reviewerMessageAlert = ['type' => 'warning', 'message' => 'Please enter a message before sending.'];
    } else {
        $assignmentStmt = $conn->prepare("
            SELECT cra.id, cra.concept_paper_id, cra.student_id, cra.reviewer_id
            FROM concept_reviewer_assignments cra
            WHERE cra.id = ?
            LIMIT 1
        ");
        $assignmentRow = null;
        if ($assignmentStmt) {
            $assignmentStmt->bind_param('i', $assignmentId);
            $assignmentStmt->execute();
            $assignmentResult = $assignmentStmt->get_result();
            $assignmentRow = $assignmentResult ? $assignmentResult->fetch_assoc() : null;
            $assignmentStmt->close();
        }

        $studentId = (int)($assignmentRow['student_id'] ?? 0);
        $assignmentConceptId = (int)($assignmentRow['concept_paper_id'] ?? 0);
        $assignmentReviewerId = (int)($assignmentRow['reviewer_id'] ?? 0);

        if (
            !$assignmentRow ||
            ($conceptId > 0 && $assignmentConceptId !== $conceptId) ||
            ($studentIdInput > 0 && $studentId !== $studentIdInput) ||
            ($reviewerIdInput > 0 && $assignmentReviewerId !== $reviewerIdInput)
        ) {
            $reviewerMessageAlert = ['type' => 'danger', 'message' => 'Unable to send that reviewer message. Please refresh and try again.'];
        } elseif ($studentId <= 0 || !student_matches_scope($conn, $studentId, $chairScope)) {
            $reviewerMessageAlert = ['type' => 'danger', 'message' => 'You can only message reviewers for students in your scope.'];
        } else {
            $saved = saveConceptReviewMessage($conn, [
                'assignment_id' => $assignmentId,
                'concept_paper_id' => $assignmentConceptId,
                'student_id' => $studentId,
                'sender_id' => $programChairId,
                'sender_role' => 'program_chairperson',
                'message' => $messageText,
            ]);
            if ($saved) {
                $reviewerMessageAlert = ['type' => 'success', 'message' => 'Message sent to the reviewer.'];
            } else {
                $reviewerMessageAlert = ['type' => 'danger', 'message' => 'Unable to send the reviewer message right now.'];
            }
        }
    }
}

$activeRankingBoards = array_values(array_filter(
    $rankingBoardCollection,
    static fn($board) => empty($finalPickSentLookup[(int)($board['student_id'] ?? 0)])
));

$finalPickHighlights = [];
foreach ($activeRankingBoards as $board) {
    if (empty($board['final_concept'])) {
        continue;
    }
    $totalAssignments = (int)($board['total_assignments'] ?? 0);
    $rankedAssignments = (int)($board['ranked_assignments'] ?? 0);
    $rankingComplete = $totalAssignments > 0 && $rankedAssignments >= $totalAssignments;

    $topConcepts = array_slice($board['concepts'] ?? [], 0, 3);
    $rankedConceptTitles = array_map(
        static fn($concept) => trim((string)($concept['title'] ?? 'Untitled Concept')),
        $topConcepts
    );
    $rankedConceptScores = array_map(
        static fn($concept) => (float)($concept['avg_score'] ?? 0),
        $topConcepts
    );
    $finalPickHighlights[] = [
        'student_id' => (int)($board['student_id'] ?? 0),
        'student_name' => $board['student_name'] ?? 'Student',
        'student_email' => $board['student_email'] ?? '',
        'concept_id' => $rankingComplete ? (int)($board['final_concept']['concept_id'] ?? 0) : 0,
        'title' => $rankingComplete ? ($board['final_concept']['title'] ?? 'Untitled Concept') : '',
        'avg_score' => $rankingComplete ? (float)($board['final_concept']['avg_score'] ?? 0) : 0,
        'review_count' => $rankingComplete ? (int)($board['final_concept']['score_count'] ?? 0) : 0,
        'top_one_title' => $rankingComplete ? ($rankedConceptTitles[0] ?? '') : '',
        'top_two_title' => $rankingComplete ? ($rankedConceptTitles[1] ?? '') : '',
        'top_three_title' => $rankingComplete ? ($rankedConceptTitles[2] ?? '') : '',
        'top_one_score' => $rankingComplete ? (float)($rankedConceptScores[0] ?? 0) : 0,
        'top_two_score' => $rankingComplete ? (float)($rankedConceptScores[1] ?? 0) : 0,
        'top_three_score' => $rankingComplete ? (float)($rankedConceptScores[2] ?? 0) : 0,
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
[$endorsementScopeClause, $endorsementScopeTypes, $endorsementScopeParams] = build_scope_condition_any($chairScope, 'stu');
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
    <link rel="stylesheet" href="progchair.css?v=20260301">
</head>
<body class="bg-light program-chair-layout">
<?php include 'header.php'; ?>
<div class="dashboard-shell">
<?php include 'sidebar.php'; ?>

<main class="content dashboard-content" role="main">
    <style>
        .ranking-board-shell {
            grid-template-columns: 1fr !important;
        }
        .reviewer-feedback-card .reviewer-feedback-table {
            table-layout: fixed;
            width: 100%;
        }
        .reviewer-feedback-card .reviewer-feedback-table thead th {
            padding: 0.35rem 0.45rem;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .reviewer-feedback-card .reviewer-feedback-table tbody td {
            padding: 0.35rem 0.45rem;
            vertical-align: middle;
            font-size: 0.9rem;
            line-height: 1.1;
        }
        .reviewer-feedback-card .reviewer-feedback-table td:nth-child(1) { width: 18%; }
        .reviewer-feedback-card .reviewer-feedback-table td:nth-child(2) { width: 10%; }
        .reviewer-feedback-card .reviewer-feedback-table td:nth-child(3) { width: 40%; }
        .reviewer-feedback-card .reviewer-feedback-table td:nth-child(4) { width: 16%; }
        .reviewer-feedback-card .reviewer-feedback-table td:nth-child(5) { width: 16%; }
        .reviewer-feedback-card .feedback-note {
            display: block;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .reviewer-feedback-card .reviewer-message-btn {
            padding: 0.3rem 0.65rem;
            font-size: 0.8rem;
            line-height: 1.1;
            min-width: 105px;
            white-space: nowrap;
        }
        .reviewer-feedback-card .badge {
            font-size: 0.7rem;
        }
    </style>
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
        <?php if ($reviewerMessageAlert): ?>
            <div class="alert alert-<?= htmlspecialchars($reviewerMessageAlert['type']); ?> border-0 shadow-sm">
                <?= htmlspecialchars($reviewerMessageAlert['message']); ?>
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
                            <a href="assign_faculty_replacement.php" class="btn btn-outline-warning btn-sm">Assign Reviewers</a>
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
                            <p class="text-muted small mb-0">Review average scores and faculty input per student.</p>
                        </div>
                        <div class="d-flex flex-row flex-nowrap gap-2">
                            <a href="completed_rankings.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-list-check me-1"></i> Completed Directory
                            </a>
                            <a href="assign_faculty_replacement.php" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-people-fill me-1"></i> Adjust Assignments
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($activeRankingBoards)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-bar-chart-line fs-2 d-block mb-2"></i>
                                <p class="mb-0">No active scores to review. Completed recommendations appear in the directory.</p>
                            </div>
                        <?php else: ?>
                            <div class="ranking-board-shell">
                                <div class="ranking-board-list">
                                    <div class="list-group ranking-student-list" data-ranking-list>
                                        <?php foreach ($activeRankingBoards as $board): ?>
                                            <?php
                                                $rankedAssignments = (int)($board['ranked_assignments'] ?? 0);
                                                $totalAssignments = (int)($board['total_assignments'] ?? 0);
                                                $rankingComplete = !empty($board['ranking_complete']);
                                                $progressLabel = $totalAssignments > 0
                                                    ? "Scored {$rankedAssignments} of {$totalAssignments} reviewers"
                                                    : "No reviewer assignments yet";
                                                $bestAvgScore = isset($board['best_avg_score']) ? (float)$board['best_avg_score'] : 0;
                                                $bestAvgLabel = $bestAvgScore > 0 ? number_format($bestAvgScore, 1) : '0.0';
                                                if ($totalAssignments <= 0) {
                                                    $statusKey = 'unassigned';
                                                    $statusLabel = 'No assignments';
                                                    $statusClass = 'bg-secondary-subtle text-secondary';
                                                } elseif ($rankingComplete) {
                                                    $statusKey = 'complete';
                                                    $statusLabel = 'Completed';
                                                    $statusClass = 'bg-success-subtle text-success';
                                                } elseif ($rankedAssignments > 0) {
                                                    $statusKey = 'in_progress';
                                                    $statusLabel = 'In progress';
                                                    $statusClass = 'bg-warning-subtle text-warning';
                                                } else {
                                                    $statusKey = 'waiting';
                                                    $statusLabel = 'Waiting';
                                                    $statusClass = 'bg-info-subtle text-info';
                                                }
                                                $searchKey = strtolower(trim(($board['student_name'] ?? '') . ' ' . ($board['student_email'] ?? '')));
                                            ?>
                                            <button
                                                type="button"
                                                class="list-group-item list-group-item-action ranking-student-item"
                                                data-student-id="<?= (int)($board['student_id'] ?? 0); ?>"
                                                data-status="<?= htmlspecialchars($statusKey); ?>"
                                                data-search="<?= htmlspecialchars($searchKey, ENT_QUOTES); ?>"
                                            >
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <div>
                                                        <div class="fw-semibold text-success"><?= htmlspecialchars($board['student_name']); ?></div>
                                                        <div class="text-muted small"><?= htmlspecialchars($board['student_email'] ?? ''); ?></div>
                                                    </div>
                                                    <span class="badge <?= $statusClass; ?>"><?= htmlspecialchars($statusLabel); ?></span>
                                                </div>
                                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                                    <small class="text-muted"><?= number_format(count($board['concepts'] ?? [])); ?> titles</small>
                                                    <span class="badge bg-success-subtle text-success">Avg: <?= $bestAvgLabel; ?></span>
                                                    <small class="text-muted"><?= htmlspecialchars($progressLabel); ?></small>
                                                </div>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="ranking-board-detail">
                                    <div class="ranking-detail-empty" data-ranking-empty>
                                        Select a student to preview score details.
                                    </div>
                                    <?php foreach ($activeRankingBoards as $board): ?>
                                        <?php
                                            $rankedAssignments = (int)($board['ranked_assignments'] ?? 0);
                                            $totalAssignments = (int)($board['total_assignments'] ?? 0);
                                            $rankingComplete = !empty($board['ranking_complete']);
                                            $progressLabel = $totalAssignments > 0
                                                ? "Scored {$rankedAssignments} of {$totalAssignments} reviewers"
                                                : "No reviewer assignments yet";
                                            $progressClass = $rankingComplete ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
                                            $recommendationClass = $rankingComplete
                                                ? 'alert alert-success-subtle border-success text-success'
                                                : 'alert alert-warning-subtle border-warning text-warning';
                                        ?>
                                        <div class="ranking-detail-panel d-none" data-ranking-detail data-student-id="<?= (int)($board['student_id'] ?? 0); ?>">
                                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                                <div>
                                                    <h5 class="mb-1 text-success"><?= htmlspecialchars($board['student_name']); ?></h5>
                                                    <div class="text-muted small"><?= htmlspecialchars($board['student_email'] ?? ''); ?></div>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge <?= $progressClass; ?>"><?= htmlspecialchars($progressLabel); ?></span>
                                                    <div class="small text-muted mt-1"><?= number_format(count($board['concepts'] ?? [])); ?> titles scored</div>
                                                </div>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle mb-3">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Concept Title</th>
                                                            <th class="text-center">Avg&nbsp;Score</th>
                                                            <th class="text-center">Reviews</th>
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
                                                                <td class="text-center">
                                                                    <span class="badge bg-success-subtle text-success">
                                                                        <?= number_format((float)($concept['avg_score'] ?? 0), 1); ?>
                                                                    </span>
                                                                </td>
                                                                <td class="text-center">
                                                                    <span class="badge bg-info-subtle text-info">
                                                                        <?= number_format((int)($concept['score_count'] ?? 0)); ?>
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php if (!empty($board['final_concept'])): ?>
                                                <div class="<?= $recommendationClass; ?>">
                                                    <?php
                                                        $finalAvgScore = (float)($board['final_concept']['avg_score'] ?? 0);
                                                        $finalReviewCount = (int)($board['final_concept']['score_count'] ?? 0);
                                                    ?>
                                                    <strong>Recommended concept:</strong> <?= htmlspecialchars($board['final_concept']['title'] ?? ''); ?>
                                                    (Avg score <?= number_format($finalAvgScore, 1); ?> from <?= number_format($finalReviewCount); ?> review<?= $finalReviewCount === 1 ? '' : 's'; ?>)
                                                    <?php if (!empty($board['has_tie_on_top'])): ?>
                                                        <span class="badge bg-warning-subtle text-warning ms-2">Tie on Avg Score</span>
                                                    <?php endif; ?>
                                                    <div class="small text-muted mb-0">
                                                        <?php if ($rankingComplete): ?>
                                                            Basis: Highest average score. Ties break via number of submitted reviews.
                                                        <?php else: ?>
                                                            Preliminary result &mdash; waiting for remaining reviewer scores.
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
                                                                    <th>Scores</th>
                                                                    <th class="text-center">Mentor Interest</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($board['reviewers'] as $reviewer): ?>
                                                                    <?php
                                                                        $scoreEntries = $reviewer['scores'] ?? [];
                                                                        if (!empty($scoreEntries)) {
                                                                            $scoreEntries = array_values($scoreEntries);
                                                                            usort($scoreEntries, function ($a, $b) {
                                                                                return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
                                                                            });
                                                                        }
                                                                    ?>
                                                                    <tr>
                                                                        <td class="fw-semibold"><?= htmlspecialchars($reviewer['reviewer_name']); ?></td>
                                                                        <td class="text-muted small text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $reviewer['reviewer_role'] ?? '')); ?></td>
                                                                        <td>
                                                                            <?php if (empty($scoreEntries)): ?>
                                                                                <span class="text-muted">-</span>
                                                                            <?php else: ?>
                                                                                <?php foreach ($scoreEntries as $scoreEntry): ?>
                                                                                    <?php
                                                                                        $scoreTitle = trim((string)($scoreEntry['title'] ?? 'Untitled Concept'));
                                                                                        $scoreValue = (int)($scoreEntry['score'] ?? 0);
                                                                                        $scoreRec = trim((string)($scoreEntry['recommendation'] ?? ''));
                                                                                        $scoreLabel = $scoreValue > 0 ? "{$scoreValue}/5" : '-';
                                                                                        if ($scoreRec !== '') {
                                                                                            $scoreLabel .= ' (' . ucfirst($scoreRec) . ')';
                                                                                        }
                                                                                    ?>
                                                                                    <div class="small">
                                                                                        <span class="fw-semibold"><?= htmlspecialchars($scoreTitle); ?></span>
                                                                                        &mdash; <?= htmlspecialchars($scoreLabel); ?>
                                                                                    </div>
                                                                                <?php endforeach; ?>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="text-center">
                                                                            <?php if (!empty($reviewer['has_interest'])): ?>
                                                                                <span class="badge bg-success-subtle text-success">Yes</span>
                                                                            <?php else: ?>
                                                                                <span class="text-muted">&mdash;</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-muted small fst-italic">Awaiting this reviewer&rsquo;s submitted score.</div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($board['reviewer_feedback_entries'])): ?>
                                                <div class="mt-3 reviewer-feedback-card">
                                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                                        <h6 class="text-uppercase text-muted mb-0">Reviewer Messages &amp; Suggestions</h6>
                                                        <span class="badge bg-info-subtle text-info">
                                                            <?= number_format(count($board['reviewer_feedback_entries'])); ?> item<?= count($board['reviewer_feedback_entries']) !== 1 ? 's' : ''; ?>
                                                        </span>
                                                    </div>
                                                    <div class="table-responsive">
                                                        <table class="table table-sm align-middle mb-0 reviewer-feedback-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Reviewer</th>
                                                                    <th>Role</th>
                                                                    <th>Concept</th>
                                                                    <th>Trigger</th>
                                                                    <th class="text-end">Action</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($board['reviewer_feedback_entries'] as $entry): ?>
                                                                    <?php
                                                                        $roleLabel = trim(str_replace('_', ' ', (string)($entry['reviewer_role'] ?? '')));
                                                                        if ($roleLabel === '') {
                                                                            $roleLabel = 'Reviewer';
                                                                        }
                                                                        $commentPreview = trim((string)($entry['comment'] ?? ''));
                                                                        if ($commentPreview !== '') {
                                                                            $commentPreview = preg_replace('/\s+/', ' ', $commentPreview);
                                                                        }
                                                                        $messagePreview = trim((string)($entry['last_reviewer_message'] ?? ''));
                                                                        if ($messagePreview !== '') {
                                                                            $messagePreview = preg_replace('/\s+/', ' ', $messagePreview);
                                                                        }
                                                                        $messageCount = (int)($entry['reviewer_message_count'] ?? 0);
                                                                        $hasComment = $commentPreview !== '';
                                                                        $hasMentorInterest = !empty($entry['has_interest']);
                                                                    ?>
                                                                    <tr>
                                                                        <td class="fw-semibold"><?= htmlspecialchars($entry['reviewer_name']); ?></td>
                                                                        <td class="text-muted small text-capitalize"><?= htmlspecialchars($roleLabel); ?></td>
                                                                        <td>
                                                                            <div class="fw-semibold"><?= htmlspecialchars($entry['concept_title']); ?></div>
                                                                            <?php if ($hasComment): ?>
                                                                                <div class="small text-muted feedback-note" title="<?= htmlspecialchars($commentPreview); ?>">
                                                                                    Comment: <?= htmlspecialchars($commentPreview); ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                            <?php if ($messagePreview !== ''): ?>
                                                                                <div class="small text-muted feedback-note" title="<?= htmlspecialchars($messagePreview); ?>">
                                                                                    Message: <?= htmlspecialchars($messagePreview); ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td>
                                                                            <?php if ($messageCount > 0): ?>
                                                                                <span class="badge bg-info-subtle text-info">Message<?= $messageCount > 1 ? " ({$messageCount})" : ''; ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if ($hasComment): ?>
                                                                                <span class="badge bg-secondary-subtle text-secondary">Comment</span>
                                                                            <?php endif; ?>
                                                                            <?php if ($hasMentorInterest): ?>
                                                                                <span class="badge bg-success-subtle text-success">Mentor interest</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="text-end">
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-sm btn-outline-success reviewer-message-btn"
                                                                                data-bs-toggle="modal"
                                                                                data-bs-target="#reviewerMessageModal"
                                                                                data-assignment-id="<?= (int)($entry['assignment_id'] ?? 0); ?>"
                                                                                data-concept-id="<?= (int)($entry['concept_id'] ?? 0); ?>"
                                                                                data-student-id="<?= (int)($entry['student_id'] ?? 0); ?>"
                                                                                data-reviewer-id="<?= (int)($entry['reviewer_id'] ?? 0); ?>"
                                                                                data-reviewer-name="<?= htmlspecialchars($entry['reviewer_name'], ENT_QUOTES); ?>"
                                                                                data-reviewer-role="<?= htmlspecialchars($roleLabel, ENT_QUOTES); ?>"
                                                                                data-concept-title="<?= htmlspecialchars($entry['concept_title'], ENT_QUOTES); ?>"
                                                                                data-context-label="<?= $messageCount > 0 ? 'Reviewer sent a message' : ($hasComment ? 'Reviewer left comments' : 'Reviewer interest noted'); ?>"
                                                                                data-thread-target="reviewerThread<?= (int)($entry['assignment_id'] ?? 0); ?>"
                                                                            >
                                                                                Message reviewer
                                                                            </button>
                                                                            <template id="reviewerThread<?= (int)($entry['assignment_id'] ?? 0); ?>">
                                                                                <div class="reviewer-thread">
                                                                                    <?php $threadMessages = $conversationLookup[(int)($entry['assignment_id'] ?? 0)] ?? []; ?>
                                                                                    <?php if (empty($threadMessages)): ?>
                                                                                        <div class="reviewer-thread-empty">No messages yet.</div>
                                                                                    <?php else: ?>
                                                                                        <?php foreach ($threadMessages as $threadMessage): ?>
                                                                                            <?php
                                                                                                $senderId = (int)($threadMessage['sender_id'] ?? 0);
                                                                                                $isReviewerMessage = $senderId === (int)($entry['reviewer_id'] ?? 0);
                                                                                                $senderName = trim((string)($threadMessage['sender_name'] ?? ''));
                                                                                                if ($senderName === '') {
                                                                                                    $senderName = $isReviewerMessage ? ($entry['reviewer_name'] ?? 'Reviewer') : 'Program Chairperson';
                                                                                                }
                                                                                                $reviewerRoleLabel = trim(str_replace('_', ' ', (string)($entry['reviewer_role'] ?? 'Reviewer')));
                                                                                                if ($reviewerRoleLabel === '') {
                                                                                                    $reviewerRoleLabel = 'Reviewer';
                                                                                                }
                                                                                                $roleLabelMap = [
                                                                                                    'faculty' => 'Faculty Reviewer',
                                                                                                    'panel' => 'Panel Reviewer',
                                                                                                    'committee chair' => 'Committee Chair',
                                                                                                    'committee chairperson' => 'Committee Chair',
                                                                                                    'adviser' => 'Adviser',
                                                                                                ];
                                                                                                $reviewerRoleLabel = $roleLabelMap[$reviewerRoleLabel] ?? $reviewerRoleLabel;
                                                                                                $senderRoleRaw = strtolower(trim((string)($threadMessage['sender_role'] ?? '')));
                                                                                                $isChairMessage = ($senderRoleRaw === 'program_chairperson') || ($senderId === (int)$programChairId);
                                                                                                $roleBadge = $isChairMessage ? 'Chair' : $reviewerRoleLabel;
                                                                                                $bubbleStyle = $isChairMessage
                                                                                                    ? 'border:1px solid rgba(22,86,44,0.25); background:#f7fbf8;'
                                                                                                    : 'border:1px solid rgba(24,90,188,0.25); background:#f7f9ff;';
                                                                                                $roleStyle = $isChairMessage
                                                                                                    ? 'background:rgba(22,86,44,0.12); color:#16562c;'
                                                                                                    : 'background:rgba(24,90,188,0.12); color:#185abc;';
                                                                                                $createdAt = $threadMessage['created_at'] ?? '';
                                                                                                $createdLabel = $createdAt ? date('M j, Y g:i A', strtotime($createdAt)) : 'Not recorded';
                                                                                            ?>
                                                                                            <div class="reviewer-thread-msg<?= $isChairMessage ? ' is-chair' : ' is-reviewer'; ?>" style="<?= $bubbleStyle; ?>">
                                                                                                <div class="reviewer-thread-meta">
                                                                                                    <span class="reviewer-thread-name"><?= htmlspecialchars($senderName); ?></span>
                                                                                                    <span class="reviewer-thread-sep">&middot;</span>
                                                                                                    <span class="reviewer-thread-role <?= $isChairMessage ? 'chair' : 'reviewer'; ?>" style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:0.7rem; <?= $roleStyle; ?>">
                                                                                                        <?= htmlspecialchars($roleBadge); ?>
                                                                                                    </span>
                                                                                                    <span class="reviewer-thread-sep">&middot;</span>
                                                                                                    <span class="reviewer-thread-time"><?= htmlspecialchars($createdLabel); ?></span>
                                                                                                </div>
                                                                                                <div class="reviewer-thread-body"><?= nl2br(htmlspecialchars((string)($threadMessage['message'] ?? ''))); ?></div>
                                                                                            </div>
                                                                                        <?php endforeach; ?>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </template>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
            </section>
            <div class="d-flex flex-column gap-4">
                
                <section class="card shadow-sm border-0">
                        <div class="card-body">
                            <h6 class="text-uppercase text-muted mb-3">Score Summary</h6>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <small class="text-muted d-block">Avg score (overall)</small>
                                    <h3 class="mb-0 text-success"><?= number_format($overallAvgScore, 1); ?></h3>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted d-block">Titles scored</small>
                                    <h4 class="mb-0"><?= number_format($rankingBoardSummary['concepts']); ?></h4>
                                </div>
                            </div>
                            <ul class="list-unstyled small text-muted mb-4">
                                <li>Students with reviewer scores: <strong><?= number_format($rankingBoardSummary['students']); ?></strong></li>
                                <li>Assignments awaiting scores: <strong><?= number_format($assignmentStats['pending']); ?></strong></li>
                                <li>Reviewer deadlines due soon: <strong><?= number_format($assignmentStats['due_soon']); ?></strong></li>
                            </ul>
                            <a href="assign_faculty_replacement.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-stars me-1"></i> Accelerate scoring cycle
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
                                            <?php
                                                $endorsementBody = strip_tags((string)($endorsement['body'] ?? ''), '<u><br>');
                                                $signaturePattern = '/<u(?=[^>]*width\\s*:\\s*200px)(?=[^>]*height\\s*:\\s*60px)(?=[^>]*border-bottom)[^>]*>(?:&nbsp;|\\s)*<\\/u>/i';
                                                if (preg_match('/<u[^>]*background[^>]*>(?:&nbsp;|\\s)*<\\/u>/i', $endorsementBody)) {
                                                    $kept = false;
                                                    $endorsementBody = preg_replace_callback($signaturePattern, function ($match) use (&$kept) {
                                                        $tag = $match[0];
                                                        $hasBackground = stripos($tag, 'background') !== false;
                                                        if ($hasBackground && !$kept) {
                                                            $kept = true;
                                                            return $tag;
                                                        }
                                                        return '';
                                                    }, $endorsementBody);
                                                }
                                                $endorsementHtml = str_replace(["\r\n", "\n"], "<br>", $endorsementBody);
                                            ?>
                                            <div class="mt-2 small"><?php echo $endorsementHtml; ?></div>
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
                            <p class="text-muted small mb-0">Auto-generated recommendations based on reviewer scores.</p>
                        </div>
                        <a href="assign_faculty_replacement.php" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-arrow-repeat me-1"></i> Refresh Assignments
                        </a>
                    </div>
                    <div class="card-body pt-0">
                        <?php if (empty($finalPickHighlights)): ?>
                            <p class="text-muted mb-0">No final pick recommendations yet. Score data will appear once reviewers submit evaluations.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Email</th>
                                            <th>Title</th>
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
                                                        ? "Awaiting scores ({$rankedAssignments}/{$totalAssignments} reviewers)"
                                                        : 'Awaiting scores';
                                                    $statusClass = 'bg-warning-subtle text-warning';
                                                } else {
                                                    $finalStatus = trim((string)($pick['final_submission_status'] ?? ''));
                                                    if ($finalStatus !== '') {
                                                        $statusLabel = $finalStatus;
                                                        $statusClass = finalConceptStatusClass($finalStatus);
                                                    } else {
                                                        $statusLabel = 'Recommended';
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
                                                            <span class="badge bg-warning-subtle text-warning ms-2">Tie on Avg Score</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="fw-semibold text-muted">Awaiting final scores</div>
                                                        <?php if ($totalAssignments > 0): ?>
                                                            <small class="text-muted">Scored <?= number_format($rankedAssignments); ?> of <?= number_format($totalAssignments); ?> reviewers</small>
                                                        <?php endif; ?>
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
                                                            data-avg-score="<?= htmlspecialchars(number_format((float)($pick['avg_score'] ?? 0), 1), ENT_QUOTES); ?>"
                                                            data-review-count="<?= (int)($pick['review_count'] ?? 0); ?>"
                                                            data-top-one-title="<?= htmlspecialchars($pick['top_one_title'] ?? '', ENT_QUOTES); ?>"
                                                            data-top-two-title="<?= htmlspecialchars($pick['top_two_title'] ?? '', ENT_QUOTES); ?>"
                                                            data-top-three-title="<?= htmlspecialchars($pick['top_three_title'] ?? '', ENT_QUOTES); ?>"
                                                            data-top-one-score="<?= htmlspecialchars(number_format((float)($pick['top_one_score'] ?? 0), 1), ENT_QUOTES); ?>"
                                                            data-top-two-score="<?= htmlspecialchars(number_format((float)($pick['top_two_score'] ?? 0), 1), ENT_QUOTES); ?>"
                                                            data-top-three-score="<?= htmlspecialchars(number_format((float)($pick['top_three_score'] ?? 0), 1), ENT_QUOTES); ?>"
                                                            data-has-tie="<?= !empty($pick['has_tie_on_top']) ? '1' : '0'; ?>"
                                                        >
                                                            Message student
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                            Waiting scores
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
                            <a href="assign_faculty_replacement.php" class="btn btn-success w-100">
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
                                        <a href="assign_faculty_replacement.php?paper=<?php echo (int)($assignment['id'] ?? 0); ?>" class="btn btn-outline-success btn-sm">
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
                <h5 class="modal-title text-success">Send Feedback</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
            <div class="modal-body">
                <input type="hidden" name="send_chair_feedback" value="1">
                <input type="hidden" name="feedback_target" id="feedbackTargetInput" value="student">
                <input type="hidden" name="assignment_id" id="feedbackAssignmentId">
                <input type="hidden" name="concept_id" id="feedbackConceptId">
                <input type="hidden" name="student_id" id="feedbackStudentId">
                <input type="hidden" name="reviewer_id" id="feedbackReviewerId">
                <input type="hidden" name="student_name" id="feedbackStudentNameInput">
                <input type="hidden" name="concept_title" id="feedbackConceptTitleInput">
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackStudentNameDisplay" id="feedbackRecipientLabel">Recipient</label>
                    <input type="text" class="form-control" id="feedbackStudentNameDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackConceptTitleDisplay" id="feedbackTopicLabel">Concept Title</label>
                    <input type="text" class="form-control" id="feedbackConceptTitleDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackAdviserNameDisplay" id="feedbackRoleLabel">Role</label>
                    <input type="text" class="form-control" id="feedbackAdviserNameDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackConceptRankDisplay" id="feedbackStatusLabel">Status</label>
                    <input type="text" class="form-control" id="feedbackConceptRankDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="feedbackUpdatedDisplay" id="feedbackUpdatedLabel">Last Updated</label>
                    <input type="text" class="form-control" id="feedbackUpdatedDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="feedbackMessageTextarea">Message</label>
                    <textarea class="form-control" name="chair_feedback_message" id="feedbackMessageTextarea" rows="4" placeholder="Write a concise note with next steps or guidance." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Send Feedback</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="reviewerMessageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable reviewer-message-modal">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-success">Message Reviewer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body reviewer-message-body">
                <input type="hidden" name="send_reviewer_message" value="1">
                <input type="hidden" name="assignment_id" id="reviewerMessageAssignmentId">
                <input type="hidden" name="concept_id" id="reviewerMessageConceptId">
                <input type="hidden" name="student_id" id="reviewerMessageStudentId">
                <input type="hidden" name="reviewer_id" id="reviewerMessageReviewerId">
                <div class="mb-3">
                    <label class="form-label text-muted small" for="reviewerMessageReviewerName">Reviewer</label>
                    <input type="text" class="form-control" id="reviewerMessageReviewerName" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="reviewerMessageReviewerRole">Role</label>
                    <input type="text" class="form-control" id="reviewerMessageReviewerRole" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="reviewerMessageConceptTitle">Concept Title</label>
                    <input type="text" class="form-control" id="reviewerMessageConceptTitle" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="reviewerMessageContext">Context</label>
                    <input type="text" class="form-control" id="reviewerMessageContext" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="reviewerMessageThread">Conversation</label>
                    <div class="reviewer-thread-shell" id="reviewerMessageThread"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="reviewerMessageTextarea">Message</label>
                    <textarea class="form-control" name="reviewer_message" id="reviewerMessageTextarea" rows="4" placeholder="Write a concise reply to the reviewer." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Send Message</button>
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
                <input type="hidden" name="avg_score" id="finalPickAvgScoreInput">
                <input type="hidden" name="review_count" id="finalPickReviewCountInput">
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickStudentNameDisplay">Student</label>
                    <input type="text" class="form-control" id="finalPickStudentNameDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickStudentEmailDisplay">Student Email</label>
                    <input type="text" class="form-control" id="finalPickStudentEmailDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickTitleDisplay">Title</label>
                    <input type="text" class="form-control" id="finalPickTitleDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small" for="finalPickTieDisplay">Tie on Avg Score</label>
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
    (function() {
        const list = document.querySelector('[data-ranking-list]');
        const panels = Array.from(document.querySelectorAll('[data-ranking-detail]'));
        const emptyState = document.querySelector('[data-ranking-empty]');
        if (!list || panels.length === 0) {
            return;
        }

        const items = Array.from(list.querySelectorAll('.ranking-student-item'));

        const showEmpty = (message) => {
            if (!emptyState) {
                return;
            }
            emptyState.textContent = message || 'Select a student to preview score details.';
            emptyState.classList.remove('d-none');
        };

        const hideEmpty = () => {
            if (!emptyState) {
                return;
            }
            emptyState.classList.add('d-none');
        };

        const setActiveItem = (item) => {
            items.forEach((entry) => entry.classList.remove('is-active'));
            if (item) {
                item.classList.add('is-active');
            }
        };

        const showPanel = (studentId) => {
            let found = false;
            panels.forEach((panel) => {
                const match = panel.dataset.studentId === studentId;
                panel.classList.toggle('d-none', !match);
                if (match) {
                    found = true;
                }
            });
            if (found) {
                hideEmpty();
            } else {
                showEmpty('Select a student to preview score details.');
            }
        };

        const handleSelect = (item) => {
            if (!item) {
                return;
            }
            const studentId = item.dataset.studentId || '';
            if (!studentId) {
                return;
            }
            setActiveItem(item);
            showPanel(studentId);
        };

        const selectFirstVisible = () => {
            const firstVisible = items.find((item) => !item.classList.contains('d-none'));
            if (firstVisible) {
                handleSelect(firstVisible);
            } else {
                panels.forEach((panel) => panel.classList.add('d-none'));
                showEmpty('No students match your filter.');
            }
        };

        items.forEach((item) => {
            item.addEventListener('click', () => handleSelect(item));
        });

        selectFirstVisible();
    })();
</script>

<script>
        const feedbackModal = document.getElementById('chairFeedbackModal');
    if (feedbackModal) {
        const feedbackForm = feedbackModal.querySelector('form');
        const feedbackTargetInput = feedbackModal.querySelector('#feedbackTargetInput');
        const recipientLabel = feedbackModal.querySelector('#feedbackRecipientLabel');
        const topicLabel = feedbackModal.querySelector('#feedbackTopicLabel');
        const roleLabel = feedbackModal.querySelector('#feedbackRoleLabel');
        const statusLabelEl = feedbackModal.querySelector('#feedbackStatusLabel');
        const updatedLabelEl = feedbackModal.querySelector('#feedbackUpdatedLabel');

        const labelMap = {
            student: {
                recipient: 'Student',
                topic: 'Concept Title',
                role: 'Adviser',
                status: 'Rank Result',
                updated: 'Last Updated'
            },
            mentor: {
                recipient: 'Reviewer',
                topic: 'Preferred Title',
                role: 'Reviewer Role',
                status: 'Interest Status',
                updated: 'Last Updated'
            }
        };

        const applyFeedbackLabels = (target) => {
            const labels = labelMap[target] || labelMap.student;
            if (recipientLabel) recipientLabel.textContent = labels.recipient;
            if (topicLabel) topicLabel.textContent = labels.topic;
            if (roleLabel) roleLabel.textContent = labels.role;
            if (statusLabelEl) statusLabelEl.textContent = labels.status;
            if (updatedLabelEl) updatedLabelEl.textContent = labels.updated;
        };

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
                updatedLabel = 'Not recorded',
                feedbackTarget = 'student',
                statusLabel = ''
            } = details;

            feedbackForm.dataset.currentConcept = conceptId;
            feedbackForm.dataset.currentAssignment = assignmentId;
            feedbackForm.dataset.currentStudent = studentId;
            feedbackForm.dataset.currentReviewer = reviewerId;
            feedbackForm.dataset.currentAdviserName = adviserName;
            feedbackForm.dataset.currentUpdated = updatedLabel;
            feedbackForm.dataset.currentFeedbackTarget = feedbackTarget;

            if (feedbackTargetInput) {
                feedbackTargetInput.value = feedbackTarget;
            }
            applyFeedbackLabels(feedbackTarget);

            feedbackModal.querySelector('#feedbackAssignmentId').value = assignmentId;
            feedbackModal.querySelector('#feedbackConceptId').value = conceptId;
            feedbackModal.querySelector('#feedbackStudentId').value = studentId;
            feedbackModal.querySelector('#feedbackReviewerId').value = reviewerId;
            feedbackModal.querySelector('#feedbackStudentNameInput').value = studentName;
            feedbackModal.querySelector('#feedbackConceptTitleInput').value = conceptTitle;
            feedbackModal.querySelector('#feedbackStudentNameDisplay').value = studentName;
            feedbackModal.querySelector('#feedbackConceptTitleDisplay').value = conceptTitle;
            feedbackModal.querySelector('#feedbackAdviserNameDisplay').value = adviserName;
            feedbackModal.querySelector('#feedbackConceptRankDisplay').value = statusLabel || (rankOrder ? `Rank #${rankOrder}` : 'Not ranked yet');
            feedbackModal.querySelector('#feedbackUpdatedDisplay').value = updatedLabel || 'Not recorded';

            const textarea = feedbackModal.querySelector('#feedbackMessageTextarea');
            if (existingFeedback) {
                textarea.value = existingFeedback;
            } else if (feedbackTarget === 'mentor') {
                textarea.value = `Hi ${studentName}, thank you for your mentoring interest in "${conceptTitle}". We'll coordinate the next steps soon.`;
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
                    updatedLabel: button.getAttribute('data-updated-label') || 'Not recorded',
                    feedbackTarget: button.getAttribute('data-feedback-target') || 'student',
                    statusLabel: button.getAttribute('data-status-label') || ''
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
                updatedLabel: feedbackForm.dataset.currentUpdated || 'Not recorded',
                feedbackTarget: feedbackForm.dataset.currentFeedbackTarget || 'student',
                statusLabel: feedbackModal.querySelector('#feedbackConceptRankDisplay').value || ''
            });
        });

        feedbackForm.addEventListener('submit', () => {
            const conceptField = document.getElementById('feedbackConceptId');
            if (!conceptField.value && feedbackForm.dataset.currentConcept) {
                conceptField.value = feedbackForm.dataset.currentConcept;
            }
        });
    }

    const reviewerMessageModal = document.getElementById('reviewerMessageModal');
    if (reviewerMessageModal) {
        const applyReviewerMessageDetails = (details = {}) => {
            const {
                assignmentId = '',
                conceptId = '',
                studentId = '',
                reviewerId = '',
                reviewerName = 'Reviewer',
                reviewerRole = 'Reviewer',
                conceptTitle = 'Concept Title',
                contextLabel = 'Reviewer feedback',
                threadTarget = ''
            } = details;

            reviewerMessageModal.querySelector('#reviewerMessageAssignmentId').value = assignmentId;
            reviewerMessageModal.querySelector('#reviewerMessageConceptId').value = conceptId;
            reviewerMessageModal.querySelector('#reviewerMessageStudentId').value = studentId;
            reviewerMessageModal.querySelector('#reviewerMessageReviewerId').value = reviewerId;
            reviewerMessageModal.querySelector('#reviewerMessageReviewerName').value = reviewerName;
            reviewerMessageModal.querySelector('#reviewerMessageReviewerRole').value = reviewerRole;
            reviewerMessageModal.querySelector('#reviewerMessageConceptTitle').value = conceptTitle;
            reviewerMessageModal.querySelector('#reviewerMessageContext').value = contextLabel;

            const threadContainer = reviewerMessageModal.querySelector('#reviewerMessageThread');
            if (threadContainer) {
                if (threadTarget) {
                    const template = document.getElementById(threadTarget);
                    threadContainer.innerHTML = template ? template.innerHTML : '<div class="reviewer-thread-empty">No messages yet.</div>';
                } else {
                    threadContainer.innerHTML = '<div class="reviewer-thread-empty">No messages yet.</div>';
                }
            }

            const textarea = reviewerMessageModal.querySelector('#reviewerMessageTextarea');
            if (textarea) {
                textarea.value = '';
            }
        };

        document.querySelectorAll('.reviewer-message-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const payload = {
                    assignmentId: button.getAttribute('data-assignment-id') || '',
                    conceptId: button.getAttribute('data-concept-id') || '',
                    studentId: button.getAttribute('data-student-id') || '',
                    reviewerId: button.getAttribute('data-reviewer-id') || '',
                    reviewerName: button.getAttribute('data-reviewer-name') || 'Reviewer',
                    reviewerRole: button.getAttribute('data-reviewer-role') || 'Reviewer',
                    conceptTitle: button.getAttribute('data-concept-title') || 'Concept Title',
                    contextLabel: button.getAttribute('data-context-label') || 'Reviewer feedback',
                    threadTarget: button.getAttribute('data-thread-target') || ''
                };
                applyReviewerMessageDetails(payload);
            });
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
                avgScore = '0',
                reviewCount = '0',
                topOneTitle = '',
                topTwoTitle = '',
                topThreeTitle = '',
                topOneScore = '0',
                topTwoScore = '0',
                topThreeScore = '0',
                hasTie = '0'
            } = details;

            finalPickForm.dataset.currentStudentId = studentId;
            finalPickForm.dataset.currentStudentName = studentName;
            finalPickForm.dataset.currentStudentEmail = studentEmail;
            finalPickForm.dataset.currentFinalTitle = finalTitle;
            finalPickForm.dataset.currentConceptId = conceptId;
            finalPickForm.dataset.currentAvgScore = avgScore;
            finalPickForm.dataset.currentReviewCount = reviewCount;
            finalPickForm.dataset.currentTopOneTitle = topOneTitle;
            finalPickForm.dataset.currentTopTwoTitle = topTwoTitle;
            finalPickForm.dataset.currentTopThreeTitle = topThreeTitle;
            finalPickForm.dataset.currentTopOneScore = topOneScore;
            finalPickForm.dataset.currentTopTwoScore = topTwoScore;
            finalPickForm.dataset.currentTopThreeScore = topThreeScore;
            finalPickForm.dataset.currentHasTie = hasTie;

            finalPickModal.querySelector('#finalPickStudentId').value = studentId;
            finalPickModal.querySelector('#finalPickStudentNameInput').value = studentName;
            finalPickModal.querySelector('#finalPickTitleInput').value = finalTitle;
            finalPickModal.querySelector('#finalPickConceptId').value = conceptId;
            finalPickModal.querySelector('#finalPickAvgScoreInput').value = avgScore;
            finalPickModal.querySelector('#finalPickReviewCountInput').value = reviewCount;

            finalPickModal.querySelector('#finalPickStudentNameDisplay').value = studentName;
            finalPickModal.querySelector('#finalPickStudentEmailDisplay').value = studentEmail;
            finalPickModal.querySelector('#finalPickTitleDisplay').value = finalTitle;
            finalPickModal.querySelector('#finalPickTieDisplay').value = hasTie === '1' ? 'Yes' : 'No';

            const textarea = finalPickModal.querySelector('#finalPickMessageTextarea');
            const normalizeTitle = (value) => (value && value.trim() ? value : 'No additional scored title available');
            const formatScore = (value) => (value && value !== '0' ? `${value}/5` : 'n/a');
            const topOneLabel = `${normalizeTitle(topOneTitle)} (${formatScore(topOneScore)})`;
            const topTwoLabel = `${normalizeTitle(topTwoTitle)} (${formatScore(topTwoScore)})`;
            const topThreeLabel = `${normalizeTitle(topThreeTitle)} (${formatScore(topThreeScore)})`;
            const avgLabel = avgScore && avgScore !== '0' ? `${avgScore}/5` : 'n/a';
            const reviewLabel = reviewCount && reviewCount !== '0' ? reviewCount : '0';
            textarea.value = `Hi ${studentName}, based on the concept ranking board, the recommended title to pursue is "${finalTitle}". Final average score: ${avgLabel} from ${reviewLabel} review(s). Top-scoring titles: 1) ${topOneLabel}, 2) ${topTwoLabel}, 3) ${topThreeLabel}. Your title is recommended.`;
        };

        document.querySelectorAll('.final-pick-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const payload = {
                    studentId: button.getAttribute('data-student-id') || '',
                    studentName: button.getAttribute('data-student-name') || 'Student',
                    studentEmail: button.getAttribute('data-student-email') || 'Not available',
                    finalTitle: button.getAttribute('data-final-title') || 'Final title',
                    conceptId: button.getAttribute('data-concept-id') || '',
                    avgScore: button.getAttribute('data-avg-score') || '0',
                    reviewCount: button.getAttribute('data-review-count') || '0',
                    topOneTitle: button.getAttribute('data-top-one-title') || '',
                    topTwoTitle: button.getAttribute('data-top-two-title') || '',
                    topThreeTitle: button.getAttribute('data-top-three-title') || '',
                    topOneScore: button.getAttribute('data-top-one-score') || '0',
                    topTwoScore: button.getAttribute('data-top-two-score') || '0',
                    topThreeScore: button.getAttribute('data-top-three-score') || '0',
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
                avgScore: finalPickForm.dataset.currentAvgScore || '0',
                reviewCount: finalPickForm.dataset.currentReviewCount || '0',
                topOneTitle: finalPickForm.dataset.currentTopOneTitle || '',
                topTwoTitle: finalPickForm.dataset.currentTopTwoTitle || '',
                topThreeTitle: finalPickForm.dataset.currentTopThreeTitle || '',
                topOneScore: finalPickForm.dataset.currentTopOneScore || '0',
                topTwoScore: finalPickForm.dataset.currentTopTwoScore || '0',
                topThreeScore: finalPickForm.dataset.currentTopThreeScore || '0',
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




