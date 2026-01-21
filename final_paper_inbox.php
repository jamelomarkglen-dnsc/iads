<?php
session_start();
require_once 'db.php';
require_once 'role_helpers.php';
require_once 'final_paper_helpers.php';

$allowedRoles = ['adviser', 'panel', 'committee_chairperson', 'committee_chair'];
enforce_role_access($allowedRoles);

ensureFinalPaperTables($conn);

$reviewerId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$roleMap = ['committee_chair' => 'committee_chairperson'];
$reviewerRoleFilter = $roleMap[$role] ?? $role;
$promptSubmissionId = (int)($_GET['review_submission_id'] ?? 0);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$conditions = ["r.reviewer_id = ?"];
$types = 'i';
$params = [$reviewerId];

if (in_array($reviewerRoleFilter, ['adviser', 'panel', 'committee_chairperson'], true)) {
    $conditions[] = "r.reviewer_role = ?";
    $types .= 's';
    $params[] = $reviewerRoleFilter;
}

if ($search !== '') {
    $conditions[] = "(CONCAT(stu.firstname, ' ', stu.lastname) LIKE ? OR s.final_title LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
    $conditions[] = "r.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

$sql = "
    SELECT
        s.id,
        s.final_title,
        s.status AS final_status,
        s.submitted_at,
        s.version,
        s.review_gate_status,
        r.status AS review_status,
        r.reviewer_role,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name
    FROM final_paper_reviews r
    JOIN final_paper_submissions s ON s.id = r.submission_id
    JOIN users stu ON stu.id = s.student_id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY s.submitted_at DESC, s.id DESC
";

$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
    $stmt->close();
}

$promptSubmissionAllowed = false;
$promptSubmissionBlocked = false;
if ($promptSubmissionId > 0) {
    foreach ($rows as $row) {
        if ((int)($row['id'] ?? 0) !== $promptSubmissionId) {
            continue;
        }
        $gateStatus = trim((string)($row['review_gate_status'] ?? ''));
        $promptSubmissionAllowed = $gateStatus !== '' || in_array($role, ['committee_chairperson', 'committee_chair'], true);
        $promptSubmissionBlocked = !$promptSubmissionAllowed;
        break;
    }
}

include 'header.php';
include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Outline Defense Manuscript Inbox</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f8f4; }
        .content { margin-left: 220px; padding: 28px 24px; min-height: 100vh; transition: margin-left .3s; }
        #sidebar.collapsed ~ .content { margin-left: 60px; }
        .filter-card { border-radius: 16px; border: none; box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08); }
        .filter-card .form-control, .filter-card .form-select { border-radius: 999px; }
        .inbox-table thead th { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: #556; }
        @media (max-width: 992px) { .content { margin-left: 0; } }
    </style>
</head>
<body>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-inbox me-2"></i>Outline Defense Manuscript Inbox</h3>
                <p class="text-muted mb-0">Review outline defense manuscripts assigned to you.</p>
            </div>
        </div>

        <div class="card filter-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold text-success">Search</label>
                        <input type="search" name="search" value="<?= htmlspecialchars($search); ?>" class="form-control" placeholder="Search student or title">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold text-success">Your Status</label>
                        <select name="status" class="form-select">
                            <option value="">All statuses</option>
                            <?php
                                $statusOptions = [
                                    'Pending' => 'Pending',
                                    'Approved' => 'Approved',
                                    'Minor Revision' => 'Accept with Minor Revision',
                                    'Major Revision' => 'Accept with Major Revision',
                                    'Rejected' => 'Rejected',
                                ];
                            ?>
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= $value; ?>" <?= $statusFilter === $value ? 'selected' : ''; ?>><?= $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button type="submit" class="btn btn-success"><i class="bi bi-search me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <div class="alert alert-light border text-center">
                <i class="bi bi-inbox fs-3 d-block mb-2 text-success"></i>
                No outline defense manuscripts assigned to you yet.
            </div>
        <?php else: ?>
            <?php if ($promptSubmissionBlocked): ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    The committee chairperson has not confirmed the review status yet.
                </div>
            <?php endif; ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 inbox-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Final Title</th>
                                    <th>Submitted</th>
                                    <th>Your Status</th>
                                    <th>Final Decision</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                        $reviewStatus = $row['review_status'] ?? 'Pending';
                                        $finalStatus = $row['final_status'] ?? 'Submitted';
                                        $gateStatus = trim((string)($row['review_gate_status'] ?? ''));
                                        $canReview = $gateStatus !== '' || in_array($role, ['committee_chairperson', 'committee_chair'], true);
                                    ?>
                                    <tr>
                                        <td class="fw-semibold text-success"><?= htmlspecialchars($row['student_name'] ?? ''); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($row['final_title'] ?? ''); ?></div>
                                            <div class="text-muted small">Version <?= htmlspecialchars((string)($row['version'] ?? 1)); ?></div>
                                        </td>
                                        <td><?= htmlspecialchars($row['submitted_at'] ?? ''); ?></td>
                                        <td><span class="badge <?= finalPaperReviewStatusClass($reviewStatus); ?>"><?= htmlspecialchars(finalPaperStatusLabel($reviewStatus)); ?></span></td>
                                        <td><span class="badge <?= finalPaperStatusClass($finalStatus); ?>"><?= htmlspecialchars(finalPaperStatusLabel($finalStatus)); ?></span></td>
                                        <td class="text-end">
                                            <?php if ($canReview): ?>
                                                <a href="final_paper_review.php?submission_id=<?= (int)$row['id']; ?>" class="btn btn-sm btn-outline-success review-manuscript-btn" data-submission-id="<?= (int)$row['id']; ?>">
                                                    Review Manuscript
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                    Awaiting chair confirmation
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="modal fade" id="reviewGateModal" tabindex="-1" aria-labelledby="reviewGateLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title" id="reviewGateLabel">Confirm Review Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="reviewGateForm">
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            Select a status before opening the manuscript review. You can update this after reviewing.
                        </p>
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" id="reviewGateStatus" required>
                            <option value="">Select status</option>
                            <option value="Approved">Passed</option>
                            <option value="Minor Revision">Passed with minor revisions</option>
                            <option value="Major Revision">Passed with major revisions</option>
                            <option value="Redefense">Redefense</option>
                            <option value="Rejected">Failed</option>
                        </select>
                        <div class="invalid-feedback">Please choose a status to continue.</div>
                        <input type="hidden" id="reviewGateSubmissionId" value="">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Continue</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        const modalEl = document.getElementById('reviewGateModal');
        const form = document.getElementById('reviewGateForm');
        const statusSelect = document.getElementById('reviewGateStatus');
        const submissionInput = document.getElementById('reviewGateSubmissionId');
        const statusMap = {
            'Approved': 'Approved',
            'Minor Revision': 'Minor Revision',
            'Major Revision': 'Major Revision',
            'Redefense': 'Major Revision',
            'Rejected': 'Rejected'
        };
        if (!modalEl || !form || !statusSelect || !submissionInput) {
            return;
        }

        const modal = new bootstrap.Modal(modalEl);

        const openModal = (submissionId) => {
            submissionInput.value = submissionId || '';
            statusSelect.value = '';
            statusSelect.classList.remove('is-invalid');
            modal.show();
        };

        document.querySelectorAll('.review-manuscript-btn').forEach((btn) => {
            btn.addEventListener('click', (event) => {
                event.preventDefault();
                openModal(btn.dataset.submissionId || '');
            });
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const submissionId = parseInt(submissionInput.value, 10);
            const selected = statusSelect.value;
            if (!submissionId) {
                modal.hide();
                return;
            }
            if (!selected) {
                statusSelect.classList.add('is-invalid');
                return;
            }
            const mappedStatus = statusMap[selected] || selected;
            let target = `final_paper_review.php?submission_id=${encodeURIComponent(submissionId)}`;
            if (mappedStatus) {
                target += `&prefill_status=${encodeURIComponent(mappedStatus)}`;
            }
            window.location.href = target;
        });

        statusSelect.addEventListener('change', () => {
            if (statusSelect.value) {
                statusSelect.classList.remove('is-invalid');
            }
        });

        const promptSubmissionId = <?php echo (int)$promptSubmissionId; ?>;
        const promptSubmissionAllowed = <?php echo $promptSubmissionAllowed ? 'true' : 'false'; ?>;
        if (promptSubmissionId > 0 && promptSubmissionAllowed) {
            openModal(promptSubmissionId);
        }
    })();
</script>
</body>
</html>
