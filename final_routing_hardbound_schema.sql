-- Schema for Final Routing + Final Hardbound committee PDF flow
-- Safe to run multiple times (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS final_routing_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    student_id INT NOT NULL,
    adviser_id INT NOT NULL,
    chair_id INT NOT NULL,
    panel_member_one_id INT NOT NULL,
    panel_member_two_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size INT NULL,
    mime_type VARCHAR(100) NULL,
    status ENUM('Submitted','Needs Revision','Passed') DEFAULT 'Submitted',
    version_number INT NOT NULL DEFAULT 1,
    parent_submission_id INT NULL,
    reviewed_by INT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    review_notes TEXT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_final_routing_submission (submission_id),
    INDEX idx_final_routing_student (student_id),
    INDEX idx_final_routing_status (status),
    INDEX idx_final_routing_chair (chair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS final_routing_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    reviewer_role ENUM('adviser','committee_chairperson','panel') NOT NULL,
    status ENUM('Pending','Reviewed') DEFAULT 'Pending',
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_final_routing_review (submission_id, reviewer_id),
    INDEX idx_final_routing_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS final_routing_annotations (
    annotation_id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    reviewer_role ENUM('adviser','committee_chairperson','panel') NOT NULL,
    annotation_type ENUM('comment','highlight','suggestion') NOT NULL,
    annotation_content TEXT NOT NULL,
    page_number INT NOT NULL,
    x_coordinate FLOAT NOT NULL,
    y_coordinate FLOAT NOT NULL,
    selected_text TEXT NULL,
    position_width FLOAT DEFAULT 5,
    position_height FLOAT DEFAULT 5,
    annotation_status ENUM('active','resolved') DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_final_routing_annotation_submission (submission_id),
    INDEX idx_final_routing_annotation_reviewer (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS final_routing_annotation_replies (
    reply_id INT AUTO_INCREMENT PRIMARY KEY,
    annotation_id INT NOT NULL,
    user_id INT NOT NULL,
    reply_content TEXT NOT NULL,
    reply_user_role ENUM('student','adviser','committee_chairperson','panel') NOT NULL,
    reply_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_final_routing_reply_annotation (annotation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS final_hardbound_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    routing_submission_id INT NULL,
    student_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size INT NULL,
    mime_type VARCHAR(100) NULL,
    status ENUM('Submitted','Under Review','Verified','Rejected') DEFAULT 'Submitted',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    reviewed_by INT NULL,
    review_notes TEXT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_final_hardbound_submission (submission_id),
    INDEX idx_final_hardbound_student (student_id),
    INDEX idx_final_hardbound_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS final_hardbound_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hardbound_submission_id INT NOT NULL,
    adviser_id INT NOT NULL,
    program_chair_id INT NOT NULL,
    status ENUM('Pending','Verified','Rejected') DEFAULT 'Pending',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    remarks TEXT NULL,
    INDEX idx_final_hardbound_request_submission (hardbound_submission_id),
    INDEX idx_final_hardbound_request_status (status),
    INDEX idx_final_hardbound_request_chair (program_chair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
