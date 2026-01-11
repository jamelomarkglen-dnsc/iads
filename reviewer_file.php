<?php
session_start();
require_once 'db.php';

$allowedRoles = ['faculty', 'panel', 'committee_chair', 'committee_chairperson', 'adviser'];
$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if ($reviewerId <= 0 || !in_array($role, $allowedRoles, true)) {
    http_response_code(403);
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

$assignmentId = (int)($_GET['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare("
    SELECT cra.reviewer_id, cp.description
    FROM concept_reviewer_assignments cra
    LEFT JOIN concept_papers cp ON cp.id = cra.concept_paper_id
    WHERE cra.id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    exit;
}
$stmt->bind_param('i', $assignmentId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row || (int)($row['reviewer_id'] ?? 0) !== $reviewerId) {
    http_response_code(403);
    exit;
}

$description = trim((string)($row['description'] ?? ''));
$prefix = 'submission_ref:';
if ($description === '' || strpos($description, $prefix) !== 0) {
    http_response_code(404);
    exit;
}

$parts = explode(':', $description);
if (count($parts) < 3) {
    http_response_code(404);
    exit;
}
$submissionId = (int)($parts[1] ?? 0);
$slot = (int)($parts[2] ?? 0);
if ($submissionId <= 0 || $slot < 1 || $slot > 3) {
    http_response_code(404);
    exit;
}

$columnMap = [
    1 => 'concept_file_1',
    2 => 'concept_file_2',
    3 => 'concept_file_3',
];
$fileColumn = $columnMap[$slot] ?? '';
if ($fileColumn === '' || !columnExists($conn, 'submissions', $fileColumn)) {
    http_response_code(404);
    exit;
}

$fileStmt = $conn->prepare("SELECT {$fileColumn} AS file_path FROM submissions WHERE id = ? LIMIT 1");
if (!$fileStmt) {
    http_response_code(500);
    exit;
}
$fileStmt->bind_param('i', $submissionId);
$fileStmt->execute();
$fileRes = $fileStmt->get_result();
$fileRow = $fileRes ? $fileRes->fetch_assoc() : null;
$fileStmt->close();

$filePath = trim((string)($fileRow['file_path'] ?? ''));
if ($filePath === '' || !is_file($filePath)) {
    http_response_code(404);
    exit;
}

$rootPath = realpath(__DIR__);
$fullPath = realpath($filePath);
if ($rootPath === false || $fullPath === false || strpos($fullPath, $rootPath) !== 0) {
    http_response_code(403);
    exit;
}

$extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
if ($extension !== 'pdf') {
    http_response_code(415);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($fullPath);
exit;
