-- =====================================================
-- Committee PDF Final Verdict Migration
-- =====================================================
-- This migration adds final verdict columns to track
-- the committee chairperson's final decision on
-- committee PDF submissions.
--
-- Run this SQL in phpMyAdmin or MySQL client.
-- =====================================================

-- Add final verdict columns
ALTER TABLE committee_pdf_submissions
ADD COLUMN final_verdict ENUM(
    'pending',
    'passed',
    'passed_minor_revisions',
    'passed_major_revisions',
    'redefense',
    'failed'
) DEFAULT 'pending' AFTER submission_status,
ADD COLUMN final_verdict_comments TEXT NULL AFTER final_verdict,
ADD COLUMN final_verdict_by INT NULL AFTER final_verdict_comments,
ADD COLUMN final_verdict_at TIMESTAMP NULL AFTER final_verdict_by,
ADD INDEX idx_final_verdict (final_verdict);

-- Optional: Add foreign key constraint
-- Uncomment if you want to enforce referential integrity
-- ALTER TABLE committee_pdf_submissions
-- ADD CONSTRAINT fk_committee_verdict_by
-- FOREIGN KEY (final_verdict_by) 
-- REFERENCES users(id)
-- ON DELETE SET NULL;

-- Verify the changes
DESCRIBE committee_pdf_submissions;
