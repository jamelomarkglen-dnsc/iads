<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'];
$userId = $_SESSION['user_id'];

// Calendar is editable for all roles
$can_edit = true;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interactive Calendar</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #e9ecef;
            color: #333;
            margin: 0;
            height: 100vh;
            overflow: hidden;
        }

        #calendar {
            position: absolute;
            top: 70px;
            left: 260px;
            right: 20px;
            bottom: 20px;
            padding: 15px;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            overflow: hidden;
            min-height: calc(100vh - 100px);
            max-height: calc(100vh - 100px);
        }

        /* Calendar Date Color Modifications */
        .fc .fc-daygrid-day-number,
        .fc .fc-col-header-cell-cushion {
            color: #16562c !important;
            font-weight: 600;
        }

        /* Toolbar Styles */
        .fc .fc-toolbar-title { color: #16562c; font-weight: bold; }
        .fc .fc-button-primary {
            background: #16562c;
            border: none;
        }
        .fc .fc-button-primary:hover {
            background: #134e27;
        }

        /* Event Styles */
        .fc-event {
            cursor: pointer;
            border-radius: 6px;
            padding: 2px 6px;
            font-size: 0.85rem;
        }
        .fc-event.Defense { background-color: #80e0a0 !important; color: #000; }
        .fc-event.Meeting { background-color: #4db8ff !important; color: #000; }
        .fc-event.Call { background-color: #444 !important; color: #fff; }
        .fc-event.Other { background-color: #c8c8c8 !important; color: #000; }

        .fc-daygrid-event .fc-event-time {
            display: block;
            margin-right: 0;
        }

        .fc-daygrid-event .fc-event-title-container,
        .fc-daygrid-event .fc-event-title {
            white-space: normal;
        }

        /* Modal Styles */
        .modal-content {
            background: #303030;
            color: #fff;
            border-radius: 8px;
        }

        .modal-header {
            background-color: #303030;
            color: #fff;
            border-bottom: 1px solid #555;
        }

        .modal-footer {
            background-color: #4b4848ff;
            border-top: none;
        }

        .btn-close { filter: invert(1); }

        .form-group label {
            font-weight: 500;
            margin-bottom: 5px;
            display: block;
            color: #ccc;
        }

        .form-control, .form-select, textarea {
            background-color: #303030 !important;
            color: #fff !important;
            border: 1px solid #f6faf7ff !important;
        }

        .form-control:focus, .form-select:focus, textarea:focus {
            border-color: #fff !important;
            color: #fff !important;
            box-shadow: none !important;
        }

        /* Error Message Styles */
        .error-message {
            color: #ff6b6b;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .calendar-tooltip .tooltip-inner {
            max-width: 360px;
            text-align: left;
            white-space: normal;
            line-height: 1.35;
        }

        .calendar-tooltip .tooltip-inner strong {
            display: block;
            margin-bottom: 2px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <?php include 'sidebar.php'; ?>

    <div id="calendar"></div>

    <!-- Event Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add/Edit Event</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="eventForm" novalidate>
                        <input type="hidden" id="eventId">
                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" id="eventTitle" required>
                            <div class="error-message" id="titleError"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start Date/Time</label>
                            <input type="datetime-local" class="form-control" id="eventStart" required>
                            <div class="error-message" id="startError"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End Date/Time (Optional)</label>
                            <input type="datetime-local" class="form-control" id="eventEnd">
                            <div class="error-message" id="endError"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select class="form-select" id="eventCategory">
                                <option value="Defense">Defense</option>
                                <option value="Meeting">Meeting</option>
                                <option value="Call">Call</option>
                                <option value="Academic">Academic</option>
                                <option value="Personal">Personal</option>
                                <option value="Other" selected>Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="eventDescription" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Color</label>
                            <input type="color" class="form-control form-control-color" id="eventColor" value="#16562c">
                        </div>
                    </form>
                    <div id="serverErrorMessage" class="alert alert-danger" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveEvent">Save Event</button>
                    <button type="button" class="btn btn-danger d-none" id="deleteEvent">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- FullCalendar -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

    <script>
    window.APP_USER_ROLE = <?php echo json_encode($role); ?>;
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var eventModal = new bootstrap.Modal(document.getElementById('eventModal'));
        var eventForm = document.getElementById('eventForm');
        var saveEventBtn = document.getElementById('saveEvent');
        var deleteEventBtn = document.getElementById('deleteEvent');
        var serverErrorMessage = document.getElementById('serverErrorMessage');
        var modalTitle = document.querySelector('#eventModal .modal-title');
        var formFields = eventForm ? eventForm.querySelectorAll('input, textarea, select') : [];
        var activeDefenseId = null;
        var activeIsDefense = false;
        var canManageDefense = window.APP_USER_ROLE === 'program_chairperson';

        // Clear previous error messages
        function clearErrors() {
            document.getElementById('titleError').textContent = '';
            document.getElementById('startError').textContent = '';
            document.getElementById('endError').textContent = '';
            serverErrorMessage.style.display = 'none';
            serverErrorMessage.textContent = '';
            serverErrorMessage.classList.remove('alert-info');
            serverErrorMessage.classList.add('alert-danger');
        }

        function setModalMode(isLocked, eventIdValue, allowDefenseDelete) {
            if (modalTitle) {
                modalTitle.textContent = isLocked ? 'Defense Schedule' : 'Add/Edit Event';
            }

            formFields.forEach(function(field) {
                if (field.id === 'eventId') {
                    return;
                }
                field.disabled = isLocked;
            });

            saveEventBtn.classList.toggle('d-none', isLocked);
            if (isLocked) {
                if (allowDefenseDelete) {
                    deleteEventBtn.classList.remove('d-none');
                } else {
                    deleteEventBtn.classList.add('d-none');
                }
                serverErrorMessage.textContent = allowDefenseDelete
                    ? 'This defense event is managed by the Program Chairperson. You may delete the schedule if needed.'
                    : 'This defense event is managed by the Program Chairperson and is view-only.';
                serverErrorMessage.classList.remove('alert-danger');
                serverErrorMessage.classList.add('alert-info');
                serverErrorMessage.style.display = 'block';
            } else {
                if (eventIdValue) {
                    deleteEventBtn.classList.remove('d-none');
                } else {
                    deleteEventBtn.classList.add('d-none');
                }
            }
        }

        function escapeHtml(text) {
            return (text || '').replace(/[&<>"']/g, function (char) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                })[char];
            });
        }

        function formatTooltipTime(dateObj) {
            if (!dateObj) return '';
            return dateObj.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        }

        function buildTooltipContent(event) {
            var parts = [];
            parts.push('<strong>' + escapeHtml(event.title || 'Event') + '</strong>');
            if (event.start) {
                parts.push('<div>' + escapeHtml(formatTooltipTime(event.start)) + '</div>');
            }
            if (event.end) {
                parts.push('<div>' + escapeHtml(formatTooltipTime(event.end)) + '</div>');
            }
            if (event.extendedProps && event.extendedProps.description) {
                var description = escapeHtml(event.extendedProps.description).replace(/\\n/g, '<br>');
                parts.push('<div class="small text-muted mt-1">' + description + '</div>');
            }
            return parts.join('');
        }

        // Validate form inputs
        function validateForm() {
            clearErrors();
            let isValid = true;

            // Title validation
            const title = document.getElementById('eventTitle');
            if (!title.value.trim()) {
                document.getElementById('titleError').textContent = 'Title is required';
                isValid = false;
            }

            // Start date validation
            const start = document.getElementById('eventStart');
            if (!start.value) {
                document.getElementById('startError').textContent = 'Start date is required';
                isValid = false;
            }

            // End date validation (if provided)
            const end = document.getElementById('eventEnd');
            if (end.value) {
                const startDate = new Date(start.value);
                const endDate = new Date(end.value);
                if (endDate < startDate) {
                    document.getElementById('endError').textContent = 'End date must be after start date';
                    isValid = false;
                }
            }

            return isValid;
        }

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            themeSystem: 'bootstrap5',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listMonth'
            },
            editable: true,
            selectable: true,
            events: 'fetch_events.php',
            eventDidMount: function(info) {
                if (!info.el) return;
                info.el.setAttribute('data-bs-toggle', 'tooltip');
                info.el.setAttribute('data-bs-html', 'true');
                info.el.setAttribute('data-bs-title', buildTooltipContent(info.event));
                info.el.setAttribute('data-bs-custom-class', 'calendar-tooltip');
                new bootstrap.Tooltip(info.el, {
                    container: 'body',
                    trigger: 'hover',
                    customClass: 'calendar-tooltip'
                });
            },
            
            // Event Interactions
            dateClick: function(info) {
                // Reset form
                eventForm.reset();
                document.getElementById('eventId').value = '';
                document.getElementById('eventStart').value = info.dateStr + 'T00:00';
                clearErrors();
                activeDefenseId = null;
                activeIsDefense = false;
                setModalMode(false, null, false);
                
                eventModal.show();
            },
            
            eventClick: function(info) {
                var event = info.event;
                clearErrors();
                activeIsDefense = event.extendedProps && event.extendedProps.source === 'defense';
                activeDefenseId = activeIsDefense ? event.extendedProps.source_id : null;
                
                // Populate form with event details
                document.getElementById('eventId').value = event.id;
                document.getElementById('eventTitle').value = event.title;
                document.getElementById('eventStart').value = formatDateTimeLocal(event.start);
                document.getElementById('eventEnd').value = event.end ? formatDateTimeLocal(event.end) : '';
                document.getElementById('eventCategory').value = event.extendedProps.category || 'Other';
                document.getElementById('eventDescription').value = event.extendedProps.description || '';
                document.getElementById('eventColor').value = event.backgroundColor || '#16562c';
                
                var isLocked = event.extendedProps && event.extendedProps.is_locked;
                setModalMode(!!isLocked, event.id, activeIsDefense && canManageDefense && !!activeDefenseId);
                eventModal.show();
            }
        });

        calendar.render();

        // Save Event
        saveEventBtn.addEventListener('click', function() {
            if (!validateForm()) {
                return;
            }

            var eventData = {
                id: document.getElementById('eventId').value,
                title: document.getElementById('eventTitle').value,
                start_datetime: document.getElementById('eventStart').value,
                end_datetime: document.getElementById('eventEnd').value || null,
                category: document.getElementById('eventCategory').value,
                description: document.getElementById('eventDescription').value,
                color: document.getElementById('eventColor').value
            };

            fetch('manage_calendar_events.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(eventData)
            })
            .then(response => {
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Received non-JSON response');
                }
                
                // Parse JSON response
                return response.json().then(data => {
                    if (!response.ok) {
                        throw new Error(data.message || 'Failed to save event');
                    }
                    return data;
                });
            })
            .then(data => {
                if (data.success) {
                    calendar.refetchEvents();
                    eventModal.hide();
                } else {
                    throw new Error(data.message || 'Unknown error occurred');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                serverErrorMessage.textContent = error.message;
                serverErrorMessage.style.display = 'block';
            });
        });

        // Delete Event
        deleteEventBtn.addEventListener('click', function() {
            if (activeIsDefense && canManageDefense && activeDefenseId) {
                if (!confirm('Delete this defense schedule and remove it from all assigned calendars?')) {
                    return;
                }
                const fd = new FormData();
                fd.append('action', 'delete');
                fd.append('id', activeDefenseId);
                fetch('assign_panel.php', { method: 'POST', body: fd })
                    .then(response => response.text())
                    .then(result => {
                        if (result.trim() === 'success') {
                            calendar.refetchEvents();
                            eventModal.hide();
                        } else {
                            throw new Error(result || 'Failed to delete defense schedule');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        serverErrorMessage.textContent = error.message;
                        serverErrorMessage.style.display = 'block';
                    });
                return;
            }

            var eventId = document.getElementById('eventId').value;

            fetch(`manage_calendar_events.php?action=delete&id=${eventId}`, {
                method: 'DELETE'
            })
            .then(response => {
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Received non-JSON response');
                }
                
                // Parse JSON response
                return response.json().then(data => {
                    if (!response.ok) {
                        throw new Error(data.message || 'Failed to delete event');
                    }
                    return data;
                });
            })
            .then(data => {
                if (data.success) {
                    calendar.refetchEvents();
                    eventModal.hide();
                } else {
                    throw new Error(data.message || 'Unknown error occurred');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                serverErrorMessage.textContent = error.message;
                serverErrorMessage.style.display = 'block';
            });
        });

        // Helper function to format datetime for input
        function formatDateTimeLocal(date) {
            if (!date) return '';
            const d = new Date(date);
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const hours = String(d.getHours()).padStart(2, '0');
            const minutes = String(d.getMinutes()).padStart(2, '0');
            return `${year}-${month}-${day}T${hours}:${minutes}`;
        }
    });
    </script>
</body>
</html>
