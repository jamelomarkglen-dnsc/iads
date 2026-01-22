<?php
session_start();
require_once 'db.php';
require_once 'final_paper_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: login.php');
    exit;
}

ensureFinalPaperTables($conn);

$studentId = (int)($_SESSION['user_id'] ?? 0);
$submissionId = (int)($_GET['submission_id'] ?? 0);

if ($submissionId <= 0) {
    $latest = fetchLatestFinalPaperSubmission($conn, $studentId);
    $submissionId = (int)($latest['id'] ?? 0);
}

$submission = null;
if ($submissionId > 0) {
    $submission = fetchFinalPaperSubmission($conn, $submissionId);
}

if (!$submission || (int)($submission['student_id'] ?? 0) !== $studentId) {
    $submission = null;
}

$signedPath = $submission['route_slip_signed_path'] ?? '';
$signedName = $submission['route_slip_signed_name'] ?? '';

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Signed Route Slip</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .card-shell { border-radius: 18px; border: none; box-shadow: 0 18px 36px rgba(22, 86, 44, 0.12); }
        .preview-frame { width: 100%; height: 720px; border: 1px solid #d9e5da; border-radius: 12px; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Signed Route Slip</h3>
                <p class="text-muted mb-0">Download the fully signed route slip once your adviser completes the final signature.</p>
            </div>
            <?php if ($signedPath): ?>
                <a href="<?= htmlspecialchars($signedPath); ?>" class="btn btn-outline-success" target="_blank">
                    <i class="bi bi-download me-1"></i>Download PDF
                </a>
            <?php endif; ?>
        </div>

        <div class="card card-shell p-4">
            <?php if ($signedPath): ?>
                <div class="mb-2 text-muted small">File: <?= htmlspecialchars($signedName ?: basename($signedPath)); ?></div>
                <iframe class="preview-frame" src="<?= htmlspecialchars($signedPath); ?>"></iframe>
            <?php else: ?>
                <div class="text-muted">No signed route slip available yet. Please wait for your adviser to complete the final signature.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
