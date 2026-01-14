-- Add position_width and position_height columns to pdf_annotations table
-- Run this SQL to update your database schema

ALTER TABLE pdf_annotations 
ADD COLUMN position_width DECIMAL(5,2) DEFAULT 5.00 AFTER selected_text,
ADD COLUMN position_height DECIMAL(5,2) DEFAULT 5.00 AFTER position_width;

-- Verify the columns were added
DESCRIBE pdf_annotations;