-- User Events Table for Role-Specific Calendar Management
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

-- Predefined Color Palette for Event Categories
CREATE TABLE IF NOT EXISTS event_categories (
    category ENUM('Defense', 'Meeting', 'Call', 'Academic', 'Personal', 'Other') PRIMARY KEY,
    default_color VARCHAR(7),
    text_color VARCHAR(7) DEFAULT '#000000'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populate Event Categories with Default Colors
INSERT INTO event_categories (category, default_color, text_color) VALUES
('Defense', '#80e0a0', '#000000'),     -- Green
('Meeting', '#4db8ff', '#000000'),     -- Blue
('Call', '#444444', '#ffffff'),        -- Dark Gray
('Academic', '#ffc107', '#000000'),    -- Amber
('Personal', '#9c27b0', '#ffffff'),    -- Purple
('Other', '#c8c8c8', '#000000')        -- Light Gray
ON DUPLICATE KEY UPDATE 
    default_color = VALUES(default_color), 
    text_color = VALUES(text_color);

-- Trigger to Validate Event Dates
DELIMITER //
CREATE TRIGGER validate_user_event_dates 
BEFORE INSERT ON user_events
FOR EACH ROW
BEGIN
    IF NEW.end_datetime IS NOT NULL AND NEW.end_datetime < NEW.start_datetime THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'End datetime cannot be earlier than start datetime';
    END IF;
END;//
DELIMITER ;

-- Stored Procedure to Fetch User-Specific Events
DELIMITER //
CREATE PROCEDURE GetUserEvents(
    IN p_user_id INT, 
    IN p_role VARCHAR(50), 
    IN p_start_date DATETIME, 
    IN p_end_date DATETIME
)
BEGIN
    SELECT 
        ue.id, 
        ue.title, 
        ue.start_datetime, 
        ue.end_datetime, 
        ue.category, 
        COALESCE(ue.color, ec.default_color) AS event_color,
        ec.text_color,
        ue.description,
        ue.is_all_day
    FROM 
        user_events ue
    JOIN 
        event_categories ec ON ue.category = ec.category
    WHERE 
        ue.user_id = p_user_id 
        AND ue.role = p_role
        AND (
            (ue.start_datetime BETWEEN p_start_date AND p_end_date)
            OR (ue.end_datetime BETWEEN p_start_date AND p_end_date)
            OR (p_start_date BETWEEN ue.start_datetime AND ue.end_datetime)
        )
    ORDER BY 
        ue.start_datetime;
END //
DELIMITER ;
