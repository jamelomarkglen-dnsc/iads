-- Create user_events table in advance_studies database
USE advance_studies;

CREATE TABLE IF NOT EXISTS user_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME,
    category ENUM('Defense', 'Meeting', 'Call', 'Academic', 'Personal', 'Other') DEFAULT 'Other',
    color VARCHAR(7) DEFAULT '#16562c', -- Default to primary green
    is_all_day BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_role_events (user_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create a trigger to validate event dates
DELIMITER //
CREATE TRIGGER IF NOT EXISTS validate_user_event_dates 
BEFORE INSERT ON user_events
FOR EACH ROW
BEGIN
    IF NEW.end_datetime IS NOT NULL AND NEW.end_datetime < NEW.start_datetime THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'End datetime cannot be earlier than start datetime';
    END IF;
END;//
DELIMITER ;

-- Create a stored procedure to fetch user events
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS GetUserEvents(
    IN p_user_id INT, 
    IN p_role VARCHAR(50), 
    IN p_start_date DATETIME, 
    IN p_end_date DATETIME
)
BEGIN
    SELECT 
        id, 
        title, 
        start_datetime AS start, 
        end_datetime AS end, 
        category,
        color,
        description,
        is_all_day
    FROM 
        user_events
    WHERE 
        user_id = p_user_id 
        AND role = p_role
        AND (
            (start_datetime BETWEEN p_start_date AND p_end_date)
            OR (end_datetime BETWEEN p_start_date AND p_end_date)
            OR (p_start_date BETWEEN start_datetime AND end_datetime)
        )
    ORDER BY 
        start_datetime;
END //
DELIMITER ;

-- Optional: Create event categories table for reference
CREATE TABLE IF NOT EXISTS event_categories (
    category ENUM('Defense', 'Meeting', 'Call', 'Academic', 'Personal', 'Other') PRIMARY KEY,
    default_color VARCHAR(7),
    text_color VARCHAR(7) DEFAULT '#000000'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate event categories
INSERT IGNORE INTO event_categories (category, default_color, text_color) VALUES
('Defense', '#80e0a0', '#000000'),     -- Green
('Meeting', '#4db8ff', '#000000'),     -- Blue
('Call', '#444444', '#ffffff'),        -- Dark Gray
('Academic', '#ffc107', '#000000'),    -- Amber
('Personal', '#9c27b0', '#ffffff'),    -- Purple
('Other', '#c8c8c8', '#000000');       -- Light Gray