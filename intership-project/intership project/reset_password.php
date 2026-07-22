<?php
/**
 * Reset Password Interface & Endpoint
 * Validates 30-minute tokens, resets password hash using password_hash(), and redirects automatically after 3s.
 */

require_once __DIR__ . '/config/auth.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$isTokenValid = false;
$user = null;
$errorMessage = '';
$successMessage = '';
$redirectAfter3Seconds = false;

if (empty($token)) {
  $errorMessage = 'This reset link is invalid or has expired.';
} else {
  try {
    $db = getDB();
    // Validate token existence & expiry in database
    $stmt = $db->prepare("SELECT id, name, email, reset_token, reset_token_expiry FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    // Validate case-sensitive token match in PHP to preserve index utilization
    if ($user && strcmp($user['reset_token'], $token) !== 0) {
      $user = false;
    }

    if (!$user) {
      $errorMessage = 'This reset link is invalid or has expired.';
    } else {
      $isTokenValid = true;
    }
  } catch (PDOException $e) {
    error_log("Token verification exception: " . $e->getMessage());
    $errorMessage = 'An unexpected database error occurred. Please try again later.';
  }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isTokenValid) {
  $newPassword = $_POST['new_password'] ?? '';
  $confirmPassword = $_POST['confirm_password'] ?? '';

  if (empty($newPassword) || empty($confirmPassword)) {
    $errorMessage = 'Please enter both password fields.';
  } else if ($newPassword !== $confirmPassword) {
    $errorMessage = 'Passwords do not match. Please try again.';
  } else if (strlen($newPassword) < 6) {
    $errorMessage = 'Password must be at least 6 characters long.';
  } else {
    try {
      // Hash password using BCRYPT
      $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

      // Update password in database and clear reset token & expiry
      $updateStmt = $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
      $updateStmt->execute([$passwordHash, $user['id']]);

      logActivity("Password reset successfully for user: {$user['name']}", "password_reset", $user['id']);

      $successMessage = 'Password reset successfully.';
      $redirectAfter3Seconds = true;
      $isTokenValid = false; // Hide form on success
    } catch (PDOException $e) {
      error_log("Password update exception: " . $e->getMessage());
      $errorMessage = 'An error occurred while updating your password. Please try again.';
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
  <title>Create New Password - CampusRecruit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/design-system.min.css">
  <link rel="stylesheet" href="css/auth.min.css">

  <?php if ($redirectAfter3Seconds): ?>
    <meta http-equiv="refresh" content="3;url=index.php">
  <?php endif; ?>
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
        <h2 class="auth-title">Set New Password</h2>
        <p class="auth-subtitle">Choose a strong password to secure your account</p>
      </div>

      <!-- Error Alert (If token invalid or passwords mismatch) -->
      <?php if (!empty($errorMessage)): ?>
        <div class="auth-alert-banner show" id="auth-error-banner" style="display: flex;">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span><?php echo htmlspecialchars($errorMessage); ?></span>
        </div>
      <?php endif; ?>

      <!-- Success Notification & Auto Redirect -->
      <?php if (!empty($successMessage)): ?>
        <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #4ADE80; padding: 16px; border-radius: var(--radius-md); font-size: 14px; margin-bottom: var(--space-3); text-align: center;">
          <div style="display: flex; align-items: center; justify-content: center; gap: 8px; font-weight: 700; margin-bottom: 6px; font-size: 16px;">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?php echo htmlspecialchars($successMessage); ?>
          </div>
          <p style="margin: 0; color: #94A3B8; font-size: 13px;">
            Redirecting to login page in <strong id="countdown-timer" style="color: #60A5FA;">3</strong> seconds...
          </p>
        </div>
      <?php endif; ?>

      <!-- Form (Displayed only if token is valid) -->
      <?php if ($isTokenValid): ?>
        <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

          <!-- New Password -->
          <div class="form-group" style="margin-bottom: var(--space-2);">
            <label class="form-label" for="new-password">New Password</label>
            <div class="input-icon-wrapper">
              <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" class="input-field" id="new-password" name="new_password" placeholder="••••••••" required minlength="6" autocomplete="new-password">
            </div>
          </div>

          <!-- Confirm Password -->
          <div class="form-group" style="margin-bottom: var(--space-3);">
            <label class="form-label" for="confirm-password">Confirm Password</label>
            <div class="input-icon-wrapper">
              <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input type="password" class="input-field" id="confirm-password" name="confirm_password" placeholder="••••••••" required minlength="6" autocomplete="new-password">
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="width: 100%; gap: var(--space-1); font-weight: 700;">
            Reset Password
          </button>
        </form>
      <?php endif; ?>

      <!-- Back to Login Link -->
      <div class="auth-footer-link" style="margin-top: var(--space-3); text-align: center;">
        <a href="index.php" style="color: #94A3B8; display: inline-flex; align-items: center; gap: 4px;">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Back to Login
        </a>
      </div>

    </div>
  </div>

  <?php if ($redirectAfter3Seconds): ?>
    <script>
      let secondsLeft = 3;
      const timerEl = document.getElementById('countdown-timer');
      const interval = setInterval(() => {
        secondsLeft--;
        if (timerEl) timerEl.textContent = secondsLeft;
        if (secondsLeft <= 0) {
          clearInterval(interval);
          window.location.href = 'index.php';
        }
      }, 1000);
    </script>
  <?php endif; ?>

</body>
</html>
