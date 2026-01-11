<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';
require_once 'notifications_helper.php';

$allowedRoles = ['adviser', 'panel', 'committee_chair', 'committee_chairperson'];
$role = $_SESSION['role'] ?? '';
$reviewerId = (int)($_SESSION['user_id'] ?? 0);

if (!$reviewerId || !in_array($role, $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureConceptReviewTables($conn);
if ($role === 'adviser') {
    syncAdviserAssignmentsFromUserLinks($conn, $reviewerId);
}

function reviewerInboxStatusClass(string $status): string
{
    return match ($status) {
        'completed' => 'bg-success-subtle text-success',
        'in_progress' => 'bg-primary-subtle text-primary',
        'declined' => 'bg-danger-subtle text-danger',
        default => 'bg-secondary-subtle text-secondary',
    };
}

function reviewerInboxFormatDate(?string $value): string
{
    if (!$value) {
        return 'Not set';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('M d, Y', $timestamp) : $value;
}

$feedback = ['type' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assignment_action'])) {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $action = $_POST['assignment_action'];
    $declineReason = trim($_POST['decline_reason'] ?? '');

    $assignmentStmt = $conn->prepare("
        SELECT cra.id, cra.student_id, cra.reviewer_id, cra.reviewer_role, cra.status,
               cp.title AS concept_title,
               CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name
        FROM concept_reviewer_assignments cra
        LEFT JOIN concept_papers cp ON cp.id = cra.concept_paper_id
        LEFT JOIN users u ON u.id = cra.student_id
        WHERE cra.id = ? LIMIT 1
    ");
    if ($assignmentStmt) {
        $assignmentStmt->bind_param('i', $assignmentId);
        $assignmentStmt->execute();
        $assignmentResult = $assignmentStmt->get_result();
        $assignmentRow = $assignmentResult ? $assignmentResult->fetch_assoc() : null;
        $assignmentStmt->close();
    } else {
        $assignmentRow = null;
    }

    if (
        !$assignmentRow ||
        (int)($assignmentRow['reviewer_id'] ?? 0) !== $reviewerId ||
        (($assignmentRow['reviewer_role'] ?? '') !== $role && !($role === 'committee_chairperson' && ($assignmentRow['reviewer_role'] ?? '') === 'committee_chair'))
    ) {
        $feedback = ['type' => 'danger', 'message' => 'Invalid assignment reference.'];
    } else {
        if ($action === 'accept') {
            if (($assignmentRow['status'] ?? '') === 'completed') {
                $feedback = ['type' => 'info', 'message' => 'This review is already completed.'];
            } else {
                $updateStmt = $conn->prepare("
                    UPDATE concept_reviewer_assignments
                    SET status = 'in_progress', decline_reason = NULL, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND reviewer_id = ?
                ");
                if ($updateStmt) {
                    $updateStmt->bind_param('ii', $assignmentId, $reviewerId);
                    if ($updateStmt->execute()) {
                        $feedback = ['type' => 'success', 'message' => 'Assignment accepted. You can now rate the concept titles.'];
                    } else {
                        $feedback = ['type' => 'danger', 'message' => 'Unable to update the assignment status.'];
                    }
                    $updateStmt->close();
                }
            }
        } elseif ($action === 'decline') {
            if ($declineReason === '') {
                $feedback = ['type' => 'danger', 'message' => 'Please provide a reason before declining the assignment.'];
            } else {
                $updateStmt = $conn->prepare("
                    UPDATE concept_reviewer_assignments
                    SET status = 'declined', decline_reason = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND reviewer_id = ?
                ");
                if ($updateStmt) {
                    $updateStmt->bind_param('sii', $declineReason, $assignmentId, $reviewerId);
                    if ($updateStmt->execute()) {
                        $feedback = ['type' => 'success', 'message' => 'Assignment declined. The program chairperson was notified.'];
                        $studentName = trim($assignmentRow['student_name'] ?? 'the student');
                        $conceptTitle = trim($assignmentRow['concept_title'] ?? 'concept set');
                        $reviewerName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
                        if ($reviewerName === '') {
                            $nameStmt = $conn->prepare("SELECT CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, '')) AS reviewer_name FROM users WHERE id = ? LIMIT 1");
                            if ($nameStmt) {
                                $nameStmt->bind_param('i', $reviewerId);
                                $nameStmt->execute();
                                $nameResult = $nameStmt->get_result()->fetch_assoc();
                                $reviewerName = trim($nameResult['reviewer_name'] ?? '');
                                $nameStmt->close();
                            }
                        }
                        if ($reviewerName === '') {
                            $reviewerName = 'A reviewer';
                        }
                        $notifyMessage = "{$reviewerName} declined the reviewer assignment for {$studentName} ({$conceptTitle}). Reason: {$declineReason}";
                        notify_roles(
                            $conn,
                            ['program_chairperson'],
                            'Reviewer declined assignment',
                            $notifyMessage,
                            'assign_faculty.php'
                        );
                    } else {
                        $feedback = ['type' => 'danger', 'message' => 'Unable to decline the assignment at the moment.'];
                    }
                    $updateStmt->close();
                }
            }
        }
    }
}

$assignments = fetchReviewerAssignments($conn, $reviewerId, $role === 'committee_chairperson' ? 'committee_chair' : null);
$pendingAssignments = array_values(array_filter(
    $assignments,
    fn($assignment) => in_array($assignment['status'] ?? 'pending', ['pending', 'declined'], true)
));
$activeAssignments = array_values(array_filter(
    $assignments,
    fn($assignment) => in_array($assignment['status'] ?? '', ['in_progress', 'completed'], true)
));

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reviewer Assignment Inbox</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f5f7fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s ease; }
        #sidebar.collapsed ~ .content { margin-left: 70px; }
        .inbox-card { border-radius: 22px; border: none; box-shadow: 0 18px 36px rgba(15,61,31,.12); }
        .assignment-card { border-radius: 16px; border: 1px solid rgba(22,86,44,.08); padding: 1.25rem; background: #fff; }
        .assignment-card + .assignment-card { margin-top: 1rem; }
        .status-pill { border-radius: 999px; padding: .2rem .75rem; font-size: .85rem; }
        .collapse textarea { resize: vertical; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center mb-4 gap-3">
            <div>
                <p class="text-uppercase small text-muted mb-1">Reviewer Workspace</p>
                <h2 class="fw-bold mb-1">Assignment Inbox</h2>
                <p class="text-muted mb-0">Confirm or decline reviewer invitations before evaluating the concept titles.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="subject_specialist_dashboard.php" class="btn btn-outline-success"><i class="bi bi-stars me-1"></i> Reviewer Dashboard</a>
            </div>
        </div>

        <?php if ($feedback['message']): ?>
            <div class="alert alert-<?= htmlspecialchars($feedback['type'] ?: 'info'); ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($feedback['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card inbox-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="mb-1 text-success">Assignments Awaiting Action</h5>
                                <small class="text-muted"><?= number_format(count($pendingAssignments)); ?> assignment(s) need your confirmation.</small>
                            </div>
                        </div>
                        <p class="text-muted small mb-3">Accept before you start ranking the three concept titles. If you decline, include a short note so the Program Chairperson can re-route the student.</p>
                        <?php if (empty($pendingAssignments)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inboxes fs-1 d-block mb-2"></i>
                                Nothing to confirm right now.
                            </div>
                        <?php else: ?>
                            <?php foreach ($pendingAssignments as $assignment): ?>
                                <?php $statusClass = reviewerInboxStatusClass($assignment['status'] ?? 'pending'); ?>
                                <div class="assignment-card">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($assignment['student_name'] ?? 'Student'); ?></h6>
                                            <p class="mb-0 text-muted"><?= htmlspecialchars($assignment['title'] ?? 'Untitled Concept'); ?></p>
                                            <?php if (!empty($assignment['instructions'])): ?>
                                                <small class="text-muted d-block mt-1"><?= nl2br(htmlspecialchars($assignment['instructions'])); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="status-pill <?= $statusClass; ?> text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $assignment['status'] ?? 'pending')); ?></span>
                                    </div>
                                    <div class="mt-3">
                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                            <form method="POST" onsubmit="return confirm('Accept this reviewer assignment?');">
                                                <input type="hidden" name="assignment_action" value="accept">
                                                <input type="hidden" name="assignment_id" value="<?= (int)$assignment['assignment_id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="bi bi-check2-circle me-1"></i> Accept
                                                </button>
                                            </form>
                                            <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#declineForm<?= (int)$assignment['assignment_id']; ?>">
                                                <i class="bi bi-x-circle me-1"></i> Decline
                                            </button>
                                        </div>
                                        <div class="collapse mt-3" id="declineForm<?= (int)$assignment['assignment_id']; ?>">
                                            <form method="POST" onsubmit="return confirm('Decline this reviewer assignment?');">
                                                <input type="hidden" name="assignment_action" value="decline">
                                                <input type="hidden" name="assignment_id" value="<?= (int)$assignment['assignment_id']; ?>">
                                                <label class="form-label small text-muted">Share a brief reason for declining</label>
                                                <small class="text-muted d-block mb-2">Your message is sent to the Program Chairperson with this assignment.</small>
                                                <textarea class="form-control mb-2" name="decline_reason" rows="2" required placeholder="Explain why you cannot review this set."></textarea>
                                                <button type="submit" class="btn btn-danger btn-sm">Submit Decline</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card inbox-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="mb-1 text-primary">Active & Completed Reviews</h5>
                                <small class="text-muted"><?= number_format(count($activeAssignments)); ?> concept sets in progress.</small>
                            </div>
                        </div>
                        <?php if (empty($activeAssignments)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-journal-check fs-1 d-block mb-2"></i>
                                Active reviews will appear here after you accept an assignment.
                            </div>
                        <?php else: ?>
                            <?php foreach ($activeAssignments as $assignment): ?>
                                <?php $statusClass = reviewerInboxStatusClass($assignment['status'] ?? 'in_progress'); ?>
                                <div class="assignment-card">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($assignment['student_name'] ?? 'Student'); ?></h6>
                                            <p class="mb-0 text-muted"><?= htmlspecialchars($assignment['title'] ?? 'Untitled Concept'); ?></p>
                                            <?php if (!empty($assignment['due_at'])): ?>
                                                <small class="text-muted d-block mt-1"><i class="bi bi-calendar3 me-1"></i>Due <?= htmlspecialchars(reviewerInboxFormatDate($assignment['due_at'])); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="status-pill <?= $statusClass; ?> text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $assignment['status'] ?? 'in_progress')); ?></span>
                                    </div>
                                    <div class="mt-3 d-flex flex-wrap gap-2">
                                        <a href="subject_specialist_dashboard.php" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-pencil-square me-1"></i> Continue Review
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
