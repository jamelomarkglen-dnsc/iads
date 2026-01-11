<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';

$allowedRoles = [
    'program_chairperson',
    'committee_chairperson',
    'committee_chair',
    'faculty',
    'panel',
    'adviser',
];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

ensureConceptReviewTables($conn);
ensureConceptReviewMessagesTable($conn);

$limitInput = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = max(5, min(50, $limitInput));
$roleFilter = isset($_GET['role']) ? strtolower(trim((string)$_GET['role'])) : 'all';

$roleOptions = [
    'all' => 'All roles',
    'adviser' => 'Adviser',
    'faculty' => 'Faculty',
    'panel' => 'Panel',
    'committee_chair' => 'Committee Chair',
];

$ownFeedbackOnly = in_array($role, ['faculty', 'panel', 'adviser'], true);

$alert = ['type' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_feedback_message'])) {
    $assignmentId = (int)($_POST['assignment_id'] ?? 0);
    $conceptId = (int)($_POST['concept_id'] ?? 0);
    $studentIdFromPost = (int)($_POST['student_id'] ?? 0);
    $messageText = trim((string)($_POST['conversation_message'] ?? ''));

    if ($assignmentId <= 0 || $conceptId <= 0 || $studentIdFromPost <= 0) {
        $alert = ['type' => 'danger', 'message' => 'Missing assignment details for this conversation.'];
    } elseif ($messageText === '') {
        $alert = ['type' => 'warning', 'message' => 'Please add a short message before sending.'];
    } else {
        $assignmentStmt = $conn->prepare("
            SELECT concept_paper_id, student_id
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
            (int)($assignmentRow['student_id'] ?? 0) !== $studentIdFromPost
        ) {
            $alert = ['type' => 'danger', 'message' => 'Unable to post a message for that assignment.'];
        } else {
            $messageSaved = saveConceptReviewMessage($conn, [
                'assignment_id' => $assignmentId,
                'concept_paper_id' => $conceptId,
                'student_id' => $studentIdFromPost,
                'sender_id' => $reviewerId,
                'sender_role' => $role,
                'message' => $messageText,
            ]);
            if ($messageSaved) {
                $alert = ['type' => 'success', 'message' => 'Message sent successfully.'];
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to send your message right now.'];
            }
        }
    }
}

$feedbackList = fetchRemainingReviewerFeedback($conn, $limit, $ownFeedbackOnly ? $reviewerId : null);
if ($roleFilter !== 'all' && array_key_exists($roleFilter, $roleOptions)) {
    $feedbackList = array_values(array_filter(
        $feedbackList,
        static fn(array $row): bool => strtolower((string)($row['reviewer_role'] ?? '')) === $roleFilter
    ));
}

$assignmentIds = array_values(array_filter(array_map(
    static fn(array $row): int => (int)($row['assignment_id'] ?? 0),
    $feedbackList
)));
$conversationLookup = !empty($assignmentIds)
    ? fetchConceptReviewMessagesByAssignments($conn, $assignmentIds)
    : [];

$roleStats = [];
foreach ($feedbackList as &$feedback) {
    $assignmentId = (int)($feedback['assignment_id'] ?? 0);
    if ($assignmentId > 0) {
        $feedback['messages'] = $conversationLookup[$assignmentId] ?? [];
    } else {
        $feedback['messages'] = [];
    }
    $roleKey = strtolower((string)($feedback['reviewer_role'] ?? 'faculty'));
    $roleStats[$roleKey] = ($roleStats[$roleKey] ?? 0) + 1;
}
unset($feedback);

$totalEntries = count($feedbackList);
$latestFeedback = array_slice($feedbackList, 0, 3);

$globalFeedbackTotal = 0;
if ($ownFeedbackOnly) {
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM reviewer_invite_feedback WHERE reviewer_id = ?");
    if ($countStmt) {
        $countStmt->bind_param('i', $reviewerId);
        if ($countStmt->execute()) {
            $countResult = $countStmt->get_result();
            if ($countRow = $countResult->fetch_assoc()) {
                $globalFeedbackTotal = (int)($countRow['total'] ?? 0);
            }
        }
        $countStmt->close();
    }
} else {
    $countResult = $conn->query("SELECT COUNT(*) AS total FROM reviewer_invite_feedback");
    if ($countResult) {
        $countRow = $countResult->fetch_assoc();
        $globalFeedbackTotal = (int)($countRow['total'] ?? 0);
        $countResult->free();
    }
}

function facultyReadableDateTime(?string $dateTime): string
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

$reviewerDashboardLink = null;
if (in_array($role, ['faculty', 'panel', 'adviser'], true)) {
    $reviewerDashboardLink = 'subject_specialist_dashboard.php';
} elseif (in_array($role, ['committee_chair', 'committee_chairperson'], true)) {
    $reviewerDashboardLink = 'committee_chair_dashboard.php';
}

$quickActions = [
    [
        'icon' => 'person-plus',
        'label' => 'Assign Faculty Reviewers',
        'description' => 'Route a new subject specialist or adviser.',
        'url' => 'assign_faculty.php',
    ],
    [
        'icon' => 'diagram-3',
        'label' => 'Coordinate Panel Members',
        'description' => 'Balance panel loads before scheduling.',
        'url' => 'assign_panel.php',
    ],
    [
        'icon' => 'journal-text',
        'label' => 'Review Formal Submissions',
        'description' => 'Open the reviewer workspace for documents.',
        'url' => 'review_submission.php',
    ],
];

if ($reviewerDashboardLink !== null) {
    $quickActions[] = [
        'icon' => 'stars',
        'label' => 'Reviewer Dashboard',
        'description' => 'Jump to the ranking & mentoring console.',
        'url' => $reviewerDashboardLink,
    ];
}

$pageTitle = 'Faculty Management - Reviewer Feedback';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f5f7fb; }
        .content {
            margin-left: 220px;
            padding: 2rem 2.5rem;
            transition: margin-left 0.3s ease;
        }
        #sidebar.collapsed ~ .content {
            margin-left: 70px;
        }
        .hero-card, .quick-actions-card, .feedback-card {
            border: none;
            border-radius: 22px;
            box-shadow: 0 15px 40px rgba(15, 61, 31, 0.08);
        }
        .hero-card .kpi-card {
            border-radius: 16px;
            padding: 1rem 1.25rem;
            background: rgba(255,255,255,0.85);
            box-shadow: inset 0 0 0 1px rgba(15, 61, 31, 0.08);
        }
        .hero-card .kpi-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6c757d;
        }
        .hero-card .kpi-value {
            font-size: 1.75rem;
            font-weight: 700;
        }
        .quick-actions-card .list-group-item {
            border: none;
            border-radius: 14px;
            padding: 0.9rem 1rem;
            margin-bottom: 0.35rem;
            background: rgba(15,61,31,0.04);
        }
        .quick-actions-card .list-group-item:hover {
            background: rgba(15,61,31,0.08);
        }
        .filter-card {
            border-radius: 18px;
            border: 1px solid rgba(15,61,31,0.08);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.5);
        }
        .feedback-item {
            border-radius: 20px;
            border: 1px solid rgba(15,61,31,0.08);
            background: #fff;
            box-shadow: 0 12px 30px rgba(15,61,31,0.08);
        }
        .conversation-thread {
            max-height: 240px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        .conversation-bubble {
            border-radius: 12px;
            padding: 0.65rem 0.85rem;
            background: rgba(15,61,31,0.05);
            margin-bottom: 0.75rem;
        }
        .conversation-bubble.self {
            background: rgba(25,135,84,0.15);
            border: 1px solid rgba(25,135,84,0.3);
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
        .stat-card {
            border-radius: 18px;
            border: 1px solid rgba(15,61,31,0.08);
            padding: 1.1rem 1.25rem;
            background: #fff;
        }
        @media (max-width: 992px) {
            .content {
                margin-left: 0;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-8">
            <div class="card hero-card h-100">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                        <div>
                            <p class="text-uppercase small text-muted mb-1">Faculty Management</p>
                            <h2 class="fw-bold mb-2">Reviewer Feedback</h2>
                            <p class="text-muted mb-0">
                                Monitor faculty responses when invitations are declined or when mentors request additional context.
                            </p>
                        </div>
                        <div class="text-lg-end">
                            <span class="badge bg-success-subtle text-success rounded-pill px-3 py-2">
                                <?= number_format($globalFeedbackTotal); ?> entries tracked
                            </span>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Queue</span>
                                <div class="kpi-value"><?= number_format($totalEntries); ?></div>
                                <small class="text-muted">Showing latest <?= number_format($limit); ?></small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Unique Roles</span>
                                <div class="kpi-value text-success"><?= number_format(count($roleStats)); ?></div>
                                <small class="text-muted">Active reviewer types</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <span class="kpi-label">Adviser Input</span>
                                <div class="kpi-value text-warning"><?= number_format($roleStats['adviser'] ?? 0); ?></div>
                                <small class="text-muted">Mentor declines</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="kpi-card">
                                <span class="kpi-label"><?= $ownFeedbackOnly ? 'My Logs' : 'Chair Follow-ups'; ?></span>
                                <div class="kpi-value text-primary">
                                    <?= number_format(
                                        $ownFeedbackOnly
                                            ? $globalFeedbackTotal
                                            : ($roleStats['committee_chair'] ?? 0)
                                    ); ?>
                                </div>
                                <small class="text-muted"><?= $ownFeedbackOnly ? 'Personal queue' : 'Chair / panel notes'; ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="card quick-actions-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <p class="text-uppercase small text-muted mb-1">Take Action</p>
                            <h5 class="mb-0">Quick Navigation</h5>
                        </div>
                        <span class="badge bg-success-subtle text-success"><?= number_format(count($quickActions)); ?> links</span>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($quickActions as $action): ?>
                            <a class="list-group-item d-flex justify-content-between align-items-start" href="<?= htmlspecialchars($action['url']); ?>">
                                <div>
                                    <div class="fw-semibold">
                                        <i class="bi bi-<?= htmlspecialchars($action['icon']); ?> me-2"></i><?= htmlspecialchars($action['label']); ?>
                                    </div>
                                    <small class="text-muted"><?= htmlspecialchars($action['description']); ?></small>
                                </div>
                                <i class="bi bi-arrow-up-right"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($alert['message']): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']); ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($alert['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card filter-card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small text-muted">Rows</label>
                    <select class="form-select" name="limit">
                        <?php foreach ([10, 20, 30, 40, 50] as $rowOption): ?>
                            <option value="<?= $rowOption; ?>" <?= $rowOption === $limit ? 'selected' : ''; ?>><?= $rowOption; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small text-muted">Reviewer Role</label>
                    <select class="form-select" name="role">
                        <?php foreach ($roleOptions as $key => $label): ?>
                            <option value="<?= $key; ?>" <?= $key === $roleFilter ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small text-muted">&nbsp;</label>
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-funnel"></i> Apply Filters
                    </button>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small text-muted">&nbsp;</label>
                    <a href="faculty_reviewer_feedback.php" class="btn btn-outline-secondary w-100">
                        Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <?php if (empty($feedbackList)): ?>
                <div class="feedback-card p-5 text-center text-muted">
                    <i class="bi bi-emoji-smile fs-1 d-block mb-2"></i>
                    Everything looks clear—no reviewer feedback to process right now.
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($feedbackList as $feedback): ?>
                        <?php
                            $assignmentId = (int)($feedback['assignment_id'] ?? 0);
                            $studentId = (int)($feedback['student_id'] ?? 0);
                            $conceptPaperId = (int)($feedback['concept_paper_id'] ?? 0);
                            $messages = $feedback['messages'];
                            $conversationDisabled = ($assignmentId <= 0 || $conceptPaperId <= 0 || $studentId <= 0);
                        ?>
                        <div class="col-12">
                            <div class="feedback-item p-4">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3 mb-3">
                                    <div>
                                        <h5 class="mb-1"><?= htmlspecialchars($feedback['student_name'] ?? 'Student'); ?></h5>
                                        <p class="text-muted mb-1"><?= htmlspecialchars($feedback['concept_title'] ?? 'No concept title recorded'); ?></p>
                                        <span class="badge bg-success-subtle text-success">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $feedback['reviewer_role'] ?? 'faculty'))); ?>
                                        </span>
                                        <span class="badge bg-light text-muted">Assignment #<?= number_format($assignmentId); ?></span>
                                    </div>
                                    <div class="text-md-end">
                                        <div class="fw-semibold"><?= htmlspecialchars($feedback['reviewer_name'] ?? 'Reviewer'); ?></div>
                                        <small class="text-muted d-block"><?= htmlspecialchars($feedback['reviewer_email'] ?? ''); ?></small>
                                        <small class="text-muted d-block">Received <?= htmlspecialchars(facultyReadableDateTime($feedback['created_at'] ?? '')); ?></small>
                                    </div>
                                </div>
                                <div class="mb-3 p-3 bg-light-subtle rounded-3 text-dark">
                                    <?= nl2br(htmlspecialchars($feedback['reason'] ?? 'No additional notes provided.')); ?>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mb-4">
                                    <a href="assign_faculty.php<?= $studentId ? '?student_id=' . (int)$studentId : ''; ?>" class="btn btn-outline-success btn-sm">
                                        <i class="bi bi-person-plus"></i> Assign Faculty
                                    </a>
                                    <a href="assign_panel.php<?= $studentId ? '?student_id=' . (int)$studentId : ''; ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-diagram-3"></i> Assign Panel
                                    </a>
                                    <a href="subject_specialist_dashboard.php" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-stars"></i> Reviewer Dashboard
                                    </a>
                                </div>
                                <div class="conversation-thread border-top pt-3 mb-3">
                                    <?php if (empty($messages)): ?>
                                        <div class="text-muted small text-center">
                                            <i class="bi bi-chat-dots me-1"></i> No conversation yet. Start by leaving a note below.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($messages as $message): ?>
                                            <?php $isSelf = (int)($message['sender_id'] ?? 0) === $reviewerId; ?>
                                            <div class="conversation-bubble<?= $isSelf ? ' self' : ''; ?>">
                                                <div class="conversation-meta mb-1">
                                                    <?= htmlspecialchars($isSelf ? 'You' : (trim((string)($message['sender_name'] ?? 'Program Chair')) ?: 'Program Chair')); ?>
                                                    &middot; <?= htmlspecialchars(facultyReadableDateTime($message['created_at'] ?? '')); ?>
                                                </div>
                                                <div><?= nl2br(htmlspecialchars($message['message'] ?? '')); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($conversationDisabled): ?>
                                    <div class="alert alert-warning py-2">
                                        Conversation is unavailable for this record because it is not tied to a reviewer assignment yet.
                                    </div>
                                <?php else: ?>
                                    <form method="POST" class="conversation-form">
                                        <input type="hidden" name="send_feedback_message" value="1">
                                        <input type="hidden" name="assignment_id" value="<?= $assignmentId; ?>">
                                        <input type="hidden" name="concept_id" value="<?= $conceptPaperId; ?>">
                                        <input type="hidden" name="student_id" value="<?= $studentId; ?>">
                                        <div class="mb-2">
                                            <textarea class="form-control" name="conversation_message" rows="2" placeholder="Share an update, question, or next step for this reviewer." required></textarea>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted">Visible to the assigned reviewer and Program Chair.</small>
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="bi bi-send"></i> Send Message
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-12 col-lg-4">
            <div class="stat-card mb-4">
                <h6 class="text-uppercase text-muted small mb-3">Role breakdown</h6>
                <?php if (empty($roleStats)): ?>
                    <p class="text-muted mb-0">No reviewer roles in the current filter.</p>
                <?php else: ?>
                    <?php foreach ($roleStats as $roleKey => $count): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $roleKey))); ?></span>
                            <span class="badge bg-light text-success"><?= number_format($count); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <h6 class="text-uppercase text-muted small mb-3">Latest entries</h6>
                <?php if (empty($latestFeedback)): ?>
                    <p class="text-muted mb-0">No activity yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($latestFeedback as $recent): ?>
                            <?php
                                $recentReason = trim((string)($recent['reason'] ?? ''));
                                if (strlen($recentReason) > 120) {
                                    $recentReason = substr($recentReason, 0, 117) . '...';
                                }
                            ?>
                            <li class="mb-3">
                                <div class="fw-semibold"><?= htmlspecialchars($recent['student_name'] ?? 'Student'); ?></div>
                                <small class="text-muted d-block"><?= htmlspecialchars($recent['reviewer_name'] ?? 'Reviewer'); ?> · <?= htmlspecialchars(facultyReadableDateTime($recent['created_at'] ?? '')); ?></small>
                                <p class="small text-muted mb-0"><?= htmlspecialchars($recentReason); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
