<?php
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications_helper.php';

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;

$notifications = fetch_user_notifications($conn, $userId, $userRole, 8);
$unreadCount = count_unread_notifications($conn, $userId, $userRole);

// Determine dashboard link based on role.
$dashboardLink = 'login.php';
if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'dean':
            $dashboardLink = 'dean.php';
            break;
        case 'program_chairperson':
            $dashboardLink = 'program_chairperson.php';
            break;
        case 'faculty':
            $dashboardLink = 'faculty.php';
            break;
        case 'adviser':
            $dashboardLink = 'adviser.php';
            break;
        case 'panel':
            $dashboardLink = 'panel.php';
            break;
        case 'committee_chair':
            $dashboardLink = 'my_committee_defense.php';
            break;
        case 'committee_chairperson':
            $dashboardLink = 'my_committee_defense.php';
            break;
        case 'student':
            $dashboardLink = 'student_dashboard.php';
            break;
    }
}
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<nav class="navbar navbar-expand-lg navbar-dark shadow-sm px-4 sticky-top" style="background-color: #16562cff; padding-top: 10px; padding-bottom: 10px;">
    <div class="d-flex align-items-center position-relative">
        <a href="<?php echo htmlspecialchars($dashboardLink); ?>" class="text-decoration-none">
            <div class="d-flex align-items-center">
                <img src="IAdS.png" alt="DNSC IAdS Logo" style="max-height: 50px; background: white; padding: 5px; border-radius: 5px; margin-right: 15px;">
                <div>
                    <h4 class="fw-bold m-0" style="color: #ffc107;">DNSC</h4>
                    <small class="text-white">Institute of Advanced Studies</small>
                </div>
            </div>
        </a>
    </div>

    <div class="ms-auto d-flex align-items-center gap-4 pe-3">
        <div class="dropdown me-2">
            <button type="button" class="btn btn-link text-white position-relative p-0" id="notifDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-bell-fill fs-4"></i>
                <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?php echo $unreadCount > 0 ? '' : 'd-none'; ?>">
                    <span id="notifCount"><?php echo htmlspecialchars((string)$unreadCount); ?></span>
                    <span class="visually-hidden">unread notifications</span>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="notifDropdown" style="min-width: 320px;" id="notifMenu">
                <li class="dropdown-header d-flex justify-content-between align-items-center px-3 py-2">
                    <span>Notifications</span>
                    <button class="btn btn-link btn-sm text-decoration-none p-0" id="markAllNotifications" type="button"<?php echo $unreadCount ? '' : ' disabled'; ?>>Mark all as read</button>
                </li>
                <li><hr class="dropdown-divider my-0"></li>
                <li id="notifItemsWrapper">
                    <div id="notifItems" class="list-group list-group-flush" style="max-height: 360px; overflow-y: auto;">
                        <?php if (empty($notifications)): ?>
                            <div class="list-group-item text-center text-muted small py-3">No notifications yet.</div>
                        <?php else: ?>
                            <?php foreach ($notifications as $note): ?>
                                <?php
                                $noteLink = $note['link'] ?? '';
                                if (!$noteLink) {
                                    $titleLower = strtolower(trim((string)($note['title'] ?? '')));
                                    if ($titleLower === 'outline defense endorsement') {
                                        $noteLink = 'program_chairperson.php#endorsement-inbox';
                                    }
                                }
                                ?>
                                <a
                                    href="<?php echo $noteLink ? htmlspecialchars($noteLink) : '#'; ?>"
                                    class="list-group-item list-group-item-action d-flex flex-column gap-1<?php echo (int)$note['is_read'] === 0 ? ' fw-semibold' : ''; ?>"
                                    data-notification-id="<?php echo (int)$note['id']; ?>"
                                    data-is-read="<?php echo (int)$note['is_read']; ?>"
                                >
                                    <span><?php echo htmlspecialchars($note['title']); ?></span>
                                    <small class="text-muted"><?php echo htmlspecialchars($note['message']); ?></small>
                                    <small class="text-muted fst-italic"><?php echo date('M d, Y h:i A', strtotime($note['created_at'])); ?></small>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </li>
                <li><hr class="dropdown-divider my-0"></li>
                <li><a class="dropdown-item text-center text-primary py-2" href="notifications.php">View all notifications</a></li>
            </ul>
        </div>
    </div>
</nav>

<script>
    window.APP_NOTIFICATIONS = {
        unread: <?php echo (int)$unreadCount; ?>,
        list: <?php echo json_encode($notifications, JSON_UNESCAPED_UNICODE); ?>
    };
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropdown = document.getElementById('notifDropdown');
    const menu = document.getElementById('notifMenu');
    const itemsContainer = document.getElementById('notifItems');
    const badge = document.getElementById('notifBadge');
    const countLabel = document.getElementById('notifCount');
    const markAllBtn = document.getElementById('markAllNotifications');

    if (!dropdown || !menu || !itemsContainer || !badge || !countLabel || !markAllBtn) {
        return;
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
        if (!value) {
            return '';
        }
        const normalised = value.replace(' ', 'T');
        const date = new Date(normalised);
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString();
    }

    function updateBadge(unread) {
        if (unread && unread > 0) {
            badge.classList.remove('d-none');
            countLabel.textContent = unread;
        } else {
            badge.classList.add('d-none');
            countLabel.textContent = '0';
        }
    }

    function resolveNotificationLink(note) {
        if (note && note.link) {
            return note.link;
        }
        const title = note && note.title ? String(note.title).toLowerCase().trim() : '';
        if (title === 'outline defense endorsement') {
            return 'program_chairperson.php#endorsement-inbox';
        }
        return '#';
    }

    function renderNotifications(data) {
        itemsContainer.innerHTML = '';
        const notes = data.notifications || [];
        if (notes.length === 0) {
            itemsContainer.innerHTML = '<div class="list-group-item text-center text-muted small py-3">No notifications yet.</div>';
        } else {
            notes.forEach(function (note) {
                const link = document.createElement('a');
                link.href = resolveNotificationLink(note);
                link.className = 'list-group-item list-group-item-action d-flex flex-column gap-1' + (parseInt(note.is_read, 10) === 0 ? ' fw-semibold' : '');
                link.dataset.notificationId = note.id;
                link.dataset.isRead = note.is_read;
                link.innerHTML = ''
                    + '<span>' + escapeHtml(note.title || '') + '</span>'
                    + '<small class="text-muted">' + escapeHtml(note.message || '') + '</small>'
                    + '<small class="text-muted fst-italic">' + escapeHtml(formatTimestamp(note.created_at)) + '</small>';
                itemsContainer.appendChild(link);
            });
        }
        updateBadge(data.unread || 0);
        markAllBtn.disabled = !(data.unread > 0);
    }

    function fetchNotifications() {
        fetch('notifications_api.php?action=list')
            .then(function (res) { return res.json(); })
            .then(function (payload) {
                if (payload && !payload.error) {
                    renderNotifications(payload);
                }
            })
            .catch(function (err) {
                console.error('Failed to load notifications', err);
            });
    }

    function markNotificationRead(id) {
        const fd = new FormData();
        fd.append('action', 'markRead');
        fd.append('id', id);
        fetch('notifications_api.php', { method: 'POST', body: fd })
            .then(function () { fetchNotifications(); })
            .catch(function (err) { console.error(err); });
    }

    function markAllNotifications() {
        const fd = new FormData();
        fd.append('action', 'markAllRead');
        fetch('notifications_api.php', { method: 'POST', body: fd })
            .then(function () { fetchNotifications(); })
            .catch(function (err) { console.error(err); });
    }
    let fallbackDropdownOpen = false;
    let fallbackCleanupFn = null;

    function closeFallbackDropdown() {
        fallbackDropdownOpen = false;
        if (fallbackCleanupFn) {
            document.removeEventListener('click', fallbackCleanupFn);
            fallbackCleanupFn = null;
        }
        menu.classList.remove('show');
        dropdown.classList.remove('show');
        dropdown.setAttribute('aria-expanded', 'false');
    }

    function openFallbackDropdown() {
        fallbackDropdownOpen = true;
        menu.classList.add('show');
        dropdown.classList.add('show');
        dropdown.setAttribute('aria-expanded', 'true');
        fetchNotifications();
        fallbackCleanupFn = function (event) {
            if (menu.contains(event.target) || dropdown.contains(event.target)) {
                return;
            }
            closeFallbackDropdown();
        };
        document.addEventListener('click', fallbackCleanupFn);
    }

    function toggleFallbackDropdown() {
        if (fallbackDropdownOpen) {
            closeFallbackDropdown();
        } else {
            openFallbackDropdown();
        }
    }

    menu.addEventListener('click', function (event) {
        const link = event.target.closest('a[data-notification-id]');
        if (link) {
            const noteId = link.dataset.notificationId;
            if (noteId) {
                markNotificationRead(noteId);
            }
            const href = link.getAttribute('href');
            const isModifiedClick = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
            if (href && href !== '#' && !isModifiedClick) {
                event.preventDefault();
                window.location.href = href;
            }
        }
    });

    dropdown.addEventListener('click', function (event) {
        event.preventDefault();
        const DropdownConstructor = window.bootstrap && window.bootstrap.Dropdown;
        if (DropdownConstructor) {
            event.stopPropagation();
            const instance = DropdownConstructor.getOrCreateInstance(dropdown);
            instance.toggle();
        } else {
            toggleFallbackDropdown();
        }
    });

    dropdown.addEventListener('show.bs.dropdown', fetchNotifications);
    markAllBtn.addEventListener('click', function (event) {
        event.preventDefault();
        markAllNotifications();
    });

    renderNotifications({
        notifications: window.APP_NOTIFICATIONS.list || [],
        unread: window.APP_NOTIFICATIONS.unread || 0
    });

    setInterval(fetchNotifications, 60000);
});
</script>
