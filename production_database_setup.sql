-- =====================================================
-- PDF ANNOTATION SYSTEM - PRODUCTION DATABASE SETUP
-- =====================================================
-- Database: advance_studies (or u645049065_iads2 for live)
-- Created: 2026-01-14
-- Purpose: Complete production-ready schema for PDF annotation system
-- =====================================================

-- Set character set and collation
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- =====================================================
-- TABLE 1: pdf_submissions
-- Purpose: Stores PDF metadata and tracks submission versions
-- =====================================================
CREATE TABLE IF NOT EXISTS `pdf_submissions` (
    `submission_id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `adviser_id` INT NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `submission_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `file_size` INT,
    `mime_type` VARCHAR(50),
    `submission_status` ENUM('pending', 'reviewed', 'approved', 'revision_requested') DEFAULT 'pending',
    `version_number` INT DEFAULT 1,
    `parent_submission_id` INT DEFAULT NULL,
    
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`adviser_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_submission_id`) REFERENCES `pdf_submissions`(`submission_id`) ON DELETE SET NULL,
    
    INDEX `idx_student_adviser` (`student_id`, `adviser_id`),
    INDEX `idx_status` (`submission_status`),
    INDEX `idx_timestamp` (`submission_timestamp`),
    INDEX `idx_student_id` (`student_id`),
    INDEX `idx_adviser_id` (`adviser_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 2: pdf_annotations
-- Purpose: Stores individual annotations with spatial positioning
-- IMPORTANT: Includes position_width and position_height columns
-- =====================================================
CREATE TABLE IF NOT EXISTS `pdf_annotations` (
    `annotation_id` INT AUTO_INCREMENT PRIMARY KEY,
    `submission_id` INT NOT NULL,
    `adviser_id` INT NOT NULL,
    `annotation_type` ENUM('comment', 'highlight', 'suggestion') NOT NULL,
    `annotation_content` TEXT NOT NULL,
    `page_number` INT NOT NULL,
    `x_coordinate` DECIMAL(10, 4) NOT NULL COMMENT 'X position as percentage (0-100)',
    `y_coordinate` DECIMAL(10, 4) NOT NULL COMMENT 'Y position as percentage (0-100)',
    `position_width` DECIMAL(10, 4) DEFAULT 5.00 COMMENT 'Width as percentage (0-100)',
    `position_height` DECIMAL(10, 4) DEFAULT 5.00 COMMENT 'Height as percentage (0-100)',
    `selected_text` TEXT,
    `creation_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `annotation_status` ENUM('active', 'resolved', 'archived') DEFAULT 'active',
    
    FOREIGN KEY (`submission_id`) REFERENCES `pdf_submissions`(`submission_id`) ON DELETE CASCADE,
    FOREIGN KEY (`adviser_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    
    INDEX `idx_submission` (`submission_id`),
    INDEX `idx_adviser` (`adviser_id`),
    INDEX `idx_page` (`page_number`),
    INDEX `idx_status` (`annotation_status`),
    INDEX `idx_created_at` (`creation_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 3: annotation_replies
-- Purpose: Enables threaded conversations around annotations
-- =====================================================
CREATE TABLE IF NOT EXISTS `annotation_replies` (
    `reply_id` INT AUTO_INCREMENT PRIMARY KEY,
    `annotation_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `reply_content` TEXT NOT NULL,
    `reply_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `user_role` ENUM('student', 'adviser') NOT NULL,
    
    FOREIGN KEY (`annotation_id`) REFERENCES `pdf_annotations`(`annotation_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    
    INDEX `idx_annotation` (`annotation_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_timestamp` (`reply_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 4: submission_notifications
-- Purpose: Tracks notification delivery and status
-- =====================================================
CREATE TABLE IF NOT EXISTS `submission_notifications` (
    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `submission_id` INT NOT NULL,
    `recipient_id` INT NOT NULL,
    `notification_type` ENUM('new_submission', 'new_annotation', 'submission_approved', 'revision_requested') NOT NULL,
    `creation_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `read_status` BOOLEAN DEFAULT FALSE,
    `action_url` VARCHAR(255),
    
    FOREIGN KEY (`submission_id`) REFERENCES `pdf_submissions`(`submission_id`) ON DELETE CASCADE,
    FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    
    INDEX `idx_recipient` (`recipient_id`),
    INDEX `idx_read_status` (`read_status`),
    INDEX `idx_timestamp` (`creation_timestamp`),
    INDEX `idx_submission` (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 5: annotation_history
-- Purpose: Maintains audit trail for compliance and recovery
-- =====================================================
CREATE TABLE IF NOT EXISTS `annotation_history` (
    `history_id` INT AUTO_INCREMENT PRIMARY KEY,
    `annotation_id` INT NOT NULL,
    `action_type` ENUM('created', 'updated', 'resolved', 'deleted') NOT NULL,
    `changed_by` INT NOT NULL,
    `old_value` TEXT,
    `new_value` TEXT,
    `change_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`annotation_id`) REFERENCES `pdf_annotations`(`annotation_id`) ON DELETE CASCADE,
    FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    
    INDEX `idx_annotation` (`annotation_id`),
    INDEX `idx_action` (`action_type`),
    INDEX `idx_timestamp` (`change_timestamp`),
    INDEX `idx_changed_by` (`changed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- MIGRATION: Add position columns to existing tables
-- (Only needed if pdf_annotations table already exists)
-- =====================================================
-- Check if columns exist before adding them
SET @dbname = DATABASE();
SET @tablename = 'pdf_annotations';
SET @columnname1 = 'position_width';
SET @columnname2 = 'position_height';

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname1)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname1, '` DECIMAL(10, 4) DEFAULT 5.00 COMMENT ''Width as percentage (0-100)'' AFTER `y_coordinate`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname2)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname2, '` DECIMAL(10, 4) DEFAULT 5.00 COMMENT ''Height as percentage (0-100)'' AFTER `position_width`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================
-- Run these queries to verify the tables were created successfully

-- Check all tables exist
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME,
    UPDATE_TIME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN (
    'pdf_submissions',
    'pdf_annotations',
    'annotation_replies',
    'submission_notifications',
    'annotation_history'
)
ORDER BY TABLE_NAME;

-- Check pdf_annotations columns (verify position_width and position_height exist)
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'pdf_annotations'
ORDER BY ORDINAL_POSITION;

-- =====================================================
-- POST-INSTALLATION NOTES
-- =====================================================
-- 1. Create upload directories with proper permissions:
--    mkdir -p uploads/pdf_submissions
--    chmod 755 uploads/pdf_submissions
--    mkdir -p uploads/pdf_revisions
--    chmod 755 uploads/pdf_revisions
--
-- 2. Ensure web server has write permissions to upload directories
--
-- 3. Update db.php with correct database credentials:
--    - For local: database = "advance_studies"
--    - For live: database = "u645049065_iads2"
--
-- 4. Test the system by:
--    - Uploading a PDF as a student
--    - Adding annotations as an adviser
--    - Verifying annotations appear correctly
--
-- 5. Monitor error logs for any issues:
--    - PHP error log
--    - MySQL error log
--    - Browser console for JavaScript errors
--
-- =====================================================
-- BACKUP RECOMMENDATION
-- =====================================================
-- Before running in production, backup your database:
-- mysqldump -u username -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql
--
-- =====================================================

-- Success message
SELECT 'PDF Annotation System tables created successfully!' AS Status;
SELECT 'Run the verification queries above to confirm installation.' AS NextStep;