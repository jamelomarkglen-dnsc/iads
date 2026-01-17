# PDF Submissions Integration Guide

## Problem
The existing `student_dashboard.php` doesn't have the "PDF Submissions" section for uploading PDFs.

## Solution
Add the PDF Submissions section to `student_dashboard.php`

---

## WHERE TO ADD THE CODE

In `student_dashboard.php`, find this section (around line 815):

```php
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Program Chair Feedback</h5>
```

**BEFORE this section**, add the following code:

---

## CODE TO ADD

```php
<!-- PDF SUBMISSIONS SECTION - ADD THIS BEFORE "Program Chair Feedback" -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-pdf"></i> PDF Submissions</h5>
        <?php
        // Count PDF submissions
        $pdfCountStmt = $conn->prepare("SELECT COUNT(*) as count FROM pdf_submissions WHERE student_id = ?");
        if ($pdfCountStmt) {
            $pdfCountStmt->bind_param('i', $studentId);
            $pdfCountStmt->execute();
            $pdfCountResult = $pdfCountStmt->get_result()->fetch_assoc();
            $pdfCount = $pdfCountResult['count'] ?? 0;
            $pdfCountStmt->close();
            if ($pdfCount > 0) {
                echo '<span class="badge bg-success-subtle text-success">' . $pdfCount . ' submissions</span>';
            }
        }
        ?>
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
                        // Get available advisers
                        $adviserStmt = $conn->prepare("SELECT id, CONCAT(firstname, ' ', lastname) as name FROM users WHERE role IN ('adviser', 'committee_chairperson') ORDER BY firstname ASC");
                        if ($adviserStmt) {
                            $adviserStmt->execute();
                            $adviserResult = $adviserStmt->get_result();
                            while ($adviser = $adviserResult->fetch_assoc()) {
                                echo "<option value='{$adviser['id']}'>" . htmlspecialchars($adviser['name']) . "</option>";
                            }
                            $adviserStmt->close();
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-upload"></i> Upload PDF
                </button>
            </form>
        </div>
        
        <!-- Submissions List -->
        <div class="mt-4">
            <h6>Your PDF Submissions</h6>
            <?php
            // Fetch PDF submissions
            $pdfSubmissionsStmt = $conn->prepare("
                SELECT 
                    ps.submission_id,
                    ps.original_filename,
                    ps.submission_status,
                    ps.submission_timestamp,
                    ps.version_number,
                    CONCAT(u.firstname, ' ', u.lastname) as adviser_name,
                    COUNT(pa.annotation_id) as annotation_count
                FROM pdf_submissions ps
                LEFT JOIN users u ON ps.adviser_id = u.id
                LEFT JOIN pdf_annotations pa ON ps.submission_id = pa.submission_id
                WHERE ps.student_id = ?
                GROUP BY ps.submission_id
                ORDER BY ps.submission_timestamp DESC
            ");
            
            $pdfSubmissions = [];
            if ($pdfSubmissionsStmt) {
                $pdfSubmissionsStmt->bind_param('i', $studentId);
                $pdfSubmissionsStmt->execute();
                $pdfResult = $pdfSubmissionsStmt->get_result();
                while ($row = $pdfResult->fetch_assoc()) {
                    $pdfSubmissions[] = $row;
                }
                $pdfSubmissionsStmt->close();
            }
            
            if (empty($pdfSubmissions)): ?>
                <p class="text-muted">No PDF submissions yet. Upload your first PDF above.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Adviser</th>
                                <th>Status</th>
                                <th>Annotations</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdfSubmissions as $sub): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sub['original_filename']); ?></td>
                                    <td><?php echo htmlspecialchars($sub['adviser_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        $statusClass = 'badge bg-secondary-subtle text-secondary';
                                        $statusLabel = ucfirst($sub['submission_status']);
                                        
                                        if ($sub['submission_status'] === 'pending') {
                                            $statusClass = 'badge bg-warning-subtle text-warning';
                                            $statusLabel = 'Pending Review';
                                        } elseif ($sub['submission_status'] === 'reviewed') {
                                            $statusClass = 'badge bg-info-subtle text-info';
                                            $statusLabel = 'Reviewed';
                                        } elseif ($sub['submission_status'] === 'approved') {
                                            $statusClass = 'badge bg-success-subtle text-success';
                                            $statusLabel = 'Approved';
                                        }
                                        ?>
                                        <span class="<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo $sub['annotation_count'] ?? 0; ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($sub['submission_timestamp'])); ?></td>
                                    <td>
                                        <a href="student_pdf_view.php?submission_id=<?php echo $sub['submission_id']; ?>" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-eye"></i> View
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
    
    const fileInput = document.getElementById('pdfFile');
    const adviserSelect = document.getElementById('adviserId');
    
    if (!fileInput.files[0]) {
        alert('Please select a PDF file');
        return;
    }
    
    if (!adviserSelect.value) {
        alert('Please select an adviser');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('pdf_file', fileInput.files[0]);
    formData.append('adviser_id', adviserSelect.value);
    
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

---

## STEP-BY-STEP INTEGRATION

### Step 1: Open `student_dashboard.php`

Open the file in your editor.

### Step 2: Find the Location

Search for: `Program Chair Feedback`

Find this line (around line 818):
```php
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Program Chair Feedback</h5>
```

### Step 3: Add the Code

**BEFORE** the "Program Chair Feedback" section, paste the code above.

### Step 4: Save the File

Save `student_dashboard.php`

### Step 5: Test

1. Login as student
2. Go to `student_dashboard.php`
3. Scroll down to find "PDF Submissions" section
4. Upload a test PDF

---

## WHAT YOU'LL SEE

After adding the code, you'll see:

```
📄 PDF SUBMISSIONS

Upload New PDF
┌─────────────────────────────────────┐
│ Select PDF File: [Choose File]      │
│ Maximum file size: 50MB             │
│                                     │
│ Select Adviser: [Dropdown ▼]        │
│                                     │
│ [📤 Upload PDF]                     │
└─────────────────────────────────────┘

Your PDF Submissions
┌─────────────────────────────────────┐
│ File Name │ Adviser │ Status │ ...  │
│ thesis.pdf│ John    │ Pending│ [View]
└─────────────────────────────────────┘
```

---

## TROUBLESHOOTING

### Issue: "PDF Submissions" section doesn't appear

**Solution:** Make sure you added the code BEFORE the "Program Chair Feedback" section.

### Issue: Upload button doesn't work

**Solution:** Check that `pdf_upload_handler.php` exists in the same directory.

### Issue: "Select Adviser" dropdown is empty

**Solution:** Make sure there are advisers in the database with role 'adviser' or 'committee_chairperson'.

---

## NEXT STEPS

Once the PDF Submissions section is added:

1. Upload a test PDF as a student
2. Login as adviser
3. Go to `adviser.php`
4. Look for "PDF Reviews" section
5. Click [Review] button
6. You should see the PDF with annotation tools

---

## SUMMARY

The PDF Submissions section needs to be manually added to `student_dashboard.php` because it's a new feature. The code above provides the complete integration that will:

✅ Allow students to upload PDFs
✅ Show upload history
✅ Display annotation counts
✅ Link to feedback view page
✅ Handle file uploads securely
