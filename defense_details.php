<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Defense Details - DNSC IAdS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    .content {
      margin-left: 220px;
      padding: 20px;
      transition: margin-left .3s;
      background: #f8f9fa;
      min-height: 100vh;
    }

    .card-header {
      background: #16562c;
      color: #fff;
      font-weight: 600;
    }
  </style>
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark shadow-sm px-4 sticky-top" style="background-color: #16562cff; padding-top: 10px; padding-bottom: 10px;">
    <div class="d-flex align-items-center position-relative">
      <a href="index.php" class="text-decoration-none">
        <div class="d-flex align-items-center">
          <img src="IAdS.png" alt="DNSC IAdS Logo" style="max-height: 50px; background: white; padding: 5px; border-radius: 5px; margin-right: 15px;">
          <div>
            <h4 class="fw-bold m-0" style="color: #ffc107;">DNSC</h4>
            <small class="text-white">Institute of Advanced Studies</small>
          </div>
        </div>
      </a>
    </div>
    <div class="ms-auto dropdown">
      <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
        <img src="https://cdn-icons-png.flaticon.com/512/3135/3135715.png" alt="Avatar" width="40" height="40" class="rounded-circle me-2 border border-light">
        <span class="fw-bold">Panel Member</span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
      </ul>
    </div>
  </nav>

  <div class="content">
    <div class="container my-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">Defense Details</h3>
        <div>
          <a href="panel.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
          </a>
        </div>
      </div>

      <!-- Defense Information -->
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-calendar-event"></i> Defense Information</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-8">
              <h4 class="text-primary mb-3">Machine Learning Applications in Healthcare</h4>
              <div class="row">
                <div class="col-md-6">
                  <p><strong>Student:</strong> John Doe</p>
                  <p><strong>Email:</strong> john.doe@example.com</p>
                  <p><strong>Program:</strong> Master of Science in Computer Science</p>
                </div>
                <div class="col-md-6">
                  <p><strong>Defense Date:</strong> January 25, 2025</p>
                  <p><strong>Time:</strong> 2:00 PM - 4:00 PM</p>
                  <p><strong>Venue:</strong> Conference Room A</p>
                </div>
              </div>
            </div>
            <div class="col-md-4 text-end">
              <span class="badge bg-success fs-6 mb-3">Confirmed</span>
              <br>
              <button class="btn btn-primary mb-2">
                <i class="bi bi-download"></i> Download Paper
              </button>
              <br>
              <button class="btn btn-outline-info">
                <i class="bi bi-calendar-plus"></i> Add to Calendar
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Panel Members -->
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-people"></i> Panel Members</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <div class="card border-primary">
                <div class="card-body text-center">
                  <i class="bi bi-person-badge text-primary fs-1"></i>
                  <h6 class="mt-2">Dr. Maria Santos</h6>
                  <span class="badge bg-primary">Panel Chairperson</span>
                  <p class="small text-muted mt-2">maria.santos@dnsc.edu.ph</p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card">
                <div class="card-body text-center">
                  <i class="bi bi-person text-secondary fs-1"></i>
                  <h6 class="mt-2">Prof. Robert Chen</h6>
                  <span class="badge bg-secondary">Panel Member</span>
                  <p class="small text-muted mt-2">robert.chen@dnsc.edu.ph</p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card">
                <div class="card-body text-center">
                  <i class="bi bi-person text-secondary fs-1"></i>
                  <h6 class="mt-2">Dr. Lisa Thompson</h6>
                  <span class="badge bg-secondary">Panel Member</span>
                  <p class="small text-muted mt-2">lisa.thompson@dnsc.edu.ph</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Defense Schedule -->
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-clock"></i> Defense Schedule</h5>
        </div>
        <div class="card-body">
          <div class="timeline">
            <div class="d-flex mb-3">
              <div class="flex-shrink-0">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                  1
                </div>
              </div>
              <div class="flex-grow-1 ms-3">
                <h6 class="mb-1">Opening & Introduction</h6>
                <p class="text-muted mb-0">2:00 PM - 2:10 PM (10 minutes)</p>
              </div>
            </div>
            <div class="d-flex mb-3">
              <div class="flex-shrink-0">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                  2
                </div>
              </div>
              <div class="flex-grow-1 ms-3">
                <h6 class="mb-1">Student Presentation</h6>
                <p class="text-muted mb-0">2:10 PM - 2:40 PM (30 minutes)</p>
              </div>
            </div>
            <div class="d-flex mb-3">
              <div class="flex-shrink-0">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                  3
                </div>
              </div>
              <div class="flex-grow-1 ms-3">
                <h6 class="mb-1">Panel Questions & Discussion</h6>
                <p class="text-muted mb-0">2:40 PM - 3:30 PM (50 minutes)</p>
              </div>
            </div>
            <div class="d-flex mb-3">
              <div class="flex-shrink-0">
                <div class="bg-warning text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                  4
                </div>
              </div>
              <div class="flex-grow-1 ms-3">
                <h6 class="mb-1">Panel Deliberation</h6>
                <p class="text-muted mb-0">3:30 PM - 3:50 PM (20 minutes)</p>
              </div>
            </div>
            <div class="d-flex">
              <div class="flex-shrink-0">
                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                  5
                </div>
              </div>
              <div class="flex-grow-1 ms-3">
                <h6 class="mb-1">Results & Closing</h6>
                <p class="text-muted mb-0">3:50 PM - 4:00 PM (10 minutes)</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Evaluation Form -->
      <div class="card shadow-sm">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Panel Evaluation</h5>
        </div>
        <div class="card-body">
          <form>
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label fw-bold">Content Quality</label>
                <select class="form-select">
                  <option value="">Rate 1-5</option>
                  <option value="5">5 - Excellent</option>
                  <option value="4">4 - Good</option>
                  <option value="3">3 - Satisfactory</option>
                  <option value="2">2 - Needs Improvement</option>
                  <option value="1">1 - Poor</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold">Presentation Skills</label>
                <select class="form-select">
                  <option value="">Rate 1-5</option>
                  <option value="5">5 - Excellent</option>
                  <option value="4">4 - Good</option>
                  <option value="3">3 - Satisfactory</option>
                  <option value="2">2 - Needs Improvement</option>
                  <option value="1">1 - Poor</option>
                </select>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold">Comments & Feedback</label>
              <textarea class="form-control" rows="4" placeholder="Provide detailed feedback..."></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold">Recommendation</label>
              <select class="form-select">
                <option value="">Select recommendation...</option>
                <option value="pass">Pass</option>
                <option value="pass_with_minor_revisions">Pass with Minor Revisions</option>
                <option value="pass_with_major_revisions">Pass with Major Revisions</option>
                <option value="fail">Fail</option>
              </select>
            </div>
            <button type="button" class="btn btn-success">
              <i class="bi bi-check-circle"></i> Submit Evaluation
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>