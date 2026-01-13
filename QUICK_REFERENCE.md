# Outline Defense Feature - Quick Reference Guide

## 🚀 Quick Start

### Test the Implementation
```
Visit: http://your-domain/test_outline_defense.php
Expected: All 38 tests pass ✓
```

### Student Submission
```
1. Go to: submit_final_paper.php
2. Upload manuscript PDF
3. Submit for review
4. View feedback and verdict
```

### Reviewer Review
```
1. Receive notification
2. Go to: outline_defense_review.php?submission_id={id}
3. Download manuscript
4. Submit review with status and comments
```

### Chairperson Decision
```
1. Go to: outline_defense_decision.php?submission_id={id}
2. Review all committee feedback
3. Select final status and verdict
4. Submit decision
```

---

## 📊 Database Schema Quick Reference

### Key Tables
| Table | Purpose |
|-------|---------|
| `final_paper_submissions` | Stores outline defense manuscripts |
| `final_paper_reviews` | Stores individual reviewer feedback |
| `notifications` | Stores system notifications |

### Key Columns
| Column | Table | Purpose |
|--------|-------|---------|
| `outline_defense_verdict` | final_paper_submissions | Final verdict (Passed/Failed) |
| `outline_defense_verdict_at` | final_paper_submissions | When verdict was issued |
| `final_decision_by` | final_paper_submissions | Chairperson ID |
| `final_decision_notes` | final_paper_submissions | Decision details |
| `reviewer_role` | final_paper_reviews | Role of reviewer |

---

## 🔐 Role Access Matrix

| Page | Student | Adviser | Chair | Panel |
|------|---------|---------|-------|-------|
| submit_final_paper.php | ✓ | ✗ | ✗ | ✗ |
| outline_defense_review.php | ✗ | ✓ | ✓ | ✓ |
| outline_defense_decision.php | ✗ | ✗ | ✓ | ✗ |

---

## 📧 Notification Flow

```
Student Submits
    ↓
Reviewers Notified → outline_defense_review.php
    ↓
Reviewer Completes
    ↓
Student Notified → submit_final_paper.php
    ↓
Chairperson Decides
    ↓
Student Notified → submit_final_paper.php (with verdict)
```

---

## 🛠️ Key Functions

### Helper Functions
```php
// Verdict Management
setOutlineDefenseVerdict($conn, $submissionId, $verdict)
getOutlineDefenseVerdict($conn, $submissionId)

// Display
outlineDefenseVerdictClass($verdict)
outlineDefenseVerdictLabel($verdict)
finalPaperStatusClass($status)
finalPaperStatusLabel($status)

// Fetching
fetchFinalPaperSubmission($conn, $submissionId)
fetchFinalPaperReviews($conn, $submissionId)
fetchFinalPaperReviewForUser($conn, $submissionId, $reviewerId)
```

### Notification Functions
```php
notify_outline_defense_submission($conn, $studentId, $submissionId, $studentName, $reviewerIds)
notify_outline_defense_review_completed($conn, $studentId, $reviewerName, $reviewerRole, $reviewStatus)
notify_outline_defense_decision($conn, $studentId, $chairName, $verdict, $decisionNotes)
notify_outline_defense_route_slip_submitted($conn, $studentId, $submissionId, $studentName, $reviewerIds, $hasRevision)
```

---

## 📁 File Structure

```
Root Directory
├── final_paper_helpers.php          (Updated with verdict functions)
├── outline_defense_review.php       (NEW - Reviewer interface)
├── outline_defense_decision.php     (NEW - Chairperson interface)
├── submit_final_paper.php           (Updated with verdict display)
├── notifications_helper.php         (Updated with notification templates)
├── test_outline_defense.php         (NEW - Test suite)
├── OUTLINE_DEFENSE_FEATURE.md       (NEW - Full documentation)
├── IMPLEMENTATION_SUMMARY.md        (NEW - Implementation details)
└── QUICK_REFERENCE.md              (NEW - This file)

uploads/
├── outline_defense/                 (Manuscript uploads)
└── route_slips/                     (Route slip uploads)
```

---

## ✅ Status & Verdict Values

### Submission Status
- `Submitted` - Initial submission
- `Under Review` - Being reviewed
- `Needs Revision` - Requires changes
- `Minor Revision` - Minor changes needed
- `Major Revision` - Major changes needed
- `Approved` - Approved
- `Rejected` - Rejected

### Review Status
- `Pending` - Not yet reviewed
- `Approved` - Approved
- `Rejected` - Rejected
- `Needs Revision` - Needs revision
- `Minor Revision` - Minor revision needed
- `Major Revision` - Major revision needed

### Outline Defense Verdict
- `Passed` - Passed outline defense
- `Passed with Revision` - Passed but needs revision
- `Failed` - Failed outline defense

---

## 🔍 Common Queries

### Get Latest Submission
```php
$submission = fetchLatestFinalPaperSubmission($conn, $studentId);
```

### Get All Reviews for Submission
```php
$reviews = fetchFinalPaperReviews($conn, $submissionId);
```

### Get Specific Reviewer's Review
```php
$review = fetchFinalPaperReviewForUser($conn, $submissionId, $reviewerId);
```

### Get Verdict
```php
$verdict = getOutlineDefenseVerdict($conn, $submissionId);
```

### Set Verdict
```php
setOutlineDefenseVerdict($conn, $submissionId, 'Passed');
```

---

## 🐛 Troubleshooting

### Issue: "Access Denied"
**Solution:** Check user role in session
```php
echo $_SESSION['role']; // Should be adviser, committee_chairperson, or panel
```

### Issue: "Submission Not Found"
**Solution:** Verify submission ID exists
```php
$submission = fetchFinalPaperSubmission($conn, $submissionId);
if (!$submission) { /* Handle error */ }
```

### Issue: "No Reviews Found"
**Solution:** Check if reviewers have been assigned
```php
$reviews = fetchFinalPaperReviews($conn, $submissionId);
if (empty($reviews)) { /* No reviews yet */ }
```

### Issue: "Notification Not Sent"
**Solution:** Verify notifications table exists
```php
notifications_bootstrap($conn); // Ensures table exists
```

### Issue: "File Upload Failed"
**Solution:** Check directory permissions
```bash
chmod 777 uploads/outline_defense/
chmod 777 uploads/route_slips/
```

---

## 📋 Testing Checklist

- [ ] Run test_outline_defense.php - All 38 tests pass
- [ ] Student can submit manuscript
- [ ] Reviewers receive notification
- [ ] Reviewer can access review page
- [ ] Reviewer can submit review
- [ ] Student receives review notification
- [ ] Chairperson can access decision page
- [ ] Chairperson can see all reviews
- [ ] Chairperson can submit decision
- [ ] Student receives decision notification
- [ ] Verdict displays on student page
- [ ] Student can resubmit if needed

---

## 🔗 Important Links

| Page | URL | Purpose |
|------|-----|---------|
| Student Submission | submit_final_paper.php | Submit manuscripts |
| Reviewer Review | outline_defense_review.php?submission_id={id} | Review manuscripts |
| Chairperson Decision | outline_defense_decision.php?submission_id={id} | Make final decision |
| Test Suite | test_outline_defense.php | Validate implementation |

---

## 📞 Support Resources

1. **Full Documentation:** OUTLINE_DEFENSE_FEATURE.md
2. **Implementation Details:** IMPLEMENTATION_SUMMARY.md
3. **Test Suite:** test_outline_defense.php
4. **Code Comments:** Check inline documentation in each file

---

## 🎯 Key Features Summary

✅ **Database Schema** - Proper tables with foreign keys
✅ **Role-Based Access** - Enforced on all pages
✅ **Notification System** - Complete workflow notifications
✅ **File Management** - PDF upload and storage
✅ **Error Handling** - Comprehensive validation
✅ **Security** - SQL injection prevention, input validation
✅ **Testing** - 38-test comprehensive suite
✅ **Documentation** - Complete guides provided

---

## 📝 Version Information

- **Feature:** Outline Defense Manuscript Submission
- **Status:** Production Ready ✓
- **Last Updated:** 2026-01-13
- **Test Coverage:** 38 tests
- **Documentation:** Complete

---

**Ready to deploy! 🚀**
