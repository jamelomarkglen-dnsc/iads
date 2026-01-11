<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'adviser') {
    header("Location: login.php");
    exit;
}

if (!function_exists('advisorColumnExists')) {
    function advisorColumnExists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $sql = "
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
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
}

if (!function_exists('advisorWhereClause')) {
    function advisorWhereClause(string $alias, array $columns): string
    {
        $parts = array_map(fn($column) => "{$alias}.{$column} = ?", $columns);
        return '(' . implode(' OR ', $parts) . ')';
    }
}

if (!function_exists('deriveAdviseeStage')) {
    function deriveAdviseeStage(array $advisee): string
    {
        $submission = strtolower($advisee['submission_status'] ?? '');
        if ($submission === 'submitted') {
            return 'active';
        }
        if ($submission === 'no submission') {
            return 'needs-draft';
        }
        return 'general';
    }
}

$adviserId = (int)$_SESSION['user_id'];
$advisorColumns = [];
if (advisorColumnExists($conn, 'adviser_id')) {
    $advisorColumns[] = 'adviser_id';
}
if (advisorColumnExists($conn, 'advisor_id')) {
    $advisorColumns[] = 'advisor_id';
}
if (empty($advisorColumns)) {
    die('Advisor tracking columns missing.');
}

$advisorWhere = advisorWhereClause('u', $advisorColumns);
$advisorTypes = str_repeat('i', count($advisorColumns));
$advisorParams = array_fill(0, count($advisorColumns), $adviserId);
$adviseeAlert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['remove_advisee'] ?? '') === '1') {
    $targetStudentId = (int)($_POST['student_id'] ?? 0);
    if ($targetStudentId <= 0) {
        $adviseeAlert = ['type' => 'danger', 'message' => 'Invalid student selection.'];
    } else {
        $checkSql = "
            SELECT u.id, CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS full_name
            FROM users u
            WHERE u.id = ?
              AND u.role = 'student'
              AND {$advisorWhere}
            LIMIT 1
        ";
        $checkStmt = $conn->prepare($checkSql);
        if ($checkStmt) {
            $bindTypes = 'i' . $advisorTypes;
            $bindValues = [$bindTypes, $targetStudentId];
            foreach ($advisorParams as $index => $value) {
                $bindValues[] = &$advisorParams[$index];
            }
            $bindValues[1] = &$targetStudentId;
            call_user_func_array([$checkStmt, 'bind_param'], $bindValues);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $studentRow = $checkResult ? $checkResult->fetch_assoc() : null;
            if ($checkResult) {
                $checkResult->free();
            }
            $checkStmt->close();
            if ($studentRow) {
                $updates = [];
                if (in_array('adviser_id', $advisorColumns, true)) {
                    $updates[] = 'adviser_id = NULL';
                }
                if (in_array('advisor_id', $advisorColumns, true)) {
                    $updates[] = 'advisor_id = NULL';
                }
                $updateSql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                if ($updateStmt) {
                    $updateStmt->bind_param('i', $targetStudentId);
                    if ($updateStmt->execute()) {
                        $removedName = trim((string)($studentRow['full_name'] ?? 'The student'));
                        $adviseeAlert = ['type' => 'success', 'message' => "{$removedName} has been removed from your advisory list."];
                    } else {
                        $adviseeAlert = ['type' => 'danger', 'message' => 'Unable to update the advisory list right now.'];
                    }
                    $updateStmt->close();
                } else {
                    $adviseeAlert = ['type' => 'danger', 'message' => 'Unable to prepare the removal request.'];
                }
            } else {
                $adviseeAlert = ['type' => 'warning', 'message' => 'The selected student is no longer linked to your advisory list.'];
            }
        } else {
            $adviseeAlert = ['type' => 'danger', 'message' => 'Failed to verify the selected student.'];
        }
    }
}

$adviseesSql = "
    SELECT u.id, u.firstname, u.lastname, u.email,
           COALESCE(cp.title, '') AS paper_title,
           CASE WHEN cp.id IS NOT NULL THEN 'Submitted' ELSE 'No Submission' END AS submission_status
    FROM users u
    LEFT JOIN concept_papers cp ON cp.student_id = u.id
    WHERE u.role = 'student' AND {$advisorWhere}
    ORDER BY u.firstname, u.lastname
";
$advisees = [];
$adviseeMap = [];
$adviseesStmt = $conn->prepare($adviseesSql);
if ($adviseesStmt) {
    if ($advisorTypes !== '' && !empty($advisorParams)) {
        $bindParams = [$advisorTypes];
        foreach ($advisorParams as $key => $value) {
            $bindParams[] = &$advisorParams[$key];
        }
        call_user_func_array([$adviseesStmt, 'bind_param'], $bindParams);
    }
    $adviseesStmt->execute();
    $result = $adviseesStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $studentId = (int)($row['id'] ?? 0);
        if ($studentId <= 0 || isset($adviseeMap[$studentId])) {
            continue;
        }
        $row['stage'] = deriveAdviseeStage($row);
        $adviseeMap[$studentId] = $row;
    }
    $advisees = array_values($adviseeMap);
    $adviseesStmt->close();
}

$adviserHasAdvisees = !empty($advisees);
$defaultAdvisee = $adviserHasAdvisees ? $advisees[0] : null;
$defaultStudentId = $defaultAdvisee['id'] ?? null;
$defaultStudentName = $defaultAdvisee ? trim($defaultAdvisee['firstname'] . ' ' . $defaultAdvisee['lastname']) : '';
$defaultStudentEmail = $defaultAdvisee['email'] ?? '';

$stats = [
    'total' => count($advisees),
    'active' => count(array_filter($advisees, fn($a) => $a['stage'] === 'active')),
    'needs_draft' => count(array_filter($advisees, fn($a) => $a['stage'] === 'needs-draft')),
];
$filters = [
    'all' => 'All Advisees',
    'active' => 'With Submission',
    'needs-draft' => 'Needs Draft',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Adviser Chat - DNSC IAdS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background: #f3f9f3;
            color: #1a2d1f;
        }
        .content {
            margin-left: var(--sidebar-width-expanded, 240px);
            padding: 28px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        #sidebar.collapsed ~ .content {
            margin-left: var(--sidebar-width-collapsed, 70px);
        }
        @media (max-width: 992px) {
            .content {
                margin-left: 0;
            }
            #sidebar.collapsed ~ .content {
                margin-left: 0;
            }
        }
        .card {
            border: 1px solid rgba(22, 86, 44, 0.12);
            border-radius: 16px;
            box-shadow: 0 12px 24px rgba(22, 86, 44, 0.08);
        }
        .card-header {
            background: #16562c;
            color: #f3f9f3;
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
        }
        .advisee-list {
            max-height: 70vh;
            overflow-y: auto;
        }
        .advisee-item {
            border: none;
            border-bottom: 1px solid rgba(22, 86, 44, 0.08);
            background: #ffffff;
            color: inherit;
            text-align: left;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .advisee-item:hover {
            background: #f0f8f1;
            transform: translateX(4px);
        }
        .advisee-item.active-student {
            background: #e2f3e7;
            border-left: 4px solid #16562c;
        }
        .chat-card {
            display: flex;
            flex-direction: column;
            height: 68vh;
            max-height: 68vh;
            background: #ffffff;
        }
        .chat-card .card-header {
            background: #16562c;
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }
        .chat-messages {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 24px;
            background: linear-gradient(180deg, #ecf6ef 0%, #f8fcf9 100%);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .chat-bubble {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 18px;
            display: inline-flex;
            flex-direction: column;
            gap: 6px;
            box-shadow: 0 8px 18px rgba(22, 86, 44, 0.12);
        }
        .chat-bubble.sent {
            margin-left: auto;
            background: linear-gradient(135deg, #1a7431, #16562c);
            color: #f3f9f3;
            border-bottom-right-radius: 4px;
        }
        .chat-bubble.received {
            margin-right: auto;
            background: #ffffff;
            border-bottom-left-radius: 4px;
            border: 1px solid rgba(22, 86, 44, 0.18);
            color: #1c3321;
        }
        .chat-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            opacity: 0.8;
        }
        .chat-divider {
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            color: rgba(22, 86, 44, 0.6);
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 8px 0;
        }
        .chat-divider::before,
        .chat-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(22, 86, 44, 0.15);
        }
        .chat-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(22, 86, 44, 0.1);
            color: #16562c;
            font-weight: 600;
        }
        .chat-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            height: 100%;
            color: rgba(22, 86, 44, 0.65);
        }
        .chat-form textarea {
            resize: none;
            background: #f6fbf7;
            border: 1px solid rgba(22, 86, 44, 0.18);
            color: inherit;
        }
        .chat-form textarea:focus {
            box-shadow: none;
            border-color: #16562c;
        }
        .stat-chip {
            border-radius: 18px;
            background: #fff;
            padding: 16px;
            box-shadow: 0 12px 24px rgba(22,86,44,0.1);
            border: 1px solid rgba(22,86,44,0.08);
        }
        .filter-toggle .btn {
            border-radius: 999px;
        }
        .filter-toggle .btn.active {
            background: #16562c;
            color: #fff;
        }
        .search-result-item {
            border-bottom: 1px solid rgba(22, 86, 44, 0.08);
            padding: 12px 0;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h2 class="fw-bold text-success mb-1">Advisory Chat</h2>
                <p class="text-muted mb-0">Collaborate with your advisees in real-time using the DNSC IAdS chat.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <div class="btn-group filter-toggle" role="group" aria-label="Advisee filters">
                    <?php foreach ($filters as $key => $label): ?>
                        <button type="button" class="btn btn-outline-success status-filter<?php echo $key === 'all' ? ' active' : ''; ?>" data-stage="<?php echo htmlspecialchars($key); ?>">
                            <?php echo htmlspecialchars($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-success px-4" data-bs-toggle="modal" data-bs-target="#addAdviseeModal">
                    <i class="bi bi-person-plus-fill me-2"></i>Add Advisee
                </button>
            </div>
        </div>
        <?php if ($adviseeAlert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($adviseeAlert['type']); ?> alert-dismissible fade show shadow-sm" role="alert">
                <?php echo htmlspecialchars($adviseeAlert['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-sm-4 col-lg-3">
                <div class="stat-chip text-center">
                    <small>Total Advisees</small>
                    <h3 class="mb-0 text-success"><?php echo number_format($stats['total']); ?></h3>
                </div>
            </div>
            <div class="col-sm-4 col-lg-3">
                <div class="stat-chip text-center">
                    <small>With Submission</small>
                    <h3 class="mb-0 text-primary"><?php echo number_format($stats['active']); ?></h3>
                </div>
            </div>
            <div class="col-sm-4 col-lg-3">
                <div class="stat-chip text-center">
                    <small>Need Draft</small>
                    <h3 class="mb-0 text-warning"><?php echo number_format($stats['needs_draft']); ?></h3>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-people-fill me-2"></i>My Advisees</span>
                        <span class="badge bg-light text-success"><?php echo count($advisees); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!$adviserHasAdvisees): ?>
                            <div class="p-4 text-center text-muted">
                                <i class="bi bi-person-exclamation fs-1 d-block mb-2"></i>
                                No advisees yet. Use “Add Advisee” to link students under your advisory.
                            </div>
                        <?php else: ?>
                            <div class="advisee-list list-group list-group-flush" id="adviseeList">
                                <?php foreach ($advisees as $advisee): ?>
                                    <?php
                                        $fullName = trim($advisee['firstname'] . ' ' . $advisee['lastname']);
                                        $isActive = $advisee['id'] === $defaultStudentId;
                                    ?>
                                    <div
                                        class="list-group-item advisee-item <?php echo $isActive ? 'active-student' : ''; ?>"
                                        role="button"
                                        tabindex="0"
                                        data-stage="<?php echo htmlspecialchars($advisee['stage']); ?>"
                                        data-student-id="<?php echo (int)$advisee['id']; ?>"
                                        data-student-name="<?php echo htmlspecialchars($fullName); ?>"
                                        data-student-email="<?php echo htmlspecialchars($advisee['email']); ?>"
                                    >
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold"><?php echo htmlspecialchars($fullName); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($advisee['email']); ?></small>
                                                <?php if (!empty($advisee['paper_title'])): ?>
                                                    <div class="small text-success mt-1">
                                                        <i class="bi bi-journal-text me-1"></i><?php echo htmlspecialchars($advisee['paper_title']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex flex-column align-items-end gap-2">
                                                <span class="badge rounded-pill bg-success-subtle text-success text-capitalize"><?php echo htmlspecialchars($advisee['submission_status']); ?></span>
                                                <form method="POST" class="text-end">
                                                    <input type="hidden" name="remove_advisee" value="1">
                                                    <input type="hidden" name="student_id" value="<?php echo (int)$advisee['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger advisee-remove-btn">
                                                        <i class="bi bi-x-circle me-1"></i>Remove
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card chat-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0" id="chatPartnerName"><?php echo $defaultStudentName ?: 'Select an advisee'; ?></h5>
                            <small class="text-white-50" id="chatHeaderMeta"><?php echo $defaultStudentEmail ? htmlspecialchars($defaultStudentEmail) : 'No advisee selected'; ?></small>
                        </div>
                        <span class="badge bg-light text-success">Adviser</span>
                    </div>
                    <div class="chat-messages" id="chatMessages">
                        <?php if (!$adviserHasAdvisees): ?>
                            <div class="chat-empty-state">
                                <i class="bi bi-people fs-1"></i>
                                <p class="mb-0">Add a student to your advisory to start chatting.</p>
                            </div>
                        <?php else: ?>
                            <div class="chat-empty-state">
                                <i class="bi bi-chat-dots fs-1"></i>
                                <p class="mb-0">Select an advisee from the left to view your conversation history.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-white">
                        <form class="chat-form" id="chatForm">
                            <div class="input-group">
                                <input type="hidden" name="student_id" id="chatStudentId" value="<?php echo $defaultStudentId ? (int)$defaultStudentId : ''; ?>">
                                <textarea class="form-control" name="message" id="chatMessageInput" rows="2" placeholder="<?php echo $adviserHasAdvisees ? 'Type your message...' : 'Add an advisee to start messaging'; ?>" <?php echo $adviserHasAdvisees ? '' : 'disabled'; ?> required></textarea>
                                <button class="btn btn-success" type="submit" id="chatSendBtn" <?php echo $adviserHasAdvisees ? '' : 'disabled'; ?>>
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Advisee Modal -->
<div class="modal fade" id="addAdviseeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Advisee</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="searchAdviseeInput" class="form-label fw-semibold">Search Students</label>
                    <div class="input-group">
                        <span class="input-group-text bg-success text-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchAdviseeInput" class="form-control" placeholder="Enter name or email">
                    </div>
                </div>
                <div id="searchAdviseeResults" class="mt-3">
                    <div class="d-flex justify-content-center py-4 text-muted">
                        <div class="spinner-border text-success" role="status"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const API_URL = 'advisory_chat_api.php';
    const ASSIGN_API_URL = 'advisory_assign_api.php';
    const adviseeButtons = document.querySelectorAll('.advisee-item');
    const removeButtons = document.querySelectorAll('.advisee-remove-btn');
    const filterButtons = document.querySelectorAll('.status-filter');
    const chatMessagesEl = document.getElementById('chatMessages');
    const chatForm = document.getElementById('chatForm');
    const chatStudentIdField = document.getElementById('chatStudentId');
    const chatMessageInput = document.getElementById('chatMessageInput');
    const chatSendBtn = document.getElementById('chatSendBtn');
    const chatPartnerNameEl = document.getElementById('chatPartnerName');
    const chatHeaderMetaEl = document.getElementById('chatHeaderMeta');

    const loadingTemplate = '<div class="chat-empty-state"><div class="spinner-border text-success" role="status"></div><p class="mb-0">Loading conversation...</p></div>';

    let activeStudentId = chatStudentIdField.value ? parseInt(chatStudentIdField.value, 10) : null;
    let activeStudentName = chatPartnerNameEl.textContent.trim();
    let activeStudentEmail = chatHeaderMetaEl.textContent.trim();
    let pollTimer = null;

    function renderMessages(messages) {
        if (!messages || messages.length === 0) {
            chatMessagesEl.innerHTML = '<div class="chat-empty-state"><i class="bi bi-chat-dots fs-1"></i><p class="mb-0">No messages yet. Start the conversation!</p></div>';
            return;
        }
        chatMessagesEl.innerHTML = '';
        let lastDate = '';
        messages.forEach(msg => {
            const dateLabel = formatDateLabel(msg.created_at);
            if (dateLabel !== lastDate) {
                lastDate = dateLabel;
                const divider = document.createElement('div');
                divider.className = 'chat-divider';
                divider.textContent = dateLabel;
                chatMessagesEl.appendChild(divider);
            }
            const isSent = msg.sender_role === 'adviser';
            const wrapper = document.createElement('div');
            wrapper.className = 'd-flex align-items-end gap-2 ' + (isSent ? 'justify-content-end' : '');

            const avatarHtml = createAvatar(isSent ? 'You' : activeStudentName || 'Student');
            const bubble = document.createElement('div');
            bubble.className = 'chat-bubble ' + (isSent ? 'sent' : 'received');
            bubble.innerHTML = `<div>${msg.message}</div><div class="chat-meta ${isSent ? 'justify-content-end' : ''}"><span class="chat-time">${formatTime(msg.created_at)}</span></div>`;

            if (isSent) {
                wrapper.appendChild(bubble);
                wrapper.insertAdjacentHTML('beforeend', avatarHtml);
            } else {
                wrapper.insertAdjacentHTML('beforeend', avatarHtml);
                wrapper.appendChild(bubble);
            }
            chatMessagesEl.appendChild(wrapper);
        });
        chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
    }

    function formatDateLabel(ts) {
        const date = new Date(ts.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return 'Unknown';
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);
        if (date.toDateString() === today.toDateString()) return 'Today';
        if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';
        return date.toLocaleDateString();
    }

    function formatTime(ts) {
        const date = new Date(ts.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return ts;
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function createAvatar(name) {
        const initials = name.trim().split(/\s+/).map(part => part[0]?.toUpperCase()).slice(0, 2).join('') || '?';
        return `<div class="chat-avatar">${initials}</div>`;
    }

    function loadMessages(showLoader = false) {
        if (!activeStudentId) return;
        if (showLoader) {
            chatMessagesEl.innerHTML = loadingTemplate;
        }
        fetch(`${API_URL}?student_id=${activeStudentId}`)
            .then(res => res.json())
            .then(payload => {
                if (payload && payload.success) {
                    renderMessages(payload.messages);
                }
            })
            .catch(err => console.error('Failed to load messages', err));
    }

    function schedulePolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
        }
        pollTimer = setInterval(loadMessages, 5000);
    }

    function activateStudent(button) {
        adviseeButtons.forEach(btn => btn.classList.remove('active-student'));
        button.classList.add('active-student');

        activeStudentId = parseInt(button.dataset.studentId, 10);
        activeStudentName = button.dataset.studentName;
        activeStudentEmail = button.dataset.studentEmail || '';

        chatStudentIdField.value = activeStudentId;
        chatPartnerNameEl.textContent = activeStudentName;
        chatHeaderMetaEl.textContent = activeStudentEmail;
        chatMessageInput.disabled = false;
        chatSendBtn.disabled = false;

        loadMessages(true);
        schedulePolling();
    }

    function applyStageFilter(stage) {
        adviseeButtons.forEach(button => {
            const btnStage = button.dataset.stage || 'general';
            button.style.display = (stage === 'all' || btnStage === stage) ? '' : 'none';
        });
    }

    adviseeButtons.forEach(button => {
        button.addEventListener('click', () => activateStudent(button));
    });
    removeButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const parent = button.closest('.advisee-item');
            const studentName = parent ? parent.dataset.studentName : 'this student';
            if (!confirm(`Remove ${studentName} from your advisory list?`)) {
                event.preventDefault();
            }
        });
    });

    filterButtons.forEach(button => {
        button.addEventListener('click', () => {
            filterButtons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            applyStageFilter(button.dataset.stage || 'all');
        });
    });

    applyStageFilter('all');

    if (activeStudentId) {
        loadMessages(true);
        schedulePolling();
    }

    chatForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!activeStudentId) return;
        const message = chatMessageInput.value.trim();
        if (message === '') return;

        const formData = new FormData();
        formData.append('student_id', activeStudentId);
        formData.append('message', message);

        chatMessageInput.disabled = true;
        chatSendBtn.disabled = true;

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(payload => {
            if (payload && payload.success) {
                chatMessageInput.value = '';
                loadMessages(true);
            }
        })
        .catch(err => console.error('Failed to send message', err))
        .finally(() => {
            chatMessageInput.disabled = false;
            chatSendBtn.disabled = false;
            chatMessageInput.focus();
        });
    });

    // --- Add Advisee Modal Logic ---
    const addModalEl = document.getElementById('addAdviseeModal');
    const addModal = addModalEl ? new bootstrap.Modal(addModalEl) : null;
    const searchInput = document.getElementById('searchAdviseeInput');
    const searchResultsEl = document.getElementById('searchAdviseeResults');
    let searchTimer = null;

    function renderSearchResults(results) {
        if (!results.length) {
            searchResultsEl.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-info-circle fs-1 d-block mb-2"></i>No available students found. All students may already be assigned.</div>';
            return;
        }
        const list = document.createElement('div');
        list.className = 'list-group list-group-flush';
        results.forEach(student => {
            const item = document.createElement('div');
            item.className = 'search-result-item d-flex justify-content-between align-items-center';
            item.innerHTML = `
                <div>
                    <div class="fw-semibold">${student.name}</div>
                    <small class="text-muted">${student.email}</small>
                </div>
                <button class="btn btn-success btn-sm" data-student-id="${student.id}"><i class="bi bi-plus-lg me-1"></i>Add</button>
            `;
            const btn = item.querySelector('button');
            btn.addEventListener('click', () => assignStudent(student.id, btn));
            list.appendChild(item);
        });
        searchResultsEl.innerHTML = '';
        searchResultsEl.appendChild(list);
    }

    function fetchCandidates(query = '') {
        searchResultsEl.innerHTML = '<div class="d-flex justify-content-center py-4 text-muted"><div class="spinner-border text-success" role="status"></div></div>';
        fetch(`${ASSIGN_API_URL}?action=search&query=${encodeURIComponent(query)}`)
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    renderSearchResults(data.results || []);
                } else {
                    searchResultsEl.innerHTML = '<div class="text-center text-danger py-4">Unable to load students. Please try again.</div>';
                }
            })
            .catch(() => searchResultsEl.innerHTML = '<div class="text-center text-danger py-4">Unable to load students. Please try again.</div>');
    }

    function assignStudent(studentId, button) {
        button.disabled = true;
        const formData = new FormData();
        formData.append('student_id', studentId);
        fetch(ASSIGN_API_URL, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                searchResultsEl.innerHTML = '<div class="text-center text-success py-4"><i class="bi bi-check-circle fs-1 d-block mb-2"></i>Student added to your advisory.</div>';
                setTimeout(() => window.location.reload(), 1200);
            } else {
                button.disabled = false;
                alert(data.error || 'Unable to add student. Please try again.');
            }
        })
        .catch(() => {
            button.disabled = false;
            alert('Unable to add student. Please try again.');
        });
    }

    if (addModalEl) {
        addModalEl.addEventListener('shown.bs.modal', () => {
            searchInput.value = '';
            fetchCandidates();
            searchInput.focus();
        });

        searchInput.addEventListener('keyup', function () {
            const query = this.value.trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => fetchCandidates(query), 250);
        });
    }
})();
</script>
</body>
</html>
