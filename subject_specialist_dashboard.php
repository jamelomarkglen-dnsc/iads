<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';
require_once 'role_helpers.php';

$allowedRoles = ['faculty', 'panel', 'committee_chair', 'committee_chairperson', 'adviser'];
$sessionRole = $_SESSION['role'] ?? '';
$role = isset($forceAssignmentRole) && in_array($forceAssignmentRole, $allowedRoles, true)
    ? $forceAssignmentRole
    : $sessionRole;
$reviewerId = (int)($_SESSION['user_id'] ?? 0);

if (!$reviewerId || !in_array($role, $allowedRoles, true)) {
    enforce_role_access($allowedRoles);
}

ensureConceptReviewTables($conn);
ensureConceptReviewMessagesTable($conn);
// Keep mirrored concept paper rows aligned with live submissions so deleted submissions
// do not leave orphaned rankings behind on the dashboard.
syncConceptPapersFromSubmissions($conn);
$assignmentRoleKey = $role === 'committee_chairperson' ? 'committee_chair' : $role;
if ($role === 'adviser') {
    syncAdviserAssignmentsFromUserLinks($conn, $reviewerId);
}
$isAdviserView = ($role === 'adviser');
$permittedAssignmentRoles = getPermittedAssignmentRoles($role);

/**
 * Map the viewer's session role to all assignment roles they are allowed to act on.
 */
function getPermittedAssignmentRoles(string $role): array
{
    $roleMap = [
        'committee_chairperson' => ['committee_chair', 'committee_chairperson'],
        'faculty' => ['faculty', 'panel'],
    ];
    if (isset($roleMap[$role])) {
        return $roleMap[$role];
    }
    return [$role];
}

/**
 * Determine the reviewer_role value that should be saved with a review/ranking.
 */
function resolveAssignmentRole(string $sessionRole, ?string $assignmentRole): string
{
    if ($assignmentRole !== null && $assignmentRole !== '') {
        return $assignmentRole;
    }
    if ($sessionRole === 'committee_chairperson') {
        return 'committee_chair';
    }
    return $sessionRole;
}

function formatReadableDate(?string $date): string
{
    if (!$date) {
        return 'Not set';
    }
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format('M d, Y');
    } catch (Exception $e) {
        return $date;
    }
}

function formatReadableDateTime(?string $dateTime): string
{
    if (!$dateTime) {
        return 'Not recorded';
    }
    try {
        $dt = new DateTimeImmutable($dateTime);
        return $dt->format('M d, Y g:i A');
    } catch (Exception $e) {
        return $dateTime;
    }
}

$feedback = ['type' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save_reviews'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $reviewsInput = is_array($_POST['reviews'] ?? null) ? $_POST['reviews'] : [];
    if ($studentId <= 0) {
        $feedback = ['type' => 'warning', 'message' => 'Please select a student before saving reviews.'];
    } else {
        $assignmentStmt = $conn->prepare("
            SELECT
                cra.id,
                cra.concept_paper_id,
                cra.student_id,
                cra.status,
                cra.reviewer_role,
                COALESCE(cr.score, 0) AS review_score,
                COALESCE(cr.recommendation, '') AS review_recommendation,
                COALESCE(cr.rank_order, NULL) AS review_rank_order,
                COALESCE(cr.is_preferred, 0) AS review_is_preferred,
                COALESCE(cr.notes, '') AS review_notes,
                COALESCE(cr.comment_suggestions, '') AS review_comments,
                COALESCE(cr.adviser_interest, 0) AS review_adviser_interest
            FROM concept_reviewer_assignments cra
            LEFT JOIN concept_reviews cr ON cr.assignment_id = cra.id
            WHERE cra.reviewer_id = ? AND cra.student_id = ?
            ORDER BY cra.id ASC
        ");
        $assignmentsForStudent = [];
        if ($assignmentStmt) {
            $assignmentStmt->bind_param('ii', $reviewerId, $studentId);
            $assignmentStmt->execute();
            $result = $assignmentStmt->get_result();
            $assignmentsForStudent = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $assignmentStmt->close();
        }
        if (!empty($assignmentsForStudent)) {
            $assignmentsForStudent = array_values(array_filter(
                $assignmentsForStudent,
                static function ($row) use ($permittedAssignmentRoles) {
                    return in_array(($row['reviewer_role'] ?? ''), $permittedAssignmentRoles, true);
                }
            ));
        }

        if (empty($assignmentsForStudent)) {
            $feedback = ['type' => 'warning', 'message' => 'No assigned title cards were found for this student.'];
        } else {
            $rowsToSave = [];
            $usedScores = [];
            $duplicateScore = null;
            $missingScore = false;
            $missingRecommendation = false;
            $missingComments = false;
            $interestSelections = 0;

            foreach ($assignmentsForStudent as $row) {
                $assignmentId = (int)($row['id'] ?? 0);
                if ($assignmentId <= 0) {
                    continue;
                }
                $input = is_array($reviewsInput[$assignmentId] ?? null) ? $reviewsInput[$assignmentId] : [];
                $score = (int)($input['score'] ?? 0);
                $recommendation = trim((string)($input['recommendation'] ?? ''));
                $commentSuggestions = trim((string)($input['comment_suggestions'] ?? ''));
                $notes = trim((string)($input['notes'] ?? ''));
                if ($commentSuggestions === '' && $notes !== '') {
                    $commentSuggestions = $notes;
                }
                if ($notes === '' && $commentSuggestions !== '') {
                    $notes = $commentSuggestions;
                }
                $adviserInterest = !empty($input['adviser_interest']) ? 1 : 0;

                if ($score < 1 || $score > 5) {
                    $missingScore = true;
                    break;
                }
                if (isset($usedScores[$score])) {
                    $duplicateScore = $score;
                    break;
                }
                if ($recommendation === '') {
                    $missingRecommendation = true;
                    break;
                }
                if ($commentSuggestions === '') {
                    $missingComments = true;
                    break;
                }

                $usedScores[$score] = $assignmentId;
                $interestSelections += $adviserInterest;
                $rowsToSave[] = [
                    'assignment_id' => $assignmentId,
                    'concept_paper_id' => (int)($row['concept_paper_id'] ?? 0),
                    'reviewer_role' => resolveAssignmentRole($role, $row['reviewer_role'] ?? null),
                    'score' => $score,
                    'recommendation' => $recommendation,
                    'notes' => $notes,
                    'comment_suggestions' => $commentSuggestions,
                    'adviser_interest' => $adviserInterest,
                ];
            }

            if ($missingScore) {
                $feedback = ['type' => 'warning', 'message' => 'Please choose a rating for every title before saving.'];
            } elseif ($duplicateScore !== null) {
                $feedback = [
                    'type' => 'warning',
                    'message' => sprintf('Rating %d can only be used once per student. Pick a different rating for the other title.', $duplicateScore),
                ];
            } elseif ($missingRecommendation) {
                $feedback = ['type' => 'warning', 'message' => 'Please choose a recommendation for every title before saving.'];
            } elseif ($missingComments) {
                $feedback = ['type' => 'warning', 'message' => 'Please provide comments and suggestions for every title before saving.'];
            } elseif ($interestSelections > 1) {
                $feedback = ['type' => 'warning', 'message' => 'Only one title per student can be marked for mentoring interest.'];
            } elseif (count($rowsToSave) !== count($assignmentsForStudent)) {
                $feedback = ['type' => 'warning', 'message' => 'Some assigned titles were not included in the save request. Please refresh and try again.'];
            } else {
                usort($rowsToSave, static function (array $left, array $right): int {
                    if ($left['score'] === $right['score']) {
                        return $left['assignment_id'] <=> $right['assignment_id'];
                    }
                    return $right['score'] <=> $left['score'];
                });

                $reviewStmt = $conn->prepare("
                    INSERT INTO concept_reviews (assignment_id, concept_paper_id, reviewer_id, reviewer_role, score, recommendation, rank_order, is_preferred, notes, comment_suggestions, adviser_interest)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        score = VALUES(score),
                        recommendation = VALUES(recommendation),
                        rank_order = VALUES(rank_order),
                        is_preferred = VALUES(is_preferred),
                        notes = VALUES(notes),
                        comment_suggestions = VALUES(comment_suggestions),
                        adviser_interest = VALUES(adviser_interest),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $statusStmt = $conn->prepare("
                    UPDATE concept_reviewer_assignments
                    SET status = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND reviewer_id = ?
                ");
                if ($reviewStmt && $statusStmt) {
                    $conn->begin_transaction();
                    $saved = true;
                    foreach ($rowsToSave as $index => $row) {
                        $rankOrder = $index + 1;
                        $isPreferred = $rankOrder === 1 ? 1 : 0;
                        $assignmentId = (int)$row['assignment_id'];
                        $conceptId = (int)$row['concept_paper_id'];
                        $reviewerRole = (string)$row['reviewer_role'];
                        $scoreValue = (int)$row['score'];
                        $recommendationValue = (string)$row['recommendation'];
                        $notesValue = (string)$row['notes'];
                        $commentsValue = (string)$row['comment_suggestions'];
                        $interestValue = (int)$row['adviser_interest'];

                        $reviewStmt->bind_param(
                            'iiisisiissi',
                            $assignmentId,
                            $conceptId,
                            $reviewerId,
                            $reviewerRole,
                            $scoreValue,
                            $recommendationValue,
                            $rankOrder,
                            $isPreferred,
                            $notesValue,
                            $commentsValue,
                            $interestValue
                        );
                        if (!$reviewStmt->execute()) {
                            $saved = false;
                            break;
                        }

                        $status = 'completed';
                        $statusStmt->bind_param('sii', $status, $assignmentId, $reviewerId);
                        if (!$statusStmt->execute()) {
                            $saved = false;
                            break;
                        }
                    }

                    if ($saved) {
                        $conn->commit();
                        $feedback = ['type' => 'success', 'message' => 'All title reviews were saved successfully.'];
                    } else {
                        $conn->rollback();
                        $feedback = ['type' => 'danger', 'message' => 'Unable to save all title reviews at this time.'];
                    }
                    $reviewStmt->close();
                    $statusStmt->close();
                } else {
                    $feedback = ['type' => 'danger', 'message' => 'Unable to prepare title review statements.'];
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_review'])) {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $conceptId = (int)($_POST['concept_id'] ?? 0);
    $score = (int)($_POST['score'] ?? 0);
    $recommendation = trim($_POST['recommendation'] ?? '');
    $rankOrderInput = trim((string)($_POST['rank_order'] ?? ''));
    $rankOrder = $rankOrderInput === '' ? null : (int)$rankOrderInput;
    if ($rankOrder !== null && ($rankOrder < 1 || $rankOrder > 3)) {
        $rankOrder = null;
    }
    $isPreferred = isset($_POST['is_preferred']) ? 1 : 0;
    if ($rankOrder === 1) {
        $isPreferred = 1;
    } elseif ($rankOrder !== null && $rankOrder > 1) {
        $isPreferred = 0;
    } elseif ($rankOrder === null && $isPreferred === 1) {
        // Treat a preferred selection as the top rank so the Program Chair can see it.
        $rankOrder = 1;
    }
    $commentSuggestions = trim($_POST['comment_suggestions'] ?? '');
    $notesInput = trim($_POST['notes'] ?? '');
    if ($commentSuggestions === '' && $notesInput !== '') {
        $commentSuggestions = $notesInput;
    }
    $notes = $commentSuggestions !== '' ? $commentSuggestions : $notesInput;
    $adviserInterest = isset($_POST['adviser_interest']) ? 1 : 0;

    $checkStmt = $conn->prepare("
        SELECT concept_paper_id, reviewer_id, reviewer_role, student_id
        FROM concept_reviewer_assignments
        WHERE id = ?
        LIMIT 1
    ");
    if ($checkStmt) {
        $checkStmt->bind_param('i', $assignmentId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $assignmentRow = $result ? $result->fetch_assoc() : null;
        $checkStmt->close();
    }

    if (
        !$assignmentRow ||
        (int)($assignmentRow['concept_paper_id'] ?? 0) !== $conceptId ||
        (int)($assignmentRow['reviewer_id'] ?? 0) !== $reviewerId ||
        !in_array(($assignmentRow['reviewer_role'] ?? ''), $permittedAssignmentRoles, true)
    ) {
        $feedback = ['type' => 'danger', 'message' => 'Invalid assignment reference.'];
    } else {
        $assignmentReviewRole = resolveAssignmentRole($role, $assignmentRow['reviewer_role'] ?? null);
        $studentIdForRank = (int)($assignmentRow['student_id'] ?? 0);
        $shouldCheckInterestLimit = false;
        if ($adviserInterest === 1 && $studentIdForRank > 0) {
            $studentInterestStmt = $conn->prepare("
                SELECT 1
                FROM concept_reviews cr
                JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
                WHERE cr.reviewer_id = ? AND cr.adviser_interest = 1 AND cra.student_id = ?
                LIMIT 1
            ");
            $hasInterestForStudent = false;
            if ($studentInterestStmt) {
                $studentInterestStmt->bind_param('ii', $reviewerId, $studentIdForRank);
                $studentInterestStmt->execute();
                $studentInterestStmt->store_result();
                $hasInterestForStudent = $studentInterestStmt->num_rows > 0;
                $studentInterestStmt->close();
            }
            $shouldCheckInterestLimit = !$hasInterestForStudent;
        }
        if ($isPreferred === 1 && $studentIdForRank > 0) {
            $clearStmt = $conn->prepare("
                UPDATE concept_reviews cr
                JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
                SET cr.is_preferred = 0,
                    cr.rank_order = CASE WHEN cr.rank_order = 1 THEN NULL ELSE cr.rank_order END,
                    cr.updated_at = CURRENT_TIMESTAMP
                WHERE cr.reviewer_id = ? AND cra.student_id = ? AND cr.assignment_id <> ?
                  AND (cr.is_preferred = 1 OR cr.rank_order = 1)
            ");
            if ($clearStmt) {
                $clearStmt->bind_param('iii', $reviewerId, $studentIdForRank, $assignmentId);
                $clearStmt->execute();
                $clearStmt->close();
            }
        }
        $duplicateRank = false;
        if ($rankOrder !== null && $studentIdForRank > 0) {
            $dupeStmt = $conn->prepare("
                SELECT cr.assignment_id
                FROM concept_reviews cr
                JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
                WHERE cr.reviewer_id = ? AND cra.student_id = ? AND cr.rank_order = ? AND cr.assignment_id <> ?
                LIMIT 1
            ");
            if ($dupeStmt) {
                $dupeStmt->bind_param('iiii', $reviewerId, $studentIdForRank, $rankOrder, $assignmentId);
                $dupeStmt->execute();
                $dupeStmt->store_result();
                if ($dupeStmt->num_rows > 0) {
                    $duplicateRank = true;
                    $feedback = [
                        'type' => 'warning',
                        'message' => sprintf('Rank %d is already used for another title for this student. Update that review first or pick a different rank.', $rankOrder),
                    ];
                }
                $dupeStmt->close();
            }
        }

        if (!$duplicateRank && $shouldCheckInterestLimit) {
            $limitCount = 0;
            $countStmt = $conn->prepare("
                SELECT COUNT(DISTINCT cra.student_id) AS total
                FROM concept_reviews cr
                JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
                WHERE cr.reviewer_id = ? AND cr.adviser_interest = 1
            ");
            if ($countStmt) {
                $countStmt->bind_param('i', $reviewerId);
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                $countRow = $countResult ? $countResult->fetch_assoc() : null;
                $limitCount = (int)($countRow['total'] ?? 0);
                $countStmt->close();
            }
            if ($limitCount >= 3) {
                $feedback = [
                    'type' => 'warning',
                    'message' => 'You can only mark interest in mentoring up to 3 students. Uncheck another interest before selecting a new one.',
                ];
            }
        }

        if (!$duplicateRank && $feedback['message'] === '' && $adviserInterest === 1 && $studentIdForRank > 0) {
            $clearInterestStmt = $conn->prepare("
                UPDATE concept_reviews cr
                JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
                SET cr.adviser_interest = 0,
                    cr.updated_at = CURRENT_TIMESTAMP
                WHERE cr.reviewer_id = ? AND cra.student_id = ? AND cr.assignment_id <> ?
            ");
            if ($clearInterestStmt) {
                $clearInterestStmt->bind_param('iii', $reviewerId, $studentIdForRank, $assignmentId);
                $clearInterestStmt->execute();
                $clearInterestStmt->close();
            }
        }

        if (!$duplicateRank && $feedback['message'] === '' && $commentSuggestions === '') {
            $feedback = ['type' => 'warning', 'message' => 'Please provide your comments and suggestions before saving.'];
        } elseif (!$duplicateRank && $feedback['message'] === '') {
            $reviewStmt = $conn->prepare("
                INSERT INTO concept_reviews (assignment_id, concept_paper_id, reviewer_id, reviewer_role, score, recommendation, rank_order, is_preferred, notes, comment_suggestions, adviser_interest)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    score = VALUES(score),
                    recommendation = VALUES(recommendation),
                    rank_order = VALUES(rank_order),
                    is_preferred = VALUES(is_preferred),
                    notes = VALUES(notes),
                    comment_suggestions = VALUES(comment_suggestions),
                    adviser_interest = VALUES(adviser_interest),
                    updated_at = CURRENT_TIMESTAMP
            ");
            if ($reviewStmt) {
                $reviewStmt->bind_param(
                    'iiisisiissi',
                    $assignmentId,
                    $conceptId,
                    $reviewerId,
                    $assignmentReviewRole,
                    $score,
                    $recommendation,
                    $rankOrder,
                    $isPreferred,
                    $notes,
                    $commentSuggestions,
                    $adviserInterest
                );
                if ($reviewStmt->execute()) {
                    $isCompleted = ($recommendation !== '' && $score > 0 && $rankOrder !== null && $commentSuggestions !== '');
                    $status = $isCompleted ? 'completed' : 'in_progress';
                    $statusStmt = $conn->prepare("
                        UPDATE concept_reviewer_assignments
                        SET status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND reviewer_id = ?
                    ");
                    if ($statusStmt) {
                        $statusStmt->bind_param('sii', $status, $assignmentId, $reviewerId);
                        $statusStmt->execute();
                        $statusStmt->close();
                    }
                    $feedback = ['type' => 'success', 'message' => 'Review saved successfully.'];
                } else {
                    $feedback = ['type' => 'danger', 'message' => 'Unable to save review at this time.'];
                }
                $reviewStmt->close();
            } else {
                $feedback = ['type' => 'danger', 'message' => 'Unable to prepare review statement.'];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_conversation'])) {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $conceptId = (int)($_POST['concept_id'] ?? 0);
    $messageText = trim((string)($_POST['conversation_message'] ?? ''));

    if ($assignmentId <= 0 || $conceptId <= 0) {
        $feedback = ['type' => 'danger', 'message' => 'Missing assignment details for the conversation.'];
    } elseif ($messageText === '') {
        $feedback = ['type' => 'warning', 'message' => 'Please enter a message before sending.'];
    } else {
        $assignmentStmt = $conn->prepare("
            SELECT concept_paper_id, reviewer_id, reviewer_role, student_id
            FROM concept_reviewer_assignments
            WHERE id = ?
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

        if (
            !$assignmentRow ||
            (int)($assignmentRow['concept_paper_id'] ?? 0) !== $conceptId ||
            (int)($assignmentRow['reviewer_id'] ?? 0) !== $reviewerId ||
            !in_array(($assignmentRow['reviewer_role'] ?? ''), $permittedAssignmentRoles, true)
        ) {
            $feedback = ['type' => 'danger', 'message' => 'You cannot post to this conversation right now.'];
        } else {
            $saved = saveConceptReviewMessage($conn, [
                'assignment_id' => $assignmentId,
                'concept_paper_id' => $conceptId,
                'student_id' => (int)($assignmentRow['student_id'] ?? 0),
                'sender_id' => $reviewerId,
                'sender_role' => $role,
                'message' => $messageText,
            ]);
            if ($saved) {
                $feedback = ['type' => 'success', 'message' => 'Message sent to the Program Chairperson.'];
            } else {
                $feedback = ['type' => 'danger', 'message' => 'Unable to send your message at the moment.'];
            }
        }
    }
}

$roleFilter = ($role === 'committee_chairperson' || $role === 'faculty') ? null : $assignmentRoleKey;
$assignments = fetchReviewerAssignments($conn, $reviewerId, $roleFilter);
$assignments = array_values(array_filter(
    $assignments,
    static function ($item) use ($permittedAssignmentRoles) {
        return in_array(($item['reviewer_role'] ?? ''), $permittedAssignmentRoles, true);
    }
));
$assignmentStats = summarizeReviewerAssignments($assignments);
$rankingSnapshot = summarizeReviewerRankingProgress($assignments);
$dueSoonAssignments = filterDueSoonReviewerAssignments($assignments);
$groupedAssignments = groupReviewerAssignmentsByStudent($assignments);
$adviserConceptPreview = [];
if ($role === 'adviser' && empty($groupedAssignments)) {
    $adviserConceptPreview = fetchAdviserConceptPreview($conn, $reviewerId, 1, 3);
}
$remainingReviewerFeedback = fetchRemainingReviewerFeedback($conn, 5, $reviewerId);

$totalAssignments = $assignmentStats['total'];
$completedAssignments = $assignmentStats['completed'];
$pendingAssignmentsCount = $assignmentStats['pending'];
$dueSoonAssignmentsCount = $assignmentStats['due_soon'];
$progressPercent = $totalAssignments > 0 ? (int)round(($completedAssignments / $totalAssignments) * 100) : 0;

$reviewLookup = [];
$conversationLookup = [];
if (!empty($assignments)) {
    $assignmentIds = array_column($assignments, 'assignment_id');
    $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
    $types = str_repeat('i', count($assignmentIds));
    $reviewSql = "
        SELECT assignment_id, concept_paper_id, score, recommendation, rank_order, is_preferred, notes, comment_suggestions, adviser_interest, chair_feedback, chair_feedback_at, chair_feedback_by, updated_at
        FROM concept_reviews
        WHERE assignment_id IN ($placeholders) AND reviewer_id = ?
    ";
    $reviewStmt = $conn->prepare($reviewSql);
    if ($reviewStmt) {
        $params = array_merge($assignmentIds, [$reviewerId]);
        $reviewStmt->bind_param($types . 'i', ...$params);
        $reviewStmt->execute();
        $reviewRes = $reviewStmt->get_result();
        if ($reviewRes) {
            while ($row = $reviewRes->fetch_assoc()) {
                $reviewLookup[$row['assignment_id']] = $row;
            }
            $reviewRes->free();
        }
        $reviewStmt->close();
    }
    $conversationLookup = fetchConceptReviewMessagesByAssignments($conn, $assignmentIds);
} else {
    $conversationLookup = [];
}

foreach ($groupedAssignments as $studentId => &$group) {
    foreach ($group['items'] as &$item) {
        $assignmentId = (int)($item['assignment_id'] ?? 0);
        $reviewData = $reviewLookup[$assignmentId] ?? [
            'score' => null,
            'recommendation' => '',
            'rank_order' => null,
            'is_preferred' => 0,
            'notes' => '',
            'comment_suggestions' => '',
            'adviser_interest' => 0,
            'chair_feedback' => '',
            'chair_feedback_at' => null,
            'chair_feedback_by' => null,
        ];
        if (($reviewData['comment_suggestions'] ?? '') === '' && ($reviewData['notes'] ?? '') !== '') {
            $reviewData['comment_suggestions'] = $reviewData['notes'];
        }
        $item['review'] = $reviewData;
        $item['messages'] = $conversationLookup[$assignmentId] ?? [];
    }
    unset($item);
}
unset($group);

$roleTitles = [
    'faculty' => 'Subject Specialist Reviewer',
    'panel' => 'Panel Member Reviewer',
    'committee_chair' => 'Committee Chair Reviewer',
    'committee_chairperson' => 'Committee Chair Reviewer',
    'adviser' => 'Adviser Reviewer',
];
$roleTitle = $overrideRoleTitle ?? ($roleTitles[$role] ?? 'Reviewer');
$heroDescription = $overrideHeroDescription ?? 'Review the title options assigned by the Program Chairperson, rate each title option, and recommend the most viable title for the student to pursue.';
$bodyClass = $isAdviserView ? 'adviser-view' : '';
$heroLabelClass = 'text-uppercase small text-muted mb-1';
$heroDescClass = 'text-muted mb-0';
$heroBadgeClass = 'badge bg-success-subtle text-success fs-6';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($roleTitle); ?> Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
    <style>
        body { background: #f5f7fb; }
        .content { margin-left: 220px; padding: 24px; transition: margin-left .3s ease; }
        #sidebar.collapsed ~ .content { margin-left: 70px; }
        .card-rounded { border-radius: 18px; border: none; box-shadow: 0 12px 32px rgba(15, 61, 31, 0.1); }
        .stat-card { border: none; border-radius: 18px; box-shadow: 0 16px 32px rgba(22, 86, 44, 0.08); }
        .stat-card .icon-pill { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .assignment-card { border-radius: 20px; border: none; box-shadow: 0 14px 36px rgba(15,61,31,.08); }
        .assignment-card .badge { font-size: 0.75rem; }
        .concept-mini { border: 1px solid rgba(22,86,44,.08); border-radius: 16px; padding: 1rem; background: #fff; box-shadow: 0 10px 28px rgba(22,86,44,.08); }
        .concept-mini .alert { border: 1px solid rgba(22,86,44,.18); background: #f6fff8; }
        .concept-title {
            display: block;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .concept-header { min-width: 0; }
        .concept-title-row { width: 100%; }
        .concept-actions { display: flex; align-items: center; gap: 0.5rem; }
        .title-tooltip .tooltip-inner {
            background: #ffffff;
            color: #1f3b2b;
            border: 1px solid rgba(31, 59, 43, 0.12);
            box-shadow: 0 10px 24px rgba(31, 59, 43, 0.18);
            font-weight: 600;
            padding: 0.45rem 0.7rem;
            border-radius: 0.6rem;
            max-width: 320px;
        }
        .title-tooltip.bs-tooltip-top .tooltip-arrow::before {
            border-top-color: #ffffff;
        }
        .preview-frame { width: 100%; height: 70vh; border: 0; }
        @media (max-width: 768px) { .preview-frame { height: 60vh; } }
        .rank-buttons .btn { font-size: 0.85rem; }
        .rank-buttons .btn-check:checked + .btn { color: #fff; background-color: #198754; border-color: #198754; }
        .rank-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 61, 31, 0.14);
            padding: 1.25rem;
            background: transparent;
            color: #1f3b2b;
            box-shadow: none;
        }
        .rank-card label { color: inherit; }
        .rank-card .btn { border-radius: 999px; }
        .rank-card .table > :not(caption) > * > * { background: transparent; color: inherit; border-color: rgba(15, 61, 31, 0.12); }
        .rank-card .text-white-50 { color: #6c757d !important; }
        .rank-table th,
        .rank-table td { padding: 0.85rem; vertical-align: middle; }
        .rank-table thead th { font-size: 0.78rem; letter-spacing: 0.08em; text-transform: uppercase; border-bottom-width: 1px; }
        .rank-table .form-check { display: inline-flex; align-items: center; justify-content: center; gap: 0.25rem; }
        .rank-table .rank-radio {
            width: 1.4rem;
            height: 1.4rem;
            border-width: 2px;
            cursor: pointer;
            border-radius: 50%;
            appearance: none;
            border: 2px solid rgba(255,255,255,0.65);
            background: transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }
        .rank-table .rank-radio::after {
            content: '';
            width: 0.55rem;
            height: 0.55rem;
            background: #ffc107;
            border-radius: 50%;
            transform: scale(0);
            transition: transform 0.2s ease;
        }
        .rank-table .rank-radio:checked {
            border-color: #ffc107;
            background: rgba(255,193,7,0.12);
        }
        .rank-table .rank-radio:checked::after {
            transform: scale(1);
        }
        .rank-table .rank-radio:focus-visible {
            outline: none;
            box-shadow: 0 0 0 0.25rem rgba(255,193,7,0.25);
        }
        .rank-table .rank-radio:disabled {
            opacity: 0.25;
            cursor: not-allowed;
        }
        .rank-table .clear-rank-btn {
            border-radius: 999px;
            font-size: 0.85rem;
            color: #fff;
            border-color: rgba(255,255,255,0.4);
            padding-inline: 1rem;
        }
        .rank-table .clear-rank-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .rank-indicator {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border-radius: 999px;
            padding: 0.3rem 0.9rem;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.8);
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        .rank-indicator.active {
            background: rgba(255,193,7,0.18);
            color: #ffc107;
            border-color: rgba(255,193,7,0.4);
        }
        .rating-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.45rem;
        }
        .rating-list .rating-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin: 0;
        }
        .rating-list .form-check-label { margin: 0; }
        .review-form select, .review-form textarea { font-size: 0.95rem; }
        .review-form textarea { resize: vertical; }
        .empty-state { text-align: center; padding: 2rem; color: #6c757d; }
        .deadline-list li { border-bottom: 1px solid rgba(0,0,0,0.05); padding: 0.65rem 0; }
        .deadline-list li:last-child { border-bottom: 0; }
        @media (max-width: 992px) {
            .content { margin-left: 0; }
        }
        .adviser-view body, .adviser-view {
            background: #f5f7fb;
            color: #212529;
        }
        body.adviser-view { color: #212529; }
        .adviser-view .content {
            margin-left: 220px;
            padding: 32px 32px 48px;
        }
        .adviser-hero {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 24px;
            box-shadow: 0 10px 30px rgba(15, 61, 31, 0.08);
        }
        .adviser-view .stat-card {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.05);
            color: #212529;
        }
        .adviser-view .assignment-card {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 18px 40px rgba(15,61,31,0.08);
        }
        .adviser-view .assignment-card h4,
        .adviser-view .assignment-card small {
            color: #343a40;
        }
        .adviser-view .concept-mini {
            background: #fdfefe;
            border: 1px solid rgba(0,0,0,0.05);
            color: #343a40;
        }
        .adviser-view .concept-mini h5 { color: #1f3b2b; }
        .adviser-view .rank-card {
            background: #ffffff;
            border: 1px solid rgba(0,0,0,0.07);
            box-shadow: 0 12px 32px rgba(15,61,31,0.1);
            color: #212529;
        }
        .adviser-view .rank-card .table > :not(caption) > * > * {
            color: inherit;
            border-color: rgba(0,0,0,0.08);
        }
        .adviser-view .empty-state {
            color: #6c757d;
        }
        .adviser-view .card-rounded,
        .adviser-view .card {
            border-radius: 20px;
            border: 1px solid rgba(0,0,0,0.05);
        }
        .adviser-view .deadline-list li {
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .adviser-view .card-rounded {
            background: #fff;
            color: #212529;
        }
        .adviser-view .card-rounded .badge {
            background: rgba(12,123,53,0.1);
            color: #0b5d1e;
        }
        .adviser-view .btn-warning {
            background: #0f7a36;
            border: none;
            color: #fff;
            font-weight: 600;
        }
        .adviser-view .rank-card label { color: #1f3b2b; }
        .adviser-view .rank-card p,
        .adviser-view .rank-card small {
            color: #4b5b54;
        }
        .adviser-view .rank-table .rank-radio {
            border-color: rgba(0,0,0,0.3);
        }
        .adviser-view .rank-table .rank-radio::after {
            background: #0f7a36;
        }
        .adviser-view .rank-table .rank-radio:checked {
            border-color: #0f7a36;
            background: rgba(15,122,54,0.15);
        }
        .adviser-view .rank-table .rank-radio:focus-visible {
            box-shadow: 0 0 0 0.2rem rgba(15,122,54,0.25);
        }
        .adviser-view .rank-indicator {
            background: rgba(0,0,0,0.05);
            border-color: rgba(0,0,0,0.08);
            color: #495057;
        }
        .adviser-view .rank-indicator.active {
            background: rgba(15,122,54,0.1);
            border-color: rgba(15,122,54,0.25);
            color: #0f7a36;
        }
        .adviser-view .rank-table .clear-rank-btn {
            border-color: rgba(15,61,31,0.25);
            color: #1f3b2b;
        }
        .adviser-view .review-form select,
        .adviser-view .review-form textarea {
            background: #fff;
            border-color: rgba(0,0,0,0.15);
            color: #212529;
        }
        .adviser-view .review-form option {
            color: #212529;
        }
        .kpi-card {
            background: rgba(255,255,255,0.9);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            box-shadow: 0 8px 24px rgba(15,61,31,0.08);
        }
        .kpi-card .kpi-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6c757d;
        }
        .kpi-card .kpi-value {
            margin: 0.15rem 0;
            font-weight: 700;
        }
        .progress-thin {
            height: 6px;
            border-radius: 999px;
            background: rgba(15,61,31,0.08);
        }
        .progress-thin .progress-bar {
            border-radius: 999px;
        }
        .quick-actions-card .list-group-item {
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1rem;
            margin-bottom: 0.35rem;
            background: rgba(15,61,31,0.03);
            font-weight: 500;
        }
        .quick-actions-card .list-group-item:hover {
            background: rgba(15,61,31,0.08);
            color: #0f3d1f;
        }
        .conversation-card {
            border-radius: 16px;
            border: 1px solid rgba(15,61,31,0.08);
            padding: 1rem;
            background: #fff;
        }
        .conversation-thread {
            max-height: 220px;
            overflow-y: auto;
            margin-bottom: 1rem;
            padding-right: 0.35rem;
        }
        .conversation-empty {
            text-align: center;
            color: #adb5bd;
            font-size: 0.9rem;
            padding: 0.75rem 0;
        }
        .conversation-bubble {
            border-radius: 12px;
            padding: 0.65rem 0.85rem;
            background: rgba(15,61,31,0.05);
            margin-bottom: 0.75rem;
        }
        .conversation-bubble.self {
            background: rgba(25,135,84,0.15);
            border: 1px solid rgba(25,135,84,0.2);
        }
        .conversation-meta {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6c757d;
        }
        .conversation-form textarea {
            resize: none;
            border-radius: 12px;
        }
        .feedback-insight {
            border-left: 3px solid #0f7a36;
            padding-left: 0.75rem;
        }
        body.adviser-view .content { margin-left: 220px; }
    </style>
</head>
<body class="<?= $bodyClass; ?>">
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid">
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="<?= $isAdviserView ? 'card adviser-hero h-100' : 'card card-rounded h-100'; ?>">
                    <div class="card-body">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                            <div>
                                <p class="<?= $heroLabelClass; ?>">Reviewer Workbench</p>
                                <h2 class="fw-bold mb-2"><?= htmlspecialchars($roleTitle); ?></h2>
                                <p class="<?= $heroDescClass; ?> mb-0"><?= htmlspecialchars($heroDescription); ?></p>
                            </div>
                            <div class="text-lg-end">
                                <span class="<?= $heroBadgeClass; ?>"><?= number_format($totalAssignments); ?> assigned titles</span>
                                <div class="text-muted small mt-2">Progress <?= $progressPercent; ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($feedback['message']): ?>
            <div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($feedback['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <div class="alert alert-warning py-2 px-3 small d-none" role="alert" data-mentoring-limit-banner>
            Mentoring limit reached: 3/3 students selected. Uncheck a student to select another.
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="icon-pill bg-primary-subtle text-primary mb-3">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <p class="text-muted mb-1">Active Assignments</p>
                        <h3 class="fw-bold mb-0"><?= number_format($totalAssignments); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="icon-pill bg-warning-subtle text-warning mb-3">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <p class="text-muted mb-1">In Progress</p>
                        <h3 class="fw-bold text-warning mb-0"><?= number_format($pendingAssignmentsCount); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="icon-pill bg-success-subtle text-success mb-3">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <p class="text-muted mb-1">Completed</p>
                        <h3 class="fw-bold text-success mb-0"><?= number_format($completedAssignments); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="icon-pill bg-danger-subtle text-danger mb-3">
                            <i class="bi bi-calendar-event"></i>
                        </div>
                        <p class="text-muted mb-1">Due in 7 Days</p>
                        <h3 class="fw-bold text-danger mb-0"><?= number_format($dueSoonAssignmentsCount); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-16">
                <?php if (empty($groupedAssignments)): ?>
                    <div class="card assignment-card">
                        <div class="card-body">
                            <div class="empty-state">
                                <i class="bi bi-emoji-smile fs-1 d-block mb-2"></i>
                                No title options are assigned to you yet.
                            </div>
                            <?php if ($role === 'adviser' && !empty($adviserConceptPreview)): ?>
                                <?php foreach ($adviserConceptPreview as $preview): ?>
                                    <?php $rankPlaceholderTextClass = $isAdviserView ? 'text-muted' : 'text-white-50'; ?>
                                    <div class="rank-card mt-3">
                                        <p class="text-uppercase small <?= $rankPlaceholderTextClass; ?> mb-1">Advisee title set</p>
                                        <h5 class="mb-2"><?= htmlspecialchars($preview['student_name']); ?></h5>
                                        <small class="<?= $rankPlaceholderTextClass; ?>">These titles were created for this advisee in assign_faculty_replacement.php. Ranking activates automatically once the Program Chairperson routes the student to you.</small>
                                        <div class="table-responsive mt-3">
                                            <table class="table table-borderless align-middle rank-table mb-0">
                                                <thead>
                                                    <tr>
                                                        <th scope="col">Title Option</th>
                                                        <th scope="col" class="text-center">Rank 1</th>
                                                        <th scope="col" class="text-center">Rank 2</th>
                                                        <th scope="col" class="text-center">Rank 3</th>
                                                        <th scope="col" class="text-center">Clear</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($preview['concepts'] as $index => $concept): ?>
                                                        <tr>
                                                            <td>
                                                                    <strong><?= $concept['has_title'] ? htmlspecialchars($concept['title']) : 'Title Option ' . ($index + 1); ?></strong>
                                                                <div class="small <?= $rankPlaceholderTextClass; ?>">
                                                                    <?php if ($concept['has_title']): ?>
                                                                        Submitted <?= htmlspecialchars($concept['created_at'] ? formatReadableDate($concept['created_at']) : 'recently'); ?>
                                                                    <?php else: ?>
                                                                        Awaiting assignment
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                            <?php for ($rank = 1; $rank <= 3; $rank++): ?>
                                                                <td class="text-center">
                                                                    <div class="form-check form-check-inline align-middle <?= $rankPlaceholderTextClass; ?>">
                                                                        <input class="form-check-input rank-radio" type="radio" disabled>
                                                                        <label class="form-check-label"><?= $rank; ?></label>
                                                                    </div>
                                                                </td>
                                                            <?php endfor; ?>
                                                            <td class="text-center"><span class="small <?= $rankPlaceholderTextClass; ?>">Clear</span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <small class="<?= $rankPlaceholderTextClass; ?>">Once the Program Chairperson routes this student to you, the ranking controls become active.</small>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif (in_array($role, ['adviser', 'panel', 'committee_chair', 'committee_chairperson', 'faculty'], true)): ?>
                                <div class="row g-3">
                                    <div class="col-lg-8">
                                        <?php $rankPlaceholderTextClass = $isAdviserView ? 'text-muted' : 'text-white-50'; ?>
                                        <div class="card card-rounded mt-3 h-100">
                                            <div class="card-body">
                                                <form method="POST" data-rank-form>
                                                    <input type="hidden" name="bulk_rank_update" value="1">
                                                    <input type="hidden" name="student_id" value="<?= (int)$student['student_id']; ?>">
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <p class="card-title mb-0 fw-semibold text-uppercase small">Ranking Preview</p>
                                                        <button type="submit" class="btn btn-warning text-dark btn-sm">
                                                            <i class="bi bi-save me-1"></i> Save Ranking
                                                        </button>
                                                    </div>
                                                    <div class="table-responsive">
                                                        <table class="table table-hover align-middle rank-table mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th scope="col" class="w-50">Title Option</th>
                                                                    <th scope="col" class="text-center w-10">Rank 1</th>
                                                                    <th scope="col" class="text-center w-10">Rank 2</th>
                                                                    <th scope="col" class="text-center w-10">Rank 3</th>
                                                                    <th scope="col" class="text-center w-10">Clear</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php for ($slot = 1; $slot <= 3; $slot++): ?>
                                                                    <tr>
                                                                        <td>
                                                                            <div class="d-flex align-items-center">
                                                                                <div class="bg-primary text-white rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                                                    <?= $slot; ?>
                                                                                </div>
                                                                                <div>
                                                                                    <strong class="d-block">Title Option <?= $slot; ?></strong>
                                                                                    <small class="text-muted">Awaiting assignment</small>
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                        <?php for ($rank = 1; $rank <= 3; $rank++): ?>
                                                                            <td class="text-center">
                                                                                <div class="form-check form-check-inline align-middle">
                                                                                    <input class="form-check-input rank-radio" type="radio" disabled>
                                                                                    <label class="form-check-label small mb-0"><?= $rank; ?></label>
                                                                                </div>
                                                                            </td>
                                                                        <?php endfor; ?>
                                                                        <td class="text-center">
                                                                            <button class="btn btn-sm btn-outline-secondary" disabled>Clear</button>
                                                                        </td>
                                                                    </tr>
                                                                <?php endfor; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <div class="alert alert-info mt-3 mb-0">
                                                        <i class="bi bi-info-circle me-2"></i>
                                                        Ranking will be activated once title options are assigned by the Program Chairperson.
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="card card-rounded mt-3">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                                            <div>
                                                                <h5 class="mb-1">Upcoming Deadlines</h5>
                                                                <small class="text-muted">Assignments due within 7 days</small>
                                                            </div>
                                                            <span class="badge bg-danger-subtle text-danger"><?= number_format($dueSoonAssignmentsCount); ?></span>
                                                        </div>
                                                        <?php if (empty($dueSoonAssignments)): ?>
                                                            <div class="empty-state py-3">
                                                                <i class="bi bi-calendar2-check fs-3 d-block mb-2"></i>
                                                                Nothing is due this week.
                                                            </div>
                                                        <?php else: ?>
                                                            <ul class="deadline-list list-unstyled mb-0">
                                                                <?php foreach ($dueSoonAssignments as $item): ?>
                                                                    <li>
                                                                        <div class="d-flex justify-content-between">
                                                                            <div>
                                                                                <strong><?= htmlspecialchars($item['title']); ?></strong><br>
                                                                                <small class="text-muted"><?= htmlspecialchars($item['student_name']); ?></small>
                                                                            </div>
                                                                            <div class="text-end">
                                                                                <span class="badge bg-danger-subtle text-danger"><?= htmlspecialchars(formatReadableDate($item['due_at'])); ?></span>
                                                                            </div>
                                                                        </div>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="card card-rounded">
                                                    <div class="card-body">
                                                        <h5 class="mb-2">Reviewer Tips</h5>
                                                        <ul class="text-muted small mb-0">
                                                            <li>Assess clarity, feasibility, and originality for each title.</li>
                                                            <li>Use the top-choice toggle to highlight the strongest title.</li>
                                                            <li>Keep notes actionable; the Program Chairperson shares them with the student.</li>
                                                            <li>Update your review status to help scheduling move forward.</li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedAssignments as $student): ?>
                        <div class="card assignment-card mb-4" data-student-review-group data-student-id="<?= (int)$student['student_id']; ?>">
                            <div class="card-body">
                                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                                    <div>
                                        <h4 class="mb-1 text-success"><?= htmlspecialchars($student['student_name']); ?></h4>
                                        <small class="text-muted"><?= htmlspecialchars($student['student_email']); ?></small>
                                    </div>
                                    <div class="text-lg-end">
                                        <span class="badge bg-light text-success"><?= count($student['items']); ?> title options</span>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-success btn-sm" data-bulk-save-trigger>
                                                <i class="bi bi-save2 me-1"></i> Save All Reviews
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <form method="POST" class="d-none" data-bulk-review-form>
                                    <input type="hidden" name="bulk_save_reviews" value="1">
                                    <input type="hidden" name="student_id" value="<?= (int)$student['student_id']; ?>">
                                </form>
                                <div class="mt-3">
                                    <div class="alert alert-info py-2 px-3 mb-3 small border-0 d-flex align-items-start" style="background: rgba(13, 110, 253, 0.08); color: #084298;">
                                        <i class="bi bi-lightbulb-fill me-2 mt-1" style="font-size: 1rem;"></i>
                                        <div>
                                            <strong>Smart Rating Assistant:</strong> Rate all 3 titles and watch the recommendations auto-fill! 
                                            <span class="d-block mt-1" style="opacity: 0.9;">Highest rating → <strong>Recommend for Pursuit</strong> • Middle rating → <strong>Needs Revision</strong> • Lowest rating → <strong>Not Recommended</strong></span>
                                            <span class="d-block mt-1" style="opacity: 0.85; font-size: 0.9em;">💡 Works with any rating combination (5-3-1, 4-2-1, etc.). You can manually override if needed.</span>
                                        </div>
                                    </div>
                                    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                                        <?php foreach ($student['items'] as $item): ?>
                                            <?php
                                                $status = $item['status'] ?? 'pending';
                                                $review = $item['review'];
                                                $rankOrderValue = isset($review['rank_order']) ? (int)$review['rank_order'] : null;
                                                $rankFieldBase = 'rank_' . (int)$item['assignment_id'];
                                                $messageList = is_array($item['messages'] ?? null) ? $item['messages'] : [];
                                                $previewUrl = !empty($item['manuscript_available'])
                                                    ? ('reviewer_file.php?assignment_id=' . (int)$item['assignment_id'])
                                                    : '';
                                            ?>
                                            <div class="col">
                                                <div class="d-flex flex-column h-100 gap-3">
                                                    <div class="review-form" data-review-item data-assignment-id="<?= (int)$item['assignment_id']; ?>">
                                                    <div class="concept-mini h-100 d-flex flex-column">
                                                        <div class="mb-2 concept-header">
                                                            <div>
                                                                <p class="text-uppercase small text-muted mb-1">Title option</p>
                                                                <div class="concept-title-row">
                                                                    <h5 class="mb-1 concept-title" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-custom-class="title-tooltip" data-bs-title="<?= htmlspecialchars($item['title'] ?? 'Untitled Title Option'); ?>">
                                                                        <?= htmlspecialchars($item['title'] ?? 'Untitled Title Option'); ?>
                                                                    </h5>
                                                                </div>
                                                                <div class="concept-actions mt-1">
                                                                    <?php if ($previewUrl !== ''): ?>
                                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#previewModal<?= (int)$item['assignment_id']; ?>">
                                                                            <i class="bi bi-eye"></i> Preview
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                                            <i class="bi bi-eye-slash"></i> No manuscript
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <small class="text-muted d-block">
                                                                    Assigned <?= htmlspecialchars(formatReadableDate($item['created_at'] ?? '')); ?>
                                                                    <?php if (!empty($item['due_at'])): ?>
                                                                        &middot; Due <?= htmlspecialchars(formatReadableDate($item['due_at'])); ?>
                                                                    <?php endif; ?>
                                                                </small>
                                                                <?php if (!empty($item['instructions'])): ?>
                                                                    <div class="alert alert-success-subtle text-success py-2 px-3 mb-2 small">
                                                                        <strong>Chair Instructions:</strong> <?= nl2br(htmlspecialchars($item['instructions'])); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="text-end">
                                                                <span class="badge <?= $status === 'completed' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'; ?>">
                                                                    <?= ucfirst(str_replace('_', ' ', $status)); ?>
                                                                </span>
                                                                <?php if ($rankOrderValue): ?>
                                                                    <?php
                                                                        $rankBadgeClass = $rankOrderValue === 1 ? 'bg-success-subtle text-success' : ($rankOrderValue === 2 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
                                                                        $rankLabel = $rankOrderValue === 1 ? 'Top choice' : ($rankOrderValue === 2 ? 'Second option' : 'Third option');
                                                                    ?>
                                                                    <span class="badge <?= $rankBadgeClass; ?> mt-1">
                                                                        Rank <?= (int)$rankOrderValue; ?> &middot; <?= htmlspecialchars($rankLabel); ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>

                                                        <div class="row g-2 mb-3">
                                                            <div class="col-sm-6">
                                                                <label class="form-label fw-semibold small text-muted">Rating (1-5)</label>
                                                                <div class="rating-list">
                                                                    <?php foreach ([1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'] as $ratingValue => $ratingLabel): ?>
                                                                        <?php $ratingId = 'score' . (int)$item['assignment_id'] . '_' . (int)$ratingValue; ?>
                                                                        <div class="form-check rating-item">
                                                                            <input class="form-check-input" type="radio" name="score_<?= (int)$item['assignment_id']; ?>" id="<?= $ratingId; ?>" value="<?= $ratingValue; ?>" data-score-value="<?= $ratingValue; ?>" <?= (int)($review['score'] ?? 0) === $ratingValue ? 'checked' : ''; ?>>
                                                                            <label class="form-check-label small" for="<?= $ratingId; ?>"><?= $ratingValue; ?> - <?= $ratingLabel; ?></label>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                            <div class="col-sm-6">
                                                                <label class="form-label fw-semibold small text-muted">Recommendation</label>
                                                                <select class="form-select form-select-sm" name="recommendation">
                                                                    <option value="">Choose...</option>
                                                                    <option value="pursue" <?= ($review['recommendation'] ?? '') === 'pursue' ? 'selected' : ''; ?>>Recommend for Pursuit</option>
                                                                    <option value="revise" <?= ($review['recommendation'] ?? '') === 'revise' ? 'selected' : ''; ?>>Needs Revision</option>
                                                                    <option value="reject" <?= ($review['recommendation'] ?? '') === 'reject' ? 'selected' : ''; ?>>Not Recommended</option>
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <?php
                                                            $isPreferredChecked = !empty($review['is_preferred']) || (int)($review['rank_order'] ?? 0) === 1;
                                                        ?>
                                                        <div class="form-check form-switch mb-3">
                                                            <input class="form-check-input" type="checkbox" role="switch" id="preferred<?= (int)$item['assignment_id']; ?>" name="is_preferred_<?= (int)$item['assignment_id']; ?>" value="1" data-preferred-toggle data-student-id="<?= (int)$student['student_id']; ?>" <?= $isPreferredChecked ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="preferred<?= (int)$item['assignment_id']; ?>">
                                                                Recommended to Pursue (Top Choice)
                                                            </label>
                                                        </div>
                                                        <small class="text-muted d-block mb-3">Only one title per student can be marked as top choice.</small>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Comments &amp; Suggestions</label>
                                                            <textarea class="form-control" name="comment_suggestions_<?= (int)$item['assignment_id']; ?>" rows="4" placeholder="Provide detailed comments or suggestions for this candidate" required><?= htmlspecialchars($review['comment_suggestions'] ?? ($review['notes'] ?? '')); ?></textarea>
                                                        </div>
                                                        <div class="form-check form-switch mb-1">
                                                            <input class="form-check-input" type="checkbox" role="switch" id="interest<?= (int)$item['assignment_id']; ?>" name="adviser_interest_<?= (int)$item['assignment_id']; ?>" value="1" data-mentoring-toggle data-student-id="<?= (int)$student['student_id']; ?>" <?= !empty($review['adviser_interest']) ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="interest<?= (int)$item['assignment_id']; ?>">
                                                                Interested in mentoring the candidate as a <strong>Thesis Adviser</strong>? (Please check)
                                                            </label>
                                                        </div>
                                                        <small class="text-muted mentoring-limit d-block mb-3" data-mentoring-count>Mentoring interests: 0/3 used</small>
                                                        <?php
                                                            $chairFeedbackMessage = trim((string)($review['chair_feedback'] ?? ''));
                                                            $mentorInterested = !empty($review['adviser_interest']);
                                                        ?>
                                                        <?php if ($chairFeedbackMessage !== ''): ?>
                                                            <div class="alert alert-info-subtle border-info-subtle text-dark mb-3">
                                                                <div class="fw-semibold mb-1"><i class="bi bi-chat-quote me-1"></i>Program Chair Feedback</div>
                                                                <div class="small mb-1"><?= nl2br(htmlspecialchars($chairFeedbackMessage)); ?></div>
                                                                <small class="text-muted">Sent <?= htmlspecialchars(formatReadableDateTime($review['chair_feedback_at'] ?? null)); ?></small>
                                                            </div>
                                                        <?php elseif ($mentorInterested): ?>
                                                            <div class="alert alert-secondary-subtle text-secondary mb-3">
                                                                <small>The Program Chair will review your mentoring request and send feedback here once available.</small>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="alert alert-secondary-subtle text-secondary mb-3">
                                                                <small>Enable the mentoring toggle above if you would like the Program Chair to review your interest and send you feedback.</small>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="d-flex justify-content-between align-items-center mt-auto">
                                                            <div class="d-flex flex-column">
                                                                <small class="text-muted">Saving updates your reviewer status.</small>
                                                                <a href="view_concept.php?id=<?= (int)$item['concept_paper_id']; ?>" class="small text-decoration-none">Preview title</a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                    <div class="conversation-card">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <span class="fw-semibold"><i class="bi bi-chat-dots me-1"></i>Reviewer Conversation</span>
                                                            <small class="text-muted"><?= number_format(count($messageList)); ?> message<?= count($messageList) === 1 ? '' : 's'; ?></small>
                                                        </div>
                                                        <div class="conversation-thread">
                                                            <?php if (empty($messageList)): ?>
                                                                <div class="conversation-empty">
                                                                    Start a discussion with the Program Chairperson here.
                                                                </div>
                                                            <?php else: ?>
                                                                <?php foreach ($messageList as $message): ?>
                                                                    <?php $isSelf = (int)($message['sender_id'] ?? 0) === $reviewerId; ?>
                                                                    <div class="conversation-bubble<?= $isSelf ? ' self' : ''; ?>">
                                                                        <div class="conversation-meta">
                                                                            <?= htmlspecialchars($isSelf ? 'You' : (trim((string)($message['sender_name'] ?? 'Program Chair')) ?: 'Program Chair')); ?>
                                                                            &middot; <?= htmlspecialchars(formatReadableDateTime($message['created_at'] ?? '')); ?>
                                                                        </div>
                                                                        <div><?= nl2br(htmlspecialchars($message['message'] ?? '')); ?></div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <form method="POST" class="conversation-form">
                                                            <input type="hidden" name="send_conversation" value="1">
                                                            <input type="hidden" name="assignment_id" value="<?= (int)$item['assignment_id']; ?>">
                                                            <input type="hidden" name="concept_id" value="<?= (int)$item['concept_paper_id']; ?>">
                                                            <div class="mb-2">
                                                                <textarea class="form-control" rows="2" name="conversation_message" placeholder="Share an update, clarification, or concern..." required></textarea>
                                                            </div>
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <small class="text-muted">Visible to the Program Chair and fellow reviewers.</small>
                                                                <button type="submit" class="btn btn-outline-success btn-sm">
                                                                    <i class="bi bi-send"></i> Send
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                    <?php if ($previewUrl !== ''): ?>
                                                        <div class="modal fade preview-modal" id="previewModal<?= (int)$item['assignment_id']; ?>" tabindex="-1" aria-labelledby="previewModalLabel<?= (int)$item['assignment_id']; ?>" aria-hidden="true" data-preview-modal data-preview-url="<?= htmlspecialchars($previewUrl); ?>">
                                                            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title" id="previewModalLabel<?= (int)$item['assignment_id']; ?>">Manuscript Preview</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                    </div>
                                                                    <div class="modal-body p-0">
                                                                        <iframe class="preview-frame" title="Manuscript preview"></iframe>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <small class="text-muted">If the preview does not load, open the PDF in a new tab.</small>
                                                                        <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($previewUrl); ?>" target="_blank" rel="noopener">
                                                                            <i class="bi bi-box-arrow-up-right"></i> Open in new tab
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if (in_array($role, ['adviser', 'panel', 'committee_chair', 'committee_chairperson', 'faculty'], true)): ?>
<script>
    (function() {
        const tooltipTriggers = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggers.forEach((el) => {
            new bootstrap.Tooltip(el);
        });
    })();

    (function() {
        const studentGroups = document.querySelectorAll('[data-student-review-group]');
        if (!studentGroups.length) {
            return;
        }

        const appendHiddenInput = (form, name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            input.dataset.generatedInput = '1';
            form.appendChild(input);
        };

        const syncGroupState = (group) => {
            const reviewForms = Array.from(group.querySelectorAll('[data-review-item]'));
            const selectedScoreOwners = new Map();
            const selectedByForm = [];

            reviewForms.forEach((form) => {
                const selected = form.querySelector('input[type="radio"][data-score-value]:checked');
                if (!selected) {
                    return;
                }
                const scoreValue = selected.getAttribute('data-score-value');
                if (selectedScoreOwners.has(scoreValue)) {
                    selected.checked = false;
                    return;
                }
                selectedScoreOwners.set(scoreValue, form);
                selectedByForm.push({
                    form,
                    score: parseInt(scoreValue, 10) || 0,
                });
            });

            reviewForms.forEach((form) => {
                form.querySelectorAll('input[type="radio"][data-score-value]').forEach((radio) => {
                    const scoreValue = radio.getAttribute('data-score-value');
                    const ownerForm = selectedScoreOwners.get(scoreValue);
                    radio.disabled = !!ownerForm && ownerForm !== form;
                });
            });

            const preferredForm = selectedByForm
                .sort((left, right) => right.score - left.score)[0]?.form ?? null;

            reviewForms.forEach((form) => {
                const preferredToggle = form.querySelector('[data-preferred-toggle]');
                if (preferredToggle) {
                    preferredToggle.checked = !!preferredForm && preferredForm === form;
                }
            });
        };

        const collectGroupReviews = (group) => {
            const reviewForms = Array.from(group.querySelectorAll('[data-review-item]'));
            const usedScores = new Set();
            const reviews = [];

            for (const form of reviewForms) {
                const assignmentId = form.getAttribute('data-assignment-id') || '';
                const scoreInput = form.querySelector('input[type="radio"][data-score-value]:checked');
                const recommendation = (form.querySelector('select[name="recommendation"]')?.value || '').trim();
                const commentSuggestions = (form.querySelector('textarea[name^="comment_suggestions_"]')?.value || '').trim();
                const notes = (form.querySelector('textarea[name^="comment_suggestions_"]')?.value || '').trim();
                const adviserInterest = form.querySelector('input[name^="adviser_interest_"]')?.checked ? 1 : 0;

                if (!assignmentId) {
                    return { error: 'Missing assignment details for one of the titles.' };
                }
                if (!scoreInput) {
                    return { error: 'Please choose a rating for every title before saving.' };
                }
                const score = parseInt(scoreInput.value, 10) || 0;
                if (usedScores.has(score)) {
                    return { error: `Rating ${score} can only be used once per student.` };
                }
                if (!recommendation) {
                    return { error: 'Please choose a recommendation for every title before saving.' };
                }
                if (!commentSuggestions) {
                    return { error: 'Please provide comments and suggestions for every title before saving.' };
                }

                usedScores.add(score);
                reviews.push({
                    assignmentId,
                    score,
                    recommendation,
                    commentSuggestions,
                    notes,
                    adviserInterest,
                });
            }

            return { reviews };
        };

        studentGroups.forEach((group) => {
            group.querySelectorAll('input[type="radio"][data-score-value]').forEach((radio) => {
                radio.addEventListener('change', () => {
                    syncGroupState(group);
                });
            });

            group.querySelectorAll('[data-preferred-toggle]').forEach((toggle) => {
                toggle.addEventListener('change', () => {
                    if (!toggle.checked) {
                        return;
                    }
                    const studentId = toggle.getAttribute('data-student-id');
                    group.querySelectorAll('[data-preferred-toggle]').forEach((other) => {
                        if (other !== toggle && other.getAttribute('data-student-id') === studentId) {
                            other.checked = false;
                        }
                    });
                });
            });

            const trigger = group.querySelector('[data-bulk-save-trigger]');
            const bulkForm = group.querySelector('[data-bulk-review-form]');
            if (trigger && bulkForm) {
                trigger.addEventListener('click', () => {
                    syncGroupState(group);
                    const result = collectGroupReviews(group);
                    if (result.error) {
                        alert(result.error);
                        return;
                    }
                    bulkForm.querySelectorAll('[data-generated-input]').forEach((input) => input.remove());
                    result.reviews.forEach((review) => {
                        appendHiddenInput(bulkForm, `reviews[${review.assignmentId}][score]`, review.score);
                        appendHiddenInput(bulkForm, `reviews[${review.assignmentId}][recommendation]`, review.recommendation);
                        appendHiddenInput(bulkForm, `reviews[${review.assignmentId}][comment_suggestions]`, review.commentSuggestions);
                        appendHiddenInput(bulkForm, `reviews[${review.assignmentId}][notes]`, review.notes);
                        appendHiddenInput(bulkForm, `reviews[${review.assignmentId}][adviser_interest]`, review.adviserInterest);
                    });
                    bulkForm.submit();
                });
            }

            syncGroupState(group);
        });
    })();

    (function() {
        const rankTables = document.querySelectorAll('[data-rank-table]');
        if (!rankTables.length) {
            return;
        }

        const updateRowState = (row) => {
            if (!row) {
                return;
            }
            const indicator = row.querySelector('[data-rank-indicator]');
            const clearBtn = row.querySelector('.clear-rank-btn');
            const selectedRadio = row.querySelector('.rank-radio:checked');
            if (indicator) {
                if (selectedRadio) {
                    indicator.textContent = `Rank ${selectedRadio.getAttribute('data-rank-value')} selected`;
                    indicator.classList.add('active');
                } else {
                    indicator.textContent = 'No rank yet';
                    indicator.classList.remove('active');
                }
            }
            if (clearBtn) {
                clearBtn.disabled = !selectedRadio;
            }
        };

        rankTables.forEach((table) => {
            table.querySelectorAll('[data-assignment-row]').forEach(updateRowState);

            table.addEventListener('change', (event) => {
                const target = event.target;
                if (!target.classList.contains('rank-radio') || !target.checked) {
                    return;
                }
                const rankValue = target.getAttribute('data-rank-value');
                const row = target.closest('[data-assignment-row]');
                if (!rankValue || !row) {
                    return;
                }
                table.querySelectorAll(`.rank-radio[data-rank-value="${rankValue}"]`).forEach((radio) => {
                    if (radio !== target && radio.checked) {
                        radio.checked = false;
                        updateRowState(radio.closest('[data-assignment-row]'));
                    }
                });
                updateRowState(row);
            });

            table.querySelectorAll('.clear-rank-btn').forEach((btn) => {
                btn.addEventListener('click', (event) => {
                    event.preventDefault();
                    const assignmentId = btn.getAttribute('data-clear-assignment');
                    if (!assignmentId) {
                        return;
                    }
                    table.querySelectorAll(`.rank-radio[data-assignment="${assignmentId}"]`).forEach((radio) => {
                        radio.checked = false;
                    });
                    updateRowState(btn.closest('[data-assignment-row]'));
                });
            });
        });
    })();

    (function() {
        const preferredToggles = document.querySelectorAll('[data-preferred-toggle]');
        if (!preferredToggles.length) {
            return;
        }

        preferredToggles.forEach((toggle) => {
            toggle.addEventListener('change', () => {
                if (!toggle.checked) {
                    return;
                }
                const studentId = toggle.getAttribute('data-student-id');
                preferredToggles.forEach((other) => {
                    if (other !== toggle && other.getAttribute('data-student-id') === studentId) {
                        other.checked = false;
                    }
                });
            });
        });
    })();

    (function() {
        const limit = 3;
        const toggles = Array.from(document.querySelectorAll('[data-mentoring-toggle]'));
        const labels = Array.from(document.querySelectorAll('[data-mentoring-count]'));
        const banner = document.querySelector('[data-mentoring-limit-banner]');
        if (!toggles.length) {
            return;
        }

        const getStudentId = (toggle) => toggle.getAttribute('data-student-id') || '';

        const getSelectedStudents = (excludeToggle = null) => {
            const selected = new Set();
            toggles.forEach((toggle) => {
                if (excludeToggle && toggle === excludeToggle) {
                    return;
                }
                if (!toggle.checked) {
                    return;
                }
                const sid = getStudentId(toggle);
                if (sid) {
                    selected.add(sid);
                }
            });
            return selected;
        };

        const update = () => {
            const selectedStudents = getSelectedStudents();
            const selectedCount = selectedStudents.size;
            labels.forEach((label) => {
                label.textContent = `Mentoring interests: ${selectedCount}/${limit} used`;
            });
            if (banner) {
                if (selectedCount >= limit) {
                    banner.textContent = `Mentoring limit reached: ${selectedCount}/${limit} students selected. Uncheck a student to select another.`;
                    banner.classList.remove('d-none');
                } else {
                    banner.classList.add('d-none');
                }
            }
            const disableExtras = selectedCount >= limit;
            toggles.forEach((toggle) => {
                const sid = getStudentId(toggle);
                if (toggle.checked) {
                    toggle.disabled = false;
                } else if (disableExtras && sid && !selectedStudents.has(sid)) {
                    toggle.disabled = true;
                } else {
                    toggle.disabled = false;
                }
            });
        };

        toggles.forEach((toggle) => {
            toggle.addEventListener('change', () => {
                const sid = getStudentId(toggle);
                if (toggle.checked && sid) {
                    const selectedBefore = getSelectedStudents(toggle);
                    if (!selectedBefore.has(sid) && selectedBefore.size >= limit) {
                        toggle.checked = false;
                        update();
                        return;
                    }
                    toggles.forEach((other) => {
                        if (other !== toggle && getStudentId(other) === sid) {
                            other.checked = false;
                        }
                    });
                }
                update();
            });
        });
        update();
    })();

    // Auto-fill recommendation dropdowns based on rating rankings
    (function() {
        const studentGroups = document.querySelectorAll('[data-student-review-group]');
        if (!studentGroups.length) {
            return;
        }

        studentGroups.forEach((group) => {
            const studentId = group.getAttribute('data-student-id');
            const reviewItems = group.querySelectorAll('[data-review-item]');
            
            // Collect all rating radios and recommendation dropdowns for this student
            const itemsData = [];
            reviewItems.forEach((item) => {
                const assignmentId = item.getAttribute('data-assignment-id');
                const ratingRadios = item.querySelectorAll('input[type="radio"][name^="score_"]');
                const recommendationSelect = item.querySelector('select[name="recommendation"]');
                
                if (ratingRadios.length && recommendationSelect) {
                    itemsData.push({
                        assignmentId,
                        ratingRadios: Array.from(ratingRadios),
                        recommendationSelect,
                    });
                }
            });

            if (!itemsData.length) {
                return;
            }

            const addVisualFeedback = (selectElement) => {
                // Add smooth visual feedback when auto-filling
                selectElement.style.transition = 'background-color 0.4s ease, border-color 0.4s ease';
                selectElement.style.backgroundColor = '#d1f4e0';
                selectElement.style.borderColor = '#198754';
                
                setTimeout(() => {
                    selectElement.style.backgroundColor = '';
                    selectElement.style.borderColor = '';
                }, 1200);
            };

            const updateRecommendations = () => {
                // Collect current ratings for each title
                const ratings = [];
                itemsData.forEach((item) => {
                    const checkedRadio = item.ratingRadios.find(r => r.checked);
                    if (checkedRadio) {
                        const score = parseInt(checkedRadio.getAttribute('data-score-value'), 10);
                        if (!isNaN(score)) {
                            ratings.push({
                                assignmentId: item.assignmentId,
                                score,
                                recommendationSelect: item.recommendationSelect,
                            });
                        }
                    }
                });

                // Only auto-fill if all 3 titles have ratings
                if (ratings.length !== 3) {
                    return;
                }

                // Sort by score (highest to lowest)
                ratings.sort((a, b) => b.score - a.score);

                // Assign recommendations based on ranking:
                // 1st (highest score) → "pursue" (Recommend for Pursuit)
                // 2nd (middle score) → "revise" (Needs Revision)
                // 3rd (lowest score) → "reject" (Not Recommended)
                if (ratings[0] && ratings[0].recommendationSelect.value !== 'pursue') {
                    ratings[0].recommendationSelect.value = 'pursue';
                    addVisualFeedback(ratings[0].recommendationSelect);
                }
                if (ratings[1] && ratings[1].recommendationSelect.value !== 'revise') {
                    ratings[1].recommendationSelect.value = 'revise';
                    addVisualFeedback(ratings[1].recommendationSelect);
                }
                if (ratings[2] && ratings[2].recommendationSelect.value !== 'reject') {
                    ratings[2].recommendationSelect.value = 'reject';
                    addVisualFeedback(ratings[2].recommendationSelect);
                }
            };

            // Attach change listeners to all rating radios
            itemsData.forEach((item) => {
                item.ratingRadios.forEach((radio) => {
                    radio.addEventListener('change', updateRecommendations);
                });
            });

            // Run once on load in case ratings are already selected
            updateRecommendations();
        });
    })();

    (function() {
        const previewModals = document.querySelectorAll('[data-preview-modal]');
        if (!previewModals.length) {
            return;
        }

        previewModals.forEach((modal) => {
            const url = modal.getAttribute('data-preview-url');
            const frame = modal.querySelector('iframe');
            if (!url || !frame) {
                return;
            }

            modal.addEventListener('shown.bs.modal', () => {
                if (!frame.getAttribute('src')) {
                    frame.setAttribute('src', url);
                }
            });

            modal.addEventListener('hidden.bs.modal', () => {
                frame.removeAttribute('src');
            });
        });
    })();
</script>
<?php endif; ?>
</body>
</html>



