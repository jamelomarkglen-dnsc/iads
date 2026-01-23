<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'chair_scope_helper.php';
require_once 'final_paper_helpers.php';
require_once 'defense_committee_helpers.php';
require_once 'notice_commence_helpers.php';

enforce_role_access(['program_chairperson']);

$chairId = (int)($_SESSION['user_id'] ?? 0);
$chairName = fetch_user_fullname($conn, $chairId);

ensureFinalPaperTables($conn);
ensureNoticeCommenceTable($conn);

$scope = get_program_chair_scope($conn, $chairId);
[$scopeClause, $scopeTypes, $scopeParams] = build_scope_condition_any($scope, 'u');

$alert = null;
$prefillSubmissionId = (int)($_GET['submission_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_notice_commence'])) {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $noticeDateInput = trim((string)($_POST['notice_date'] ?? ''));
    $startDateInput = trim((string)($_POST['start_date'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = trim((string)($_POST['notice_body'] ?? ''));

    $errors = [];
    $signatureError = '';
    if (isset($_FILES['chair_signature'])) {
        save_notice_signature_upload($_FILES['chair_signature'], $chairId, $signatureError);
        if ($signatureError !== '') {
            $errors[] = $signatureError;
        }
    }
    if ($submissionId <= 0 || $studentId <= 0) {
        $errors[] = 'Please select an approved submission.';
    }

    $submission = null;
    if (!$errors) {
        $lookupSql = "
            SELECT fps.id, fps.student_id, fps.final_title, fps.status, fps.route_slip_signed_at,
                   u.firstname, u.lastname, u.program
            FROM final_paper_submissions fps
            JOIN users u ON u.id = fps.student_id
            WHERE fps.id = ? AND fps.student_id = ?
        ";
        if ($scopeClause !== '') {
            $lookupSql .= " AND {$scopeClause}";
        }
        $lookupSql .= " LIMIT 1";
        $lookupStmt = $conn->prepare($lookupSql);
        if ($lookupStmt) {
            $bindTypes = 'ii' . $scopeTypes;
            $bindParams = array_merge([$submissionId, $studentId], $scopeParams);
            bind_scope_params($lookupStmt, $bindTypes, $bindParams);
            $lookupStmt->execute();
            $lookupResult = $lookupStmt->get_result();
            $submission = $lookupResult ? $lookupResult->fetch_assoc() : null;
            $lookupStmt->close();
        }
        $submissionStatus = trim((string)($submission['status'] ?? ''));
        $routeSlipDecision = strtolower(trim((string)($submission['route_slip_overall_decision'] ?? '')));
        $allowedRouteSlipDecisions = [
            'approved',
            'passed with minor revision',
            'passed with major revision',
        ];
        $fullySigned = !empty($submission['route_slip_signed_at']);
        if (
            !$submission
            || ($submissionStatus !== 'Approved'
                && !in_array($routeSlipDecision, $allowedRouteSlipDecisions, true)
                && !$fullySigned)
        ) {
            $errors[] = 'Only approved outline defense submissions can be issued a notice to commence.';
        }
    }

    if (!$errors) {
        $duplicateStmt = $conn->prepare("
            SELECT id
            FROM notice_to_commence_requests
            WHERE submission_id = ? AND status IN ('Pending', 'Approved')
            LIMIT 1
        ");
        if ($duplicateStmt) {
            $duplicateStmt->bind_param('i', $submissionId);
            $duplicateStmt->execute();
            $duplicateResult = $duplicateStmt->get_result();
            $duplicate = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
            $duplicateStmt->close();
            if ($duplicate) {
                $errors[] = 'A notice to commence has already been submitted for this student.';
            }
        }
    }

    $noticeDate = $noticeDateInput;
    if ($noticeDate === '' || !DateTime::createFromFormat('Y-m-d', $noticeDate)) {
        $noticeDate = date('Y-m-d');
    }
    $startDate = $startDateInput;
    if ($startDate === '' || !DateTime::createFromFormat('Y-m-d', $startDate)) {
        $startDate = $noticeDate;
    }
    if ($subject === '') {
        $subject = 'NOTIFICATION TO COMMENCE THE APPROVED PROPOSAL';
    }

    if (!$errors && $submission) {
        $studentName = trim(($submission['firstname'] ?? '') . ' ' . ($submission['lastname'] ?? '')) ?: 'the student';
        $programName = trim((string)($submission['program'] ?? ''));
        $finalTitle = trim((string)($submission['final_title'] ?? ''));
        if ($body === '') {
            $body = build_notice_commence_body($studentName, $finalTitle, $programName, $startDate);
        }

        $insertStmt = $conn->prepare("
            INSERT INTO notice_to_commence_requests
                (student_id, submission_id, program_chair_id, status, notice_date, start_date, subject, body)
            VALUES (?, ?, ?, 'Pending', ?, ?, ?, ?)
        ");
        if ($insertStmt) {
            $insertStmt->bind_param(
                'iiissss',
                $studentId,
                $submissionId,
                $chairId,
                $noticeDate,
                $startDate,
                $subject,
                $body
            );
            if ($insertStmt->execute()) {
                $message = "{$chairName} submitted a notice to commence request for {$studentName}.";
                notify_role($conn, 'dean', 'Notice to commence approval needed', $message, 'dean_notice_commence.php');
                $alert = ['type' => 'success', 'message' => 'Notice to commence sent to the dean for approval.'];
            } else {
                $alert = ['type' => 'danger', 'message' => 'Unable to save the notice request.'];
            }
            $insertStmt->close();
        } else {
            $alert = ['type' => 'danger', 'message' => 'Unable to prepare the notice request.'];
        }
    } elseif ($errors) {
        $alert = ['type' => 'danger', 'message' => implode(' ', $errors)];
    }
}

$noticeStatusBySubmission = [];
$noticeStatusStmt = $conn->prepare("
    SELECT submission_id, status
    FROM notice_to_commence_requests
    WHERE status IN ('Pending', 'Approved')
");
if ($noticeStatusStmt) {
    $noticeStatusStmt->execute();
    $noticeStatusResult = $noticeStatusStmt->get_result();
    if ($noticeStatusResult) {
        while ($row = $noticeStatusResult->fetch_assoc()) {
            $submissionId = (int)($row['submission_id'] ?? 0);
            if ($submissionId > 0) {
                $noticeStatusBySubmission[$submissionId] = $row['status'] ?? 'Pending';
            }
        }
        $noticeStatusResult->free();
    }
    $noticeStatusStmt->close();
}

$eligibleSubmissions = [];
$submissionSql = "
    SELECT fps.id AS submission_id, fps.student_id, fps.final_title, fps.submitted_at,
           u.firstname, u.lastname, u.program
    FROM final_paper_submissions fps
    JOIN users u ON u.id = fps.student_id
    WHERE (
        fps.status = 'Approved'
        OR fps.route_slip_signed_at IS NOT NULL
        OR LOWER(TRIM(fps.route_slip_overall_decision)) IN (
            'approved',
            'passed with minor revision',
            'passed with major revision'
        )
    )
";
if ($scopeClause !== '') {
    $submissionSql .= " AND {$scopeClause}";
}
$submissionSql .= " ORDER BY fps.submitted_at DESC, fps.id DESC";
$submissionStmt = $conn->prepare($submissionSql);
if ($submissionStmt) {
    if ($scopeClause !== '') {
        $scopeBindParams = $scopeParams;
        bind_scope_params($submissionStmt, $scopeTypes, $scopeBindParams);
    }
    $submissionStmt->execute();
    $submissionResult = $submissionStmt->get_result();
    if ($submissionResult) {
        $eligibleSubmissions = $submissionResult->fetch_all(MYSQLI_ASSOC);
        $submissionResult->free();
    }
    $submissionStmt->close();
}

$recentNotices = [];
$recentStmt = $conn->prepare("
    SELECT n.id, n.status, n.notice_date, n.created_at, n.student_id, n.submission_id,
           CONCAT(u.firstname, ' ', u.lastname) AS student_name,
           fp.final_title
    FROM notice_to_commence_requests n
    JOIN users u ON u.id = n.student_id
    LEFT JOIN final_paper_submissions fp ON fp.id = n.submission_id
    WHERE n.program_chair_id = ?
    ORDER BY n.created_at DESC
    LIMIT 10
");
if ($recentStmt) {
    $recentStmt->bind_param('i', $chairId);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    if ($recentResult) {
        $recentNotices = $recentResult->fetch_all(MYSQLI_ASSOC);
        $recentResult->free();
    }
    $recentStmt->close();
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notice to Commence</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8f5; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .hero-card { border-radius: 20px; background: linear-gradient(130deg, #144d28, #0c341c); color: #fff; padding: 24px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .form-label { font-weight: 600; color: #16562c; }
        .small-muted { color: #6c757d; font-size: 0.9rem; }
        .notice-preview-card { border-radius: 18px; overflow: hidden; background: #fff; }
        .notice-letter-head,
        .notice-letter-foot {
            background-image: url('memopic.jpg');
            background-repeat: no-repeat;
            background-size: 100% auto;
            width: 100%;
        }
        .notice-letter-head {
            height: 160px;
            background-position: top center;
            border-bottom: 1px solid #d9e2d6;
        }
        .notice-letter-foot {
            height: 110px;
            background-position: bottom center;
            border-top: 1px solid #d9e2d6;
        }
        .notice-letter-body {
            padding: 20px 36px;
            font-size: 0.95rem;
            line-height: 1.5;
            text-align: justify;
            text-justify: inter-word;
        }
        .notice-letter-body p { margin: 0 0 0.75rem; }
        .notice-letter-body p:last-child { margin-bottom: 0; }
        .notice-preview-title { letter-spacing: 0.08em; font-size: 0.9rem; }
        .notice-signature-line { width: 220px; border-top: 1px solid #1f2d22; margin-top: 28px; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <h3 class="fw-bold mb-1">Notice to Commence</h3>
            <p class="mb-0 text-white-50">Prepare the notice and send it to the dean for approval.</p>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?= htmlspecialchars($alert['type']); ?>">
                <?= htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card card-shell p-4">
                    <h5 class="fw-bold text-success mb-3">Create Notice</h5>
                    <?php if (empty($eligibleSubmissions)): ?>
                        <p class="text-muted">No approved outline defense submissions available yet.</p>
                    <?php endif; ?>
                    <form method="post" id="noticeForm" enctype="multipart/form-data">
                        <input type="hidden" name="submit_notice_commence" value="1">
                        <input type="hidden" name="student_id" id="studentId">
                        <div class="mb-3">
                            <label class="form-label">Select Approved Submission</label>
                            <select name="submission_id" id="submissionSelect" class="form-select" required <?= empty($eligibleSubmissions) ? 'disabled' : ''; ?>>
                                <?php if (empty($eligibleSubmissions)): ?>
                                    <option value="">No approved submissions available</option>
                                <?php else: ?>
                                    <option value="">Choose student...</option>
                                    <?php foreach ($eligibleSubmissions as $submission): ?>
                                        <?php
                                        $submissionId = (int)($submission['submission_id'] ?? 0);
                                        $status = $noticeStatusBySubmission[$submissionId] ?? '';
                                        $disabled = $status !== '' ? 'disabled' : '';
                                        $suffix = $status !== '' ? " ({$status})" : '';
                                        $studentName = trim(($submission['firstname'] ?? '') . ' ' . ($submission['lastname'] ?? ''));
                                        $selected = $prefillSubmissionId > 0 && $submissionId === $prefillSubmissionId ? 'selected' : '';
                                        ?>
                                        <option
                                            value="<?= $submissionId; ?>"
                                            <?= $disabled; ?>
                                            <?= $selected; ?>
                                            data-student-id="<?= (int)($submission['student_id'] ?? 0); ?>"
                                            data-student-name="<?= htmlspecialchars($studentName, ENT_QUOTES); ?>"
                                            data-program="<?= htmlspecialchars($submission['program'] ?? '', ENT_QUOTES); ?>"
                                            data-title="<?= htmlspecialchars($submission['final_title'] ?? '', ENT_QUOTES); ?>"
                                        >
                                            <?= htmlspecialchars($studentName ?: 'Student'); ?><?= htmlspecialchars($suffix); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="small-muted mt-1">Only approved outline defense submissions can be issued a notice.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Student</label>
                                <input type="text" id="studentName" class="form-control" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Program</label>
                                <input type="text" id="programInput" class="form-control" readonly>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Approved Title</label>
                            <input type="text" id="titleInput" class="form-control" readonly>
                        </div>

                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label class="form-label">Notice Date</label>
                                <input type="date" name="notice_date" id="noticeDate" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Research Start Date</label>
                                <input type="date" name="start_date" id="startDate" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')); ?>">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Subject</label>
                            <input type="text" name="subject" id="subjectInput" class="form-control" value="NOTIFICATION TO COMMENCE THE APPROVED PROPOSAL">
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Notice Body</label>
                            <textarea name="notice_body" id="noticeBody" class="form-control" rows="10"><?=
                                htmlspecialchars(
                                    build_notice_commence_body(
                                        'the student',
                                        'your research proposal',
                                        '',
                                        date('Y-m-d')
                                    )
                                );
                            ?></textarea>
                            <div class="small-muted mt-1">You can adjust the message before sending to the dean.</div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Program Chairperson E-Signature (PNG or JPG)</label>
                            <input type="file" name="chair_signature" class="form-control" accept="image/png,image/jpeg">
                            <div class="small-muted mt-1">Upload once; it will appear on the printed notice.</div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-success" id="sendToDeanBtn" <?= empty($eligibleSubmissions) ? 'disabled' : ''; ?>>
                                <i class="bi bi-send-check me-2"></i>Send to Dean
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card card-shell notice-preview-card p-0 mt-4">
                    <div class="notice-letter-head" aria-hidden="true"></div>
                    <div class="notice-letter-body">
                        <div class="text-center fw-semibold notice-preview-title">NOTICE TO COMMENCE</div>
                        <div class="d-flex justify-content-between flex-wrap text-muted small mb-3">
                            <div>Date: <span id="previewNoticeDate">--</span></div>
                            <div>Subject: <span id="previewSubject">--</span></div>
                        </div>
                        <div class="mb-2"><span class="fw-semibold">To:</span> <span id="previewStudentName">Student</span></div>
                        <div class="text-muted small mb-3" id="previewProgram">Program: --</div>
                        <div id="previewBody"></div>
                        <div class="notice-signature-line"></div>
                        <div class="text-muted small">Program Chairperson</div>
                    </div>
                    <div class="notice-letter-foot" aria-hidden="true"></div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card card-shell p-4">
                    <h5 class="fw-bold text-success mb-3">Recent Notices</h5>
                    <?php if (empty($recentNotices)): ?>
                        <p class="text-muted mb-0">No notices sent yet.</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($recentNotices as $notice): ?>
                                <?php
                                $status = $notice['status'] ?? 'Pending';
                                $noticeDate = $notice['notice_date'] ? notice_commence_format_date($notice['notice_date']) : 'Date not set';
                                $badgeClass = $status === 'Approved' ? 'bg-success-subtle text-success' : ($status === 'Rejected' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning');
                                ?>
                                <div class="border rounded-3 p-3">
                                    <div class="fw-semibold"><?= htmlspecialchars($notice['student_name'] ?? 'Student'); ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($notice['final_title'] ?? ''); ?></div>
                                    <div class="small mt-2">
                                        <span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($status); ?></span>
                                        <span class="text-muted ms-2"><?= htmlspecialchars($noticeDate); ?></span>
                                    </div>
                                    <div class="small mt-2">
                                        <a href="notice_commence_view.php?notice_id=<?= (int)($notice['id'] ?? 0); ?>" class="text-success text-decoration-none" target="_blank">
                                            <i class="bi bi-file-earmark-text me-1"></i>View Notice
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const submissionSelect = document.getElementById('submissionSelect');
const studentIdInput = document.getElementById('studentId');
const studentNameInput = document.getElementById('studentName');
const programInput = document.getElementById('programInput');
const titleInput = document.getElementById('titleInput');
const noticeDateInput = document.getElementById('noticeDate');
const startDateInput = document.getElementById('startDate');
const subjectInput = document.getElementById('subjectInput');
const bodyInput = document.getElementById('noticeBody');
const sendToDeanBtn = document.getElementById('sendToDeanBtn');
const previewNoticeDate = document.getElementById('previewNoticeDate');
const previewSubject = document.getElementById('previewSubject');
const previewStudentName = document.getElementById('previewStudentName');
const previewProgram = document.getElementById('previewProgram');
const previewBody = document.getElementById('previewBody');

let bodyDirty = false;

function formatDateLabel(dateValue) {
    if (!dateValue) return '';
    const date = new Date(dateValue + 'T00:00:00');
    if (Number.isNaN(date.getTime())) return dateValue;
    return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
}

function buildNoticeBody() {
    const title = titleInput.value || 'your research proposal';
    const startDateLabel = formatDateLabel(startDateInput.value || noticeDateInput.value);
    return [
        `We are pleased to inform you that your research proposal entitled, "${title}" has been approved. With the approval in place, you are now authorized to commence your study.`,
        `The official start date of your research is ${startDateLabel}, and we anticipate its completion within one year. Please ensure that you adhere to the approved protocols and methodologies as outlined in your proposal. Should you require any resources or support, do not hesitate to reach out to your research adviser.`,
        `This is an exciting opportunity to contribute valuable insights to your field of study, and we are confident in your ability to execute this research with diligence and rigor. If you need further assistance, please feel free to visit the office.`,
        `For your guidance and commitment.`
    ].join('\n\n');
}

function fillNoticeFields() {
    const option = submissionSelect.options[submissionSelect.selectedIndex];
    if (!option || !option.value) {
        studentIdInput.value = '';
        studentNameInput.value = '';
        programInput.value = '';
        titleInput.value = '';
        if (!bodyDirty && bodyInput && bodyInput.value.trim() === '') {
            bodyInput.value = buildNoticeBody();
        }
        if (sendToDeanBtn) {
            sendToDeanBtn.disabled = true;
        }
        updateNoticePreview();
        return;
    }
    studentIdInput.value = option.dataset.studentId || '';
    studentNameInput.value = option.dataset.studentName || '';
    programInput.value = option.dataset.program || '';
    titleInput.value = option.dataset.title || '';
    if (!subjectInput.value) {
        subjectInput.value = 'NOTIFICATION TO COMMENCE THE APPROVED PROPOSAL';
    }
    bodyDirty = false;
    bodyInput.value = buildNoticeBody();
    if (sendToDeanBtn) {
        sendToDeanBtn.disabled = false;
    }
    updateNoticePreview();
}

if (submissionSelect) {
    submissionSelect.addEventListener('change', fillNoticeFields);
    fillNoticeFields();
}

if (bodyInput) {
    bodyInput.addEventListener('input', () => {
        bodyDirty = true;
        updateNoticePreview();
    });
}

if (startDateInput && bodyInput) {
    startDateInput.addEventListener('change', () => {
        if (!bodyDirty) {
            bodyInput.value = buildNoticeBody();
        }
        updateNoticePreview();
    });
}

if (noticeDateInput && bodyInput) {
    noticeDateInput.addEventListener('change', () => {
        if (!bodyDirty) {
            bodyInput.value = buildNoticeBody();
        }
        updateNoticePreview();
    });
}

if (subjectInput) {
    subjectInput.addEventListener('input', updateNoticePreview);
}

function updateNoticePreview() {
    if (previewNoticeDate) {
        previewNoticeDate.textContent = formatDateLabel(noticeDateInput.value || '');
    }
    if (previewSubject) {
        previewSubject.textContent = subjectInput.value || 'NOTIFICATION TO COMMENCE THE APPROVED PROPOSAL';
    }
    if (previewStudentName) {
        previewStudentName.textContent = studentNameInput.value || 'Student';
    }
    if (previewProgram) {
        const programValue = programInput.value ? `Program: ${programInput.value}` : 'Program: --';
        previewProgram.textContent = programValue;
    }
    if (previewBody) {
        const rawBody = bodyInput.value || '';
        previewBody.innerHTML = renderNoticeBody(rawBody);
    }
}

updateNoticePreview();

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderNoticeBody(value) {
    const normalized = (value || '').replace(/\r\n/g, '\n');
    const parts = normalized.split(/\n{2,}/).map((part) => part.trim()).filter(Boolean);
    if (!parts.length) {
        return '';
    }
    return parts.map((part) => `<p>${escapeHtml(part).replace(/\n/g, '<br>')}</p>`).join('');
}
</script>
</body>
</html>
