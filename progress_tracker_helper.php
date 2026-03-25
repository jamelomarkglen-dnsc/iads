<?php

require_once __DIR__ . '/notifications_helper.php';
require_once __DIR__ . '/final_paper_helpers.php';
require_once __DIR__ . '/defense_committee_helpers.php';
require_once __DIR__ . '/pdf_submission_helpers.php';
require_once __DIR__ . '/submission_helpers.php';

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

        if (function_exists('ensureFinalPaperTables')) {
            ensureFinalPaperTables($conn);
        }
        if (function_exists('ensureDefenseCommitteeRequestsTable')) {
            ensureDefenseCommitteeRequestsTable($conn);
        }
        if (function_exists('ensureDefensePanelMemberColumns')) {
            ensureDefensePanelMemberColumns($conn);
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
                ['label' => 'Final Routing Submitted', 'complete' => false],
                ['label' => 'Final Routing Passed', 'complete' => false],
                ['label' => 'Payment Proof Submitted (Final)', 'complete' => false],
                ['label' => 'Payment Verified (Final)', 'complete' => false],
                ['label' => 'Final Defense Scheduled', 'complete' => false],
                ['label' => 'Final Defense Outcome Recorded', 'complete' => false],
                ['label' => 'Final Endorsement Submitted', 'complete' => false],
                ['label' => 'Final Endorsement Approved', 'complete' => false],
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
        if ($finalRoutingPassed && $finalRoutingTimestamp && $paymentTimestamp) {
            $finalPaymentSubmitted = strtotime($paymentTimestamp) >= strtotime($finalRoutingTimestamp);
            $finalPaymentVerified = $finalPaymentSubmitted && $paymentStatus === 'payment_accepted';
        }

        $finalDefenseScheduled = false;
        if (progress_tracker_column_exists($conn, 'defense_schedules', 'id')) {
            $stmt = $conn->prepare("
                SELECT defense_date
                FROM defense_schedules
                WHERE student_id = ?
                ORDER BY defense_date ASC, defense_time ASC
                LIMIT 1
            ");
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
        if (progress_tracker_column_exists($conn, 'defense_outcomes', 'id')) {
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
        $finalEndorsementSubmitted = $finalEndorsementStatus !== null;
        $finalEndorsementApproved = $finalEndorsementStatus === 'Approved';

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
