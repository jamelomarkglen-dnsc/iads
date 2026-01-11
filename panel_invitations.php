<?php
require_once 'panel_context.php';
include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Panel Invitations - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .stats-card { border-radius: 18px; background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; padding: 1.5rem; box-shadow: 0 18px 36px rgba(22,86,44,0.14); }
        .stats-card small { color: rgba(255,255,255,0.7); }
        .invite-letter-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22,86,44,0.08); }
        .invite-letter-card .card-header { background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; border-radius: 18px 18px 0 0; }
        .invite-letter-card .card-body { background: #fff; }
        .invitation-letter { background: #f8fff4; border: 1px solid #d8f1d5; border-radius: 12px; padding: 1rem 1.25rem; font-size: .95rem; line-height: 1.55; color: #0f3d1f; }
        .invitation-letter p:last-child { margin-bottom: 0; }
        .empty-state { text-align: center; padding: 48px 16px; color: #6c757d; }
        .empty-state i { font-size: 2.8rem; color: #16562c; margin-bottom: .75rem; display: block; }
        .recent-list .list-group-item { border: none; border-bottom: 1px solid #f1f2f5; padding: 1rem 0; }
        .recent-list .list-group-item:last-child { border-bottom: none; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
            <div>
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-envelope-open me-2"></i>Defense Invitations</h3>
                <p class="mb-0 text-muted">Review formal invitations from the Program Chairperson and confirm your participation.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="panel.php#invitations" class="btn btn-outline-secondary"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="panel_schedule.php" class="btn btn-outline-primary"><i class="bi bi-calendar-week"></i> Schedule</a>
                <a href="calendar.php" class="btn btn-outline-success"><i class="bi bi-calendar3"></i> Calendar</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php foreach (['pending' => 'Pending', 'accepted' => 'Accepted', 'scheduled' => 'Confirmed', 'completed' => 'Completed'] as $key => $label): ?>
                <div class="col-md-3">
                    <div class="stats-card h-100">
                        <small><?= htmlspecialchars($label); ?></small>
                        <h2 class="fw-bold mb-0"><?= number_format($stats[$key] ?? 0); ?></h2>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card invite-letter-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-text me-2"></i>Invitation Letters</span>
                <span class="badge bg-light text-dark">Pending: <?= number_format($stats['pending'] ?? 0); ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($pendingInvites)): ?>
                    <div class="empty-state">
                        <i class="bi bi-envelope-exclamation"></i>
                        <p class="mb-0">No pending invitations for now. You will be notified once a program chair assigns you.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($pendingInvites as $invite): ?>
                            <?php
                                $scheduleLabel = panel_format_schedule_label($invite['defense_date'] ?? null, $invite['defense_time'] ?? null);
                                $letterText = panel_build_invitation_letter($invite, $programChairInfo, $panelProfile['display_name']);
                                $letterParagraphs = preg_split("/\n{2,}/", $letterText) ?: [$letterText];
                                $title = $invite['submission_title'] ?? 'Research Title';
                            ?>
                            <div class="col-lg-6">
                                <div class="border rounded-4 shadow-sm h-100 p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <p class="text-muted small mb-1">From: <?= htmlspecialchars($programChairInfo['full_name'] ?? 'Program Chairperson'); ?></p>
                                            <h5 class="fw-semibold mb-1"><?= htmlspecialchars($title); ?></h5>
                                            <div class="text-muted small">Student: <?= htmlspecialchars($invite['student_name']); ?></div>
                                        </div>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i> Awaiting response</span>
                                    </div>
                                    <p class="mb-1"><strong>Defense Schedule:</strong> <?= htmlspecialchars($scheduleLabel); ?></p>
                                    <p class="mb-3 text-muted small"><strong>Venue:</strong> <?= htmlspecialchars($invite['venue'] ?? 'To be announced'); ?></p>
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

        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check2-all me-2"></i>Recent Commitments</span>
                <a href="panel_schedule.php" class="btn btn-sm btn-outline-success">View full schedule</a>
            </div>
            <div class="card-body recent-list">
                <?php if (empty($acceptedInvites)): ?>
                    <div class="empty-state py-4">
                        <i class="bi bi-clipboard-check"></i>
                        <p class="mb-0">Accept an invitation to see your confirmed defenses here.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach (array_slice($acceptedInvites, 0, 4) as $assignment): ?>
                            <?php
                                $scheduleLabel = panel_format_schedule_label($assignment['defense_date'] ?? null, $assignment['defense_time'] ?? null);
                                $status = $assignment['status'] ?? 'Pending';
                                $badgeClass = $status === 'Confirmed'
                                    ? 'bg-success-subtle text-success'
                                    : ($status === 'Completed' ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary');
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1 text-success"><?= htmlspecialchars($assignment['student_name']); ?></h6>
                                    <p class="mb-1 small text-muted"><?= htmlspecialchars($assignment['submission_title'] ?? 'Research Title'); ?></p>
                                    <small><i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($scheduleLabel); ?></small>
                                </div>
                                <span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="panel_invitation_actions.js"></script>
</body>
</html>
