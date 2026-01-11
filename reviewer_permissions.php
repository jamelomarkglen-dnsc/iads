<?php
class ReviewerPermissions {
    private $conn;
    private $userId;

    public function __construct(mysqli $connection, int $userId) {
        $this->conn = $connection;
        $this->userId = $userId;
    }

    /**
     * Check if user can view concept papers
     * @param int $conceptPaperId
     * @return bool
     */
    public function canViewConceptPaper(int $conceptPaperId): bool {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count 
            FROM concept_reviewer_assignments 
            WHERE reviewer_id = ? AND concept_paper_id = ?
        ");
        $stmt->bind_param('ii', $this->userId, $conceptPaperId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'] > 0;
    }

    /**
     * Check if user can submit review
     * @param int $assignmentId
     * @return bool
     */
    public function canSubmitReview(int $assignmentId): bool {
        $stmt = $this->conn->prepare("
            SELECT status 
            FROM concept_reviewer_assignments 
            WHERE reviewer_id = ? AND id = ?
        ");
        $stmt->bind_param('ii', $this->userId, $assignmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignment = $result->fetch_assoc();
        
        return $assignment && 
               in_array($assignment['status'], ['pending', 'in_progress']);
    }

    /**
     * Get assigned concept papers
     * @return array
     */
    public function getAssignedConceptPapers(): array {
        $stmt = $this->conn->prepare("
            SELECT 
                cp.id, 
                cp.title, 
                u.firstname, 
                u.lastname,
                cra.status,
                cra.id as assignment_id
            FROM concept_reviewer_assignments cra
            JOIN concept_papers cp ON cp.id = cra.concept_paper_id
            JOIN users u ON u.id = cp.student_id
            WHERE cra.reviewer_id = ?
            ORDER BY cra.created_at DESC
        ");
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        while ($row = $result->fetch_assoc()) {
            $assignments[] = $row;
        }
        return $assignments;
    }

    /**
     * Log reviewer actions
     * @param string $action
     * @param int $conceptPaperId
     */
    public function logAction(string $action, int $conceptPaperId): void {
        $stmt = $this->conn->prepare("
            INSERT INTO reviewer_action_logs 
            (user_id, action, concept_paper_id, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->bind_param('iss', $this->userId, $action, $conceptPaperId);
        $stmt->execute();
    }

    /**
     * Ensure necessary database infrastructure
     * @param mysqli $conn
     */
    public static function ensureInfrastructure(mysqli $conn): void {
        $conn->query("
            CREATE TABLE IF NOT EXISTS reviewer_action_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(100) NOT NULL,
                concept_paper_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_action (user_id, action),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (concept_paper_id) REFERENCES concept_papers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

// Optional: Initialization function to be called during user login or role switch
function initializeReviewerPermissions(mysqli $conn, int $userId): ReviewerPermissions {
    ReviewerPermissions::ensureInfrastructure($conn);
    return new ReviewerPermissions($conn, $userId);
}
?>