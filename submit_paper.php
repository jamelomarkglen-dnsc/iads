<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'progress_tracker_helper.php';
require_once 'submission_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: login.php");
    exit;
}

ensure_submission_type_schema($conn);


function columnExists(mysqli $conn, string $table, string $column): bool
{
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
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $exists;
}

function deleteRecordsByColumn(mysqli $conn, string $table, string $column, string $type, $value): void
{
    if (!columnExists($conn, $table, $column)) {
        return;
    }
    $sql = "DELETE FROM {$table} WHERE {$column} = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param($type, $value);
    $stmt->execute();
    $stmt->close();
}

function deleteNotificationsByTitle(mysqli $conn, int $userId, string $title): void
{
    if (!columnExists($conn, 'notifications', 'user_id') || !columnExists($conn, 'notifications', 'title')) {
        return;
    }
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND title = ?");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('is', $userId, $title);
    $stmt->execute();
    $stmt->close();
}

function deleteFinalPaperReviewsForStudent(mysqli $conn, int $studentId): void
{
    if (
        !columnExists($conn, 'final_paper_reviews', 'submission_id')
        || !columnExists($conn, 'final_paper_submissions', 'id')
        || !columnExists($conn, 'final_paper_submissions', 'student_id')
    ) {
        return;
    }
    $stmt = $conn->prepare("
        DELETE fpr
        FROM final_paper_reviews fpr
        JOIN final_paper_submissions fps ON fps.id = fpr.submission_id
        WHERE fps.student_id = ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->close();
}

function resetStudentProgressRecords(mysqli $conn, int $studentId): void
{
    if ($studentId <= 0) {
        return;
    }

    if (function_exists('progress_tracker_reset_student_progress')) {
        progress_tracker_reset_student_progress($conn, $studentId);
    }

    deleteRecordsByColumn($conn, 'concept_papers', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'concept_reviewer_assignments', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'final_pick_messages', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'final_concept_submissions', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'pdf_submissions', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'endorsement_requests', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'payment_proofs', 'user_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'defense_committee_requests', 'student_id', 'i', $studentId);
    deleteFinalPaperReviewsForStudent($conn, $studentId);
    deleteRecordsByColumn($conn, 'final_paper_submissions', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'notice_to_commence_requests', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'final_routing_submissions', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'defense_schedules', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'defense_outcomes', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'final_endorsement_submissions', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'final_hardbound_submissions', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'institutional_final_copies', 'student_id', 'i', $studentId);
    deleteRecordsByColumn($conn, 'research_archive', 'student_id', 'i', $studentId);
    deleteNotificationsByTitle($conn, $studentId, 'Final concept recommendation');
}

function deleteSubmissionRelatedRecords(mysqli $conn, int $submissionId): void
{
    if ($submissionId <= 0) {
        return;
    }
    deleteRecordsByColumn($conn, 'status_logs', 'submission_id', 'i', $submissionId);
    deleteRecordsByColumn($conn, 'submission_feedback', 'submission_id', 'i', $submissionId);
}

function countStudentSubmissions(mysqli $conn, int $studentId): int
{
    if ($studentId <= 0 || !columnExists($conn, 'submissions', 'student_id')) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM submissions WHERE student_id = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['total'] ?? 0);
}

function studentExists(mysqli $conn, int $studentId): bool
{
    if ($studentId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1 FROM users
        WHERE id = ? LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function ensureSubmissionProposalColumns(mysqli $conn): array
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $available = [];
    $columns = [
        'concept_proposal_1' => "ALTER TABLE submissions ADD COLUMN concept_proposal_1 VARCHAR(255) NULL AFTER keywords",
        'concept_proposal_2' => "ALTER TABLE submissions ADD COLUMN concept_proposal_2 VARCHAR(255) NULL AFTER concept_proposal_1",
        'concept_proposal_3' => "ALTER TABLE submissions ADD COLUMN concept_proposal_3 VARCHAR(255) NULL AFTER concept_proposal_2",
    ];

    foreach ($columns as $column => $alterSql) {
        if (!columnExists($conn, 'submissions', $column)) {
            $conn->query($alterSql);
        }
        if (columnExists($conn, 'submissions', $column)) {
            $available[] = $column;
        }
    }

    return $available;
}

function ensureSubmissionProposalFileColumns(mysqli $conn): array
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $available = [];
    $columns = [
        'concept_file_1' => "ALTER TABLE submissions ADD COLUMN concept_file_1 VARCHAR(255) NULL AFTER concept_proposal_3",
        'concept_file_2' => "ALTER TABLE submissions ADD COLUMN concept_file_2 VARCHAR(255) NULL AFTER concept_file_1",
        'concept_file_3' => "ALTER TABLE submissions ADD COLUMN concept_file_3 VARCHAR(255) NULL AFTER concept_file_2",
    ];

    foreach ($columns as $column => $alterSql) {
        if (!columnExists($conn, 'submissions', $column)) {
            $conn->query($alterSql);
        }
        if (columnExists($conn, 'submissions', $column)) {
            $available[] = $column;
        }
    }

    return $available;
}

function cleanupConceptProposalFiles(array $files): void
{
    foreach ($files as $path) {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}

function deleteSubmissionFiles(array $submission): void
{
    $fileColumns = ['file_path', 'concept_file_1', 'concept_file_2', 'concept_file_3'];
    $paths = [];
    foreach ($fileColumns as $column) {
        if (!empty($submission[$column])) {
            $paths[] = $submission[$column];
        }
    }
    if (!empty($paths)) {
        cleanupConceptProposalFiles($paths);
    }
}

function fetchStudentSubmission(mysqli $conn, int $submissionId, int $studentId): ?array
{
    if ($submissionId <= 0 || $studentId <= 0) {
        return null;
    }

    $columns = [
        'id',
        'student_id',
        'title',
        'type',
        'status',
        'file_path',
        'concept_proposal_1',
        'concept_proposal_2',
        'concept_proposal_3',
        'concept_file_1',
        'concept_file_2',
        'concept_file_3',
    ];

    $sql = "SELECT " . implode(', ', $columns) . " FROM submissions WHERE id = ? AND student_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $submissionId, $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $submission = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $submission ?: null;
}

function isPdfUpload(array $fileInfo): bool
{
    $extension = strtolower(pathinfo($fileInfo['name'] ?? '', PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return false;
    }

    $detectedType = '';
    if (!empty($fileInfo['tmp_name']) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedType = finfo_file($finfo, $fileInfo['tmp_name']) ?: '';
            finfo_close($finfo);
        }
    }

    $typeToCheck = $detectedType ?: ($fileInfo['type'] ?? '');
    return stripos((string)$typeToCheck, 'pdf') !== false;
}

function normalizeConceptValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/\s+/', ' ', $value);
    return strtolower($value);
}

function hasDuplicateConceptValues(array $values): bool
{
    $seen = [];
    foreach ($values as $value) {
        $normalized = normalizeConceptValue((string)$value);
        if ($normalized === '') {
            continue;
        }
        if (isset($seen[$normalized])) {
            return true;
        }
        $seen[$normalized] = true;
    }
    return false;
}

function hasDuplicateUploadedFileNames(array $fileInfos): bool
{
    $seen = [];
    foreach ($fileInfos as $fileInfo) {
        if (!$fileInfo || ($fileInfo['error'] ?? null) !== UPLOAD_ERR_OK) {
            continue;
        }
        $name = strtolower(basename((string)($fileInfo['name'] ?? '')));
        if ($name === '') {
            continue;
        }
        if (isset($seen[$name])) {
            return true;
        }
        $seen[$name] = true;
    }
    return false;
}

function bindStatementParams(mysqli_stmt $stmt, string $types, array &$params): bool
{
    $bindArgs = [$types];
    foreach ($params as $key => $value) {
        $bindArgs[] = &$params[$key];
    }
    return (bool)call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

function fetchStudentSubmissionHistory(mysqli $conn, int $studentId, int $limit = 5): array
{
    if ($studentId <= 0) {
        return [];
    }

    $columns = ['id', 'title'];
    $optionalColumns = [
        'type',
        'status',
        'created_at',
        'updated_at',
        'file_path',
        'concept_proposal_1',
        'concept_proposal_2',
        'concept_proposal_3',
    ];
    foreach ($optionalColumns as $col) {
        if (columnExists($conn, 'submissions', $col)) {
            $columns[] = $col;
        }
    }

    $orderColumn = columnExists($conn, 'submissions', 'created_at') ? 'created_at' : 'id';
    $limitValue = max(1, (int)$limit);
    $sql = "
        SELECT " . implode(', ', $columns) . "
        FROM submissions
        WHERE student_id = ?
        ORDER BY {$orderColumn} DESC
        LIMIT {$limitValue}
    ";
    $stmt = $conn->prepare($sql);
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

function formatHumanDate(?string $value): string
{
    if (!$value) {
        return 'Not recorded';
    }
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return $value;
    }
    return date('M d, Y \\a\\t g:i A', $timestamp);
}

function statusBadgeClass(string $status): string
{
    $map = [
        'Approved' => 'bg-success-subtle text-success',
        'Pending' => 'bg-secondary-subtle text-secondary',
        'Reviewing' => 'bg-warning-subtle text-warning',
        'In Review' => 'bg-warning-subtle text-warning',
        'Under Review' => 'bg-warning-subtle text-warning',
        'Reviewer Assigning' => 'bg-info-subtle text-info',
        'Assigning Reviewer' => 'bg-info-subtle text-info',
        'Revision Required' => 'bg-info-subtle text-info',
        'Rejected' => 'bg-danger-subtle text-danger',
    ];
    return $map[$status] ?? 'bg-secondary-subtle text-secondary';
}

$student_id = (int)$_SESSION['user_id'];
$studentExists = studentExists($conn, $student_id);
$success = $error = '';
if (!empty($_SESSION['flash_success'])) {
    $success = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
$formData = [
    'title' => '',
    'type' => '',
    'concept_proposal_1' => '',
    'concept_proposal_2' => '',
    'concept_proposal_3' => '',
];
$conceptFiles = [
    'concept_file_1' => null,
    'concept_file_2' => null,
    'concept_file_3' => null,
];
$action = $_POST['action'] ?? '';

$proposalColumns = ensureSubmissionProposalColumns($conn);
$proposalFileColumns = ensureSubmissionProposalFileColumns($conn);
$proposalFileColumns = ensureSubmissionProposalFileColumns($conn);
$allowedSubmissionTypes = ['Thesis', 'Capstone', 'Dissertation'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_submission') {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    if ($submissionId <= 0) {
        $error = "Invalid submission selected for removal.";
    } else {
        $submission = fetchStudentSubmission($conn, $submissionId, $student_id);
        if (!$submission) {
            $error = "The submission you are trying to remove could not be found.";
        } else {
            $deleteStmt = $conn->prepare("DELETE FROM submissions WHERE id = ? AND student_id = ?");
            if ($deleteStmt) {
                $deleteStmt->bind_param('ii', $submissionId, $student_id);
                $transactionStarted = $conn->begin_transaction();
                $deleteOk = false;
                if ($transactionStarted) {
                    deleteSubmissionRelatedRecords($conn, $submissionId);
                    if ($deleteStmt->execute()) {
                        $deleteOk = true;
                        if (countStudentSubmissions($conn, $student_id) === 0) {
                            resetStudentProgressRecords($conn, $student_id);
                        }
                    }
                    if ($deleteOk) {
                        $conn->commit();
                    } else {
                        $conn->rollback();
                    }
                } else {
                    deleteSubmissionRelatedRecords($conn, $submissionId);
                    if ($deleteStmt->execute()) {
                        $deleteOk = true;
                        if (countStudentSubmissions($conn, $student_id) === 0) {
                            resetStudentProgressRecords($conn, $student_id);
                        }
                    }
                }
                if ($deleteOk) {
                    deleteSubmissionFiles($submission);
                    $_SESSION['flash_success'] = "Submission removed successfully.";
                    header("Location: submit_paper.php");
                    exit;
                } else {
                    $error = "Unable to remove the submission right now. Please try again.";
                }
                $deleteStmt->close();
            } else {
                $error = "Unable to prepare the removal request. Please try again.";
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_submission') {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    if ($submissionId <= 0) {
        $error = "Invalid submission selected for editing.";
    } else {
        $existingSubmission = fetchStudentSubmission($conn, $submissionId, $student_id);
        if (!$existingSubmission) {
            $error = "Unable to locate the submission you want to edit.";
        } else {
            $updatedType = trim($_POST['type'] ?? '');
            $existingType = trim((string)($existingSubmission['type'] ?? ''));
            $editableTypes = $allowedSubmissionTypes;
            if ($existingType !== '' && !in_array($existingType, $editableTypes, true)) {
                $editableTypes[] = $existingType;
            }
            if ($updatedType === '' || !in_array($updatedType, $editableTypes, true)) {
                $error = "Please select a valid research type for this submission.";
            }

            $conceptProposals = [
                'concept_proposal_1' => trim($_POST['concept_proposal_1'] ?? ''),
                'concept_proposal_2' => trim($_POST['concept_proposal_2'] ?? ''),
                'concept_proposal_3' => trim($_POST['concept_proposal_3'] ?? ''),
            ];
            $providedConcepts = array_filter($conceptProposals, fn($value) => $value !== '');
            if (!$error && empty($providedConcepts)) {
                $error = "Please provide at least one title option.";
            }
            if (!$error && hasDuplicateConceptValues($conceptProposals)) {
                $error = "Title options must be unique.";
            }
            if (
                !$error
                && hasDuplicateUploadedFileNames([
                    $_FILES['concept_file_1'] ?? null,
                    $_FILES['concept_file_2'] ?? null,
                    $_FILES['concept_file_3'] ?? null,
                ])
            ) {
                $error = "Uploaded PDF filenames must be unique.";
            }

            $conceptUploadDir = "uploads/submissions/";
            if (!$error && !is_dir($conceptUploadDir)) {
                mkdir($conceptUploadDir, 0777, true);
            }

            $updatedFiles = [
                'concept_file_1' => $existingSubmission['concept_file_1'] ?? null,
                'concept_file_2' => $existingSubmission['concept_file_2'] ?? null,
                'concept_file_3' => $existingSubmission['concept_file_3'] ?? null,
            ];
            $newUploads = [];
            $filesToDelete = [];

            foreach ($updatedFiles as $fileKey => $currentPath) {
                $index = (int)substr($fileKey, -1);
                $proposalKey = "concept_proposal_{$index}";
                $proposalValue = $conceptProposals[$proposalKey] ?? '';
                $fileInfo = $_FILES[$fileKey] ?? null;

                if ($proposalValue === '') {
                    if ($currentPath) {
                        $filesToDelete[] = $currentPath;
                    }
                    $updatedFiles[$fileKey] = null;
                    continue;
                }

                if ($fileInfo && $fileInfo['error'] === UPLOAD_ERR_OK) {
                    if (!isPdfUpload($fileInfo)) {
                        $error = "Title Option {$index} must be uploaded as a PDF file.";
                        break;
                    }
                    $conceptFilename = uniqid("concept{$index}_", true) . "_" . basename($fileInfo['name']);
                    $conceptPath = $conceptUploadDir . $conceptFilename;
                    if (!move_uploaded_file($fileInfo['tmp_name'], $conceptPath)) {
                        $error = "Unable to upload the file for Title Option {$index}. Please try again.";
                        break;
                    }
                    $updatedFiles[$fileKey] = $conceptPath;
                    $newUploads[] = $conceptPath;
                    if ($currentPath) {
                        $filesToDelete[] = $currentPath;
                    }
                } else {
                    if (!$currentPath) {
                        $error = "Please upload a PDF document for Title Option {$index}.";
                        break;
                    }
                    $updatedFiles[$fileKey] = $currentPath;
                }
            }

            $primaryFilePath = $updatedFiles['concept_file_1'] ?? null;
            if (!$error && $conceptProposals['concept_proposal_1'] !== '' && !$primaryFilePath) {
                $error = "Title Option 1 requires a supporting PDF document.";
            }

            if (!$error) {
                $updateColumns = ['type = ?'];
                $updateValues = [$updatedType];
                $updateTypes = 's';

                foreach ($conceptProposals as $column => $value) {
                    if (in_array($column, $proposalColumns, true)) {
                        $updateColumns[] = "{$column} = ?";
                        $updateValues[] = $value;
                        $updateTypes .= 's';
                    }
                }

                foreach ($updatedFiles as $fileColumn => $fileValue) {
                    if (in_array($fileColumn, $proposalFileColumns, true)) {
                        $updateColumns[] = "{$fileColumn} = ?";
                        $updateValues[] = $fileValue ?? '';
                        $updateTypes .= 's';
                    }
                }

                $updateColumns[] = 'file_path = ?';
                $updateValues[] = $primaryFilePath ?? '';
                $updateTypes .= 's';

                $updateColumns[] = 'status = ?';
                $updateValues[] = 'Pending';
                $updateTypes .= 's';

                $updateValues[] = $submissionId;
                $updateValues[] = $student_id;
                $updateTypes .= 'ii';

                $updateSql = "UPDATE submissions SET " . implode(', ', $updateColumns) . " WHERE id = ? AND student_id = ?";
                $stmt = $conn->prepare($updateSql);

                if ($stmt && bindStatementParams($stmt, $updateTypes, $updateValues)) {
                    if ($stmt->execute()) {
                        $_SESSION['flash_success'] = "Submission updated successfully.";
                        cleanupConceptProposalFiles($filesToDelete);
                        header("Location: submit_paper.php");
                        exit;
                    } else {
                        $error = "Unable to update the submission right now. Please try again.";
                        cleanupConceptProposalFiles($newUploads);
                    }
                    $stmt->close();
                } else {
                    $error = "Unable to prepare the update request. Please try again.";
                    cleanupConceptProposalFiles($newUploads);
                }
            } else {
                cleanupConceptProposalFiles($newUploads);
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === '' || $action === 'create_submission')) {
    if (!$studentExists) {
        $error = "Your account record could not be found. Please log in again.";
    } else {
    foreach ($formData as $key => $value) {
        $formData[$key] = trim($_POST[$key] ?? '');
    }

    $title = $formData['title'];
    $type = $formData['type'];
    $abstract = '';
    $keywords = '';
    if ($type === '' || !in_array($type, $allowedSubmissionTypes, true)) {
        $error = "Please select a valid research type for this submission.";
    }
    $conceptProposals = [
        'concept_proposal_1' => $formData['concept_proposal_1'],
        'concept_proposal_2' => $formData['concept_proposal_2'],
        'concept_proposal_3' => $formData['concept_proposal_3'],
    ];

    foreach ([1, 2, 3] as $index) {
        $proposalKey = "concept_proposal_{$index}";
        if (trim($conceptProposals[$proposalKey] ?? '') === '') {
            $error = "Please provide a title for Title Option {$index}.";
            break;
        }
    }
    if (!$error && hasDuplicateConceptValues($conceptProposals)) {
        $error = "Title options must be unique.";
    }
    if (
        !$error
        && hasDuplicateUploadedFileNames([
            $_FILES['concept_file_1'] ?? null,
            $_FILES['concept_file_2'] ?? null,
            $_FILES['concept_file_3'] ?? null,
        ])
    ) {
        $error = "Uploaded PDF filenames must be unique.";
    }

    $conceptUploadDir = "uploads/submissions/";
    if (!$error && !file_exists($conceptUploadDir)) {
        mkdir($conceptUploadDir, 0777, true);
    }

    if (!$error) {
        foreach ($conceptFiles as $fileKey => $_) {
            $index = (int)substr($fileKey, -1);
            $proposalKey = "concept_proposal_{$index}";
            $fileInfo = $_FILES[$fileKey] ?? null;
            $proposalValue = trim($conceptProposals[$proposalKey] ?? '');

            if ($proposalValue === '') {
                $error = "Please provide a title for Title Option {$index}.";
                break;
            }

            if (!$fileInfo || $fileInfo['error'] !== UPLOAD_ERR_OK) {
                $error = "Please upload a document for Title Option {$index}.";
                break;
            }

            if (!isPdfUpload($fileInfo)) {
                $error = "Title Option {$index} must be uploaded as a PDF file.";
                break;
            }

            $conceptFilename = uniqid("concept{$index}_", true) . "_" . basename($fileInfo['name']);
            $conceptPath = $conceptUploadDir . $conceptFilename;
            if (!move_uploaded_file($fileInfo['tmp_name'], $conceptPath)) {
                $error = "Unable to upload the file for Title Option {$index}. Please try again.";
                break;
            }
            $conceptFiles[$fileKey] = $conceptPath;
        }
    }

    $primaryFilePath = $conceptFiles['concept_file_1'] ?? null;
    if (!$error && !$primaryFilePath) {
        $error = "Title Option 1 requires a supporting PDF document.";
    }

    if (!$error) {
        $insertColumns = ['student_id', 'title', 'type', 'abstract', 'keywords'];
        $insertValues = [$student_id, $title, $type, $abstract, $keywords];
        $insertTypes = 'issss';

        foreach ($conceptProposals as $column => $value) {
            if (in_array($column, $proposalColumns, true)) {
                $insertColumns[] = $column;
                $insertValues[] = $value;
                $insertTypes .= 's';
            }
        }

        foreach ($conceptFiles as $fileColumn => $fileValue) {
            if ($fileValue && in_array($fileColumn, $proposalFileColumns, true)) {
                $insertColumns[] = $fileColumn;
                $insertValues[] = $fileValue;
                $insertTypes .= 's';
            }
        }

        $insertColumns[] = 'file_path';
        $insertValues[] = $primaryFilePath ?? '';
        $insertTypes .= 's';

        $insertColumns[] = 'status';
        $statusValue = 'Pending';
        $insertValues[] = $statusValue;
        $insertTypes .= 's';

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $insertSql = "INSERT INTO submissions (" . implode(', ', $insertColumns) . ") VALUES ({$placeholders})";
        $stmt = $conn->prepare($insertSql);

            if ($stmt && bindStatementParams($stmt, $insertTypes, $insertValues)) {
                if ($stmt->execute()) {
                $submissionId = (int)$stmt->insert_id;
                $_SESSION['flash_success'] = "Your submission and title options were submitted successfully. Track the live status on the right.";
                $formData = array_map(fn() => '', $formData);

                $nameStmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
                if ($nameStmt) {
                    $nameStmt->bind_param('i', $student_id);
                    $nameStmt->execute();
                    $nameResult = $nameStmt->get_result()->fetch_assoc();
                    $nameStmt->close();
                } else {
                    $nameResult = null;
                }
                $studentName = trim(($nameResult['firstname'] ?? '') . ' ' . ($nameResult['lastname'] ?? ''));
                if ($studentName === '') {
                    $studentName = 'A student';
                }

                $titleSnippet = $title !== '' ? " titled \"{$title}\"" : '';
                $message = "{$studentName} submitted a new research entry{$titleSnippet}.";
                // Notify only program chairpersons whose program matches the student
                notify_program_chairs_for_student(
                    $conn,
                    $student_id,
                    'New research submission',
                    $message,
                    'submissions.php'
                );
                // Notify other roles (these may need similar filtering in the future)
                notify_roles(
                    $conn,
                    ['committee_chairperson', 'committee_chair', 'adviser'],
                    'New research submission',
                    $message,
                    'submissions.php'
                );
                if (function_exists('progress_tracker_mark_step_complete')) {
                    progress_tracker_mark_step_complete($conn, $student_id, 'concept_submitted', 'submissions', $submissionId);
                }
                header("Location: submit_paper.php");
                exit;
            } else {
                $error = "Database error: " . $conn->error;
            }
        } else {
            $error = "Unable to prepare submission. Please try again.";
        }

        if ($stmt) {
            $stmt->close();
        }
    }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $error
    && ($action === '' || $action === 'create_submission')
) {
    cleanupConceptProposalFiles($conceptFiles);
}


$submissionHistory = fetchStudentSubmissionHistory($conn, $student_id, 5);
$statusCounts = [];
foreach ($submissionHistory as $submission) {
    $statusKey = trim($submission['status'] ?? 'Submitted');
    if (!isset($statusCounts[$statusKey])) {
        $statusCounts[$statusKey] = 0;
    }
    $statusCounts[$statusKey]++;
}
$latestSubmission = $submissionHistory[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Submit Research Entry - DNSC IAdS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    body { background: #f5f7fb; }
    .content { margin-left: 220px; padding: 24px; min-height: 100vh; transition: margin-left .3s ease; }
    #sidebar.collapsed ~ .content { margin-left: 70px; }
    .page-intro {
        border-radius: 20px;
        background: linear-gradient(135deg, #16562c, #0f3d1f);
        color: #fff;
        padding: 24px;
        box-shadow: 0 18px 38px rgba(15, 61, 31, 0.25);
    }
    .submission-card,
    .status-card {
        border: none;
        border-radius: 22px;
        box-shadow: 0 18px 42px rgba(15, 61, 31, 0.12);
    }
    .submission-card .card-header,
    .status-card .card-header {
        background: transparent;
        border-bottom: none;
        padding-bottom: 0;
    }
    .submission-card__title { color: #16562c; }
    .concept-proposal-box {
        border: 1px dashed rgba(22, 86, 44, 0.25);
        border-radius: 16px;
        background: #f8fffb;
        padding: 1rem;
    }
    .concept-proposal-stack {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    .concept-proposal-stack .proposal-column {
        background: #fff;
        border: 1px solid rgba(22, 86, 44, 0.12);
        border-radius: 14px;
        padding: 1rem;
    }
    .proposal-pill {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        border-radius: 999px;
        padding: .35rem .85rem;
        background: rgba(22, 86, 44, 0.08);
        color: #16562c;
        font-size: .85rem;
        margin-right: .35rem;
        margin-top: .35rem;
    }
    .status-chip {
        border-radius: 999px;
        background: rgba(15, 61, 31, 0.08);
        color: #0f3d1f;
        font-size: .8rem;
        padding: .25rem .75rem;
    }
    .status-entry {
        border: 1px solid rgba(22, 86, 44, 0.08);
        border-radius: 16px;
        padding: 1rem;
        margin-bottom: 1rem;
        background: #fff;
    }
    .status-entry:last-child { margin-bottom: 0; }
    .empty-state {
        text-align: center;
        padding: 2rem 1rem;
        color: #6c757d;
    }
    .btn-icon-gap i { margin-right: .35rem; }
    .status-actions { gap: .5rem; }
    @media (max-width: 992px) {
        .content { margin-left: 0; }
    }
  </style>
</head>
<body>
  <?php include 'header.php'; ?>
  <?php include 'sidebar.php'; ?>

  <div class="content">
    <div class="container my-4">
      <div class="page-intro mb-4">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
          <div>
            <p class="text-uppercase small mb-1">Student workspace</p>
            <h2 class="fw-bold mb-1">Submit Research Entry & Track Approvals</h2>
            <p class="mb-0">Upload your manuscript, list the three title options, and monitor the live status of every submission.</p>
          </div>
          <div class="text-lg-end">
            <span class="badge bg-light text-success fs-6">
              <i class="bi bi-clock-history me-1"></i> Status panel updates in real time
            </span>
          </div>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <?= htmlspecialchars($success); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <?= htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="row g-4">
        <div class="col-xl-7">
          <div class="card submission-card h-100">
            <div class="card-header">
              <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                <div>
                  <p class="text-uppercase small text-muted mb-1">Step 1</p>
                  <h4 class="submission-card__title mb-0"><i class="bi bi-upload me-2"></i>Research Submission Form</h4>
                </div>
                <span class="badge bg-success-subtle text-success">
                  <i class="bi bi-shield-check me-1"></i> Secure upload
                </span>
              </div>
            </div>
            <div class="card-body">
              <form method="POST" enctype="multipart/form-data" class="needs-validation">
                <input type="hidden" name="action" value="create_submission">
                <div class="row">
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label class="form-label fw-semibold">Research Type <span class="text-danger">*</span></label>
                      <select class="form-select" name="type" required>
                        <option value="">Select type...</option>
                        <?php foreach ($allowedSubmissionTypes as $submissionType): ?>
                          <option value="<?= htmlspecialchars($submissionType); ?>" <?= $formData['type'] === $submissionType ? 'selected' : ''; ?>><?= htmlspecialchars($submissionType); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="concept-proposal-box mb-4">
                  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
                    <div>
                      <p class="fw-semibold mb-0">Title Options <span class="text-danger">*</span></p>
                      <small class="text-muted">List up to three research title options so reviewers can rank them.</small>
                    </div>
                  </div>
                  <div class="concept-proposal-stack">
                    <div class="proposal-column">
                      <label class="form-label small text-muted">Title Option 1</label>
                      <input type="text" class="form-control" name="concept_proposal_1" value="<?= htmlspecialchars($formData['concept_proposal_1']); ?>" required>
                      <label class="form-label small text-muted mt-3 mb-1">Upload Manuscript <span class="text-danger">*</span></label>
                      <input type="file" class="form-control" name="concept_file_1" accept=".pdf" required>
                      <small class="text-muted d-block mt-2">Upload the manuscript for Option 1 (PDF only).</small>
                    </div>
                    <div class="proposal-column">
                      <label class="form-label small text-muted">Title Option 2</label>
                      <input type="text" class="form-control" name="concept_proposal_2" value="<?= htmlspecialchars($formData['concept_proposal_2']); ?>" required>
                      <label class="form-label small text-muted mt-3 mb-1">Upload Manuscript <span class="text-danger">*</span></label>
                      <input type="file" class="form-control" name="concept_file_2" accept=".pdf" required>
                      <small class="text-muted d-block mt-2">Upload the manuscript for Option 2 (PDF only).</small>
                    </div>
                    <div class="proposal-column">
                      <label class="form-label small text-muted">Title Option 3</label>
                      <input type="text" class="form-control" name="concept_proposal_3" value="<?= htmlspecialchars($formData['concept_proposal_3']); ?>" required>
                      <label class="form-label small text-muted mt-3 mb-1">Upload Manuscript <span class="text-danger">*</span></label>
                      <input type="file" class="form-control" name="concept_file_3" accept=".pdf" required>
                      <small class="text-muted d-block mt-2">Upload the manuscript for Option 3 (PDF only).</small>
                    </div>
                  </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                  <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-3">
                    <button type="reset" class="btn btn-outline-secondary btn-icon-gap w-100 w-md-auto">
                      <i class="bi bi-arrow-counterclockwise"></i> Clear Form
                    </button>
                    <div class="d-flex flex-column flex-md-row gap-2 w-100 w-md-auto">
                      <a href="student_activity_log.php" class="btn btn-outline-success w-100 btn-icon-gap">
                        <i class="bi bi-graph-up-arrow"></i> View Status History
                      </a>
                      <button type="submit" class="btn btn-success btn-icon-gap w-100">
                        <i class="bi bi-send-check"></i> Submit for Review
                      </button>
                    </div>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
        <div class="col-xl-5">
          <div class="card status-card h-100">
            <div class="card-header">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <p class="text-uppercase small text-muted mb-1">Step 2</p>
                  <h5 class="mb-0 text-success"><i class="bi bi-broadcast-pin me-2"></i>Live Submission Status</h5>
                </div>
                <button class="btn btn-sm btn-outline-secondary" id="refreshStatusBtn">
                  <i class="bi bi-arrow-repeat"></i> Refresh
                </button>
              </div>
            </div>
            <div class="card-body">
              <?php if (empty($submissionHistory)): ?>
                <div class="empty-state">
                  <i class="bi bi-folder-plus fs-1 d-block mb-2"></i>
                  <p class="mb-0">You have not submitted a research entry yet. Your status timeline will appear here after your first upload.</p>
                </div>
              <?php else: ?>
                <?php if (!empty($statusCounts)): ?>
                  <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($statusCounts as $label => $count): ?>
                      <span class="status-chip"><?= htmlspecialchars($label); ?>: <?= number_format($count); ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php foreach ($submissionHistory as $index => $submission): ?>
                  <?php
                    $statusLabel = trim($submission['status'] ?? 'Submitted');
                    $badgeClass = statusBadgeClass($statusLabel);
                    $submittedAt = formatHumanDate($submission['created_at'] ?? null);
                    $submissionTitle = $submission['title'] ?? '';
                    $submissionType = $submission['type'] ?? '';
                    $displayTitle = $submissionTitle ?: ($submissionType ? "Type: {$submissionType}" : 'Submission');
                    $proposals = array_filter([
                        $submission['concept_proposal_1'] ?? '',
                        $submission['concept_proposal_2'] ?? '',
                        $submission['concept_proposal_3'] ?? '',
                    ]);
                  ?>
                  <div class="status-entry">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <h6 class="mb-1"><?= htmlspecialchars($displayTitle); ?></h6>
                        <small class="text-muted">Submitted <?= htmlspecialchars($submittedAt); ?></small>
                      </div>
                      <span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($statusLabel); ?></span>
                    </div>
                    <?php if (!empty($proposals)): ?>
                      <div class="mt-3">
                        <?php foreach ($proposals as $proposalIndex => $proposalText): ?>
                          <span class="proposal-pill"><strong>P<?= $proposalIndex + 1; ?>:</strong> <?= htmlspecialchars($proposalText); ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap status-actions mt-3">
                      <button type="button" class="btn btn-sm btn-outline-secondary btn-icon-gap" data-bs-toggle="modal" data-bs-target="#editSubmissionModal<?= (int)$submission['id']; ?>">
                        <i class="bi bi-pencil-square"></i> Edit Submission
                      </button>
                      <button type="button" class="btn btn-sm btn-outline-danger btn-icon-gap" data-bs-toggle="modal" data-bs-target="#deleteSubmissionModal<?= (int)$submission['id']; ?>">
                        <i class="bi bi-trash"></i> Remove Submission
                      </button>
                    </div>
                  </div>
                  <div class="modal fade" id="editSubmissionModal<?= (int)$submission['id']; ?>" tabindex="-1" aria-labelledby="editSubmissionLabel<?= (int)$submission['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                      <form method="POST" enctype="multipart/form-data" class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title" id="editSubmissionLabel<?= (int)$submission['id']; ?>">Edit Submission</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <p class="text-muted small mb-3">Update the title options or upload replacement PDFs. Saving changes moves the submission back to <strong>Pending</strong>.</p>
                          <input type="hidden" name="action" value="edit_submission">
                          <input type="hidden" name="submission_id" value="<?= (int)$submission['id']; ?>">
                          <div class="mb-3">
                            <label class="form-label fw-semibold">Research Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" required>
                              <option value="">Select type...</option>
                              <?php
                                $currentSubmissionType = trim((string)($submission['type'] ?? ''));
                                $editSubmissionTypes = $allowedSubmissionTypes;
                                if ($currentSubmissionType !== '' && !in_array($currentSubmissionType, $editSubmissionTypes, true)) {
                                    $editSubmissionTypes[] = $currentSubmissionType;
                                }
                              ?>
                              <?php foreach ($editSubmissionTypes as $submissionType): ?>
                                <option value="<?= htmlspecialchars($submissionType); ?>" <?= $currentSubmissionType === $submissionType ? 'selected' : ''; ?>><?= htmlspecialchars($submissionType); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="concept-proposal-stack">
                            <?php for ($modalProposalIndex = 1; $modalProposalIndex <= 3; $modalProposalIndex++): ?>
                              <?php
                                $proposalField = "concept_proposal_{$modalProposalIndex}";
                                $fileField = "concept_file_{$modalProposalIndex}";
                                $proposalValue = $submission[$proposalField] ?? '';
                                $proposalLabel = "Title Option {$modalProposalIndex}";
                              ?>
                              <div class="proposal-column">
                                <label class="form-label small text-muted"><?= htmlspecialchars($proposalLabel); ?><?= $modalProposalIndex === 1 ? ' *' : ''; ?></label>
                                <input
                                  type="text"
                                  class="form-control"
                                  name="<?= $proposalField; ?>"
                                  value="<?= htmlspecialchars($proposalValue); ?>"
                                  <?= $modalProposalIndex === 1 ? 'required' : ''; ?>
                                >
                                <label class="form-label small text-muted mt-3 mb-1">Replace PDF<?= $modalProposalIndex === 1 ? ' *' : ''; ?></label>
                                <input type="file" class="form-control" name="<?= $fileField; ?>" accept=".pdf">
                                <small class="text-muted d-block mt-2">
                                  <?= $modalProposalIndex === 1
                                    ? 'A PDF is required for Option 1. Leave the upload blank to keep the current file.'
                                    : 'Upload a new PDF only if you need to replace the current file for this option.'; ?>
                                </small>
                              </div>
                            <?php endfor; ?>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn btn-success btn-icon-gap">
                            <i class="bi bi-save"></i> Save Changes
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                  <div class="modal fade" id="deleteSubmissionModal<?= (int)$submission['id']; ?>" tabindex="-1" aria-labelledby="deleteSubmissionLabel<?= (int)$submission['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog">
                      <form method="POST" class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title" id="deleteSubmissionLabel<?= (int)$submission['id']; ?>">Remove Submission</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="action" value="delete_submission">
                          <input type="hidden" name="submission_id" value="<?= (int)$submission['id']; ?>">
                          <p class="mb-2">Are you sure you want to remove this research submission?</p>
                          <p class="fw-semibold mb-0"><?= htmlspecialchars($displayTitle); ?></p>
                          <small class="text-muted d-block mt-2">All uploaded PDFs tied to this submission will be deleted.</small>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                          <button type="submit" class="btn btn-danger btn-icon-gap">
                            <i class="bi bi-trash"></i> Remove Submission
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const refreshBtn = document.getElementById('refreshStatusBtn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        refreshBtn.classList.add('disabled');
        refreshBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Refreshing';
        window.location.reload();
      });
    }
  });
</script>
</body>
</html>
