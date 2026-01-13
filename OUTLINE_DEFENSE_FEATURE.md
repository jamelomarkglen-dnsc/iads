# Outline Defense Manuscript Submission Feature

## Overview

The Outline Defense Manuscript Submission Feature provides a complete workflow for students to submit outline defense manuscripts and for committee members to review and provide feedback. The system includes proper database structure, role-based access control, and a comprehensive notification system.

## Architecture

### Database Schema

#### `final_paper_submissions` Table
Stores outline defense manuscript submissions with the following key columns:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | INT | Primary key |
| `student_id` | INT | Foreign key to users table |
| `final_title` | VARCHAR(255) | Outline defense manuscript title |
| `introduction` | TEXT | Introduction section |
| `background` | TEXT | Background section |
| `methodology` | TEXT | Methodology section |
| `submission_notes` | TEXT | Student submission notes |
| `file_path` | VARCHAR(255) | Path to uploaded PDF manuscript |
| `file_name` | VARCHAR(255) | Original filename |
| `route_slip_path` | VARCHAR(255) | Path to route slip PDF |
| `route_slip_name` | VARCHAR(255) | Route slip filename |
| `status` | ENUM | Submission status (Submitted, Under Review, Needs Revision, Minor Revision, Major Revision, Approved, Rejected) |
| `version` | INT | Submission version number |
| `submitted_at` | TIMESTAMP | Submission timestamp |
| `updated_at` | TIMESTAMP | Last update timestamp |
| `final_decision_by` | INT | Foreign key to users (committee chairperson) |
| `final_decision_notes` | TEXT | Chairperson's decision notes |
| `final_decision_at` | TIMESTAMP | Decision timestamp |
| `committee_reviews_completed_at` | TIMESTAMP | When all reviews were completed |
| `outline_defense_verdict` | VARCHAR(50) | Final verdict (Passed, Passed with Revision, Failed) |
| `outline_defense_verdict_at` | TIMESTAMP | Verdict timestamp |

#### `final_paper_reviews` Table
Stores individual reviewer feedback:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | INT | Primary key |
| `submission_id` | INT | Foreign key to final_paper_submissions |
| `reviewer_id` | INT | Foreign key to users |
| `reviewer_role` | ENUM | Role of reviewer (adviser, committee_chairperson, panel) |
| `status` | ENUM | Review status (Pending, Approved, Rejected, Needs Revision, Minor Revision, Major Revision) |
| `comments` | TEXT | Reviewer's feedback comments |
| `reviewed_at` | TIMESTAMP | Review completion timestamp |
| `created_at` | TIMESTAMP | Review creation timestamp |

### Foreign Key Relationships

```
final_paper_submissions
├── student_id → users.id (ON DELETE CASCADE)
└── final_decision_by → users.id (ON DELETE SET NULL)

final_paper_reviews
├── submission_id → final_paper_submissions.id (ON DELETE CASCADE)
└── reviewer_id → users.id (ON DELETE CASCADE)
```

## File Structure

### Core Files

#### `final_paper_helpers.php`
Helper functions for managing outline defense submissions:

- `ensureFinalPaperTables()` - Creates/validates database tables
- `fetchFinalPaperSubmission()` - Retrieves a specific submission
- `fetchFinalPaperReviews()` - Gets all reviews for a submission
- `fetchFinalPaperReviewForUser()` - Gets a specific reviewer's feedback
- `setOutlineDefenseVerdict()` - Records the final verdict
- `getOutlineDefenseVerdict()` - Retrieves the verdict
- `outlineDefenseVerdictClass()` - Returns CSS class for verdict display
- `outlineDefenseVerdictLabel()` - Returns human-readable verdict label
- `finalPaperStatusClass()` - Returns CSS class for status display
- `finalPaperStatusLabel()` - Returns human-readable status label

#### `submit_final_paper.php`
Student interface for submitting outline defense manuscripts:

- Displays submission status and version history
- Allows manuscript upload (PDF only)
- Supports route slip upload
- Shows reviewer feedback and verdicts
- Enables resubmission for revisions
- Displays outline defense verdict prominently

#### `outline_defense_review.php`
Committee member review interface:

- Accessible to: adviser, committee_chairperson, panel
- Displays student information and submission details
- Provides document download links
- Allows reviewer to submit feedback and status
- Sends notifications to student upon review completion

#### `outline_defense_decision.php`
Committee chairperson final decision interface:

- Accessible to: committee_chairperson only
- Displays all committee member reviews
- Allows chairperson to make final decision
- Records verdict (Passed, Passed with Revision, Failed)
- Sends notification to student with decision

#### `notifications_helper.php`
Notification functions for outline defense workflow:

- `notify_outline_defense_submission()` - Notifies reviewers of new submission
- `notify_outline_defense_review_completed()` - Notifies student of review completion
- `notify_outline_defense_decision()` - Notifies student of final decision
- `notify_outline_defense_route_slip_submitted()` - Notifies reviewers of route slip

### Test Files

#### `test_outline_defense.php`
Comprehensive test suite validating:

- Database schema and column existence
- Foreign key relationships
- Helper function availability
- Notification function availability
- Role definitions
- Enum value validation
- File upload directory accessibility
- Helper function logic correctness
- Status and verdict label functions

## Role-Based Access Control

### Student Role
- Can submit outline defense manuscripts
- Can view their own submission status
- Can view reviewer feedback and verdicts
- Can resubmit if marked for revision
- Can upload route slip packets

### Adviser Role
- Can review outline defense manuscripts
- Can provide feedback and status
- Can access outline_defense_review.php
- Receives notifications of new submissions

### Committee Chairperson Role
- Can review outline defense manuscripts
- Can access outline_defense_review.php
- Can access outline_defense_decision.php
- Can make final decisions and verdicts
- Can view all committee member reviews

### Panel Member Role
- Can review outline defense manuscripts
- Can provide feedback and status
- Can access outline_defense_review.php
- Receives notifications of new submissions

## Notification System

### Submission Notification
When a student submits an outline defense manuscript:
- All assigned reviewers receive notification
- Link to outline_defense_review.php provided
- Message includes student name and submission details

### Review Completion Notification
When a reviewer completes their review:
- Student receives notification
- Includes reviewer name, role, and status
- Link to submit_final_paper.php provided

### Decision Notification
When committee chairperson makes final decision:
- Student receives notification
- Includes verdict and decision notes
- Link to submit_final_paper.php provided

### Route Slip Notification
When student submits route slip packet:
- All reviewers receive notification
- Indicates if revised manuscript was included
- Link to outline_defense_review.php provided

## Workflow

### Student Submission Flow
1. Student navigates to submit_final_paper.php
2. Fills in outline defense title and notes
3. Uploads manuscript PDF
4. Optionally uploads route slip PDF
5. Submits for review
6. System creates submission record
7. Notifications sent to all reviewers
8. Student can view feedback as it arrives

### Reviewer Review Flow
1. Reviewer receives notification of new submission
2. Clicks link to outline_defense_review.php
3. Downloads and reviews manuscript
4. Selects review status (Approved, Rejected, Revision Required)
5. Provides detailed feedback comments
6. Submits review
7. Student receives notification of review completion

### Chairperson Decision Flow
1. Chairperson accesses outline_defense_decision.php
2. Reviews all committee member feedback
3. Selects final status and verdict
4. Provides decision notes
5. Submits final decision
6. System records verdict and timestamps
7. Student receives notification with decision

### Resubmission Flow
1. Student receives feedback indicating revision needed
2. Revises manuscript based on feedback
3. Resubmits through submit_final_paper.php
4. Version number increments
5. Status resets to "Submitted"
6. Notifications sent to reviewers again

## Database Validation

### Schema Validation
The system automatically:
- Creates required tables on first access
- Adds missing columns to existing tables
- Validates enum values
- Ensures foreign key constraints

### Data Integrity
- Foreign key constraints prevent orphaned records
- ON DELETE CASCADE removes related reviews when submission deleted
- ON DELETE SET NULL preserves decision history if chairperson deleted
- Unique constraints prevent duplicate reviews per reviewer

## Security Features

### Role Enforcement
- `enforce_role_access()` validates user permissions
- Only authorized roles can access specific pages
- Session validation on every request

### Input Validation
- File uploads validated for PDF format
- File type detection using finfo
- Filename sanitization
- SQL injection prevention via prepared statements

### Data Protection
- Passwords hashed in users table
- Sensitive data not exposed in URLs
- Timestamps track all modifications
- Audit trail via created_at and updated_at fields

## Error Handling

### Database Errors
- Prepared statements prevent SQL injection
- Connection errors logged and handled gracefully
- Transaction rollback on failures

### File Upload Errors
- File size validation
- MIME type verification
- Directory creation with proper permissions
- Cleanup of failed uploads

### Validation Errors
- User-friendly error messages
- Form data preservation on error
- Detailed logging for debugging

## Performance Considerations

### Indexing
- Primary keys indexed automatically
- Foreign keys indexed for join performance
- Created_at and updated_at indexed for sorting

### Query Optimization
- Prepared statements reduce parsing overhead
- Efficient joins between tables
- Limit clauses on result sets

### Caching
- Static role definitions cached
- Helper functions use static variables
- Notification queries optimized

## Testing

Run the comprehensive test suite:
```
http://your-domain/test_outline_defense.php
```

The test suite validates:
- All database tables and columns
- Foreign key relationships
- Helper function availability
- Notification functions
- Role definitions
- Enum values
- File upload directories
- Helper function logic

## Troubleshooting

### Common Issues

**Issue: "Table does not exist" error**
- Solution: Ensure `ensureFinalPaperTables()` is called at page start
- Check database permissions

**Issue: Foreign key constraint error**
- Solution: Verify user IDs exist before creating submissions
- Check cascade delete settings

**Issue: Notifications not sending**
- Solution: Verify `notifications_bootstrap()` is called
- Check user IDs in notification functions
- Review notification_api.php for delivery

**Issue: File upload fails**
- Solution: Check directory permissions (0777)
- Verify disk space available
- Check PHP upload_max_filesize setting

## Future Enhancements

- Email notifications for outline defense events
- Bulk reviewer assignment
- Submission deadline tracking
- Automatic reminder notifications
- Advanced filtering and search
- Export submission reports
- Plagiarism detection integration
- Version comparison tools

## Support

For issues or questions:
1. Check test_outline_defense.php for validation
2. Review error logs in browser console
3. Verify database schema with test suite
4. Check role assignments in user_roles table
