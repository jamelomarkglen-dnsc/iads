<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

$allowedRoles = [
    'program_chairperson',
    'committee_chairperson',
    'committee_chair',
    'panel',
    'adviser',
];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
    header('Location: login.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? '';
$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$feedback = ['success' => null, 'error' => null];
$validationErrors = [];

if ($submissionId <= 0) {
    $_SESSION['error'] = 'Invalid submission reference.';
    header('Location: submissions.php');
    exit;
}

/**
 * Lightweight column existence checker with static cache.
 */
function columnExists(mysqli $conn, string $table, string $column, bool $refresh = false): bool
{
    static $cache = [];
    $key = "{$table}.{$column}";
    if ($refresh && isset($cache[$key])) {
        unset($cache[$key]);
    }
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $tableEscaped = $conn->real_escape_string($table);
    $columnEscaped = $conn->real_escape_string($column);
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '{$tableEscaped}'
          AND COLUMN_NAME = '{$columnEscaped}'
        LIMIT 1
    ";

    $result = $conn->query($sql);
    $exists = $result ? ($result->num_rows > 0) : false;
    if ($result) {
        $result->free();
    }

    $cache[$key] = $exists;
    return $exists;
}

/**
 * Ensure a specific column exists on a table, adding it when missing.
 */
function ensureColumnDefinition(mysqli $conn, string $table, string $column, string $definition): void
{
    if (columnExists($conn, $table, $column)) {
        return;
    }
    $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    columnExists($conn, $table, $column, true);
}

/**
 * Ensure the submission_reviews table exists so reviewers can save feedback.
 */
function ensureReviewsTable(mysqli $conn): void
{
    static $created = false;
    if ($created) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS submission_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            submission_id INT NOT NULL,
            reviewer_id INT NOT NULL,
            reviewer_role VARCHAR(50) NOT NULL,
            overall_rating TINYINT NULL,
            recommendation VARCHAR(50) NULL,
            strengths TEXT NULL,
            improvements TEXT NULL,
            suggestions TEXT NULL,
            comments TEXT NULL,
            methodology_rating TINYINT NULL,
            data_rating TINYINT NULL,
            literature_rating TINYINT NULL,
            writing_rating TINYINT NULL,
            is_draft TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY submission_reviewer (submission_id, reviewer_id),
            INDEX idx_submission (submission_id),
            INDEX idx_reviewer (reviewer_id),
            CONSTRAINT fk_reviews_submission
                FOREIGN KEY (submission_id) REFERENCES submissions(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_reviews_reviewer
                FOREIGN KEY (reviewer_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";

    $conn->query($sql);

    ensureColumnDefinition($conn, 'submission_reviews', 'reviewer_role', "`reviewer_role` VARCHAR(50) NOT NULL DEFAULT ''");
    ensureColumnDefinition($conn, 'submission_reviews', 'overall_rating', '`overall_rating` TINYINT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'recommendation', '`recommendation` VARCHAR(50) NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'strengths', '`strengths` TEXT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'improvements', '`improvements` TEXT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'suggestions', '`suggestions` TEXT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'comments', '`comments` TEXT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'methodology_rating', '`methodology_rating` TINYINT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'data_rating', '`data_rating` TINYINT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'literature_rating', '`literature_rating` TINYINT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'writing_rating', '`writing_rating` TINYINT NULL');
    ensureColumnDefinition($conn, 'submission_reviews', 'is_draft', '`is_draft` TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumnDefinition($conn, 'submission_reviews', 'created_at', '`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    ensureColumnDefinition($conn, 'submission_reviews', 'updated_at', '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    $created = true;
}
/**
 * Fetch submission details together with student info (if available).
 */
function fetch_submission(mysqli $conn, int $submissionId): ?array
{
    $fields = [
        's.id',
        's.title',
        's.type',
        's.abstract',
        's.keywords',
        's.file_path',
        's.status',
    ];

    if (columnExists($conn, 'submissions', 'created_at')) {
        $fields[] = 's.created_at';
    }

    if (columnExists($conn, 'submissions', 'updated_at')) {
        $fields[] = 's.updated_at';
    }

    $fileColumns = ['concept_file_1', 'concept_file_2', 'concept_file_3'];
    foreach ($fileColumns as $fileColumn) {
        if (columnExists($conn, 'submissions', $fileColumn)) {
            $fields[] = "s.{$fileColumn}";
        }
    }

    $studentFields = "'Unknown Student' AS student_name, '' AS student_email, NULL AS student_id";
    $joinClause = '';
    if (columnExists($conn, 'submissions', 'student_id')) {
        $fields[] = 's.student_id';
        $studentFields = "
            CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS student_name,
            COALESCE(u.email, '') AS student_email,
            u.id AS student_id
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
    return $submission ?: null;
}
/**
 * Fetch the current user's review for the submission, if it exists.
 */
function fetch_reviewer_review(mysqli $conn, int $submissionId, int $reviewerId): ?array
{
    $sql = "
        SELECT id, submission_id, reviewer_id, reviewer_role, overall_rating, recommendation,
               strengths, improvements, suggestions, comments,
               methodology_rating, data_rating, literature_rating, writing_rating,
               is_draft, created_at, updated_at
        FROM submission_reviews
        WHERE submission_id = ? AND reviewer_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $submissionId, $reviewerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $review = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $review ?: null;
}

/**
 * Fetch all reviews associated with the submission for history display.
 */
function fetch_review_history(mysqli $conn, int $submissionId): array
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

function parseRating(?string $value): ?int
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    $int = (int)$value;
    if ($int < 1 || $int > 5) {
        return null;
    }
    return $int;
}
ensureReviewsTable($conn);

$submission = fetch_submission($conn, $submissionId);
if (!$submission) {
    $_SESSION['error'] = 'Submission could not be found or was removed.';
    header('Location: submissions.php');
    exit;
}

$review = fetch_reviewer_review($conn, $submissionId, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $isDraft = ($action === 'save_draft');

    $overallRating = parseRating($_POST['overall_rating'] ?? null);
    $recommendation = trim($_POST['recommendation'] ?? '');
    $strengths = trim($_POST['strengths'] ?? '');
    $improvements = trim($_POST['improvements'] ?? '');
    $suggestions = trim($_POST['suggestions'] ?? '');
    $comments = trim($_POST['comments'] ?? '');
    $methodologyRating = parseRating($_POST['methodology_rating'] ?? null);
    $dataRating = parseRating($_POST['data_rating'] ?? null);
    $literatureRating = parseRating($_POST['literature_rating'] ?? null);
    $writingRating = parseRating($_POST['writing_rating'] ?? null);

    if (!$isDraft && $recommendation === '') {
        $validationErrors[] = 'Recommendation selection is required when submitting a review.';
    }

    if (empty($validationErrors)) {
        $recommendationValue = $recommendation !== '' ? $recommendation : null;
        $isDraftValue = $isDraft ? 1 : 0;

        if ($review) {
            $sql = "
                UPDATE submission_reviews
                SET overall_rating = ?,
                    recommendation = ?,
                    strengths = ?,
                    improvements = ?,
                    suggestions = ?,
                    comments = ?,
                    methodology_rating = ?,
                    data_rating = ?,
                    literature_rating = ?,
                    writing_rating = ?,
                    is_draft = ?
                WHERE id = ?
            ";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(
                    'isssssiiiiii',
                    $overallRating,
                    $recommendationValue,
                    $strengths,
                    $improvements,
                    $suggestions,
                    $comments,
                    $methodologyRating,
                    $dataRating,
                    $literatureRating,
                    $writingRating,
                    $isDraftValue,
                    $review['id']
                );
                $ok = $stmt->execute();
                $stmt->close();
            } else {
                $ok = false;
            }
        } else {
            $sql = "
                INSERT INTO submission_reviews (
                    submission_id,
                    reviewer_id,
                    reviewer_role,
                    overall_rating,
                    recommendation,
                    strengths,
                    improvements,
                    suggestions,
                    comments,
                    methodology_rating,
                    data_rating,
                    literature_rating,
                    writing_rating,
                    is_draft
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param(
                    'iisisssssiiiii',
                    $submissionId,
                    $userId,
                    $userRole,
                    $overallRating,
                    $recommendationValue,
                    $strengths,
                    $improvements,
                    $suggestions,
                    $comments,
                    $methodologyRating,
                    $dataRating,
                    $literatureRating,
                    $writingRating,
                    $isDraftValue
                );
                $ok = $stmt->execute();
                $stmt->close();
            } else {
                $ok = false;
            }
        }

        if (!empty($ok)) {
            $review = fetch_reviewer_review($conn, $submissionId, $userId);
            $reviewHistory = fetch_review_history($conn, $submissionId);
            $feedback['success'] = $isDraft ? 'Draft saved successfully.' : 'Review submitted successfully.';

            if (!$isDraft && !empty($submission['student_id'])) {
                $titleSnippet = $submission['title'] ?? 'your submission';
                notify_user(
                    $conn,
                    (int)$submission['student_id'],
                    'Review submitted',
                    "A review has been submitted for \"{$titleSnippet}\".",
                    'student_dashboard.php'
                );
            }
        } else {
            $feedback['error'] = 'Unable to save review. Please try again.';
        }
    } else {
        $feedback['error'] = implode(' ', $validationErrors);
    }
}

$reviewHistory = $reviewHistory ?? fetch_review_history($conn, $submissionId);
$createdAt = $submission['created_at'] ?? null;
$submittedLabel = $createdAt ? date('F j, Y \\a\\t g:i A', strtotime($createdAt)) : 'Not recorded';
$downloadLink = !empty($submission['file_path']) ? $submission['file_path'] : '';
$abstract = $submission['abstract'] ?? 'No abstract provided.';
$status = $submission['status'] ?? 'Pending';
$type = $submission['type'] ?? 'Concept Paper';
$studentName = trim($submission['student_name'] ?? 'Unknown Student');
$studentEmail = $submission['student_email'] ?? '';
$submittedDate = $createdAt ? date('F j, Y', strtotime($createdAt)) : 'Not recorded';
$submittedTime = $createdAt ? date('g:i A', strtotime($createdAt)) : 'Not recorded';

$submissionFiles = [];
$fileSlots = [
    'concept_file_1' => 'Concept Paper 1',
    'concept_file_2' => 'Concept Paper 2',
    'concept_file_3' => 'Concept Paper 3',
];
foreach ($fileSlots as $field => $label) {
    $filePath = trim($submission[$field] ?? '');
    if ($filePath === '') {
        continue;
    }
    $pathPart = parse_url($filePath, PHP_URL_PATH);
    $fileName = $pathPart !== null ? basename($pathPart) : basename($filePath);
    $submissionFiles[] = [
        'label' => $label,
        'path' => $filePath,
        'name' => $fileName,
    ];
}
if (empty($submissionFiles) && $downloadLink !== '') {
    $pathPart = parse_url($downloadLink, PHP_URL_PATH);
    $fallbackName = $pathPart !== null ? basename($pathPart) : basename($downloadLink);
    $submissionFiles[] = [
        'label' => 'Concept Paper',
        'path' => $downloadLink,
        'name' => $fallbackName,
    ];
}
$activePreview = $submissionFiles[0]['path'] ?? '';

$currentOverall = $review['overall_rating'] ?? null;
$currentRecommendation = $review['recommendation'] ?? '';
$currentStrengths = $review['strengths'] ?? '';
$currentImprovements = $review['improvements'] ?? '';
$currentSuggestions = $review['suggestions'] ?? '';
$currentComments = $review['comments'] ?? '';
$currentMethodology = $review['methodology_rating'] ?? null;
$currentData = $review['data_rating'] ?? null;
$currentLiterature = $review['literature_rating'] ?? null;
$currentWriting = $review['writing_rating'] ?? null;
$isDraftExisting = isset($review['is_draft']) ? (int)$review['is_draft'] === 1 : true;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Review Submission - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="progchair.css" rel="stylesheet">
    <style>
        .content {
            margin-left: 220px;
            padding: 20px;
            transition: margin-left .3s;
            background: #f8f9fa;
            min-height: 100vh;
        }
        #sidebar.collapsed~.content {
            margin-left: 60px;
        }
        .page-heading {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        .heading-title {
            font-weight: 700;
            font-size: 1.65rem;
            margin: 0;
        }
        .back-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            width: fit-content;
            padding: 0.4rem 1rem;
            border-radius: 999px;
            background: #e3f1e6;
            color: #16562c;
            text-decoration: none;
            font-weight: 500;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            margin-top: 0;
        }
        .submission-card-header {
            background: #16562c;
            color: #fff;
            font-weight: 600;
        }
        .status-chip {
            background: rgba(22, 86, 44, 0.1);
            color: #16562c;
            padding: 0.25rem 0.8rem;
            border-radius: 999px;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .meta-grid {
            display: flex;
            gap: 1rem;
            margin-top: 1.2rem;
            overflow-x: auto;
            padding-bottom: 0.25rem;
            flex-wrap: nowrap;
        }
        .meta-item {
            background: #f6f8f6;
            border: 1px solid rgba(22, 86, 44, 0.08);
            border-radius: 10px;
            padding: 0.9rem 1rem;
            min-width: 190px;
            flex: 1 1 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        @media (max-width: 991.98px) {
            .meta-grid {
                flex-wrap: wrap;
            }
            .meta-item {
                min-width: calc(50% - 0.5rem);
            }
        }
        .meta-label {
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            color: #6c757d;
            margin-bottom: 0.1rem;
        }
        .meta-value {
            margin: 0;
            font-weight: 600;
            color: #1f2833;
        }
        .badge-soft {
            background-color: rgba(22, 86, 44, 0.12);
            color: #16562c;
        }
        .dashboard-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .preview-card .card-body {
            padding: 1.5rem;
        }
        .file-preview-grid {
            display: flex;
            flex-direction: column;
            gap: 1.2rem;
        }
        @media (min-width: 992px) {
            .file-preview-grid {
                flex-direction: row;
                align-items: stretch;
            }
        }
        .file-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            flex: 0 0 170px;
        }
        .file-item {
            border: 1px solid rgba(22, 86, 44, 0.15);
            border-radius: 10px;
            padding: 0.9rem 1rem;
            cursor: pointer;
            background: #fff;
            transition: all 0.2s ease;
            outline: none;
        }
        .file-item.active,
        .file-item:hover {
            background: #16562c;
            color: #fff;
            border-color: #16562c;
        }
        .file-item .file-label {
            font-weight: 600;
        }
        .file-item small {
            display: block;
            color: inherit;
            opacity: 0.9;
        }
        .preview-pane {
            flex: 1 1 0;
            border: 1px solid rgba(22, 86, 44, 0.15);
            border-radius: 12px;
            background: #f4f8f5;
            padding: 0.75rem;
            min-height: 360px;
            display: flex;
            flex-direction: column;
        }
        @media (min-width: 992px) {
            .preview-pane {
                min-height: 520px;
            }
        }
        .preview-pane iframe {
            flex: 1 1 auto;
            width: 100%;
            min-height: 320px;
            border: none;
            border-radius: 8px;
            background: #fff;
        }
        .preview-placeholder {
            margin: auto;
            text-align: center;
            color: #6c757d;
        }
        .preview-toolbar {
            text-align: right;
            margin-top: 0.5rem;
        }
        .file-list-link {
            text-align: left;
            border-top: 1px dashed rgba(22, 86, 44, 0.25);
            padding-top: 0.6rem;
            margin-top: 0.6rem;
        }
        .preview-link {
            font-size: 0.9rem;
            color: #16562c;
            text-decoration: none;
            font-weight: 600;
        }
        .preview-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>

    <div class="content">
        <div class="container my-4">
            <div class="page-heading mb-3">
                <a href="submissions.php?view=all" class="back-chip mb-1 mt-0">
                        <i class="bi bi-arrow-left"></i>
                        Back to Submissions
                </a>
                <h3 class="heading-title">Review Submission</h3>
            </div>

            <?php if (!empty($feedback['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($feedback['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php elseif (!empty($feedback['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($feedback['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <div class="dashboard-stack">
                <div class="card shadow-sm">
                    <div class="card-header submission-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-person-lines-fill me-2"></i> Submission Snapshot</span>
                        <span class="status-chip"><?= htmlspecialchars($status); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between flex-wrap gap-3">
                            <div>
                                <h4 class="text-primary mb-1"><?= htmlspecialchars($submission['title'] ?? 'Untitled Submission'); ?></h4>
                                <p class="mb-1 text-muted">
                                    <strong class="text-dark">Student:</strong>
                                    <?= htmlspecialchars($studentName); ?>
                                    <?php if ($studentEmail !== ''): ?>
                                        <span class="text-muted">(<?= htmlspecialchars($studentEmail); ?>)</span>
                                    <?php endif; ?>
                                </p>
                                <p class="mb-0 text-muted"><strong class="text-dark">Submitted:</strong> <?= htmlspecialchars($submittedLabel); ?></p>
                            </div>
                        </div>
                        <div class="meta-grid">
                            <div class="meta-item">
                                <div class="meta-label">Student Name</div>
                                <p class="meta-value"><?= htmlspecialchars($studentName); ?></p>
                            </div>
                            <div class="meta-item">
                                <div class="meta-label">Date Submitted</div>
                                <p class="meta-value"><?= htmlspecialchars($submittedDate); ?></p>
                            </div>
                            <div class="meta-item">
                                <div class="meta-label">Time Submitted</div>
                                <p class="meta-value"><?= htmlspecialchars($submittedTime); ?></p>
                            </div>
                            <div class="meta-item">
                                <div class="meta-label">Paper Type</div>
                                <p class="meta-value"><?= htmlspecialchars(ucwords($type)); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card shadow-sm preview-card">
                    <div class="card-header submission-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-file-earmark-pdf me-2"></i> Submission Files</span>
                        <span class="badge bg-light text-dark"><?= count($submissionFiles); ?> files</span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($submissionFiles)): ?>
                            <div class="file-preview-grid">
                                <div class="file-list">
                                    <?php foreach ($submissionFiles as $index => $file): ?>
                                        <div class="file-item<?= $index === 0 ? ' active' : ''; ?>" tabindex="0" role="button" data-preview-file="<?= htmlspecialchars($file['path'], ENT_QUOTES); ?>">
                                            <div class="file-label"><?= htmlspecialchars($file['label']); ?></div>
                                            <small><?= htmlspecialchars($file['name']); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="file-list-link preview-toolbar w-100">
                                        <a href="<?= htmlspecialchars($activePreview, ENT_QUOTES); ?>" class="preview-link" id="previewExternalLink" target="_blank" rel="noopener">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Open file in new tab
                                        </a>
                                    </div>
                                </div>
                                <div class="preview-pane">
                                    <iframe id="pdfPreviewFrame" src="<?= htmlspecialchars($activePreview, ENT_QUOTES); ?>" title="PDF preview" loading="lazy"></iframe>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">No PDF files are attached to this submission.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const fileItems = document.querySelectorAll('[data-preview-file]');
            const previewFrame = document.getElementById('pdfPreviewFrame');
            const previewLink = document.getElementById('previewExternalLink');
            if (!fileItems.length || !previewFrame) {
                return;
            }

            const setActiveFile = (target) => {
                const file = target.getAttribute('data-preview-file');
                if (!file) {
                    return;
                }
                fileItems.forEach((item) => item.classList.remove('active'));
                target.classList.add('active');
                previewFrame.src = file;
                if (previewLink) {
                    previewLink.href = file;
                }
            };

            fileItems.forEach((item) => {
                item.addEventListener('click', () => setActiveFile(item));
                item.addEventListener('keypress', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        setActiveFile(item);
                    }
                });
            });
        })();
    </script>
</body>

</html>
