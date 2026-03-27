<?php
/**
 * Cleanup script to remove non-comment annotations safely.
 *
 * Usage:
 * 1) Visit cleanup_annotation_types.php to see counts.
 * 2) Visit cleanup_annotation_types.php?confirm=1 to delete.
 */

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$allowedRoles = ['dean', 'program_chairperson', 'adviser', 'committee_chairperson'];
$role = $_SESSION['role'] ?? '';
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo 'Unauthorized.';
    exit;
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $exists;
}

function count_non_comment(mysqli $conn, string $table): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM {$table} WHERE annotation_type IN ('highlight', 'suggestion')");
    if (!$stmt) {
        return 0;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result ? (int)($result->fetch_assoc()['total'] ?? 0) : 0;
    $stmt->close();
    return $count;
}

$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';

$hasPdfAnnotations = table_exists($conn, 'pdf_annotations');
$hasAnnotationReplies = table_exists($conn, 'annotation_replies');
$hasCommitteeAnnotations = table_exists($conn, 'committee_pdf_annotations');
$hasCommitteeReplies = table_exists($conn, 'committee_annotation_replies');

$pdfCount = $hasPdfAnnotations ? count_non_comment($conn, 'pdf_annotations') : 0;
$committeeCount = $hasCommitteeAnnotations ? count_non_comment($conn, 'committee_pdf_annotations') : 0;

if (!$confirm) {
    echo "<h3>Non-comment annotation cleanup</h3>";
    echo "<p>PDF annotations to delete: <strong>{$pdfCount}</strong></p>";
    echo "<p>Committee annotations to delete: <strong>{$committeeCount}</strong></p>";
    echo "<p>To proceed, open: <code>cleanup_annotation_types.php?confirm=1</code></p>";
    exit;
}

try {
    $conn->begin_transaction();

    if ($hasPdfAnnotations && $pdfCount > 0) {
        if ($hasAnnotationReplies) {
            $conn->query("
                DELETE ar
                FROM annotation_replies ar
                INNER JOIN pdf_annotations pa ON pa.annotation_id = ar.annotation_id
                WHERE pa.annotation_type IN ('highlight', 'suggestion')
            ");
        }
        $conn->query("DELETE FROM pdf_annotations WHERE annotation_type IN ('highlight', 'suggestion')");
    }

    if ($hasCommitteeAnnotations && $committeeCount > 0) {
        if ($hasCommitteeReplies) {
            $conn->query("
                DELETE cr
                FROM committee_annotation_replies cr
                INNER JOIN committee_pdf_annotations ca ON ca.annotation_id = cr.annotation_id
                WHERE ca.annotation_type IN ('highlight', 'suggestion')
            ");
        }
        $conn->query("DELETE FROM committee_pdf_annotations WHERE annotation_type IN ('highlight', 'suggestion')");
    }

    $conn->commit();
    echo "<h3>Cleanup complete</h3>";
    echo "<p>Removed non-comment annotations safely.</p>";
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo "<h3>Cleanup failed</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
