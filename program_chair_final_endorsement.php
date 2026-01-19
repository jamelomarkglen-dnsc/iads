<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'chair_scope_helper.php';
require_once 'final_defense_endorsement_helpers.php';

enforce_role_access(['program_chairperson']);

$programChairId = (int)($_SESSION['user_id'] ?? 0);
$chairScope = get_program_chair_scope($conn, $programChairId);

ensureFinalDefenseEndorsementTable($conn);

$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_final_endorsement'])) {
    $endorsementId = (int)($_POST['endorsement_id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));
    $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));

    if ($endorsementId <= 0 || !in_array($decision, ['Verified', 'Rejected'], true)) {
        $alert = ['type' => 'danger', 'message' => 'Please select a valid decision.'];
    } else {
        $lookupStmt = $conn->prepare("
            SELECT fe.id, fe.status, fe.student_id, fe.adviser_id,
                   CONCAT(stu.firstname, ' ', stu.lastname) AS student_name
            FROM final_defense_endorsements fe
            JOIN users stu ON stu.id = fe.student_id
            WHERE fe.id = ?
            LIMIT 1
        ");
        $endorsement = null;
        if ($lookupStmt) {
            $lookupStmt->bind_param('i', $endorsementId);
            $lookupStmt->execute();
            $lookupResult = $lookupStmt->get_result();
            $endorsement = $lookupResult ? $lookupResult->fetch_assoc() : null;
            $lookupStmt->close();
        }

        if (!$endorsement) {
            $alert = ['type' => 'danger', 'message' => 'Final endorsement not found.'];
        } elseif (!student_matches_scope_any($conn, (int)($endorsement['student_id'] ?? 0), $chairScope)) {
            $alert = ['type' => 'danger', 'message' => 'You can only verify endorsements for students in your scope.'];
        } elseif (($endorsement['status'] ?? '') === 'Verified') {
            $alert = ['type' => 'warning', 'message' => 'This endorsement has already been verified.'];
        } else {
            $updateStmt = $conn->prepare("
                UPDATE final_defense_endorsements
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
                WHERE id = ?
            ");
            if ($updateStmt) {
                $updateStmt->bind_param('sisi', $decision, $programChairId, $reviewNotes, $endorsementId);
                if ($updateStmt->execute()) {
                    $studentName = $endorsement['student_name'] ?? 'the student';
                    $adviserId = (int)($endorsement['adviser_id'] ?? 0);
                    $studentId = (int)($endorsement['student_id'] ?? 0);
                    if ($adviserId > 0) {
                        notify_user(
                            $conn,
                            $adviserId,
                            'Final defense endorsement reviewed',
                            "Your final defense endorsement for {$studentName} was marked as {$decision}.",
                            'adviser_final_endorsement.php',
                            false
                        );
                    }
                    if ($studentId > 0) {
                        notify_user(
                            $conn,
                            $studentId,
                            'Final defense endorsement reviewed',
                            "Your final defense endorsement was marked as {$decision}.",
                            'student_dashboard.php',
                            false
                        );
                    }
                    $alert = ['type' => 'success', 'message' => 'Final endorsement decision saved.'];
                } else {
                    $alert = ['type' => 'danger', 'message' => 'Unable to update the endorsement decision.'];
                }
                $updateStmt->close();
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to prepare the endorsement update.'];
            }
        }
    }
}

[$scopeClause, $scopeTypes, $scopeParams] = build_scope_condition_any($chairScope, 'stu');
$endorsementSql = "
    SELECT fe.id, fe.title, fe.body, fe.signature_path, fe.status, fe.submitted_at, fe.reviewed_at, fe.review_notes,
           CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
           CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name
    FROM final_defense_endorsements fe
    JOIN users stu ON stu.id = fe.student_id
    LEFT JOIN users adv ON adv.id = fe.adviser_id
";
if ($scopeClause !== '') {
    $endorsementSql .= " WHERE {$scopeClause}";
}
$endorsementSql .= " ORDER BY fe.submitted_at DESC LIMIT 20";

$endorsements = [];
$endorsementStmt = $conn->prepare($endorsementSql);
if ($endorsementStmt) {
    if ($scopeTypes !== '') {
        $scopeBindParams = $scopeParams;
        bind_scope_params($endorsementStmt, $scopeTypes, $scopeBindParams);
    }
    $endorsementStmt->execute();
    $endorsementResult = $endorsementStmt->get_result();
    if ($endorsementResult) {
        $endorsements = $endorsementResult->fetch_all(MYSQLI_ASSOC);
        $endorsementResult->free();
    }
    $endorsementStmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Defense Endorsements</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .signature-block { text-align: left; margin: 4px 0 2px; }
        .signature-image { max-height: 70px; max-width: 180px; object-fit: contain; }
        .signature-line { width: 220px; margin: 4px 0 0; border-top: 1px solid #1d3522; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Defense Endorsements</h3>
                <p class="text-muted mb-0">Review and verify final defense endorsements from advisers.</p>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']); ?>">
                <?= htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="card card-shell p-4">
            <h5 class="fw-bold text-success mb-3">Pending and Recent</h5>
            <?php if (empty($endorsements)): ?>
                <p class="text-muted mb-0">No final endorsements received yet.</p>
            <?php else: ?>
                <div class="d-flex flex-column gap-4">
                    <?php foreach ($endorsements as $endorsement): ?>
                        <?php
                            $status = $endorsement['status'] ?? 'Submitted';
                            $badgeClass = $status === 'Verified'
                                ? 'bg-success-subtle text-success'
                                : ($status === 'Rejected' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning');
                            $submittedAt = $endorsement['submitted_at'] ? date('M d, Y g:i A', strtotime($endorsement['submitted_at'])) : 'Not recorded';
                            $reviewedAt = $endorsement['reviewed_at'] ? date('M d, Y g:i A', strtotime($endorsement['reviewed_at'])) : '';
                            $body = strip_tags((string)($endorsement['body'] ?? ''), '<u><br>');
                            $bodyHtml = str_replace(["\r\n", "\n"], "<br>", $body);
                            $signaturePath = trim((string)($endorsement['signature_path'] ?? ''));
                            if ($signaturePath !== '' && stripos($bodyHtml, 'final_endorsement_signatures/') === false) {
                                $signatureBlock = '<div class="signature-block">'
                                    . '<img src="' . htmlspecialchars($signaturePath) . '" alt="Adviser e-signature" class="signature-image">'
                                    . '<div class="signature-line"></div>'
                                    . '</div>';
                                $marker = 'Adviser:';
                                $pos = stripos($bodyHtml, $marker);
                                if ($pos !== false) {
                                    $bodyHtml = substr_replace($bodyHtml, $signatureBlock, $pos, 0);
                                } else {
                                    $bodyHtml .= '<br>' . $signatureBlock;
                                }
                            }
                        ?>
                        <div class="border rounded-4 p-3">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="fw-semibold text-success"><?= htmlspecialchars($endorsement['student_name'] ?? 'Student'); ?></div>
                                    <div class="text-muted small">Adviser: <?= htmlspecialchars($endorsement['adviser_name'] ?? 'Adviser'); ?></div>
                                    <div class="small mt-1"><strong>Title:</strong> <?= htmlspecialchars($endorsement['title'] ?? ''); ?></div>
                                </div>
                                <span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span>
                            </div>
                            <div class="text-muted small mt-2">Sent <?= htmlspecialchars($submittedAt); ?></div>
                            <?php if ($status !== 'Submitted'): ?>
                                <div class="text-muted small mt-1">Reviewed <?= htmlspecialchars($reviewedAt); ?></div>
                            <?php endif; ?>
                            <details class="mt-2">
                                <summary class="small text-decoration-underline">View endorsement letter</summary>
                                <div class="mt-2 small"><?= $bodyHtml; ?></div>
                            </details>
                            <?php if (!empty($endorsement['review_notes'])): ?>
                                <div class="text-muted small mt-2">Notes: <?= htmlspecialchars($endorsement['review_notes']); ?></div>
                            <?php endif; ?>
                            <?php if ($status === 'Submitted'): ?>
                                <form method="post" class="mt-3 d-flex flex-column gap-2">
                                    <input type="hidden" name="review_final_endorsement" value="1">
                                    <input type="hidden" name="endorsement_id" value="<?= (int)($endorsement['id'] ?? 0); ?>">
                                    <textarea name="review_notes" class="form-control form-control-sm" rows="2" placeholder="Optional notes for the adviser..."></textarea>
                                    <div class="d-flex gap-2 justify-content-end">
                                        <button type="submit" name="decision" value="Rejected" class="btn btn-outline-danger btn-sm">Reject</button>
                                        <button type="submit" name="decision" value="Verified" class="btn btn-success btn-sm">Verify</button>
                                    </div>
                                </form>
                            <?php endif; ?>
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
