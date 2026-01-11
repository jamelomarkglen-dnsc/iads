<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Reviews - DNSC IAdS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link href="progchair.css" rel="stylesheet">
  <style>
    .content {
      margin-left: 220px;
      padding: 20px;
      transition: margin-left .3s;
      background: #f8f9fa;
      min-height: 100vh;
    }

    #sidebar.collapsed~.content {
      margin-left: 60px;
    }

    .card-header {
      background: #16562c;
      color: #fff;
      font-weight: 600;
    }

    .stats-card {
      transition: transform 0.2s ease;
    }

    .stats-card:hover {
      transform: translateY(-2px);
    }
  </style>
</head>

<body>
  <?php include 'header.php'; ?>
  <?php include 'sidebar.php'; ?>

  <div class="content">
    <div class="container my-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">My Reviews</h3>
        <div>
          <button class="btn btn-outline-secondary me-2" onclick="window.location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
          </button>
          <a href="faculty.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
          </a>
        </div>
      </div>

      <!-- Statistics Cards -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="card stats-card shadow-sm">
            <div class="card-body text-center">
              <i class="bi bi-star-fill text-primary fs-1"></i>
              <h5 class="mt-2">Total Reviews</h5>
              <h3 class="text-primary fw-bold">12</h3>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stats-card shadow-sm">
            <div class="card-body text-center">
              <i class="bi bi-check-circle text-success fs-1"></i>
              <h5 class="mt-2">Accepted</h5>
              <h3 class="text-success fw-bold">8</h3>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stats-card shadow-sm">
            <div class="card-body text-center">
              <i class="bi bi-x-circle text-danger fs-1"></i>
              <h5 class="mt-2">Rejected</h5>
              <h3 class="text-danger fw-bold">1</h3>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stats-card shadow-sm">
            <div class="card-body text-center">
              <i class="bi bi-arrow-repeat text-warning fs-1"></i>
              <h5 class="mt-2">Revisions</h5>
              <h3 class="text-warning fw-bold">3</h3>
            </div>
          </div>
        </div>
      </div>

      <!-- Average Rating Card -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-md-8">
              <h5 class="fw-bold mb-1">Average Rating</h5>
              <p class="text-muted mb-0">Your average rating across all reviews</p>
            </div>
            <div class="col-md-4 text-end">
              <div class="d-flex align-items-center justify-content-end">
                <span class="display-6 fw-bold text-primary me-2">4.2</span>
                <div>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star text-muted"></i>
                  <br><small class="text-muted">out of 5</small>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Submissions Table -->
      <div class="card shadow-sm">
        <div class="card-header">
          <h5 class="mb-0">
            <i class="bi bi-table"></i>
            Submissions for Review
          </h5>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-hover">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Title</th>
                  <th>Type</th>
                  <th>Submitted</th>
                  <th>Status</th>
                  <th>My Review</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>
                    <div>
                      <strong>John Doe</strong>
                      <br><small class="text-muted">john.doe@example.com</small>
                    </div>
                  </td>
                  <td>
                    <div>
                      <span class="fw-bold">Machine Learning Applications in Healthcare</span>
                      <br><small class="text-muted">This research explores the use of ML algorithms...</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-secondary">Thesis</span>
                  </td>
                  <td>
                    <div>
                      Jan 15, 2025
                      <br><small class="text-muted">2:30 PM</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-warning">Under Review</span>
                  </td>
                  <td>
                    <div>
                      <span class="badge bg-primary">4/5</span>
                      <br><small class="text-muted">Accept</small>
                      <br><small class="text-muted">Jan 16</small>
                    </div>
                  </td>
                  <td>
                    <div class="btn-group" role="group">
                      <button class="btn btn-sm btn-outline-primary" title="Download">
                        <i class="bi bi-download"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-success" title="Review">
                        <i class="bi bi-star"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-info" title="View Reviews">
                        <i class="bi bi-eye"></i>
                      </button>
                    </div>
                  </td>
                </tr>
                <tr>
                  <td>
                    <div>
                      <strong>Jane Smith</strong>
                      <br><small class="text-muted">jane.smith@example.com</small>
                    </div>
                  </td>
                  <td>
                    <div>
                      <span class="fw-bold">Sustainable Energy Solutions</span>
                      <br><small class="text-muted">A comprehensive study on renewable energy...</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-secondary">Dissertation</span>
                  </td>
                  <td>
                    <div>
                      Jan 12, 2025
                      <br><small class="text-muted">10:15 AM</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-success">Approved</span>
                  </td>
                  <td>
                    <div>
                      <span class="badge bg-primary">5/5</span>
                      <br><small class="text-muted">Accept</small>
                      <br><small class="text-muted">Jan 13</small>
                    </div>
                  </td>
                  <td>
                    <div class="btn-group" role="group">
                      <button class="btn btn-sm btn-outline-primary" title="Download">
                        <i class="bi bi-download"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-success" title="Review">
                        <i class="bi bi-star"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-info" title="View Reviews">
                        <i class="bi bi-eye"></i>
                      </button>
                    </div>
                  </td>
                </tr>
                <tr>
                  <td>
                    <div>
                      <strong>Mike Johnson</strong>
                      <br><small class="text-muted">mike.johnson@example.com</small>
                    </div>
                  </td>
                  <td>
                    <div>
                      <span class="fw-bold">Blockchain Technology in Supply Chain</span>
                      <br><small class="text-muted">An analysis of blockchain implementation...</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-secondary">Concept Paper</span>
                  </td>
                  <td>
                    <div>
                      Jan 10, 2025
                      <br><small class="text-muted">4:45 PM</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-info">Revision Required</span>
                  </td>
                  <td>
                    <div>
                      <span class="badge bg-primary">3/5</span>
                      <br><small class="text-muted">Minor Revision</small>
                      <br><small class="text-muted">Jan 11</small>
                    </div>
                  </td>
                  <td>
                    <div class="btn-group" role="group">
                      <button class="btn btn-sm btn-outline-primary" title="Download">
                        <i class="bi bi-download"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-success" title="Review">
                        <i class="bi bi-star"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-info" title="View Reviews">
                        <i class="bi bi-eye"></i>
                      </button>
                    </div>
                  </td>
                </tr>
                <tr>
                  <td>
                    <div>
                      <strong>Sarah Wilson</strong>
                      <br><small class="text-muted">sarah.wilson@example.com</small>
                    </div>
                  </td>
                  <td>
                    <div>
                      <span class="fw-bold">AI-Powered Educational Assessment</span>
                      <br><small class="text-muted">Development of intelligent assessment systems...</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-secondary">Thesis</span>
                  </td>
                  <td>
                    <div>
                      Jan 8, 2025
                      <br><small class="text-muted">1:20 PM</small>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-secondary">Pending</span>
                  </td>
                  <td>
                    <span class="text-muted">Not reviewed</span>
                  </td>
                  <td>
                    <div class="btn-group" role="group">
                      <button class="btn btn-sm btn-outline-primary" title="Download">
                        <i class="bi bi-download"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-success" title="Review">
                        <i class="bi bi-star"></i>
                      </button>
                      <button class="btn btn-sm btn-outline-info" title="View Reviews">
                        <i class="bi bi-eye"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Review Guidelines -->
      <div class="card shadow-sm mt-4">
        <div class="card-header">
          <h5 class="mb-0">
            <i class="bi bi-info-circle"></i>
            Review Guidelines
          </h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6">
              <h6 class="fw-bold text-primary">Evaluation Criteria:</h6>
              <ul class="list-unstyled">
                <li><i class="bi bi-check-circle text-success me-2"></i>Research methodology</li>
                <li><i class="bi bi-check-circle text-success me-2"></i>Literature review quality</li>
                <li><i class="bi bi-check-circle text-success me-2"></i>Data analysis approach</li>
                <li><i class="bi bi-check-circle text-success me-2"></i>Writing clarity and structure</li>
              </ul>
            </div>
            <div class="col-md-6">
              <h6 class="fw-bold text-primary">Rating Scale:</h6>
              <ul class="list-unstyled">
                <li><i class="bi bi-star-fill text-warning me-2"></i>5 - Excellent</li>
                <li><i class="bi bi-star-fill text-warning me-2"></i>4 - Good</li>
                <li><i class="bi bi-star-fill text-warning me-2"></i>3 - Satisfactory</li>
                <li><i class="bi bi-star-fill text-warning me-2"></i>2 - Needs Improvement</li>
                <li><i class="bi bi-star text-muted me-2"></i>1 - Poor</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>