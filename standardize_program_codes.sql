-- SQL Script to Standardize Program Column Values
-- This script converts full program names to their standardized codes
-- Run this script to fix existing inconsistent data in the users table

-- Backup recommendation: Create a backup before running this script
-- Example: CREATE TABLE users_backup AS SELECT * FROM users;

-- Update full program names to standardized codes
UPDATE users 
SET program = 'PHDEM' 
WHERE program IN ('PhD in Educational Management', 'Doctor of Philosophy in Educational Management', 'PHDEM');

UPDATE users 
SET program = 'MAEM' 
WHERE program IN ('Master of Arts in Educational Management', 'MAEM');

UPDATE users 
SET program = 'MAED-ELST' 
WHERE program IN (
    'PhD in English Language Studies and Teaching',
    'Doctor of Philosophy in English Language Studies and Teaching',
    'Master of Education Major in English Language Studies and Teaching',
    'MAED-ELST'
);

UPDATE users 
SET program = 'MST-GENSCI' 
WHERE program IN ('Master in Science Teaching Major in General Science', 'MST-GENSCI');

UPDATE users 
SET program = 'MST-MATH' 
WHERE program IN ('Master in Science Teaching Major in Mathematics', 'MST-MATH');

UPDATE users 
SET program = 'MFM-AT' 
WHERE program IN ('Master in Fisheries Management Major in Aquaculture Technology', 'MFM-AT');

UPDATE users 
SET program = 'MFM-FP' 
WHERE program IN ('Master in Fisheries Management Major in Fish Processing', 'MFM-FP');

UPDATE users 
SET program = 'MSMB' 
WHERE program IN (
    'MS in Marine Biodiversity & Fisheries Management',
    'Master of Science in Marine Biodiversity',
    'MSMB'
);

UPDATE users 
SET program = 'MIT' 
WHERE program IN ('Master in Information Technology', 'MIT');

-- Check for any remaining non-standard program values
SELECT DISTINCT program, COUNT(*) as count
FROM users 
WHERE program IS NOT NULL 
  AND program NOT IN ('PHDEM', 'MAEM', 'MAED-ELST', 'MST-GENSCI', 'MST-MATH', 'MFM-AT', 'MFM-FP', 'MSMB', 'MIT')
GROUP BY program;

-- Display summary of standardized programs
SELECT program, COUNT(*) as total_users
FROM users
WHERE program IS NOT NULL
GROUP BY program
ORDER BY program;
