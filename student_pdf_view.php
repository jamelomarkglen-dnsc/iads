<?php
/**
 * Student PDF View Page
 * Allows students to view adviser feedback and annotations on their PDFs
 * 
 * @package IAdS
 * @subpackage PDF Annotation System
 */

session_start();
require_once 'db.php';
require_once 'pdf_submission_helpers.php';
require_once 'pdf_annotation_helpers.php';

// =====================================================
// SECURITY: Verify user is logged in and is student
// =====================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

// =====================================================
// GET SUBMISSION ID
// =====================================================
$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;

if ($submission_id <= 0) {
    header('Location: student_dashboard.php');
    exit;
}

// =====================================================
// FETCH SUBMISSION
// =====================================================
$submission = fetch_pdf_submission($conn, $submission_id);

if (!$submission) {
    header('Location: student_dashboard.php');
    exit;
}

// =====================================================
// VERIFY STUDENT OWNS THIS SUBMISSION
// =====================================================
if ($submission['student_id'] != $_SESSION['user_id']) {
    header('Location: student_dashboard.php');
    exit;
}

// =====================================================
// FETCH ANNOTATIONS
// =====================================================
$annotations = fetch_submission_annotations($conn, $submission_id);
$stats = get_annotation_statistics($conn, $submission_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View PDF Feedback - IAdS</title>
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
        
        /* Card improvements */
        .card {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(22, 86, 44, 0.08);
            border: none;
        }
        
        /* PDF Review Container improvements */
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
        
        /* Responsive PDF container */
        @media (max-width: 1200px) {
            .pdf-review-container {
                flex-direction: column;
            }
            
            .comment-panel {
                width: 100%;
            }
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .content {
                padding: 10px;
            }
            
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
                        <h3><i class="bi bi-file-pdf"></i> PDF Feedback</h3>
                        <p class="text-muted mb-0">
                            Adviser: <strong><?php echo htmlspecialchars($submission['adviser_name']); ?></strong> |
                            File: <strong><?php echo htmlspecialchars($submission['original_filename']); ?></strong> |
                            Submitted: <strong><?php echo date('F d, Y g:i A', strtotime($submission['submission_timestamp'])); ?></strong>
                        </p>
                    </div>
                    <div class="col-md-4 text-end mt-3 mt-md-0">
                        <a href="student_dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- Submission Info Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>File:</strong> <?php echo htmlspecialchars($submission['original_filename']); ?></p>
                            <p><strong>Adviser:</strong> <?php echo htmlspecialchars($submission['adviser_name']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Status:</strong> 
                                <span class="<?php echo get_submission_status_class($submission['submission_status']); ?>">
                                    <?php echo get_submission_status_label($submission['submission_status']); ?>
                                </span>
                            </p>
                            <p><strong>Version:</strong> <?php echo $submission['version_number']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['total_annotations'] ?? 0; ?></h5>
                            <p class="card-text">Total Annotations</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['active_annotations'] ?? 0; ?></h5>
                            <p class="card-text">Active</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['resolved_annotations'] ?? 0; ?></h5>
                            <p class="card-text">Resolved</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['total_replies'] ?? 0; ?></h5>
                            <p class="card-text">Replies</p>
                        </div>
                    </div>
                </div>
            </div>

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
                    
                    <!-- PDF Canvas Container -->
                    <div class="pdf-canvas-container" id="pdf-canvas-container"></div>
                </div>
                
                <!-- Comment Panel (Read-Only for Students) -->
                <div class="comment-panel">
                    <div class="comment-panel-header">
                        <span>Adviser Feedback</span>
                        <span class="comment-count-badge" id="annotationCount"><?php echo count($annotations); ?></span>
                    </div>
                    <div class="comment-panel-content"></div>
                </div>
            </div>

            <!-- Upload Revision Section -->
            <?php if ($submission['submission_status'] !== 'approved'): ?>
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-arrow-repeat"></i> Upload Revised PDF</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Submit a revised version of your PDF based on the feedback above.</p>
                    <form id="revisionUploadForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="revisionFile" class="form-label">Select Revised PDF File</label>
                            <input type="file" class="form-control" id="revisionFile" name="pdf_file" accept=".pdf" required>
                            <small class="text-muted">Maximum file size: 50MB</small>
                        </div>
                        <button type="submit" class="btn btn-info">
                            <i class="bi bi-upload"></i> Upload Revised PDF
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="pdf_viewer.js"></script>
    <script src="annotation_manager.js"></script>
    
    <script>
        // Initialize PDF Viewer
        const pdfViewer = new PDFViewer({
            pdfUrl: '<?php echo htmlspecialchars($submission['file_path']); ?>',
            containerId: 'pdf-canvas-container',
            scale: 1.5
        });
        
        // Initialize Annotation Manager (read-only for students)
        const annotationManager = new AnnotationManager({
            submissionId: <?php echo $submission_id; ?>,
            userId: <?php echo $_SESSION['user_id']; ?>,
            userRole: '<?php echo $_SESSION['role']; ?>',
            pdfViewer: pdfViewer,
            apiEndpoint: 'pdf_annotation_api.php'
        });
        
        // Page navigation
        document.getElementById('prevPageBtn').addEventListener('click', () => pdfViewer.previousPage());
        document.getElementById('nextPageBtn').addEventListener('click', () => pdfViewer.nextPage());
        
        // Zoom controls
        document.getElementById('zoomInBtn').addEventListener('click', () => pdfViewer.zoomIn());
        document.getElementById('zoomOutBtn').addEventListener('click', () => pdfViewer.zoomOut());
        document.getElementById('resetZoomBtn').addEventListener('click', () => pdfViewer.resetZoom());
        
        // Revision upload handler
        document.getElementById('revisionUploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'upload_revision');
            formData.append('pdf_file', document.getElementById('revisionFile').files[0]);
            formData.append('parent_submission_id', <?php echo $submission_id; ?>);
            formData.append('adviser_id', <?php echo $submission['adviser_id']; ?>);
            
            try {
                const response = await fetch('pdf_upload_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Revised PDF uploaded successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error uploading revised PDF');
            }
        });
    </script>
</body>
</html>
