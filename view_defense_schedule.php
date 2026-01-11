<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$student_id = $_SESSION['user_id'];

// ✅ Fetch defense schedule
$sql = "
  SELECT ds.id, ds.defense_date, ds.defense_time, ds.venue, ds.status,
         GROUP_CONCAT(dp.panel_member SEPARATOR ', ') AS panel_members
  FROM defense_schedules ds
  LEFT JOIN defense_panels dp ON dp.defense_id = ds.id
  WHERE ds.student_id = ?
  GROUP BY ds.id
  ORDER BY ds.defense_date DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$schedules = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include 'header.php';
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Defense Schedule - DNSC IAdS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background-color: #f8f9fa; overflow-x: hidden; }
.content { margin-left: 220px; padding: 25px; transition: margin-left .3s; }
.card-header { background: #16562c; color: #fff; font-weight: 600; }
.schedule-card { border-left: 6px solid #16562c; transition: transform .2s; }
.schedule-card:hover { transform: scale(1.01); }
.countdown { font-weight: 600; color: #16562c; }
.progress { height: 12px; margin-top: 5px; }
.circular-timer {
  width: 100px; height: 100px; position: relative; display: inline-block; margin-right: 15px;
}
.circular-timer svg { transform: rotate(-90deg); width: 100px; height: 100px; }
.circular-timer circle { fill: none; stroke-width: 10; stroke-linecap: round; }
.circular-bg { stroke: #e6e6e6; }
.circular-progress { stroke: #16562c; transition: stroke-dashoffset 1s linear; }
.circular-text {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  font-size: 1.2rem; font-weight: 700; color: #16562c;
}
/* 🔔 Animated Defense Banner */
#defenseBanner {
  display: none;
  position: relative;
  background: #fff3cd;
  border-left: 6px solid #ffc107;
  color: #856404;
  padding: 15px;
  border-radius: 6px;
  margin-bottom: 20px;
  font-weight: 500;
  opacity: 0;
  transform: translateY(-20px);
  transition: opacity 0.8s ease, transform 0.8s ease;
}
#defenseBanner.show {
  display: block;
  opacity: 1;
  transform: translateY(0);
}
</style>
</head>

<body>
<div class="content">
  <div class="container my-4">
    <h3 class="fw-bold text-success mb-4">
      <i class="bi bi-calendar-event me-2"></i> My Defense Schedule
    </h3>

    <!-- 🔔 Animated Defense Tomorrow Banner -->
    <div id="defenseBanner">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <span id="bannerText"><strong>Your defense is tomorrow!</strong></span>
    </div>

    <div class="card shadow-sm">
      <div class="card-header">
        <i class="bi bi-list-task me-2"></i> Defense Schedule Overview
      </div>
      <div class="card-body">
        <?php if (empty($schedules)): ?>
          <div class="alert alert-warning text-center mb-0">
            <i class="bi bi-info-circle"></i> You have no scheduled defense yet.
          </div>
        <?php else: ?>
          <?php foreach ($schedules as $index => $s): 
            $defenseDateTime = $s['defense_date'] . ' ' . $s['defense_time'];
          ?>
            <div class="schedule-card bg-white shadow-sm p-4 mb-4 rounded-3"
                 data-defense="<?= htmlspecialchars($defenseDateTime) ?>"
                 data-date="<?= htmlspecialchars(date('F d, Y', strtotime($s['defense_date']))) ?>"
                 data-time="<?= htmlspecialchars(date('h:i A', strtotime($s['defense_time']))) ?>"
                 id="schedule-<?= $index ?>">
              <div class="d-flex align-items-center mb-3">
                <div class="circular-timer">
                  <svg>
                    <circle class="circular-bg" cx="50" cy="50" r="45"></circle>
                    <circle class="circular-progress" id="circle-<?= $index ?>" cx="50" cy="50" r="45"
                            stroke-dasharray="283" stroke-dashoffset="283"></circle>
                  </svg>
                  <div class="circular-text" id="percent-<?= $index ?>">0%</div>
                </div>
                <h5 class="fw-bold text-success mb-0">
                  <i class="bi bi-mortarboard"></i> Defense Information
                </h5>
              </div>

              <div class="row mb-2">
                <div class="col-md-6">
                  <p class="mb-1"><strong>Date:</strong> <?= date('F d, Y', strtotime($s['defense_date'])) ?></p>
                  <p class="mb-1"><strong>Time:</strong> <?= date('h:i A', strtotime($s['defense_time'])) ?></p>
                </div>
                <div class="col-md-6">
                  <p class="mb-1"><strong>Venue:</strong> <?= htmlspecialchars($s['venue']) ?></p>
                  <p class="mb-1">
                    <strong>Status:</strong> 
                    <span class="badge <?= 
                      $s['status'] === 'Pending' ? 'bg-warning text-dark' : 
                      ($s['status'] === 'Confirmed' ? 'bg-success' : 'bg-secondary')
                    ?>"><?= htmlspecialchars($s['status']) ?></span>
                  </p>
                </div>
              </div>

              <hr>
              <p class="fw-bold mb-1"><i class="bi bi-people-fill"></i> Panel Members:</p>
              <p class="text-muted mb-3"><?= htmlspecialchars($s['panel_members'] ?: 'Not assigned yet.') ?></p>

              <div class="countdown" id="countdown-<?= $index ?>"></div>
              <div class="progress">
                <div class="progress-bar bg-success" id="progress-<?= $index ?>" style="width: 0%;"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 🕓 Live Countdown + Animated Banner
function updateCountdowns() {
  let showBanner = false;
  let bannerText = '';

  document.querySelectorAll('.schedule-card').forEach((card, index) => {
    const defenseDate = new Date(card.dataset.defense);
    const now = new Date();
    const countdownElem = document.getElementById('countdown-' + index);
    const progressElem = document.getElementById('progress-' + index);
    const circleElem = document.getElementById('circle-' + index);
    const percentText = document.getElementById('percent-' + index);

    const totalDays = 30;
    const diffMs = defenseDate - now;
    const daysLeft = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

    const radius = 45;
    const circumference = 2 * Math.PI * radius;
    let percent = 0;
    let statusText = "";

    if (diffMs > 0) {
      if (daysLeft === 1) {
        showBanner = true;
        bannerText = `⚠️ Your defense is tomorrow at <strong>${card.dataset.time}</strong> on <strong>${card.dataset.date}</strong>. Prepare your presentation and requirements.`;
      }
      statusText = `⏳ ${daysLeft} day${daysLeft !== 1 ? 's' : ''} left before defense`;
      percent = Math.min(100, Math.max(0, 100 - (daysLeft / totalDays) * 100));
      circleElem.style.stroke = "#16562c";
    } else if (Math.abs(daysLeft) <= 1) {
      statusText = "✅ Defense is today!";
      percent = 100;
    } else {
      statusText = `📅 Defense completed ${Math.abs(daysLeft)} day${Math.abs(daysLeft) !== 1 ? 's' : ''} ago`;
      percent = 100;
      circleElem.style.stroke = "#6c757d";
    }

    const offset = circumference - (percent / 100) * circumference;
    circleElem.style.strokeDashoffset = offset;
    progressElem.style.width = percent + '%';
    percentText.textContent = Math.round(percent) + '%';
    countdownElem.textContent = statusText;
  });

  // 🔔 Show or hide banner smoothly
  const banner = document.getElementById('defenseBanner');
  const bannerMessage = document.getElementById('bannerText');

  if (showBanner) {
    bannerMessage.innerHTML = bannerText;
    banner.classList.add('show');
  } else {
    banner.classList.remove('show');
    setTimeout(() => { banner.style.display = 'none'; }, 800);
  }
}

// 🔁 Update every second
updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>
</body>
</html>
