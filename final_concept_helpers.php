<?php

function ensureFinalConceptSubmissionTable(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'final_concept_submissions'");
    $exists = $tableCheck && $tableCheck->num_rows > 0;
    if ($tableCheck) {
        $tableCheck->free();
    }
    if (!$exists) {
        $sql = "
            CREATE TABLE IF NOT EXISTS final_concept_submissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                concept_paper_id INT NOT NULL,
                final_title VARCHAR(255) NOT NULL,
                abstract TEXT NOT NULL,
                keywords VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                status ENUM('Pending','Under Review','Approved','Returned') DEFAULT 'Pending',
                remarks TEXT NULL,
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL,
                CONSTRAINT fk_final_concept_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_final_concept_paper FOREIGN KEY (concept_paper_id) REFERENCES concept_papers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        $conn->query($sql);
    }

    $ensured = true;
}

function getLatestFinalConceptSubmission(mysqli $conn, int $studentId): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM final_concept_submissions
        WHERE student_id = ?
        ORDER BY submitted_at DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetchFinalConceptSubmissionHistory(mysqli $conn, int $studentId): array
{
    $stmt = $conn->prepare("
        SELECT id, concept_paper_id, final_title, status, remarks, submitted_at, reviewed_at, file_path
        FROM final_concept_submissions
        WHERE student_id = ?
        ORDER BY submitted_at DESC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function getEligibleConceptForFinalSubmission(mysqli $conn, int $studentId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            cp.id AS concept_paper_id,
            cp.title,
            MIN(cr.rank_order) AS best_rank,
            MAX(cr.updated_at) AS last_ranked_at
        FROM concept_reviews cr
        INNER JOIN concept_reviewer_assignments cra ON cra.id = cr.assignment_id
        INNER JOIN concept_papers cp ON cp.id = cr.concept_paper_id
        WHERE cra.student_id = ?
          AND cr.rank_order IS NOT NULL
        GROUP BY cp.id, cp.title
        HAVING best_rank = 1
        ORDER BY last_ranked_at DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function getProgramChairsForStudent(mysqli $conn, int $studentId): array
{
    $stmt = $conn->prepare("SELECT program, department, college FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    $program = trim((string)($student['program'] ?? ''));
    $department = trim((string)($student['department'] ?? ''));
    $college = trim((string)($student['college'] ?? ''));

    $conditions = ["role = 'program_chairperson'"];
    $params = [];
    $types = '';

    if ($program !== '') {
        $conditions[] = "program = ?";
        $params[] = $program;
        $types .= 's';
    } elseif ($department !== '') {
        $conditions[] = "department = ?";
        $params[] = $department;
        $types .= 's';
    } elseif ($college !== '') {
        $conditions[] = "college = ?";
        $params[] = $college;
        $types .= 's';
    }

    $sql = "SELECT id FROM users WHERE " . implode(' AND ', $conditions);
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $chairIds = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $chairIds[] = (int)$row['id'];
        }
        $result->free();
    }
    $stmt->close();

    if (empty($chairIds)) {
        $fallback = $conn->query("SELECT id FROM users WHERE role = 'program_chairperson'");
        if ($fallback) {
            while ($row = $fallback->fetch_assoc()) {
                $chairIds[] = (int)$row['id'];
            }
            $fallback->free();
        }
    }

    return array_values(array_unique($chairIds));
}

function finalConceptStatusClass(string $status): string
{
    return [
        'Approved' => 'bg-success-subtle text-success',
        'Under Review' => 'bg-warning-subtle text-warning',
        'Returned' => 'bg-danger-subtle text-danger',
        'Pending' => 'bg-secondary-subtle text-secondary',
    ][$status] ?? 'bg-secondary-subtle text-secondary';
}
