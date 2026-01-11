<?php
session_start();
require_once 'db.php';

$allowedRoles = [
    'program_chairperson',
    'faculty',
    'committee_chair',
    'committee_chairperson',
    'adviser',
    'panel',
];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$table}'
          AND COLUMN_NAME = '{$column}'
        LIMIT 1
    ";
    $result = $conn->query($sql);
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

function fetchSubmission(mysqli $conn, int $submissionId): ?array
{
    if ($submissionId <= 0) {
        return null;
    }

    $fields = [
        's.id',
        's.title',
        's.type',
        's.abstract',
        's.keywords',
        's.status',
        's.file_path',
    ];

    if (columnExists($conn, 'submissions', 'description')) {
        $fields[] = 's.description';
    }
    if (columnExists($conn, 'submissions', 'created_at')) {
        $fields[] = 's.created_at';
    }
    if (columnExists($conn, 'submissions', 'updated_at')) {
        $fields[] = 's.updated_at';
    }

    $studentFields = "'Unknown Student' AS student_name, '' AS student_email, '' AS student_contact";
    $joinClause = '';
    if (columnExists($conn, 'submissions', 'student_id')) {
        $fields[] = 's.student_id';
        $studentFields = "
            CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name,
            COALESCE(u.email, '') AS student_email,
            COALESCE(u.contact, '') AS student_contact
        ";
        $joinClause = 'LEFT JOIN users u ON u.id = s.student_id';
    }

    $fieldSql = implode(', ', $fields);
    $sql = "
        SELECT {$fieldSql}, {$studentFields}
        FROM submissions s
        {$joinClause}
        WHERE s.id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $submission = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$submission) {
        return null;
    }
    $submission['source'] = 'submission';
    return $submission;
}

function fetchConceptPaperFallback(mysqli $conn, int $paperId): ?array
{
    if ($paperId <= 0 || !columnExists($conn, 'concept_papers', 'id')) {
        return null;
    }

    $fields = [
        'cp.id',
        'cp.title',
        'cp.description',
        'cp.assigned_faculty',
        'cp.created_at',
    ];

    foreach (['abstract', 'keywords', 'file_path', 'status', 'updated_at'] as $optional) {
        if (columnExists($conn, 'concept_papers', $optional)) {
            $fields[] = "cp.{$optional}";
        }
    }

    $studentFields = "
        CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name,
        COALESCE(u.email, '') AS student_email,
        COALESCE(u.contact, '') AS student_contact
    ";
    $sql = "
        SELECT " . implode(', ', $fields) . ", {$studentFields}
        FROM concept_papers cp
        LEFT JOIN users u ON u.id = cp.student_id
        WHERE cp.id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $paperId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'id' => $row['id'],
        'title' => $row['title'] ?? 'Concept Paper',
        'type' => 'Concept Paper',
        'abstract' => $row['abstract'] ?? ($row['description'] ?? ''),
        'keywords' => $row['keywords'] ?? '',
        'status' => $row['status'] ?? 'Pending',
        'file_path' => $row['file_path'] ?? '',
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'student_name' => $row['student_name'] ?? 'Unknown Student',
        'student_email' => $row['student_email'] ?? '',
        'student_contact' => $row['student_contact'] ?? '',
        'description' => $row['description'] ?? '',
        'assigned_faculty' => $row['assigned_faculty'] ?? '',
        'source' => 'concept',
    ];
}

function fetchReviewHistory(mysqli $conn, int $submissionId): array
{
    $sql = "
        SELECT r.id,
               r.reviewer_role,
               r.overall_rating,
               r.recommendation,
               r.is_draft,
               r.updated_at,
               CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS reviewer_name
        FROM submission_reviews r
        LEFT JOIN users u ON u.id = r.reviewer_id
        WHERE r.submission_id = ?
        ORDER BY r.updated_at DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows ?: [];
}

function formatDateToReadable(?string $date): string
{
    if (!$date) {
        return 'Not specified';
    }
    try {
        $dt = new DateTimeImmutable($date);
        return $dt->format('F d, Y g:i A');
    } catch (Exception $e) {
        return $date;
    }
}

function formatKeywords(string $keywords): array
{
    $parts = preg_split('/[,;]+/', $keywords);
    $parts = array_filter(array_map('trim', $parts));
    return array_values($parts);
}

function humanFileSize(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($bytes, 1024));
    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$submission = $submissionId > 0 ? fetchSubmission($conn, $submissionId) : null;
$reviews = [];

if (!$submission) {
    $submission = fetchConceptPaperFallback($conn, $submissionId);
}

if ($submission && ($submission['source'] ?? '') === 'submission') {
    $reviews = fetchReviewHistory($conn, (int)$submission['id']);
}
$reviewCount = count($reviews);

$errorMessage = '';
if (!$submission) {
    $errorMessage = 'We could not find the requested concept paper.';
}

$title = $submission['title'] ?? 'Concept Paper';
$studentName = trim($submission['student_name'] ?? '');
if ($studentName === '') {
    $studentName = 'Unknown Student';
}
$studentEmail = $submission['student_email'] ?? 'Not provided';
$studentContact = $submission['student_contact'] ?? 'Not provided';
$status = $submission['status'] ?? 'Pending';
$type = $submission['type'] ?? 'Concept Paper';
$abstract = $submission['abstract'] ?? ($submission['description'] ?? 'No abstract provided.');
$keywords = formatKeywords($submission['keywords'] ?? '');
$submittedAt = formatDateToReadable($submission['created_at'] ?? null);
$updatedAt = formatDateToReadable($submission['updated_at'] ?? null);
$filePath = $submission['file_path'] ?? '';
$fileExists = $filePath !== '' && is_file($filePath);
$fileSize = $fileExists ? humanFileSize(filesize($filePath)) : null;
$downloadUrl = $filePath !== '' ? $filePath : '#';
$downloadDisabledClass = $filePath === '' ? ' disabled' : '';
$downloadDisabledAttr = $filePath === '' ? ' tabindex="-1" aria-disabled="true"' : '';

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Concept Paper</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .page-header h1 { font-size: 1.75rem; margin: 0; }
        .status-badge { border-radius: 999px; padding: 0.35rem 0.9rem; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .card-rounded { border: none; border-radius: 22px; box-shadow: 0 18px 45px rgba(15, 61, 31, 0.08); }
        .meta-label { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; }
        .keyword-badge { display: inline-block; background: rgba(22, 86, 44, 0.08); color: #16562c; border-radius: 999px; padding: 0.25rem 0.75rem; margin: 0 0.35rem 0.35rem 0; font-size: 0.85rem; }
        .review-item { border-bottom: 1px solid rgba(15, 61, 31, 0.08); padding: 0.75rem 0; }
        .review-item:last-child { border-bottom: none; }
        .empty-state { text-align: center; padding: 2rem 1rem; color: #94a3b8; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="page-header mb-4">
            <div>
                <p class="text-uppercase text-muted mb-1 fw-semibold">Review Submission</p>
                <h1 class="fw-bold text-success">Evaluate the student's concept paper and record your feedback.</h1>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="submissions.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left-short"></i> Back to Submissions</a>
            </div>
        </div>

        <?php if ($errorMessage): ?>
            <div class="alert alert-warning">
                <?= htmlspecialchars($errorMessage); ?>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card card-rounded mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between flex-wrap align-items-start gap-2 mb-3">
                                <div>
                                    <h3 class="text-primary mb-1"><?= htmlspecialchars($title); ?></h3>
                                    <p class="mb-0">
                                        <strong>Student:</strong> <?= htmlspecialchars($studentName); ?>
                                        <?php if ($studentEmail): ?>
                                            (<?= htmlspecialchars($studentEmail); ?>)
                                        <?php endif; ?>
                                    </p>
                                    <p class="mb-0"><strong>Submitted:</strong> <?= htmlspecialchars($submittedAt); ?></p>
                                    <p class="mb-0"><strong>Type:</strong> <?= htmlspecialchars($type); ?></p>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge bg-warning-subtle text-warning"><?= htmlspecialchars($status); ?></span>
                                    <div class="mt-3 d-flex flex-wrap gap-2">
                                        <a href="<?= htmlspecialchars($downloadUrl); ?>" target="_blank" class="btn btn-primary<?= $downloadDisabledClass; ?>"<?= $downloadDisabledAttr; ?>>
                                            <i class="bi bi-download"></i> Download Paper
                                        </a>
                                        <a href="<?= htmlspecialchars($downloadUrl); ?>" target="_blank" class="btn btn-outline-info<?= $downloadDisabledClass; ?>"<?= $downloadDisabledAttr; ?>>
                                            <i class="bi bi-eye"></i> View Full Details
                                        </a>
                                    </div>
                                    <?php if ($fileExists && $fileSize): ?>
                                        <small class="text-muted d-block mt-1">File size: <?= htmlspecialchars($fileSize); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <hr>
                            <section class="mb-4">
                                <h5 class="fw-bold">Abstract</h5>
                                <p class="mb-0"><?= nl2br(htmlspecialchars($abstract)); ?></p>
                            </section>
                            <section class="mb-4">
                                <h5 class="fw-bold">Keywords</h5>
                                <?php if (empty($keywords)): ?>
                                    <p class="text-muted mb-0">No keywords provided.</p>
                                <?php else: ?>
                                    <?php foreach ($keywords as $keyword): ?>
                                        <span class="keyword-badge"><?= htmlspecialchars($keyword); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card card-rounded mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="mb-1">Review Activity</h5>
                                    <small class="text-muted">Audit trail for this submission</small>
                                </div>
                                <span class="badge bg-success-subtle text-success"><?= $reviewCount; ?> total</span>
                            </div>
                            <?php if (empty($reviews)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-journal-text fs-1 d-block mb-2"></i>
                                    No reviews recorded yet for this submission.
                                </div>
                            <?php else: ?>
                                <?php foreach ($reviews as $review): ?>
                                    <div class="review-item">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong><?= htmlspecialchars($review['reviewer_name'] ?? 'Reviewer'); ?></strong>
                                                <span class="badge bg-light text-success ms-2"><?= htmlspecialchars($review['reviewer_role'] ?? 'Reviewer'); ?></span>
                                            </div>
                                            <small class="text-muted"><?= htmlspecialchars(formatDateToReadable($review['updated_at'] ?? null)); ?></small>
                                        </div>
                                        <div class="mt-1 text-muted small">
                                            <?php if (!empty($review['recommendation'])): ?>
                                                Recommendation: <strong><?= htmlspecialchars($review['recommendation']); ?></strong>
                                            <?php else: ?>
                                                Pending recommendation
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card card-rounded">
                        <div class="card-body">
                            <h5 class="fw-bold">Submission Metadata</h5>
                            <dl class="row mb-0">
                                <dt class="col-5 meta-label">Type</dt>
                                <dd class="col-7"><?= htmlspecialchars($type); ?></dd>
                                <dt class="col-5 meta-label">Submitted</dt>
                                <dd class="col-7"><?= htmlspecialchars($submittedAt); ?></dd>
                                <dt class="col-5 meta-label">Last Updated</dt>
                                <dd class="col-7"><?= htmlspecialchars($updatedAt); ?></dd>
                                <dt class="col-5 meta-label">Contact</dt>
                                <dd class="col-7"><?= htmlspecialchars($studentContact); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-rounded">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="mb-1">Actions</h5>
                        <small class="text-muted">Available actions for this concept paper</small>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="review_submission.php?id=<?= (int)$submissionId; ?>" class="btn btn-success">
                            <i class="bi bi-clipboard-check"></i> Review Submission
                        </a>
                        <a href="assign_faculty.php" class="btn btn-outline-primary">
                            <i class="bi bi-people"></i> Manage Reviewers
                        </a>
                        <button class="btn btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
