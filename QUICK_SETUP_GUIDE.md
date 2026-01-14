# Quick Setup Guide - PDF Annotation System

## Error: Table 'advance_studies.pdf_submissions' doesn't exist

This error means the database tables haven't been created yet. Follow these steps to set up the system:

---

## STEP 1: Create Database Tables

### Option A: Using phpMyAdmin (Easiest)

1. Open phpMyAdmin
   - URL: `http://localhost/phpmyadmin`
   - Login with your credentials

2. Select database: `advance_studies`

3. Click "SQL" tab at the top

4. Open file: `pdf_annotation_schema.sql`
   - Location: `c:/xampp/IAdS_Ni/htdocs/IAdS_Ni/pdf_annotation_schema.sql`
   - Copy all the SQL code

5. Paste into phpMyAdmin SQL editor

6. Click "Go" or "Execute"

7. You should see: "5 queries executed successfully"

### Option B: Using Command Line

```bash
# Open Command Prompt
# Navigate to MySQL bin directory
cd C:\xampp\mysql\bin

# Execute the SQL file
mysql -u root -p advance_studies < C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\pdf_annotation_schema.sql

# When prompted for password, press Enter (if no password set)
```

### Option C: Using MySQL Workbench

1. Open MySQL Workbench
2. Connect to your MySQL server
3. Open file: `pdf_annotation_schema.sql`
4. Click "Execute" or press Ctrl+Shift+Enter
5. Verify tables are created

---

## STEP 2: Create Upload Directories

### Using Command Prompt

```bash
# Navigate to project directory
cd C:\xampp\IAdS_Ni\htdocs\IAdS_Ni

# Create directories
mkdir uploads\pdf_submissions
mkdir uploads\pdf_revisions

# Verify directories were created
dir uploads
```

### Using File Explorer

1. Open File Explorer
2. Navigate to: `C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads`
3. Create new folder: `pdf_submissions`
4. Create new folder: `pdf_revisions`

---

## STEP 3: Verify Database Tables

### Check in phpMyAdmin

1. Open phpMyAdmin
2. Select database: `advance_studies`
3. You should see these tables:
   - ✅ `pdf_submissions`
   - ✅ `pdf_annotations`
   - ✅ `annotation_replies`
   - ✅ `submission_notifications`
   - ✅ `annotation_history`

### Check with SQL Query

```sql
-- Run this query in phpMyAdmin
SHOW TABLES LIKE 'pdf_%';

-- Should return:
-- pdf_submissions
-- pdf_annotations
-- annotation_replies
-- submission_notifications
-- annotation_history
```

---

## STEP 4: Test the System

### Test Adviser PDF Review Page

1. Login as Adviser
   - URL: `http://localhost/IAdS_Ni/login.php`
   - Username: adviser_username
   - Password: adviser_password

2. Go to Adviser Dashboard
   - URL: `http://localhost/IAdS_Ni/adviser.php`

3. Scroll to "PDF Reviews" section
   - Should show: "No pending reviews" (if no submissions yet)

4. If you see the section without errors, setup is successful! ✅

### Test Student PDF Upload

1. Login as Student
   - URL: `http://localhost/IAdS_Ni/login.php`
   - Username: student_username
   - Password: student_password

2. Go to Student Dashboard
   - URL: `http://localhost/IAdS_Ni/student_dashboard.php`

3. Scroll to "PDF Submissions" section
   - Should show upload form

4. Try uploading a test PDF
   - Select a PDF file
   - Select an adviser
   - Click "Upload PDF"

5. If upload succeeds, system is working! ✅

---

## STEP 5: Verify All Files Are in Place

Check that these files exist in: `C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\`

### Backend Files
- ✅ `pdf_annotation_schema.sql`
- ✅ `pdf_submission_helpers.php`
- ✅ `pdf_annotation_helpers.php`
- ✅ `pdf_upload_handler.php`
- ✅ `pdf_annotation_api.php`

### Frontend Files
- ✅ `pdf_annotation_styles.css`
- ✅ `pdf_viewer.js`
- ✅ `annotation_manager.js`

### Page Files
- ✅ `adviser_pdf_review.php`
- ✅ `student_pdf_view.php`

### Documentation Files
- ✅ `PDF_ANNOTATION_IMPLEMENTATION_GUIDE.md`
- ✅ `PDF_SUBMISSION_DISPLAY_GUIDE.md`
- ✅ `PDF_FILE_STORAGE_GUIDE.md`
- ✅ `ANNOTATION_TOOLS_LOCATION_GUIDE.md`
- ✅ `QUICK_SETUP_GUIDE.md`

---

## TROUBLESHOOTING

### Error: "Table doesn't exist"

**Solution:** Execute the SQL schema file (Step 1)

```bash
# Quick command
mysql -u root advance_studies < pdf_annotation_schema.sql
```

### Error: "Permission denied" for uploads folder

**Solution:** Set folder permissions

```bash
# Windows - Run as Administrator
icacls C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads /grant:r "%USERNAME%":F /t

# Linux/Mac
chmod 755 uploads/pdf_submissions
chmod 755 uploads/pdf_revisions
```

### Error: "PDF file not found"

**Solution:** Check file path in database

```sql
-- Check what path is stored
SELECT submission_id, file_path FROM pdf_submissions LIMIT 1;

-- Verify file exists at that location
-- Example: C:\xampp\IAdS_Ni\htdocs\IAdS_Ni\uploads\pdf_submissions\student_101\thesis.pdf
```

### Error: "PDF.js worker not found"

**Solution:** Check PDF.js CDN is accessible

```html
<!-- In adviser_pdf_review.php and student_pdf_view.php -->
<!-- Should have this line: -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
```

---

## COMPLETE SETUP CHECKLIST

- [ ] Database tables created (5 tables)
- [ ] Upload directories created (2 folders)
- [ ] All 14 files in place
- [ ] Adviser can access adviser_pdf_review.php
- [ ] Student can access student_pdf_view.php
- [ ] Student can upload PDF
- [ ] Adviser can see pending submissions
- [ ] Adviser can see annotation tools
- [ ] PDF displays in browser
- [ ] Annotations can be created
- [ ] Annotations display in comment panel

---

## NEXT STEPS

Once setup is complete:

1. **Test Student Upload**
   - Student uploads PDF
   - Adviser receives notification

2. **Test Adviser Review**
   - Adviser opens PDF
   - Adviser adds annotations
   - Student sees feedback

3. **Test Student Response**
   - Student views feedback
   - Student replies to annotations
   - Student uploads revision

4. **Monitor for Errors**
   - Check browser console (F12)
   - Check server error logs
   - Check database for records

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

---

## QUICK COMMANDS

### Create Database Tables
```bash
mysql -u root -p advance_studies < pdf_annotation_schema.sql
```

### Create Upload Directories
```bash
mkdir uploads\pdf_submissions
mkdir uploads\pdf_revisions
```

### Check Database Tables
```bash
mysql -u root -p advance_studies -e "SHOW TABLES LIKE 'pdf_%';"
```

### Test Database Connection
```bash
mysql -u root -p advance_studies -e "SELECT 1;"
```

---

## SUMMARY

| Step | Action | Status |
|------|--------|--------|
| 1 | Execute SQL schema | ⏳ TODO |
| 2 | Create upload directories | ⏳ TODO |
| 3 | Verify database tables | ⏳ TODO |
| 4 | Test adviser page | ⏳ TODO |
| 5 | Test student page | ⏳ TODO |
| 6 | Test PDF upload | ⏳ TODO |
| 7 | Test annotation tools | ⏳ TODO |

Once all steps are complete, the system is ready to use! ✅
