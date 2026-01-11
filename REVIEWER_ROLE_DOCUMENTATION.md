# Faculty Reviewer Role Documentation

## Overview
The Faculty Reviewer role is a dynamic role within the Institute of Advanced Studies (IAdS) management system, designed to provide faculty members with the ability to review and rank student concept papers.

## Role Characteristics
- **Switchable**: Yes
- **Dashboard**: `reviewer_dashboard.php`
- **Primary Functions**: 
  1. View assigned concept papers
  2. Rank concept papers
  3. Provide mentoring interest feedback

## Role Assignment
Faculty members can be assigned to the Reviewer role through the system's role management infrastructure. The role can be dynamically switched with other faculty-related roles such as:
- Adviser
- Panel Member
- Committee Chair
- Program Chairperson

## Permissions
The Reviewer role comes with specific permissions managed by `ReviewerPermissions` class:

### Key Permissions
- `canViewConceptPaper(conceptPaperId)`: 
  - Checks if the reviewer can view a specific concept paper
  - Based on reviewer assignments

- `canSubmitReview(assignmentId)`: 
  - Verifies if the reviewer can submit a review
  - Checks assignment status (pending/in-progress)

- `getAssignedConceptPapers()`: 
  - Retrieves all concept papers assigned to the reviewer
  - Includes paper details, student information, and assignment status

## Workflow
1. Reviewer logs in
2. Views assigned concept papers on dashboard
3. Can rank papers (Rank 1, 2, 3)
4. Can indicate mentoring interest
5. Submits review through system interface

## Technical Implementation
- Role defined in `role_helpers.php`
- Permissions managed by `reviewer_permissions.php`
- Dashboard implemented in `reviewer_dashboard.php`
- Role switching handled by `switch_role.php`

## Best Practices
- Regularly update and rank assigned concept papers
- Provide constructive feedback
- Indicate mentoring interest when appropriate

## Logging
- All reviewer actions are logged in `reviewer_action_logs` table
- Includes user ID, action type, and concept paper reference

## System Requirements
- PHP 7.4+
- MySQL 5.7+
- Bootstrap 5.3+

## Potential Future Enhancements
- Advanced analytics on reviewer rankings
- More granular permission controls
- Integration with research management systems

## Troubleshooting
- If unable to view/rank papers, contact system administrator
- Ensure current role is set to 'reviewer'
- Verify active assignments in dashboard