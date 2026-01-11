<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$studentId = (int)$_SESSION['user_id'];

$advisorStmt = $conn->prepare("\n    SELECT adv.id, adv.firstname, adv.lastname, adv.email\n    FROM users stu\n    LEFT JOIN users adv ON adv.id = stu.adviser_id\n    WHERE stu.id = ?\n    LIMIT 1\n");
$advisorStmt->bind_param("i", $studentId);
$advisorStmt->execute();
$advisor = $advisorStmt->get_result()->fetch_assoc();
$advisorStmt->close();

if (!$advisor || empty($advisor['id'])) {
    $fallback = $conn->prepare("\n        SELECT adv.id, adv.firstname, adv.lastname, adv.email\n        FROM users stu\n        LEFT JOIN users adv ON adv.id = stu.advisor_id\n        WHERE stu.id = ?\n        LIMIT 1\n    ");
    $fallback->bind_param("i", $studentId);
    $fallback->execute();
    $advisor = $fallback->get_result()->fetch_assoc();
    $fallback->close();
}

$hasAdvisor = !empty($advisor['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Adviser Messenger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: #f4f8f4;
            color: #1d3522;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        .content {
            margin-left: var(--sidebar-width-expanded, 240px);
            transition: margin-left 0.3s ease;
            padding: 28px 24px;
            min-height: 100vh;
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
        .chat-card {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 120px);
            background: #fff;
            border-radius: 1.25rem;
            border: 1px solid rgba(22, 86, 44, 0.08);
            overflow: hidden;
            box-shadow: 0 14px 28px rgba(22, 86, 44, 0.08);
        }
        .chat-header {
            padding: 0.75rem 1.1rem;
            background: linear-gradient(135deg, #16562c, #0f3b1d);
            color: #fff;
        }
        .chat-header .badge {
            font-size: 0.65rem;
            padding: 0.3rem 0.5rem;
        }
        .chat-messages {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 0.8rem 0.9rem 1rem;
            background: linear-gradient(180deg, #f3faf5 0%, #ffffff 100%);
        }
        .chat-messages .d-flex {
            margin-bottom: 0.35rem;
        }
        .chat-empty-state {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 12px;
            color: rgba(22, 86, 44, 0.65);
            height: 200px;
            text-align: center;
        }
        .chat-bubble {
            max-width: 46%;
            padding: 7px 10px;
            border-radius: 12px;
            display: inline-flex;
            flex-direction: column;
            gap: 6px;
            font-size: 0.84rem;
            line-height: 1.35;
            box-shadow: 0 3px 10px rgba(22, 86, 44, 0.1);
        }
        .chat-bubble.sent {
            margin-left: auto;
            background: linear-gradient(135deg, #1a7431, #16562c);
            color: #f0fff3;
            border-bottom-right-radius: 5px;
        }
        .chat-bubble.received {
            margin-right: auto;
            background: #f9fbf9;
            color: #1d3522;
            border-bottom-left-radius: 5px;
            border: 1px solid rgba(22, 86, 44, 0.1);
        }
        .chat-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.6rem;
            opacity: 0.75;
        }
        .chat-divider {
            text-transform: uppercase;
            font-size: 0.6rem;
            letter-spacing: 0.08em;
            color: rgba(22, 86, 44, 0.6);
            display: flex;
            align-items: center;
            gap: 18px;
            margin: 6px 0;
        }
        .chat-divider::before,
        .chat-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(22, 86, 44, 0.18);
        }
        .chat-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.65rem;
            color: #16562c;
            background: rgba(22, 86, 44, 0.1);
        }
        .chat-form textarea {
            resize: none;
            background: #f6fbf7;
            border: 1px solid rgba(22, 86, 44, 0.18);
            color: inherit;
            padding: 8px 10px;
            min-height: 40px;
            border-radius: 10px;
        }
        .chat-form textarea:focus {
            box-shadow: none;
            border-color: #16562c;
        }
        .chat-form .btn {
            border-radius: 10px;
            padding: 0.45rem 0.75rem;
        }
        .card-footer {
            padding: 0.7rem 0.9rem;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 fw-semibold text-success mb-0">Adviser Messenger</h1>
            <a href="student_dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        </div>

        <div class="chat-card">
            <div class="chat-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="d-block fw-semibold">Conversation</span>
                    <small class="text-white-50" id="advisorMeta">
                        <?php if ($hasAdvisor): ?>
                            <?php echo htmlspecialchars(($advisor['firstname'] ?? '') . ' ' . ($advisor['lastname'] ?? '')); ?>
                            &bull;
                            <?php echo htmlspecialchars($advisor['email'] ?? ''); ?>
                        <?php else: ?>
                            No adviser assigned yet.
                        <?php endif; ?>
                    </small>
                </div>
                <span class="badge bg-light text-success">Student</span>
            </div>
            <div class="chat-messages" id="studentChatMessages">
                <?php if (!$hasAdvisor): ?>
                    <div class="chat-empty-state" id="studentChatEmpty">
                        <div>
                            <i class="bi bi-person-check fs-1 mb-2"></i>
                            <p>No adviser is linked to your account yet. Please contact the program chair.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="chat-empty-state" id="studentChatEmpty">
                        <div>
                            <i class="bi bi-chat-dots fs-1 mb-2"></i>
                            <p>Start a conversation with your adviser to receive updates and feedback.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white">
                <form class="chat-form" id="studentChatForm">
                    <div class="input-group">
                        <textarea class="form-control" id="studentChatMessage" rows="2" placeholder="<?php echo $hasAdvisor ? 'Type a message to your adviser...' : 'No adviser assigned'; ?>" <?php echo $hasAdvisor ? '' : 'disabled'; ?> required></textarea>
                        <button class="btn btn-success" type="submit" id="studentChatSendBtn" <?php echo $hasAdvisor ? '' : 'disabled'; ?>>
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const hasAdvisor = <?php echo $hasAdvisor ? 'true' : 'false'; ?>;
    if (!hasAdvisor) return;

    const API_URL = 'advisory_chat_api.php';
    const chatMessagesEl = document.getElementById('studentChatMessages');
    const chatForm = document.getElementById('studentChatForm');
    const loadingTemplate = '<div class="chat-empty-state"><div class="spinner-border text-success" role="status"></div><p class="mt-2 mb-0">Loading conversation...</p></div>';
    const chatInput = document.getElementById('studentChatMessage');
    const sendBtn = document.getElementById('studentChatSendBtn');

    let pollTimer = null;
    const adviserName = <?php echo json_encode($hasAdvisor ? trim(($advisor['firstname'] ?? '') . ' ' . ($advisor['lastname'] ?? '')) : 'Adviser'); ?>;

    function escapeHtml(str) {
        return str.replace(/[&<>"']/g, function (ch) {
            switch (ch) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                default: return ch;
            }
        });
    }

    function renderMessages(messages) {
        if (!messages || messages.length === 0) {
            chatMessagesEl.innerHTML = '<div class="chat-empty-state"><div><i class="bi bi-chat-dots fs-1 mb-2"></i><p class="mb-0">Start a conversation with your adviser to receive updates and feedback.</p></div></div>';
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
            const isSent = msg.sender_role === 'student';
            const wrapper = document.createElement('div');
            wrapper.className = 'd-flex align-items-end gap-2 ' + (isSent ? 'justify-content-end' : '');
            const avatarHtml = createAvatar(isSent ? 'You' : adviserName);
            const bubble = document.createElement('div');
            bubble.className = 'chat-bubble ' + (isSent ? 'sent' : 'received');
            bubble.innerHTML = `<div>${escapeHtml(msg.message || '')}</div><div class="chat-meta ${isSent ? 'justify-content-end' : ''}"><span class="chat-time">${formatTime(msg.created_at)}</span></div>`;

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

    function loadMessages(showLoader = false) {
        if (showLoader) {
            chatMessagesEl.innerHTML = loadingTemplate;
        }
        fetch(API_URL)
            .then(res => res.json())
            .then(payload => {
                if (payload && payload.success) {
                    renderMessages(payload.messages);
                }
            })
            .catch(err => console.error('Failed to load chat', err));
    }

    function schedulePolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(loadMessages, 5000);
    }

    chatForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const message = chatInput.value.trim();
        if (message === '') return;

        const formData = new FormData();
        formData.append('message', message);

        chatInput.disabled = true;
        sendBtn.disabled = true;

        fetch(API_URL, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(payload => {
            if (payload && payload.success) {
                chatInput.value = '';
                loadMessages();
            }
        })
        .catch(err => console.error('Failed to send chat message', err))
        .finally(() => {
            chatInput.disabled = false;
            sendBtn.disabled = false;
            chatInput.focus();
        });
    });

    function formatDateLabel(ts) {
        const date = new Date(ts.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return 'Unknown date';
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

    loadMessages(true);
    schedulePolling();

    document.addEventListener('visibilitychange', function () {
        if (!pollTimer) return;
        if (document.hidden) {
            clearInterval(pollTimer);
            pollTimer = null;
        } else {
            loadMessages();
            schedulePolling();
        }
    });
})();
</script>
</body>
</html>

