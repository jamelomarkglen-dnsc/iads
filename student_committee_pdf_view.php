<?php
session_start();
require_once 'db.php';
require_once 'committee_pdf_submission_helpers.php';
require_once 'committee_pdf_annotation_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: login.php');
    exit;
}

ensureCommitteePdfTables($conn);

$submission_id = (int)($_GET['submission_id'] ?? 0);
if ($submission_id <= 0) {
    header('Location: student_committee_pdf_submission.php');
    exit;
}

$submission = fetch_committee_pdf_submission($conn, $submission_id);
if (!$submission || (int)$submission['student_id'] !== (int)$_SESSION['user_id']) {
    header('Location: student_committee_pdf_submission.php');
    exit;
}

$annotations = fetch_committee_submission_annotations($conn, $submission_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Committee PDF Feedback</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="pdf_annotation_styles.css">
    <style>
        body { background: #f4f8f4; font-family: "Segoe UI", Arial, sans-serif; }
        .content { margin-left: var(--sidebar-width-expanded, 240px); transition: margin-left 0.3s ease; padding: 20px; min-height: 100vh; }
        #sidebar.collapsed ~ .content { margin-left: var(--sidebar-width-collapsed, 70px); }
        @media (max-width: 992px) { .content { margin-left: 0; padding: 15px; } }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Committee PDF Feedback</h3>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($submission['original_filename'] ?? ''); ?></p>
            </div>
            <a href="student_committee_pdf_submission.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <div class="card p-3 shadow-sm mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Manuscript Preview</h5>
                <div class="pdf-page-info text-muted small"></div>
            </div>
            <div class="annotation-toolbar mb-2">
                <div class="ms-auto d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" id="prevPageBtn">Prev</button>
                    <button class="btn btn-sm btn-outline-secondary" id="nextPageBtn">Next</button>
                    <button class="btn btn-sm btn-outline-secondary" id="zoomInBtn">+</button>
                    <button class="btn btn-sm btn-outline-secondary" id="zoomOutBtn">-</button>
                    <button class="btn btn-sm btn-outline-secondary" id="resetZoomBtn">Reset</button>
                </div>
            </div>
            <div id="pdf-canvas-container" class="pdf-canvas-container"></div>
        </div>

        <div class="comment-panel">
            <div class="comment-panel-header">
                <span>Committee Annotations</span>
                <span class="comment-count-badge" id="annotationCount"><?php echo count($annotations); ?></span>
            </div>
            <div class="comment-panel-content"></div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="pdf_viewer.js"></script>
<script src="annotation_manager.js"></script>

<script>
    const pdfViewer = new PDFViewer({
        pdfUrl: '<?php echo htmlspecialchars($submission['file_path']); ?>',
        containerId: 'pdf-canvas-container',
        scale: 1.5
    });

    const annotationManager = new AnnotationManager({
        submissionId: <?php echo (int)$submission_id; ?>,
        userId: <?php echo (int)$_SESSION['user_id']; ?>,
        userRole: '<?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?>',
        pdfViewer: pdfViewer,
        apiEndpoint: 'committee_pdf_annotation_api.php',
        enablePolling: true,
        pollingInterval: 2000
    });

    document.getElementById('prevPageBtn').addEventListener('click', () => pdfViewer.previousPage());
    document.getElementById('nextPageBtn').addEventListener('click', () => pdfViewer.nextPage());
    document.getElementById('zoomInBtn').addEventListener('click', () => pdfViewer.zoomIn());
    document.getElementById('zoomOutBtn').addEventListener('click', () => pdfViewer.zoomOut());
    document.getElementById('resetZoomBtn').addEventListener('click', () => pdfViewer.resetZoom());
</script>
</body>
</html>
