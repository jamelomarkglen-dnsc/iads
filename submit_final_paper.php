<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_concept_helpers.php';
require_once 'final_paper_helpers.php';
require_once 'route_slip_helpers.php';

enforce_role_access(['student']);

ensureFinalPaperTables($conn);
ensureRouteSlipTable($conn);

$studentId = (int)($_SESSION['user_id'] ?? 0);
$studentName = trim(
    ($_SESSION['firstname'] ?? $_SESSION['first_name'] ?? '') . ' ' .
    ($_SESSION['lastname'] ?? $_SESSION['last_name'] ?? '')
);
$studentName = $studentName !== '' ? $studentName : 'Student';

$finalPick = getEligibleConceptForFinalSubmission($conn, $studentId);
$finalPickTitle = trim((string)($finalPick['title'] ?? ''));

$memoRequest = null;
$memoFinalTitle = '';
$memoReceivedAt = null;
$memoApproved = false;
$memoReady = false;
$memoStmt = $conn->prepare("
    SELECT status, memo_body, memo_final_title, memo_received_at
    FROM defense_committee_requests
    WHERE student_id = ?
    ORDER BY reviewed_at DESC, requested_at DESC
    LIMIT 1
");
if ($memoStmt) {
    $memoStmt->bind_param('i', $studentId);
    $memoStmt->execute();
    $memoRequest = $memoStmt->get_result()->fetch_assoc();
    $memoStmt->close();
}
if ($memoRequest) {
    $memoFinalTitle = trim((string)($memoRequest['memo_final_title'] ?? ''));
    $memoReceivedAt = $memoRequest['memo_received_at'] ?? null;
    $memoApproved = ($memoRequest['status'] ?? '') === 'Approved'
        && trim((string)($memoRequest['memo_body'] ?? '')) !== '';
    $memoReady = $memoApproved && !empty($memoReceivedAt);
    if ($memoReady && $memoFinalTitle !== '') {
        $finalPickTitle = $memoFinalTitle;
    }
}

$currentSubmission = fetchLatestFinalPaperSubmission($conn, $studentId);
$currentStatus = trim((string)($currentSubmission['status'] ?? ''));
$currentVersion = (int)($currentSubmission['version'] ?? 0);
$currentFile = $currentSubmission['file_path'] ?? '';
$currentRouteSlip = $currentSubmission['route_slip_path'] ?? '';
$latestRouteSlip = fetchLatestRouteSlipForStudent($conn, $studentId);
$latestRouteSlipDate = '';
if (!empty($latestRouteSlip['created_at'])) {
    $latestRouteSlipDate = date('M d, Y g:i A', strtotime($latestRouteSlip['created_at']));
}
$reviewSummary = [];
if (!empty($currentSubmission['id'])) {
    $reviewSummary = fetchFinalPaperReviews($conn, (int)$currentSubmission['id']);
}

if ($currentSubmission && !$memoReady) {
    $memoReady = true;
    $submissionTitle = trim((string)($currentSubmission['final_title'] ?? ''));
    if ($submissionTitle !== '' && $finalPickTitle === '') {
        $finalPickTitle = $submissionTitle;
    }
}

$formEnabled = $memoReady && $finalPickTitle !== '';
$canResubmit = $currentSubmission && in_array($currentStatus, ['Needs Revision', 'Minor Revision', 'Major Revision', 'Rejected'], true);
$canSubmit = $formEnabled && (!$currentSubmission || $canResubmit);
$canSendPacket = $currentSubmission !== null;

$success = '';
$error = '';

$formValues = [
    'final_title' => ($memoReady && $finalPickTitle !== '') ? $finalPickTitle : '',
    'introduction' => '',
    'background' => '',
    'methodology' => '',
    'notes' => '',
];

function is_pdf_upload(array $fileInfo): bool
{
    $extension = strtolower(pathinfo($fileInfo['name'] ?? '', PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return false;
    }
    $detectedType = '';
    if (!empty($fileInfo['tmp_name']) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedType = finfo_file($finfo, $fileInfo['tmp_name']) ?: '';
            finfo_close($finfo);
        }
    }
    $typeToCheck = $detectedType ?: ($fileInfo['type'] ?? '');
    return stripos((string)$typeToCheck, 'pdf') !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_route_slip_packet'])) {
    if (!$currentSubmission) {
        $error = 'Please submit your outline defense manuscript first.';
    } else {
        $reviewers = fetchFinalPaperReviewersForStudent($conn, $studentId);
        if (empty($reviewers)) {
            $error = 'Defense panel assignments are missing. Please contact the program chairperson.';
        } else {
            $revisedInfo = $_FILES['revised_document'] ?? null;
            $routeSlipInfo = $_FILES['route_slip_document'] ?? null;
            $hasRevised = $revisedInfo && ($revisedInfo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            if (!$routeSlipInfo || ($routeSlipInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Please upload the route slip PDF from your adviser.';
            } elseif (!is_pdf_upload($routeSlipInfo)) {
                $error = 'Route slip must be a PDF file.';
            } elseif ($hasRevised && !is_pdf_upload($revisedInfo)) {
                $error = 'Revised manuscript must be a PDF file.';
            } else {
                $filePath = $currentFile;
                $safeName = $currentSubmission['file_name'] ?? '';
                if ($hasRevised) {
                    $uploadDir = 'uploads/outline_defense/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($revisedInfo['name']));
                    $filename = 'outline_defense_final_' . $studentId . '_' . date('Ymd_His') . '_' . $safeName;
                    $filePath = $uploadDir . $filename;
                    if (!move_uploaded_file($revisedInfo['tmp_name'], $filePath)) {
                        $error = 'Unable to upload the revised manuscript. Please try again.';
                    }
                }

                if ($error === '') {
                    $slipDir = 'uploads/route_slips/';
                    if (!is_dir($slipDir)) {
                        mkdir($slipDir, 0777, true);
                    }
                    $safeSlipName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($routeSlipInfo['name']));
                    $slipFilename = 'route_slip_final_' . $studentId . '_' . date('Ymd_His') . '_' . $safeSlipName;
                    $slipPath = $slipDir . $slipFilename;
                    if (!move_uploaded_file($routeSlipInfo['tmp_name'], $slipPath)) {
                        $error = 'Unable to upload the route slip PDF. Please try again.';
                        if ($hasRevised && $filePath !== $currentFile) {
                            @unlink($filePath);
                        }
                    } else {
                        if ($hasRevised && !empty($currentFile) && $currentFile !== $filePath && file_exists($currentFile)) {
                            @unlink($currentFile);
                        }
                        if (!empty($currentRouteSlip) && $currentRouteSlip !== $slipPath && file_exists($currentRouteSlip)) {
                            @unlink($currentRouteSlip);
                        }

                        $updateStmt = $conn->prepare("
                            UPDATE final_paper_submissions
                            SET file_path = ?,
                                file_name = ?,
                                route_slip_path = ?,
                                route_slip_name = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        if ($updateStmt) {
                            $updateStmt->bind_param(
                                'ssssi',
                                $filePath,
                                $safeName,
                                $slipPath,
                                $safeSlipName,
                                $currentSubmission['id']
                            );
                            if ($updateStmt->execute()) {
                                foreach ($reviewers as $reviewer) {
                                    $reviewerId = (int)($reviewer['reviewer_id'] ?? 0);
                                    $reviewerRole = trim((string)($reviewer['reviewer_role'] ?? ''));
                                    if ($reviewerId <= 0 || $reviewerRole === '') {
                                        continue;
                                    }
                                    $link = 'route_slip_review.php?submission_id=' . (int)$currentSubmission['id'];
                                    notify_user_for_role(
                                        $conn,
                                        $reviewerId,
                                        $reviewerRole,
                                        'Route slip issued by adviser',
                                        "{$studentName} submitted the route slip" . ($hasRevised ? ' and revised manuscript' : '') . " for review.",
                                        $link
                                    );
                                }
                                $success = $hasRevised
                                    ? 'Route slip and revised manuscript sent to the defense committee.'
                                    : 'Route slip sent to the defense committee.';
                                $currentSubmission = fetchLatestFinalPaperSubmission($conn, $studentId);
                                $currentStatus = trim((string)($currentSubmission['status'] ?? ''));
                                $currentVersion = (int)($currentSubmission['version'] ?? 0);
                                $currentFile = $currentSubmission['file_path'] ?? '';
                                $currentRouteSlip = $currentSubmission['route_slip_path'] ?? '';
                            } else {
                                $error = 'Unable to update the route slip packet submission.';
                            }
                            $updateStmt->close();
                        } else {
                            $error = 'Unable to prepare the route slip packet update.';
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_final_paper'])) {
    $formValues['final_title'] = trim($_POST['final_title'] ?? '');
    $formValues['introduction'] = trim($_POST['introduction'] ?? '');
    $formValues['background'] = trim($_POST['background'] ?? '');
    $formValues['methodology'] = trim($_POST['methodology'] ?? '');
    $formValues['notes'] = trim($_POST['notes'] ?? '');

    if (!$canSubmit) {
        $error = $formEnabled
            ? 'Your outline defense manuscript is already under review.'
            : 'Final pick is not available yet. Please wait for the program chairperson.';
    } elseif ($formValues['final_title'] === '') {
        $error = 'Final title is required.';
    } else {
        $reviewers = fetchFinalPaperReviewersForStudent($conn, $studentId);
        if (empty($reviewers)) {
            $error = 'Defense panel assignments are missing. Please contact the program chairperson.';
        } else {
            $fileInfo = $_FILES['final_document'] ?? null;
            $routeSlipInfo = $_FILES['route_slip_document'] ?? null;
            if (!$fileInfo || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Please upload the outline defense manuscript PDF.';
            } elseif (!is_pdf_upload($fileInfo)) {
                $error = 'Only PDF files are allowed.';
            } elseif ($routeSlipInfo && ($routeSlipInfo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !is_pdf_upload($routeSlipInfo)) {
                $error = 'Route slip must be a PDF file.';
            } else {
                $uploadDir = 'uploads/outline_defense/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($fileInfo['name']));
                $filename = 'outline_defense_' . $studentId . '_' . date('Ymd_His') . '_' . $safeName;
                $filePath = $uploadDir . $filename;

                if (!move_uploaded_file($fileInfo['tmp_name'], $filePath)) {
                    $error = 'Unable to upload the outline defense manuscript. Please try again.';
                } else {
                    $routeSlipPath = $currentRouteSlip;
                    $routeSlipName = $currentSubmission['route_slip_name'] ?? '';
                    if ($routeSlipInfo && ($routeSlipInfo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $slipDir = 'uploads/route_slips/';
                        if (!is_dir($slipDir)) {
                            mkdir($slipDir, 0777, true);
                        }
                        $safeSlipName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($routeSlipInfo['name']));
                        $slipFilename = 'route_slip_student_' . $studentId . '_' . date('Ymd_His') . '_' . $safeSlipName;
                        $slipPath = $slipDir . $slipFilename;
                        if (!move_uploaded_file($routeSlipInfo['tmp_name'], $slipPath)) {
                            $error = 'Unable to upload the route slip PDF. Please try again.';
                            @unlink($filePath);
                        } else {
                            if (!empty($routeSlipPath) && $routeSlipPath !== $slipPath && file_exists($routeSlipPath)) {
                                @unlink($routeSlipPath);
                            }
                            $routeSlipPath = $slipPath;
                            $routeSlipName = $safeSlipName;
                        }
                    }

                    if ($error === '') {
                        $submissionId = 0;
                        if ($currentSubmission && $canResubmit) {
                            if (!empty($currentFile) && $currentFile !== $filePath && file_exists($currentFile)) {
                                @unlink($currentFile);
                            }
                            $newVersion = max(1, $currentVersion + 1);
                            $updateStmt = $conn->prepare("
                                UPDATE final_paper_submissions
                                SET final_title = ?,
                                    introduction = ?,
                                    background = ?,
                                    methodology = ?,
                                    submission_notes = ?,
                                    file_path = ?,
                                    file_name = ?,
                                    route_slip_path = ?,
                                    route_slip_name = ?,
                                    status = 'Submitted',
                                    version = ?,
                                    submitted_at = NOW(),
                                    final_decision_by = NULL,
                                    final_decision_notes = NULL,
                                    final_decision_at = NULL
                                WHERE id = ?
                            ");
                            if ($updateStmt) {
                                $updateStmt->bind_param(
                                    'sssssssssii',
                                    $formValues['final_title'],
                                    $formValues['introduction'],
                                    $formValues['background'],
                                    $formValues['methodology'],
                                    $formValues['notes'],
                                    $filePath,
                                    $safeName,
                                    $routeSlipPath,
                                    $routeSlipName,
                                    $newVersion,
                                    $currentSubmission['id']
                                );
                                if ($updateStmt->execute()) {
                                    $submissionId = (int)$currentSubmission['id'];
                                } else {
                                    $error = 'Unable to update the outline defense manuscript submission.';
                                }
                                $updateStmt->close();
                            } else {
                                $error = 'Unable to prepare the outline defense submission.';
                            }
                        } else {
                            $insertStmt = $conn->prepare("
                                INSERT INTO final_paper_submissions
                                    (student_id, final_title, introduction, background, methodology, submission_notes, file_path, file_name, route_slip_path, route_slip_name, status, version)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', 1)
                            ");
                            if ($insertStmt) {
                                $insertStmt->bind_param(
                                    'isssssssss',
                                    $studentId,
                                    $formValues['final_title'],
                                    $formValues['introduction'],
                                    $formValues['background'],
                                    $formValues['methodology'],
                                    $formValues['notes'],
                                    $filePath,
                                    $safeName,
                                    $routeSlipPath,
                                    $routeSlipName
                                );
                                if ($insertStmt->execute()) {
                                    $submissionId = (int)$insertStmt->insert_id;
                                } else {
                                    $error = 'Unable to save the outline defense manuscript submission.';
                                }
                                $insertStmt->close();
                            } else {
                                $error = 'Unable to prepare the outline defense submission.';
                            }
                        }

                        if ($submissionId > 0 && $error === '') {
                            replaceFinalPaperReviews($conn, $submissionId, $reviewers);
                            foreach ($reviewers as $reviewer) {
                                $reviewerId = (int)($reviewer['reviewer_id'] ?? 0);
                                $reviewerRole = trim((string)($reviewer['reviewer_role'] ?? ''));
                                if ($reviewerId <= 0 || $reviewerRole === '') {
                                    continue;
                                }
                                $link = 'final_paper_inbox.php?review_submission_id=' . $submissionId;
                                notify_user_for_role(
                                    $conn,
                                    $reviewerId,
                                    $reviewerRole,
                                    'Outline defense manuscript submitted',
                                    "{$studentName} submitted an outline defense manuscript for review.",
                                    $link
                                );
                            }

                            $success = $canResubmit
                                ? 'Outline defense manuscript resubmitted successfully.'
                                : 'Outline defense manuscript submitted successfully.';
                            $currentSubmission = fetchLatestFinalPaperSubmission($conn, $studentId);
                            $currentStatus = trim((string)($currentSubmission['status'] ?? ''));
                            $currentVersion = (int)($currentSubmission['version'] ?? 1);
                            $currentRouteSlip = $currentSubmission['route_slip_path'] ?? '';
                            $canResubmit = $currentSubmission && in_array($currentStatus, ['Needs Revision', 'Minor Revision', 'Major Revision', 'Rejected'], true);
                            $canSubmit = $formEnabled && (!$currentSubmission || $canResubmit);
                        } elseif ($error !== '') {
                            @unlink($filePath);
                        }
                    }
                }
            }
        }
    }
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Outline Defense Manuscript Submission</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .hero-card { border-radius: 20px; background: linear-gradient(130deg, #16562c, #0f3d1f); color: #fff; padding: 28px; }
        .info-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .form-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .form-label { font-weight: 600; color: #16562c; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="hero-card mb-4">
            <h3 class="fw-bold mb-1">Outline Defense Manuscript Submission</h3>
            <p class="mb-0 text-white-50">Upload your outline defense manuscript (PDF only) for adviser, committee chairperson, and panel review.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success); ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card info-card p-4">
                    <h5 class="fw-bold text-success mb-2">Submission Status</h5>
                    <?php if ($currentSubmission): ?>
                        <p class="mb-1"><strong>Outline Defense Title:</strong> <?= htmlspecialchars($currentSubmission['final_title'] ?? ''); ?></p>
                        <p class="mb-1"><strong>Status:</strong> <?= htmlspecialchars(finalPaperStatusLabel($currentStatus ?: 'Submitted')); ?></p>
                        <p class="mb-1"><strong>Version:</strong> <?= htmlspecialchars((string)max(1, $currentVersion)); ?></p>
                        <p class="mb-0"><strong>Last Submitted:</strong> <?= htmlspecialchars($currentSubmission['submitted_at'] ?? ''); ?></p>
                    <?php else: ?>
                        <p class="mb-1">No outline defense manuscript submitted yet.</p>
                    <?php endif; ?>
                    <?php if (!$formEnabled): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <?php if (!$memoApproved): ?>
                                Outline defense memo has not been approved yet. Please wait for the program chairperson.
                            <?php else: ?>
                                Please open the outline defense memo to enable manuscript submission.
                            <?php endif; ?>
                        </div>
                    <?php elseif ($currentSubmission && !$canResubmit): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            Your submission is under review. You can resubmit only if it is marked for revision.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card form-card p-4">
                    <h5 class="fw-bold text-success mb-3">Upload Outline Defense Manuscript (PDF)</h5>
                    <form method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Outline Defense Title</label>
                            <input type="text"
                                   name="final_title"
                                   class="form-control"
                                   value="<?= htmlspecialchars($formValues['final_title']); ?>"
                                   <?= ($memoReady && $finalPickTitle !== '') ? 'readonly' : ''; ?>
                                   required>
                            <div class="form-text">Auto-filled from your outline defense memo.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (optional)</label>
                            <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($formValues['notes']); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Outline Defense Manuscript PDF</label>
                            <input type="file" name="final_document" class="form-control" accept="application/pdf" required>
                            <div class="form-text">PDF only to keep the formatting consistent for panel review.</div>
                        </div>
                        <div class="alert alert-light border small mb-3">
                            <strong>Submission tips:</strong> use a clear filename, verify all pages are readable, and confirm the title matches your final pick.
                        </div>
                        <button type="submit" name="submit_final_paper" class="btn btn-success"
                            <?= $canSubmit ? '' : 'disabled'; ?>>
                            Submit Manuscript
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($canSendPacket): ?>
            <div class="card form-card p-4 mt-4">
                <h5 class="fw-bold text-success mb-3">Send Route Slip Packet</h5>
                <p class="text-muted mb-3">Upload your revised manuscript and the adviser-issued route slip for committee review.</p>
                <?php if ($currentStatus !== 'Approved'): ?>
                    <div class="alert alert-warning small mb-3">
                        Your submission is not marked as Passed yet. You can still send the route slip packet, and the committee will see it as an early update.
                    </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Revised Manuscript PDF</label>
                        <input type="file" name="revised_document" class="form-control" accept="application/pdf">
                        <div class="form-text">Optional if you only need to send the route slip now.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Route Slip PDF (from Adviser)</label>
                        <?php if ($latestRouteSlip): ?>
                            <div class="alert alert-light border small mb-2">
                                Latest route slip from <?= htmlspecialchars($latestRouteSlip['adviser_name'] ?? 'Adviser'); ?> -
                                <?= htmlspecialchars($latestRouteSlipDate); ?>.
                                <a href="<?= htmlspecialchars($latestRouteSlip['file_path'] ?? '#'); ?>" target="_blank" class="text-decoration-none">Download</a>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="route_slip_document" class="form-control" accept="application/pdf" required>
                    </div>
                    <button type="submit" name="submit_route_slip_packet" class="btn btn-outline-success">
                        Send Route Slip Packet
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card info-card p-4 mt-4">
            <h5 class="fw-bold text-success mb-3">Reviewer Feedback & Verdict</h5>
            <?php if (!$currentSubmission): ?>
                <p class="mb-0 text-muted">Submit your manuscript to receive feedback from the committee.</p>
            <?php else: ?>
                <!-- Outline Defense Verdict -->
                <?php
                $verdictInfo = getOutlineDefenseVerdict($conn, (int)$currentSubmission['id']);
                $verdict = $verdictInfo ? trim((string)($verdictInfo['outline_defense_verdict'] ?? '')) : '';
                $verdictAt = $verdictInfo ? ($verdictInfo['outline_defense_verdict_at'] ?? null) : null;
                ?>
                <?php if ($verdict !== ''): ?>
                    <div class="mb-4 p-3 rounded-3" style="background: linear-gradient(135deg, rgba(22, 86, 44, 0.1), rgba(22, 86, 44, 0.05)); border-left: 4px solid #16562c;">
                        <div class="fw-semibold text-success mb-2">Outline Defense Verdict</div>
                        <div class="d-flex align-items-center gap-3">
                            <span class="badge <?= outlineDefenseVerdictClass($verdict); ?>" style="font-size: 1rem; padding: 0.6rem 1.2rem;">
                                <?= htmlspecialchars(outlineDefenseVerdictLabel($verdict)); ?>
                            </span>
                            <?php if ($verdictAt): ?>
                                <span class="small text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= htmlspecialchars(date('M d, Y g:i A', strtotime($verdictAt))); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mb-4">
                    <div class="fw-semibold text-success mb-1">Overall Decision (Committee Chairperson)</div>
                    <div class="small text-muted mb-2">
                        Status: <?= htmlspecialchars(finalPaperStatusLabel($currentStatus ?: 'Submitted')); ?>
                    </div>
                    <?php if (!empty($currentSubmission['final_decision_notes'])): ?>
                        <div class="border rounded-3 p-3 bg-light">
                            <?= nl2br(htmlspecialchars($currentSubmission['final_decision_notes'])); ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">No overall decision note yet.</div>
                    <?php endif; ?>
                </div>

                <div class="fw-semibold text-success mb-2">Manuscript Reviewer Comments</div>
                <?php if (empty($reviewSummary)): ?>
                    <p class="mb-0 text-muted">No reviewer comments yet.</p>
                <?php else: ?>
                    <div class="row g-3">
                        <?php
                        $roleLabels = [
                            'adviser' => 'Adviser',
                            'committee_chairperson' => 'Committee Chairperson',
                            'panel' => 'Panel Member',
                        ];
                        ?>
                        <?php foreach ($reviewSummary as $review): ?>
                            <?php
                            $roleKey = $review['reviewer_role'] ?? '';
                            $roleLabel = $roleLabels[$roleKey] ?? ucfirst($roleKey);
                            $reviewerName = $review['reviewer_name'] ?? 'Reviewer';
                            $reviewStatus = finalPaperStatusLabel($review['status'] ?? 'Pending');
                            $reviewComments = trim((string)($review['comments'] ?? ''));
                            ?>
                            <div class="col-lg-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="fw-semibold"><?= htmlspecialchars($reviewerName); ?></div>
                                    <div class="small text-muted mb-2"><?= htmlspecialchars($roleLabel); ?> - <?= htmlspecialchars($reviewStatus); ?></div>
                                    <?php if ($reviewComments !== ''): ?>
                                        <div class="small"><?= nl2br(htmlspecialchars($reviewComments)); ?></div>
                                    <?php else: ?>
                                        <div class="small text-muted">No comment yet.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Route Slip Overall Decision -->
                <?php if (!empty($currentSubmission['route_slip_overall_decision'])): ?>
                <div class="mb-4 p-3 rounded-3 mt-4" style="background: linear-gradient(135deg, rgba(13, 110, 253, 0.1), rgba(13, 110, 253, 0.05)); border-left: 4px solid #0d6efd;">
                    <div class="fw-semibold text-primary mb-2">Route Slip Overall Decision</div>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="badge <?= finalPaperReviewStatusClass($currentSubmission['route_slip_overall_decision']); ?>" style="font-size: 1rem; padding: 0.6rem 1.2rem;">
                            <?= htmlspecialchars($currentSubmission['route_slip_overall_decision']); ?>
                        </span>
                        <?php if (!empty($currentSubmission['route_slip_decision_at'])): ?>
                            <span class="small text-muted">
                                <i class="bi bi-clock me-1"></i>
                                <?= htmlspecialchars(date('M d, Y g:i A', strtotime($currentSubmission['route_slip_decision_at']))); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($currentSubmission['route_slip_decision_notes'])): ?>
                        <div class="border rounded-3 p-3 bg-white">
                            <div class="small fw-semibold text-muted mb-1">Decision Notes:</div>
                            <?= nl2br(htmlspecialchars($currentSubmission['route_slip_decision_notes'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Route Slip Reviews Section -->
                <div class="fw-semibold text-success mb-2 mt-4">Route Slip Reviews</div>
                <?php if (empty($reviewSummary)): ?>
                    <p class="mb-0 text-muted">No route slip reviews yet.</p>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($reviewSummary as $review): ?>
                            <?php
                            $roleKey = $review['reviewer_role'] ?? '';
                            $roleLabel = $roleLabels[$roleKey] ?? ucfirst($roleKey);
                            $reviewerName = $review['reviewer_name'] ?? 'Reviewer';
                            $routeSlipStatus = finalPaperStatusLabel($review['route_slip_status'] ?? 'Pending');
                            $routeSlipComments = trim((string)($review['route_slip_comments'] ?? ''));
                            $routeSlipReviewedAt = $review['route_slip_reviewed_at'] ?? null;
                            ?>
                            <div class="col-lg-6">
                                <div class="border rounded-3 p-3 h-100" style="border-left: 3px solid #0d6efd !important;">
                                    <div class="fw-semibold"><?= htmlspecialchars($reviewerName); ?></div>
                                    <div class="small text-muted mb-2">
                                        <?= htmlspecialchars($roleLabel); ?> - <?= htmlspecialchars($routeSlipStatus); ?>
                                    </div>
                                    <?php if ($routeSlipComments !== ''): ?>
                                        <div class="small"><?= nl2br(htmlspecialchars($routeSlipComments)); ?></div>
                                    <?php else: ?>
                                        <div class="small text-muted">No route slip comment yet.</div>
                                    <?php endif; ?>
                                    <?php if ($routeSlipReviewedAt): ?>
                                        <div class="small text-muted mt-2">
                                            <i class="bi bi-clock me-1"></i>
                                            <?= htmlspecialchars(date('M d, Y g:i A', strtotime($routeSlipReviewedAt))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
