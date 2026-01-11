<?php
// Strict error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Ensure clean JSON output
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Start session and include necessary files
session_start();
require_once 'db.php';
require_once 'role_helpers.php';

// Prevent any output before JSON
ob_start();

// Ensure user_events table exists
function ensureUserEventsTable($conn) {
    $tableCheckQuery = "SHOW TABLES LIKE 'user_events'";
    $result = $conn->query($tableCheckQuery);
    
    if ($result->num_rows == 0) {
        // Create table if it doesn't exist
        $createTableQuery = "
        CREATE TABLE user_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME,
            category ENUM('Defense', 'Meeting', 'Call', 'Academic', 'Personal', 'Other') DEFAULT 'Other',
            color VARCHAR(7) DEFAULT '#16562c',
            source VARCHAR(50) NULL,
            source_id INT NULL,
            is_locked BOOLEAN DEFAULT FALSE,
            is_all_day BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_role_events (user_id, role),
            UNIQUE KEY uniq_user_source (user_id, source, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if (!$conn->query($createTableQuery)) {
            throw new Exception("Failed to create user_events table: " . $conn->error);
        }
    }

    $columns = [
        'source' => "ALTER TABLE user_events ADD COLUMN source VARCHAR(50) NULL AFTER color",
        'source_id' => "ALTER TABLE user_events ADD COLUMN source_id INT NULL AFTER source",
        'is_locked' => "ALTER TABLE user_events ADD COLUMN is_locked BOOLEAN DEFAULT FALSE AFTER source_id",
    ];
    foreach ($columns as $column => $sql) {
        $check = $conn->query("SHOW COLUMNS FROM user_events LIKE '{$column}'");
        if ($check && $check->num_rows === 0) {
            $conn->query($sql);
        }
        if ($check) {
            $check->free();
        }
    }

    $indexCheck = $conn->query("SHOW INDEX FROM user_events WHERE Key_name = 'uniq_user_source'");
    if ($indexCheck && $indexCheck->num_rows === 0) {
        $conn->query("ALTER TABLE user_events ADD UNIQUE KEY uniq_user_source (user_id, source, source_id)");
    }
    if ($indexCheck) {
        $indexCheck->free();
    }
}

// Error handling function
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    ob_clean(); // Clear any previous output
    echo json_encode($data);
    exit;
}

// Catch any unexpected errors
function handleUnexpectedErrors() {
    $error = error_get_last();
    if ($error !== null) {
        sendJsonResponse([
            'error' => true,
            'message' => 'Unexpected server error: ' . $error['message'],
            'error_details' => $error
        ], 500);
    }
}
register_shutdown_function('handleUnexpectedErrors');

try {
    // Ensure user_events table exists
    ensureUserEventsTable($conn);

    // Ensure user is logged in
    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse([
            'error' => true, 
            'message' => 'Unauthorized access'
        ], 403);
    }

    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'] ?? '';

    // Handle different request methods
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            // Read raw input
            $rawInput = file_get_contents('php://input');
            
            // Validate JSON input
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                sendJsonResponse([
                    'error' => true,
                    'message' => 'Invalid JSON: ' . json_last_error_msg()
                ], 400);
            }

            if (!empty($input['id'])) {
                $lockStmt = $conn->prepare("SELECT is_locked, source FROM user_events WHERE id = ? AND user_id = ? LIMIT 1");
                if ($lockStmt) {
                    $lockStmt->bind_param('ii', $input['id'], $user_id);
                    $lockStmt->execute();
                    $lockResult = $lockStmt->get_result();
                    $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
                    $lockStmt->close();
                    if ($lockRow && (!empty($lockRow['is_locked']) || ($lockRow['source'] ?? '') === 'defense')) {
                        sendJsonResponse([
                            'error' => true,
                            'message' => 'This defense event is locked and cannot be edited.'
                        ], 403);
                    }
                }
            }

            // Validate event data
            $validationErrors = validateEventData($input);
            if (!empty($validationErrors)) {
                sendJsonResponse([
                    'error' => true,
                    'message' => implode('; ', $validationErrors)
                ], 400);
            }

            // Prepare and execute database operation
            $stmt = $conn->prepare("
                INSERT INTO user_events 
                (user_id, role, title, description, start_datetime, end_datetime, category, color) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                title = VALUES(title), 
                description = VALUES(description), 
                start_datetime = VALUES(start_datetime), 
                end_datetime = VALUES(end_datetime), 
                category = VALUES(category), 
                color = VALUES(color)
            ");

            // Check if prepare was successful
            if ($stmt === false) {
                sendJsonResponse([
                    'error' => true,
                    'message' => 'Failed to prepare SQL statement: ' . $conn->error
                ], 500);
            }

            // Sanitize and validate inputs
            $title = trim($input['title']);
            $description = !empty($input['description']) ? trim($input['description']) : null;
            $start_datetime = $input['start_datetime'];
            $end_datetime = !empty($input['end_datetime']) ? $input['end_datetime'] : null;
            $category = $input['category'];
            $color = !empty($input['color']) ? $input['color'] : '#16562c';

            $stmt->bind_param(
                'isssssss', 
                $user_id, 
                $user_role, 
                $title, 
                $description, 
                $start_datetime, 
                $end_datetime, 
                $category, 
                $color
            );

            if ($stmt->execute()) {
                sendJsonResponse([
                    'success' => true, 
                    'message' => 'Event saved successfully', 
                    'event_id' => $stmt->insert_id
                ]);
            } else {
                sendJsonResponse([
                    'error' => true,
                    'message' => 'Database error: ' . $stmt->error
                ], 500);
            }
            break;

        case 'DELETE':
            $event_id = $_GET['id'] ?? null;
            
            if (!$event_id) {
                sendJsonResponse([
                    'error' => true,
                    'message' => 'Event ID is required'
                ], 400);
            }

            $lockStmt = $conn->prepare("SELECT is_locked, source FROM user_events WHERE id = ? AND user_id = ? LIMIT 1");
            if ($lockStmt) {
                $lockStmt->bind_param('ii', $event_id, $user_id);
                $lockStmt->execute();
                $lockResult = $lockStmt->get_result();
                $lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
                $lockStmt->close();
                if ($lockRow && (!empty($lockRow['is_locked']) || ($lockRow['source'] ?? '') === 'defense')) {
                    sendJsonResponse([
                        'error' => true,
                        'message' => 'This defense event is locked and cannot be deleted.'
                    ], 403);
                }
            }

            $stmt = $conn->prepare("
                DELETE FROM user_events 
                WHERE id = ? AND user_id = ? AND role = ?
            ");

            // Check if prepare was successful
            if ($stmt === false) {
                sendJsonResponse([
                    'error' => true,
                    'message' => 'Failed to prepare delete statement: ' . $conn->error
                ], 500);
            }

            $stmt->bind_param('iis', $event_id, $user_id, $user_role);
            
            if ($stmt->execute()) {
                sendJsonResponse([
                    'success' => true,
                    'message' => 'Event deleted successfully'
                ]);
            } else {
                sendJsonResponse([
                    'error' => true,
                    'message' => 'Failed to delete event: ' . $stmt->error
                ], 500);
            }
            break;

        default:
            sendJsonResponse([
                'error' => true,
                'message' => 'Method not allowed'
            ], 405);
    }
} catch (Exception $e) {
    sendJsonResponse([
        'error' => true,
        'message' => 'Unexpected error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 500);
}

// Validation function
function validateEventData($data) {
    $errors = [];

    // Title validation
    if (empty($data['title']) || strlen($data['title']) > 255) {
        $errors[] = 'Invalid title. Must be 1-255 characters.';
    }

    // Date validation
    try {
        $start = new DateTime($data['start_datetime']);
        if (!empty($data['end_datetime'])) {
            $end = new DateTime($data['end_datetime']);
            if ($end < $start) {
                $errors[] = 'End date must be after start date.';
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Invalid date format.';
    }

    // Category validation
    $validCategories = ['Defense', 'Meeting', 'Call', 'Academic', 'Personal', 'Other'];
    if (!in_array($data['category'], $validCategories)) {
        $errors[] = 'Invalid event category.';
    }

    return $errors;
}
