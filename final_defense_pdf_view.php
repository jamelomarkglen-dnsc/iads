<?php
session_start();
require_once 'db.php';
require_once 'final_defense_submission_helpers.php';
require_once 'final_defense_annotation_helpers.php';

$allowedRoles = ['student', 'adviser', 'panel', 'committee_chair', 'committee_chairperson'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureFinalDefenseSubmissionTable($conn);
ensureFinalDefenseAnnotationTables($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? '';
$roleMap = ['committee_chair' => 'committee_chairperson'];
$normalizedRole = $roleMap[$userRole] ?? $userRole;

$submissionId = (int)($_GET['submission_id'] ?? 0);
if ($submissionId <= 0) {
    header('Location: ' . ($normalizedRole === 'student' ? 'submit_final_defense.php' : 'final_defense_committee_dashboard.php'));
    exit;
}

$submission = null;
$stmt = $conn->prepare("
    SELECT fds.*, s.title AS submission_title,
           CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
           CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
           CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name,
           CONCAT(p1.firstname, ' ', p1.lastname) AS panel_one_name,
           CONCAT(p2.firstname, ' ', p2.lastname) AS panel_two_name
    FROM final_defense_submissions fds
    LEFT JOIN submissions s ON s.id = fds.submission_id
    LEFT JOIN users stu ON stu.id = fds.student_id
    LEFT JOIN users adv ON adv.id = fds.adviser_id
    LEFT JOIN users ch ON ch.id = fds.chair_id
    LEFT JOIN users p1 ON p1.id = fds.panel_member_one_id
    LEFT JOIN users p2 ON p2.id = fds.panel_member_two_id
    WHERE fds.id = ?
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

if (!$submission) {
    header('Location: ' . ($normalizedRole === 'student' ? 'submit_final_defense.php' : 'final_defense_committee_dashboard.php'));
    exit;
}

$isAssigned = in_array($userId, [
    (int)($submission['adviser_id'] ?? 0),
    (int)($submission['chair_id'] ?? 0),
    (int)($submission['panel_member_one_id'] ?? 0),
    (int)($submission['panel_member_two_id'] ?? 0),
], true);

if ($normalizedRole === 'student') {
    if ((int)($submission['student_id'] ?? 0) !== $userId) {
        header('Location: submit_final_defense.php');
        exit;
    }
} else {
    if (!$isAssigned) {
        header('Location: final_defense_committee_dashboard.php');
        exit;
    }
}

$canAnnotate = $isAssigned && in_array($normalizedRole, ['committee_chairperson', 'panel', 'adviser'], true);
$filePath = $submission['file_path'] ?? '';
$fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$isPdf = $fileExt === 'pdf';

$annotations = $isPdf ? fetch_final_defense_submission_annotations($conn, $submissionId) : [];
$stats = $isPdf ? get_final_defense_annotation_statistics($conn, $submissionId) : [];

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Final Defense Annotations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="pdf_annotation_styles.css?v=20260422-search11">
    <style>
        body { background: #f4f8f4; font-family: "Segoe UI", Arial, sans-serif; }
        .content { margin-left: var(--sidebar-width-expanded, 240px); transition: margin-left 0.3s ease; padding: 20px; min-height: 100vh; }
        #sidebar.collapsed ~ .content { margin-left: var(--sidebar-width-collapsed, 70px); }
        @media (max-width: 992px) { .content { margin-left: 0; padding: 15px; } }

        .annotation-user-tabs {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
            scrollbar-width: thin;
        }
        .annotation-user-tabs::-webkit-scrollbar { height: 4px; }
        .annotation-user-tabs::-webkit-scrollbar-thumb { background: #ccc; border-radius: 2px; }
        .user-tab {
            padding: 6px 12px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 4px;
            font-size: 0.85rem;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .user-tab:hover { background: #f8f9fa; border-color: #198754; }
        .user-tab.active { background: #198754; color: white; border-color: #198754; }
        .user-tab-count {
            display: inline-block;
            margin-left: 4px;
            padding: 2px 6px;
            background: rgba(0,0,0,0.1);
            border-radius: 10px;
            font-size: 0.75rem;
        }
        .user-tab.active .user-tab-count { background: rgba(255,255,255,0.3); }
        .comment-selected-text { display: none !important; }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Final Defense Annotations</h3>
                <p class="text-muted mb-0"><?= htmlspecialchars($submission['student_name'] ?? 'Student'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= $normalizedRole === 'student' ? 'submit_final_defense.php' : 'final_defense_committee_dashboard.php'; ?>"
                   class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if (!$isPdf): ?>
            <div class="alert alert-warning">
                This file is not a PDF. Annotation view is available for PDF files only.
                <?php if (!empty($filePath)): ?>
                    <a href="<?= htmlspecialchars($filePath); ?>" class="alert-link" target="_blank" rel="noopener">Download file</a>.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card p-3 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0">Final Defense File</h5>
                        <div class="pdf-page-info text-muted small"></div>
                    </div>

                    <div class="annotation-toolbar mb-2">
                        <?php if ($canAnnotate): ?>
                            <button class="annotation-tool-btn" data-tool="comment" title="Add Comment">
                                <i class="bi bi-chat-dots"></i>
                            </button>
                        <?php endif; ?>
                        <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
                            <div class="input-group input-group-sm" style="width: 150px;">
                                <span class="input-group-text">Page</span>
                                <input type="number" min="1" class="form-control" id="pageJumpInput" aria-label="Page number">
                                <span class="input-group-text" id="pageJumpTotal">of 0</span>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" id="pageJumpBtn">Go</button>
                            <button class="btn btn-sm btn-outline-secondary" id="prevPageBtn">Prev</button>
                            <button class="btn btn-sm btn-outline-secondary" id="nextPageBtn">Next</button>
                            <button class="btn btn-sm btn-outline-secondary" id="zoomInBtn">+</button>
                            <button class="btn btn-sm btn-outline-secondary" id="zoomOutBtn">-</button>
                            <button class="btn btn-sm btn-outline-secondary" id="resetZoomBtn">Reset</button>
                        </div>
                    </div>
                    <?php if ($normalizedRole === 'student' && !$canAnnotate): ?>
                        <div class="alert alert-info small mb-2">
                            Committee members can annotate. You can reply to any feedback in the panel on the right.
                        </div>
                    <?php endif; ?>

                    <?php if ($isPdf): ?>
                        <div id="pdf-canvas-container" class="pdf-canvas-container"></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card p-3 shadow-sm mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-semibold mb-0">Annotations</h6>
                        <span class="comment-count-badge" id="annotationCount"><?php echo count($annotations); ?></span>
                    </div>

                    <div class="annotation-user-tabs mb-2" id="annotationUserTabs">
                        <button class="user-tab active" data-user-id="all">All</button>
                    </div>

                    <div class="comment-panel-content" style="max-height: 400px; overflow-y: auto;"></div>
                </div>

                <div class="card p-3 shadow-sm mb-3">
                    <h6 class="fw-semibold mb-3">Submission Details</h6>
                    <div class="text-muted small mb-2"><strong>Title:</strong> <?php echo htmlspecialchars($submission['submission_title'] ?? ''); ?></div>
                    <div class="text-muted small mb-2"><strong>Filename:</strong> <?php echo htmlspecialchars($submission['file_name'] ?? basename($filePath)); ?></div>
                    <div class="text-muted small mb-2"><strong>Status:</strong> <?php echo htmlspecialchars($submission['status'] ?? 'Submitted'); ?></div>
                    <div class="text-muted small"><strong>Submitted:</strong>
                        <?php echo htmlspecialchars($submission['submitted_at'] ? date('M d, Y g:i A', strtotime($submission['submitted_at'])) : 'N/A'); ?>
                    </div>
                </div>

                <div class="card p-3 shadow-sm">
                    <h6 class="fw-semibold mb-2">Annotation Summary</h6>
                    <div class="d-flex justify-content-between text-muted small mb-2">
                        <span>Total</span>
                        <span><?php echo (int)($stats['total_annotations'] ?? 0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small mb-2">
                        <span>Active</span>
                        <span><?php echo (int)($stats['active_annotations'] ?? 0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Resolved</span>
                        <span><?php echo (int)($stats['resolved_annotations'] ?? 0); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canAnnotate): ?>
<div class="annotation-dialog">
    <div class="annotation-dialog-header">
        <span>Add Annotation</span>
        <button class="annotation-dialog-close">&times;</button>
    </div>
    <div class="annotation-dialog-body">
        <div class="annotation-form-group">
            <label>Annotation Type</label>
            <select name="annotation_type">
                <option value="comment">Comment</option>
                <option value="highlight">Highlight</option>
                <option value="suggestion">Suggestion</option>
            </select>
        </div>
        <div class="annotation-form-group">
            <label>Content</label>
            <textarea name="annotation_content" placeholder="Enter your annotation..."></textarea>
        </div>
    </div>
    <div class="annotation-dialog-footer">
        <button class="annotation-dialog-btn secondary">Cancel</button>
        <button class="annotation-dialog-btn primary">Save Annotation</button>
    </div>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="pdf_viewer.js?v=20260422-search11"></script>
<script src="annotation_manager.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($isPdf): ?>
<script>
    const pdfViewer = new PDFViewer({
        pdfUrl: '<?php echo htmlspecialchars($filePath); ?>',
        containerId: 'pdf-canvas-container',
        scale: 1.5
    });

    const annotationManager = new AnnotationManager({
        submissionId: <?php echo (int)$submissionId; ?>,
        userId: <?php echo (int)$userId; ?>,
        userRole: '<?php echo htmlspecialchars($canAnnotate ? $normalizedRole : 'student'); ?>',
        pdfViewer: pdfViewer,
        apiEndpoint: 'final_defense_annotation_api.php',
        enablePolling: true,
        pollingInterval: 2000
    });

    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    const zoomInBtn = document.getElementById('zoomInBtn');
    const zoomOutBtn = document.getElementById('zoomOutBtn');
    const resetZoomBtn = document.getElementById('resetZoomBtn');
    const pageJumpInput = document.getElementById('pageJumpInput');
    const pageJumpBtn = document.getElementById('pageJumpBtn');
    const pageJumpTotal = document.getElementById('pageJumpTotal');

    if (prevBtn) prevBtn.addEventListener('click', () => pdfViewer.previousPage());
    if (nextBtn) nextBtn.addEventListener('click', () => pdfViewer.nextPage());
    if (zoomInBtn) zoomInBtn.addEventListener('click', () => pdfViewer.zoomIn());
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => pdfViewer.zoomOut());
    if (resetZoomBtn) resetZoomBtn.addEventListener('click', () => pdfViewer.resetZoom());

    const syncPageJumpMeta = () => {
        if (!pageJumpInput || !pageJumpTotal) {
            return;
        }
        const totalPages = pdfViewer.getTotalPages();
        if (totalPages > 0) {
            pageJumpTotal.textContent = `of ${totalPages}`;
            pageJumpInput.max = totalPages;
            if (!pageJumpInput.value) {
                pageJumpInput.value = pdfViewer.getCurrentPage();
            }
        }
    };

    const syncPageJumpValue = () => {
        if (pageJumpInput) {
            pageJumpInput.value = pdfViewer.getCurrentPage();
        }
    };

    const waitForTotalPages = () => {
        const totalPages = pdfViewer.getTotalPages();
        if (totalPages > 0) {
            syncPageJumpMeta();
            return;
        }
        setTimeout(waitForTotalPages, 200);
    };

    waitForTotalPages();

    if (pageJumpBtn && pageJumpInput) {
        pageJumpBtn.addEventListener('click', async () => {
            const value = parseInt(pageJumpInput.value, 10);
            if (Number.isNaN(value)) {
                return;
            }
            await pdfViewer.goToPage(value);
            syncPageJumpValue();
        });

        pageJumpInput.addEventListener('keydown', async (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                pageJumpBtn.click();
            }
        });
    }
</script>
<?php endif; ?>
</body>
</html>
