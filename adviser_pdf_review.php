<?php
/**
 * Adviser PDF Review Page
 * Allows advisers to review student PDFs and add annotations
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

session_start();
require_once 'db.php';
require_once 'pdf_submission_helpers.php';
require_once 'pdf_annotation_helpers.php';

// =====================================================
// SECURITY: Verify user is logged in and is adviser
// =====================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!in_array($_SESSION['role'], ['adviser', 'committee_chairperson', 'panel'])) {
    header('Location: index.php');
    exit;
}

// =====================================================
// GET SUBMISSION ID
// =====================================================
$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;

if ($submission_id <= 0) {
    header('Location: adviser.php');
    exit;
}

// =====================================================
// FETCH SUBMISSION
// =====================================================
$submission = fetch_pdf_submission($conn, $submission_id);
$error_message = null;

// Debug: Log what we're getting
error_log("DEBUG: submission_id = {$submission_id}");
error_log("DEBUG: submission = " . print_r($submission, true));
error_log("DEBUG: user_id = " . $_SESSION['user_id']);

if (!$submission) {
    // Submission not found - show error message instead of redirecting
    $error_message = "Submission not found. The PDF submission with ID {$submission_id} does not exist in the database. Please check the submission ID and try again.";
    error_log("ERROR: Submission not found for ID {$submission_id}");
} else if (isset($submission['adviser_id']) && $submission['adviser_id'] != $_SESSION['user_id']) {
    // Adviser doesn't have access to this submission
    $error_message = "You do not have permission to review this submission. This submission is assigned to adviser ID {$submission['adviser_id']}, but you are user ID {$_SESSION['user_id']}.";
    error_log("ERROR: Permission denied. Adviser ID {$submission['adviser_id']} != User ID {$_SESSION['user_id']}");
}

// If there's an error, show it and provide a back button
if ($error_message) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - IAdS</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
        <style>
            body {
                background: #f4f8f4;
            }
            .content {
                margin-left: var(--sidebar-width-expanded, 240px);
                transition: margin-left 0.3s ease;
                padding: 20px;
                min-height: 100vh;
            }
            #sidebar.collapsed ~ .content {
                margin-left: var(--sidebar-width-collapsed, 70px);
            }
            @media (max-width: 992px) {
                .content {
                    margin-left: 0;
                    padding: 15px;
                }
            }
        </style>
    </head>
    <body>
        <?php include 'header.php'; ?>
        <?php include 'sidebar.php'; ?>
        <div class="content">
            <div class="container-fluid">
                <div class="alert alert-danger" role="alert">
                    <h4 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Error!</h4>
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                    <hr>
                    <p class="mb-0">
                        <a href="adviser.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =====================================================
// FETCH ANNOTATIONS
// =====================================================
$annotations = fetch_submission_annotations($conn, $submission_id);
$stats = get_annotation_statistics($conn, $submission_id);

// Get version chain information
$version_info = get_version_chain_info($conn, $submission_id);

// =====================================================
// UPDATE SUBMISSION STATUS IF NEEDED
// =====================================================
if ($submission['submission_status'] === 'pending') {
    update_submission_status($conn, $submission_id, 'reviewed');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review PDF - IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="pdf_annotation_styles.css">
    <style>
        /* Fix sidebar overlap and improve responsiveness */
        body {
            background: #f4f8f4;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        
        .content {
            margin-left: var(--sidebar-width-expanded, 240px);
            transition: margin-left 0.3s ease;
            padding: 20px;
            min-height: 100vh;
        }
        
        #sidebar.collapsed ~ .content {
            margin-left: var(--sidebar-width-collapsed, 70px);
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .content {
                margin-left: 0;
                padding: 15px;
            }
            
            #sidebar.collapsed ~ .content {
                margin-left: 0;
            }
        }
        
        /* Page header improvements */
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(22, 86, 44, 0.08);
        }
        
        .page-header h3 {
            color: #16562c;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .page-header .text-muted {
            font-size: 0.9rem;
        }
        
        /* PDF Review Container improvements */
        .pdf-review-wrapper {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .pdf-review-container {
            display: flex;
            gap: 20px;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(22, 86, 44, 0.08);
            min-height: calc(100vh - 200px);
        }
        
        .pdf-viewer-wrapper {
            flex: 1;
            min-width: 0;
        }
        
        .comment-panel {
            width: 350px;
            flex-shrink: 0;
        }
        
        /* Error/Alert messages */
        .alert {
            margin-bottom: 0;
        }
        
        /* Responsive PDF container */
        @media (max-width: 1200px) {
            .pdf-review-container {
                flex-direction: column;
            }
            
            .comment-panel {
                width: 100%;
            }
        }
        
        /* Toolbar improvements */
        .pdf-toolbar {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .annotation-toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .annotation-tool-btn {
            padding: 8px 16px;
            border: 2px solid #16562c;
            background: white;
            color: #16562c;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        
        .annotation-tool-btn:hover {
            background: #16562c;
            color: white;
        }
        
        .annotation-tool-btn.active {
            background: #16562c;
            color: white;
        }
        
        /* Button improvements */
        .btn-outline-secondary {
            border-color: #16562c;
            color: #16562c;
        }
        
        .btn-outline-secondary:hover {
            background: #16562c;
            color: white;
            border-color: #16562c;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .page-header {
                padding: 15px;
            }
            
            .page-header h3 {
                font-size: 1.3rem;
            }
            
            .page-header .text-muted {
                font-size: 0.8rem;
            }
            
            .pdf-review-container {
                padding: 15px;
            }
            
            .pdf-toolbar {
                padding: 10px;
            }
            
            .annotation-tool-btn {
                padding: 6px 12px;
                font-size: 0.9rem;
            }
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h3><i class="bi bi-file-pdf"></i> Review PDF Submission</h3>
                        <p class="text-muted mb-0">
                            Student: <strong><?php echo htmlspecialchars($submission['student_name']); ?></strong> |
                            File: <strong><?php echo htmlspecialchars($submission['original_filename']); ?></strong> |
                            Submitted: <strong><?php echo date('F d, Y g:i A', strtotime($submission['submission_timestamp'])); ?></strong>
                        </p>
                    </div>
                    <div class="col-md-4 text-end mt-3 mt-md-0">
                        <a href="adviser.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- Version Navigation -->
            <?php if ($version_info && $version_info['total_versions'] > 1): ?>
            <div class="version-navigation">
                <a href="<?php echo $version_info['has_previous'] ? 'adviser_pdf_review.php?submission_id=' . $version_info['previous_id'] : '#'; ?>"
                   class="btn <?php echo $version_info['has_previous'] ? '' : 'disabled'; ?>"
                   <?php echo $version_info['has_previous'] ? '' : 'onclick="return false;"'; ?>>
                    <i class="bi bi-arrow-left"></i> Previous Version
                </a>
                
                <div class="version-info">
                    <span>Version <?php echo $version_info['current_version']; ?> of <?php echo $version_info['total_versions']; ?></span>
                    <?php if (!$version_info['is_latest']): ?>
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
                    <a href="<?php echo $version_info['has_next'] ? 'adviser_pdf_review.php?submission_id=' . $version_info['next_id'] : '#'; ?>"
                       class="btn <?php echo $version_info['has_next'] ? '' : 'disabled'; ?>"
                       <?php echo $version_info['has_next'] ? '' : 'onclick="return false;"'; ?>>
                        Next Version <i class="bi bi-arrow-right"></i>
                    </a>
                    
                    <?php if (!$version_info['is_latest']): ?>
                        <a href="adviser_pdf_review.php?submission_id=<?php echo $version_info['latest_id']; ?>"
                           class="btn btn-success">
                            <i class="bi bi-skip-end-fill"></i> Jump to Latest
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- PDF Review Wrapper -->
            <div class="pdf-review-wrapper">
                <!-- Error/Success Messages (if any) -->
                <div id="messageContainer"></div>
                
                <!-- PDF Review Container -->
                <div class="pdf-review-container">
                    <!-- PDF Viewer Section -->
                    <div class="pdf-viewer-wrapper">
                    <!-- PDF Toolbar -->
                    <div class="pdf-toolbar">
                        <div class="pdf-toolbar-left">
                            <button class="btn btn-sm btn-outline-primary" id="prevPageBtn">
                                <i class="bi bi-chevron-left"></i> Previous
                            </button>
                            <span class="pdf-page-info">Page 1 of 0</span>
                            <button class="btn btn-sm btn-outline-primary" id="nextPageBtn">
                                Next <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>
                        <div class="pdf-toolbar-right">
                            <div class="pdf-zoom-controls">
                                <button id="zoomOutBtn" title="Zoom Out">−</button>
                                <span id="zoomLevel">100%</span>
                                <button id="zoomInBtn" title="Zoom In">+</button>
                                <button id="resetZoomBtn" title="Reset Zoom">Reset</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Annotation Toolbar - ANNOTATION TOOLS ARE HERE -->
                    <div class="pdf-toolbar">
                        <div class="annotation-toolbar">
                            <button class="annotation-tool-btn" data-tool="comment" title="Add Comment">
                                <i class="bi bi-chat-left-text"></i> Comment
                            </button>
                            <button class="annotation-tool-btn" data-tool="highlight" title="Highlight Text">
                                <i class="bi bi-highlighter"></i> Highlight
                            </button>
                            <button class="annotation-tool-btn" data-tool="suggestion" title="Add Suggestion">
                                <i class="bi bi-lightbulb"></i> Suggestion
                            </button>
                        </div>
                    </div>
                    
                    <!-- PDF Canvas Container -->
                    <div class="pdf-canvas-container" id="pdf-canvas-container"></div>
                </div>
                
                <!-- Comment Panel -->
                <div class="comment-panel">
                    <div class="comment-panel-header">
                        <span>Annotations</span>
                        <span class="comment-count-badge" id="annotationCount"><?php echo count($annotations); ?></span>
                    </div>
                    <div class="comment-panel-content"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Annotation Dialog -->
    <div class="annotation-dialog">
        <div class="annotation-dialog-header">
            <span>Add Annotation</span>
            <button class="annotation-dialog-close">&times;</button>
        </div>
        <div class="annotation-dialog-body">
            <div class="annotation-form-group">
                <label>Type</label>
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
            <div class="selected-text-display" style="display: none; background: #fff3cd; padding: 8px; border-radius: 4px; margin-bottom: 12px; font-size: 12px;"></div>
        </div>
        <div class="annotation-dialog-footer">
            <button class="annotation-dialog-btn secondary">Cancel</button>
            <button class="annotation-dialog-btn primary">Save Annotation</button>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="pdf_viewer.js"></script>
    <script src="annotation_manager.js"></script>
    
    <script>
        // Global notification function
        window.showNotification = function(message, type = 'info') {
            // Map types to Bootstrap alert classes
            const alertClass = type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info';
            
            // Create Bootstrap alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${alertClass} alert-dismissible fade show`;
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <strong>${type === 'error' ? 'Error!' : type === 'success' ? 'Success!' : 'Info:'}</strong> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            // Get message container
            const container = document.getElementById('messageContainer');
            if (container) {
                // Clear previous messages
                container.innerHTML = '';
                // Add new message
                container.appendChild(alertDiv);
                
                // Auto-dismiss after 5 seconds
                setTimeout(() => {
                    alertDiv.classList.remove('show');
                    setTimeout(() => alertDiv.remove(), 150);
                }, 5000);
                
                // Scroll to top to show message
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                console.error('Message container not found');
                console.log(`${type.toUpperCase()}: ${message}`);
            }
        };
        
        // Initialize PDF Viewer
        const pdfViewer = new PDFViewer({
            pdfUrl: '<?php echo htmlspecialchars($submission['file_path']); ?>',
            containerId: 'pdf-canvas-container',
            scale: 1.5
        });
        
        // Initialize Annotation Manager
        const annotationManager = new AnnotationManager({
            submissionId: <?php echo $submission_id; ?>,
            userId: <?php echo $_SESSION['user_id']; ?>,
            userRole: '<?php echo $_SESSION['role']; ?>',
            pdfViewer: pdfViewer,
            apiEndpoint: 'pdf_annotation_api.php',
            enablePolling: true,
            pollingInterval: 1000 // Poll every 1 second for real-time updates
        });
        
        // Page navigation
        document.getElementById('prevPageBtn').addEventListener('click', () => pdfViewer.previousPage());
        document.getElementById('nextPageBtn').addEventListener('click', () => pdfViewer.nextPage());
        
        // Zoom controls
        document.getElementById('zoomInBtn').addEventListener('click', () => pdfViewer.zoomIn());
        document.getElementById('zoomOutBtn').addEventListener('click', () => pdfViewer.zoomOut());
        document.getElementById('resetZoomBtn').addEventListener('click', () => pdfViewer.resetZoom());
    </script>
</body>
</html>
