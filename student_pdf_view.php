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

// Get version chain information
$version_info = get_version_chain_info($conn, $submission_id);
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

            <!-- Version Navigation -->
            <?php if ($version_info && $version_info['total_versions'] > 1): ?>
            <div class="version-navigation">
                <a href="<?php echo $version_info['has_previous'] ? 'student_pdf_view.php?submission_id=' . $version_info['previous_id'] : '#'; ?>"
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
                    <a href="<?php echo $version_info['has_next'] ? 'student_pdf_view.php?submission_id=' . $version_info['next_id'] : '#'; ?>"
                       class="btn <?php echo $version_info['has_next'] ? '' : 'disabled'; ?>"
                       <?php echo $version_info['has_next'] ? '' : 'onclick="return false;"'; ?>>
                        Next Version <i class="bi bi-arrow-right"></i>
                    </a>
                    
                    <?php if (!$version_info['is_latest']): ?>
                        <a href="student_pdf_view.php?submission_id=<?php echo $version_info['latest_id']; ?>"
                           class="btn btn-success">
                            <i class="bi bi-skip-end-fill"></i> Jump to Latest
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

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
        
        // Revision upload handler with verbose logging
        document.getElementById('revisionUploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            console.log('=== REVISION UPLOAD DEBUG START ===');
            
            const fileInput = document.getElementById('revisionFile');
            const file = fileInput.files[0];
            
            // Log file details
            console.log('File selected:', {
                name: file?.name,
                size: file?.size,
                type: file?.type
            });
            
            const formData = new FormData();
            formData.append('action', 'upload_revision');
            formData.append('pdf_file', file);
            formData.append('parent_submission_id', <?php echo $submission_id; ?>);
            formData.append('adviser_id', <?php echo $submission['adviser_id']; ?>);
            
            // Log FormData contents
            console.log('FormData contents:');
            for (let [key, value] of formData.entries()) {
                if (value instanceof File) {
                    console.log(`  ${key}:`, value.name, `(${value.size} bytes)`);
                } else {
                    console.log(`  ${key}:`, value);
                }
            }
            
            try {
                console.log('Sending request to pdf_upload_handler.php...');
                
                const response = await fetch('pdf_upload_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                console.log('Response received:', {
                    status: response.status,
                    statusText: response.statusText,
                    headers: {
                        contentType: response.headers.get('content-type'),
                        location: response.headers.get('location')
                    },
                    url: response.url,
                    redirected: response.redirected
                });
                
                // Get response text first to see what we actually received
                const responseText = await response.text();
                console.log('Response text:', responseText);
                
                // Try to parse as JSON
                let result;
                try {
                    result = JSON.parse(responseText);
                    console.log('Parsed JSON result:', result);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.log('Response was not valid JSON. Raw response:', responseText.substring(0, 500));
                    alert('Error: Server returned invalid response. Check console for details.');
                    console.log('=== REVISION UPLOAD DEBUG END ===');
                    return;
                }
                
                if (result.success) {
                    console.log('Upload successful!');
                    console.log('New submission ID:', result.submission_id);
                    console.log('New version:', result.version);
                    alert('Revised PDF uploaded successfully! Redirecting to new version...');
                    // Redirect to the NEW version instead of reloading current page
                    window.location.href = 'student_pdf_view.php?submission_id=' + result.submission_id;
                } else {
                    console.error('Upload failed:', result.error || result.errors);
                    alert('Error: ' + (result.error || JSON.stringify(result.errors) || 'Unknown error'));
                }
            } catch (error) {
                console.error('Fetch error:', error);
                console.error('Error stack:', error.stack);
                alert('Error uploading revised PDF: ' + error.message + '\nCheck console for details.');
            }
            
            console.log('=== REVISION UPLOAD DEBUG END ===');
        });
    </script>
</body>
</html>
