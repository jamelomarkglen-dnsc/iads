<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'defense_outcome_helpers.php';

enforce_role_access(['student']);

ensureDefenseOutcomeTable($conn);

$studentId = (int)($_SESSION['user_id'] ?? 0);
$outcome = fetch_latest_defense_outcome($conn, $studentId);

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Defense Outcome</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card { border-radius: 18px; border: none; box-shadow: 0 16px 32px rgba(22, 86, 44, 0.1); }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-mortarboard me-2"></i>Defense Outcome</h3>
                <p class="text-muted mb-0">View the latest outcome of your defense.</p>
            </div>
        </div>

        <div class="card p-4">
            <?php if (!$outcome): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-info-circle fs-2 mb-2"></i>
                    <p class="mb-0">No defense outcome has been recorded yet.</p>
                </div>
            <?php else: ?>
                <?php
                    $dateLabel = !empty($outcome['defense_date']) ? date('M d, Y', strtotime($outcome['defense_date'])) : 'TBA';
                    $timeLabel = !empty($outcome['defense_time']) && $outcome['defense_time'] !== '00:00:00'
                        ? ' • ' . date('g:i A', strtotime($outcome['defense_time']))
                        : '';
                ?>
                <div class="mb-3">
                    <div class="text-muted small">Defense schedule</div>
                    <div class="fw-semibold text-success"><?= htmlspecialchars($dateLabel . $timeLabel); ?></div>
                    <?php if (!empty($outcome['venue'])): ?>
                        <div class="text-muted small"><?= htmlspecialchars($outcome['venue']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <div class="text-muted small">Outcome</div>
                    <span class="badge <?= defense_outcome_badge_class($outcome['outcome'] ?? ''); ?>">
                        <?= htmlspecialchars($outcome['outcome'] ?? ''); ?>
                    </span>
                </div>
                <?php if (!empty($outcome['notes'])): ?>
                    <div class="mb-0">
                        <div class="text-muted small">Notes</div>
                        <div><?= nl2br(htmlspecialchars($outcome['notes'])); ?></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
