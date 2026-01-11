<?php

if (!function_exists('ensureEndorsementRequestsTable')) {
    function ensureEndorsementRequestsTable(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS endorsement_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                adviser_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                status ENUM('Pending','Verified') DEFAULT 'Pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                verified_by INT NULL,
                verified_at TIMESTAMP NULL,
                INDEX idx_endorsement_student (student_id),
                INDEX idx_endorsement_adviser (adviser_id),
                INDEX idx_endorsement_status (status),
                CONSTRAINT fk_endorsement_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_endorsement_adviser FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_endorsement_verified_by FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ";
        $conn->query($sql);

        $ensured = true;
    }
}

if (!function_exists('fetch_final_pick_title_for_endorsement')) {
    function fetch_final_pick_title_for_endorsement(mysqli $conn, int $studentId): string
    {
        if ($studentId <= 0) {
            return '';
        }
        $sql = "
            SELECT
                cp.title,
                SUM(CASE WHEN cr.rank_order = 1 THEN 1 ELSE 0 END) AS rank_one_votes,
                SUM(CASE WHEN cr.rank_order = 2 THEN 1 ELSE 0 END) AS rank_two_votes,
                SUM(CASE WHEN cr.rank_order = 3 THEN 1 ELSE 0 END) AS rank_three_votes
            FROM concept_reviews cr
            INNER JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
            INNER JOIN concept_papers cp ON cp.id = cr.concept_paper_id
            WHERE cra.student_id = ?
              AND cr.rank_order IS NOT NULL
            GROUP BY cp.id, cp.title, cp.created_at
            HAVING (rank_one_votes > 0 OR rank_two_votes > 0 OR rank_three_votes > 0)
            ORDER BY rank_one_votes DESC, rank_two_votes DESC, rank_three_votes DESC, cp.created_at DESC
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();

        return trim((string)($row['title'] ?? ''));
    }
}
