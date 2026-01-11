<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Page Not Found - DNSC IAdS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    body {
      background: linear-gradient(135deg, #16562c 0%, #2d8f47 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .error-container {
      text-align: center;
      color: white;
    }

    .error-code {
      font-size: 8rem;
      font-weight: bold;
      text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
      margin-bottom: 0;
    }

    .error-message {
      font-size: 1.5rem;
      margin-bottom: 2rem;
      opacity: 0.9;
    }

    .error-icon {
      font-size: 6rem;
      margin-bottom: 2rem;
      opacity: 0.8;
    }

    .btn-home {
      background-color: rgba(255, 255, 255, 0.2);
      border: 2px solid white;
      color: white;
      padding: 12px 30px;
      font-weight: 500;
      transition: all 0.3s ease;
    }

    .btn-home:hover {
      background-color: white;
      color: #16562c;
      transform: translateY(-2px);
    }

    .suggestions {
      background-color: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      padding: 2rem;
      margin-top: 3rem;
      backdrop-filter: blur(10px);
    }

    .suggestions h5 {
      margin-bottom: 1rem;
    }

    .suggestions ul {
      text-align: left;
      max-width: 400px;
      margin: 0 auto;
    }

    .suggestions li {
      margin-bottom: 0.5rem;
    }

    .suggestions a {
      color: white;
      text-decoration: underline;
    }

    .suggestions a:hover {
      color: #f0f0f0;
    }
  </style>
</head>

<body>
  <div class="container">
    <div class="error-container">
      <div class="error-icon">
        <i class="bi bi-exclamation-triangle"></i>
      </div>

      <h1 class="error-code">404</h1>

      <p class="error-message">Oops! The page you're looking for doesn't exist.</p>

      <div class="mb-4">
        <a href="index.php" class="btn btn-home btn-lg me-3">
          <i class="bi bi-house"></i> Go to Home
        </a>
        <button onclick="history.back()" class="btn btn-home btn-lg">
          <i class="bi bi-arrow-left"></i> Go Back
        </button>
      </div>

      <div class="suggestions">
        <h5><i class="bi bi-lightbulb"></i> What you can do:</h5>
        <ul class="list-unstyled">
          <li><i class="bi bi-check"></i> Check the URL for typos</li>
          <li><i class="bi bi-check"></i> Use the navigation menu to find what you need</li>
          <li><i class="bi bi-check"></i> <a href="login.php">Login to your account</a></li>
          <li><i class="bi bi-check"></i> <a href="register.php">Create a new account</a></li>
          <li><i class="bi bi-check"></i> Contact support if the problem persists</li>
        </ul>
      </div>

      <div class="mt-4">
        <small class="opacity-75">
          <i class="bi bi-info-circle"></i>
          If you believe this is an error, please contact the system administrator.
        </small>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Add some animation to the error code
    document.addEventListener('DOMContentLoaded', function() {
      const errorCode = document.querySelector('.error-code');
      errorCode.style.opacity = '0';
      errorCode.style.transform = 'translateY(-50px)';

      setTimeout(() => {
        errorCode.style.transition = 'all 0.8s ease';
        errorCode.style.opacity = '1';
        errorCode.style.transform = 'translateY(0)';
      }, 200);
    });

    // Add floating animation to the icon
    const icon = document.querySelector('.error-icon i');
    setInterval(() => {
      icon.style.transform = 'translateY(-10px)';
      setTimeout(() => {
        icon.style.transform = 'translateY(0)';
      }, 1000);
    }, 2000);
  </script>
</body>

</html>