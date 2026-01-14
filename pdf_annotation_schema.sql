-- PDF Annotation System Database Schema
-- Created for IAdS Platform
-- Date: 2026-01-14

-- =====================================================
-- TABLE 1: pdf_submissions
-- Purpose: Stores PDF metadata and tracks submission versions
-- =====================================================
CREATE TABLE IF NOT EXISTS pdf_submissions (
    submission_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    adviser_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    submission_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    file_size INT,
    mime_type VARCHAR(50),
    submission_status ENUM('pending', 'reviewed', 'approved', 'revision_requested') DEFAULT 'pending',
    version_number INT DEFAULT 1,
    parent_submission_id INT,
    
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_submission_id) REFERENCES pdf_submissions(submission_id) ON DELETE SET NULL,
    
    INDEX idx_student_adviser (student_id, adviser_id),
    INDEX idx_status (submission_status),
    INDEX idx_timestamp (submission_timestamp),
    INDEX idx_student_id (student_id),
    INDEX idx_adviser_id (adviser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 2: pdf_annotations
-- Purpose: Stores individual annotations with spatial positioning
-- =====================================================
CREATE TABLE IF NOT EXISTS pdf_annotations (
    annotation_id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    adviser_id INT NOT NULL,
    annotation_type ENUM('comment', 'highlight', 'suggestion') NOT NULL,
    annotation_content TEXT NOT NULL,
    page_number INT NOT NULL,
    x_coordinate DECIMAL(10, 4) NOT NULL,
    y_coordinate DECIMAL(10, 4) NOT NULL,
    selected_text TEXT,
    creation_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    annotation_status ENUM('active', 'resolved', 'archived') DEFAULT 'active',
    
    FOREIGN KEY (submission_id) REFERENCES pdf_submissions(submission_id) ON DELETE CASCADE,
    FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_submission (submission_id),
    INDEX idx_adviser (adviser_id),
    INDEX idx_page (page_number),
    INDEX idx_status (annotation_status),
    INDEX idx_created_at (creation_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 3: annotation_replies
-- Purpose: Enables threaded conversations around annotations
-- =====================================================
CREATE TABLE IF NOT EXISTS annotation_replies (
    reply_id INT AUTO_INCREMENT PRIMARY KEY,
    annotation_id INT NOT NULL,
    user_id INT NOT NULL,
    reply_content TEXT NOT NULL,
    reply_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_role ENUM('student', 'adviser') NOT NULL,
    
    FOREIGN KEY (annotation_id) REFERENCES pdf_annotations(annotation_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_annotation (annotation_id),
    INDEX idx_user (user_id),
    INDEX idx_timestamp (reply_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 4: submission_notifications
-- Purpose: Tracks notification delivery and status
-- =====================================================
CREATE TABLE IF NOT EXISTS submission_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    recipient_id INT NOT NULL,
    notification_type ENUM('new_submission', 'new_annotation', 'submission_approved', 'revision_requested') NOT NULL,
    creation_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_status BOOLEAN DEFAULT FALSE,
    action_url VARCHAR(255),
    
    FOREIGN KEY (submission_id) REFERENCES pdf_submissions(submission_id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_recipient (recipient_id),
    INDEX idx_read_status (read_status),
    INDEX idx_timestamp (creation_timestamp),
    INDEX idx_submission (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE 5: annotation_history
-- Purpose: Maintains audit trail for compliance and recovery
-- =====================================================
CREATE TABLE IF NOT EXISTS annotation_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    annotation_id INT NOT NULL,
    action_type ENUM('created', 'updated', 'resolved', 'deleted') NOT NULL,
    changed_by INT NOT NULL,
    old_value TEXT,
    new_value TEXT,
    change_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (annotation_id) REFERENCES pdf_annotations(annotation_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_annotation (annotation_id),
    INDEX idx_action (action_type),
    INDEX idx_timestamp (change_timestamp),
    INDEX idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Create upload directories (if needed)
-- =====================================================
-- Note: These directories should be created manually with proper permissions:
-- mkdir -p uploads/pdf_submissions
-- chmod 755 uploads/pdf_submissions
-- mkdir -p uploads/pdf_revisions
-- chmod 755 uploads/pdf_revisions
