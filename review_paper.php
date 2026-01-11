<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Review Paper - DNSC IAdS</title>
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

    .rating-section {
      background: #f8f9fa;
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 15px;
    }

    .rating-stars {
      font-size: 1.2rem;
      color: #ffc107;
      cursor: pointer;
    }

    .rating-stars .bi-star {
      color: #dee2e6;
    }

    .rating-stars .bi-star:hover,
    .rating-stars .bi-star.active {
      color: #ffc107;
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
        <span class="fw-bold">Adviser</span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
      </ul>
    </div>
  </nav>

  <div class="content">
    <div class="container my-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">Review Student Paper</h3>
        <div>
          <a href="adviser.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
          </a>
        </div>
      </div>

      <!-- Student & Paper Information -->
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-person-circle"></i> Student Information</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-8">
              <h4 class="text-primary mb-3">Machine Learning Applications in Healthcare</h4>
              <div class="row">
                <div class="col-md-6">
                  <p><strong>Student:</strong> John Doe</p>
                  <p><strong>Email:</strong> john.doe@example.com</p>
                  <p><strong>Student ID:</strong> 2023-001234</p>
                </div>
                <div class="col-md-6">
                  <p><strong>Program:</strong> MS Computer Science</p>
                  <p><strong>Submitted:</strong> January 15, 2025</p>
                  <p><strong>Type:</strong> <span class="badge bg-secondary">Thesis</span></p>
                </div>
              </div>
            </div>
            <div class="col-md-4 text-end">
              <button class="btn btn-primary mb-2">
                <i class="bi bi-download"></i> Download Paper
              </button>
              <br>
              <button class="btn btn-outline-info">
                <i class="bi bi-envelope"></i> Email Student
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Review Form -->
      <div class="card shadow-sm">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Adviser Review Form</h5>
        </div>
        <div class="card-body">
          <form>
            <!-- Overall Assessment -->
            <div class="rating-section">
              <h6 class="fw-bold mb-3">Overall Assessment</h6>
              <div class="row">
                <div class="col-md-6">
                  <label class="form-label">Overall Quality Rating</label>
                  <div class="rating-stars" id="overallRating">
                    <i class="bi bi-star" data-rating="1"></i>
                    <i class="bi bi-star" data-rating="2"></i>
                    <i class="bi bi-star" data-rating="3"></i>
                    <i class="bi bi-star" data-rating="4"></i>
                    <i class="bi bi-star" data-rating="5"></i>
                  </div>
                  <small class="text-muted">Click to rate (1-5 stars)</small>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Recommendation</label>
                  <select class="form-select">
                    <option value="">Select recommendation...</option>
                    <option value="approve">Approve for Defense</option>
                    <option value="minor_revision">Minor Revisions Required</option>
                    <option value="major_revision">Major Revisions Required</option>
                    <option value="resubmit">Resubmit After Substantial Changes</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- Detailed Evaluation -->
            <div class="rating-section">
              <h6 class="fw-bold mb-3">Detailed Evaluation</h6>
              <div class="row">
                <div class="col-md-6">
                  <div class="mb-3">
                    <label class="form-label">Research Problem & Objectives</label>
                    <select class="form-select form-select-sm">
                      <option value="">Rate 1-5</option>
                      <option value="5">5 - Excellent</option>
                      <option value="4">4 - Good</option>
                      <option value="3">3 - Satisfactory</option>
                      <option value="2">2 - Needs Improvement</option>
                      <option value="1">1 - Poor</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Literature Review</label>
                    <select class="form-select form-select-sm">
                      <option value="">Rate 1-5</option>
                      <option value="5">5 - Excellent</option>
                      <option value="4">4 - Good</option>
                      <option value="3">3 - Satisfactory</option>
                      <option value="2">2 - Needs Improvement</option>
                      <option value="1">1 - Poor</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Research Methodology</label>
                    <select class="form-select form-select-sm">
                      <option value="">Rate 1-5</option>
                      <option value="5">5 - Excellent</option>
                      <option value="4">4 - Good</option>
                      <option value="3">3 - Satisfactory</option>
                      <option value="2">2 - Needs Improvement</option>
                      <option value="1">1 - Poor</option>
                    </select>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="mb-3">
                    <label class="form-label">Data Analysis & Results</label>
                    <select class="form-select form-select-sm">
                      <option value="">Rate 1-5</option>
                      <option value="5">5 - Excellent</option>
                      <option value="4">4 - Good</option>
                      <option value="3">3 - Satisfactory</option>
                      <option value="2">2 - Needs Improvement</option>
                      <option value="1">1 - Poor</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Discussion & Conclusions</label>
                    <select class="form-select form-select-sm">
                      <option value="">Rate 1-5</option>
                      <option value="5">5 - Excellent</option>
                      <option value="4">4 - Good</option>
                      <option value="3">3 - Satisfactory</option>
                      <option value="2">2 - Needs Improvement</option>
                      <option value="1">1 - Poor</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Writing Quality & Organization</label>
                    <select class="form-select form-select-sm">
                      <option value="">Rate 1-5</option>
                      <option value="5">5 - Excellent</option>
                      <option value="4">4 - Good</option>
                      <option value="3">3 - Satisfactory</option>
                      <option value="2">2 - Needs Improvement</option>
                      <option value="1">1 - Poor</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Feedback Sections -->
            <div class="mb-3">
              <label class="form-label fw-bold">Strengths of the Research</label>
              <textarea class="form-control" rows="4" placeholder="Highlight the strong points and positive aspects of this research work..."></textarea>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Areas Requiring Improvement</label>
              <textarea class="form-control" rows="4" placeholder="Identify specific areas that need improvement or revision..."></textarea>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Specific Comments & Suggestions</label>
              <textarea class="form-control" rows="5" placeholder="Provide detailed, constructive feedback and specific suggestions for improvement..."></textarea>
            </div>

            <div class="mb-4">
              <label class="form-label fw-bold">Next Steps & Action Items</label>
              <textarea class="form-control" rows="3" placeholder="Outline the next steps the student should take..."></textarea>
            </div>

            <!-- Meeting Schedule -->
            <div class="card bg-light mb-4">
              <div class="card-header bg-transparent">
                <h6 class="mb-0">Schedule Follow-up Meeting</h6>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-6">
                    <label class="form-label">Meeting Date</label>
                    <input type="date" class="form-control">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Meeting Time</label>
                    <input type="time" class="form-control">
                  </div>
                </div>
                <div class="mt-3">
                  <label class="form-label">Meeting Purpose</label>
                  <select class="form-select">
                    <option value="">Select purpose...</option>
                    <option value="discuss_revisions">Discuss Revisions</option>
                    <option value="progress_check">Progress Check</option>
                    <option value="defense_preparation">Defense Preparation</option>
                    <option value="methodology_review">Methodology Review</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="d-flex justify-content-between">
              <button type="button" class="btn btn-outline-secondary">
                <i class="bi bi-save"></i> Save Draft
              </button>
              <button type="button" class="btn btn-success btn-lg">
                <i class="bi bi-send"></i> Submit Review & Send to Student
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Star rating functionality
    const stars = document.querySelectorAll('#overallRating .bi-star');
    let currentRating = 0;

    stars.forEach(star => {
      star.addEventListener('click', function() {
        currentRating = parseInt(this.dataset.rating);
        updateStars();
      });

      star.addEventListener('mouseover', function() {
        const hoverRating = parseInt(this.dataset.rating);
        highlightStars(hoverRating);
      });
    });

    document.getElementById('overallRating').addEventListener('mouseleave', function() {
      updateStars();
    });

    function updateStars() {
      stars.forEach((star, index) => {
        if (index < currentRating) {
          star.classList.remove('bi-star');
          star.classList.add('bi-star-fill', 'active');
        } else {
          star.classList.remove('bi-star-fill', 'active');
          star.classList.add('bi-star');
        }
      });
    }

    function highlightStars(rating) {
      stars.forEach((star, index) => {
        if (index < rating) {
          star.classList.remove('bi-star');
          star.classList.add('bi-star-fill');
        } else {
          star.classList.remove('bi-star-fill');
          star.classList.add('bi-star');
        }
      });
    }
  </script>
</body>

</html>