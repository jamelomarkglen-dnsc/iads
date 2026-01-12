<?php
session_start();
include 'db.php';
require_once 'notifications_helper.php';
require_once 'chair_scope_helper.php';
require_once 'defense_schedule_helpers.php';
require_once 'defense_committee_helpers.php';
require_once 'role_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'program_chairperson') {
    header("Location: login.php");
    exit;
}

$programChairId = (int)$_SESSION['user_id'];
$chairScope = get_program_chair_scope($conn, $programChairId);

ensureDefenseCommitteeRequestsTable($conn);
ensureDefensePanelMemberColumns($conn);
ensureRoleInfrastructure($conn);

$alert = null;

function grant_switch_roles(mysqli $conn, int $userId, array $roles): void
{
    if ($userId <= 0 || empty($roles)) {
        return;
    }
    foreach ($roles as $roleCode) {
        $roleCode = trim((string)$roleCode);
        if ($roleCode === '') {
            continue;
        }
        ensureUserRoleAssignment($conn, $userId, $roleCode);
    }
}

$adviserOptions = fetch_users_by_roles($conn, ['adviser', 'faculty']);
$chairOptions = fetch_users_by_roles($conn, ['committee_chair', 'committee_chairperson', 'faculty']);
$panelOptions = fetch_users_by_roles($conn, ['panel', 'faculty']);

$studentScopeClause = '';
$studentScopeTypes = '';
$studentScopeParams = [];
[$studentScopeClause, $studentScopeTypes, $studentScopeParams] = build_scope_condition_any($chairScope, 'u');
$studentSql = "
    SELECT u.id, u.firstname, u.lastname, u.email, u.program
    FROM users u
    WHERE u.role = 'student'
";
if ($studentScopeClause !== '') {
    $studentSql .= " AND {$studentScopeClause}";
}
$studentSql .= " ORDER BY u.lastname, u.firstname";
$studentOptions = [];
$studentStmt = $conn->prepare($studentSql);
if ($studentStmt) {
    if ($studentScopeTypes !== '') {
        bind_scope_params($studentStmt, $studentScopeTypes, $studentScopeParams);
    }
    $studentStmt->execute();
    $result = $studentStmt->get_result();
    if ($result) {
        $studentOptions = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $studentStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_committee_request'])) {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $adviserId = (int)($_POST['adviser_id'] ?? 0);
    $chairId = (int)($_POST['chair_id'] ?? 0);
    $panelOneId = (int)($_POST['panel_member_one_id'] ?? 0);
    $panelTwoId = (int)($_POST['panel_member_two_id'] ?? 0);
    $defenseDateInput = trim((string)($_POST['defense_date'] ?? ''));
    $defenseTimeInput = trim((string)($_POST['defense_time'] ?? ''));
    $venueChoice = trim((string)($_POST['venue_choice'] ?? ''));
    $venueCustom = trim((string)($_POST['venue_custom'] ?? ''));
    $venue = $venueChoice === 'Others' ? $venueCustom : $venueChoice;
    $requestNotes = trim((string)($_POST['request_notes'] ?? ''));

    $errors = [];
    if ($studentId <= 0) {
        $errors[] = 'Please select a student.';
    } elseif (!student_matches_scope_any($conn, $studentId, $chairScope)) {
        $errors[] = 'You can only assign committees for students in your scope.';
    }
    if ($adviserId <= 0 || $chairId <= 0 || $panelOneId <= 0 || $panelTwoId <= 0) {
        $errors[] = 'Please select all committee members.';
    }
    $allowedVenues = ['IAdS Conference Room', 'Online Platform (MS Teams)', 'Others'];
    if ($defenseDateInput === '' || $defenseTimeInput === '' || $venueChoice === '') {
        $errors[] = 'Please provide the defense date, time, and venue.';
    } elseif (!in_array($venueChoice, $allowedVenues, true)) {
        $errors[] = 'Please choose a valid venue.';
    } elseif ($venueChoice === 'Others' && $venueCustom === '') {
        $errors[] = 'Please specify the other venue.';
    }
    $uniqueMembers = array_unique([$adviserId, $chairId, $panelOneId, $panelTwoId]);
    if (count($uniqueMembers) < 4) {
        $errors[] = 'Committee members must be unique.';
    }

    $dateValue = $defenseDateInput !== '' ? date('Y-m-d', strtotime($defenseDateInput)) : '';
    $startTime = $defenseTimeInput !== '' ? date('H:i:s', strtotime($defenseTimeInput)) : '';
    $endTime = $startTime !== '' ? date('H:i:s', strtotime($startTime . ' +1 hour')) : '';

    if ($dateValue !== '' && $startTime !== '' && defenseScheduleHasConflict($conn, $dateValue, $startTime, $endTime)) {
        $errors[] = 'Another defense is already scheduled in this time slot.';
    }

    if ($errors) {
        $alert = ['type' => 'danger', 'message' => implode(' ', $errors)];
    } else {
        $conn->begin_transaction();
        try {
            $scheduleColumns = ['student_id', 'defense_date', 'defense_time', 'start_time', 'end_time', 'venue', 'status'];
            $scheduleValues = [$studentId, $dateValue, $startTime, $startTime, $endTime, $venue, 'Pending'];
            $scheduleTypes = 'issssss';

            if (defense_committee_column_exists($conn, 'defense_schedules', 'schedule_date')) {
                $scheduleColumns[] = 'schedule_date';
                $scheduleValues[] = $dateValue;
                $scheduleTypes .= 's';
            }
            if (defense_committee_column_exists($conn, 'defense_schedules', 'schedule_time')) {
                $scheduleColumns[] = 'schedule_time';
                $scheduleValues[] = $startTime;
                $scheduleTypes .= 's';
            }
            if (defense_committee_column_exists($conn, 'defense_schedules', 'remarks')) {
                $scheduleColumns[] = 'remarks';
                $scheduleValues[] = $requestNotes;
                $scheduleTypes .= 's';
            }

            $columnsSql = implode(', ', $scheduleColumns);
            $placeholders = implode(', ', array_fill(0, count($scheduleColumns), '?'));
            $scheduleSql = "INSERT INTO defense_schedules ({$columnsSql}) VALUES ({$placeholders})";
            $scheduleStmt = $conn->prepare($scheduleSql);
            if (!$scheduleStmt) {
                throw new Exception('Unable to create defense schedule.');
            }
            $scheduleStmt->bind_param($scheduleTypes, ...$scheduleValues);
            if (!$scheduleStmt->execute()) {
                $scheduleStmt->close();
                throw new Exception('Unable to save defense schedule.');
            }
            $defenseId = (int)$scheduleStmt->insert_id;
            $scheduleStmt->close();

            $panelStmt = $conn->prepare("
                INSERT INTO defense_panels (defense_id, panel_member, panel_member_id, panel_role)
                VALUES (?, ?, ?, ?)
            ");
            if (!$panelStmt) {
                throw new Exception('Unable to assign defense committee.');
            }

            $memberMap = [
                ['id' => $adviserId, 'role' => 'adviser'],
                ['id' => $chairId, 'role' => 'committee_chair'],
                ['id' => $panelOneId, 'role' => 'panel_member'],
                ['id' => $panelTwoId, 'role' => 'panel_member'],
            ];
            foreach ($memberMap as $member) {
                $memberName = fetch_user_fullname($conn, (int)$member['id']);
                if ($memberName === '') {
                    $panelStmt->close();
                    throw new Exception('Unable to load committee member details.');
                }
                $panelStmt->bind_param('isis', $defenseId, $memberName, $member['id'], $member['role']);
                if (!$panelStmt->execute()) {
                    $panelStmt->close();
                    throw new Exception('Unable to save committee members.');
                }
            }
            $panelStmt->close();

            $requestStmt = $conn->prepare("
                INSERT INTO defense_committee_requests
                    (student_id, defense_id, adviser_id, chair_id, panel_member_one_id, panel_member_two_id, request_notes, requested_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
            ");
            if (!$requestStmt) {
                throw new Exception('Unable to submit committee request.');
            }
            $requestStmt->bind_param(
                'iiiiiisi',
                $studentId,
                $defenseId,
                $adviserId,
                $chairId,
                $panelOneId,
                $panelTwoId,
                $requestNotes,
                $programChairId
            );
            if (!$requestStmt->execute()) {
                $requestStmt->close();
                throw new Exception('Unable to submit committee request.');
            }
            $requestStmt->close();

            $roleGrants = [
                ['id' => $adviserId, 'roles' => ['adviser', 'faculty']],
                ['id' => $panelOneId, 'roles' => ['panel', 'faculty']],
                ['id' => $panelTwoId, 'roles' => ['panel', 'faculty']],
            ];
            foreach ($roleGrants as $grant) {
                grant_switch_roles($conn, (int)$grant['id'], $grant['roles']);
            }

            $studentName = fetch_user_fullname($conn, $studentId);
            $message = "Defense committee selection for {$studentName} scheduled on {$dateValue} at {$startTime} ({$venue}). Please verify.";
            notify_role($conn, 'dean', 'Defense committee verification requested', $message, 'dean_defense_committee.php', false);

            $conn->commit();
            $alert = ['type' => 'success', 'message' => 'Committee request sent to the dean for verification.'];
        } catch (Exception $e) {
            $conn->rollback();
            $alert = ['type' => 'danger', 'message' => $e->getMessage()];
        }
    }
}

$requests = [];
$requestScopeClause = '';
$requestScopeTypes = '';
$requestScopeParams = [];
[$requestScopeClause, $requestScopeTypes, $requestScopeParams] = build_scope_condition_any($chairScope, 'stu');

$requestSql = "
    SELECT
        r.id,
        r.status,
        r.request_notes,
        r.review_notes,
        r.requested_at,
        r.reviewed_at,
        ds.defense_date,
        ds.defense_time,
        ds.venue,
        CONCAT(stu.firstname, ' ', stu.lastname) AS student_name,
        CONCAT(adv.firstname, ' ', adv.lastname) AS adviser_name,
        CONCAT(ch.firstname, ' ', ch.lastname) AS chair_name,
        CONCAT(p1.firstname, ' ', p1.lastname) AS panel_one_name,
        CONCAT(p2.firstname, ' ', p2.lastname) AS panel_two_name,
        CONCAT(rv.firstname, ' ', rv.lastname) AS reviewed_by_name
    FROM defense_committee_requests r
    JOIN users stu ON stu.id = r.student_id
    JOIN defense_schedules ds ON ds.id = r.defense_id
    LEFT JOIN users adv ON adv.id = r.adviser_id
    LEFT JOIN users ch ON ch.id = r.chair_id
    LEFT JOIN users p1 ON p1.id = r.panel_member_one_id
    LEFT JOIN users p2 ON p2.id = r.panel_member_two_id
    LEFT JOIN users rv ON rv.id = r.reviewed_by
";
if ($requestScopeClause !== '') {
    $requestSql .= " WHERE {$requestScopeClause}";
}
$requestSql .= " ORDER BY r.requested_at DESC LIMIT 12";
$requestStmt = $conn->prepare($requestSql);
if ($requestStmt) {
    if ($requestScopeTypes !== '') {
        bind_scope_params($requestStmt, $requestScopeTypes, $requestScopeParams);
    }
    $requestStmt->execute();
    $result = $requestStmt->get_result();
    if ($result) {
        $requests = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
    $requestStmt->close();
}

$summary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($requests as $row) {
    $status = $row['status'] ?? 'Pending';
    if (isset($summary[$status])) {
        $summary[$status]++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Defense Committee Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="progchair.css">
    <style>
        .page-hero {
            background: linear-gradient(120deg, rgba(22, 86, 44, 0.92), rgba(12, 51, 26, 0.95));
            color: #fff;
            border-radius: 18px;
            padding: 24px 28px;
            position: relative;
            overflow: hidden;
        }
        .page-hero::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            top: -80px;
            right: -70px;
        }
        .stat-tile {
            background: #fff;
            border-radius: 16px;
            border: 1px solid rgba(22, 86, 44, 0.1);
            padding: 16px;
            box-shadow: 0 16px 30px rgba(22, 86, 44, 0.08);
        }
        .stat-tile h3 {
            margin: 0;
            font-size: 1.4rem;
            color: #16562c;
        }
        .form-section-title {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #5c6f61;
            margin-bottom: 0.75rem;
        }
        .table thead th {
            white-space: nowrap;
        }
        .committee-list {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
    </style>
</head>
<body class="bg-light program-chair-layout">
<?php include 'header.php'; ?>
<div class="dashboard-shell">
<?php include 'sidebar.php'; ?>

<main class="content dashboard-content" role="main">
    <div class="container-fluid py-4">
        <div class="page-hero mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <div class="badge bg-light text-success mb-2">Program Chairperson</div>
                    <h1 class="h4 fw-semibold mb-1">Defense Committee Dashboard</h1>
                    <p class="mb-0 text-white-50">Schedule defenses, assign committee members, and send requests to the dean for verification.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <div class="stat-tile">
                        <small class="text-muted text-uppercase d-block">Pending</small>
                        <h3><?php echo number_format($summary['Pending']); ?></h3>
                    </div>
                    <div class="stat-tile">
                        <small class="text-muted text-uppercase d-block">Approved</small>
                        <h3><?php echo number_format($summary['Approved']); ?></h3>
                    </div>
                    <div class="stat-tile">
                        <small class="text-muted text-uppercase d-block">Rejected</small>
                        <h3><?php echo number_format($summary['Rejected']); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?> border-0 shadow-sm">
                <?php echo htmlspecialchars($alert['message']); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0">
                        <h2 class="h6 fw-semibold mb-1">Create Committee Request</h2>
                        <p class="text-muted small mb-0">Select a student, schedule the defense, and assign the committee.</p>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="create_committee_request" value="1">
                            <div class="mb-3">
                                <label class="form-label text-muted small">Student</label>
                                <select name="student_id" class="form-select" required>
                                    <option value="">Select student</option>
                                    <?php foreach ($studentOptions as $student): ?>
                                        <?php
                                            $studentName = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
                                            $studentLabel = $studentName;
                                            if (!empty($student['email'])) {
                                                $studentLabel .= ' - ' . $student['email'];
                                            }
                                        ?>
                                        <option value="<?php echo (int)$student['id']; ?>">
                                            <?php echo htmlspecialchars($studentLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-section-title">Defense Committee Review Schedule</div>
                            <div class="text-muted small mb-2">Final defense schedule will be set later by the program chairperson in Panel Assignment.</div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label text-muted small">Date</label>
                                    <input type="date" name="defense_date" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-muted small">Time</label>
                                    <input type="time" name="defense_time" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-muted small">Venue</label>
                                    <select name="venue_choice" class="form-select" required>
                                        <option value="">Select venue</option>
                                        <option value="IAdS Conference Room">IAdS Conference Room</option>
                                        <option value="Online Platform (MS Teams)">Online Platform (MS Teams)</option>
                                        <option value="Others">Others (Specify)</option>
                                    </select>
                                </div>
                                <div class="col-12 d-none" id="venueOtherWrapper">
                                    <label class="form-label text-muted small">Other Venue</label>
                                    <input type="text" name="venue_custom" id="venueOtherInput" class="form-control" placeholder="Specify venue">
                                </div>
                            </div>

                            <div class="form-section-title">Committee Selection</div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Adviser</label>
                                <select name="adviser_id" class="form-select" required>
                                    <option value="">Select adviser</option>
                                    <?php foreach ($adviserOptions as $adviser): ?>
                                        <option value="<?php echo (int)$adviser['id']; ?>">
                                            <?php echo htmlspecialchars(($adviser['firstname'] ?? '') . ' ' . ($adviser['lastname'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Faculty selections become adviser roles after dean approval.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Committee Chair</label>
                                <select name="chair_id" class="form-select" required>
                                    <option value="">Select chair</option>
                                    <?php foreach ($chairOptions as $chair): ?>
                                        <option value="<?php echo (int)$chair['id']; ?>">
                                            <?php echo htmlspecialchars(($chair['firstname'] ?? '') . ' ' . ($chair['lastname'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Faculty selections become committee chair roles after dean approval.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Panel Member 1</label>
                                <select name="panel_member_one_id" class="form-select" required>
                                    <option value="">Select panel member</option>
                                    <?php foreach ($panelOptions as $panel): ?>
                                        <option value="<?php echo (int)$panel['id']; ?>">
                                            <?php echo htmlspecialchars(($panel['firstname'] ?? '') . ' ' . ($panel['lastname'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Panel Member 2</label>
                                <select name="panel_member_two_id" class="form-select" required>
                                    <option value="">Select panel member</option>
                                    <?php foreach ($panelOptions as $panel): ?>
                                        <option value="<?php echo (int)$panel['id']; ?>">
                                            <?php echo htmlspecialchars(($panel['firstname'] ?? '') . ' ' . ($panel['lastname'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Notes for Dean (optional)</label>
                                <textarea name="request_notes" class="form-control" rows="3" placeholder="Share additional context for review."></textarea>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-send me-1"></i> Send to Dean for Verification
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 fw-semibold mb-1">Committee Requests</h2>
                            <p class="text-muted small mb-0">Track verification status and committee details.</p>
                        </div>
                        <a href="assign_panel.php" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-calendar2-check me-1"></i> Panel Schedule
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($requests)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inboxes fs-2 mb-2"></i>
                                <p class="mb-0">No committee requests yet. Use the form to create one.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Schedule</th>
                                            <th>Committee</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($requests as $request): ?>
                                            <?php
                                                $status = $request['status'] ?? 'Pending';
                                                $statusClass = defense_committee_status_class($status);
                                                $scheduleLabel = '';
                                                if (!empty($request['defense_date'])) {
                                                    $scheduleLabel = date('M d, Y', strtotime($request['defense_date']));
                                                }
                                                if (!empty($request['defense_time'])) {
                                                    $scheduleLabel .= $scheduleLabel ? ' • ' . date('g:i A', strtotime($request['defense_time'])) : '';
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold text-success"><?php echo htmlspecialchars($request['student_name'] ?? ''); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($request['venue'] ?? ''); ?></small>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($scheduleLabel ?: 'TBA'); ?></div>
                                                    <small class="text-muted">Requested <?php echo htmlspecialchars(date('M d, Y', strtotime($request['requested_at']))); ?></small>
                                                </td>
                                                <td>
                                                    <div class="committee-list small">
                                                        <span><strong>Adviser:</strong> <?php echo htmlspecialchars($request['adviser_name'] ?? ''); ?></span>
                                                        <span><strong>Chair:</strong> <?php echo htmlspecialchars($request['chair_name'] ?? ''); ?></span>
                                                        <span><strong>Panel:</strong> <?php echo htmlspecialchars($request['panel_one_name'] ?? ''); ?>, <?php echo htmlspecialchars($request['panel_two_name'] ?? ''); ?></span>
                                                    </div>
                                                    <?php if (!empty($request['review_notes'])): ?>
                                                        <div class="text-muted small mt-1">
                                                            <i class="bi bi-chat-left-text me-1"></i><?php echo htmlspecialchars($request['review_notes']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($status); ?></span>
                                                    <?php if (!empty($request['reviewed_by_name'])): ?>
                                                        <div class="text-muted small mt-1">
                                                            Reviewed by <?php echo htmlspecialchars($request['reviewed_by_name']); ?>
                                                        </div>
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
</main>
</div>

<script>
    (() => {
        const selectors = [
            'select[name="adviser_id"]',
            'select[name="chair_id"]',
            'select[name="panel_member_one_id"]',
            'select[name="panel_member_two_id"]'
        ];
        const selects = selectors.map((selector) => document.querySelector(selector)).filter(Boolean);
        if (!selects.length) {
            return;
        }

        const updateOptions = (changedSelect = null) => {
            const counts = {};
            selects.forEach((sel) => {
                const value = sel.value;
                if (value) {
                    counts[value] = (counts[value] || 0) + 1;
                }
            });

            selects.forEach((sel) => {
                const value = sel.value;
                if (!value) {
                    return;
                }
                if ((counts[value] || 0) > 1 && sel !== changedSelect) {
                    sel.value = '';
                }
            });

            const selected = new Set();
            selects.forEach((sel) => {
                if (sel.value) {
                    selected.add(sel.value);
                }
            });

            selects.forEach((sel) => {
                const current = sel.value;
                Array.from(sel.options).forEach((option) => {
                    if (!option.value) {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }
                    const takenElsewhere = selected.has(option.value) && option.value !== current;
                    option.hidden = takenElsewhere;
                    option.disabled = takenElsewhere;
                });
            });
        };

        selects.forEach((sel) => {
            sel.addEventListener('change', () => updateOptions(sel));
        });
        updateOptions();
    })();

    (() => {
        const venueSelect = document.querySelector('select[name="venue_choice"]');
        const otherWrapper = document.getElementById('venueOtherWrapper');
        const otherInput = document.getElementById('venueOtherInput');
        if (!venueSelect || !otherWrapper || !otherInput) {
            return;
        }

        const toggleOther = () => {
            const showOther = venueSelect.value === 'Others';
            otherWrapper.classList.toggle('d-none', !showOther);
            otherInput.required = showOther;
            if (!showOther) {
                otherInput.value = '';
            }
        };

        venueSelect.addEventListener('change', toggleOther);
        toggleOther();
    })();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
