<?php
session_start();
require_once 'db.php';
require_once 'concept_review_helpers.php';
require_once 'notifications_helper.php';
require_once 'final_defense_submission_helpers.php';
require_once 'final_hardbound_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chairperson') {
    header('Location: login.php');
    exit;
}

ensureConceptReviewTables($conn);
ensureResearchArchiveSupport($conn);
ensureFinalDefenseSubmissionTable($conn);
ensureFinalHardboundTables($conn);

$chairId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$messageType = 'success';

function backfill_final_defense_archive_ready(mysqli $conn): void
{
    if (!function_exists('final_defense_submission_column_exists')) {
        return;
    }
    if (!final_defense_submission_column_exists($conn, 'archive_ready_at')) {
        return;
    }
    $conn->query("
        UPDATE final_defense_submissions
        SET archive_ready_at = COALESCE(reviewed_at, submitted_at, NOW())
        WHERE status = 'Passed' AND archive_ready_at IS NULL
    ");
}

backfill_final_defense_archive_ready($conn);

function fetchEligibleSubmissions(mysqli $conn): array
{
    $sql = "
        SELECT s.id, s.student_id, s.title, s.type, s.status, s.keywords, s.file_path, s.abstract,
               CONCAT(u.firstname, ' ', u.lastname) AS student_name,
               fhs.id AS hardbound_submission_id,
               fha.file_path AS archive_file_path,
               fha.original_filename AS archive_original_filename
        FROM submissions s
        JOIN final_hardbound_submissions fhs ON fhs.submission_id = s.id
        JOIN final_hardbound_archive_uploads fha ON fha.hardbound_submission_id = fhs.id
        LEFT JOIN users u ON s.student_id = u.id
        WHERE fhs.status IN ('Passed','Verified')
          AND fha.status = 'Pending'
          AND NOT EXISTS (
            SELECT 1
            FROM final_hardbound_submissions newer
            WHERE newer.submission_id = fhs.submission_id
              AND newer.id > fhs.id
          )
          AND NOT EXISTS (
            SELECT 1 FROM research_archive ra WHERE ra.submission_id = s.id
          )
        ORDER BY fhs.reviewed_at DESC, fhs.submitted_at DESC, s.created_at DESC
        LIMIT 20
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows ?: [];
}

function fetchArchiveEntries(mysqli $conn, string $search = '', string $typeFilter = ''): array
{
    $conditions = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $conditions[] = "(ra.title LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= 'ss';
    }

    if ($typeFilter !== '') {
        $conditions[] = "ra.doc_type = ?";
        $params[] = $typeFilter;
        $types .= 's';
    }

    $sql = "
        SELECT ra.*, CONCAT(u.firstname, ' ', u.lastname) AS student_name,
               CONCAT(arch.firstname, ' ', arch.lastname) AS archived_by_name
        FROM research_archive ra
        LEFT JOIN users u ON ra.student_id = u.id
        LEFT JOIN users arch ON ra.archived_by = arch.id
    ";
    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY ra.archived_at DESC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows ?: [];
}

function storeArchiveFile(?array $upload): ?string
{
    if (!$upload || !isset($upload['tmp_name']) || $upload['tmp_name'] === '') {
        return null;
    }
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $targetDir = __DIR__ . '/archive_uploads';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }
    $ext = pathinfo($upload['name'], PATHINFO_EXTENSION);
    $filename = uniqid('archive_', true) . ($ext ? '.' . $ext : '');
    $targetPath = $targetDir . '/' . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
        return null;
    }
    return 'archive_uploads/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_submission'])) {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $publicationType = trim($_POST['publication_type'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($submissionId <= 0) {
        $message = 'Invalid submission reference.';
        $messageType = 'danger';
    } else {
        $stmt = $conn->prepare("SELECT * FROM submissions WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $submissionId);
        $stmt->execute();
        $submission = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$submission) {
            $message = 'Submission not found.';
            $messageType = 'danger';
        } else {
            $pendingUpload = fetch_pending_final_hardbound_archive_upload_for_submission($conn, $submissionId);
            if (!$pendingUpload) {
                $message = 'Student archive upload is required before archiving.';
                $messageType = 'warning';
            } else {
                $storedPath = trim((string)($pendingUpload['file_path'] ?? ''));
                $uploadedPath = storeArchiveFile($_FILES['archive_file'] ?? null);
                if ($uploadedPath !== null) {
                    $storedPath = $uploadedPath;
                }
                if ($storedPath === '') {
                    $message = 'No document available to archive. Please upload the final file.';
                    $messageType = 'warning';
                } else {
                    $archiveStmt = $conn->prepare("
                        INSERT INTO research_archive (submission_id, student_id, title, doc_type, publication_type, file_path, keywords, abstract, notes, archived_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                $docType = $submission['type'] ?? 'Concept Paper';
                $keywords = $submission['keywords'] ?? '';
                $abstract = $submission['abstract'] ?? null;
                $archiveStmt->bind_param(
                    'iisssssssi',
                    $submissionId,
                    $submission['student_id'],
                    $submission['title'],
                    $docType,
                    $publicationType,
                    $storedPath,
                    $keywords,
                    $abstract,
                    $notes,
                    $chairId
                );
                    if ($archiveStmt->execute()) {
                        $conn->query("UPDATE submissions SET archived_at = NOW() WHERE id = {$submissionId}");

                        $markStmt = $conn->prepare("
                            UPDATE final_hardbound_archive_uploads
                            SET status = 'Archived', archived_at = NOW(), archived_by = ?
                            WHERE id = ?
                        ");
                        if ($markStmt) {
                            $pendingUploadId = (int)$pendingUpload['id'];
                            $markStmt->bind_param('ii', $chairId, $pendingUploadId);
                            $markStmt->execute();
                            $markStmt->close();
                        }

                        notify_user(
                            $conn,
                            (int)$submission['student_id'],
                            'Research archived',
                            'Your approved research has been archived for publication reference.',
                            'student_dashboard.php'
                        );
                        $archivedTitle = trim((string)($submission['title'] ?? 'Research document'));
                        notify_role(
                            $conn,
                            'dean',
                            'Research archived',
                            "{$archivedTitle} has been archived and is ready in the archive catalog.",
                            'archive_library.php'
                        );
                        $message = 'Submission archived successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Unable to archive submission. Please try again.';
                        $messageType = 'danger';
                    }
                    $archiveStmt->close();
                }
            }
        }
    }
}

$eligibleSubmissions = fetchEligibleSubmissions($conn);
$searchQuery = trim($_GET['search_archive'] ?? '');
$typeFilter = trim($_GET['type_filter'] ?? '');
$archiveEntries = fetchArchiveEntries($conn, $searchQuery, $typeFilter);

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Archive Manager - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f6f8fb; }
        .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .archive-card { border-radius: 16px; border: none; box-shadow: 0 18px 36px rgba(22,86,44,0.08); }
        .archive-card .card-header { background: linear-gradient(135deg, #16562c, #0f3d1f); color: #fff; border-radius: 16px 16px 0 0; }
        .table thead { text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.08em; }
        .badge-status { font-size: 0.75rem; }
        .filter-card { border-radius: 16px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h2 class="fw-bold text-success mb-1"><i class="bi bi-archive me-2"></i>Archive Manager</h2>
                <p class="text-muted mb-0">Store approved research documents for dean and board references.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card archive-card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Ready for Archive</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($eligibleSubmissions)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inboxes fs-2 mb-2"></i>
                                <p class="mb-0">No approved submissions waiting for archiving.</p>
                            </div>
                        <?php else: ?>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="archive_submission" value="1">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold text-success">Select Submission</label>
                                    <select name="submission_id" id="archiveSubmissionSelect" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php foreach ($eligibleSubmissions as $submission): ?>
                                            <option value="<?= (int)$submission['id']; ?>"
                                                data-archive-file="<?= htmlspecialchars($submission['archive_file_path'] ?? ''); ?>">
                                                <?= htmlspecialchars($submission['title']); ?> (<?= htmlspecialchars($submission['student_name']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="mt-2">
                                        <a id="archiveFileLink" class="btn btn-sm btn-outline-success" href="#" target="_blank" style="display: none;">
                                            View student archive upload
                                        </a>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold text-success">Publication Type</label>
                                    <input type="text" name="publication_type" class="form-control" placeholder="e.g., Journal, Hardbound, Capstone Book">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold text-success">Upload Final Document (optional)</label>
                                    <input type="file" name="archive_file" class="form-control" accept=".pdf,.doc,.docx">
                                    <small class="text-muted d-block mt-1">Leave blank to reuse the student's final upload.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold text-success">Notes</label>
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Remarks or routing instructions"></textarea>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-success"><i class="bi bi-archive me-1"></i>Archive Submission</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card filter-card mb-3">
                    <div class="card-body">
                        <form class="row g-3" method="get">
                            <div class="col-md-7">
                                <label class="form-label fw-semibold text-success">Search Archive</label>
                                <input type="search" name="search_archive" value="<?= htmlspecialchars($searchQuery); ?>" class="form-control" placeholder="Search by student or title">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-success">Document Type</label>
                                <select name="type_filter" class="form-select">
                                    <option value="">All types</option>
                                    <?php foreach (['Concept Paper','Thesis','Dissertation','Capstone'] as $type): ?>
                                        <option value="<?= $type; ?>" <?= $typeFilter === $type ? 'selected' : ''; ?>><?= $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1 d-grid">
                                <button class="btn btn-outline-success"><i class="bi bi-search"></i></button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card archive-card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-archive-fill me-2"></i>Archive Catalog</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($archiveEntries)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-2 mb-2"></i>
                                <p class="mb-0">No archived documents yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Student</th>
                                            <th>Type</th>
                                            <th>Publication</th>
                                            <th>Archived At</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($archiveEntries as $entry): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($entry['title']); ?></strong>
                                                    <?php if (!empty($entry['notes'])): ?>
                                                        <div class="text-muted small"><?= htmlspecialchars($entry['notes']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($entry['student_name'] ?? 'Student'); ?></td>
                                                <td><?= htmlspecialchars($entry['doc_type']); ?></td>
                                                <td><?= htmlspecialchars($entry['publication_type'] ?? '—'); ?></td>
                                                <td>
                                                    <?= htmlspecialchars(date('M d, Y g:i A', strtotime($entry['archived_at']))); ?>
                                                    <?php if (!empty($entry['archived_by_name'])): ?>
                                                        <div class="text-muted small">By <?= htmlspecialchars($entry['archived_by_name']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if (!empty($entry['file_path'])): ?>
                                                        <a href="<?= htmlspecialchars($entry['file_path']); ?>" class="btn btn-sm btn-outline-success" target="_blank"><i class="bi bi-download"></i></a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const archiveSelect = document.getElementById('archiveSubmissionSelect');
    const archiveLink = document.getElementById('archiveFileLink');
    if (archiveSelect && archiveLink) {
        const updateArchiveLink = () => {
            const option = archiveSelect.options[archiveSelect.selectedIndex];
            const filePath = option ? option.getAttribute('data-archive-file') : '';
            if (filePath) {
                archiveLink.href = filePath;
                archiveLink.style.display = 'inline-block';
            } else {
                archiveLink.href = '#';
                archiveLink.style.display = 'none';
            }
        };
        archiveSelect.addEventListener('change', updateArchiveLink);
        updateArchiveLink();
    }
</script>
</body>
</html>
