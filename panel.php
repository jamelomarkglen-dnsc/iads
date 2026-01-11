<?php
require_once 'panel_context.php';
require_once 'concept_review_helpers.php';
ensureConceptReviewTables($conn);
$panelReviewerAssignments = fetchReviewerAssignments($conn, (int)($panelProfile['id'] ?? 0), 'panel');
$panelReviewerStats = summarizeReviewerAssignments($panelReviewerAssignments);
$panelReviewerGroups = groupReviewerAssignmentsByStudent($panelReviewerAssignments);
$panelReviewerDueSoon = filterDueSoonReviewerAssignments($panelReviewerAssignments);
$panelRankingProgress = summarizeReviewerRankingProgress($panelReviewerAssignments);
$panelTopPicks = array_slice($panelRankingProgress['top_picks'], 0, 3);
$panelRankingCompletion = ($panelReviewerStats['total'] ?? 0) > 0
    ? round(($panelRankingProgress['ranked'] / $panelReviewerStats['total']) * 100)
    : 0;
include 'header.php';
include 'sidebar.php';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Panel Dashboard - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .stat-card { border-radius: 18px; background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; box-shadow: 0 16px 32px rgba(22,86,44,0.14); }
        .stat-card small { color: rgba(255,255,255,0.7); }
        .invite-card { border-radius: 16px; box-shadow: 0 16px 32px rgba(22,86,44,0.08); border: none; }
        .invite-card .card-header { background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; border-radius: 16px 16px 0 0; }
        .schedule-card { border-left: 6px solid #16562c; border-radius: 14px; box-shadow: 0 12px 24px rgba(22,86,44,0.08); transition: transform .2s; }
        .schedule-card:hover { transform: translateY(-2px); }
        .badge-status { font-size: .85rem; }
        .empty-state { text-align: center; padding: 48px 16px; color: #6c757d; }
        .empty-state i { font-size: 2.8rem; color: #16562c; margin-bottom: .75rem; }
        .invitation-letter { background: #f8fff4; border: 1px solid #d8f1d5; border-radius: 12px; padding: 1rem 1.25rem; font-size: .95rem; line-height: 1.55; color: #0f3d1f; }
        .invitation-letter p:last-child { margin-bottom: 0; }
        .review-card { border-radius: 16px; box-shadow: 0 16px 32px rgba(22,86,44,0.08); border: none; }
        .review-assignment { border-bottom: 1px solid rgba(0,0,0,0.05); padding: 0.85rem 0; }
        .review-assignment:last-child { border-bottom: none; }
        .review-assignment .title-list { font-size: 0.9rem; color: #6b7568; }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between flex-wrap align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1 text-success"><i class="bi bi-speedometer2 me-2"></i>Panel Dashboard</h3>
                <p class="text-muted mb-0">Review invitations, confirm participation, and monitor scheduled defenses.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="calendar.php" class="btn btn-outline-primary"><i class="bi bi-calendar3"></i> Calendar</a>
                <a href="notifications.php" class="btn btn-outline-success"><i class="bi bi-bell"></i> Notifications</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php foreach (['pending' => 'Pending Invitations', 'accepted' => 'Accepted Assignments', 'scheduled' => 'Confirmed Schedules', 'completed' => 'Completed Defenses'] as $key => $label): ?>
                <div class="col-md-3">
                    <div class="card stat-card h-100 p-3">
                        <small><?= htmlspecialchars($label); ?></small>
                        <h3 class="fw-bold mb-0"><?= number_format($stats[$key]); ?></h3>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card review-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="mb-1">Concept Review Assignments</h5>
                                <small class="text-muted">Concept titles routed by the Program Chairperson</small>
                            </div>
                            <a href="subject_specialist_dashboard.php" class="btn btn-sm btn-success">
                                <i class="bi bi-stars me-1"></i> Reviewer Workbench
                            </a>
                        </div>
                        <?php if (empty($panelReviewerGroups)): ?>
                            <div class="empty-state py-4">
                                <i class="bi bi-journal-arrow-up"></i>
                                <p class="mb-0">No concept papers are assigned to you yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach (array_slice($panelReviewerGroups, 0, 4) as $group): ?>
                                <?php
                                    $items = $group['items'] ?? [];
                                    $titles = array_map(fn($item) => $item['title'] ?? 'Untitled Concept', array_slice($items, 0, 3));
                                    $status = count(array_filter($items, fn($item) => ($item['status'] ?? '') !== 'completed')) > 0 ? 'In progress' : 'Completed';
                                    $badgeClass = $status === 'Completed' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
                                    $dueLabel = 'No due date';
                                    foreach ($items as $item) {
                                        if (!empty($item['due_at'])) {
                                            $ts = strtotime($item['due_at']);
                                            if ($ts && ($dueLabel === 'No due date' || $ts < strtotime($dueLabel))) {
                                                $dueLabel = date('M d, Y', $ts);
                                            }
                                        }
                                    }
                                ?>
                                <div class="review-assignment">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= htmlspecialchars($group['student_name']); ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($group['student_email']); ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge <?= $badgeClass; ?>"><?= $status; ?></span>
                                            <div class="small text-muted"><?= htmlspecialchars($dueLabel); ?></div>
                                        </div>
                                    </div>
                                    <div class="title-list mt-2">
                                        <?php if (!empty($titles)): ?>
                                            <?php foreach ($titles as $title): ?>
                                                <span><?= htmlspecialchars($title); ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($items) > 3): ?>
                                                <span>+<?= count($items) - 3; ?> more</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span>No concept titles uploaded yet.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card review-card h-100">
                    <div class="card-body">
                        <h6 class="text-uppercase text-muted mb-3">Reviewer Snapshot</h6>
                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <small class="text-muted">Assigned</small>
                                <h4 class="mb-0"><?= number_format($panelReviewerStats['total'] ?? 0); ?></h4>
                            </div>
                            <div class="text-end">
                                <small class="text-muted">Completed</small>
                                <h4 class="mb-0 text-success"><?= number_format($panelReviewerStats['completed'] ?? 0); ?></h4>
                            </div>
                        </div>
                        <p class="mb-1 text-muted">In progress: <span class="fw-semibold"><?= number_format($panelReviewerStats['pending'] ?? 0); ?></span></p>
                        <p class="text-muted">Due soon: <span class="fw-semibold text-danger"><?= number_format($panelReviewerStats['due_soon'] ?? 0); ?></span></p>
                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">Ranking progress</small>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $panelRankingCompletion; ?>%;" aria-valuenow="<?= $panelRankingCompletion; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <div class="d-flex justify-content-between small text-muted mt-1">
                                <span><?= number_format($panelRankingProgress['ranked']); ?> ranked</span>
                                <span><?= number_format($panelRankingProgress['pending']); ?> awaiting rank</span>
                            </div>
                        </div>
                        <?php if (!empty($panelReviewerDueSoon)): ?>
                            <?php
                                $next = $panelReviewerDueSoon[0];
                                $dueTs = !empty($next['due_at']) ? strtotime($next['due_at']) : null;
                                $dueHuman = $dueTs ? date('M d, Y', $dueTs) : 'No due date';
                            ?>
                            <div class="p-3 rounded bg-light">
                                <small class="text-muted d-block">Next deadline</small>
                                <strong><?= htmlspecialchars($next['student_name'] ?? 'Student'); ?></strong>
                                <div class="text-muted small"><?= htmlspecialchars($dueHuman); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="p-3 rounded bg-light text-muted text-center">
                                <i class="bi bi-calendar2-check"></i>
                                <p class="mb-0 small">No upcoming reviewer deadlines.</p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($panelTopPicks)): ?>
                            <div class="border-top pt-3 mt-3">
                                <small class="text-muted d-block mb-2">Top ranked concepts</small>
                                <?php foreach ($panelTopPicks as $pick): ?>
                                    <div class="rounded-3 bg-light p-2 mb-2">
                                        <strong><?= htmlspecialchars($pick['title'] ?? 'Untitled Concept'); ?></strong>
                                        <div class="small text-muted"><?= htmlspecialchars($pick['student_name'] ?? 'Student'); ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($panelRankingProgress['top'] > count($panelTopPicks)): ?>
                                    <small class="text-muted">...and <?= number_format($panelRankingProgress['top'] - count($panelTopPicks)); ?> more marked as top choice.</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card invite-card mb-4" id="invitations">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-envelope-open me-2"></i>Panel Invitations</span>
                <span class="badge bg-light text-dark">Pending: <?= number_format($stats['pending']); ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($pendingInvites)): ?>
                    <div class="empty-state py-4">
                        <i class="bi bi-inboxes"></i>
                        <p class="mb-0">No pending invitations at the moment.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($pendingInvites as $invite): ?>
                            <?php
                                $scheduleLabel = panel_format_schedule_label($invite['defense_date'] ?? null, $invite['defense_time'] ?? null);
                                $title = $invite['submission_title'] ?? 'Defense Title';
                                $letterText = panel_build_invitation_letter($invite, $programChairInfo, $panelProfile['display_name']);
                                $letterParagraphs = preg_split("/\n{2,}/", $letterText) ?: [$letterText];
                            ?>
                            <div class="col-lg-6">
                                <div class="border rounded-4 shadow-sm p-4 h-100">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <span class="badge bg-warning text-dark mb-2"><i class="bi bi-hourglass-split me-1"></i> Awaiting response</span>
                                            <h5 class="fw-semibold mb-1"><?= htmlspecialchars($title); ?></h5>
                                            <div class="text-muted small">Student: <?= htmlspecialchars($invite['student_name']); ?></div>
                                        </div>
                                        <i class="bi bi-envelope fs-3 text-warning"></i>
                                    </div>
                                    <p class="mb-1"><strong>Schedule:</strong> <?= htmlspecialchars($scheduleLabel); ?></p>
                                    <div class="invitation-letter mb-3">
                                        <?php foreach ($letterParagraphs as $paragraph): ?>
                                            <p><?= htmlspecialchars($paragraph); ?></p>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="d-flex flex-column flex-sm-row gap-2">
                                        <button class="btn btn-success flex-fill respondBtn" data-id="<?= $invite['panel_entry_id']; ?>" data-response="Accepted">
                                            <i class="bi bi-check2-circle me-1"></i> Accept
                                        </button>
                                        <button class="btn btn-outline-secondary flex-fill respondBtn" data-id="<?= $invite['panel_entry_id']; ?>" data-response="Declined">
                                            <i class="bi bi-x-circle me-1"></i> Decline
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm" id="schedule">
            <div class="card-header d-flex justify-content-between alignments-center">
                <span><i class="bi bi-calendar-event me-2"></i>Accepted Defense Schedule</span>
                <input type="search" id="scheduleSearch" class="form-control form-control-sm w-auto" placeholder="Search schedule...">
            </div>
            <div class="card-body">
                <?php if (empty($acceptedInvites)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar2-x"></i>
                        <p class="mb-0">No accepted defenses yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($acceptedInvites as $assignment): ?>
                        <?php
                            $scheduleLabel = panel_format_schedule_label($assignment['defense_date'] ?? null, $assignment['defense_time'] ?? null);
                            $status = $assignment['status'] ?? 'Pending';
                            $badgeClass = $status === 'Confirmed' ? 'bg-success' : ($status === 'Completed' ? 'bg-primary' : 'bg-secondary');
                        ?>
                        <div class="schedule-card bg-white p-4 mb-4" data-search="<?= htmlspecialchars(strtolower(($assignment['student_name'] ?? '') . ' ' . ($assignment['submission_title'] ?? '') . ' ' . $status)); ?>">
                            <div class="d-flex justify-content-between alignments-center mb-2">
                                <h5 class="mb-0 text-success">
                                    <i class="bi bi-mortarboard me-2"></i><?= htmlspecialchars($assignment['student_name']); ?>
                                    — <?= htmlspecialchars($assignment['submission_title'] ?? 'Research Title'); ?>
                                </h5>
                                <span class="badge <?= $badgeClass; ?> badge-status"><?= htmlspecialchars($status); ?></span>
                            </div>
                            <p class="mb-1"><strong>Date:</strong> <?= htmlspecialchars($scheduleLabel); ?></p>
                            <p class="mb-0"><strong>Venue:</strong> <?= htmlspecialchars($assignment['venue'] ?? 'To be announced'); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="panel_invitation_actions.js"></script>
</body>
</html>
