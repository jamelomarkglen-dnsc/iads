<?php
session_start();
require_once 'db.php';
require_once 'committee_pdf_submission_helpers.php';
require_once 'committee_pdf_annotation_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chairperson', 'committee_chair'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

ensureCommitteePdfTables($conn);

$submission_id = (int)($_GET['submission_id'] ?? 0);
if ($submission_id <= 0) {
    header('Location: committee_pdf_inbox.php');
    exit;
}

$reviewer_id = (int)($_SESSION['user_id'] ?? 0);
$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewer_role = $roleMap[$_SESSION['role'] ?? ''] ?? ($_SESSION['role'] ?? '');

$submission = fetch_committee_pdf_submission($conn, $submission_id);
if (!$submission) {
    header('Location: committee_pdf_inbox.php');
    exit;
}

$reviewRow = fetch_committee_review_row($conn, $submission_id, $reviewer_id, $reviewer_role);
if (!$reviewRow) {
    header('Location: committee_pdf_inbox.php');
    exit;
}

$annotations = fetch_committee_submission_annotations($conn, $submission_id);
$stats = get_committee_annotation_statistics($conn, $submission_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Committee PDF Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="pdf_annotation_styles.css">
    <style>
        body { background: #f4f8f4; font-family: "Segoe UI", Arial, sans-serif; }
        .content { margin-left: var(--sidebar-width-expanded, 240px); transition: margin-left 0.3s ease; padding: 20px; min-height: 100vh; }
        #sidebar.collapsed ~ .content { margin-left: var(--sidebar-width-collapsed, 70px); }
        @media (max-width: 992px) { .content { margin-left: 0; padding: 15px; } }
        
        /* User Filter Tabs */
        .annotation-user-tabs {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
            scrollbar-width: thin;
        }
        .annotation-user-tabs::-webkit-scrollbar {
            height: 4px;
        }
        .annotation-user-tabs::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 2px;
        }
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
        .user-tab:hover {
            background: #f8f9fa;
            border-color: #198754;
        }
        .user-tab.active {
            background: #198754;
            color: white;
            border-color: #198754;
        }
        .user-tab-count {
            display: inline-block;
            margin-left: 4px;
            padding: 2px 6px;
            background: rgba(0,0,0,0.1);
            border-radius: 10px;
            font-size: 0.75rem;
        }
        .user-tab.active .user-tab-count {
            background: rgba(255,255,255,0.3);
        }
        
        /* Hide selected text in comment list */
        .comment-selected-text {
            display: none !important;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1">Committee PDF Review</h3>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($submission['student_name'] ?? 'Student'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="committee_pdf_inbox.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Inbox
                </a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card p-3 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0">Manuscript Preview</h5>
                        <div class="pdf-page-info text-muted small"></div>
                    </div>
                    <div class="annotation-toolbar mb-2">
                        <button class="annotation-tool-btn" data-tool="comment" title="Add Comment">
                            <i class="bi bi-chat-dots"></i>
                        </button>
                        <button class="annotation-tool-btn" data-tool="highlight" title="Highlight Text">
                            <i class="bi bi-highlighter"></i>
                        </button>
                        <button class="annotation-tool-btn" data-tool="suggestion" title="Add Suggestion">
                            <i class="bi bi-lightbulb"></i>
                        </button>
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
            </div>
            <div class="col-lg-4">
                <div class="card p-3 shadow-sm mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-semibold mb-0">Committee Annotations</h6>
                        <span class="comment-count-badge" id="annotationCount"><?php echo count($annotations); ?></span>
                    </div>
                    
                    <!-- User Filter Tabs -->
                    <div class="annotation-user-tabs mb-2" id="annotationUserTabs">
                        <button class="user-tab active" data-user-id="all">All</button>
                    </div>
                    
                    <!-- Annotation List -->
                    <div class="comment-panel-content" style="max-height: 400px; overflow-y: auto;"></div>
                </div>

                <div class="card p-3 shadow-sm mb-3">
                    <h6 class="fw-semibold mb-3">Submission Details</h6>
                    <div class="text-muted small mb-2"><strong>Filename:</strong> <?php echo htmlspecialchars($submission['original_filename'] ?? ''); ?></div>
                    <div class="text-muted small mb-2"><strong>Version:</strong> v<?php echo (int)($submission['version_number'] ?? 1); ?></div>
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

                <!-- Final Verdict Card (Committee Chairperson Only) -->
                <?php if ($reviewer_role === 'committee_chairperson'): ?>
                <div class="card p-3 shadow-sm mt-3">
                    <h6 class="fw-semibold mb-3">
                        <i class="bi bi-gavel text-warning me-2"></i>
                        Submit Final Verdict
                    </h6>
                    
                    <?php if (isset($_SESSION['verdict_success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show small" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['verdict_success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['verdict_success']); ?>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['verdict_error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show small" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['verdict_error']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['verdict_error']); ?>
                    <?php endif; ?>
                    
                    <!-- Current Verdict Display (if already set) -->
                    <?php if (!empty($submission['final_verdict']) && $submission['final_verdict'] !== 'pending'): ?>
                        <div class="alert alert-info small mb-3">
                            <strong>Current Verdict:</strong> 
                            <?php echo htmlspecialchars(get_verdict_label($submission['final_verdict'])); ?>
                            <br>
                            <small>Set on: <?php echo $submission['final_verdict_at'] ? date('M d, Y g:i A', strtotime($submission['final_verdict_at'])) : 'N/A'; ?></small>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="committee_pdf_verdict_handler.php">
                        <input type="hidden" name="submission_id" value="<?php echo (int)$submission_id; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Verdict Decision</label>
                            <select name="final_verdict" class="form-select form-select-sm" required>
                                <option value="">-- Select Verdict --</option>
                                <option value="passed">✅ Passed</option>
                                <option value="passed_minor_revisions">✏️ Passed with Minor Revisions</option>
                                <option value="passed_major_revisions">📝 Passed with Major Revisions</option>
                                <option value="redefense">🔄 Redefense Required</option>
                                <option value="failed">❌ Failed</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Comments/Recommendations</label>
                            <textarea name="verdict_comments" class="form-control form-control-sm" rows="4" 
                                      placeholder="Provide detailed feedback and recommendations..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-warning btn-sm w-100">
                            <i class="bi bi-check-circle me-1"></i>Submit Final Verdict
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

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
        userRole: '<?php echo htmlspecialchars($reviewer_role); ?>',
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
