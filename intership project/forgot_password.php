<?php
/**
 * Forgot Password Page
 * Initiates PHPMailer-based password recovery.
 */
require_once __DIR__ . '/config/auth.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Handle AJAX POST requests for forgot password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  
  $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
  if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
    exit;
  }
  
  try {
    $db = getDB();
    
    // Check if email exists
    $stmt = $db->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
      echo json_encode(['status' => 'error', 'message' => 'No account found with this email.']);
      exit;
    }
    
    // Generate secure random token
    $token = bin2hex(random_bytes(32));
    
    // Save to database using database NOW() to avoid timezone mismatch with PHP
    $stmtUpdate = $db->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE email = ?");
    $stmtUpdate->execute([$token, $email]);
    
    // Load autoloader for PHPMailer
    require_once __DIR__ . '/vendor/autoload.php';
    
    $mail = new PHPMailer(true);
    
    // SMTP Configuration from environment (.env)
    $mail->isSMTP();
    $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('SMTP_USER');
    $mail->Password   = getenv('SMTP_PASS');
    $mail->SMTPSecure = getenv('SMTP_SECURE') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = getenv('SMTP_PORT') ?: 587;
    
    // Disable debug output for AJAX response compatibility, but capture logs if needed
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    
    // Recipients
    $mail->setFrom(getenv('MAIL_FROM') ?: 'support@university.edu', getenv('MAIL_FROM_NAME') ?: 'CampusRecruit Support');
    $mail->addAddress($email, $user['name']);
    
    // Generate absolute reset password link
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $absoluteResetLink = $protocol . "://" . $host . BASE_URL . "reset_password.php?token=" . $token;
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Reset Your Password - CRMS';
    $mail->Body    = "
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset='UTF-8'>
        <title>Reset Your Password</title>
        <style>
          body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0F172A; color: #F8FAFC; margin: 0; padding: 0; }
          .container { max-width: 600px; margin: 40px auto; background-color: #1E293B; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 40px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
          .header { text-align: center; margin-bottom: 30px; }
          .logo { font-size: 24px; font-weight: bold; color: #3B82F6; text-decoration: none; }
          .title { font-size: 20px; font-weight: bold; color: #FFFFFF; margin-top: 20px; }
          .content { line-height: 1.6; color: #94A3B8; font-size: 15px; }
          .btn-container { text-align: center; margin: 35px 0; }
          .btn { background-color: #3B82F6; color: #FFFFFF !important; padding: 12px 30px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 15px; display: inline-block; box-shadow: 0 4px 12px rgba(59,130,246,0.3); transition: all 0.2s ease; }
          .footer { text-align: center; margin-top: 40px; font-size: 12px; color: #64748B; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 20px; }
        </style>
      </head>
      <body>
        <div class='container'>
          <div class='header'>
            <a href='#' class='logo'>CampusRecruit</a>
            <div class='title'>Reset Your Password</div>
          </div>
          <div class='content'>
            <p>Hello " . htmlspecialchars($user['name']) . ",</p>
            <p>We received a request to reset the password for your account on the Campus Recruitment Management System.</p>
            <p>Click the button below to set a new password. This link is valid for <strong>30 minutes</strong>.</p>
            <div class='btn-container'>
              <a href='{$absoluteResetLink}' target='_blank' class='btn'>Reset Password</a>
            </div>
            <p>If you didn't request a password reset, please ignore this email. Your password will remain unchanged.</p>
          </div>
          <div class='footer'>
            <p>This is an automated security message from CampusRecruit Portal.</p>
            <p>&copy; " . date('Y') . " CampusRecruit. All rights reserved.</p>
          </div>
        </div>
      </body>
      </html>
    ";
    
    $mail->send();
    echo json_encode(['status' => 'success', 'message' => 'A password reset link has been sent to your email.']);
    exit;
  } catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Mailer Error: ' . $mail->ErrorInfo]);
    exit;
  } catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error encountered during password reset request.']);
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - CRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/design-system.css">
  <link rel="stylesheet" href="css/auth.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="auth-body">

  <div class="auth-wrapper">
    <div class="auth-card">
      
      <!-- Logo -->
      <div class="auth-logo-section">
        <div class="auth-brand-name">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
          CampusRecruit
        </div>
      </div>

      <div class="auth-header">
        <h2 class="auth-title">Reset Your Password</h2>
        <p class="auth-subtitle">Enter your registered email address to receive a secure recovery link</p>
      </div>

      <!-- Error Alert -->
      <div class="auth-alert-banner" id="auth-error-banner">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="auth-error-msg">No account found with this email.</span>
      </div>

      <!-- Form -->
      <form id="forgot-password-form" novalidate>
        
        <div class="form-group" style="margin-bottom: var(--space-3);">
          <label class="form-label" for="reset-email">Email Address</label>
          <div class="input-icon-wrapper">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" class="input-field" id="reset-email" name="email" placeholder="name@domain.com" required autocomplete="username">
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; font-weight: 700;" id="submit-btn">
          Send Reset Link
        </button>

        <div class="auth-footer-link" style="margin-top: var(--space-3);">
          Remembered password? <a href="index.php">Back to Login</a>
        </div>
      </form>

    </div>
  </div>

  <script>
    const form = document.getElementById("forgot-password-form");
    const banner = document.getElementById("auth-error-banner");
    const errorMsg = document.getElementById("auth-error-msg");
    const submitBtn = document.getElementById("submit-btn");

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      banner.classList.remove("active");

      const emailInput = document.getElementById("reset-email");
      const email = emailInput.value.trim();

      if (!email) {
        errorMsg.innerText = "Please enter your email address.";
        banner.classList.add("active");
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerText = "Sending Reset Link...";

      const formData = new FormData(form);

      fetch('forgot_password.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(res => {
        if (res.status === 'success') {
          Swal.fire({
            title: 'Email Sent!',
            text: res.message,
            icon: 'success',
            confirmButtonColor: '#2563EB',
            confirmButtonText: 'OK'
          }).then(() => {
            emailInput.value = '';
            submitBtn.disabled = false;
            submitBtn.innerText = "Send Reset Link";
          });
        } else {
          errorMsg.innerText = res.message;
          banner.classList.add("active");
          submitBtn.disabled = false;
          submitBtn.innerText = "Send Reset Link";
        }
      })
      .catch(err => {
        errorMsg.innerText = "An error occurred. Please try again.";
        banner.classList.add("active");
        submitBtn.disabled = false;
        submitBtn.innerText = "Send Reset Link";
      });
    });
  </script>
</body>
</html>
