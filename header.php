<?php
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications_helper.php';

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;

$notifications = fetch_user_notifications($conn, $userId, $userRole, 25);
$unreadCount = count_unread_notifications($conn, $userId, $userRole);
$progressTrackerData = $progressTrackerData ?? null;
if ($userRole === 'student' && !is_array($progressTrackerData)) {
    require_once __DIR__ . '/progress_tracker_helper.php';
    if (function_exists('get_student_progress_tracker_data')) {
        $progressTrackerData = get_student_progress_tracker_data($conn, (int)$userId);
    }
}
$progressTrackerEnabled = ($userRole === 'student' && is_array($progressTrackerData));
$progressSteps = $progressTrackerEnabled ? ($progressTrackerData['steps'] ?? []) : [];
$progressCompleted = $progressTrackerEnabled ? (int)($progressTrackerData['completed'] ?? 0) : 0;
$progressTotal = $progressTrackerEnabled ? (int)($progressTrackerData['total'] ?? 0) : 0;
$progressPercent = $progressTrackerEnabled ? (int)($progressTrackerData['percent'] ?? 0) : 0;
$progressCurrent = $progressTrackerEnabled ? (string)($progressTrackerData['current'] ?? '') : '';

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
        <?php if ($progressTrackerEnabled): ?>
            <div class="dropdown">
                <button type="button" class="btn btn-link text-white position-relative p-0 progress-trigger" id="progressDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-graph-up fs-4"></i>
                    <span class="progress-mini-badge">
                        <?php echo htmlspecialchars((string)$progressPercent); ?>%
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end p-0 progress-menu" aria-labelledby="progressDropdown" id="progressMenu">
                    <li class="dropdown-header d-flex justify-content-between align-items-center px-3 py-2">
                        <span>Progress Tracker</span>
                        <span class="badge bg-success-subtle text-success">
                            <?php echo number_format($progressCompleted); ?> / <?php echo number_format($progressTotal); ?>
                        </span>
                    </li>
                    <li><hr class="dropdown-divider my-0"></li>
                    <li class="px-3 py-3">
                        <div class="text-muted small">Current step</div>
                        <div class="fw-semibold text-success"><?php echo htmlspecialchars($progressCurrent ?: 'In progress'); ?></div>
                        <button class="btn btn-success w-100 mt-3" type="button" id="progressModalTrigger" data-bs-toggle="modal" data-bs-target="#progressTrackerModal">
                            View Full Tracker
                        </button>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
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
                                $titleLower = strtolower(trim((string)($note['title'] ?? '')));
                                if ($userRole === 'student' && $titleLower === 'new advisory message') {
                                    $noteLink = 'student_messages.php';
                                } elseif (!$noteLink) {
                                    if ($titleLower === 'outline defense endorsement') {
                                        $noteLink = 'program_chairperson.php#endorsement-inbox';
                                    }
                                }
                                ?>
                                <a
                                    href="<?php echo $noteLink ? htmlspecialchars($noteLink) : '#'; ?>"
                                    class="list-group-item list-group-item-action d-flex flex-column gap-1 notif-item<?php echo (int)$note['is_read'] === 0 ? ' fw-semibold is-unread' : ' is-read'; ?>"
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

<?php if ($progressTrackerEnabled): ?>
<div class="modal fade" id="progressTrackerModal" tabindex="-1" aria-labelledby="progressTrackerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl progress-modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="progressTrackerModalLabel">Progress Tracker</h5>
                    <div class="text-muted small">Current step: <?php echo htmlspecialchars($progressCurrent ?: 'In progress'); ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="progress-summary mb-3">
                    <div class="text-muted small">Completed</div>
                    <div class="fw-semibold text-success">
                        <?php echo number_format($progressCompleted); ?> / <?php echo number_format($progressTotal); ?> steps
                    </div>
                </div>
                <div class="progress-grid">
                    <?php $progressColumns = array_chunk($progressSteps, 7); ?>
                    <?php foreach ($progressColumns as $columnIndex => $columnSteps): ?>
                        <?php
                            $startStep = ($columnIndex * 7) + 1;
                            $endStep = $startStep + count($columnSteps) - 1;
                        ?>
                        <div class="progress-column">
                            <div class="progress-column-title">
                                Steps <?php echo (int)$startStep; ?>-<?php echo (int)$endStep; ?>
                            </div>
                            <?php foreach ($columnSteps as $stepIndex => $step): ?>
                                <?php
                                    $state = $step['state'] ?? 'pending';
                                    $stepNumber = $startStep + $stepIndex;
                                ?>
                                <div class="progress-item <?php echo htmlspecialchars($state); ?>">
                                    <span class="progress-dot"></span>
                                    <div class="progress-step-index">Step <?php echo (int)$stepNumber; ?></div>
                                    <div class="progress-label"><?php echo htmlspecialchars($step['label']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .notif-item {
        background-color: #fff;
        transition: background-color 0.2s ease;
    }
    .notif-item.is-unread {
        background-color: #f1f3f5;
    }
    .notif-item.is-read {
        background-color: #fff;
    }
    .notif-item:hover,
    .notif-item:focus {
        background-color: #eef2f5;
    }
    .progress-trigger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: none;
        background: transparent;
        padding: 0;
        margin: 0;
        color: #ffd24d;
        box-shadow: none;
        transition: color 0.2s ease;
    }
    .progress-trigger:hover,
    .progress-trigger:focus {
        background: transparent;
        color: #ffe5a7;
        box-shadow: none;
    }
    .progress-mini-badge {
        position: absolute;
        top: -6px;
        right: -10px;
        background: #ffc107;
        color: #1c2b14;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 999px;
        border: none;
    }
    .progress-menu {
        width: 360px;
        min-width: 360px;
        max-width: calc(100vw - 24px);
        border-radius: 14px;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.22);
        border: 1px solid rgba(0, 0, 0, 0.08);
    }
    .progress-menu .dropdown-header {
        font-weight: 600;
        font-size: 0.95rem;
        color: #5f6f66;
    }
    .progress-menu .btn {
        border-radius: 10px;
        padding: 10px 16px;
        font-weight: 600;
        box-shadow: none;
    }
    .progress-modal {
        max-width: 1100px;
        width: 96vw;
    }
    .progress-modal .modal-content {
        border-radius: 16px;
    }
    .progress-modal .modal-body {
        max-height: 75vh;
        overflow: auto;
    }
    .progress-modal .progress-summary {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .progress-modal .progress-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
    }
    .progress-modal .progress-column {
        background: #f8faf8;
        border: 1px solid rgba(22, 86, 44, 0.12);
        border-radius: 1rem;
        padding: 16px;
        min-height: 100%;
    }
    .progress-modal .progress-column-title {
        font-size: 0.75rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #6d7a6f;
        margin-bottom: 12px;
        font-weight: 600;
    }
    .progress-modal .progress-item {
        position: relative;
        padding-left: 26px;
        padding-bottom: 16px;
    }
    .progress-modal .progress-item::before {
        content: '';
        position: absolute;
        left: 7px;
        top: 2px;
        bottom: -14px;
        width: 2px;
        background: #d7ddd6;
    }
    .progress-modal .progress-item:last-child {
        padding-bottom: 0;
    }
    .progress-modal .progress-item:last-child::before {
        bottom: 6px;
    }
    .progress-modal .progress-dot {
        position: absolute;
        left: 0;
        top: 2px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #d7ddd6;
        border: 2px solid #fff;
        box-shadow: 0 0 0 2px rgba(22, 86, 44, 0.08);
    }
    .progress-modal .progress-step-index {
        font-size: 0.75rem;
        font-weight: 700;
        color: #87948a;
        margin-bottom: 2px;
    }
    .progress-modal .progress-label {
        font-size: 0.88rem;
        line-height: 1.35;
        color: #6d7a6f;
    }
    .progress-modal .progress-item.complete .progress-dot {
        background: #0f6b35;
        box-shadow: 0 0 0 4px rgba(15, 107, 53, 0.15);
    }
    .progress-modal .progress-item.complete::before {
        background: #0f6b35;
    }
    .progress-modal .progress-item.complete .progress-label {
        color: #16562c;
        font-weight: 600;
    }
    .progress-modal .progress-item.current .progress-dot {
        background: #1f8b4c;
        box-shadow: 0 0 0 4px rgba(31, 139, 76, 0.18);
    }
    .progress-modal .progress-item.current::before {
        background: linear-gradient(180deg, rgba(31, 139, 76, 0.9), rgba(215, 221, 214, 0.9));
    }
    .progress-modal .progress-item.current .progress-label {
        color: #1d3522;
        font-weight: 600;
    }
    @media (max-width: 1200px) {
        .progress-modal .progress-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 768px) {
        .progress-modal .progress-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
    window.APP_NOTIFICATIONS = {
        unread: <?php echo (int)$unreadCount; ?>,
        list: <?php echo json_encode($notifications, JSON_UNESCAPED_UNICODE); ?>,
        role: <?php echo json_encode((string)$userRole); ?>
    };
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const progressDropdown = document.getElementById('progressDropdown');
    const progressMenu = document.getElementById('progressMenu');
    const progressModal = document.getElementById('progressTrackerModal');
    const progressModalTrigger = document.getElementById('progressModalTrigger');
    let progressDropdownOpen = false;
    let progressCleanupFn = null;
    let progressBackdrop = null;

    function closeProgressDropdown() {
        progressDropdownOpen = false;
        if (progressCleanupFn) {
            document.removeEventListener('click', progressCleanupFn);
            progressCleanupFn = null;
        }
        if (progressMenu) {
            progressMenu.classList.remove('show');
        }
        if (progressDropdown) {
            progressDropdown.classList.remove('show');
            progressDropdown.setAttribute('aria-expanded', 'false');
        }
    }

    function openProgressDropdown() {
        if (!progressDropdown || !progressMenu) {
            return;
        }
        progressDropdownOpen = true;
        progressMenu.classList.add('show');
        progressDropdown.classList.add('show');
        progressDropdown.setAttribute('aria-expanded', 'true');
        progressCleanupFn = function (event) {
            if (progressMenu.contains(event.target) || progressDropdown.contains(event.target)) {
                return;
            }
            closeProgressDropdown();
        };
        document.addEventListener('click', progressCleanupFn);
    }

    function toggleProgressDropdown() {
        if (progressDropdownOpen) {
            closeProgressDropdown();
        } else {
            openProgressDropdown();
        }
    }

    function showProgressModalFallback() {
        if (!progressModal) {
            return;
        }
        progressModal.classList.add('show');
        progressModal.style.display = 'block';
        progressModal.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
        progressBackdrop = document.createElement('div');
        progressBackdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(progressBackdrop);
    }

    function hideProgressModalFallback() {
        if (!progressModal) {
            return;
        }
        progressModal.classList.remove('show');
        progressModal.style.display = 'none';
        progressModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        if (progressBackdrop) {
            progressBackdrop.remove();
            progressBackdrop = null;
        }
    }

    if (progressDropdown && progressMenu) {
        progressDropdown.addEventListener('click', function (event) {
            event.preventDefault();
            const DropdownConstructor = window.bootstrap && window.bootstrap.Dropdown;
            if (DropdownConstructor) {
                event.stopPropagation();
                const instance = DropdownConstructor.getOrCreateInstance(progressDropdown);
                instance.toggle();
            } else {
                toggleProgressDropdown();
            }
        });
    }

    if (progressModalTrigger && progressModal) {
        progressModalTrigger.addEventListener('click', function (event) {
            const ModalConstructor = window.bootstrap && window.bootstrap.Modal;
            if (ModalConstructor) {
                event.preventDefault();
                ModalConstructor.getOrCreateInstance(progressModal).show();
            } else {
                showProgressModalFallback();
            }
            closeProgressDropdown();
        });
    }

    if (progressModal) {
        progressModal.addEventListener('click', function (event) {
            const ModalConstructor = window.bootstrap && window.bootstrap.Modal;
            if (ModalConstructor) {
                return;
            }
            if (event.target === progressModal || event.target.matches('[data-bs-dismiss="modal"]') || event.target.closest('.btn-close')) {
                hideProgressModalFallback();
            }
        });
    }

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
        const title = note && note.title ? String(note.title).toLowerCase().trim() : '';
        if (title === 'new advisory message' && window.APP_NOTIFICATIONS && window.APP_NOTIFICATIONS.role === 'student') {
            return 'student_messages.php';
        }
        if (note && note.link) {
            return note.link;
        }
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
                link.className = 'list-group-item list-group-item-action d-flex flex-column gap-1 notif-item'
                    + (parseInt(note.is_read, 10) === 0 ? ' fw-semibold is-unread' : ' is-read');
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
        fetch('notifications_api.php?action=list&limit=25')
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
