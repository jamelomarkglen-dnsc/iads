# Debugging Guide - PDF Annotation System

## Issue: Redirected Back to Dashboard When Opening adviser_pdf_review.php

### Possible Causes

1. **Submission doesn't exist in database**
   - No PDF submissions have been uploaded yet
   - The submission_id doesn't exist

2. **Adviser ID mismatch**
   - The submission is assigned to a different adviser
   - Your user ID doesn't match the adviser_id in the database

3. **Database tables not created**
   - The pdf_submissions table doesn't exist
   - The SQL schema hasn't been executed

---

## STEP 1: Check if Database Tables Exist

### Using phpMyAdmin

1. Open: `http://localhost/phpmyadmin`
2. Select database: `advance_studies`
3. Look for these tables:
   - ✅ `pdf_submissions`
   - ✅ `pdf_annotations`
   - ✅ `annotation_replies`
   - ✅ `submission_notifications`
   - ✅ `annotation_history`

**If tables don't exist:**
→ Execute the SQL schema file: `pdf_annotation_schema.sql`

### Using SQL Query

```sql
SHOW TABLES LIKE 'pdf_%';
```

Should return 5 tables.

---

## STEP 2: Check if Submissions Exist

### Using phpMyAdmin

1. Open: `http://localhost/phpmyadmin`
2. Select database: `advance_studies`
3. Click table: `pdf_submissions`
4. Click "Browse" tab
5. Check if there are any rows

**If no submissions exist:**
→ A student needs to upload a PDF first

### Using SQL Query

```sql
SELECT * FROM pdf_submissions;
```

Should return at least one row if submissions exist.

---

## STEP 3: Check Your User ID and Adviser Assignments

### Find Your User ID

```sql
SELECT id, firstname, lastname, role FROM users WHERE username = 'your_username';
```

**Example result:**
```
id: 201
firstname: John
lastname: Jamelo
role: adviser
```

### Check Submissions Assigned to You

```sql
SELECT 
    submission_id,
    student_id,
    adviser_id,
    original_filename,
    submission_status
FROM pdf_submissions
WHERE adviser_id = 201;  -- Replace 201 with your user ID
```

**If no results:**
→ No submissions are assigned to you yet

---

## STEP 4: Test with a Known Submission ID

### Get a Valid Submission ID

```sql
SELECT submission_id, adviser_id, student_id, original_filename 
FROM pdf_submissions 
LIMIT 1;
```

**Example result:**
```
submission_id: 1
adviser_id: 201
student_id: 101
original_filename: thesis.pdf
```

### Check if You're the Adviser

If your user ID (from Step 3) matches the `adviser_id`, you can access this submission.

### Test the URL

```
http://localhost/IAdS_Ni/adviser_pdf_review.php?submission_id=1
```

Replace `1` with the actual submission_id.

---

## STEP 5: Create Test Data

If no submissions exist, create one:

### Option A: Upload via Student Dashboard

1. Login as a student
2. Go to: `http://localhost/IAdS_Ni/student_dashboard.php`
3. Scroll to "PDF Submissions" section
4. Upload a test PDF
5. Select yourself as the adviser (if you're also an adviser)

### Option B: Insert Directly into Database

```sql
INSERT INTO pdf_submissions (
    student_id,
    adviser_id,
    file_path,
    original_filename,
    file_size,
    mime_type,
    submission_status,
    submission_timestamp,
    version_number
) VALUES (
    101,                                    -- student_id
    201,                                    -- adviser_id (your ID)
    'uploads/pdf_submissions/student_101/test.pdf',
    'test.pdf',
    1000000,
    'application/pdf',
    'pending',
    NOW(),
    1
);
```

Then test with the new submission_id.

---

## STEP 6: Check Error Messages

### Updated adviser_pdf_review.php

The file has been updated to show error messages instead of silently redirecting.

**Error messages you might see:**

1. **"Submission not found"**
   → The submission_id doesn't exist in the database
   → Check Step 2 and Step 4

2. **"You do not have permission"**
   → The submission is assigned to a different adviser
   → Check Step 3 and verify adviser_id matches your user_id

---

## STEP 7: Verify File Paths

### Check if PDF File Exists

```sql
SELECT submission_id, file_path FROM pdf_submissions LIMIT 1;
```

**Example result:**
```
file_path: uploads/pdf_submissions/student_101/thesis_20240115_abc123.pdf
```

### Verify File Exists on Server

Check if the file exists at:
```
C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads\pdf_submissions\student_101\thesis_20240115_abc123.pdf
```

**If file doesn't exist:**
→ The upload failed or the file was deleted
→ Upload a new PDF

---

## COMPLETE DEBUGGING CHECKLIST

- [ ] Database tables exist (5 tables)
- [ ] At least one submission exists in pdf_submissions table
- [ ] Your user ID matches the adviser_id of a submission
- [ ] The PDF file exists on the server
- [ ] You can access adviser_pdf_review.php?submission_id=X without error
- [ ] The PDF displays in the viewer
- [ ] Annotation tools are visible

---

## QUICK SQL QUERIES

### Check Everything

```sql
-- Check tables exist
SHOW TABLES LIKE 'pdf_%';

-- Check submissions exist
SELECT COUNT(*) as submission_count FROM pdf_submissions;

-- Check your user ID
SELECT id, username, role FROM users WHERE username = 'your_username';

-- Check submissions assigned to you
SELECT submission_id, original_filename, adviser_id 
FROM pdf_submissions 
WHERE adviser_id = YOUR_USER_ID;

-- Check file paths
SELECT submission_id, file_path FROM pdf_submissions LIMIT 5;
```

---

## TROUBLESHOOTING FLOWCHART

```
START: adviser_pdf_review.php?submission_id=X
│
├─ Error: "Submission not found"
│  └─ Check: Does submission_id exist in pdf_submissions table?
│     ├─ NO → Upload a PDF first
│     └─ YES → Check file_path exists
│
├─ Error: "You do not have permission"
│  └─ Check: Does adviser_id match your user_id?
│     ├─ NO → Ask the adviser who owns this submission to review it
│     └─ YES → Check database for data corruption
│
├─ No error, but PDF doesn't load
│  └─ Check: Does file exist at file_path?
│     ├─ NO → Re-upload the PDF
│     └─ YES → Check PDF.js CDN is accessible
│
└─ Success! ✅
   └─ You can now use annotation tools
```

---

## COMMON ISSUES & SOLUTIONS

### Issue 1: "Table doesn't exist"
**Solution:** Execute SQL schema file
```bash
mysql -u root -p advance_studies < pdf_annotation_schema.sql
```

### Issue 2: "No submissions to review"
**Solution:** Upload a PDF as a student first
1. Login as student
2. Go to student_dashboard.php
3. Upload a PDF
4. Select yourself as adviser

### Issue 3: "Permission denied"
**Solution:** Check adviser_id matches your user_id
```sql
SELECT id FROM users WHERE username = 'your_username';
-- Use this ID to find submissions assigned to you
SELECT * FROM pdf_submissions WHERE adviser_id = YOUR_ID;
```

### Issue 4: "PDF doesn't display"
**Solution:** Check file exists
```bash
# Check if file exists
dir C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads\pdf_submissions\
```

---

## NEXT STEPS

Once you've verified everything:

1. ✅ Database tables exist
2. ✅ Submissions exist
3. ✅ You're assigned as adviser
4. ✅ PDF file exists
5. ✅ adviser_pdf_review.php loads without error
6. ✅ PDF displays in viewer
7. ✅ Annotation tools are visible

Then you can:
- Click annotation tools
- Add comments, highlights, suggestions
- Save annotations
- Student sees feedback

---

## SUPPORT

If you're still having issues:

1. Check browser console (F12)
2. Check server error logs
3. Run the SQL queries above
4. Verify file permissions
5. Check database connection

All error messages should now be displayed instead of silent redirects.
