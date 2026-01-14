# PDF File Storage & Directory Structure Guide

## Quick Answer

**Files are stored in:**
```
c:/xampp/IAdS_Ni/htdocs/IAdS_Ni/uploads/pdf_submissions/
```

**Database records are stored in:**
```
Database: advance_studies
Table: pdf_submissions
```

---

## PART 1: DIRECTORY STRUCTURE

### Complete Project Directory Tree

```
c:/xampp/IAdS_Ni/htdocs/IAdS_Ni/
│
├── uploads/                          ← Main uploads folder
│   ├── pdf_submissions/              ← ✅ STUDENT PDF FILES STORED HERE
│   │   ├── student_1/
│   │   │   ├── thesis_final_20240115_abc123.pdf
│   │   │   ├── dissertation_20240120_def456.pdf
│   │   │   └── research_v2_20240125_ghi789.pdf
│   │   │
│   │   ├── student_2/
│   │   │   ├── paper_20240110_jkl012.pdf
│   │   │   └── proposal_20240118_mno345.pdf
│   │   │
│   │   └── student_3/
│   │       └── thesis_20240122_pqr678.pdf
│   │
│   ├── pdf_revisions/                ← ✅ REVISED PDF FILES STORED HERE
│   │   ├── submission_1/
│   │   │   ├── v1_thesis_20240115_abc123.pdf
│   │   │   ├── v2_thesis_20240120_def456.pdf
│   │   │   └── v3_thesis_20240125_ghi789.pdf
│   │   │
│   │   └── submission_2/
│   │       ├── v1_paper_20240110_jkl012.pdf
│   │       └── v2_paper_20240118_mno345.pdf
│   │
│   └── archive_uploads/              ← Existing archive folder
│       └── [other files]
│
├── pdf_annotation_schema.sql         ← Database schema
├── pdf_submission_helpers.php        ← Helper functions
├── pdf_annotation_helpers.php        ← Annotation functions
├── pdf_upload_handler.php            ← Upload processor
├── pdf_annotation_api.php            ← API endpoints
├── pdf_annotation_styles.css         ← Styling
├── pdf_viewer.js                     ← PDF viewer
├── annotation_manager.js             ← Annotation manager
│
├── student_dashboard.php             ← Student sees submissions here
├── adviser.php                       ← Adviser sees submissions here
├── adviser_pdf_review.php            ← Adviser reviews PDF here
├── student_pdf_view.php              ← Student views feedback here
│
└── [other existing files]
```

---

## PART 2: FILE NAMING CONVENTION

### Student Submission Files

**Format:**
```
{original_filename}_{timestamp}_{random_hash}.pdf
```

**Examples:**
```
thesis_final_20240115_abc123def456.pdf
dissertation_20240120_ghi789jkl012.pdf
research_paper_20240125_mno345pqr678.pdf
```

**Why this naming?**
- Prevents filename conflicts
- Maintains original filename for display
- Timestamp for version tracking
- Random hash for security

### Revision Files

**Format:**
```
v{version_number}_{original_filename}_{timestamp}_{random_hash}.pdf
```

**Examples:**
```
v1_thesis_final_20240115_abc123def456.pdf
v2_thesis_final_20240120_ghi789jkl012.pdf
v3_thesis_final_20240125_mno345pqr678.pdf
```

---

## PART 3: DATABASE STORAGE

### PDF Submissions Table

**Table Name:** `pdf_submissions`

**Sample Data:**

```sql
SELECT * FROM pdf_submissions;

┌─────────────┬────────────┬────────────┬──────────────────────────────────────────┬──────────────────┬─────────────────┬──────────────────┐
│ submission_ │ student_id │ adviser_id │ file_path                                │ original_filename│ file_size       │ submission_status│
│ id          │            │            │                                          │                  │                 │                  │
├─────────────┼────────────┼────────────┼──────────────────────────────────────────┼──────────────────┼─────────────────┼──────────────────┤
│ 1           │ 101        │ 201        │ uploads/pdf_submissions/student_101/     │ thesis_final.pdf │ 2500000         │ reviewed         │
│             │            │            │ thesis_final_20240115_abc123.pdf         │                  │ (2.5 MB)        │                  │
├─────────────┼────────────┼────────────┼──────────────────────────────────────────┼──────────────────┼─────────────────┼──────────────────┤
│ 2           │ 102        │ 201        │ uploads/pdf_submissions/student_102/     │ dissertation.pdf │ 3200000         │ pending          │
│             │            │            │ dissertation_20240120_def456.pdf         │                  │ (3.2 MB)        │                  │
├─────────────┼────────────┼────────────┼──────────────────────────────────────────┼──────────────────┼─────────────────┼──────────────────┤
│ 3           │ 101        │ 201        │ uploads/pdf_revisions/submission_1/      │ thesis_final.pdf │ 2600000         │ reviewed         │
│             │            │            │ v2_thesis_final_20240120_ghi789.pdf      │                  │ (2.6 MB)        │                  │
└─────────────┴────────────┴────────────┴──────────────────────────────────────────┴──────────────────┴─────────────────┴──────────────────┘
```

### Key Fields:

| Field | Purpose | Example |
|-------|---------|---------|
| `submission_id` | Unique identifier | 1, 2, 3 |
| `student_id` | Student who uploaded | 101, 102 |
| `adviser_id` | Assigned adviser | 201, 202 |
| `file_path` | Physical file location | uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf |
| `original_filename` | Original filename | thesis_final.pdf |
| `file_size` | File size in bytes | 2500000 |
| `submission_status` | Current status | pending, reviewed, approved |
| `submission_timestamp` | Upload time | 2024-01-15 10:30:45 |
| `version_number` | Version number | 1, 2, 3 |

---

## PART 4: STEP-BY-STEP FILE FLOW

### When Student Uploads PDF

```
1. STUDENT UPLOADS
   └─ student_dashboard.php
      └─ Form: Select PDF + Select Adviser
         └─ [Upload PDF] button
            └─ POST to pdf_upload_handler.php

2. FILE PROCESSING
   └─ pdf_upload_handler.php
      ├─ Validate file (MIME type, size, magic bytes)
      ├─ Generate unique filename
      │  └─ thesis_final_20240115_abc123def456.pdf
      ├─ Create student folder if not exists
      │  └─ uploads/pdf_submissions/student_101/
      └─ Move file to folder
         └─ uploads/pdf_submissions/student_101/thesis_final_20240115_abc123def456.pdf

3. DATABASE RECORD
   └─ Insert into pdf_submissions table
      ├─ submission_id: 1
      ├─ student_id: 101
      ├─ adviser_id: 201
      ├─ file_path: uploads/pdf_submissions/student_101/thesis_final_20240115_abc123def456.pdf
      ├─ original_filename: thesis_final.pdf
      ├─ file_size: 2500000
      ├─ submission_status: pending
      └─ submission_timestamp: 2024-01-15 10:30:45

4. NOTIFICATION
   └─ Adviser receives notification
      └─ "John Castro submitted a new PDF for review"
         └─ Link to adviser_pdf_review.php?submission_id=1

5. ADVISER SEES IN DASHBOARD
   └─ adviser.php
      └─ PDF Reviews Section
         └─ Pending Review Table
            ├─ Student: John Castro
            ├─ File: thesis_final.pdf
            ├─ Submitted: Jan 15, 2024
            └─ [Review] button → adviser_pdf_review.php?submission_id=1
```

### When Adviser Reviews PDF

```
1. ADVISER CLICKS REVIEW
   └─ adviser_pdf_review.php?submission_id=1
      ├─ Fetch submission from database
      ├─ Get file_path: uploads/pdf_submissions/student_101/thesis_final_20240115_abc123def456.pdf
      └─ Load PDF in browser using PDF.js

2. ADVISER ADDS ANNOTATIONS
   └─ Click on PDF → Select annotation tool
      ├─ Comment, Highlight, or Suggestion
      └─ Type annotation content
         └─ [Save Annotation] button
            └─ POST to pdf_annotation_api.php

3. ANNOTATION SAVED
   └─ Insert into pdf_annotations table
      ├─ annotation_id: 1
      ├─ submission_id: 1
      ├─ adviser_id: 201
      ├─ annotation_type: comment
      ├─ annotation_content: "Please clarify this section"
      ├─ page_number: 3
      ├─ x_coordinate: 45.5
      ├─ y_coordinate: 62.3
      └─ creation_timestamp: 2024-01-15 11:00:00

4. STUDENT SEES FEEDBACK
   └─ student_dashboard.php
      └─ PDF Submissions Table
         ├─ Status changes to: Reviewed
         ├─ Annotations count: 1
         └─ [View] button → student_pdf_view.php?submission_id=1
            └─ PDF viewer shows annotations as overlays
```

### When Student Uploads Revision

```
1. STUDENT UPLOADS REVISION
   └─ student_pdf_view.php?submission_id=1
      └─ [Upload Revised PDF] button
         └─ Select new PDF file
            └─ POST to pdf_upload_handler.php?action=upload_revision

2. FILE PROCESSING
   └─ pdf_upload_handler.php
      ├─ Validate file
      ├─ Generate version number: v2
      ├─ Generate filename
      │  └─ v2_thesis_final_20240120_ghi789jkl012.pdf
      ├─ Create revision folder if not exists
      │  └─ uploads/pdf_revisions/submission_1/
      └─ Move file to folder
         └─ uploads/pdf_revisions/submission_1/v2_thesis_final_20240120_ghi789jkl012.pdf

3. DATABASE RECORD
   └─ Insert new row in pdf_submissions table
      ├─ submission_id: 2 (new)
      ├─ student_id: 101
      ├─ adviser_id: 201
      ├─ parent_submission_id: 1 (links to original)
      ├─ file_path: uploads/pdf_revisions/submission_1/v2_thesis_final_20240120_ghi789jkl012.pdf
      ├─ version_number: 2
      ├─ submission_status: pending
      └─ submission_timestamp: 2024-01-20 14:15:30

4. ADVISER SEES IN DASHBOARD
   └─ adviser.php
      └─ PDF Reviews Section
         └─ Pending Review Table
            ├─ Student: John Castro
            ├─ File: thesis_final.pdf (Version 2)
            ├─ Submitted: Jan 20, 2024
            └─ [Review] button → adviser_pdf_review.php?submission_id=2
```

---

## PART 5: DIRECTORY SETUP COMMANDS

### Create Directories

```bash
# Navigate to project root
cd c:/xampp/IAdS_Ni/htdocs/IAdS_Ni

# Create main uploads directory if not exists
mkdir uploads

# Create PDF submission directory
mkdir uploads/pdf_submissions

# Create PDF revisions directory
mkdir uploads/pdf_revisions

# Set proper permissions (Windows)
icacls uploads /grant:r "%USERNAME%":F /t

# Or for Linux/Mac
chmod 755 uploads
chmod 755 uploads/pdf_submissions
chmod 755 uploads/pdf_revisions
```

### Verify Directories

```bash
# List directory structure
dir uploads

# Should show:
# Directory of c:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads
# 
# 01/15/2024  10:30 AM    <DIR>          pdf_submissions
# 01/15/2024  10:30 AM    <DIR>          pdf_revisions
# 01/15/2024  10:30 AM    <DIR>          archive_uploads
```

---

## PART 6: FILE ACCESS FLOW

### How Files Are Accessed

```
1. STUDENT UPLOADS
   ├─ Browser: student_dashboard.php
   ├─ Form submits to: pdf_upload_handler.php
   ├─ File saved to: uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf
   └─ Path stored in DB: pdf_submissions.file_path

2. ADVISER REVIEWS
   ├─ Browser: adviser_pdf_review.php?submission_id=1
   ├─ PHP fetches from DB: SELECT file_path FROM pdf_submissions WHERE submission_id=1
   ├─ Gets path: uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf
   ├─ Passes to JavaScript: pdf_viewer.js
   └─ PDF.js loads file from: /uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf

3. STUDENT VIEWS FEEDBACK
   ├─ Browser: student_pdf_view.php?submission_id=1
   ├─ PHP fetches from DB: SELECT file_path FROM pdf_submissions WHERE submission_id=1
   ├─ Gets path: uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf
   ├─ Passes to JavaScript: pdf_viewer.js
   └─ PDF.js loads file from: /uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf
```

---

## PART 7: SECURITY CONSIDERATIONS

### File Permissions

```
uploads/                    755 (rwxr-xr-x)
uploads/pdf_submissions/    755 (rwxr-xr-x)
uploads/pdf_revisions/      755 (rwxr-xr-x)
*.pdf files                 644 (rw-r--r--)
```

### Access Control

- Only authenticated users can upload
- Only assigned adviser can review
- Only student who uploaded can view
- Files not directly accessible via URL (served through PHP)

### File Validation

- MIME type check: application/pdf
- Magic bytes check: %PDF
- File size limit: 50MB
- Filename sanitization

---

## PART 8: EXAMPLE FILE STRUCTURE

### After Multiple Uploads

```
uploads/
├── pdf_submissions/
│   ├── student_101/
│   │   ├── thesis_final_20240115_abc123def456.pdf (2.5 MB)
│   │   ├── dissertation_20240120_ghi789jkl012.pdf (3.2 MB)
│   │   └── research_paper_20240125_mno345pqr678.pdf (1.8 MB)
│   │
│   ├── student_102/
│   │   ├── proposal_20240110_stu901vwx234.pdf (1.5 MB)
│   │   └── thesis_20240118_yza567bcd890.pdf (2.8 MB)
│   │
│   └── student_103/
│       └── paper_20240122_efg123hij456.pdf (2.1 MB)
│
├── pdf_revisions/
│   ├── submission_1/
│   │   ├── v1_thesis_final_20240115_abc123def456.pdf (2.5 MB)
│   │   ├── v2_thesis_final_20240120_ghi789jkl012.pdf (2.6 MB)
│   │   └── v3_thesis_final_20240125_mno345pqr678.pdf (2.7 MB)
│   │
│   └── submission_2/
│       ├── v1_proposal_20240110_stu901vwx234.pdf (1.5 MB)
│       └── v2_proposal_20240118_yza567bcd890.pdf (1.6 MB)
│
└── archive_uploads/
    └── [existing files]

Total Storage: ~25 MB (example)
```

---

## PART 9: DATABASE QUERIES

### Find File Location

```sql
-- Find where a specific student's PDF is stored
SELECT 
    submission_id,
    student_id,
    file_path,
    original_filename,
    submission_status
FROM pdf_submissions
WHERE student_id = 101
ORDER BY submission_timestamp DESC;

-- Result:
-- submission_id: 1
-- file_path: uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf
```

### Find All Revisions

```sql
-- Find all versions of a submission
SELECT 
    submission_id,
    version_number,
    file_path,
    submission_timestamp
FROM pdf_submissions
WHERE parent_submission_id = 1
ORDER BY version_number ASC;

-- Result:
-- submission_id: 2, version: 2, file_path: uploads/pdf_revisions/submission_1/v2_thesis_final_20240120_ghi789.pdf
-- submission_id: 3, version: 3, file_path: uploads/pdf_revisions/submission_1/v3_thesis_final_20240125_mno345.pdf
```

---

## SUMMARY

| Item | Location |
|------|----------|
| **Student Submissions** | `uploads/pdf_submissions/student_{id}/` |
| **Revised Submissions** | `uploads/pdf_revisions/submission_{id}/` |
| **Database Records** | `advance_studies.pdf_submissions` |
| **Annotations** | `advance_studies.pdf_annotations` |
| **File Path Format** | `uploads/pdf_submissions/student_101/thesis_final_20240115_abc123.pdf` |
| **Revision Path Format** | `uploads/pdf_revisions/submission_1/v2_thesis_final_20240120_ghi789.pdf` |
| **Max File Size** | 50 MB |
| **Allowed Format** | PDF only |
