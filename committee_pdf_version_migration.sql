-- =====================================================
-- Committee PDF Submissions - Version Tracking Migration
-- =====================================================
-- This migration adds parent_submission_id column to enable
-- version tracking for committee PDF submissions, similar to
-- the regular PDF submission system.
--
-- Run this SQL in phpMyAdmin or MySQL client before using
-- the per-entry reupload feature.
-- =====================================================

-- Add parent_submission_id column to track version chains
ALTER TABLE committee_pdf_submissions 
ADD COLUMN parent_submission_id INT NULL AFTER version_number,
ADD INDEX idx_parent_submission (parent_submission_id);

-- Optional: Add foreign key constraint for referential integrity
-- Uncomment if you want to enforce parent-child relationships
-- ALTER TABLE committee_pdf_submissions
-- ADD CONSTRAINT fk_committee_parent_submission
-- FOREIGN KEY (parent_submission_id) 
-- REFERENCES committee_pdf_submissions(id)
-- ON DELETE SET NULL;

-- Verify the changes
DESCRIBE committee_pdf_submissions;
