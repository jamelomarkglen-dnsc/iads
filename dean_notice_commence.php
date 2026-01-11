<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_paper_helpers.php';
require_once 'defense_committee_helpers.php';
require_once 'notice_commence_helpers.php';

enforce_role_access(['dean']);

$deanId = (int)($_SESSION['user_id'] ?? 0);
$deanName = fetch_user_fullname($conn, $deanId);

ensureFinalPaperTables($conn);
ensureNoticeCommenceTable($conn);

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_notice_commence'])) {
    $noticeId = (int)($_POST['notice_id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));
    $deanNotes = trim((string)($_POST['dean_notes'] ?? ''));

    if ($noticeId <= 0 || !in_array($decision, ['Approved', 'Rejected'], true)) {
        $alert = ['type' => 'danger', 'message' => 'Please select a valid decision.'];
    } else {
        $notice = null;
        $lookupStmt = $conn->prepare("
            SELECT n.id, n.status, n.student_id, n.program_chair_id,
                   fp.final_title,
                   CONCAT(u.firstname, ' ', u.lastname) AS student_name
            FROM notice_to_commence_requests n
            JOIN users u ON u.id = n.student_id
            LEFT JOIN final_paper_submissions fp ON fp.id = n.submission_id
            WHERE n.id = ?
            LIMIT 1
        ");
        if ($lookupStmt) {
            $lookupStmt->bind_param('i', $noticeId);
            $lookupStmt->execute();
            $lookupResult = $lookupStmt->get_result();
            $notice = $lookupResult ? $lookupResult->fetch_assoc() : null;
            $lookupStmt->close();
        }

        if (!$notice) {
            $alert = ['type' => 'danger', 'message' => 'Notice request not found.'];
        } elseif (($notice['status'] ?? '') !== 'Pending') {
            $alert = ['type' => 'warning', 'message' => 'This notice request is already reviewed.'];
        } else {
            $updateStmt = $conn->prepare("
                UPDATE notice_to_commence_requests
                SET status = ?, dean_id = ?, reviewed_at = NOW(), dean_notes = ?
                WHERE id = ?
            ");
            if ($updateStmt) {
                $updateStmt->bind_param('sisi', $decision, $deanId, $deanNotes, $noticeId);
                if ($updateStmt->execute()) {
                    $studentId = (int)($notice['student_id'] ?? 0);
                    $chairId = (int)($notice['program_chair_id'] ?? 0);
                    $studentName = $notice['student_name'] ?? 'the student';
                    $finalTitle = $notice['final_title'] ?? 'the proposal';
                    $viewLink = 'notice_commence_view.php?notice_id=' . $noticeId;

                    if ($decision === 'Approved') {
                        notify_user(
                            $conn,
                            $chairId,
                            'Notice to commence approved',
                            "Dean approved the notice to commence for {$studentName}.",
                            $viewLink
                        );
                        if ($studentId > 0) {
                            notify_user(
                                $conn,
                                $studentId,
                                'Notice to commence approved',
                                "Your notice to commence for \"{$finalTitle}\" is approved. You may now proceed.",
                                $viewLink
                            );
                        }
                        $alert = ['type' => 'success', 'message' => 'Notice approved and notifications sent.'];
                    } else {
                        $noteSuffix = $deanNotes !== '' ? " Notes: {$deanNotes}" : '';
                        notify_user(
                            $conn,
                            $chairId,
                            'Notice to commence rejected',
                            "Dean rejected the notice to commence for {$studentName}.{$noteSuffix}",
                            'notice_to_commence.php'
                        );
                        $alert = ['type' => 'warning', 'message' => 'Notice rejected and the program chair was notified.'];
                    }
                } else {
                    $alert = ['type' => 'danger', 'message' => 'Unable to update the notice request.'];
                }
                $updateStmt->close();
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to prepare the notice update.'];
            }
        }
    }
}

$pendingNotices = [];
$pendingStmt = $conn->prepare("
    SELECT n.id, n.notice_date, n.start_date, n.subject, n.body, n.created_at,
           CONCAT(u.firstname, ' ', u.lastname) AS student_name,
           u.program,
           fp.final_title,
           CONCAT(pc.firstname, ' ', pc.lastname) AS chair_name
    FROM notice_to_commence_requests n
    JOIN users u ON u.id = n.student_id
    LEFT JOIN final_paper_submissions fp ON fp.id = n.submission_id
    LEFT JOIN users pc ON pc.id = n.program_chair_id
    WHERE n.status = 'Pending'
    ORDER BY n.created_at DESC
");
if ($pendingStmt) {
    $pendingStmt->execute();
    $pendingResult = $pendingStmt->get_result();
    if ($pendingResult) {
        $pendingNotices = $pendingResult->fetch_all(MYSQLI_ASSOC);
        $pendingResult->free();
    }
    $pendingStmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notice to Commence Approvals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8f5; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .hero-card { border-radius: 20px; background: linear-gradient(130deg, #144d28, #0c341c); color: #fff; padding: 24px; }
        .notice-body { white-space: pre-wrap; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <h3 class="fw-bold mb-1">Notice to Commence Approvals</h3>
            <p class="mb-0 text-white-50">Review and approve notices sent by the program chair.</p>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']); ?>">
                <?= htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="card card-shell p-4">
            <h5 class="fw-bold text-success mb-3">Pending Requests</h5>
            <?php if (empty($pendingNotices)): ?>
                <p class="text-muted mb-0">No pending notice requests at the moment.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-4">
                    <?php foreach ($pendingNotices as $notice): ?>
                        <?php
                        $noticeId = (int)($notice['id'] ?? 0);
                        $noticeDate = $notice['notice_date'] ? notice_commence_format_date($notice['notice_date']) : 'Date not set';
                        $startDate = $notice['start_date'] ? notice_commence_format_date($notice['start_date']) : 'Start date not set';
                        $studentName = $notice['student_name'] ?? 'Student';
                        $chairName = $notice['chair_name'] ?? 'Program Chairperson';
                        ?>
                        <div class="border rounded-3 p-3">
                            <div class="d-flex justify-content-between flex-wrap gap-2">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($studentName); ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($notice['final_title'] ?? ''); ?></div>
                                    <div class="small text-muted">Program Chair: <?= htmlspecialchars($chairName); ?></div>
                                </div>
                                <div class="text-end small text-muted">
                                    <div>Notice Date: <?= htmlspecialchars($noticeDate); ?></div>
                                    <div>Start Date: <?= htmlspecialchars($startDate); ?></div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <div class="small text-muted fw-semibold">Subject</div>
                                <div><?= htmlspecialchars($notice['subject'] ?? ''); ?></div>
                            </div>
                            <div class="mt-3 notice-body">
                                <?= nl2br(htmlspecialchars($notice['body'] ?? '')); ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                                <a href="notice_commence_view.php?notice_id=<?= $noticeId; ?>" target="_blank" class="text-success text-decoration-none">
                                    <i class="bi bi-file-earmark-text me-1"></i>View full notice
                                </a>
                                <form method="post" class="d-flex flex-column gap-2">
                                    <input type="hidden" name="review_notice_commence" value="1">
                                    <input type="hidden" name="notice_id" value="<?= $noticeId; ?>">
                                    <textarea name="dean_notes" class="form-control form-control-sm" rows="2" placeholder="Optional notes for the program chair..."></textarea>
                                    <div class="d-flex gap-2 justify-content-end">
                                        <button type="submit" name="decision" value="Rejected" class="btn btn-outline-danger btn-sm">
                                            Reject
                                        </button>
                                        <button type="submit" name="decision" value="Approved" class="btn btn-success btn-sm">
                                            Approve
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
