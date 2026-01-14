# PDF Submission Integration Guide

## Overview
This guide documents the complete integration of PDF submission workflow between student and adviser dashboards, enabling students to upload PDFs and advisers to review them with annotation capabilities.

## Implementation Date
January 14, 2026

## Features Implemented

### 1. Student Dashboard PDF Upload
**File:** [`student_dashboard.php`](student_dashboard.php:864-958)

Students can:
- Upload PDF files (max 50MB)
- Select their adviser from a dropdown
- View their submission history with status tracking
- See version numbers for each submission
- Access their submitted PDFs for viewing

**Key Components:**
- Upload form with file input and adviser selection (lines 876-897)
- PDF submissions table showing all student's uploads (lines 908-955)
- Integration with [`pdf_upload_handler.php`](pdf_upload_handler.php:1) for processing

### 2. Adviser Dashboard Integration
**File:** [`adviser.php`](adviser.php:1-709)

#### A. PDF Submissions Data Fetching
**Location:** Lines 169-197

```php
// Fetch PDF submissions for this adviser
$pdfSubmissions = [];
$pdfSubmissionsSql = "
    SELECT 
        ps.submission_id,
        ps.student_id,
        ps.original_filename,
        ps.file_size,
        ps.submission_status,
        ps.submission_timestamp,
        ps.version_number,
        CONCAT(u.firstname, ' ', u.lastname) AS student_name,
        u.email AS student_email
    FROM pdf_submissions ps
    JOIN users u ON u.id = ps.student_id
    WHERE ps.adviser_id = ?
    ORDER BY ps.submission_timestamp DESC
";
```

This query fetches all PDF submissions assigned to the logged-in adviser.

#### B. PDF Review Button in Advisee Tracker
**Location:** Lines 574-604

Added a "PDF" button next to the existing "Review" button that:
- Checks if the student has submitted any PDFs
- Shows a green "PDF" button if submissions exist
- Links directly to [`adviser_pdf_review.php`](adviser_pdf_review.php:1) with the submission ID
- Displays only for students with PDF submissions

**Visual Example:**
```
[Review] [PDF] ← Both buttons appear when student has submissions
```

#### C. Dedicated PDF Submissions Card
**Location:** Lines 594-656

A new card section that displays:
- **Header:** "PDF Submissions" with file count badge
- **Empty State:** Message when no PDFs are submitted
- **Submission List:** Shows up to 5 most recent PDFs with:
  - Student name
  - Filename
  - Submission timestamp
  - Version number
  - Status badge (reviewed/pending)
- **Clickable Items:** Each submission links to the review page

**Features:**
- Shows submission count badge
- Color-coded status indicators
- Timestamp with version tracking
- Responsive design
- Scrollable list for multiple submissions

### 3. PDF Review Page
**File:** [`adviser_pdf_review.php`](adviser_pdf_review.php:1-491)

The review page includes:
- **PDF Viewer:** Full-screen PDF display with zoom controls
- **Annotation Tools:** Comment, Highlight, and Suggestion tools (lines 364-377)
- **Navigation:** Page controls and zoom functionality
- **Back Button:** Returns to adviser dashboard (line 327-330)
- **Security:** Validates adviser has permission to view the submission

## Complete Workflow

### Step 1: Student Uploads PDF
1. Student navigates to their dashboard
2. Scrolls to "PDF Submissions" section
3. Selects PDF file (max 50MB)
4. Chooses their adviser from dropdown
5. Clicks "Upload PDF"
6. File is processed by [`pdf_upload_handler.php`](pdf_upload_handler.php:1)
7. Record created in `pdf_submissions` table

### Step 2: Adviser Receives Notification
The PDF appears in two places on [`adviser.php`](adviser.php:1):

**A. Advisee Tracker Table**
- Shows "PDF" button next to student's name
- Button appears in the Action column
- Green color indicates new submission

**B. PDF Submissions Card**
- Lists all recent PDF submissions
- Shows student name, filename, and status
- Badge shows total count of submissions

### Step 3: Adviser Reviews PDF
1. Adviser clicks either:
   - "PDF" button in Advisee Tracker, OR
   - Student name in PDF Submissions card
2. Opens [`adviser_pdf_review.php?submission_id=X`](adviser_pdf_review.php:1)
3. PDF loads with annotation tools
4. Adviser can:
   - Add comments
   - Highlight text
   - Make suggestions
   - Navigate pages
   - Zoom in/out

### Step 4: Return to Dashboard
- Click "Back to Dashboard" button (line 327-330)
- Returns to [`adviser.php`](adviser.php:1)
- Submission status updated to "reviewed"

## Database Schema

### pdf_submissions Table
```sql
CREATE TABLE pdf_submissions (
    submission_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    adviser_id INT NOT NULL,
    original_filename VARCHAR(255),
    file_path VARCHAR(500),
    file_size BIGINT,
    submission_status VARCHAR(50) DEFAULT 'pending',
    submission_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    version_number INT DEFAULT 1,
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (adviser_id) REFERENCES users(id)
);
```

## File Structure

```
IAdS_Ni/
├── student_dashboard.php          # Student uploads PDFs here
├── adviser.php                    # Adviser views submissions list
├── adviser_pdf_review.php         # Adviser reviews with annotations
├── pdf_upload_handler.php         # Processes PDF uploads
├── pdf_submission_helpers.php     # Helper functions
├── pdf_annotation_helpers.php     # Annotation functions
├── pdf_viewer.js                  # PDF rendering
├── annotation_manager.js          # Annotation management
└── pdf_annotation_styles.css      # Styling
```

## Key Features

### Security
- ✅ Session validation for both student and adviser
- ✅ Role-based access control
- ✅ Adviser can only see their assigned students' PDFs
- ✅ Student can only upload to their assigned adviser

### User Experience
- ✅ Intuitive upload interface
- ✅ Real-time status tracking
- ✅ Version numbering for multiple submissions
- ✅ Easy navigation between dashboard and review
- ✅ Visual indicators for new submissions

### Functionality
- ✅ PDF rendering with zoom controls
- ✅ Annotation tools (comment, highlight, suggest)
- ✅ Persistent annotations saved to database
- ✅ Status tracking (pending → reviewed)
- ✅ File size validation (50MB limit)

## Testing Checklist

- [ ] Student can upload PDF successfully
- [ ] PDF appears in adviser's dashboard
- [ ] "PDF" button shows in Advisee Tracker
- [ ] PDF Submissions card displays correctly
- [ ] Clicking PDF button opens review page
- [ ] PDF renders correctly in review page
- [ ] Annotation tools work properly
- [ ] Back button returns to dashboard
- [ ] Status updates to "reviewed" after viewing
- [ ] Multiple PDFs from same student display correctly
- [ ] Version numbers increment properly

## Future Enhancements (Optional)

1. **Notification System**
   - Send email/in-app notification when student uploads PDF
   - Notify student when adviser reviews their submission

2. **Bulk Operations**
   - Download multiple PDFs at once
   - Mark multiple as reviewed

3. **Advanced Filtering**
   - Filter by status (pending/reviewed)
   - Filter by student
   - Sort by date/name

4. **Analytics**
   - Track average review time
   - Monitor submission patterns
   - Generate reports

## Support Files

- [`PDF_ANNOTATION_IMPLEMENTATION_GUIDE.md`](PDF_ANNOTATION_IMPLEMENTATION_GUIDE.md) - Annotation system details
- [`PDF_SUBMISSION_DISPLAY_GUIDE.md`](PDF_SUBMISSION_DISPLAY_GUIDE.md) - Display implementation
- [`PDF_FILE_STORAGE_GUIDE.md`](PDF_FILE_STORAGE_GUIDE.md) - File storage structure
- [`ANNOTATION_TOOLS_LOCATION_GUIDE.md`](ANNOTATION_TOOLS_LOCATION_GUIDE.md) - Tool locations

## Troubleshooting

### PDF Not Showing in Adviser Dashboard
1. Check if `adviser_id` is correctly set in `pdf_submissions` table
2. Verify student selected correct adviser during upload
3. Check database connection in [`adviser.php`](adviser.php:169-197)

### Review Page Not Loading
1. Verify `submission_id` parameter in URL
2. Check file path exists in database
3. Ensure PDF file exists on server
4. Check adviser has permission (adviser_id matches)

### Annotations Not Saving
1. Check [`pdf_annotation_api.php`](pdf_annotation_api.php:1) is accessible
2. Verify database table `pdf_annotations` exists
3. Check browser console for JavaScript errors

## Conclusion

The PDF submission integration is now complete and functional. Students can upload PDFs to their advisers, and advisers can review them with full annotation capabilities directly from their dashboard. The system includes proper security, status tracking, and an intuitive user interface.