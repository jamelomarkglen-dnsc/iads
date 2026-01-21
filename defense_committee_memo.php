<?php
session_start();
include 'db.php';
require_once 'defense_committee_helpers.php';
require_once 'role_helpers.php';

$allowedRoles = ['dean', 'program_chairperson', 'adviser', 'committee_chairperson', 'panel', 'student'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$requestId = (int)($_GET['request_id'] ?? 0);
$memo = null;
$error = '';

if ($requestId <= 0) {
    $error = 'Missing memo request.';
} else {
    $stmt = $conn->prepare("
        SELECT
            r.id,
            r.student_id,
            r.requested_by,
            r.adviser_id,
            r.chair_id,
            r.panel_member_one_id,
            r.panel_member_two_id,
            r.memo_number,
            r.memo_series_year,
            r.memo_date,
            r.memo_subject,
            r.memo_body,
            r.memo_final_title,
            r.memo_received_at,
            r.status,
            ds.defense_date,
            ds.defense_time,
            ds.venue,
            CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
            CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
            CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name,
            CONCAT(p1.firstname, ' ', p1.lastname) AS panel_one_name,
            CONCAT(p2.firstname, ' ', p2.lastname) AS panel_two_name
        FROM defense_committee_requests r
        JOIN users stu ON stu.id = r.student_id
        JOIN defense_schedules ds ON ds.id = r.defense_id
        LEFT JOIN users adv ON adv.id = r.adviser_id
        LEFT JOIN users ch ON ch.id = r.chair_id
        LEFT JOIN users p1 ON p1.id = r.panel_member_one_id
        LEFT JOIN users p2 ON p2.id = r.panel_member_two_id
        WHERE r.id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $result = $stmt->get_result();
        $memo = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
    if (!$memo) {
        $error = 'Unable to locate the memo.';
    } else {
        $allowed = false;
        if ($role === 'dean') {
            $allowed = true;
        } else {
            $targets = [
                (int)($memo['student_id'] ?? 0),
                (int)($memo['requested_by'] ?? 0),
                (int)($memo['adviser_id'] ?? 0),
                (int)($memo['chair_id'] ?? 0),
                (int)($memo['panel_member_one_id'] ?? 0),
                (int)($memo['panel_member_two_id'] ?? 0),
            ];
            $allowed = in_array($userId, $targets, true);
        }
        if (!$allowed) {
            $error = 'You are not authorized to view this memo.';
            $memo = null;
        } elseif (trim((string)($memo['memo_body'] ?? '')) === '') {
            $error = 'Memo is not available yet.';
        } elseif ($role === 'student' && ($memo['status'] ?? '') !== 'Approved') {
            $error = 'Memo is not available yet.';
            $memo = null;
        } elseif ($role === 'student' && empty($memo['memo_received_at'])) {
            $update = $conn->prepare("
                UPDATE defense_committee_requests
                SET memo_received_at = NOW()
                WHERE id = ? AND memo_received_at IS NULL
            ");
            if ($update) {
                $update->bind_param('i', $requestId);
                if ($update->execute()) {
                    $memo['memo_received_at'] = date('Y-m-d H:i:s');
                }
                $update->close();
            }
        }
    }
}

function formatMemoDate(?string $date): string
{
    if (!$date) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt) {
        return $dt->format('F d, Y');
    }
    return $date;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Defense Committee Memo</title>
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
        .memo-card {
            border-radius: 18px;
            border: 1px solid rgba(22, 86, 44, 0.12);
            box-shadow: 0 18px 40px rgba(15, 61, 31, 0.08);
            overflow: hidden;
            background: #fff;
            max-width: 820px;
            margin: 0 auto;
        }
        .memo-header { letter-spacing: 0.06em; font-size: 0.98rem; }
        .memo-body {
            white-space: pre-line;
            font-size: 0.96rem;
            line-height: 1.5;
            text-align: justify;
            text-justify: inter-word;
        }
        .letter-head,
        .letter-foot {
            background-image: url('memopic.jpg');
            background-repeat: no-repeat;
            background-size: 100% auto;
            width: 100%;
        }
        .letter-head {
            height: 180px;
            background-position: top center;
            border-bottom: 1px solid #d9e2d6;
        }
        .letter-foot {
            height: 120px;
            background-position: bottom center;
            border-top: 1px solid #d9e2d6;
        }
        .letter-body { padding: 24px 44px; }
        @media (max-width: 768px) {
            .letter-body { padding: 20px 24px; }
        }
        @media print {
            @page { size: letter; margin: 0.5in; }
            body { background: #fff; }
            nav.navbar,
            #sidebar,
            .btn {
                display: none !important;
            }
            .content { margin: 0 !important; }
            .memo-card { border: none; box-shadow: none; max-width: 100%; margin: 0; }
            .letter-head { height: 170px; }
            .letter-foot { height: 110px; }
            .letter-body { padding: 18px 28px; }
            .memo-header { font-size: 11pt; letter-spacing: 0.08em; }
            .memo-body { font-size: 10.5pt; line-height: 1.5; text-align: justify; text-justify: inter-word; }
            .memo-card .text-muted.small { font-size: 9.5pt; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<main class="content dashboard-content" role="main">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h5 fw-semibold text-success mb-1">Defense Committee Memo</h1>
                <p class="text-muted small mb-0">Official memorandum for the outline defense committee.</p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <div class="card memo-card">
                <div class="letter-head" aria-hidden="true"></div>
                <div class="card-body letter-body">
                    <div class="text-center fw-semibold memo-header">OFFICE OF THE DEAN</div>
                    <div class="text-center text-muted small mb-3">
                        Memorandum No. <?php echo htmlspecialchars($memo['memo_number'] ?? ''); ?> &middot;
                        Series of <?php echo htmlspecialchars($memo['memo_series_year'] ?? date('Y')); ?>
                    </div>
                    <div class="d-flex justify-content-between flex-wrap mb-3">
                        <div class="text-muted small">
                            <strong>Date:</strong> <?php echo htmlspecialchars(formatMemoDate($memo['memo_date'] ?? null)); ?>
                        </div>
                        <div class="text-muted small">
                            <strong>Subject:</strong> <?php echo htmlspecialchars($memo['memo_subject'] ?? ''); ?>
                        </div>
                    </div>
                    <div class="memo-body"><?php echo htmlspecialchars($memo['memo_body'] ?? ''); ?></div>
                </div>
                <div class="letter-foot" aria-hidden="true"></div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
