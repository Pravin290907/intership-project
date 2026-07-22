<?php
/**
 * TPO Portal Login
 * Premium glassmorphic screen for Training & Placement Officer authentication.
 */
require_once __DIR__ . '/../config/auth.php';

if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'tpo') {
  header("Location: " . BASE_URL . "tpo/dashboard.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TPO Portal - CRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/design-system.min.css">
  <link rel="stylesheet" href="../css/auth.min.css">
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
        <h2 class="auth-title">Placement Office Portal</h2>
        <p class="auth-subtitle">Training & Placement Officers authentication</p>
      </div>

      <!-- Error Alert -->
      <div class="auth-alert-banner" id="auth-error-banner">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="auth-error-msg">Incorrect credentials</span>
      </div>

      <!-- Form -->
      <form id="tpo-login-form" novalidate>
        <input type="hidden" name="role" value="tpo">
        
        <div class="form-group">
          <label class="form-label" for="tpo-email">TPO Email Address</label>
          <div class="input-icon-wrapper">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" class="input-field" id="tpo-email" name="email" placeholder="tpo@university.edu" required autocomplete="username">
          </div>
        </div>

        <div class="form-group" style="margin-bottom: var(--space-25);">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <label class="form-label" for="tpo-password">Password</label>
            <span style="font-size:12px;"><a href="../forgot_password.php" style="color:#60A5FA;">Forgot?</a></span>
          </div>
          <div class="input-icon-wrapper">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" class="input-field" id="tpo-password" name="password" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="show-password-btn" id="toggle-pw-btn">Show</button>
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3);">
          <label class="checkbox-label">
            <input type="checkbox" class="checkbox-custom" name="remember" id="tpo-remember">
            <div class="checkbox-box"></div>
            Remember Session
          </label>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; gap: var(--space-1); font-weight: 700;" id="login-submit-btn">
          Verify Officer Access
        </button>
      </form>

    </div>
  </div>

  <script>
    const pwInput = document.getElementById("tpo-password");
    const toggleBtn = document.getElementById("toggle-pw-btn");
    toggleBtn.addEventListener("click", () => {
      if (pwInput.type === "password") {
        pwInput.type = "text";
        toggleBtn.innerText = "Hide";
      } else {
        pwInput.type = "password";
        toggleBtn.innerText = "Show";
      }
    });

    const form = document.getElementById("tpo-login-form");
    const banner = document.getElementById("auth-error-banner");
    const errorMsg = document.getElementById("auth-error-msg");
    const submitBtn = document.getElementById("login-submit-btn");

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      banner.classList.remove("active");
      
      const email = document.getElementById("tpo-email").value;
      const pw = pwInput.value;
      
      if (!email.trim() || !pw.trim()) {
        errorMsg.innerText = "All fields required.";
        banner.classList.add("active");
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerText = "Authorizing...";

      const formData = new FormData(form);

      fetch('../auth/login.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
      .then(res => res.json())
      .then(res => {
        if (res.status === 'success') {
          submitBtn.innerText = "Redirecting...";
          window.location.href = res.redirect;
        } else {
          errorMsg.innerText = res.message;
          banner.classList.add("active");
          submitBtn.disabled = false;
          submitBtn.innerText = "Verify Officer Access";
        }
      })
      .catch(err => {
        errorMsg.innerText = "Request timeout. Try again.";
        banner.classList.add("active");
        submitBtn.disabled = false;
        submitBtn.innerText = "Verify Officer Access";
      });
    });
  </script>
</body>
</html>
