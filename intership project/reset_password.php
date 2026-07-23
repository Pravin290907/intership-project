<?php
/**
 * Reset Password Page
 * Validates reset token and updates the user's password.
 */
require_once __DIR__ . '/config/auth.php';

$token = $_GET['token'] ?? '';
$isValid = false;
$user = null;

// Handle AJAX POST request for updating the password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  
  $token = $_POST['token'] ?? '';
  $password = $_POST['password'] ?? '';
  $confirm_password = $_POST['confirm_password'] ?? '';
  
  if (empty($token)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing session token.']);
    exit;
  }
  
  if (empty($password) || strlen($password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters long.']);
    exit;
  }
  
  if ($password !== $confirm_password) {
    echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
    exit;
  }
  
  try {
    $db = getDB();
    
    // Double check token validity and expiry in the database
    $stmt = $db->prepare("SELECT id, role FROM users WHERE reset_token = ? AND reset_token_expiry >= NOW() LIMIT 1");
    $stmt->execute([$token]);
    $userRecord = $stmt->fetch();
    
    if (!$userRecord) {
      echo json_encode(['status' => 'error', 'message' => 'This reset link is invalid or has expired.']);
      exit;
    }
    
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    
    // Update password and clear token/expiry
    $stmtUpdate = $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
    $stmtUpdate->execute([$password_hash, $userRecord['id']]);
    
    // Determine redirect login page based on role
    $redirectUrl = 'index.php';
    if ($userRecord['role'] === 'student') $redirectUrl = 'student/login.php';
    else if ($userRecord['role'] === 'company') $redirectUrl = 'company/login.php';
    else if ($userRecord['role'] === 'tpo') $redirectUrl = 'tpo/login.php';
    else if ($userRecord['role'] === 'admin') $redirectUrl = 'admin/login.php';
    
    echo json_encode([
      'status' => 'success', 
      'message' => 'Password reset successfully.', 
      'redirect' => BASE_URL . $redirectUrl
    ]);
    exit;
  } catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error encountered during password reset.']);
    exit;
  }
}

// GET request: Validate the token from the URL
if (!empty($token)) {
  try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email FROM users WHERE reset_token = ? AND reset_token_expiry >= NOW() LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
      $isValid = true;
    }
  } catch (PDOException $e) {
    $isValid = false;
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

      <?php if ($isValid && $user): ?>
        <div class="auth-header">
          <h2 class="auth-title">Reset Your Password</h2>
          <p class="auth-subtitle">Set your new account credentials securely for <?= htmlspecialchars($user['email']) ?></p>
        </div>

        <!-- Error Alert -->
        <div class="auth-alert-banner" id="auth-error-banner">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span id="auth-error-msg">Incorrect parameters</span>
        </div>

        <!-- Form -->
        <form id="reset-password-form" novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          
          <div class="form-group">
            <label class="form-label" for="new-password">New Password</label>
            <div class="input-icon-wrapper">
              <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" class="input-field" id="new-password" name="password" placeholder="••••••••" required autocomplete="new-password">
              <button type="button" class="show-password-btn" id="toggle-pw-btn-1">Show</button>
            </div>
          </div>

          <div class="form-group" style="margin-bottom: var(--space-3);">
            <label class="form-label" for="confirm-password">Confirm Password</label>
            <div class="input-icon-wrapper">
              <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" class="input-field" id="confirm-password" name="confirm_password" placeholder="••••••••" required autocomplete="new-password">
              <button type="button" class="show-password-btn" id="toggle-pw-btn-2">Show</button>
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; font-weight: 700;" id="submit-btn">
            Reset Password
          </button>
        </form>

        <script>
          const pw1 = document.getElementById("new-password");
          const toggle1 = document.getElementById("toggle-pw-btn-1");
          toggle1.addEventListener("click", () => {
            if (pw1.type === "password") {
              pw1.type = "text";
              toggle1.innerText = "Hide";
            } else {
              pw1.type = "password";
              toggle1.innerText = "Show";
            }
          });

          const pw2 = document.getElementById("confirm-password");
          const toggle2 = document.getElementById("toggle-pw-btn-2");
          toggle2.addEventListener("click", () => {
            if (pw2.type === "password") {
              pw2.type = "text";
              toggle2.innerText = "Hide";
            } else {
              pw2.type = "password";
              toggle2.innerText = "Show";
            }
          });

          const form = document.getElementById("reset-password-form");
          const banner = document.getElementById("auth-error-banner");
          const errorMsg = document.getElementById("auth-error-msg");
          const submitBtn = document.getElementById("submit-btn");

          form.addEventListener("submit", (e) => {
            e.preventDefault();
            banner.classList.remove("active");

            const passVal = pw1.value;
            const confVal = pw2.value;

            if (!passVal || !confVal) {
              errorMsg.innerText = "Please fill in all inputs.";
              banner.classList.add("active");
              return;
            }

            if (passVal.length < 6) {
              errorMsg.innerText = "Password must be at least 6 characters.";
              banner.classList.add("active");
              return;
            }

            if (passVal !== confVal) {
              errorMsg.innerText = "Passwords do not match.";
              banner.classList.add("active");
              return;
            }

            submitBtn.disabled = true;
            submitBtn.innerText = "Updating Password...";

            const formData = new FormData(form);

            fetch('reset_password.php', {
              method: 'POST',
              body: formData
            })
            .then(res => res.json())
            .then(res => {
              if (res.status === 'success') {
                Swal.fire({
                  title: 'Success!',
                  text: 'Password reset successfully.',
                  icon: 'success',
                  showConfirmButton: false,
                  timer: 3000,
                  timerProgressBar: true
                });
                setTimeout(() => {
                  window.location.href = res.redirect;
                }, 3000);
              } else {
                errorMsg.innerText = res.message;
                banner.classList.add("active");
                submitBtn.disabled = false;
                submitBtn.innerText = "Reset Password";
              }
            })
            .catch(err => {
              errorMsg.innerText = "An error occurred. Please try again.";
              banner.classList.add("active");
              submitBtn.disabled = false;
              submitBtn.innerText = "Reset Password";
            });
          });
        </script>

      <?php else: ?>
        <div class="auth-header">
          <h2 class="auth-title" style="color: #EF4444; font-size: 20px;">Expired or Invalid Link</h2>
          <p class="auth-subtitle" style="margin-top: 12px; font-size: 14px;">This reset link is invalid or has expired.</p>
        </div>

        <div class="auth-footer-link" style="margin-top: var(--space-4); text-align: center;">
          <a href="forgot_password.php" class="btn btn-primary" style="display: inline-block; padding: 10px 20px; font-weight: 600; text-decoration: none;">Request New Link</a>
        </div>
      <?php endif; ?>

    </div>
  </div>

</body>
</html>
