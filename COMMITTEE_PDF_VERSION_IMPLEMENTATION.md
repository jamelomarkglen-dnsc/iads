# Committee PDF Per-Entry Reupload Feature Implementation

## Overview
This document describes the implementation of per-entry version tracking and reupload functionality for committee PDF submissions, matching the behavior of the regular PDF submission system in `student_dashboard.php`.

---

## Changes Made

### 1. Database Migration
**File:** [`committee_pdf_version_migration.sql`](committee_pdf_version_migration.sql)

Added `parent_submission_id` column to track version chains:
```sql
ALTER TABLE committee_pdf_submissions 
ADD COLUMN parent_submission_id INT NULL AFTER version_number,
ADD INDEX idx_parent_submission (parent_submission_id);
```

**⚠️ IMPORTANT:** Run this SQL migration in phpMyAdmin/MySQL before using the feature.

---

### 2. Helper Functions
**File:** [`committee_pdf_submission_helpers.php`](committee_pdf_submission_helpers.php)

#### Added Functions:

1. **`create_committee_revision_submission()`** (Lines 479-530)
   - Creates a new version linked to a parent submission
   - Auto-increments version number
   - Verifies student ownership
   - Automatically creates review assignments for the new version
   - Returns: `['success' => true, 'submission_id' => int, 'version' => int]`

2. **`get_committee_version_chain_info()`** (Lines 532-612)
   - Retrieves version chain metadata
   - Finds previous, next, and latest versions
   - Returns navigation information for version browsing
   - Returns: Array with version chain details

3. **`get_committee_latest_version_id()`** (Lines 614-620)
   - Helper to quickly get the latest version ID in a chain
   - Returns: Latest submission ID

#### Modified Functions:

4. **`fetch_committee_pdf_submissions_for_student()`** (Lines 358-391)
   - Added `$latest_only` parameter (default: `true`)
   - When `true`: Filters to show only latest versions (no superseded versions)
   - When `false`: Shows all versions
   - Uses SQL subquery to exclude submissions that have child versions

---

### 3. Student View Page
**File:** [`student_committee_pdf_view.php`](student_committee_pdf_view.php)

#### Added Features:

1. **Version Badge Display** (Lines 107-113)
   - Shows current version number
   - Displays submission status badge

2. **Success Message with Version** (Lines 115-127)
   - Shows upload success alert
   - Displays version number in badge

3. **Upload New Version Form** (Lines 129-159)
   - Card with upload form for revisions
   - Hidden fields: `action=upload_revision`, `parent_submission_id`
   - File input with PDF validation
   - Button shows next version number (e.g., "Upload Version 2")
   - Submits to `committee_pdf_upload_handler.php`

---

### 4. Upload Handler
**File:** [`committee_pdf_upload_handler.php`](committee_pdf_upload_handler.php)

#### Modified Logic:

1. **Action Validation** (Lines 24-28)
   - Now accepts both `'upload'` and `'upload_revision'` actions

2. **Revision Upload Branch** (Lines 54-73)
   - Validates `parent_submission_id` parameter
   - Calls `create_committee_revision_submission()`
   - Sets version-specific success message
   - Redirects back to the submission view page

3. **New Upload Branch** (Lines 75-95)
   - Original logic for brand new submissions
   - Redirects to submission list page

4. **Notification System** (Lines 97-120)
   - Sends different notification messages for revisions vs new uploads
   - Notifies all committee reviewers
   - Includes version number in revision notifications

---

## How It Works

### User Flow

#### Uploading a New Submission:
1. Student goes to [`student_committee_pdf_submission.php`](student_committee_pdf_submission.php)
2. Uses "Upload New Committee PDF" form
3. Submits with `action=upload`
4. Creates new submission with `version_number=1`, `parent_submission_id=NULL`
5. Shows in list as a new entry

#### Uploading a New Version:
1. Student clicks "View" on an existing submission
2. Goes to [`student_committee_pdf_view.php?submission_id=X`](student_committee_pdf_view.php)
3. Uses "Upload New Version" form
4. Submits with `action=upload_revision` and `parent_submission_id=X`
5. Creates new submission with `version_number=2`, `parent_submission_id=X`
6. Old version (v1) is hidden from list
7. New version (v2) appears in list

### Database Structure

```
Submission Chain Example:

submission_id | version_number | parent_submission_id | visible_in_list
-------------|----------------|---------------------|----------------
     100     |       1        |        NULL         |      NO (has child)
     105     |       2        |        100          |      YES (latest)
```

The SQL query filters out submissions where a child exists:
```sql
WHERE NOT EXISTS (
    SELECT 1 FROM committee_pdf_submissions child
    WHERE child.parent_submission_id = committee_pdf_submissions.id
)
```

---

## Testing Checklist

### Before Testing:
- [ ] Run [`committee_pdf_version_migration.sql`](committee_pdf_version_migration.sql) in database
- [ ] Ensure defense committee is assigned to test student
- [ ] Ensure committee reviewers exist in defense_panels table

### Test Scenarios:

1. **New Upload:**
   - [ ] Upload first PDF from [`student_committee_pdf_submission.php`](student_committee_pdf_submission.php)
   - [ ] Verify it shows as v1 in list
   - [ ] Verify reviewers receive notification

2. **First Revision:**
   - [ ] Click "View" on the v1 submission
   - [ ] Upload new version using "Upload New Version" form
   - [ ] Verify success message shows "Version 2"
   - [ ] Verify list now shows only v2 (v1 hidden)
   - [ ] Verify reviewers receive "new version" notification

3. **Multiple Revisions:**
   - [ ] Upload v3, v4, etc.
   - [ ] Verify only latest version shows in list
   - [ ] Verify version numbers increment correctly

4. **Annotations:**
   - [ ] Add annotations to v1
   - [ ] Upload v2
   - [ ] Verify v1 annotations remain on v1
   - [ ] Verify v2 starts with no annotations

5. **Version Navigation:**
   - [ ] Test `get_committee_version_chain_info()` function
   - [ ] Verify it returns correct previous/next/latest IDs

---

## Key Differences from Regular PDF System

| Feature | Regular PDF System | Committee PDF System |
|---------|-------------------|---------------------|
| **Table** | `pdf_submissions` | `committee_pdf_submissions` |
| **Helper prefix** | `create_revision_submission()` | `create_committee_revision_submission()` |
| **Upload handler** | `pdf_upload_handler.php` | `committee_pdf_upload_handler.php` |
| **View page** | `student_pdf_view.php` | `student_committee_pdf_view.php` |
| **List page** | `student_dashboard.php` | `student_committee_pdf_submission.php` |
| **Reviewer assignment** | Single adviser | Multiple committee members |
| **Defense linking** | Optional | Required (`defense_id`) |

---

## Files Modified

1. ✅ [`committee_pdf_version_migration.sql`](committee_pdf_version_migration.sql) - **NEW**
2. ✅ [`committee_pdf_submission_helpers.php`](committee_pdf_submission_helpers.php) - Modified
3. ✅ [`student_committee_pdf_view.php`](student_committee_pdf_view.php) - Modified
4. ✅ [`committee_pdf_upload_handler.php`](committee_pdf_upload_handler.php) - Modified
5. ✅ [`COMMITTEE_PDF_VERSION_IMPLEMENTATION.md`](COMMITTEE_PDF_VERSION_IMPLEMENTATION.md) - **NEW** (this file)

---

## Troubleshooting

### Issue: "Parent submission not found" error
**Solution:** Ensure the `parent_submission_id` exists and belongs to the student

### Issue: List shows all versions instead of latest only
**Solution:** Verify the SQL migration added `parent_submission_id` column correctly

### Issue: Reviewers not notified
**Solution:** Check that `defense_panels` table has committee members assigned

### Issue: Version number doesn't increment
**Solution:** Verify `create_committee_revision_submission()` is being called (not `create_committee_pdf_submission()`)

---

## Future Enhancements

Potential improvements for future development:

1. **Version History View:** Add a page to view all versions of a submission
2. **Version Comparison:** Side-by-side comparison of two versions
3. **Annotation Migration:** Option to copy annotations from previous version
4. **Version Rollback:** Allow reverting to a previous version
5. **Version Comments:** Add notes explaining what changed in each version

---

## Support

For questions or issues with this implementation, refer to:
- Regular PDF system: [`pdf_submission_helpers.php`](pdf_submission_helpers.php)
- Student dashboard: [`student_dashboard.php`](student_dashboard.php) (lines 514-976)
- PDF submission guide: `PDF_SUBMISSION_DISPLAY_GUIDE.md`

---

**Implementation Date:** January 21, 2026  
**Status:** ✅ Complete - Ready for testing after SQL migration
