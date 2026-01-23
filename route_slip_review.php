<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_paper_helpers.php';
require_once 'notice_commence_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chairperson', 'committee_chair'];
enforce_role_access($allowedRoles);

ensureFinalPaperTables($conn);

$submissionId = (int)($_GET['submission_id'] ?? 0);
$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewerRoleFilter = $roleMap[$role] ?? $role;

$submission = null;
if ($submissionId > 0) {
    $stmt = $conn->prepare("
        SELECT s.*, u.firstname AS student_firstname, u.lastname AS student_lastname,
               CONCAT(u.firstname, ' ', u.lastname) AS student_name, u.email AS student_email
        FROM final_paper_submissions s
        JOIN users u ON u.id = s.student_id
        WHERE s.id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $submission = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();
    }
}

if (!$submission) {
    header('Location: final_paper_inbox.php');
    exit;
}

$reviewRow = null;
if (in_array($reviewerRoleFilter, ['adviser', 'panel', 'committee_chairperson'], true)) {
    $reviewStmt = $conn->prepare("
        SELECT *
        FROM final_paper_reviews
        WHERE submission_id = ? AND reviewer_id = ? AND reviewer_role = ?
        LIMIT 1
    ");
    if ($reviewStmt) {
        $reviewStmt->bind_param('iis', $submissionId, $reviewerId, $reviewerRoleFilter);
        $reviewStmt->execute();
        $reviewResult = $reviewStmt->get_result();
        $reviewRow = $reviewResult ? $reviewResult->fetch_assoc() : null;
        if ($reviewResult) {
            $reviewResult->free();
        }
        $reviewStmt->close();
    }
}
if (!$reviewRow) {
    header('Location: final_paper_inbox.php');
    exit;
}

$reviews = fetchFinalPaperReviews($conn, $submissionId);
$canFinalize = in_array($role, ['committee_chairperson', 'committee_chair'], true);

$panelReviews = array_values(array_filter($reviews, function ($review) {
    return ($review['reviewer_role'] ?? '') === 'panel';
}));
usort($panelReviews, function ($a, $b) {
    return (int)($a['reviewer_id'] ?? 0) <=> (int)($b['reviewer_id'] ?? 0);
});
$panel1 = $panelReviews[0] ?? [];
$panel2 = $panelReviews[1] ?? [];
$chairReview = null;
$adviserReview = null;
foreach ($reviews as $review) {
    $roleValue = $review['reviewer_role'] ?? '';
    if ($roleValue === 'committee_chairperson' && $chairReview === null) {
        $chairReview = $review;
    }
    if ($roleValue === 'adviser' && $adviserReview === null) {
        $adviserReview = $review;
    }
}
$signatureSlots = [
    [
        'label' => 'Panel Member 1',
        'name' => $panel1['reviewer_name'] ?? '',
        'path' => $panel1['route_slip_signature_path'] ?? '',
    ],
    [
        'label' => 'Panel Member 2',
        'name' => $panel2['reviewer_name'] ?? '',
        'path' => $panel2['route_slip_signature_path'] ?? '',
    ],
    [
        'label' => 'Committee Chairperson',
        'name' => $chairReview['reviewer_name'] ?? '',
        'path' => $chairReview['route_slip_signature_path'] ?? '',
    ],
    [
        'label' => 'Adviser',
        'name' => $adviserReview['reviewer_name'] ?? '',
        'path' => $adviserReview['route_slip_signature_path'] ?? '',
    ],
];

$isSummaryRequest = isset($_GET['summary']) && $_GET['summary'] === '1';
if ($isSummaryRequest) {
    $summaryRows = [];
    foreach ($reviews as $review) {
        $status = $review['route_slip_status'] ?? '';
        if (strcasecmp($status, 'Needs Revision') === 0) {
            $status = 'Minor Revision';
        }
        $statusLabel = $status !== '' ? $status : 'Pending';
        $summaryRows[] = [
            'reviewer_name' => $review['reviewer_name'] ?? '',
            'reviewer_role' => $review['reviewer_role'] ?? '',
            'status_label' => $statusLabel,
            'status_class' => finalPaperReviewStatusClass($statusLabel),
            'reviewed_at' => $review['route_slip_reviewed_at'] ?? '',
            'signature_path' => $review['route_slip_signature_path'] ?? '',
        ];
    }
    $panelReviews = array_values(array_filter($reviews, function ($review) {
        return ($review['reviewer_role'] ?? '') === 'panel';
    }));
    usort($panelReviews, function ($a, $b) {
        return (int)($a['reviewer_id'] ?? 0) <=> (int)($b['reviewer_id'] ?? 0);
    });
    $panel1 = $panelReviews[0] ?? [];
    $panel2 = $panelReviews[1] ?? [];
    $chairReview = null;
    $adviserReview = null;
    foreach ($reviews as $review) {
        $roleValue = $review['reviewer_role'] ?? '';
        if ($roleValue === 'committee_chairperson' && $chairReview === null) {
            $chairReview = $review;
        }
        if ($roleValue === 'adviser' && $adviserReview === null) {
            $adviserReview = $review;
        }
    }
    $signatureSlots = [
        [
            'label' => 'Panel Member 1',
            'name' => $panel1['reviewer_name'] ?? '',
            'path' => $panel1['route_slip_signature_path'] ?? '',
        ],
        [
            'label' => 'Panel Member 2',
            'name' => $panel2['reviewer_name'] ?? '',
            'path' => $panel2['route_slip_signature_path'] ?? '',
        ],
        [
            'label' => 'Committee Chairperson',
            'name' => $chairReview['reviewer_name'] ?? '',
            'path' => $chairReview['route_slip_signature_path'] ?? '',
        ],
        [
            'label' => 'Adviser',
            'name' => $adviserReview['reviewer_name'] ?? '',
            'path' => $adviserReview['route_slip_signature_path'] ?? '',
        ],
    ];
    header('Content-Type: application/json');
    echo json_encode(['reviews' => $summaryRows, 'signature_slots' => $signatureSlots], JSON_UNESCAPED_UNICODE);
    exit;
}

$reviewSuccess = '';
$reviewError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_route_slip_review'])) {
    $newStatus = trim($_POST['route_slip_status'] ?? '');
    $comments = trim($_POST['route_slip_comments'] ?? '');
    $signatureError = '';
    $signaturePath = '';
    if (isset($_FILES['route_slip_signature'])) {
        $signaturePath = save_notice_signature_upload($_FILES['route_slip_signature'], $reviewerId, $signatureError);
        if ($signatureError !== '') {
            $reviewError = $signatureError;
        }
    }

    if ($reviewError === '' && !in_array($newStatus, ['Approved', 'Minor Revision', 'Major Revision'], true)) {
        $reviewError = 'Please choose a valid route slip decision.';
    } elseif ($reviewError === '') {
        if ($signaturePath !== '') {
            $stmt = $conn->prepare("
                UPDATE final_paper_reviews
                SET route_slip_status = ?,
                    route_slip_comments = ?,
                    route_slip_signature_path = ?,
                    route_slip_reviewed_at = NOW()
                WHERE submission_id = ? AND reviewer_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                UPDATE final_paper_reviews
                SET route_slip_status = ?,
                    route_slip_comments = ?,
                    route_slip_reviewed_at = NOW()
                WHERE submission_id = ? AND reviewer_id = ?
            ");
        }
        if ($stmt) {
            if ($signaturePath !== '') {
                $stmt->bind_param('sssii', $newStatus, $comments, $signaturePath, $submissionId, $reviewerId);
            } else {
                $stmt->bind_param('ssii', $newStatus, $comments, $submissionId, $reviewerId);
            }
            if ($stmt->execute()) {
                $reviewSuccess = 'Route slip review saved successfully.';
                $reviewRow = fetchFinalPaperReviewForUser($conn, $submissionId, $reviewerId);
                $reviews = fetchFinalPaperReviews($conn, $submissionId);

                $committeeTotal = 0;
                $committeeSigned = 0;
                foreach ($reviews as $review) {
                    $roleValue = $review['reviewer_role'] ?? '';
                    if ($roleValue === 'panel' || $roleValue === 'committee_chairperson') {
                        $committeeTotal++;
                        if (!empty($review['route_slip_signature_path'])) {
                            $committeeSigned++;
                        }
                    }
                }
                if ($committeeTotal > 0 && $committeeSigned === $committeeTotal && empty($submission['route_slip_committee_signed_at'])) {
                    $signedStmt = $conn->prepare("
                        UPDATE final_paper_submissions
                        SET route_slip_committee_signed_at = NOW()
                        WHERE id = ?
                    ");
                    if ($signedStmt) {
                        $signedStmt->bind_param('i', $submissionId);
                        $signedStmt->execute();
                        $signedStmt->close();
                        $submission['route_slip_committee_signed_at'] = date('Y-m-d H:i:s');
                    }

                    $adviserId = 0;
                    $adviserStmt = $conn->prepare("
                        SELECT reviewer_id
                        FROM final_paper_reviews
                        WHERE submission_id = ? AND reviewer_role = 'adviser'
                        LIMIT 1
                    ");
                    if ($adviserStmt) {
                        $adviserStmt->bind_param('i', $submissionId);
                        $adviserStmt->execute();
                        $adviserResult = $adviserStmt->get_result();
                        $adviserRow = $adviserResult ? $adviserResult->fetch_assoc() : null;
                        if ($adviserResult) {
                            $adviserResult->free();
                        }
                        $adviserStmt->close();
                        $adviserId = (int)($adviserRow['reviewer_id'] ?? 0);
                    }
                    if ($adviserId > 0) {
                        $studentName = $submission['student_name'] ?? 'the student';
                        notify_user_for_role(
                            $conn,
                            $adviserId,
                            'adviser',
                            'Route slip signatures completed',
                            "All committee signatures for {$studentName} are complete. Please add your final signature.",
                            "adviser_route_slip.php?submission_id={$submissionId}",
                            false
                        );
                    }
                    $studentId = (int)($submission['student_id'] ?? 0);
                    if ($studentId > 0) {
                        notify_user_for_role(
                            $conn,
                            $studentId,
                            'student',
                            'Committee signatures completed',
                            'All defense committee signatures are complete. Waiting for the adviser to finalize the route slip.',
                            "route_slip_review.php?submission_id={$submissionId}",
                            false
                        );
                    }
                }

                // Check if all route slip reviews are completed
                $allRouteSlipReviewsComplete = true;
                foreach ($reviews as $review) {
                    $slipStatus = strtolower(trim((string)($review['route_slip_status'] ?? '')));
                    if ($slipStatus === '' || $slipStatus === 'pending') {
                        $allRouteSlipReviewsComplete = false;
                        break;
                    }
                }

                // If all route slip reviews are complete and this is from committee chairperson, notify student and dean
                if ($allRouteSlipReviewsComplete && $reviewerRoleFilter === 'committee_chairperson') {
                    $studentId = (int)($submission['student_id'] ?? 0);
                    $studentName = $submission['student_name'] ?? 'Student';
                    $reviewerName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
                    $reviewerName = $reviewerName !== '' ? $reviewerName : 'Committee Chairperson';

                    // Notify student
                    notify_user(
                        $conn,
                        $studentId,
                        'Route slip review completed',
                        "{$reviewerName} has completed the route slip review. Decision: {$newStatus}. Please check your submission page for details.",
                        'submit_final_paper.php',
                        false
                    );

                    // Notify dean
                    notify_role(
                        $conn,
                        'dean',
                        'Route slip final decision made',
                        "Committee Chairperson has completed the route slip review for {$studentName}. Decision: {$newStatus}.",
                        'submit_final_paper.php',
                        false
                    );
                }
            } else {
                $reviewError = 'Unable to save your route slip review.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the route slip review update.';
        }
    }
}

// Handle overall route slip decision from committee chairperson
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_overall_route_slip_decision']) && $canFinalize) {
    $overallDecision = trim($_POST['overall_decision'] ?? '');
    $decisionNotes = trim($_POST['decision_notes'] ?? '');

    if (!in_array($overallDecision, ['Approved', 'Minor Revision', 'Major Revision', 'Rejected'], true)) {
        $reviewError = 'Please choose a valid overall decision.';
    } else {
        $stmt = $conn->prepare("
            UPDATE final_paper_submissions
            SET route_slip_overall_decision = ?,
                route_slip_decision_notes = ?,
                route_slip_decision_by = ?,
                route_slip_decision_at = NOW()
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('ssii', $overallDecision, $decisionNotes, $reviewerId, $submissionId);
            if ($stmt->execute()) {
                $reviewSuccess = 'Overall route slip decision saved successfully.';
                $submission = fetchFinalPaperSubmission($conn, $submissionId);

                if ($overallDecision === 'Approved') {
                    $statusStmt = $conn->prepare("
                        UPDATE final_paper_submissions
                        SET status = 'Approved'
                        WHERE id = ?
                    ");
                    if ($statusStmt) {
                        $statusStmt->bind_param('i', $submissionId);
                        $statusStmt->execute();
                        $statusStmt->close();
                        $submission['status'] = 'Approved';
                    }
                }

                $studentId = (int)($submission['student_id'] ?? 0);
                $studentName = $submission['student_name'] ?? 'Student';
                $chairName = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
                $chairName = $chairName !== '' ? $chairName : 'Committee Chairperson';
                $programChairId = 0;
                $programChairStmt = $conn->prepare("
                    SELECT u.id
                    FROM users u
                    JOIN users s ON s.program = u.program
                    WHERE s.id = ? AND u.role = 'program_chairperson'
                    LIMIT 1
                ");
                if ($programChairStmt) {
                    $programChairStmt->bind_param('i', $studentId);
                    $programChairStmt->execute();
                    $chairResult = $programChairStmt->get_result();
                    $chairRow = $chairResult ? $chairResult->fetch_assoc() : null;
                    if ($chairResult) {
                        $chairResult->free();
                    }
                    $programChairStmt->close();
                    $programChairId = (int)($chairRow['id'] ?? 0);
                }

                // Notify student
                notify_user(
                    $conn,
                    $studentId,
                    'Route slip overall decision made',
                    "{$chairName} has made the overall decision on your route slip. Decision: {$overallDecision}. Please check your submission page for details.",
                    'submit_final_paper.php',
                    false
                );

                // Auto-create Notice to Commence if approved
                if ($overallDecision === 'Approved') {
                    ensureNoticeCommenceTable($conn);
                    
                    // Check if notice already exists
                    $checkStmt = $conn->prepare("
                        SELECT id FROM notice_to_commence_requests
                        WHERE submission_id = ? AND status IN ('Pending', 'Approved')
                        LIMIT 1
                    ");
                    $noticeExists = false;
                    if ($checkStmt) {
                        $checkStmt->bind_param('i', $submissionId);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        $noticeExists = $checkResult && $checkResult->num_rows > 0;
                        if ($checkResult) $checkResult->free();
                        $checkStmt->close();
                    }
                    
                    if (!$noticeExists) {
                        if ($programChairId > 0) {
                            $noticeDate = date('Y-m-d');
                            $startDate = date('Y-m-d');
                            $subject = 'NOTIFICATION TO COMMENCE THE APPROVED PROPOSAL';
                            $finalTitle = $submission['final_title'] ?? 'the research proposal';
                            $studentProgram = '';
                            
                            // Get student program
                            $studentProgramStmt = $conn->prepare("SELECT program FROM users WHERE id = ? LIMIT 1");
                            if ($studentProgramStmt) {
                                $studentProgramStmt->bind_param('i', $studentId);
                                $studentProgramStmt->execute();
                                $progResult = $studentProgramStmt->get_result();
                                $progRow = $progResult ? $progResult->fetch_assoc() : null;
                                if ($progResult) $progResult->free();
                                $studentProgramStmt->close();
                                $studentProgram = $progRow['program'] ?? '';
                            }
                            
                            $body = build_notice_commence_body($studentName, $finalTitle, $studentProgram, $startDate);
                            
                            // Create notice to commence
                            $insertNoticeStmt = $conn->prepare("
                                INSERT INTO notice_to_commence_requests
                                    (student_id, submission_id, program_chair_id, status, notice_date, start_date, subject, body)
                                VALUES (?, ?, ?, 'Pending', ?, ?, ?, ?)
                            ");
                            if ($insertNoticeStmt) {
                                $insertNoticeStmt->bind_param(
                                    'iiissss',
                                    $studentId,
                                    $submissionId,
                                    $programChairId,
                                    $noticeDate,
                                    $startDate,
                                    $subject,
                                    $body
                                );
                                $insertNoticeStmt->execute();
                                $insertNoticeStmt->close();
                            }
                        }
                    }
                }

                // Notify program chairperson
                $noticeLink = $overallDecision === 'Approved'
                    ? "notice_to_commence.php?submission_id={$submissionId}"
                    : 'submit_final_paper.php';
                $noticeMsg = $overallDecision === 'Approved'
                    ? "Committee Chairperson has approved the route slip for {$studentName}. A Notice to Commence has been prepared for your review and submission to the Dean."
                    : "Committee Chairperson has made the overall route slip decision for {$studentName}. Decision: {$overallDecision}.";

                if ($programChairId > 0) {
                    notify_user(
                        $conn,
                        $programChairId,
                        'Route slip overall decision made',
                        $noticeMsg,
                        $noticeLink,
                        false
                    );
                } else {
                    notify_role(
                        $conn,
                        'program_chairperson',
                        'Route slip overall decision made',
                        $noticeMsg,
                        $noticeLink,
                        false
                    );
                }

                // Notify dean
                notify_role(
                    $conn,
                    'dean',
                    'Route slip overall decision made',
                    "Committee Chairperson has made the overall route slip decision for {$studentName}. Decision: {$overallDecision}.",
                    'submit_final_paper.php',
                    false
                );
            } else {
                $reviewError = 'Unable to save the overall decision.';
            }
            $stmt->close();
        } else {
            $reviewError = 'Unable to prepare the overall decision update.';
        }
    }
}

include 'header.php';
include 'sidebar.php';
$selectedRouteSlipStatus = $reviewRow['route_slip_status'] ?? '';
if ($selectedRouteSlipStatus === 'Needs Revision') {
    $selectedRouteSlipStatus = 'Minor Revision';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Slip Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .review-card { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .preview-frame { width: 100%; height: 600px; border: 1px solid #d9e5da; border-radius: 12px; }
        .review-table thead th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #556; }
        .signature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
        .signature-block { text-align: center; padding: 0.5rem; border: 1px solid rgba(22, 86, 44, 0.12); border-radius: 12px; background: #fff; }
        .signature-image { max-height: 60px; max-width: 180px; object-fit: contain; }
        .signature-line { border-top: 1px solid #1f2d22; margin: 10px auto 6px; width: 200px; }
        .signature-placeholder { color: #6c757d; font-size: 0.85rem; min-height: 22px; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Route Slip Review</h3>
                <p class="text-muted mb-0"><?= htmlspecialchars($submission['student_name'] ?? 'Student'); ?> - <?= htmlspecialchars($submission['final_title'] ?? ''); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="final_paper_review.php?submission_id=<?= (int)$submissionId; ?>" class="btn btn-outline-success">
                    <i class="bi bi-file-text me-1"></i>Manuscript Review
                </a>
                <a href="route_slip_inbox.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Inbox
                </a>
            </div>
        </div>

        <?php if ($reviewSuccess): ?>
            <div class="alert alert-success"><?= htmlspecialchars($reviewSuccess); ?></div>
        <?php elseif ($reviewError): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($reviewError); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card review-card p-3">
                    <h5 class="fw-bold text-success">Route Slip Preview</h5>
                    <?php if (!empty($submission['route_slip_path'])): ?>
                        <iframe class="preview-frame" src="<?= htmlspecialchars($submission['route_slip_path']); ?>"></iframe>
                    <?php else: ?>
                        <div class="text-muted">No route slip uploaded yet.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card review-card p-4">
                    <h5 class="fw-bold text-success mb-3">Route Slip Checklist</h5>
                    <form method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Decision</label>
                            <div class="border rounded-3 p-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="route_slip_status" id="slipApproved" value="Approved"
                                        <?= ($selectedRouteSlipStatus === 'Approved') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="slipApproved">Approval for the conduct of the study.</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="route_slip_status" id="slipMinor" value="Minor Revision"
                                        <?= ($selectedRouteSlipStatus === 'Minor Revision') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="slipMinor">Approval for the conduct of the study but still subjected to minor revisions and improvement.</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="route_slip_status" id="slipMajor" value="Major Revision"
                                        <?= ($selectedRouteSlipStatus === 'Major Revision') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="slipMajor">Disapproval. The paper needs further major revisions and improvement.</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Comments</label>
                            <textarea name="route_slip_comments" class="form-control" rows="4"><?= htmlspecialchars($reviewRow['route_slip_comments'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">E-Signature (PNG or JPG)</label>
                            <input type="file" name="route_slip_signature" class="form-control" accept="image/png,image/jpeg">
                            <div class="form-text">Upload your e-signature for the route slip.</div>
                            <?php if (!empty($reviewRow['route_slip_signature_path'])): ?>
                                <div class="mt-2">
                                    <?php
                                        $currentSigPath = $reviewRow['route_slip_signature_path'];
                                        $currentCacheBuster = is_file($currentSigPath) ? filemtime($currentSigPath) : time();
                                        $currentSigSrc = $currentSigPath . '?v=' . $currentCacheBuster;
                                    ?>
                                    <img src="<?= htmlspecialchars($currentSigSrc); ?>" alt="Route slip signature" style="max-height: 70px; max-width: 200px; object-fit: contain;">
                                </div>
                            <?php endif; ?>
                        </div>
                        <button type="submit" name="save_route_slip_review" class="btn btn-success">Save Route Slip Review</button>
                    </form>
                </div>

                <?php if ($canFinalize): ?>
                <div class="card review-card p-4 mt-4">
                    <h5 class="fw-bold text-success mb-3">Overall Route Slip Decision</h5>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Overall Decision</label>
                            <select name="overall_decision" class="form-select" required>
                                <option value="">-- Select Overall Decision --</option>
                                <option value="Approved" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="Minor Revision" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Minor Revision' ? 'selected' : ''; ?>>Approved with Minor Revision</option>
                                <option value="Major Revision" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Major Revision' ? 'selected' : ''; ?>>Approved with Major Revision</option>
                                <option value="Rejected" <?= ($submission['route_slip_overall_decision'] ?? '') === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <div class="form-text">Select the overall decision based on all route slip reviews.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-success">Decision Notes</label>
                            <textarea name="decision_notes" class="form-control" rows="4" placeholder="Provide the overall decision notes for the student..."><?= htmlspecialchars($submission['route_slip_decision_notes'] ?? ''); ?></textarea>
                            <div class="form-text">This message will be visible to the student and dean.</div>
                        </div>
                        <button type="submit" name="save_overall_route_slip_decision" class="btn btn-success">
                            <i class="bi bi-check-circle me-1"></i> Save Overall Decision
                        </button>
                    </form>
                    <?php if (!empty($submission['route_slip_decision_at'])): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Overall decision was made on <?= htmlspecialchars(date('M d, Y g:i A', strtotime($submission['route_slip_decision_at']))); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card review-card p-4 mt-4">
            <h5 class="fw-bold text-success mb-3">Route Slip Review Summary</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle review-table mb-0">
                    <thead>
                        <tr>
                            <th>Reviewer</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Reviewed At</th>
                            <th>Signature</th>
                        </tr>
                    </thead>
                    <tbody id="routeSlipSummaryBody">
                        <?php foreach ($reviews as $review): ?>
                            <?php
                                $status = $review['route_slip_status'] ?? '';
                                if (strcasecmp($status, 'Needs Revision') === 0) {
                                    $status = 'Minor Revision';
                                }
                                $statusLabel = $status !== '' ? $status : 'Pending';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($review['reviewer_name'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($review['reviewer_role'] ?? ''); ?></td>
                                <td><span class="badge <?= finalPaperReviewStatusClass($statusLabel); ?>"><?= htmlspecialchars($statusLabel); ?></span></td>
                                <td><?= htmlspecialchars($review['route_slip_reviewed_at'] ?? ''); ?></td>
                                <td>
                                    <?php if (!empty($review['route_slip_signature_path'])): ?>
                                        <?php
                                            $sigPath = $review['route_slip_signature_path'];
                                            $cacheBuster = is_file($sigPath) ? filemtime($sigPath) : time();
                                            $sigSrc = $sigPath . '?v=' . $cacheBuster;
                                        ?>
                                        <img src="<?= htmlspecialchars($sigSrc); ?>" alt="Signature" style="max-height: 40px; max-width: 120px; object-fit: contain;">
                                    <?php else: ?>
                                        <span class="text-muted small">Not uploaded</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card review-card p-4 mt-4">
            <h5 class="fw-bold text-success mb-3">Signature Preview (Current)</h5>
            <div class="signature-grid" id="routeSlipSignaturePreview">
                <?php foreach ($signatureSlots as $slot): ?>
                    <div class="signature-block">
                        <?php if (!empty($slot['path'])): ?>
                            <img src="<?= htmlspecialchars($slot['path']); ?>" alt="Signature" class="signature-image">
                        <?php else: ?>
                            <div class="signature-placeholder">No signature yet</div>
                        <?php endif; ?>
                        <div class="signature-line"></div>
                        <div class="fw-semibold small"><?= htmlspecialchars($slot['label']); ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($slot['name']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const summaryBody = document.getElementById('routeSlipSummaryBody');
const signaturePreview = document.getElementById('routeSlipSignaturePreview');
const submissionId = <?php echo (int)$submissionId; ?>;

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderSummaryRows(reviews) {
    if (!summaryBody) {
        return;
    }
    summaryBody.innerHTML = '';
    if (!Array.isArray(reviews) || reviews.length === 0) {
        const row = document.createElement('tr');
        row.innerHTML = '<td colspan="5" class="text-muted small">No route slip reviews yet.</td>';
        summaryBody.appendChild(row);
        return;
    }
    reviews.forEach((review) => {
        const row = document.createElement('tr');
        const signatureCell = review.signature_path
            ? `<img src="${escapeHtml(review.signature_path)}" alt="Signature" style="max-height: 40px; max-width: 120px; object-fit: contain;">`
            : '<span class="text-muted small">Not uploaded</span>';
        row.innerHTML = `
            <td>${escapeHtml(review.reviewer_name || '')}</td>
            <td>${escapeHtml(review.reviewer_role || '')}</td>
            <td><span class="badge ${escapeHtml(review.status_class || '')}">${escapeHtml(review.status_label || '')}</span></td>
            <td>${escapeHtml(review.reviewed_at || '')}</td>
            <td>${signatureCell}</td>
        `;
        summaryBody.appendChild(row);
    });
}

function renderSignaturePreview(slots) {
    if (!signaturePreview) {
        return;
    }
    signaturePreview.innerHTML = '';
    if (!Array.isArray(slots) || slots.length === 0) {
        const fallback = document.createElement('div');
        fallback.className = 'text-muted small';
        fallback.textContent = 'No signature data available.';
        signaturePreview.appendChild(fallback);
        return;
    }
    slots.forEach((slot) => {
        const block = document.createElement('div');
        block.className = 'signature-block';
        const path = slot.path || '';
        block.innerHTML = `
            ${path ? `<img src="${escapeHtml(path)}" alt="Signature" class="signature-image">` : '<div class="signature-placeholder">No signature yet</div>'}
            <div class="signature-line"></div>
            <div class="fw-semibold small">${escapeHtml(slot.label || '')}</div>
            <div class="text-muted small">${escapeHtml(slot.name || '')}</div>
        `;
        signaturePreview.appendChild(block);
    });
}

function refreshSummary() {
    if (!summaryBody || !submissionId) {
        return;
    }
    fetch(`route_slip_review.php?submission_id=${submissionId}&summary=1`)
        .then((res) => res.json())
        .then((data) => {
            renderSummaryRows(data.reviews || []);
            renderSignaturePreview(data.signature_slots || []);
        })
        .catch((err) => {
            console.error('Failed to refresh route slip summary', err);
        });
}

refreshSummary();
setInterval(refreshSummary, 20000);
</script>
</body>
</html>
