<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications_helper.php';

if (!isset($_SESSION)) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

if ($userId === null && ($role === null || $role === '')) {
    header('Location: login.php');
    exit;
}

$allNotifications = fetch_user_notifications($conn, $userId, $role, 100);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications - DNSC IAdS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f5f7fb; }
        .content { margin-left: 220px; padding: 30px; transition: margin-left 0.3s ease; }
        .sidebar.collapsed ~ .content { margin-left: 60px; }
        .notification-item {
            border-left: 4px solid transparent;
        }
        .notification-item.unread {
            background-color: #eef7f0;
            border-left-color: #198754;
        }
        .notification-item .timestamp {
            font-size: 0.8rem;
        }
        .notification-actions .btn {
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>
<div class="content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-success mb-1"><i class="bi bi-bell-fill me-2"></i>Notifications</h3>
                <p class="text-muted mb-0">Stay updated with the latest actions related to your role.</p>
            </div>
            <div class="notification-actions">
                <button class="btn btn-outline-success btn-sm" id="pageMarkAll">Mark all as read</button>
                <button class="btn btn-outline-secondary btn-sm" id="pageRefresh">Refresh</button>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($allNotifications)): ?>
                    <div class="p-4 text-center text-muted">No notifications yet.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush" id="pageNotificationList">
                        <?php foreach ($allNotifications as $note): ?>
                            <div class="list-group-item notification-item d-flex justify-content-between align-items-start<?php echo (int)$note['is_read'] === 0 ? ' unread' : ''; ?>" data-notification-id="<?php echo (int)$note['id']; ?>">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($note['title']); ?></h6>
                                    <p class="mb-1 text-muted small"><?php echo htmlspecialchars($note['message']); ?></p>
                                    <div class="timestamp text-muted"><?php echo date('M d, Y h:i A', strtotime($note['created_at'])); ?></div>
                                    <?php if (!empty($note['link'])): ?>
                                        <a href="<?php echo htmlspecialchars($note['link']); ?>" class="small">Open related page</a>
                                    <?php endif; ?>
                                </div>
                                <?php if ((int)$note['is_read'] === 0): ?>
                                    <button class="btn btn-link btn-sm p-0 mark-read-btn" type="button">Mark as read</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('pageNotificationList');
    const markAllBtn = document.getElementById('pageMarkAll');
    const refreshBtn = document.getElementById('pageRefresh');

    function refreshPageNotifications() {
        fetch('notifications_api.php?action=list&limit=100')
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (!payload || payload.error) {
                    return;
                }
                renderList(payload.notifications || []);
            });
    }

    function renderList(notes) {
        if (!list) return;
        if (notes.length === 0) {
            list.innerHTML = '<div class="p-4 text-center text-muted">No notifications yet.</div>';
            return;
        }
        list.innerHTML = '';
        notes.forEach(function (note) {
            const wrapper = document.createElement('div');
            wrapper.className = 'list-group-item notification-item d-flex justify-content-between align-items-start' + (parseInt(note.is_read, 10) === 0 ? ' unread' : '');
            wrapper.dataset.notificationId = note.id;
            wrapper.innerHTML = ''
                + '<div>'
                +   '<h6 class="mb-1">' + escapeHtml(note.title || '') + '</h6>'
                +   '<p class="mb-1 text-muted small">' + escapeHtml(note.message || '') + '</p>'
                +   '<div class="timestamp text-muted">' + escapeHtml(formatTimestamp(note.created_at)) + '</div>'
                +   (note.link ? '<a href="' + escapeHtml(note.link) + '" class="small">Open related page</a>' : '')
                + '</div>'
                + (parseInt(note.is_read, 10) === 0 ? '<button class="btn btn-link btn-sm p-0 mark-read-btn" type="button">Mark as read</button>' : '');
            list.appendChild(wrapper);
        });
    }

    function markNotification(id) {
        const fd = new FormData();
        fd.append('action', 'markRead');
        fd.append('id', id);
        fetch('notifications_api.php', { method: 'POST', body: fd })
            .then(function () { refreshPageNotifications(); });
    }

    function markAll() {
        const fd = new FormData();
        fd.append('action', 'markAllRead');
        fetch('notifications_api.php', { method: 'POST', body: fd })
            .then(function () { refreshPageNotifications(); });
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatTimestamp(value) {
        if (!value) return '';
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString();
    }

    if (list) {
        list.addEventListener('click', function (event) {
            if (event.target.classList.contains('mark-read-btn')) {
                const row = event.target.closest('[data-notification-id]');
                if (row) {
                    markNotification(row.dataset.notificationId);
                }
            }
        });
    }

    markAllBtn.addEventListener('click', function () {
        markAll();
    });

    refreshBtn.addEventListener('click', function () {
        refreshPageNotifications();
    });
});
</script>
</body>
</html>
