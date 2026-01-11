<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'final_concept_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: login.php");
    exit;
}

ensureFinalConceptSubmissionTable($conn);

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
$finalSuccess = $finalError = '';
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
$finalFormData = [
    'final_title' => '',
    'final_abstract' => '',
    'final_keywords' => '',
];
$action = $_POST['action'] ?? '';

$proposalColumns = ensureSubmissionProposalColumns($conn);
$proposalFileColumns = ensureSubmissionProposalFileColumns($conn);
$proposalFileColumns = ensureSubmissionProposalFileColumns($conn);
$finalEligibleConcept = getEligibleConceptForFinalSubmission($conn, $student_id);
$currentFinalSubmission = getLatestFinalConceptSubmission($conn, $student_id);
$finalSubmissionStatus = $currentFinalSubmission['status'] ?? null;
$finalResubmitAllowed = $finalEligibleConcept && (
    !$currentFinalSubmission ||
    in_array($finalSubmissionStatus, ['Returned'], true)
);
$finalFormEnabled = false;
if ($finalEligibleConcept && !$currentFinalSubmission) {
    $finalFormEnabled = true;
    $finalFormData['final_title'] = $finalEligibleConcept['title'] ?? '';
} elseif ($currentFinalSubmission && in_array($currentFinalSubmission['status'] ?? '', ['Returned'], true)) {
    $finalFormEnabled = true;
    $finalFormData['final_title'] = $currentFinalSubmission['final_title'] ?? ($finalEligibleConcept['title'] ?? '');
    $finalFormData['final_abstract'] = $currentFinalSubmission['abstract'] ?? '';
    $finalFormData['final_keywords'] = $currentFinalSubmission['keywords'] ?? '';
}
$finalStatusLabel = 'Waiting for Rankings';
$finalStatusBadge = 'bg-secondary-subtle text-secondary';
if ($currentFinalSubmission) {
    $finalStatusLabel = $currentFinalSubmission['status'] ?? 'Pending';
    $finalStatusBadge = finalConceptStatusClass($finalStatusLabel);
} elseif ($finalEligibleConcept) {
    $finalStatusLabel = 'Ready for Submission';
    $finalStatusBadge = 'bg-info-subtle text-info';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['final_submit'])) {
    if (!$studentExists) {
        $finalError = "Your account record could not be found. Please log in again.";
    } else {
        $finalFormData['final_title'] = trim($_POST['final_title'] ?? '');
        $finalFormData['final_abstract'] = trim($_POST['final_abstract'] ?? '');
        $finalFormData['final_keywords'] = trim($_POST['final_keywords'] ?? '');
        $targetConceptId = $finalEligibleConcept['concept_paper_id'] ?? ($currentFinalSubmission['concept_paper_id'] ?? 0);
        $canSubmit = !$currentFinalSubmission || in_array(($currentFinalSubmission['status'] ?? 'Pending'), ['Returned'], true);

        if (!$targetConceptId) {
            $finalError = "Your concept titles are still under review. Please wait for an approved ranking before sending the final version.";
        } elseif (!$canSubmit) {
            // No error needed; the dashboard already shows the current status.
        } elseif ($finalFormData['final_title'] === '' || $finalFormData['final_abstract'] === '' || $finalFormData['final_keywords'] === '') {
            $finalError = "Final title, abstract, and keywords are all required.";
        } else {
            $file = $_FILES['final_document'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $finalError = "Please upload the final concept document (PDF or DOC).";
            } else {
                $allowed = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ];
                if (!in_array($file['type'], $allowed, true)) {
                    $finalError = "Only PDF or Word documents are allowed for the final concept.";
                } else {
                    $uploadDir = "uploads/final_concepts/";
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $filename = uniqid('final_concept_', true) . "_" . basename($file['name']);
                    $filePath = $uploadDir . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                        $finalError = "Unable to upload the final concept file. Please try again.";
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO final_concept_submissions
                            (student_id, concept_paper_id, final_title, abstract, keywords, file_path, status, remarks)
                            VALUES (?, ?, ?, ?, ?, ?, 'Pending', NULL)
                        ");
                        if ($stmt) {
                            $stmt->bind_param(
                                'iissss',
                                $student_id,
                                $targetConceptId,
                                $finalFormData['final_title'],
                                $finalFormData['final_abstract'],
                                $finalFormData['final_keywords'],
                                $filePath
                            );
                            if ($stmt->execute()) {
                                $finalSuccess = "Final concept title submitted successfully. The Program Chairperson will review it shortly.";
                                $currentFinalSubmission = getLatestFinalConceptSubmission($conn, $student_id);
                                $finalSubmissionStatus = $currentFinalSubmission['status'] ?? 'Pending';
                                $finalResubmitAllowed = false;
                                $finalFormEnabled = false;
                                $finalFormData = ['final_title' => '', 'final_abstract' => '', 'final_keywords' => ''];

                                $chairRecipients = getProgramChairsForStudent($conn, $student_id);
                                if (!empty($chairRecipients)) {
                                    foreach ($chairRecipients as $chairId) {
                                        notify_user(
                                            $conn,
                                            $chairId,
                                            'Final concept ready for approval',
                                            'A student submitted the final concept title for review. Please check the Final Concept Submission panel.',
                                            'program_chairperson.php'
                                        );
                                    }
                                }
                            } else {
                                $finalError = "Unable to save the final concept submission right now.";
                                @unlink($filePath);
                            }
                            $stmt->close();
                        } else {
                            $finalError = "Unable to prepare the final submission. Please try again later.";
                            @unlink($filePath);
                        }
                    }
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_submission') {
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
                if ($deleteStmt->execute()) {
                    deleteSubmissionFiles($submission);
                    $success = "Submission removed successfully.";
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
            if ($updatedType === '') {
                $error = "Please select the research type for this submission.";
            }

            $conceptProposals = [
                'concept_proposal_1' => trim($_POST['concept_proposal_1'] ?? ''),
                'concept_proposal_2' => trim($_POST['concept_proposal_2'] ?? ''),
                'concept_proposal_3' => trim($_POST['concept_proposal_3'] ?? ''),
            ];
            $providedConcepts = array_filter($conceptProposals, fn($value) => $value !== '');
            if (!$error && empty($providedConcepts)) {
                $error = "Please provide at least one concept proposal title.";
            }
            if (!$error && hasDuplicateConceptValues($conceptProposals)) {
                $error = "Concept proposal titles must be unique.";
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
                        $error = "Concept Proposal {$index} must be uploaded as a PDF file.";
                        break;
                    }
                    $conceptFilename = uniqid("concept{$index}_", true) . "_" . basename($fileInfo['name']);
                    $conceptPath = $conceptUploadDir . $conceptFilename;
                    if (!move_uploaded_file($fileInfo['tmp_name'], $conceptPath)) {
                        $error = "Unable to upload the file for Concept Proposal {$index}. Please try again.";
                        break;
                    }
                    $updatedFiles[$fileKey] = $conceptPath;
                    $newUploads[] = $conceptPath;
                    if ($currentPath) {
                        $filesToDelete[] = $currentPath;
                    }
                } else {
                    if (!$currentPath) {
                        $error = "Please upload a PDF document for Concept Proposal {$index}.";
                        break;
                    }
                    $updatedFiles[$fileKey] = $currentPath;
                }
            }

            $primaryFilePath = $updatedFiles['concept_file_1'] ?? null;
            if (!$error && $conceptProposals['concept_proposal_1'] !== '' && !$primaryFilePath) {
                $error = "Concept Proposal 1 requires a supporting PDF document.";
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
                        $success = "Submission updated successfully.";
                        cleanupConceptProposalFiles($filesToDelete);
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
$conceptProposals = [
        'concept_proposal_1' => $formData['concept_proposal_1'],
        'concept_proposal_2' => $formData['concept_proposal_2'],
        'concept_proposal_3' => $formData['concept_proposal_3'],
    ];

    $providedConcepts = array_filter($conceptProposals, fn($value) => $value !== '');
    if (empty($providedConcepts)) {
        $error = "Please provide at least one concept proposal title.";
    }
    if (!$error && hasDuplicateConceptValues($conceptProposals)) {
        $error = "Concept proposal titles must be unique.";
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

    foreach ($conceptFiles as $fileKey => $_) {
        $index = (int)substr($fileKey, -1);
        $proposalKey = "concept_proposal_{$index}";
        $fileInfo = $_FILES[$fileKey] ?? null;
        $proposalValue = trim($conceptProposals[$proposalKey] ?? '');

        if ($proposalValue === '') {
            if ($fileInfo && $fileInfo['error'] === UPLOAD_ERR_OK) {
                $error = "Please enter a title for Concept Proposal {$index} before uploading a document.";
                break;
            }
            continue;
        }

        if (!$fileInfo || $fileInfo['error'] !== UPLOAD_ERR_OK) {
            $error = "Please upload a document for Concept Proposal {$index}.";
            break;
        }

        if (!isPdfUpload($fileInfo)) {
            $error = "Concept Proposal {$index} must be uploaded as a PDF file.";
            break;
        }

        $conceptFilename = uniqid("concept{$index}_", true) . "_" . basename($fileInfo['name']);
        $conceptPath = $conceptUploadDir . $conceptFilename;
        if (!move_uploaded_file($fileInfo['tmp_name'], $conceptPath)) {
            $error = "Unable to upload the file for Concept Proposal {$index}. Please try again.";
            break;
        }
        $conceptFiles[$fileKey] = $conceptPath;
    }

    $primaryFilePath = $conceptFiles['concept_file_1'] ?? null;
    if (!$error && !$primaryFilePath) {
        $error = "Concept Proposal 1 requires a supporting PDF document.";
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
                $success = "Your concept paper and proposals were submitted successfully. Track the live status on the right.";
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
                $message = "{$studentName} submitted a new paper{$titleSnippet}.";
                notify_roles(
                    $conn,
                    ['program_chairperson', 'committee_chairperson', 'committee_chair', 'adviser'],
                    'New paper submission',
                    $message,
                    'submissions.php'
                );
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

$finalSubmissionHistory = fetchFinalConceptSubmissionHistory($conn, $student_id);

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
  <title>Submit Paper - DNSC IAdS</title>
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
            <h2 class="fw-bold mb-1">Submit Concept Paper & Track Approvals</h2>
            <p class="mb-0">Upload your manuscript, list the three concept proposals, and monitor the live status of every submission.</p>
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
                  <h4 class="submission-card__title mb-0"><i class="bi bi-upload me-2"></i>Paper Submission Form</h4>
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
                        <option value="Concept Paper" <?= $formData['type'] === 'Concept Paper' ? 'selected' : ''; ?>>Concept Paper</option>
                        <option value="Thesis" <?= $formData['type'] === 'Thesis' ? 'selected' : ''; ?>>Thesis</option>
                        <option value="Dissertation" <?= $formData['type'] === 'Dissertation' ? 'selected' : ''; ?>>Dissertation</option>
                        <option value="Capstone" <?= $formData['type'] === 'Capstone' ? 'selected' : ''; ?>>Capstone</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="concept-proposal-box mb-4">
                  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
                    <div>
                      <p class="fw-semibold mb-0">Concept Proposal Titles <span class="text-danger">*</span></p>
                      <small class="text-muted">List up to three concept proposals so reviewers can rank them.</small>
                    </div>
                    <span class="badge bg-light text-success">3 slots available</span>
                  </div>
                  <div class="concept-proposal-stack">
                    <div class="proposal-column">
                      <label class="form-label small text-muted">Concept Proposal 1</label>
                      <input type="text" class="form-control" name="concept_proposal_1" value="<?= htmlspecialchars($formData['concept_proposal_1']); ?>" required>
                      <label class="form-label small text-muted mt-3 mb-1">Upload Manuscript <span class="text-danger">*</span></label>
                      <input type="file" class="form-control" name="concept_file_1" accept=".pdf" required>
                      <small class="text-muted d-block mt-2">Upload the manuscript for Proposal 1 (PDF only).</small>
                    </div>
                    <div class="proposal-column">
                      <label class="form-label small text-muted">Concept Proposal 2</label>
                      <input type="text" class="form-control" name="concept_proposal_2" value="<?= htmlspecialchars($formData['concept_proposal_2']); ?>">
                      <label class="form-label small text-muted mt-3 mb-1">Upload Manuscript</label>
                      <input type="file" class="form-control" name="concept_file_2" accept=".pdf">
                      <small class="text-muted d-block mt-2">Add a PDF if this proposal is used.</small>
                    </div>
                    <div class="proposal-column">
                      <label class="form-label small text-muted">Concept Proposal 3</label>
                      <input type="text" class="form-control" name="concept_proposal_3" value="<?= htmlspecialchars($formData['concept_proposal_3']); ?>">
                      <label class="form-label small text-muted mt-3 mb-1">Upload Manuscript</label>
                      <input type="file" class="form-control" name="concept_file_3" accept=".pdf">
                      <small class="text-muted d-block mt-2">Add a PDF if this proposal is used.</small>
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
                  <p class="mb-0">You have not submitted a concept paper yet. Your status timeline will appear here after your first upload.</p>
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
                    $proposals = array_filter([
                        $submission['concept_proposal_1'] ?? '',
                        $submission['concept_proposal_2'] ?? '',
                        $submission['concept_proposal_3'] ?? '',
                    ]);
                  ?>
                  <div class="status-entry">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <h6 class="mb-1"><?= htmlspecialchars($submission['title'] ?: 'Untitled Submission'); ?></h6>
                        <small class="text-muted">Submitted <?= htmlspecialchars($submittedAt); ?></small>
                      </div>
                      <span class="badge <?= $badgeClass; ?>"><?= htmlspecialchars($statusLabel); ?></span>
                    </div>
                    <?php if (!empty($submission['type'])): ?>
                      <div class="small text-muted mt-2">Type: <strong><?= htmlspecialchars($submission['type']); ?></strong></div>
                    <?php endif; ?>
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
                          <p class="text-muted small mb-3">Update the proposal titles or upload replacement PDFs. Saving changes moves the submission back to <strong>Pending</strong>.</p>
                          <input type="hidden" name="action" value="edit_submission">
                          <input type="hidden" name="submission_id" value="<?= (int)$submission['id']; ?>">
                          <div class="mb-3">
                            <label class="form-label fw-semibold">Research Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" required>
                              <option value="">Select type...</option>
                              <option value="Concept Paper" <?= ($submission['type'] ?? '') === 'Concept Paper' ? 'selected' : ''; ?>>Concept Paper</option>
                              <option value="Thesis" <?= ($submission['type'] ?? '') === 'Thesis' ? 'selected' : ''; ?>>Thesis</option>
                              <option value="Dissertation" <?= ($submission['type'] ?? '') === 'Dissertation' ? 'selected' : ''; ?>>Dissertation</option>
                              <option value="Capstone" <?= ($submission['type'] ?? '') === 'Capstone' ? 'selected' : ''; ?>>Capstone</option>
                            </select>
                          </div>
                          <div class="concept-proposal-stack">
                            <?php for ($modalProposalIndex = 1; $modalProposalIndex <= 3; $modalProposalIndex++): ?>
                              <?php
                                $proposalField = "concept_proposal_{$modalProposalIndex}";
                                $fileField = "concept_file_{$modalProposalIndex}";
                                $proposalValue = $submission[$proposalField] ?? '';
                                $proposalLabel = "Concept Proposal {$modalProposalIndex}";
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
                                    ? 'A PDF is required for Proposal 1. Leave the upload blank to keep the current file.'
                                    : 'Upload a new PDF only if you need to replace the current file for this proposal.'; ?>
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
                          <p class="mb-2">Are you sure you want to remove this concept paper submission?</p>
                          <p class="fw-semibold mb-0"><?= htmlspecialchars($submission['title'] ?: 'Untitled Submission'); ?></p>
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

              <?php if (!empty($finalSubmissionHistory)): ?>
                <hr class="my-4">
                <h5 class="fw-semibold mb-3">Final Concept Submission History</h5>
                <div class="table-responsive">
                  <table class="table table-sm align-middle">
                    <thead class="table-light">
                      <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Reviewed</th>
                        <th>Remarks</th>
                        <th>File</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($finalSubmissionHistory as $finalRow): ?>
                        <?php
                          $historyStatus = $finalRow['status'] ?? 'Pending';
                          $historyBadge = finalConceptStatusClass($historyStatus);
                          $historySubmitted = formatHumanDate($finalRow['submitted_at'] ?? null);
                          $historyReviewed = formatHumanDate($finalRow['reviewed_at'] ?? null);
                        ?>
                        <tr>
                          <td><?= htmlspecialchars($finalRow['final_title'] ?? 'Untitled'); ?></td>
                          <td><span class="badge <?= $historyBadge; ?>"><?= htmlspecialchars($historyStatus); ?></span></td>
                          <td><?= htmlspecialchars($historySubmitted); ?></td>
                          <td><?= htmlspecialchars($finalRow['reviewed_at'] ? $historyReviewed : '—'); ?></td>
                          <td><?= htmlspecialchars($finalRow['remarks'] ?? '—'); ?></td>
                          <td>
                            <?php if (!empty($finalRow['file_path'])): ?>
                              <a href="<?= htmlspecialchars($finalRow['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-file-earmark-arrow-down"></i>
                              </a>
                            <?php else: ?>
                              —
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

      <div class="row g-4 mt-1">
        <div class="col-xl-7">
          <div class="card submission-card h-100">
            <div class="card-header">
              <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                <div>
                  <p class="text-uppercase small text-muted mb-1">Step 2</p>
                  <h4 class="submission-card__title mb-0"><i class="bi bi-file-earmark-check me-2"></i>Final Concept Title Submission</h4>
                </div>
                <span class="badge <?= $finalStatusBadge; ?>">
                  <i class="bi bi-flag-fill me-1"></i><?= htmlspecialchars($finalStatusLabel); ?>
                </span>
              </div>
            </div>
            <div class="card-body">
              <?php if ($finalSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                  <?= htmlspecialchars($finalSuccess); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php elseif ($finalError): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <?= htmlspecialchars($finalError); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <?php if ($currentFinalSubmission): ?>
                <?php
                  $finalSubmittedAt = formatHumanDate($currentFinalSubmission['submitted_at'] ?? null);
                  $finalRemarks = trim((string)($currentFinalSubmission['remarks'] ?? ''));
                ?>
                <div class="status-entry mb-4">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <p class="fw-semibold mb-1">Latest Final Concept</p>
                      <small class="text-muted">Submitted <?= htmlspecialchars($finalSubmittedAt); ?></small>
                    </div>
                    <span class="badge <?= finalConceptStatusClass($currentFinalSubmission['status'] ?? 'Pending'); ?>">
                      <?= htmlspecialchars($currentFinalSubmission['status'] ?? 'Pending'); ?>
                    </span>
                  </div>
                  <p class="mt-3 mb-2"><strong>Title:</strong> <?= htmlspecialchars($currentFinalSubmission['final_title'] ?? 'Untitled'); ?></p>
                  <?php if ($finalRemarks !== ''): ?>
                    <div class="alert alert-warning py-2 px-3 mb-3">
                      <strong>Chair Remarks:</strong> <?= htmlspecialchars($finalRemarks); ?>
                    </div>
                  <?php endif; ?>
                  <div class="d-flex flex-wrap gap-2">
                    <?php if (!empty($currentFinalSubmission['file_path'])): ?>
                      <a href="<?= htmlspecialchars($currentFinalSubmission['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary btn-icon-gap">
                        <i class="bi bi-file-earmark-arrow-down"></i> Download Final Copy
                      </a>
                    <?php endif; ?>
                    <a href="student_activity_log.php" class="btn btn-sm btn-outline-success btn-icon-gap">
                      <i class="bi bi-lightning-charge"></i> View Activity Log
                    </a>
                  </div>
                </div>
              <?php endif; ?>

              <?php if (!$finalFormEnabled): ?>
                <div class="alert alert-info mb-0">
                  <?php if (!$finalEligibleConcept && !$currentFinalSubmission): ?>
                    Your advisers, committee chair, and panel are still ranking your concept proposals. You'll receive an alert here once a title is approved for final submission.
                  <?php elseif ($currentFinalSubmission && !in_array($currentFinalSubmission['status'] ?? '', ['Returned'], true)): ?>
                    Your final concept submission is currently <?= strtolower(htmlspecialchars($currentFinalSubmission['status'] ?? 'Pending')); ?>. Please wait for further updates from the Program Chairperson.
                  <?php else: ?>
                    Hang tight—your workspace is getting ready for the final upload.
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <form method="POST" enctype="multipart/form-data" class="mt-2">
                  <div class="mb-3">
                    <label class="form-label fw-semibold">Final Concept Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="final_title" value="<?= htmlspecialchars($finalFormData['final_title']); ?>" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-semibold">Abstract <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="final_abstract" rows="5" required><?= htmlspecialchars($finalFormData['final_abstract']); ?></textarea>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-semibold">Keywords <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="final_keywords" placeholder="Separate keywords with commas" value="<?= htmlspecialchars($finalFormData['final_keywords']); ?>" required>
                  </div>
                  <div class="mb-4">
                    <label class="form-label fw-semibold">Upload Final Concept Document <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" name="final_document" accept=".pdf,.doc,.docx" required>
                    <small class="text-muted d-block mt-1">Upload the polished concept paper as PDF or Word (max 10MB).</small>
                  </div>
                  <div class="d-grid">
                    <button type="submit" name="final_submit" value="1" class="btn btn-success btn-icon-gap">
                      <i class="bi bi-send-check"></i> Submit Final Concept
                    </button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-xl-5">
          <div class="card status-card h-100">
            <div class="card-header">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <p class="text-uppercase small text-muted mb-1">Final concept timeline</p>
                  <h5 class="mb-0">What's next?</h5>
                </div>
                <span class="badge bg-light text-success"><i class="bi bi-compass"></i></span>
              </div>
            </div>
            <div class="card-body">
              <ol class="list-group list-group-numbered list-group-flush">
                <li class="list-group-item">
                  <strong>Panel ranks your proposals</strong>
                  <p class="small text-muted mb-0">Once a title hits Rank #1, the final submission gate unlocks.</p>
                </li>
                <li class="list-group-item">
                  <strong>Upload the polished concept</strong>
                  <p class="small text-muted mb-0">Include the refined abstract, updated keywords, and the signed document.</p>
                </li>
                <li class="list-group-item">
                  <strong>Program Chair review</strong>
                  <p class="small text-muted mb-0">You'll receive approval or revision notes directly in this panel.</p>
                </li>
              </ol>
              <div class="alert alert-secondary mt-4 mb-0">
                <i class="bi bi-info-circle me-2"></i>
                Need help? Message your adviser or Program Chair before resubmitting.
              </div>
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
