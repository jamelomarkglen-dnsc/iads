<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Approve Submission - DNSC IAdS</title>
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

    .decision-card {
      border: 2px solid transparent;
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .decision-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .decision-card.selected {
      border-color: #16562c;
      background-color: #f8f9fa;
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
        <span class="fw-bold">Committee Chair</span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
      </ul>
    </div>
  </nav>

  <div class="content">
    <div class="container my-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">Approve Submission</h3>
        <div>
          <a href="my_committee_defense.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
          </a>
        </div>
      </div>

      <!-- Submission Details -->
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Submission Details</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-8">
              <h4 class="text-primary mb-3">Machine Learning Applications in Healthcare</h4>
              <p><strong>Student:</strong> John Doe (john.doe@example.com)</p>
              <p><strong>Submitted:</strong> January 15, 2025 at 2:30 PM</p>
              <p><strong>Type:</strong> <span class="badge bg-secondary">Thesis</span></p>
              <p><strong>Current Status:</strong> <span class="badge bg-warning">Under Review</span></p>
            </div>
            <div class="col-md-4 text-end">
              <button class="btn btn-primary mb-2">
                <i class="bi bi-download"></i> Download Paper
              </button>
              <br>
              <button class="btn btn-outline-info">
                <i class="bi bi-eye"></i> View Reviews
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Review Summary -->
      <div class="card shadow-sm mb-4">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-star-fill"></i> Review Summary</h5>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-4 text-center">
              <h3 class="text-primary">4.2</h3>
              <p class="text-muted">Average Rating</p>
              <div class="text-warning">
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star-fill"></i>
                <i class="bi bi-star"></i>
              </div>
            </div>
            <div class="col-md-4 text-center">
              <h3 class="text-success">3</h3>
              <p class="text-muted">Total Reviews</p>
              <small class="text-muted">All panel members reviewed</small>
            </div>
            <div class="col-md-4 text-center">
              <h3 class="text-info">2</h3>
              <p class="text-muted">Accept Recommendations</p>
              <small class="text-muted">1 minor revision</small>
            </div>
          </div>

          <hr>

          <h6 class="fw-bold mb-3">Individual Reviews:</h6>
          <div class="row">
            <div class="col-md-4">
              <div class="card border-success">
                <div class="card-body">
                  <h6>Dr. Maria Santos</h6>
                  <div class="d-flex justify-content-between">
                    <span class="badge bg-primary">4/5</span>
                    <span class="badge bg-success">Accept</span>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card border-warning">
                <div class="card-body">
                  <h6>Prof. Robert Chen</h6>
                  <div class="d-flex justify-content-between">
                    <span class="badge bg-primary">4/5</span>
                    <span class="badge bg-warning">Minor Revision</span>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card border-success">
                <div class="card-body">
                  <h6>Dr. Lisa Thompson</h6>
                  <div class="d-flex justify-content-between">
                    <span class="badge bg-primary">5/5</span>
                    <span class="badge bg-success">Accept</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Decision Form -->
      <div class="card shadow-sm">
        <div class="card-header">
          <h5 class="mb-0"><i class="bi bi-check-circle"></i> Final Decision</h5>
        </div>
        <div class="card-body">
          <form>
            <div class="mb-4">
              <label class="form-label fw-bold">Select Decision</label>
              <div class="row">
                <div class="col-md-3">
                  <div class="decision-card card text-center p-3" data-decision="approved">
                    <i class="bi bi-check-circle text-success fs-1"></i>
                    <h6 class="mt-2">Approve</h6>
                    <small class="text-muted">Ready for defense</small>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="decision-card card text-center p-3" data-decision="minor_revision">
                    <i class="bi bi-pencil text-warning fs-1"></i>
                    <h6 class="mt-2">Minor Revision</h6>
                    <small class="text-muted">Small changes needed</small>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="decision-card card text-center p-3" data-decision="major_revision">
                    <i class="bi bi-arrow-repeat text-info fs-1"></i>
                    <h6 class="mt-2">Major Revision</h6>
                    <small class="text-muted">Significant changes required</small>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="decision-card card text-center p-3" data-decision="rejected">
                    <i class="bi bi-x-circle text-danger fs-1"></i>
                    <h6 class="mt-2">Reject</h6>
                    <small class="text-muted">Does not meet standards</small>
                  </div>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Comments to Student</label>
              <textarea class="form-control" rows="5" placeholder="Provide feedback and instructions to the student..."></textarea>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Internal Notes</label>
              <textarea class="form-control" rows="3" placeholder="Internal notes for committee records (not visible to student)..."></textarea>
            </div>

            <div class="mb-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="notifyStudent">
                <label class="form-check-label" for="notifyStudent">
                  Send email notification to student
                </label>
              </div>
            </div>

            <div class="d-flex justify-content-between">
              <button type="button" class="btn btn-outline-secondary">
                <i class="bi bi-save"></i> Save Draft
              </button>
              <button type="button" class="btn btn-success btn-lg" id="submitDecision" disabled>
                <i class="bi bi-check-circle"></i> Submit Final Decision
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Decision card selection
    const decisionCards = document.querySelectorAll('.decision-card');
    const submitButton = document.getElementById('submitDecision');
    let selectedDecision = null;

    decisionCards.forEach(card => {
      card.addEventListener('click', function() {
        // Remove selected class from all cards
        decisionCards.forEach(c => c.classList.remove('selected'));

        // Add selected class to clicked card
        this.classList.add('selected');

        // Store selected decision
        selectedDecision = this.dataset.decision;

        // Enable submit button
        submitButton.disabled = false;
      });
    });

    // Submit decision
    submitButton.addEventListener('click', function() {
      if (selectedDecision) {
        const decisionText = document.querySelector(`[data-decision="${selectedDecision}"] h6`).textContent;
        if (confirm(`Are you sure you want to ${decisionText.toLowerCase()} this submission?`)) {
          alert(`Decision "${decisionText}" has been submitted successfully!`);
          // Here you would normally submit the form
        }
      }
    });
  </script>
</body>

</html>
