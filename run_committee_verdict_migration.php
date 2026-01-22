<?php
/**
 * Committee PDF Final Verdict Migration Runner
 * 
 * This script automatically runs the final verdict migration.
 * Run this file once in your browser to add the verdict columns.
 */

require_once 'db.php';

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>Committee Verdict Migration</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='bg-light'>
<div class='container mt-5'>
    <div class='card shadow'>
        <div class='card-header bg-success text-white'>
            <h4 class='mb-0'>Committee PDF Final Verdict Migration</h4>
        </div>
        <div class='card-body'>";

// Check if columns already exist
$check_query = "SHOW COLUMNS FROM committee_pdf_submissions LIKE 'final_verdict'";
$result = $conn->query($check_query);

if ($result && $result->num_rows > 0) {
    echo "<div class='alert alert-info'>
            <i class='bi bi-info-circle-fill me-2'></i>
            <strong>Migration already applied!</strong> The final_verdict columns already exist.
          </div>";
} else {
    echo "<h5 class='mb-3'>Running migration...</h5>";
    
    // Run the migration
    $migration_sql = "
        ALTER TABLE committee_pdf_submissions
        ADD COLUMN final_verdict ENUM(
            'pending',
            'passed',
            'passed_minor_revisions',
            'passed_major_revisions',
            'redefense',
            'failed'
        ) DEFAULT 'pending' AFTER submission_status,
        ADD COLUMN final_verdict_comments TEXT NULL AFTER final_verdict,
        ADD COLUMN final_verdict_by INT NULL AFTER final_verdict_comments,
        ADD COLUMN final_verdict_at TIMESTAMP NULL AFTER final_verdict_by,
        ADD INDEX idx_final_verdict (final_verdict);
    ";
    
    try {
        if ($conn->multi_query($migration_sql)) {
            // Clear any remaining results
            while ($conn->more_results()) {
                $conn->next_result();
            }
            
            echo "<div class='alert alert-success'>
                    <i class='bi bi-check-circle-fill me-2'></i>
                    <strong>Migration successful!</strong> Final verdict columns have been added.
                  </div>";
            
            echo "<h5 class='mt-4'>Verification:</h5>";
            echo "<div class='table-responsive'>";
            echo "<table class='table table-sm table-bordered'>";
            echo "<thead class='table-light'><tr><th>Column</th><th>Type</th><th>Default</th></tr></thead>";
            echo "<tbody>";
            
            $verify = $conn->query("SHOW COLUMNS FROM committee_pdf_submissions WHERE Field LIKE 'final_verdict%'");
            if ($verify) {
                while ($row = $verify->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td><code>" . htmlspecialchars($row['Field']) . "</code></td>";
                    echo "<td><code>" . htmlspecialchars($row['Type']) . "</code></td>";
                    echo "<td><code>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</code></td>";
                    echo "</tr>";
                }
            }
            
            echo "</tbody></table></div>";
            
        } else {
            throw new Exception($conn->error);
        }
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>
                <i class='bi bi-exclamation-triangle-fill me-2'></i>
                <strong>Migration failed!</strong><br>
                Error: " . htmlspecialchars($e->getMessage()) . "
              </div>";
    }
}

echo "
        <div class='mt-4'>
            <a href='committee_pdf_review.php' class='btn btn-success'>Go to Committee Review</a>
            <a href='student_committee_pdf_submission.php' class='btn btn-outline-secondary'>Go to Submissions</a>
        </div>
    </div>
    </div>
</div>
</body>
</html>";

$conn->close();
?>
