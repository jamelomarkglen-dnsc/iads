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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_rank_update'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $rankAssignmentsInput = $_POST['rank_assignments'] ?? [];
    $rankAssignments = [];
    $usedRanks = [];
    $duplicateRank = null;
    foreach ($rankAssignmentsInput as $assignmentId => $rankValue) {
        $assignment = (int)$assignmentId;
        $rank = (int)$rankValue;
        if ($assignment <= 0 || $rank < 1 || $rank > 3) {
            continue;
        }
        if (isset($usedRanks[$rank])) {
            $duplicateRank = $rank;
            break;
        }
        $usedRanks[$rank] = $assignment;
        $rankAssignments[$assignment] = $rank;
    }
    if ($studentId <= 0) {
        $feedback = ['type' => 'warning', 'message' => 'Please select a student to rank.'];
    } elseif ($duplicateRank !== null) {
        $feedback = [
            'type' => 'warning',
            'message' => sprintf('Rank %d can only be assigned to one title per student. Clear the existing selection before reusing it.', $duplicateRank),
        ];
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
        ");
        if ($assignmentStmt) {
            $assignmentStmt->bind_param('ii', $reviewerId, $studentId);
            $assignmentStmt->execute();
            $result = $assignmentStmt->get_result();
            $assignmentsForStudent = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $assignmentStmt->close();
        } else {
            $assignmentsForStudent = [];
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
            $feedback = ['type' => 'warning', 'message' => 'No assigned concept titles were found for ranking.'];
        } else {
            $reviewStmt = $conn->prepare("
                INSERT INTO concept_reviews (assignment_id, concept_paper_id, reviewer_id, reviewer_role, score, recommendation, rank_order, is_preferred, notes, comment_suggestions, adviser_interest)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
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
                foreach ($assignmentsForStudent as $row) {
                    $assignmentId = (int)($row['id'] ?? 0);
                    $conceptId = (int)($row['concept_paper_id'] ?? 0);
                    if ($assignmentId <= 0 || $conceptId <= 0) {
                        continue;
                    }
                    $assignmentReviewerRole = resolveAssignmentRole($role, $row['reviewer_role'] ?? null);
                    $selectedRank = $rankAssignments[$assignmentId] ?? null;
                    $rankOrderParam = $selectedRank !== null ? (int)$selectedRank : null;
                    $scoreValue = (int)($row['review_score'] ?? 0);
                    $recommendationValue = (string)($row['review_recommendation'] ?? '');
                    $notesValue = (string)($row['review_notes'] ?? '');
                    $commentsValue = (string)($row['review_comments'] ?? '');
                    if ($commentsValue === '' && $notesValue !== '') {
                        $commentsValue = $notesValue;
                    }
                    if ($notesValue === '' && $commentsValue !== '') {
                        $notesValue = $commentsValue;
                    }
                    $interestValue = (int)($row['review_adviser_interest'] ?? 0);
                    $isPreferred = $selectedRank === 1 ? 1 : 0;

                    $reviewStmt->bind_param(
                        'iiisisiissi',
                        $assignmentId,
                        $conceptId,
                        $reviewerId,
                        $assignmentReviewerRole,
                        $scoreValue,
                        $recommendationValue,
                        $rankOrderParam,
                        $isPreferred,
                        $notesValue,
                        $commentsValue,
                        $interestValue
                    );
                    $reviewStmt->execute();

                    $newStatus = $selectedRank !== null ? 'in_progress' : 'pending';
                    $statusStmt->bind_param('sii', $newStatus, $assignmentId, $reviewerId);
                    $statusStmt->execute();
                }
                $feedback = ['type' => 'success', 'message' => 'Ranking updated successfully.'];
            } else {
                $feedback = ['type' => 'danger', 'message' => 'Unable to prepare ranking statements.'];
            }
            if ($reviewStmt) {
                $reviewStmt->close();
            }
            if ($statusStmt) {
                $statusStmt->close();
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

        if (!$duplicateRank && $commentSuggestions === '') {
            $feedback = ['type' => 'warning', 'message' => 'Please provide your comments and suggestions before saving.'];
        } elseif (!$duplicateRank) {
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
$heroDescription = $overrideHeroDescription ?? 'Review the concept titles assigned by the Program Chairperson, rate each concept paper, and recommend the most viable title for the student to pursue.';
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
                                No concept papers are assigned to you yet.
                            </div>
                            <?php if ($role === 'adviser' && !empty($adviserConceptPreview)): ?>
                                <?php foreach ($adviserConceptPreview as $preview): ?>
                                    <?php $rankPlaceholderTextClass = $isAdviserView ? 'text-muted' : 'text-white-50'; ?>
                                    <div class="rank-card mt-3">
                                        <p class="text-uppercase small <?= $rankPlaceholderTextClass; ?> mb-1">Advisee concept set</p>
                                        <h5 class="mb-2"><?= htmlspecialchars($preview['student_name']); ?></h5>
                                        <small class="<?= $rankPlaceholderTextClass; ?>">These titles were created for this advisee in assign_faculty.php. Ranking activates automatically once the Program Chairperson routes the student to you.</small>
                                        <div class="table-responsive mt-3">
                                            <table class="table table-borderless align-middle rank-table mb-0">
                                                <thead>
                                                    <tr>
                                                        <th scope="col">Concept Title</th>
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
                                                                <strong><?= $concept['has_title'] ? htmlspecialchars($concept['title']) : 'Concept Title ' . ($index + 1); ?></strong>
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
                                                                    <th scope="col" class="w-50">Concept Title</th>
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
                                                                                    <strong class="d-block">Concept Title <?= $slot; ?></strong>
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
                                                        Ranking will be activated once concept titles are assigned by the Program Chairperson.
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
                                                            <li>Use the "preferred concept" toggle to highlight the strongest title.</li>
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
                        <div class="card assignment-card mb-4">
                            <div class="card-body">
                                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                                    <div>
                                        <h4 class="mb-1 text-success"><?= htmlspecialchars($student['student_name']); ?></h4>
                                        <small class="text-muted"><?= htmlspecialchars($student['student_email']); ?></small>
                                    </div>
                                    <span class="badge bg-light text-success"><?= count($student['items']); ?> concept titles</span>
                                </div>
                                <?php if (in_array($role, ['adviser', 'panel', 'committee_chair', 'committee_chairperson', 'faculty'], true)): ?>
                                    <?php
                                        $rankSelections = [];
                                        foreach ($student['items'] as $item) {
                                            $rankValue = (int)($item['review']['rank_order'] ?? 0);
                                            if ($rankValue >= 1 && $rankValue <= 3) {
                                                $rankSelections[(int)$item['assignment_id']] = $rankValue;
                                            }
                                        }
                                        $rankMetaClass = $isAdviserView ? 'text-muted' : 'text-white-50';
                                    ?>
                                    <div class="rank-card mt-3 mb-4">
                                        <form method="POST" data-rank-form>
                                            <input type="hidden" name="bulk_rank_update" value="1">
                                            <input type="hidden" name="student_id" value="<?= (int)$student['student_id']; ?>">
                                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
                                                <div>
                                                    <p class="text-uppercase small mb-1 fw-semibold">Rank the student&#39;s titles</p>
                                                    <small class="<?= $rankMetaClass; ?>">
                                                        Use the numbered buttons beside each title. Each rank (1-3) can only be used once. Clear a selection if you need to reuse that rank.
                                                    </small>
                                                </div>
                                                <button type="submit" class="btn btn-warning text-dark px-4 mt-2 mt-lg-0">
                                                    <i class="bi bi-save me-1"></i> Save Ranking
                                                </button>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-borderless align-middle rank-table mb-0" data-rank-table>
                                                    <thead>
                                                        <tr>
                                                            <th scope="col">Concept Title</th>
                                                            <th scope="col" class="text-center">Rank 1</th>
                                                            <th scope="col" class="text-center">Rank 2</th>
                                                            <th scope="col" class="text-center">Rank 3</th>
                                                            <th scope="col" class="text-center">Clear</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                            $maxRankSlots = 3;
                                                            $rankableAssignments = array_values($student['items']);
                                                            for ($slot = 0; $slot < $maxRankSlots; $slot++):
                                                                $assignmentData = $rankableAssignments[$slot] ?? null;
                                                                $assignmentId = $assignmentData ? (int)($assignmentData['assignment_id'] ?? 0) : 0;
                                                                $currentRank = $assignmentId ? ($rankSelections[$assignmentId] ?? 0) : 0;
                                                                $titleText = $assignmentData ? ($assignmentData['title'] ?? 'Untitled Concept') : '';
                                                                $statusText = $assignmentData ? ucwords(str_replace('_', ' ', $assignmentData['status'] ?? 'pending')) : 'Awaiting assignment';
                                                                $dueDisplay = ($assignmentData && !empty($assignmentData['due_at'])) ? formatReadableDate($assignmentData['due_at']) : null;
                                                                $tablePreviewUrl = ($assignmentData && !empty($assignmentData['manuscript_available']))
                                                                    ? ('reviewer_file.php?assignment_id=' . $assignmentId)
                                                                    : '';
                                                            ?>
                                                            <tr <?= $assignmentId ? 'data-assignment-row="' . $assignmentId . '"' : ''; ?>>
                                                                <td>
                                                                    <div class="small text-uppercase <?= $rankMetaClass; ?>">Concept Title <?= $slot + 1; ?></div>
                                                                    <?php if ($assignmentId): ?>
                                                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                                                            <div class="fw-semibold"><?= htmlspecialchars($titleText); ?></div>
                                                                            <?php if ($tablePreviewUrl !== ''): ?>
                                                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#previewModal<?= $assignmentId; ?>">
                                                                                    <i class="bi bi-eye"></i> Preview
                                                                                </button>
                                                                            <?php else: ?>
                                                                                <span class="badge bg-light text-muted">No manuscript</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div class="small <?= $rankMetaClass; ?>">
                                                                            <?= htmlspecialchars($statusText); ?>
                                                                            <?php if ($dueDisplay): ?>
                                                                                &middot; Due <?= htmlspecialchars($dueDisplay); ?>
                                                                            <?php endif; ?>
                                                                            <?php if ($assignmentData && !empty($assignmentData['assigned_by_name'])): ?>
                                                                                &middot; Assigned by <?= htmlspecialchars($assignmentData['assigned_by_name']); ?>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <div class="text-muted small">Awaiting assignment from the Program Chairperson.</div>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <?php for ($rank = 1; $rank <= 3; $rank++): ?>
                                                                    <td class="text-center">
                                                                        <?php if ($assignmentId): ?>
                                                                            <div class="form-check form-check-inline align-middle">
                                                                                <input
                                                                                    class="form-check-input rank-radio"
                                                                                    type="radio"
                                                                                    name="rank_assignments[<?= $assignmentId; ?>]"
                                                                                    id="rank<?= $assignmentId; ?>_<?= $rank; ?>"
                                                                                    value="<?= $rank; ?>"
                                                                                    data-rank-value="<?= $rank; ?>"
                                                                                    data-assignment="<?= $assignmentId; ?>"
                                                                                    <?= $currentRank === $rank ? 'checked' : ''; ?>
                                                                                >
                                                                                <label class="form-check-label" for="rank<?= $assignmentId; ?>_<?= $rank; ?>"><?= $rank; ?></label>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <div class="form-check form-check-inline align-middle <?= $rankMetaClass; ?>">
                                                                                <input class="form-check-input rank-radio" type="radio" disabled>
                                                                                <label class="form-check-label"><?= $rank; ?></label>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                <?php endfor; ?>
                                                                <td class="text-center">
                                                                    <?php if ($assignmentId): ?>
                                                                        <div class="d-flex flex-column align-items-center gap-2">
                                                                            <span class="rank-indicator<?= $currentRank ? ' active' : ''; ?>" data-rank-indicator>
                                                                                <?= $currentRank ? 'Rank ' . $currentRank . ' selected' : 'No rank yet'; ?>
                                                                            </span>
                                                                        <button type="button" class="btn btn-sm btn-outline-light clear-rank-btn" data-clear-assignment="<?= $assignmentId; ?>" <?= $currentRank ? '' : 'disabled'; ?>>
                                                                                <i class="bi bi-x-circle me-1"></i> Clear
                                                                            </button>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span class="small <?= $rankMetaClass; ?>">Awaiting title</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endfor; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-3">
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
                                                    <form method="POST" class="review-form">
                                                    <input type="hidden" name="save_review" value="1">
                                                    <input type="hidden" name="assignment_id" value="<?= (int)$item['assignment_id']; ?>">
                                                    <input type="hidden" name="concept_id" value="<?= (int)$item['concept_paper_id']; ?>">
                                                    <div class="concept-mini h-100 d-flex flex-column">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <div>
                                                                <p class="text-uppercase small text-muted mb-1">Concept title</p>
                                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                                    <h5 class="mb-1"><?= htmlspecialchars($item['title'] ?? 'Untitled Concept'); ?></h5>
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
                                                                <?php if (!empty($item['assigned_by_name'])): ?>
                                                                    <small class="text-muted d-block">Assigned by <?= htmlspecialchars($item['assigned_by_name']); ?></small>
                                                                <?php endif; ?>
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

                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold small text-muted">Rank this concept</label>
                                                            <select class="form-select form-select-sm" name="rank_order">
                                                                <option value="">No rank</option>
                                                                <option value="1" <?= $rankOrderValue === 1 ? 'selected' : ''; ?>>Rank 1 &middot; Top choice</option>
                                                                <option value="2" <?= $rankOrderValue === 2 ? 'selected' : ''; ?>>Rank 2 &middot; Backup option</option>
                                                                <option value="3" <?= $rankOrderValue === 3 ? 'selected' : ''; ?>>Rank 3 &middot; Third option</option>
                                                            </select>
                                                            <small class="text-muted d-block mt-1">Assign unique ranks 1-3 across this student's titles.</small>
                                                        </div>

                                                        <div class="row g-2 mb-3">
                                                            <div class="col-sm-6">
                                                                <label class="form-label fw-semibold small text-muted">Rating (1-5)</label>
                                                                <select class="form-select form-select-sm" name="score">
                                                                    <option value="0">Select</option>
                                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                        <option value="<?= $i; ?>" <?= (int)($review['score'] ?? 0) === $i ? 'selected' : ''; ?>><?= $i; ?> - <?= ['Poor','Fair','Good','Very Good','Excellent'][$i-1]; ?></option>
                                                                    <?php endfor; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-sm-6">
                                                                <label class="form-label fw-semibold small text-muted">Recommendation</label>
                                                                <select class="form-select form-select-sm" name="recommendation">
                                                                    <option value="">Choose...</option>
                                                                    <option value="pursue" <?= ($review['recommendation'] ?? '') === 'pursue' ? 'selected' : ''; ?>>Recommend for Pursuit</option>
                                                                    <option value="revise" <?= ($review['recommendation'] ?? '') === 'revise' ? 'selected' : ''; ?>>Needs Revision</option>
                                                                    <option value="reject" <?= ($review['recommendation'] ?? '') === 'reject' ? 'selected' : ''; ?>>Not Recommended</option>
                                                                </select>
                                                                <div class="form-check mt-2">
                                                                    <input class="form-check-input" type="checkbox" name="is_preferred" id="preferred<?= (int)$item['assignment_id']; ?>" <?= !empty($review['is_preferred']) ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label small" for="preferred<?= (int)$item['assignment_id']; ?>">
                                                                        Mark as preferred concept
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Comments &amp; Suggestions</label>
                                                            <textarea class="form-control" name="comment_suggestions" rows="4" placeholder="Provide detailed comments or suggestions for this candidate" required><?= htmlspecialchars($review['comment_suggestions'] ?? ($review['notes'] ?? '')); ?></textarea>
                                                        </div>
                                                        <div class="form-check form-switch mb-3">
                                                            <input class="form-check-input" type="checkbox" role="switch" id="interest<?= (int)$item['assignment_id']; ?>" name="adviser_interest" value="1" <?= !empty($review['adviser_interest']) ? 'checked' : ''; ?>>
                                                            <label class="form-check-label" for="interest<?= (int)$item['assignment_id']; ?>">
                                                                Interested in mentoring the candidate as a <strong>Thesis Adviser</strong>? (Please check)
                                                            </label>
                                                        </div>
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
                                                                <a href="view_concept.php?id=<?= (int)$item['concept_paper_id']; ?>" class="small text-decoration-none">Preview concept</a>
                                                            </div>
                                                            <button type="submit" class="btn btn-success">
                                                                <i class="bi bi-save"></i> Save Review
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
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



