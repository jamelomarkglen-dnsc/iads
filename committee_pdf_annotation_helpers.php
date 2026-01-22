<?php
/**
 * Committee PDF Annotation Helpers
 * Separate annotations for defense committee flow
 */

require_once 'db.php';

function create_committee_annotation(
    mysqli $conn,
    int $submission_id,
    int $reviewer_id,
    string $reviewer_role,
    string $annotation_type,
    string $annotation_content,
    int $page_number,
    float $x_coordinate,
    float $y_coordinate,
    ?string $selected_text = null,
    float $position_width = 5,
    float $position_height = 5
): array {
    $valid_types = ['comment', 'highlight', 'suggestion'];
    if (!in_array($annotation_type, $valid_types, true)) {
        return ['success' => false, 'error' => 'Invalid annotation type.'];
    }

    $annotation_content = trim($annotation_content);
    if ($annotation_content === '') {
        return ['success' => false, 'error' => 'Annotation content is required.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO committee_pdf_annotations (
            submission_id,
            reviewer_id,
            reviewer_role,
            annotation_type,
            annotation_content,
            page_number,
            x_coordinate,
            y_coordinate,
            selected_text,
            position_width,
            position_height,
            annotation_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param(
        'iisssidddds',
        $submission_id,
        $reviewer_id,
        $reviewer_role,
        $annotation_type,
        $annotation_content,
        $page_number,
        $x_coordinate,
        $y_coordinate,
        $selected_text,
        $position_width,
        $position_height
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create annotation: ' . $stmt->error];
    }
    $annotation_id = (int)$stmt->insert_id;
    $stmt->close();

    return ['success' => true, 'annotation_id' => $annotation_id];
}

function fetch_committee_annotation(mysqli $conn, int $annotation_id): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM committee_pdf_annotations
        WHERE annotation_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $annotation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: null;
}

function fetch_committee_submission_annotations(mysqli $conn, int $submission_id): array
{
    $stmt = $conn->prepare("
        SELECT
            a.*,
            CONCAT(u.firstname, ' ', u.lastname) AS reviewer_name,
            CONCAT(u.firstname, ' ', u.lastname) AS adviser_name,
            a.reviewer_id AS adviser_id,
            a.created_at AS creation_timestamp
        FROM committee_pdf_annotations a
        LEFT JOIN users u ON u.id = a.reviewer_id
        WHERE a.submission_id = ?
        ORDER BY a.page_number ASC, a.annotation_id ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function fetch_committee_page_annotations(mysqli $conn, int $submission_id, int $page_number): array
{
    $stmt = $conn->prepare("
        SELECT
            a.*,
            CONCAT(u.firstname, ' ', u.lastname) AS reviewer_name,
            CONCAT(u.firstname, ' ', u.lastname) AS adviser_name,
            a.reviewer_id AS adviser_id,
            a.created_at AS creation_timestamp
        FROM committee_pdf_annotations a
        LEFT JOIN users u ON u.id = a.reviewer_id
        WHERE a.submission_id = ? AND a.page_number = ?
        ORDER BY a.annotation_id ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $submission_id, $page_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function update_committee_annotation(mysqli $conn, int $annotation_id, string $annotation_content, int $changed_by): array
{
    $annotation_content = trim($annotation_content);
    if ($annotation_content === '') {
        return ['success' => false, 'error' => 'Annotation content cannot be empty.'];
    }
    $stmt = $conn->prepare("
        UPDATE committee_pdf_annotations
        SET annotation_content = ?
        WHERE annotation_id = ?
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('si', $annotation_content, $annotation_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to update annotation: ' . $stmt->error];
    }
    $stmt->close();
    return ['success' => true];
}

function resolve_committee_annotation(mysqli $conn, int $annotation_id): array
{
    $stmt = $conn->prepare("
        UPDATE committee_pdf_annotations
        SET annotation_status = 'resolved'
        WHERE annotation_id = ?
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('i', $annotation_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to resolve annotation: ' . $stmt->error];
    }
    $stmt->close();
    return ['success' => true];
}

function delete_committee_annotation(mysqli $conn, int $annotation_id): array
{
    $stmt = $conn->prepare("
        DELETE FROM committee_pdf_annotations
        WHERE annotation_id = ?
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('i', $annotation_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to delete annotation: ' . $stmt->error];
    }
    $stmt->close();
    return ['success' => true];
}

function add_committee_annotation_reply(mysqli $conn, int $annotation_id, int $user_id, string $reply_content, string $reply_user_role): array
{
    $valid_roles = ['student', 'adviser', 'committee_chairperson', 'panel'];
    if (!in_array($reply_user_role, $valid_roles, true)) {
        return ['success' => false, 'error' => 'Invalid user role.'];
    }
    $reply_content = trim($reply_content);
    if ($reply_content === '') {
        return ['success' => false, 'error' => 'Reply content cannot be empty.'];
    }
    $stmt = $conn->prepare("
        INSERT INTO committee_annotation_replies (
            annotation_id,
            user_id,
            reply_content,
            reply_user_role
        ) VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    $stmt->bind_param('iiss', $annotation_id, $user_id, $reply_content, $reply_user_role);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to add reply: ' . $stmt->error];
    }
    $reply_id = (int)$stmt->insert_id;
    $stmt->close();
    return ['success' => true, 'reply_id' => $reply_id];
}

function fetch_committee_annotation_replies(mysqli $conn, int $annotation_id): array
{
    $stmt = $conn->prepare("
        SELECT
            r.reply_id,
            r.annotation_id,
            r.user_id,
            r.reply_content,
            r.reply_timestamp,
            r.reply_user_role,
            CONCAT(u.firstname, ' ', u.lastname) AS user_name,
            u.email AS user_email
        FROM committee_annotation_replies r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.annotation_id = ?
        ORDER BY r.reply_timestamp ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $annotation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows ?: [];
}

function get_committee_annotation_statistics(mysqli $conn, int $submission_id): array
{
    $stmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT a.annotation_id) AS total_annotations,
            SUM(CASE WHEN a.annotation_status = 'active' THEN 1 ELSE 0 END) AS active_annotations,
            SUM(CASE WHEN a.annotation_status = 'resolved' THEN 1 ELSE 0 END) AS resolved_annotations,
            SUM(CASE WHEN a.annotation_type = 'comment' THEN 1 ELSE 0 END) AS comment_count,
            SUM(CASE WHEN a.annotation_type = 'highlight' THEN 1 ELSE 0 END) AS highlight_count,
            SUM(CASE WHEN a.annotation_type = 'suggestion' THEN 1 ELSE 0 END) AS suggestion_count
        FROM committee_pdf_annotations a
        WHERE a.submission_id = ?
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $row ?: [];
}
