# PDF Annotation System - Complete Testing Guide

## Overview
This guide provides step-by-step instructions to test the complete PDF Annotation System workflow, from student upload to adviser review to student feedback.

---

## PHASE 1: SETUP & PREREQUISITES

### Step 1.1 - Create Database Tables

**Using phpMyAdmin (Recommended):**
1. Open `http://localhost/phpmyadmin`
2. Select database: `advance_studies`
3. Click "SQL" tab
4. Open file: `pdf_annotation_schema.sql`
5. Copy all SQL code
6. Paste into phpMyAdmin SQL editor
7. Click "Go" or "Execute"
8. Verify: "5 queries executed successfully"

**Using Command Line:**
```bash
cd C:\xampp\mysql\bin
mysql -u root -p advance_studies < C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\pdf_annotation_schema.sql
# Press Enter when prompted for password (if no password set)
```

### Step 1.2 - Create Upload Directories

**Using Command Prompt:**
```bash
cd C:\xampp\IAdS_Ni\htdocs\IAdS_Ni
mkdir uploads\pdf_submissions
mkdir uploads\pdf_revisions
```

**Using File Explorer:**
1. Navigate to: `C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads`
2. Create new folder: `pdf_submissions`
3. Create new folder: `pdf_revisions`

### Step 1.3 - Verify Database Tables

**In phpMyAdmin:**
1. Select database: `advance_studies`
2. Look for these 5 tables:
   - ✅ `pdf_submissions`
   - ✅ `pdf_annotations`
   - ✅ `annotation_replies`
   - ✅ `submission_notifications`
   - ✅ `annotation_history`

**Using SQL Query:**
```sql
SHOW TABLES LIKE 'pdf_%';
```

Should return 5 tables.

---

## PHASE 2: STUDENT PDF UPLOAD TEST

### Step 2.1 - Login as Student

1. Open: `http://localhost/IAdS_Ni/login.php`
2. Enter student credentials
3. Click "Login"

### Step 2.2 - Navigate to Student Dashboard

1. URL: `http://localhost/IAdS_Ni/student_dashboard.php`
2. Scroll down to find "PDF Submissions" section
3. You should see:
   - Upload form with file input
   - Adviser dropdown
   - "Upload PDF" button

### Step 2.3 - Upload Test PDF

1. Click "Select PDF File" button
2. Choose a test PDF file from your computer
3. Select an adviser from the dropdown
4. Click "Upload PDF" button
5. Wait for success message

**Expected Result:**
- File uploaded successfully
- Submission appears in "Your Submissions" table
- Status shows "pending"
- Version shows "v1"

### Step 2.4 - Verify in Database

**In phpMyAdmin:**
```sql
SELECT submission_id, student_id, adviser_id, original_filename, submission_status 
FROM pdf_submissions 
ORDER BY submission_timestamp DESC 
LIMIT 1;
```

**Expected Result:**
- Shows your uploaded file
- Status: "pending"
- File path stored correctly

---

## PHASE 3: ADVISER PDF REVIEW TEST

### Step 3.1 - Login as Adviser

1. Open: `http://localhost/IAdS_Ni/login.php`
2. Enter adviser credentials (must be the adviser assigned to the submission)
3. Click "Login"

### Step 3.2 - Access Adviser Dashboard

1. URL: `http://localhost/IAdS_Ni/adviser.php`
2. Look for "PDF Reviews" section
3. Should see the pending submission you just uploaded

### Step 3.3 - Open PDF Review Page

1. Click on the submission in the list
2. Should redirect to: `adviser_pdf_review.php?submission_id=X`
3. Verify:
   - PDF loads in the viewer
   - Annotation tools visible in toolbar
   - Comment, Highlight, Suggestion buttons available

### Step 3.4 - Test Comment Tool

1. Click "Comment" button in toolbar
2. Click on PDF to place comment marker
3. Enter comment text in the popup
4. Click "Save Comment"
5. Verify:
   - Comment appears on PDF
   - Comment appears in right panel
   - Timestamp shows

### Step 3.5 - Test Highlight Tool

1. Click "Highlight" button
2. Select text on PDF by clicking and dragging
3. Choose highlight color from palette
4. Click "Save Highlight"
5. Verify:
   - Text is highlighted on PDF
   - Highlight appears in right panel
   - Color is correct

### Step 3.6 - Test Suggestion Tool

1. Click "Suggestion" button
2. Click on PDF location where you want to add suggestion
3. Enter suggestion text
4. Click "Save Suggestion"
5. Verify:
   - Suggestion appears in right panel
   - Suggestion is linked to location on PDF

### Step 3.7 - Verify Annotations in Database

**In phpMyAdmin:**
```sql
SELECT annotation_id, annotation_type, annotation_text, created_by 
FROM pdf_annotations 
WHERE submission_id = X;  -- Replace X with submission_id
```

**Expected Result:**
- Shows all comments, highlights, suggestions
- Created_by shows adviser ID
- Timestamps are correct

---

## PHASE 4: STUDENT FEEDBACK VIEW TEST

### Step 4.1 - Login as Student

1. Open: `http://localhost/IAdS_Ni/login.php`
2. Use the same student account from Phase 2
3. Click "Login"

### Step 4.2 - Navigate to Student Dashboard

1. URL: `http://localhost/IAdS_Ni/student_dashboard.php`
2. Scroll to "PDF Submissions" section
3. Find the submission you uploaded

### Step 4.3 - View Feedback

1. Click "View" button on the submission
2. Should redirect to: `student_pdf_view.php?submission_id=X`
3. Verify:
   - PDF loads in viewer
   - All adviser annotations appear in right panel
   - Comments, highlights, suggestions all visible
   - Adviser name shown for each annotation

### Step 4.4 - Test Reply to Annotation

1. Find an annotation in the right panel
2. Click "Reply" button
3. Enter reply text
4. Click "Send Reply"
5. Verify:
   - Reply appears under annotation
   - Reply shows student name
   - Timestamp is correct

### Step 4.5 - Verify Replies in Database

**In phpMyAdmin:**
```sql
SELECT reply_id, annotation_id, reply_text, created_by 
FROM annotation_replies 
WHERE annotation_id IN (
    SELECT annotation_id FROM pdf_annotations WHERE submission_id = X
);
```

**Expected Result:**
- Shows all replies
- Created_by shows student ID
- Timestamps are correct

---

## PHASE 5: REVISION UPLOAD TEST

### Step 5.1 - Upload Revised PDF

1. Stay on student_dashboard.php
2. Scroll to "PDF Submissions" section
3. Upload a new version of the PDF
4. Select same adviser
5. Click "Upload PDF"

### Step 5.2 - Verify Version Number

**In phpMyAdmin:**
```sql
SELECT submission_id, version_number, original_filename, submission_timestamp 
FROM pdf_submissions 
WHERE student_id = YOUR_STUDENT_ID 
ORDER BY submission_timestamp DESC;
```

**Expected Result:**
- First upload: version_number = 1
- Second upload: version_number = 2
- Timestamps show correct order

### Step 5.3 - Adviser Reviews Revision

1. Login as adviser
2. Go to adviser.php
3. Should see new version in "PDF Reviews"
4. Open and add new annotations
5. Verify annotations are linked to version 2

---

## PHASE 6: NOTIFICATION TEST

### Step 6.1 - Check Notifications

**In phpMyAdmin:**
```sql
SELECT notification_id, submission_id, notification_type, is_read 
FROM submission_notifications 
WHERE recipient_id = YOUR_USER_ID;
```

**Expected Notifications:**
- PDF uploaded (to adviser)
- Annotation added (to student)
- Reply received (to adviser)

---

## PHASE 7: AUDIT TRAIL TEST

### Step 7.1 - Check History

**In phpMyAdmin:**
```sql
SELECT history_id, submission_id, action_type, action_details, timestamp 
FROM annotation_history 
WHERE submission_id = X 
ORDER BY timestamp DESC;
```

**Expected Actions:**
- submission_uploaded
- annotation_created (for each annotation)
- reply_added (for each reply)

---

## PHASE 8: ERROR HANDLING TEST

### Test 1: Invalid Submission ID

1. URL: `http://localhost/IAdS_Ni/adviser_pdf_review.php?submission_id=99999`
2. Expected: Error message "Submission not found"

### Test 2: Permission Denied

1. Login as different adviser
2. Try to access submission assigned to another adviser
3. Expected: Error message "You do not have permission"

### Test 3: Missing PDF File

1. Delete the PDF file from `uploads/pdf_submissions/`
2. Try to view submission
3. Expected: Error message "PDF file not found"

---

## COMPLETE TESTING CHECKLIST

### Setup Phase
- [ ] Database tables created (5 tables)
- [ ] Upload directories created (2 folders)
- [ ] All files in place

### Student Upload Phase
- [ ] Student can access student_dashboard.php
- [ ] PDF Submissions section visible
- [ ] Student can upload PDF
- [ ] File saved to uploads/pdf_submissions/
- [ ] Database entry created with correct status
- [ ] Version number is 1

### Adviser Review Phase
- [ ] Adviser can access adviser.php
- [ ] PDF Reviews section shows pending submissions
- [ ] Adviser can open adviser_pdf_review.php
- [ ] PDF displays in viewer
- [ ] Comment tool works (add, save, display)
- [ ] Highlight tool works (select, color, save, display)
- [ ] Suggestion tool works (add, save, display)
- [ ] Annotations saved to database
- [ ] Adviser name shown for annotations

### Student Feedback Phase
- [ ] Student can access student_pdf_view.php
- [ ] PDF displays in viewer
- [ ] All adviser annotations visible
- [ ] Student can reply to annotations
- [ ] Replies saved to database
- [ ] Replies display correctly

### Revision Phase
- [ ] Student can upload revised PDF
- [ ] Version number increments to 2
- [ ] Adviser can review revision
- [ ] New annotations linked to version 2

### Notifications Phase
- [ ] Notifications created for PDF upload
- [ ] Notifications created for annotations
- [ ] Notifications created for replies
- [ ] Notifications marked as read

### Audit Trail Phase
- [ ] History records created
- [ ] All actions logged
- [ ] Timestamps correct

### Error Handling Phase
- [ ] Invalid submission shows error
- [ ] Permission denied shows error
- [ ] Missing file shows error

---

## QUICK REFERENCE URLS

| Action | URL |
|--------|-----|
| Student Login | `http://localhost/IAdS_Ni/login.php` |
| Student Dashboard | `http://localhost/IAdS_Ni/student_dashboard.php` |
| Adviser Login | `http://localhost/IAdS_Ni/login.php` |
| Adviser Dashboard | `http://localhost/IAdS_Ni/adviser.php` |
| PDF Review | `http://localhost/IAdS_Ni/adviser_pdf_review.php?submission_id=X` |
| Student Feedback | `http://localhost/IAdS_Ni/student_pdf_view.php?submission_id=X` |
| phpMyAdmin | `http://localhost/phpmyadmin` |

---

## TROUBLESHOOTING

### Issue: "Table doesn't exist"
**Solution:** Execute SQL schema file
```bash
mysql -u root -p advance_studies < pdf_annotation_schema.sql
```

### Issue: "No submissions to review"
**Solution:** Upload a PDF as a student first
1. Login as student
2. Go to student_dashboard.php
3. Upload a PDF
4. Select yourself as adviser

### Issue: "Permission denied"
**Solution:** Check adviser_id matches your user_id
```sql
SELECT id FROM users WHERE username = 'your_username';
-- Use this ID to find submissions assigned to you
SELECT * FROM pdf_submissions WHERE adviser_id = YOUR_ID;
```

### Issue: "PDF doesn't display"
**Solution:** Check file exists
```bash
dir C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads\pdf_submissions\
```

### Issue: "PDF.js worker not found"
**Solution:** Check CDN is accessible
- Verify internet connection
- Check PDF.js CDN: https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js

---

## SUPPORT

If you encounter issues:

1. Check error message carefully
2. Verify database tables exist
3. Verify upload directories exist
4. Verify all files are in place
5. Check file permissions
6. Review browser console (F12)
7. Check server error logs
8. Refer to [`DEBUGGING_GUIDE.md`](DEBUGGING_GUIDE.md)

---

## SUMMARY

| Phase | Status | Notes |
|-------|--------|-------|
| Setup | ⏳ TODO | Create tables and directories |
| Student Upload | ⏳ TODO | Upload PDF from dashboard |
| Adviser Review | ⏳ TODO | Add annotations (comment, highlight, suggestion) |
| Student Feedback | ⏳ TODO | View feedback and reply |
| Revision | ⏳ TODO | Upload revised PDF |
| Notifications | ⏳ TODO | Verify notifications created |
| Audit Trail | ⏳ TODO | Verify history logged |
| Error Handling | ⏳ TODO | Test error scenarios |

Once all phases are complete, the system is ready for production use! ✅
