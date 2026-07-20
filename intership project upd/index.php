<?php
/**
 * Unified Portal Gateway & Login Page
 * Default entry point. Redirects to dashboard if logged in, otherwise displays the role-detecting login.
 */
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Force logout parameter handler
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params["path"], $params["domain"],
      $params["secure"], $params["httponly"]
    );
  }
  if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 42000, '/');
  }
  session_destroy();
  header("Location: index.php");
  exit;
}

// Redirect if already authenticated
if (isset($_SESSION['user_id'])) {
  header("Location: dashboard.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - Campus Recruitment Portal</title>
  <link rel="stylesheet" href="css/design-system.css">
  <link rel="stylesheet" href="css/auth.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <style>
    /* Styling adjustments for single-page unified login */
    .role-badge-row {
      display: flex;
      justify-content: center;
      gap: var(--space-1);
      margin-bottom: var(--space-2);
      flex-wrap: wrap;
    }
    .role-badge {
      font-size: 11px;
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #94A3B8;
      cursor: pointer;
      transition: all var(--transition-normal);
    }
    .role-badge:hover {
      border-color: var(--primary);
      color: #FFFFFF;
    }
    .role-badge.active {
      background: var(--primary);
      border-color: var(--primary);
      color: #FFFFFF;
      box-shadow: 0 0 10px rgba(37,99,235,0.4);
    }
  </style>
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
        <h2 class="auth-title">Unified Login Portal</h2>
        <p class="auth-subtitle">Enter credentials to access your placement dashboard</p>
      </div>

      <!-- Helper Role Badges to guide users about easy accounts -->
      <div class="role-badge-row">
        <div class="role-badge active" id="badge-stu">Student</div>
        <div class="role-badge" id="badge-comp">Recruiter</div>
        <div class="role-badge" id="badge-tpo">TPO</div>
      </div>

      <!-- Error Alert -->
      <div class="auth-alert-banner" id="auth-error-banner">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="auth-error-msg">Incorrect credentials</span>
      </div>

      <!-- Form -->
      <form id="unified-login-form" novalidate>
        
        <!-- Email Input -->
        <div class="form-group">
          <label class="form-label" for="login-email">Email Address</label>
          <div class="input-icon-wrapper">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" class="input-field" id="login-email" name="email" placeholder="student@university.edu" required autocomplete="username">
          </div>
        </div>

        <!-- Password Input -->
        <div class="form-group" style="margin-bottom: var(--space-25);">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <label class="form-label" for="login-password">Password</label>
            <span style="font-size:12px;"><a href="#" onclick="Swal.fire({title: 'Forgot Password?', text: 'Demo accounts use simplified passwords: admin123, tpo123, company123, student123.', icon: 'info', confirmButtonColor: '#2563EB'})" style="color:#60A5FA;">Forgot Password?</a></span>
          </div>
          <div class="input-icon-wrapper">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" class="input-field" id="login-password" name="password" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="show-password-btn" id="toggle-pw-btn">Show</button>
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-3);">
          <label class="checkbox-label">
            <input type="checkbox" class="checkbox-custom" name="remember" id="login-remember">
            <div class="checkbox-box"></div>
            Keep me logged in
          </label>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; gap: var(--space-1); font-weight: 700;" id="login-submit-btn">
          Sign In
        </button>

        <div class="auth-footer-link" style="margin-top:var(--space-25);">
          New Student? <a href="student/register.php">Register Profile</a>
        </div>
        <div class="auth-footer-link" style="margin-top:4px;">
          New Recruiter? <a href="company/register.php">Enroll Company</a>
        </div>
      </form>

    </div>
  </div>

  <script>
    // Micro-interactions: Show hints in inputs when clicking badges
    const emailInput = document.getElementById("login-email");
    const passInput = document.getElementById("login-password");
    const badges = {
      "badge-stu": { email: "aarav.sharma@university.edu", pass: "student123" },
      "badge-comp": { email: "google@recruiting.com", pass: "company123" },
      "badge-tpo": { email: "tpo@university.edu", pass: "tpo123" }
    };

    Object.keys(badges).forEach(id => {
      document.getElementById(id).addEventListener("click", () => {
        // Toggle active style
        Object.keys(badges).forEach(k => document.getElementById(k).classList.remove("active"));
        document.getElementById(id).classList.add("active");
        
        // Fill details for easy testing
        emailInput.value = badges[id].email;
        passInput.value = badges[id].pass;
      });
    });

    // Populate default (Student) on load
    emailInput.value = badges["badge-stu"].email;
    passInput.value = badges["badge-stu"].pass;

    // Password view toggle
    const toggleBtn = document.getElementById("toggle-pw-btn");
    toggleBtn.addEventListener("click", () => {
      if (passInput.type === "password") {
        passInput.type = "text";
        toggleBtn.innerText = "Hide";
      } else {
        passInput.type = "password";
        toggleBtn.innerText = "Show";
      }
    });

    // Form Submission
    const form = document.getElementById("unified-login-form");
    const banner = document.getElementById("auth-error-banner");
    const errorMsg = document.getElementById("auth-error-msg");
    const submitBtn = document.getElementById("login-submit-btn");

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      banner.classList.remove("active");

      const email = emailInput.value;
      const pw = passInput.value;

      if (!email.trim() || !pw.trim()) {
        errorMsg.innerText = "Please fill in all inputs.";
        banner.classList.add("active");
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerText = "Verifying Credentials...";

      const formData = new FormData(form);

      fetch('auth/login.php', {
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
          submitBtn.innerText = "Sign In";
        }
      })
      .catch(err => {
        errorMsg.innerText = "Authorization server error. Please retry.";
        banner.classList.add("active");
        submitBtn.disabled = false;
        submitBtn.innerText = "Sign In";
      });
    });
  </script>
</body>
</html>
