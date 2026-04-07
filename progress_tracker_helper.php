<?php

require_once __DIR__ . '/notifications_helper.php';
require_once __DIR__ . '/final_paper_helpers.php';
require_once __DIR__ . '/defense_committee_helpers.php';
require_once __DIR__ . '/defense_schedule_helpers.php';
require_once __DIR__ . '/pdf_submission_helpers.php';
require_once __DIR__ . '/submission_helpers.php';

if (!defined('PROGRESS_TRACKER_SEED_ON_EMPTY')) {
    define('PROGRESS_TRACKER_SEED_ON_EMPTY', true);
}

if (!function_exists('progress_tracker_step_definitions')) {
    function progress_tracker_step_definitions(): array
    {
        return [
            ['key' => 'concept_submitted', 'label' => 'Concept / Thesis / Capstone / Dissertation Submitted'],
            ['key' => 'concept_review_assigned', 'label' => 'Concept Review Assigned / In Review'],
            ['key' => 'final_concept_recommended', 'label' => 'Final Concept Recommended'],
            ['key' => 'concept_pdf_submitted', 'label' => 'Concept Paper Submitted to Adviser (PDF Submission)'],
            ['key' => 'endorsement_submitted', 'label' => 'Endorsement Request Submitted'],
            ['key' => 'endorsement_verified', 'label' => 'Endorsement Verified'],
            ['key' => 'payment_submitted', 'label' => 'Payment Proof Submitted'],
            ['key' => 'payment_verified', 'label' => 'Payment Verified'],
            ['key' => 'committee_memo_issued', 'label' => 'Defense Committee Memo Issued'],
            ['key' => 'outline_submitted', 'label' => 'Outline Defense Manuscript Submitted'],
            ['key' => 'outline_review_completed', 'label' => 'Outline Defense Review Completed'],
            ['key' => 'outline_verdict_released', 'label' => 'Outline Defense Verdict Released'],
            ['key' => 'revision_completed', 'label' => 'Student/Adviser Revision Completed'],
            ['key' => 'route_slip_issued', 'label' => 'Route Slip for Outline Issued'],
            ['key' => 'notice_submitted', 'label' => 'Notice to Commence Submitted'],
            ['key' => 'notice_approved', 'label' => 'Notice to Commence Approved'],
            ['key' => 'final_endorsement_submitted', 'label' => 'Final Endorsement Submitted'],
            ['key' => 'final_endorsement_approved', 'label' => 'Final Endorsement Approved'],
            ['key' => 'final_payment_submitted', 'label' => 'Payment Proof Submitted (Final)'],
            ['key' => 'final_payment_verified', 'label' => 'Payment Verified (Final)'],
            ['key' => 'final_defense_scheduled', 'label' => 'Final Defense Scheduled'],
            ['key' => 'final_defense_outcome', 'label' => 'Final Defense Outcome Recorded'],
            ['key' => 'final_routing_submitted', 'label' => 'Final Routing Submitted'],
            ['key' => 'final_routing_passed', 'label' => 'Final Routing Passed'],
            ['key' => 'final_hardbound_submitted', 'label' => 'Final Hardbound Submitted'],
            ['key' => 'final_hardbound_passed', 'label' => 'Final Hardbound Passed / Verified'],
            ['key' => 'institutional_copy', 'label' => 'Institutional Final Research Copy'],
            ['key' => 'archived', 'label' => 'Archived'],
        ];
    }
}

if (!function_exists('progress_tracker_label_to_key_map')) {
    function progress_tracker_label_to_key_map(): array
    {
        $map = [];
        foreach (progress_tracker_step_definitions() as $step) {
            $map[$step['label']] = $step['key'];
        }
        return $map;
    }
}

if (!function_exists('ensure_progress_tracker_table')) {
    function ensure_progress_tracker_table(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $conn->query("
            CREATE TABLE IF NOT EXISTS progress_tracker_steps (
                student_id INT NOT NULL,
                step_key VARCHAR(64) NOT NULL,
                status ENUM('pending','complete') NOT NULL DEFAULT 'pending',
                completed_at DATETIME NULL,
                source_table VARCHAR(64) NULL,
                source_id INT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (student_id, step_key),
                INDEX idx_progress_student (student_id),
                CONSTRAINT fk_progress_tracker_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $ensured = true;
    }
}

if (!function_exists('progress_tracker_count_rows')) {
    function progress_tracker_count_rows(mysqli $conn, int $studentId): int
    {
        if ($studentId <= 0) {
            return 0;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM progress_tracker_steps WHERE student_id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('ensure_progress_tracker_rows')) {
    function ensure_progress_tracker_rows(mysqli $conn, int $studentId): void
    {
        if ($studentId <= 0) {
            return;
        }
        $stmt = $conn->prepare("
            INSERT INTO progress_tracker_steps (student_id, step_key)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE step_key = VALUES(step_key)
        ");
        if (!$stmt) {
            return;
        }
        foreach (progress_tracker_step_definitions() as $step) {
            $key = $step['key'];
            $stmt->bind_param('is', $studentId, $key);
            $stmt->execute();
        }
        $stmt->close();
    }
}

if (!function_exists('progress_tracker_fetch_rows')) {
    function progress_tracker_fetch_rows(mysqli $conn, int $studentId): array
    {
        if ($studentId <= 0) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT step_key, status, completed_at
            FROM progress_tracker_steps
            WHERE student_id = ?
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[$row['step_key']] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('progress_tracker_mark_step_complete')) {
    function progress_tracker_mark_step_complete(
        mysqli $conn,
        int $studentId,
        string $stepKey,
        ?string $sourceTable = null,
        ?int $sourceId = null,
        ?string $completedAt = null
    ): bool {
        if ($studentId <= 0 || $stepKey === '') {
            return false;
        }
        ensure_progress_tracker_table($conn);
        ensure_progress_tracker_rows($conn, $studentId);
        $completedAt = $completedAt ?: date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            UPDATE progress_tracker_steps
            SET status = 'complete',
                completed_at = COALESCE(completed_at, ?),
                source_table = COALESCE(?, source_table),
                source_id = COALESCE(?, source_id)
            WHERE student_id = ? AND step_key = ?
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssiss', $completedAt, $sourceTable, $sourceId, $studentId, $stepKey);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('progress_tracker_reset_student_progress')) {
    function progress_tracker_reset_student_progress(mysqli $conn, int $studentId): void
    {
        if ($studentId <= 0) {
            return;
        }
        ensure_progress_tracker_table($conn);
        $stmt = $conn->prepare("
            UPDATE progress_tracker_steps
            SET status = 'pending',
                completed_at = NULL,
                source_table = NULL,
                source_id = NULL
            WHERE student_id = ?
        ");
        if ($stmt) {
            $stmt->bind_param('i', $studentId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('progress_tracker_build_data_from_rows')) {
    function progress_tracker_build_data_from_rows(array $rows): array
    {
        $progressSteps = [];
        foreach (progress_tracker_step_definitions() as $stepDef) {
            $key = $stepDef['key'];
            $status = $rows[$key]['status'] ?? 'pending';
            $progressSteps[] = [
                'label' => $stepDef['label'],
                'complete' => ($status === 'complete'),
            ];
        }

        $firstIncompleteIndex = null;
        foreach ($progressSteps as $index => $step) {
            if (empty($step['complete'])) {
                $firstIncompleteIndex = $index;
                break;
            }
        }
        foreach ($progressSteps as $index => $step) {
            if (!empty($step['complete'])) {
                $progressSteps[$index]['state'] = 'complete';
            } elseif ($firstIncompleteIndex === $index) {
                $progressSteps[$index]['state'] = 'current';
            } else {
                $progressSteps[$index]['state'] = 'pending';
            }
        }

        $completedSteps = 0;
        foreach ($progressSteps as $step) {
            if (!empty($step['complete'])) {
                $completedSteps++;
            }
        }
        $totalSteps = count($progressSteps);
        $currentStepLabel = $firstIncompleteIndex === null
            ? 'All steps completed'
            : ($progressSteps[$firstIncompleteIndex]['label'] ?? 'In progress');
        $progressPercent = $totalSteps > 0 ? (int)round(($completedSteps / $totalSteps) * 100) : 0;
        $progressPercent = min(100, max(0, $progressPercent));

        return [
            'steps' => $progressSteps,
            'completed' => $completedSteps,
            'total' => $totalSteps,
            'current' => $currentStepLabel,
            'percent' => $progressPercent,
        ];
    }
}

if (!function_exists('progress_tracker_seed_from_legacy')) {
    function progress_tracker_seed_from_legacy(mysqli $conn, int $studentId, array $legacyData): void
    {
        if ($studentId <= 0 || empty($legacyData['steps'])) {
            return;
        }
        $labelMap = progress_tracker_label_to_key_map();
        foreach ($legacyData['steps'] as $step) {
            $label = $step['label'] ?? '';
            if ($label === '' || empty($step['complete'])) {
                continue;
            }
            $key = $labelMap[$label] ?? null;
            if ($key) {
                progress_tracker_mark_step_complete($conn, $studentId, $key, 'legacy_seed', null);
            }
        }
    }
}

if (!function_exists('progress_tracker_student_has_final_routing_passed')) {
    function progress_tracker_student_has_final_routing_passed(mysqli $conn, int $studentId): bool
    {
        if ($studentId <= 0) {
            return false;
        }
        if (!progress_tracker_column_exists($conn, 'final_routing_submissions', 'status')) {
            return false;
        }
        $stmt = $conn->prepare("
            SELECT 1
            FROM final_routing_submissions
            WHERE student_id = ? AND status = 'Passed'
            LIMIT 1
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
}

if (!function_exists('progress_tracker_student_has_final_endorsement_approved')) {
    function progress_tracker_student_has_final_endorsement_approved(mysqli $conn, int $studentId): bool
    {
        if ($studentId <= 0) {
            return false;
        }
        $status = null;
        if (progress_tracker_column_exists($conn, 'final_endorsement_submissions', 'status')) {
            $stmt = $conn->prepare("
                SELECT status
                FROM final_endorsement_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $status = $row ? trim((string)($row['status'] ?? '')) : null;
                $stmt->close();
            }
        }
        if (
            $status === null
            && progress_tracker_column_exists($conn, 'final_defense_endorsements', 'status')
        ) {
            $stmt = $conn->prepare("
                SELECT status
                FROM final_defense_endorsements
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $status = $row ? trim((string)($row['status'] ?? '')) : null;
                $stmt->close();
            }
        }
        return in_array($status, ['Approved', 'Verified'], true);
    }
}

if (!function_exists('progress_tracker_column_exists')) {
    function progress_tracker_column_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
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
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        $cache[$key] = $exists;
        return $exists;
    }
}

if (!function_exists('progress_tracker_compute_legacy_data')) {
    function progress_tracker_compute_legacy_data(mysqli $conn, int $studentId): ?array
    {
        static $cache = [];
        if ($studentId <= 0) {
            return null;
        }
        if (isset($cache[$studentId])) {
            return $cache[$studentId];
        }

        if (function_exists('ensureFinalPaperTables')) {
            ensureFinalPaperTables($conn);
        }
        if (function_exists('ensureDefenseCommitteeRequestsTable')) {
            ensureDefenseCommitteeRequestsTable($conn);
        }
        if (function_exists('ensureDefensePanelMemberColumns')) {
            ensureDefensePanelMemberColumns($conn);
        }
        if (function_exists('ensureDefenseScheduleTypeColumn')) {
            ensureDefenseScheduleTypeColumn($conn);
        }

        $submissionsTableExists = progress_tracker_column_exists($conn, 'submissions', 'id');
        $submissionHasUpdatedAt = $submissionsTableExists ? progress_tracker_column_exists($conn, 'submissions', 'updated_at') : false;
        $submissionHasArchivedAt = $submissionsTableExists ? progress_tracker_column_exists($conn, 'submissions', 'archived_at') : false;

        $totalSubmissions = 0;
        if ($submissionsTableExists) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM submissions WHERE student_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                if ($stmt->execute()) {
                    $row = $stmt->get_result()->fetch_assoc();
                    $totalSubmissions = (int)($row['total'] ?? 0);
                }
                $stmt->close();
            }
        }

        if ($totalSubmissions === 0) {
            $progressSteps = [
                ['label' => 'Concept / Thesis / Capstone / Dissertation Submitted', 'complete' => false],
                ['label' => 'Concept Review Assigned / In Review', 'complete' => false],
                ['label' => 'Final Concept Recommended', 'complete' => false],
                ['label' => 'Concept Paper Submitted to Adviser (PDF Submission)', 'complete' => false],
                ['label' => 'Endorsement Request Submitted', 'complete' => false],
                ['label' => 'Endorsement Verified', 'complete' => false],
                ['label' => 'Payment Proof Submitted', 'complete' => false],
                ['label' => 'Payment Verified', 'complete' => false],
                ['label' => 'Defense Committee Memo Issued', 'complete' => false],
                ['label' => 'Outline Defense Manuscript Submitted', 'complete' => false],
                ['label' => 'Outline Defense Review Completed', 'complete' => false],
                ['label' => 'Outline Defense Verdict Released', 'complete' => false],
                ['label' => 'Student/Adviser Revision Completed', 'complete' => false],
                ['label' => 'Route Slip for Outline Issued', 'complete' => false],
                ['label' => 'Notice to Commence Submitted', 'complete' => false],
                ['label' => 'Notice to Commence Approved', 'complete' => false],
                ['label' => 'Final Endorsement Submitted', 'complete' => false],
                ['label' => 'Final Endorsement Approved', 'complete' => false],
                ['label' => 'Payment Proof Submitted (Final)', 'complete' => false],
                ['label' => 'Payment Verified (Final)', 'complete' => false],
                ['label' => 'Final Defense Scheduled', 'complete' => false],
                ['label' => 'Final Defense Outcome Recorded', 'complete' => false],
                ['label' => 'Final Routing Submitted', 'complete' => false],
                ['label' => 'Final Routing Passed', 'complete' => false],
                ['label' => 'Final Hardbound Submitted', 'complete' => false],
                ['label' => 'Final Hardbound Passed / Verified', 'complete' => false],
                ['label' => 'Institutional Final Research Copy', 'complete' => false],
                ['label' => 'Archived', 'complete' => false],
            ];

            $firstIncompleteIndex = 0;
            foreach ($progressSteps as $index => $step) {
                if (!empty($step['complete'])) {
                    $progressSteps[$index]['state'] = 'complete';
                } elseif ($firstIncompleteIndex === $index) {
                    $progressSteps[$index]['state'] = 'current';
                } else {
                    $progressSteps[$index]['state'] = 'pending';
                }
            }
            $completedSteps = 0;
            $totalSteps = count($progressSteps);
            $currentStepLabel = $progressSteps[$firstIncompleteIndex]['label'] ?? 'In progress';
            $progressPercent = 0;

            $cache[$studentId] = [
                'steps' => $progressSteps,
                'completed' => $completedSteps,
                'total' => $totalSteps,
                'current' => $currentStepLabel,
                'percent' => $progressPercent,
            ];

            return $cache[$studentId];
        }

        $conceptSubmissionComplete = $totalSubmissions > 0;
        $conceptReviewAssigned = false;
        if (progress_tracker_column_exists($conn, 'concept_reviewer_assignments', 'id')) {
            $stmt = $conn->prepare("SELECT 1 FROM concept_reviewer_assignments WHERE student_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $stmt->store_result();
                $conceptReviewAssigned = $stmt->num_rows > 0;
                $stmt->close();
            }
        }

        $hasFinalPickRecommendation = false;
        if (function_exists('notifications_bootstrap')) {
            notifications_bootstrap($conn);
        }
        if (progress_tracker_column_exists($conn, 'notifications', 'id')) {
            $stmt = $conn->prepare("
                SELECT 1
                FROM notifications
                WHERE user_id = ? AND title = 'Final concept recommendation'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $stmt->store_result();
                $hasFinalPickRecommendation = $stmt->num_rows > 0;
                $stmt->close();
            }
        }
        if (!$hasFinalPickRecommendation && progress_tracker_column_exists($conn, 'final_pick_messages', 'id')) {
            $stmt = $conn->prepare("
                SELECT sent_at
                FROM final_pick_messages
                WHERE student_id = ?
                ORDER BY sent_at DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $hasFinalPickRecommendation = !empty($row['sent_at']);
                $stmt->close();
            }
        }

        $conceptPdfSubmitted = false;
        if (progress_tracker_column_exists($conn, 'pdf_submissions', 'submission_id')) {
            $stmt = $conn->prepare("
                SELECT 1
                FROM pdf_submissions ps
                WHERE ps.student_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM pdf_submissions child
                    WHERE child.parent_submission_id = ps.submission_id
                )
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $stmt->store_result();
                $conceptPdfSubmitted = $stmt->num_rows > 0;
                $stmt->close();
            }
        }

        $endorsementStatus = null;
        if (progress_tracker_column_exists($conn, 'endorsement_requests', 'id')) {
            $stmt = $conn->prepare("
                SELECT status
                FROM endorsement_requests
                WHERE student_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $endorsementStatus = $row ? trim((string)($row['status'] ?? '')) : null;
                $stmt->close();
            }
        }
        $endorsementSubmitted = $endorsementStatus !== null;
        $endorsementVerified = $endorsementStatus === 'Verified';

        $paymentStatus = null;
        $paymentTimestamp = null;
        if (progress_tracker_column_exists($conn, 'payment_proofs', 'id')) {
            $stmt = $conn->prepare("
                SELECT status, created_at, updated_at
                FROM payment_proofs
                WHERE user_id = ?
                ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $paymentStatus = $row['status'] ?? null;
                    $paymentTimestamp = $row['updated_at'] ?? $row['created_at'] ?? null;
                }
                $stmt->close();
            }
        }
        $paymentSubmitted = $paymentStatus !== null;
        $paymentVerified = $paymentStatus === 'payment_accepted';

        $committeeMemoAvailable = false;
        if (progress_tracker_column_exists($conn, 'defense_committee_requests', 'id') && progress_tracker_column_exists($conn, 'defense_schedules', 'id')) {
            $stmt = $conn->prepare("
                SELECT r.status, r.memo_final_title, r.memo_received_at
                FROM defense_committee_requests r
                JOIN defense_schedules ds ON ds.id = r.defense_id
                WHERE r.student_id = ?
                ORDER BY r.reviewed_at DESC, r.requested_at DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $committeeStatusLabel = trim((string)($row['status'] ?? '')) ?: 'Pending';
                    $committeeMemoAvailable = $committeeStatusLabel === 'Approved';
                }
                $stmt->close();
            }
        }
        $committeeMemoIssued = $committeeMemoAvailable;

        $finalPaperSubmission = null;
        if (progress_tracker_column_exists($conn, 'final_paper_submissions', 'id') && function_exists('fetchLatestFinalPaperSubmission')) {
            $finalPaperSubmission = fetchLatestFinalPaperSubmission($conn, $studentId);
        }
        $finalPaperStatusLabel = 'Not submitted';
        if ($finalPaperSubmission) {
            $finalPaperStatusLabel = trim((string)($finalPaperSubmission['status'] ?? ''));
            if ($finalPaperStatusLabel === '') {
                $finalPaperStatusLabel = 'Submitted';
            }
        }
        $outlineSubmitted = $finalPaperSubmission !== null;
        $outlineReviewCompleted = false;
        if ($finalPaperSubmission) {
            $completedAt = $finalPaperSubmission['committee_reviews_completed_at'] ?? null;
            if (!empty($completedAt)) {
                $outlineReviewCompleted = true;
            } elseif (progress_tracker_column_exists($conn, 'final_paper_reviews', 'id')) {
                $stmt = $conn->prepare("
                    SELECT
                        COUNT(*) AS total_reviews,
                        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_reviews
                    FROM final_paper_reviews
                    WHERE submission_id = ?
                ");
                if ($stmt) {
                    $submissionId = (int)($finalPaperSubmission['id'] ?? 0);
                    $stmt->bind_param('i', $submissionId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $totalReviews = (int)($row['total_reviews'] ?? 0);
                    $pendingReviews = (int)($row['pending_reviews'] ?? 0);
                    $outlineReviewCompleted = $totalReviews > 0 && $pendingReviews === 0;
                    $stmt->close();
                }
            }
        }
        $outlineVerdictReleased = !empty(trim((string)($finalPaperSubmission['outline_defense_verdict'] ?? '')));
        $revisionCompleted = strcasecmp($finalPaperStatusLabel, 'Approved') === 0;
        $verdictValue = strtolower(trim((string)($finalPaperSubmission['outline_defense_verdict'] ?? '')));
        $reviewGateStatus = strtolower(trim((string)($finalPaperSubmission['review_gate_status'] ?? '')));
        $passedVerdicts = ['passed', 'passed with revision'];
        $passedGateStatuses = ['passed', 'passed with minor revision', 'passed with major revision'];
        $hasFailVerdict = $verdictValue !== ''
            && (in_array($verdictValue, ['failed'], true) || stripos($verdictValue, 'redefense') !== false);
        $hasFailGate = $reviewGateStatus !== ''
            && (in_array($reviewGateStatus, ['failed', 'redefense required'], true) || stripos($reviewGateStatus, 'redefense') !== false);
        if (!$hasFailVerdict && !$hasFailGate) {
            if (in_array($verdictValue, $passedVerdicts, true) || in_array($reviewGateStatus, $passedGateStatuses, true)) {
                $revisionCompleted = true;
            }
        }
        $routeSlipDecision = strtolower(trim((string)($finalPaperSubmission['route_slip_overall_decision'] ?? '')));
        $routeSlipSignedAt = $finalPaperSubmission['route_slip_signed_at'] ?? null;
        $routeSlipIssued = !empty($routeSlipSignedAt)
            || in_array($routeSlipDecision, ['approved', 'passed with minor revision', 'passed with major revision'], true);

        $noticeStatus = null;
        if (progress_tracker_column_exists($conn, 'notice_to_commence_requests', 'id')) {
            $stmt = $conn->prepare("
                SELECT status
                FROM notice_to_commence_requests
                WHERE student_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $noticeStatus = $row ? trim((string)($row['status'] ?? '')) : null;
                $stmt->close();
            }
        }
        $noticeSubmitted = $noticeStatus !== null;
        $noticeApproved = $noticeStatus === 'Approved';

        $finalRoutingStatus = null;
        $finalRoutingTimestamp = null;
        if (progress_tracker_column_exists($conn, 'final_routing_submissions', 'id')) {
            $stmt = $conn->prepare("
                SELECT status, submitted_at, updated_at
                FROM final_routing_submissions
                WHERE student_id = ?
                ORDER BY COALESCE(updated_at, submitted_at) DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $finalRoutingStatus = $row['status'] ?? null;
                    $finalRoutingTimestamp = $row['updated_at'] ?? $row['submitted_at'] ?? null;
                }
                $stmt->close();
            }
        }
        $finalRoutingSubmitted = $finalRoutingStatus !== null;
        $finalRoutingPassed = $finalRoutingStatus === 'Passed';
        $finalPaymentSubmitted = false;
        $finalPaymentVerified = false;
        if ($finalEndorsementApproved && $paymentTimestamp) {
            $finalPaymentSubmitted = true;
            $finalPaymentVerified = $paymentStatus === 'payment_accepted';
        }

        $finalDefenseScheduled = false;
        if (progress_tracker_column_exists($conn, 'final_defense_submissions', 'id')) {
            $stmt = $conn->prepare("
                SELECT submitted_at
                FROM final_defense_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $finalDefenseScheduled = !empty($row['submitted_at']);
                }
                $stmt->close();
            }
        }
        if (!$finalDefenseScheduled && progress_tracker_column_exists($conn, 'defense_schedules', 'id')) {
            if (progress_tracker_column_exists($conn, 'defense_schedules', 'schedule_type')) {
                $stmt = $conn->prepare("
                    SELECT defense_date
                    FROM defense_schedules
                    WHERE student_id = ?
                      AND schedule_type = 'final'
                    ORDER BY defense_date ASC, defense_time ASC
                    LIMIT 1
                ");
            } else {
                $stmt = $conn->prepare("
                    SELECT defense_date
                    FROM defense_schedules
                    WHERE student_id = ?
                    ORDER BY defense_date ASC, defense_time ASC
                    LIMIT 1
                ");
            }
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $finalDefenseScheduled = !empty($row['defense_date']);
                }
                $stmt->close();
            }
        }

        $finalDefenseOutcome = false;
        if (progress_tracker_column_exists($conn, 'final_defense_submissions', 'id')) {
            $stmt = $conn->prepare("
                SELECT status, reviewed_at
                FROM final_defense_submissions
                WHERE student_id = ?
                ORDER BY COALESCE(reviewed_at, submitted_at) DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $status = trim((string)($row['status'] ?? ''));
                    $finalDefenseOutcome = $status !== '' && $status !== 'Submitted';
                }
                $stmt->close();
            }
        }
        if (!$finalDefenseOutcome && progress_tracker_column_exists($conn, 'defense_outcomes', 'id')) {
            $stmt = $conn->prepare("SELECT 1 FROM defense_outcomes WHERE student_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $stmt->store_result();
                $finalDefenseOutcome = $stmt->num_rows > 0;
                $stmt->close();
            }
        }

        $finalEndorsementStatus = null;
        if (progress_tracker_column_exists($conn, 'final_endorsement_submissions', 'id')) {
            $stmt = $conn->prepare("
                SELECT status
                FROM final_endorsement_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $finalEndorsementStatus = $row ? trim((string)($row['status'] ?? '')) : null;
                $stmt->close();
            }
        }
        if (
            $finalEndorsementStatus === null
            && progress_tracker_column_exists($conn, 'final_defense_endorsements', 'id')
        ) {
            $stmt = $conn->prepare("
                SELECT status
                FROM final_defense_endorsements
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $finalEndorsementStatus = $row ? trim((string)($row['status'] ?? '')) : null;
                $stmt->close();
            }
        }
        $finalEndorsementSubmitted = $finalEndorsementStatus !== null;
        $finalEndorsementApproved = in_array($finalEndorsementStatus, ['Approved', 'Verified'], true);

        $finalHardboundStatus = null;
        if (progress_tracker_column_exists($conn, 'final_hardbound_submissions', 'id')) {
            $stmt = $conn->prepare("
                SELECT status
                FROM final_hardbound_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $finalHardboundStatus = $row ? trim((string)($row['status'] ?? '')) : null;
                $stmt->close();
            }
        }
        $finalHardboundSubmitted = $finalHardboundStatus !== null;
        $finalHardboundPassed = in_array($finalHardboundStatus, ['Passed', 'Verified', 'Approved'], true);

        $institutionalCopyStored = false;
        if (progress_tracker_column_exists($conn, 'institutional_final_copies', 'id')) {
            $stmt = $conn->prepare("SELECT 1 FROM institutional_final_copies WHERE student_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $stmt->store_result();
                $institutionalCopyStored = $stmt->num_rows > 0;
                $stmt->close();
            }
        }

        $archived = false;
        if (progress_tracker_column_exists($conn, 'research_archive', 'id')) {
            $stmt = $conn->prepare("
                SELECT 1
                FROM research_archive
                WHERE student_id = ? AND status = 'Archived'
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $stmt->store_result();
                $archived = $stmt->num_rows > 0;
                $stmt->close();
            }
        }
        if (!$archived && $submissionHasUpdatedAt && $submissionHasArchivedAt) {
            $stmt = $conn->prepare("
                SELECT 1
                FROM submissions
                WHERE student_id = ? AND archived_at IS NOT NULL
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $stmt->store_result();
                $archived = $stmt->num_rows > 0;
                $stmt->close();
            }
        }

        $progressSteps = [
            ['label' => 'Concept / Thesis / Capstone / Dissertation Submitted', 'complete' => $conceptSubmissionComplete],
            ['label' => 'Concept Review Assigned / In Review', 'complete' => $conceptReviewAssigned],
            ['label' => 'Final Concept Recommended', 'complete' => $hasFinalPickRecommendation],
            ['label' => 'Concept Paper Submitted to Adviser (PDF Submission)', 'complete' => $conceptPdfSubmitted],
            ['label' => 'Endorsement Request Submitted', 'complete' => $endorsementSubmitted],
            ['label' => 'Endorsement Verified', 'complete' => $endorsementVerified],
            ['label' => 'Payment Proof Submitted', 'complete' => $paymentSubmitted],
            ['label' => 'Payment Verified', 'complete' => $paymentVerified],
            ['label' => 'Defense Committee Memo Issued', 'complete' => $committeeMemoIssued],
            ['label' => 'Outline Defense Manuscript Submitted', 'complete' => $outlineSubmitted],
            ['label' => 'Outline Defense Review Completed', 'complete' => $outlineReviewCompleted],
            ['label' => 'Outline Defense Verdict Released', 'complete' => $outlineVerdictReleased],
            ['label' => 'Student/Adviser Revision Completed', 'complete' => $revisionCompleted],
            ['label' => 'Route Slip for Outline Issued', 'complete' => $routeSlipIssued],
            ['label' => 'Notice to Commence Submitted', 'complete' => $noticeSubmitted],
            ['label' => 'Notice to Commence Approved', 'complete' => $noticeApproved],
            ['label' => 'Final Routing Submitted', 'complete' => $finalRoutingSubmitted],
            ['label' => 'Final Routing Passed', 'complete' => $finalRoutingPassed],
            ['label' => 'Payment Proof Submitted (Final)', 'complete' => $finalPaymentSubmitted],
            ['label' => 'Payment Verified (Final)', 'complete' => $finalPaymentVerified],
            ['label' => 'Final Defense Scheduled', 'complete' => $finalDefenseScheduled],
            ['label' => 'Final Defense Outcome Recorded', 'complete' => $finalDefenseOutcome],
            ['label' => 'Final Endorsement Submitted', 'complete' => $finalEndorsementSubmitted],
            ['label' => 'Final Endorsement Approved', 'complete' => $finalEndorsementApproved],
            ['label' => 'Final Hardbound Submitted', 'complete' => $finalHardboundSubmitted],
            ['label' => 'Final Hardbound Passed / Verified', 'complete' => $finalHardboundPassed],
            ['label' => 'Institutional Final Research Copy', 'complete' => $institutionalCopyStored],
            ['label' => 'Archived', 'complete' => $archived],
        ];

        $firstIncompleteIndex = null;
        foreach ($progressSteps as $index => $step) {
            if (empty($step['complete'])) {
                $firstIncompleteIndex = $index;
                break;
            }
        }
        foreach ($progressSteps as $index => $step) {
            if (!empty($step['complete'])) {
                $progressSteps[$index]['state'] = 'complete';
            } elseif ($firstIncompleteIndex === $index) {
                $progressSteps[$index]['state'] = 'current';
            } else {
                $progressSteps[$index]['state'] = 'pending';
            }
        }

        $completedSteps = 0;
        foreach ($progressSteps as $step) {
            if (!empty($step['complete'])) {
                $completedSteps++;
            }
        }
        $totalSteps = count($progressSteps);
        $currentStepLabel = $firstIncompleteIndex === null
            ? 'All steps completed'
            : ($progressSteps[$firstIncompleteIndex]['label'] ?? 'In progress');
        $progressPercent = $totalSteps > 0 ? (int)round(($completedSteps / $totalSteps) * 100) : 0;
        $progressPercent = min(100, max(0, $progressPercent));

        $cache[$studentId] = [
            'steps' => $progressSteps,
            'completed' => $completedSteps,
            'total' => $totalSteps,
            'current' => $currentStepLabel,
            'percent' => $progressPercent,
        ];

        return $cache[$studentId];
    }
}

if (!function_exists('get_student_progress_tracker_data')) {
    function get_student_progress_tracker_data(mysqli $conn, int $studentId): ?array
    {
        static $cache = [];
        if ($studentId <= 0) {
            return null;
        }
        if (isset($cache[$studentId])) {
            return $cache[$studentId];
        }

        ensure_progress_tracker_table($conn);
        $existingCount = progress_tracker_count_rows($conn, $studentId);
        if ($existingCount === 0) {
            ensure_progress_tracker_rows($conn, $studentId);
            if (PROGRESS_TRACKER_SEED_ON_EMPTY && function_exists('progress_tracker_compute_legacy_data')) {
                $legacyData = progress_tracker_compute_legacy_data($conn, $studentId);
                if ($legacyData) {
                    progress_tracker_seed_from_legacy($conn, $studentId, $legacyData);
                }
            }
        }

        $rows = progress_tracker_fetch_rows($conn, $studentId);
        if ($rows) {
            if (function_exists('ensureDefenseScheduleTypeColumn')) {
                ensureDefenseScheduleTypeColumn($conn);
            }
            $hasFinalDefenseSubmission = false;
            if (progress_tracker_column_exists($conn, 'final_defense_submissions', 'id')) {
                $stmt = $conn->prepare("
                    SELECT 1
                    FROM final_defense_submissions
                    WHERE student_id = ?
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param('i', $studentId);
                    $stmt->execute();
                    $stmt->store_result();
                    $hasFinalDefenseSubmission = $stmt->num_rows > 0;
                    $stmt->close();
                }
            }
            if (progress_tracker_column_exists($conn, 'defense_schedules', 'schedule_type')) {
                $stmt = $conn->prepare("
                    SELECT 1
                    FROM defense_schedules
                    WHERE student_id = ? AND schedule_type = 'final'
                    LIMIT 1
                ");
                $hasFinalSchedule = false;
                if ($stmt) {
                    $stmt->bind_param('i', $studentId);
                    $stmt->execute();
                    $stmt->store_result();
                    $hasFinalSchedule = $stmt->num_rows > 0;
                    $stmt->close();
                }
                if (!$hasFinalSchedule && !$hasFinalDefenseSubmission && isset($rows['final_defense_scheduled'])) {
                    $rows['final_defense_scheduled']['status'] = 'pending';
                    $rows['final_defense_scheduled']['completed_at'] = null;
                }
            }
        }

        $latestFinalPaper = null;
        if (progress_tracker_column_exists($conn, 'final_paper_submissions', 'id') && function_exists('fetchLatestFinalPaperSubmission')) {
            $latestFinalPaper = fetchLatestFinalPaperSubmission($conn, $studentId);
        }
        $verdictValue = $latestFinalPaper ? trim((string)($latestFinalPaper['outline_defense_verdict'] ?? '')) : '';
        $verdictValueLower = strtolower($verdictValue);
        $reviewGateStatus = $latestFinalPaper ? strtolower(trim((string)($latestFinalPaper['review_gate_status'] ?? ''))) : '';
        $statusValue = $latestFinalPaper ? strtolower(trim((string)($latestFinalPaper['status'] ?? ''))) : '';
        $verdictStepStatus = $rows['outline_verdict_released']['status'] ?? 'pending';
        if ($verdictValue !== '' && $verdictStepStatus !== 'complete') {
            $completedAt = $latestFinalPaper['outline_defense_verdict_at'] ?? null;
            progress_tracker_mark_step_complete(
                $conn,
                $studentId,
                'outline_verdict_released',
                'final_paper_submissions',
                (int)($latestFinalPaper['id'] ?? 0),
                $completedAt
            );
            $rows = progress_tracker_fetch_rows($conn, $studentId);
        }
        $revisionStepStatus = $rows['revision_completed']['status'] ?? 'pending';
        if ($latestFinalPaper && $revisionStepStatus !== 'complete') {
            $passedVerdicts = ['passed', 'passed with revision'];
            $passedGateStatuses = ['passed', 'passed with minor revision', 'passed with major revision'];
            $hasFailVerdict = $verdictValueLower !== ''
                && (in_array($verdictValueLower, ['failed'], true) || stripos($verdictValueLower, 'redefense') !== false);
            $hasFailGate = $reviewGateStatus !== ''
                && (in_array($reviewGateStatus, ['failed', 'redefense required'], true) || stripos($reviewGateStatus, 'redefense') !== false);
            $shouldCompleteRevision = false;
            if (!$hasFailVerdict && !$hasFailGate) {
                if (in_array($verdictValueLower, $passedVerdicts, true)
                    || in_array($reviewGateStatus, $passedGateStatuses, true)
                    || in_array($statusValue, ['approved', 'minor revision', 'major revision'], true)) {
                    $shouldCompleteRevision = true;
                }
            }
            if ($shouldCompleteRevision) {
                $completedAt = $latestFinalPaper['final_decision_at'] ?? $latestFinalPaper['outline_defense_verdict_at'] ?? null;
                progress_tracker_mark_step_complete(
                    $conn,
                    $studentId,
                    'revision_completed',
                    'final_paper_submissions',
                    (int)($latestFinalPaper['id'] ?? 0),
                    $completedAt
                );
                $rows = progress_tracker_fetch_rows($conn, $studentId);
            }
        }
        if (function_exists('progress_tracker_student_has_final_endorsement_approved')
            && progress_tracker_student_has_final_endorsement_approved($conn, $studentId)
        ) {
            $paymentStatus = null;
            $paymentTimestamp = null;
            if (progress_tracker_column_exists($conn, 'payment_proofs', 'id')) {
                $stmt = $conn->prepare("
                    SELECT status, created_at, updated_at
                    FROM payment_proofs
                    WHERE user_id = ?
                    ORDER BY COALESCE(updated_at, created_at) DESC, id DESC
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param('i', $studentId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    if ($row) {
                        $paymentStatus = $row['status'] ?? null;
                        $paymentTimestamp = $row['updated_at'] ?? $row['created_at'] ?? null;
                    }
                    $stmt->close();
                }
            }
            if ($paymentTimestamp) {
                $finalPaymentSubmittedStatus = $rows['final_payment_submitted']['status'] ?? 'pending';
                if ($finalPaymentSubmittedStatus !== 'complete') {
                    progress_tracker_mark_step_complete(
                        $conn,
                        $studentId,
                        'final_payment_submitted',
                        'payment_proofs',
                        null,
                        $paymentTimestamp
                    );
                    $rows = progress_tracker_fetch_rows($conn, $studentId);
                }
                if ($paymentStatus === 'payment_accepted') {
                    $finalPaymentVerifiedStatus = $rows['final_payment_verified']['status'] ?? 'pending';
                    if ($finalPaymentVerifiedStatus !== 'complete') {
                        progress_tracker_mark_step_complete(
                            $conn,
                            $studentId,
                            'final_payment_verified',
                            'payment_proofs',
                            null,
                            $paymentTimestamp
                        );
                        $rows = progress_tracker_fetch_rows($conn, $studentId);
                    }
                }
            }
        }
        if (progress_tracker_column_exists($conn, 'final_defense_submissions', 'id')) {
            $stmt = $conn->prepare("
                SELECT id, submitted_at
                FROM final_defense_submissions
                WHERE student_id = ?
                ORDER BY submitted_at DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $finalDefenseStepStatus = $rows['final_defense_scheduled']['status'] ?? 'pending';
                    if ($finalDefenseStepStatus !== 'complete') {
                        progress_tracker_mark_step_complete(
                            $conn,
                            $studentId,
                            'final_defense_scheduled',
                            'final_defense_submissions',
                            (int)($row['id'] ?? 0),
                            $row['submitted_at'] ?? null
                        );
                        $rows = progress_tracker_fetch_rows($conn, $studentId);
                    }
                }
            }
        }
        if (progress_tracker_column_exists($conn, 'final_defense_submissions', 'id')) {
            $stmt = $conn->prepare("
                SELECT id, status, reviewed_at, submitted_at
                FROM final_defense_submissions
                WHERE student_id = ?
                ORDER BY COALESCE(reviewed_at, submitted_at) DESC, id DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $studentId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $status = trim((string)($row['status'] ?? ''));
                    $isVerdictReady = $status !== '' && $status !== 'Submitted';
                    $finalDefenseOutcomeStatus = $rows['final_defense_outcome']['status'] ?? 'pending';
                    if ($isVerdictReady && $finalDefenseOutcomeStatus !== 'complete') {
                        progress_tracker_mark_step_complete(
                            $conn,
                            $studentId,
                            'final_defense_outcome',
                            'final_defense_submissions',
                            (int)($row['id'] ?? 0),
                            $row['reviewed_at'] ?? $row['submitted_at'] ?? null
                        );
                        $rows = progress_tracker_fetch_rows($conn, $studentId);
                    }
                }
            }
        }
        if (!$rows && function_exists('progress_tracker_compute_legacy_data')) {
            $cache[$studentId] = progress_tracker_compute_legacy_data($conn, $studentId);
            return $cache[$studentId];
        }

        $cache[$studentId] = progress_tracker_build_data_from_rows($rows);
        return $cache[$studentId];
    }
}
