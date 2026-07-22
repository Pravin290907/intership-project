<?php
/**
 * Forgot Password Endpoint & Interface
 * Handles password reset link requests securely using PDO prepared statements and PHPMailer.
 */

// Debug configuration toggle (Set to true while developing or add ?debug=1 in URL)
$debugMode = isset($_GET['debug']) || getenv('APP_DEBUG') === 'true';
if ($debugMode) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mailer.php';

$errorMessage = '';
$successMessage = '';
$previewResetLink = '';
$debugInfo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

  if (empty($email)) {
    $errorMessage = 'Please enter your registered email address.';
  } else {
    // 1. Verify Database Connection
    $db = null;
    try {
      $db = getDB();
    } catch (\Throwable $e) {
      error_log("Database Connection Failed: " . $e->getMessage());
      $errorMessage = 'Database connection failed. Please verify MySQL server status.';
      if ($debugMode) {
        $debugInfo = 'DB Exception: ' . $e->getMessage();
      }
    }

    if ($db !== null) {
      try {
        // 2. Verify Email Exists in Database
        $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Validate case-sensitive email match in PHP to preserve index utilization
        if ($user && strcmp($user['email'], $email) !== 0) {
          $user = false;
        }

        if (!$user) {
          $errorMessage = 'No account found with this email.';
        } else {
          // 3. Generate and Save Reset Token
          $token = bin2hex(random_bytes(32));
          
          try {
            $updateStmt = $db->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
            $updateStmt->execute([$token, $user['id']]);
          } catch (\Throwable $e) {
            error_log("Token Save Error: " . $e->getMessage());
            $errorMessage = 'Token could not be saved to database.';
            if ($debugMode) {
              $debugInfo = 'Save Exception: ' . $e->getMessage();
            }
          }

          if (empty($errorMessage)) {
            // Build absolute reset link
            $resetLink = BASE_URL . 'reset_password.php?token=' . $token;

            // 4. Dispatch Email via PHPMailer
            $mailResult = sendResetPasswordMail($user['email'], $user['name'], $resetLink);

            if ($mailResult['success']) {
              $successMessage = $mailResult['message'];
            } else {
              $errorMessage = $mailResult['message'];
              if ($debugMode) {
                $debugInfo = 'Mail Details: ' . json_encode($mailResult);
              }
            }
          }
        }
      } catch (\Throwable $e) {
        error_log("Forgot Password General Throwable: " . $e->getMessage());
        $errorMessage = 'An error occurred while processing your request: ' . $e->getMessage();
        if ($debugMode) {
          $debugInfo = 'Trace: ' . $e->getTraceAsString();
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <base href="<?php echo BASE_URL; ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Your Password - CampusRecruit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/design-system.min.css">
  <link rel="stylesheet" href="css/auth.min.css">
</head>
<body class="auth-body">

  <div class="auth-wrapper">
    <div class="auth-card">
      
      <!-- Brand Logo -->
      <div class="auth-logo-section">
        <div class="auth-brand-name">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
          CampusRecruit
        </div>
      </div>

      <div class="auth-header">
        <h2 class="auth-title">Reset Your Password</h2>
        <p class="auth-subtitle">Enter your email to receive a password reset link</p>
      </div>

      <!-- Error Alert -->
      <?php if (!empty($errorMessage)): ?>
        <div class="auth-alert-banner show" id="auth-error-banner" style="display: flex; flex-direction: column; align-items: flex-start;">
          <div style="display: flex; align-items: center; gap: 8px;">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?php echo htmlspecialchars($errorMessage); ?></span>
          </div>
          <?php if (!empty($debugInfo)): ?>
            <pre style="margin-top: 8px; font-size: 11px; color: #FCA5A5; background: rgba(0,0,0,0.3); padding: 8px; border-radius: 4px; max-width: 100%; overflow-x: auto; white-space: pre-wrap;"><?php echo htmlspecialchars($debugInfo); ?></pre>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Success Alert -->
      <?php if (!empty($successMessage)): ?>
        <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #4ADE80; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px; margin-bottom: var(--space-3); display: flex; align-items: center; gap: 8px;">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span><?php echo htmlspecialchars($successMessage); ?></span>
        </div>
      <?php endif; ?>

      <!-- Form -->
      <form action="forgot_password.php" method="POST" id="forgot-password-form">
        
        <!-- Email Input -->
        <div class="form-group" style="margin-bottom: var(--space-3);">
          <label class="form-label" for="reset-email">Email Address</label>
          <div class="input-icon-wrapper">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" class="input-field" id="reset-email" name="email" placeholder="name@university.edu" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required autocomplete="email">
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; gap: var(--space-1); font-weight: 700;" id="submit-btn">
          Send Reset Link
        </button>

        <div class="auth-footer-link" style="margin-top: var(--space-3); text-align: center;">
          <a href="index.php" style="color: #94A3B8; display: inline-flex; align-items: center; gap: 4px;">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Login
          </a>
        </div>
      </form>

    </div>
  </div>

  <script>
    const form = document.getElementById("forgot-password-form");
    const btn = document.getElementById("submit-btn");
    if (form && btn) {
      form.addEventListener("submit", () => {
        btn.disabled = true;
        btn.innerText = "Sending Reset Link...";
      });
    }
  </script>
</body>
</html>
