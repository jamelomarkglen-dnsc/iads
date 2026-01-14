# PDF Submission Display Guide - Where to See Submissions

## Quick Answer

**STUDENT DASHBOARD:** 
- Section: "📄 PDF SUBMISSIONS" 
- Shows: Upload form + Table of all submissions with status

**ADVISER DASHBOARD:**
- Section: "📋 PDF REVIEWS"
- Shows: Statistics + Pending/In Progress/Completed tables

---

## PART 1: STUDENT DASHBOARD - PDF SUBMISSIONS SECTION

### Location: `student_dashboard.php`

Add this code section to your student dashboard:

```php
<?php
// At the top of student_dashboard.php
require_once 'pdf_submission_helpers.php';
require_once 'pdf_annotation_helpers.php';

// Get student's submissions
$submissions = fetch_student_submissions($conn, $_SESSION['user_id']);
?>

<!-- Add this HTML section to the dashboard body -->
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

### What Student Sees:

```
┌─────────────────────────────────────────────────────────────┐
│  📄 PDF SUBMISSIONS                                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Upload New PDF                                            │
│  ┌─────────────────────────────────────────────────────┐  │
│  │ Select PDF File: [Choose File]                      │  │
│  │ Maximum file size: 50MB                             │  │
│  │                                                     │  │
│  │ Select Adviser: [John Jamelo ▼]                     │  │
│  │                                                     │  │
│  │ [📤 Upload PDF]                                     │  │
│  └─────────────────────────────────────────────────────┘  │
│                                                             │
│  Your Submissions                                           │
│  ┌─────────────────────────────────────────────────────┐  │
│  │ File Name      │ Adviser    │ Status   │ Annot │    │  │
│  │ ─────────────────────────────────────────────────── │  │
│  │ thesis.pdf     │ John       │ Reviewed │ 5    │    │  │
│  │                                    [View]          │  │
│  │ dissertation   │ John       │ Pending  │ 0    │    │  │
│  │                                    [View]          │  │
│  │ research_v2    │ John       │ Approved │ 8    │    │  │
│  │                                    [View]          │  │
│  └─────────────────────────────────────────────────────┘  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## PART 2: ADVISER DASHBOARD - PDF REVIEWS SECTION

### Location: `adviser.php`

Add this code section to your adviser dashboard:

```php
<?php
// At the top of adviser.php
require_once 'pdf_submission_helpers.php';
require_once 'pdf_annotation_helpers.php';

// Get adviser's submissions
$submissions = fetch_adviser_submissions($conn, $_SESSION['user_id']);

// Separate by status
$pending = array_filter($submissions, fn($s) => $s['submission_status'] === 'pending');
$reviewed = array_filter($submissions, fn($s) => $s['submission_status'] === 'reviewed');
$completed = array_filter($submissions, fn($s) => in_array($s['submission_status'], ['approved', 'revision_requested']));
?>

<!-- Add this HTML section to the adviser dashboard body -->
<div class="card mt-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-file-pdf"></i> PDF Reviews</h5>
    </div>
    <div class="card-body">
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo count($pending); ?></h5>
                        <p class="card-text">Pending Review</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo count($reviewed); ?></h5>
                        <p class="card-text">In Progress</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo count($completed); ?></h5>
                        <p class="card-text">Completed</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Pending Reviews -->
        <h6>Pending Review</h6>
        <?php if (empty($pending)): ?>
            <p class="text-muted">No pending reviews</p>
        <?php else: ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>File</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $sub): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sub['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($sub['original_filename']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($sub['submission_timestamp'])); ?></td>
                                <td>
                                    <a href="adviser_pdf_review.php?submission_id=<?php echo $sub['submission_id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-pencil"></i> Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- In Progress -->
        <h6>In Progress</h6>
        <?php if (empty($reviewed)): ?>
            <p class="text-muted">No reviews in progress</p>
        <?php else: ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>File</th>
                            <th>Annotations</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviewed as $sub): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sub['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($sub['original_filename']); ?></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $sub['annotation_count']; ?></span>
                                </td>
                                <td>
                                    <a href="adviser_pdf_review.php?submission_id=<?php echo $sub['submission_id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Completed -->
        <h6>Completed</h6>
        <?php if (empty($completed)): ?>
            <p class="text-muted">No completed reviews</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>File</th>
                            <th>Status</th>
                            <th>Annotations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed as $sub): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sub['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($sub['original_filename']); ?></td>
                                <td>
                                    <span class="<?php echo get_submission_status_class($sub['submission_status']); ?>">
                                        <?php echo get_submission_status_label($sub['submission_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $sub['annotation_count']; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
```

### What Adviser Sees:

```
┌─────────────────────────────────────────────────────────────┐
│  📋 PDF REVIEWS                                             │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │      3       │  │      1       │  │      5       │     │
│  │ Pending      │  │ In Progress  │  │ Completed    │     │
│  │ Review       │  │              │  │              │     │
│  └──────────────┘  └──────────────┘  └──────────────┘     │
│                                                             │
│  Pending Review                                            │
│  ┌─────────────────────────────────────────────────────┐  │
│  │ Student         │ File          │ Submitted │       │  │
│  │ ─────────────────────────────────────────────────── │  │
│  │ John Castro     │ thesis.pdf    │ 2 hours   │       │  │
│  │                                      [Review]       │  │
│  │ KC Caminade     │ dissertation  │ 1 day     │       │  │
│  │                                      [Review]       │  │
│  └─────────────────────────────────────────────────────┘  │
│                                                             │
│  In Progress                                               │
│  ┌─────────────────────────────────────────────────────┐  │
│  │ Student         │ File          │ Annot │           │  │
│  │ ─────────────────────────────────────────────────── │  │
│  │ Maria Santos    │ research.pdf  │ 3    │ [View]    │  │
│  └─────────────────────────────────────────────────────┘  │
│                                                             │
│  Completed                                                 │
│  ┌─────────────────────────────────────────────────────┐  │
│  │ Student         │ File          │ Status   │ Annot │  │
│  │ ─────────────────────────────────────────────────── │  │
│  │ Previous        │ old_file.pdf  │ Approved │ 8    │  │
│  └─────────────────────────────────────────────────────┘  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## PART 3: NOTIFICATION SYSTEM

### Where Notifications Appear

Both student and adviser receive notifications in the notification center:

**Adviser Receives When:**
- Student uploads new PDF → "John Castro submitted a new PDF for review"
- Student replies to annotation → "Student replied to your annotation"
- Student uploads revision → "John Castro submitted a revised PDF (Version 2)"

**Student Receives When:**
- Adviser adds annotation → "John Jamelo added feedback to your PDF"
- Adviser replies → "Adviser replied to your annotation"
- Adviser approves → "Your submission has been approved"

---

## PART 4: COMPLETE WORKFLOW DIAGRAM

```
STUDENT SIDE                          ADVISER SIDE
─────────────────────────────────────────────────────

1. STUDENT DASHBOARD
   ├─ PDF Submissions Section
   │  ├─ Upload Form
   │  │  ├─ Select File
   │  │  ├─ Select Adviser
   │  │  └─ [Upload]
   │  │
   │  └─ Submissions Table
   │     ├─ File Name
   │     ├─ Adviser
   │     ├─ Status
   │     ├─ Annotations Count
   │     └─ [View] Button
   │
   └─ Notification
      "Adviser added feedback"
      └─ [Click] → View Feedback
                                        
                                    2. ADVISER DASHBOARD
                                       ├─ PDF Reviews Section
                                       │  ├─ Statistics Cards
                                       │  │  ├─ Pending Count
                                       │  │  ├─ In Progress Count
                                       │  │  └─ Completed Count
                                       │  │
                                       │  ├─ Pending Table
                                       │  │  ├─ Student Name
                                       │  │  ├─ File Name
                                       │  │  ├─ Submitted Date
                                       │  │  └─ [Review] Button
                                       │  │
                                       │  ├─ In Progress Table
                                       │  │  ├─ Student Name
                                       │  │  ├─ File Name
                                       │  │  ├─ Annotation Count
                                       │  │  └─ [View] Button
                                       │  │
                                       │  └─ Completed Table
                                       │     ├─ Student Name
                                       │     ├─ File Name
                                       │     ├─ Status
                                       │     └─ Annotation Count
                                       │
                                       └─ Notification
                                          "Student uploaded PDF"
                                          └─ [Click] → Review PDF

3. STUDENT PDF VIEW PAGE
   ├─ PDF Viewer
   ├─ Adviser Annotations
   ├─ Reply to Annotations
   └─ Upload Revision
                                    
                                    4. ADVISER PDF REVIEW PAGE
                                       ├─ PDF Viewer
                                       ├─ Annotation Toolbar
                                       │  ├─ Comment Tool
                                       │  ├─ Highlight Tool
                                       │  └─ Suggestion Tool
                                       ├─ Create Annotations
                                       ├─ Comment Panel
                                       └─ Save Annotations
```

---

## SUMMARY

**To see PDF submissions:**

1. **As Student:** Go to `student_dashboard.php` → Scroll to "📄 PDF SUBMISSIONS" section
2. **As Adviser:** Go to `adviser.php` → Scroll to "📋 PDF REVIEWS" section
3. **To Review:** Click [Review] button in adviser dashboard
4. **To View Feedback:** Click [View] button in student dashboard
