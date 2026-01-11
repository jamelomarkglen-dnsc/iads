<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'final_paper_helpers.php';
require_once 'notice_commence_helpers.php';

$allowedRoles = ['dean', 'program_chairperson', 'student'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureFinalPaperTables($conn);
ensureNoticeCommenceTable($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$noticeId = (int)($_GET['notice_id'] ?? 0);
$notice = null;
$error = '';

if ($noticeId <= 0) {
    $error = 'Missing notice request.';
} else {
    $stmt = $conn->prepare("
        SELECT n.*, u.firstname, u.lastname, u.program,
               fp.final_title,
               CONCAT(pc.firstname, ' ', pc.lastname) AS chair_name,
               CONCAT(d.firstname, ' ', d.lastname) AS dean_name
        FROM notice_to_commence_requests n
        JOIN users u ON u.id = n.student_id
        LEFT JOIN final_paper_submissions fp ON fp.id = n.submission_id
        LEFT JOIN users pc ON pc.id = n.program_chair_id
        LEFT JOIN users d ON d.id = n.dean_id
        WHERE n.id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $noticeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $notice = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
    if (!$notice) {
        $error = 'Unable to locate the notice.';
    } else {
        $allowed = false;
        if ($role === 'dean') {
            $allowed = true;
        } elseif ($role === 'program_chairperson') {
            $allowed = $userId === (int)($notice['program_chair_id'] ?? 0);
        } elseif ($role === 'student') {
            $allowed = $userId === (int)($notice['student_id'] ?? 0);
        }

        if (!$allowed) {
            $error = 'You are not authorized to view this notice.';
            $notice = null;
        }
    }
}

function notice_status_badge(string $status): string
{
    return [
        'Approved' => 'bg-success-subtle text-success',
        'Rejected' => 'bg-danger-subtle text-danger',
        'Pending' => 'bg-warning-subtle text-warning',
    ][$status] ?? 'bg-secondary-subtle text-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notice to Commence</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8f5; color: #1f2d22; }
        .content { margin-left: var(--sidebar-width-expanded, 240px); transition: margin-left 0.3s ease; }
        #sidebar.collapsed ~ .content { margin-left: var(--sidebar-width-collapsed, 70px); }
        @media (max-width: 992px) {
            .content { margin-left: 0; }
            #sidebar.collapsed ~ .content { margin-left: 0; }
        }
        .notice-card { border-radius: 18px; border: 1px solid rgba(22, 86, 44, 0.12); box-shadow: 0 18px 40px rgba(15, 61, 31, 0.08); }
        .notice-body { white-space: pre-wrap; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main class="content dashboard-content" role="main">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h5 fw-semibold text-success mb-1">Notice to Commence Approved Proposal</h1>
                <p class="text-muted small mb-0">Official notice issued after route slip approval.</p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <?= htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <?php
            $studentName = trim(($notice['firstname'] ?? '') . ' ' . ($notice['lastname'] ?? '')) ?: 'Student';
            $programName = trim((string)($notice['program'] ?? ''));
            $programLabel = $programName !== '' ? "{$programName} Student" : 'Student';
            $finalTitle = trim((string)($notice['final_title'] ?? ''));
            $subject = trim((string)($notice['subject'] ?? ''));
            if ($subject === '') {
                $subject = 'NOTIFICATION TO COMMENCE THE APPROVED PROPOSAL';
            }
            $noticeDateLabel = notice_commence_format_date($notice['notice_date'] ?? null);
            $body = trim((string)($notice['body'] ?? ''));
            if ($body === '') {
                $body = build_notice_commence_body($studentName, $finalTitle, $programName, $notice['start_date'] ?? null);
            }
            $chairName = $notice['chair_name'] ?? 'Program Chairperson';
            $status = $notice['status'] ?? 'Pending';
            $chairTitle = $programName !== '' ? "Program Chairperson, {$programName}" : 'Program Chairperson';
            $deanApproved = $status === 'Approved';
            $deanName = $notice['dean_name'] ?? 'Dean, Institute of Advanced Studies';
            $deanDisplay = $deanApproved ? $deanName : 'Pending approval';
            ?>
            <div class="card notice-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                        <div>
                            <div class="text-uppercase text-muted small">Status</div>
                            <span class="badge <?= notice_status_badge($status); ?>"><?= htmlspecialchars($status); ?></span>
                        </div>
                        <div class="text-muted small">
                            <strong>Date:</strong> <?= htmlspecialchars($noticeDateLabel ?: 'Date not set'); ?>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="row align-items-start g-2">
                            <div class="col-sm-8">
                                <div class="fw-semibold">TO&nbsp;&nbsp;:&nbsp;&nbsp;<?= htmlspecialchars($studentName); ?></div>
                                <div class="text-muted small ms-4"><?= htmlspecialchars($programLabel); ?></div>
                            </div>
                            <div class="col-sm-4 text-sm-end text-muted small">
                                <strong>DATE&nbsp;&nbsp;:</strong> <?= htmlspecialchars($noticeDateLabel ?: 'Date not set'); ?>
                            </div>
                        </div>
                        <div class="text-muted small mt-2">
                            <strong>SUBJECT&nbsp;&nbsp;:</strong> <?= htmlspecialchars($subject); ?>
                        </div>
                    </div>

                    <div class="notice-body mb-4"><?= nl2br(htmlspecialchars($body)); ?></div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="text-muted small">Recommending approval:</div>
                            <div class="fw-semibold mt-2"><?= htmlspecialchars($chairName); ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($chairTitle); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Approved:</div>
                            <div class="fw-semibold mt-2"><?= htmlspecialchars($deanDisplay); ?></div>
                            <div class="text-muted small">Dean, Institute of Advanced Studies</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
