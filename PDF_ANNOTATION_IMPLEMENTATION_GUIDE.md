# PDF Annotation System - Implementation Guide

## Overview

This guide provides step-by-step instructions for implementing the PDF review and annotation system in the IAdS platform.

---

## PART 1: DATABASE SETUP

### Step 1: Create Database Tables

Execute the SQL schema file to create all required tables:

```bash
mysql -u root -p advance_studies < pdf_annotation_schema.sql
```

Or manually execute the SQL commands in your database management tool.

**Tables Created:**
- `pdf_submissions` - Stores PDF metadata and submission versions
- `pdf_annotations` - Stores individual annotations
- `annotation_replies` - Stores student/adviser replies to annotations
- `submission_notifications` - Tracks notification delivery
- `annotation_history` - Maintains audit trail

### Step 2: Create Upload Directories

Create the necessary directories for PDF storage:

```bash
mkdir -p uploads/pdf_submissions
mkdir -p uploads/pdf_revisions
chmod 755 uploads/pdf_submissions
chmod 755 uploads/pdf_revisions
```

---

## PART 2: FILE PLACEMENT

Place the following files in your project root directory:

### Backend Files
- `pdf_submission_helpers.php` - PDF submission management functions
- `pdf_annotation_helpers.php` - Annotation management functions
- `pdf_upload_handler.php` - File upload processing
- `pdf_annotation_api.php` - AJAX API endpoints

### Frontend Files
- `pdf_annotation_styles.css` - Responsive styling
- `pdf_viewer.js` - PDF.js integration
- `annotation_manager.js` - Annotation interaction logic

### Documentation
- `PDF_ANNOTATION_IMPLEMENTATION_GUIDE.md` - This file
- `pdf_annotation_schema.sql` - Database schema

---

## PART 3: ADVISER INTERFACE IMPLEMENTATION

### Step 1: Create Adviser PDF Review Page

Create a new file `adviser_pdf_review.php`:

```php
<?php
session_start();
require_once 'db.php';
require_once 'pdf_submission_helpers.php';
require_once 'pdf_annotation_helpers.php';

// Verify user is adviser
if ($_SESSION['role'] !== 'adviser' && $_SESSION['role'] !== 'committee_chairperson' && $_SESSION['role'] !== 'panel') {
    header('Location: login.php');
    exit;
}

$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$submission = fetch_pdf_submission($conn, $submission_id);

if (!$submission || $submission['adviser_id'] != $_SESSION['user_id']) {
    header('Location: adviser.php');
    exit;
}

$annotations = fetch_submission_annotations($conn, $submission_id);
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
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <div class="pdf-review-container">
            <!-- PDF Viewer -->
            <div class="pdf-viewer-wrapper">
                <!-- Toolbar -->
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
                
                <!-- Annotation Toolbar -->
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
                
                <!-- PDF Canvas -->
                <div class="pdf-canvas-container" id="pdf-canvas-container"></div>
            </div>
            
            <!-- Comment Panel -->
            <div class="comment-panel">
                <div class="comment-panel-header">
                    <span>Annotations</span>
                    <span class="comment-count-badge" id="annotationCount">0</span>
                </div>
                <div class="comment-panel-content"></div>
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
            apiEndpoint: 'pdf_annotation_api.php'
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
```

---

## PART 4: STUDENT INTERFACE IMPLEMENTATION

### Step 1: Create Student PDF Upload Component

Add to `student_dashboard.php`:

```php
<?php
// At the top of the file, include helpers
require_once 'pdf_submission_helpers.php';
require_once 'pdf_annotation_helpers.php';

// Get student's submissions
$submissions = fetch_student_submissions($conn, $_SESSION['user_id']);
?>

<!-- Add this section to student dashboard -->
<div class="card mt-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-file-pdf"></i> PDF Submissions</h5>
    </div>
    <div class="card-body">
        <!-- Upload Form -->
        <div class="mb-4">
            <h6>Upload New PDF</h6>
            <form id="pdfUploadForm" enctype="multipart/form-data">
                <div class="mb-3">
                    <label for="pdfFile" class="form-label">Select PDF File</label>
                    <input type="file" class="form-control" id="pdfFile" name="pdf_file" accept=".pdf" required>
                    <small class="text-muted">Maximum file size: 50MB</small>
                </div>
                <div class="mb-3">
                    <label for="adviserId" class="form-label">Select Adviser</label>
                    <select class="form-control" id="adviserId" name="adviser_id" required>
                        <option value="">-- Select Adviser --</option>
                        <?php
                        // Fetch available advisers
                        $advisers = $conn->query("SELECT id, CONCAT(firstname, ' ', lastname) as name FROM users WHERE role IN ('adviser', 'committee_chairperson')");
                        while ($adviser = $advisers->fetch_assoc()) {
                            echo "<option value='{$adviser['id']}'>{$adviser['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload"></i> Upload PDF
                </button>
            </form>
        </div>
        
        <!-- Submissions List -->
        <div class="mt-4">
            <h6>Your Submissions</h6>
            <?php if (empty($submissions)): ?>
                <p class="text-muted">No submissions yet</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Adviser</th>
                                <th>Status</th>
                                <th>Annotations</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissions as $sub): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sub['original_filename']); ?></td>
                                    <td><?php echo htmlspecialchars($sub['adviser_name']); ?></td>
                                    <td>
                                        <span class="<?php echo get_submission_status_class($sub['submission_status']); ?>">
                                            <?php echo get_submission_status_label($sub['submission_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $sub['annotation_count']; ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($sub['submission_timestamp'])); ?></td>
                                    <td>
                                        <a href="student_pdf_view.php?submission_id=<?php echo $sub['submission_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('pdfUploadForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('pdf_file', document.getElementById('pdfFile').files[0]);
    formData.append('adviser_id', document.getElementById('adviserId').value);
    
    try {
        const response = await fetch('pdf_upload_handler.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('PDF uploaded successfully!');
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error uploading PDF');
    }
});
</script>
```

### Step 2: Create Student PDF View Page

Create `student_pdf_view.php`:

```php
<?php
session_start();
require_once 'db.php';
require_once 'pdf_submission_helpers.php';
require_once 'pdf_annotation_helpers.php';

// Verify user is student
if ($_SESSION['role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;
$submission = fetch_pdf_submission($conn, $submission_id);

if (!$submission || $submission['student_id'] != $_SESSION['user_id']) {
    header('Location: student_dashboard.php');
    exit;
}

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
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-md-8">
                    <h3><i class="bi bi-file-pdf"></i> PDF Feedback</h3>
                    <p class="text-muted">
                        Submitted: <?php echo date('F d, Y g:i A', strtotime($submission['submission_timestamp'])); ?>
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="student_dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- Submission Info -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>File:</strong> <?php echo htmlspecialchars($submission['original_filename']); ?></p>
                            <p><strong>Adviser:</strong> <?php echo htmlspecialchars($submission['adviser_name']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Status:</strong> <span class="<?php echo get_submission_status_class($submission['submission_status']); ?>"><?php echo get_submission_status_label($submission['submission_status']); ?></span></p>
                            <p><strong>Version:</strong> <?php echo $submission['version_number']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics -->
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
            
            <!-- PDF Viewer and Comments -->
            <div class="pdf-review-container">
                <!-- PDF Viewer -->
                <div class="pdf-viewer-wrapper">
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
                                <button id="zoomOutBtn">−</button>
                                <span id="zoomLevel">100%</span>
                                <button id="zoomInBtn">+</button>
                                <button id="resetZoomBtn">Reset</button>
                            </div>
                        </div>
                    </div>
                    <div class="pdf-canvas-container" id="pdf-canvas-container"></div>
                </div>
                
                <!-- Comment Panel -->
                <div class="comment-panel">
                    <div class="comment-panel-header">
                        <span>Adviser Feedback</span>
                        <span class="comment-count-badge" id="annotationCount"><?php echo count($annotations); ?></span>
                    </div>
                    <div class="comment-panel-content"></div>
                </div>
            </div>
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
    </script>
</body>
</html>
```

---

## PART 5: TESTING

### Test Checklist

- [ ] Database tables created successfully
- [ ] Upload directories created with proper permissions
- [ ] Student can upload PDF
- [ ] Adviser receives notification
- [ ] Adviser can view PDF in browser
- [ ] Adviser can create annotations
- [ ] Annotations saved to database
- [ ] Student can view annotations
- [ ] Student can reply to annotations
- [ ] Responsive design works on mobile
- [ ] All error messages display correctly
- [ ] File upload validation works
- [ ] Access control prevents unauthorized access

---

## PART 6: SECURITY CHECKLIST

- [ ] File upload validation implemented
- [ ] MIME type checking enabled
- [ ] File size limits enforced
- [ ] SQL injection prevention (prepared statements)
- [ ] XSS prevention (output escaping)
- [ ] CSRF protection (if implemented)
- [ ] Access control verified
- [ ] Session validation on all pages
- [ ] File permissions set correctly
- [ ] Sensitive data encrypted

---

## PART 7: TROUBLESHOOTING

### Issue: PDF not loading
**Solution:** Check file path and ensure PDF.js CDN is accessible

### Issue: Annotations not saving
**Solution:** Verify database tables exist and user has proper permissions

### Issue: Responsive design broken
**Solution:** Check CSS file is loaded and viewport meta tag is present

### Issue: Notifications not appearing
**Solution:** Verify notification system is integrated and working

---

## PART 8: NEXT STEPS

1. Test all functionality thoroughly
2. Implement email notifications (optional)
3. Add annotation export feature (optional)
4. Implement annotation search (optional)
5. Add analytics dashboard (optional)

---

## Support

For issues or questions, refer to:
- `pdf_annotation_schema.sql` - Database structure
- `pdf_submission_helpers.php` - Submission functions
- `pdf_annotation_helpers.php` - Annotation functions
- `pdf_annotation_api.php` - API endpoints
