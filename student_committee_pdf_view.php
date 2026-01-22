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

// Get version chain information
$version_info = get_committee_version_chain_info($conn, $submission_id);
$current_version = $version_info ? (int)$version_info['current_version'] : 1;
$is_latest = $version_info ? $version_info['is_latest'] : true;

// Count total versions in this chain
$total_versions = 1;
if ($version_info) {
    // Count backwards to root
    $temp_id = $submission_id;
    $parent_id = $version_info['previous_id'];
    while ($parent_id) {
        $total_versions++;
        $parent_submission = fetch_committee_pdf_submission($conn, $parent_id);
        $parent_id = $parent_submission['parent_submission_id'] ?? null;
    }
    // Count forwards to latest
    if (!$is_latest) {
        $temp_id = $submission_id;
        $child_stmt = $conn->prepare("SELECT id FROM committee_pdf_submissions WHERE parent_submission_id = ? LIMIT 1");
        while ($child_stmt) {
            $child_stmt->bind_param('i', $temp_id);
            $child_stmt->execute();
            $child_result = $child_stmt->get_result();
            if ($child_row = $child_result->fetch_assoc()) {
                $total_versions++;
                $temp_id = (int)$child_row['id'];
            } else {
                break;
            }
            $child_result->free();
        }
        if ($child_stmt) {
            $child_stmt->close();
        }
    }
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
        
        /* User Filter Tabs */
        .annotation-user-tabs {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding: 10px 16px;
            background: rgba(255,255,255,0.5);
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
        
        /* Version Navigation Styles */
        .version-navigation {
            background: linear-gradient(135deg, #16562c 0%, #0c331a 100%);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            box-shadow: 0 4px 12px rgba(22, 86, 44, 0.15);
        }
        
        .version-navigation .version-info {
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .version-navigation .badge {
            font-size: 0.85rem;
            padding: 4px 10px;
        }
        
        .version-navigation .btn {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .version-navigation .btn:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
        }
        
        .version-navigation .btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        
        .version-navigation .btn-success {
            background: #28a745;
            border-color: #28a745;
        }
        
        .version-navigation .btn-success:hover {
            background: #218838;
            border-color: #1e7e34;
        }
        
        @media (max-width: 768px) {
            .version-navigation {
                flex-direction: column;
                text-align: center;
            }
            
            .version-navigation .version-info {
                flex-direction: column;
            }
        }
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
                <div class="mt-2">
                    <span class="badge bg-<?php echo ($submission['submission_status'] ?? 'pending') === 'reviewed' ? 'success' : 'warning'; ?>-subtle text-<?php echo ($submission['submission_status'] ?? 'pending') === 'reviewed' ? 'success' : 'warning'; ?>">
                        <?php echo ucfirst($submission['submission_status'] ?? 'pending'); ?>
                    </span>
                </div>
            </div>
            <a href="student_committee_pdf_submission.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <!-- Version Navigation -->
        <?php if ($version_info && $total_versions > 1): ?>
        <div class="version-navigation">
            <a href="<?php echo $version_info['has_previous'] ? 'student_committee_pdf_view.php?submission_id=' . $version_info['previous_id'] : '#'; ?>"
               class="btn <?php echo $version_info['has_previous'] ? '' : 'disabled'; ?>"
               <?php echo $version_info['has_previous'] ? '' : 'onclick="return false;"'; ?>>
                <i class="bi bi-arrow-left"></i> Previous Version
            </a>
            
            <div class="version-info">
                <span>Version <?php echo $current_version; ?> of <?php echo $total_versions; ?></span>
                <?php if (!$is_latest): ?>
                    <span class="badge bg-warning text-dark">
                        <i class="bi bi-exclamation-triangle"></i> Viewing Old Version
                    </span>
                <?php else: ?>
                    <span class="badge bg-success">
                        <i class="bi bi-check-circle"></i> Latest Version
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="d-flex gap-2">
                <a href="<?php echo $version_info['has_next'] ? 'student_committee_pdf_view.php?submission_id=' . $version_info['next_id'] : '#'; ?>"
                   class="btn <?php echo $version_info['has_next'] ? '' : 'disabled'; ?>"
                   <?php echo $version_info['has_next'] ? '' : 'onclick="return false;"'; ?>>
                    Next Version <i class="bi bi-arrow-right"></i>
                </a>
                
                <?php if (!$is_latest): ?>
                    <a href="student_committee_pdf_view.php?submission_id=<?php echo $version_info['latest_id']; ?>"
                       class="btn btn-success">
                        <i class="bi bi-skip-end-fill"></i> Jump to Latest
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['committee_pdf_upload_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($_SESSION['committee_pdf_upload_success']); ?>
                <?php if (isset($_SESSION['committee_pdf_upload_version'])): ?>
                    <span class="badge bg-success ms-2">Version <?php echo (int)$_SESSION['committee_pdf_upload_version']; ?></span>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php
                unset($_SESSION['committee_pdf_upload_success']);
                unset($_SESSION['committee_pdf_upload_version']);
            ?>
        <?php endif; ?>

        <!-- Final Verdict Display -->
        <?php if (!empty($submission['final_verdict']) && $submission['final_verdict'] !== 'pending'): ?>
            <?php
                $verdict = $submission['final_verdict'];
                $config = get_verdict_config($verdict);
            ?>
            <div class="alert <?php echo $config['class']; ?> border-0 shadow-sm" role="alert">
                <h5 class="alert-heading fw-bold">
                    <i class="bi <?php echo $config['icon']; ?> me-2"></i>
                    Final Verdict: <?php echo $config['title']; ?>
                </h5>
                <p class="mb-2"><?php echo htmlspecialchars($config['description']); ?></p>
                <?php if (!empty($submission['final_verdict_comments'])): ?>
                    <hr>
                    <h6 class="fw-semibold">Committee Chairperson's Comments:</h6>
                    <p class="mb-2"><?php echo nl2br(htmlspecialchars($submission['final_verdict_comments'])); ?></p>
                <?php endif; ?>
                <hr>
                <p class="mb-0 small">
                    <i class="bi bi-person-badge me-1"></i>
                    <strong>Committee Chairperson</strong> |
                    <i class="bi bi-calendar-check ms-2 me-1"></i>
                    <?php echo $submission['final_verdict_at'] ? date('F d, Y g:i A', strtotime($submission['final_verdict_at'])) : 'N/A'; ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Upload New Version Card -->
        <div class="card mb-4 shadow-sm">
            <div class="card-body">
                <h5 class="fw-semibold mb-3">
                    <i class="bi bi-cloud-upload text-success me-2"></i>Upload New Version
                </h5>
                <p class="text-muted small mb-3">
                    Upload a revised version of your committee PDF. The new version will be linked to this submission and reviewers will be notified.
                </p>
                <form enctype="multipart/form-data" method="POST" action="committee_pdf_upload_handler.php">
                    <input type="hidden" name="action" value="upload_revision">
                    <input type="hidden" name="parent_submission_id" value="<?php echo (int)$submission_id; ?>">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Select PDF File</label>
                            <input type="file" class="form-control" name="pdf_file" accept=".pdf" required>
                            <small class="text-muted">Maximum file size: 50MB</small>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-cloud-upload me-2"></i>Upload Version <?php echo ((int)($submission['version_number'] ?? 1)) + 1; ?>
                            </button>
                        </div>
                    </div>
                </form>
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
                <div class="card p-3 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-semibold mb-0">Committee Annotations</h6>
                        <span class="comment-count-badge" id="annotationCount"><?php echo count($annotations); ?></span>
                    </div>
                    
                    <!-- User Filter Tabs -->
                    <div class="annotation-user-tabs mb-2" id="annotationUserTabs">
                        <button class="user-tab active" data-user-id="all">All</button>
                    </div>
                    
                    <!-- Annotation List -->
                    <div class="comment-panel-content" style="max-height: 600px; overflow-y: auto;"></div>
                </div>
            </div>
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
