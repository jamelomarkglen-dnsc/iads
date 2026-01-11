<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$name = $_SESSION['name'];

// Fetch assigned defense schedule
$stmt = $conn->prepare("SELECT d.id, d.schedule_date, d.schedule_time, d.venue, d.status,
    CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.topic_title,
    GROUP_CONCAT(CONCAT(u.first_name, ' ', u.last_name, '||', dp.role) SEPARATOR '||SEP||') AS panel_members
FROM defense_schedules d
JOIN users s ON d.student_id = s.id
LEFT JOIN defense_panels dp ON d.id = dp.defense_id
LEFT JOIN users u ON dp.panel_user_id = u.id
WHERE d.student_id = ?
GROUP BY d.id");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$schedule = $result->fetch_assoc();
?>

<?php include 'header.php'; ?>
<?php include 'sidebar.php'; ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
  #main-content {
    margin-left: 260px;
    padding: 30px;
    background-color: #f8f9fa;
  }

  .card {
    border-radius: 12px;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
  }

  .badge-chair {
    background-color: #0d6efd;
    color: white;
  }

  .badge-member {
    background-color: #6c757d;
    color: white;
  }
</style>

<div id="main-content">
  <h3 class="mb-4 fw-bold">📅 My Defense Schedule</h3>

  <?php if ($schedule): ?>
    <div class="card p-4">
      <h5 class="mb-3 text-success">Assigned Panel & Schedule</h5>
      <p><strong>Student:</strong> <?= htmlspecialchars($schedule['student_name']) ?></p>
      <p><strong>Topic:</strong> <?= htmlspecialchars($schedule['topic_title']) ?></p>
      <p><strong>Date:</strong> <?= htmlspecialchars($schedule['schedule_date']) ?></p>
      <p><strong>Time:</strong> <?= htmlspecialchars($schedule['schedule_time']) ?></p>
      <p><strong>Venue:</strong> <?= htmlspecialchars($schedule['venue']) ?></p>
      <p><strong>Status:</strong>
        <span class="badge bg-<?php
          echo $schedule['status'] === 'confirmed' ? 'success' : ($schedule['status'] === 'pending' ? 'warning' : 'secondary');
        ?>">
          <?= ucwords($schedule['status']) ?>
        </span>
      </p>
      <p><strong>Panel Members:</strong></p>
      <div>
        <?php
          $panels = explode('||SEP||', $schedule['panel_members']);
          foreach ($panels as $p) {
            list($name, $role) = explode('||', $p);
            if ($role === 'chair') {
              echo '<span class="badge badge-chair me-2">' . htmlspecialchars($name) . ' (Chair)</span>';
            } else {
              echo '<span class="badge badge-member me-2">' . htmlspecialchars($name) . '</span>';
            }
          }
        ?>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-info">
      No defense schedule has been assigned to you yet.
    </div>
  <?php endif; ?>
</div>
