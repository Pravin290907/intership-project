<?php
/**
 * Email OTP Verification Screen
 * Premium glassmorphic screen for verifying student and recruiter corporate emails.
 */
require_once __DIR__ . '/../config/auth.php';

// Handle AJAX actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $action = $_POST['action'] ?? '';
  $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

  if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide a valid email address.']);
    exit;
  }

  try {
    $db = getDB();

    // Load user and timezone-safe flags
    $stmt = $db->prepare("
      SELECT id, name, email, otp_code, otp_attempts, role, status, email_verified,
             CASE WHEN otp_expiry < NOW() THEN 1 ELSE 0 END as is_expired,
             CASE WHEN otp_created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) THEN 1 ELSE 0 END as resend_blocked,
             TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(otp_created_at, INTERVAL 60 SECOND)) as resend_countdown
      FROM users WHERE BINARY email = ? LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
      echo json_encode(['status' => 'error', 'message' => 'Email address is not registered.']);
      exit;
    }

    // Action 1: Verify OTP
    if ($action === 'verify') {
      $otp = trim($_POST['otp'] ?? '');

      if (!preg_match('/^[0-9]{6}$/', $otp)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid 6-digit OTP code.']);
        exit;
      }

      if ($user['email_verified']) {
        // Redirection target based on role
        $loginRedirect = '../index.php#login-portal';
        if ($user['role'] === 'student') {
          $loginRedirect = '../student/login.php';
        } else if ($user['role'] === 'company') {
          $loginRedirect = '../company/login.php';
        }
        echo json_encode(['status' => 'success', 'message' => 'Email is already verified. Redirecting...', 'redirect' => $loginRedirect]);
        exit;
      }

      if (empty($user['otp_code'])) {
        echo json_encode(['status' => 'error', 'message' => 'No active OTP verification code found. Please request a new one.']);
        exit;
      }

      if ($user['is_expired']) {
        echo json_encode(['status' => 'error', 'message' => 'The verification OTP has expired. Please request a new OTP.']);
        exit;
      }

      if ($user['otp_attempts'] >= 5) {
        echo json_encode(['status' => 'error', 'message' => 'Maximum verification attempts (5) exceeded. Please request a new OTP.']);
        exit;
      }

      // Verify hashed OTP
      if (!password_verify($otp, $user['otp_code'])) {
        $newAttempts = $user['otp_attempts'] + 1;
        $stmtAttempts = $db->prepare("UPDATE users SET otp_attempts = ? WHERE id = ?");
        $stmtAttempts->execute([$newAttempts, $user['id']]);

        $remaining = 5 - $newAttempts;
        if ($remaining <= 0) {
          echo json_encode(['status' => 'error', 'message' => 'Incorrect OTP. Maximum attempts exceeded. Please request a new OTP.']);
        } else {
          echo json_encode(['status' => 'error', 'message' => "Incorrect OTP. You have {$remaining} attempts remaining."]);
        }
        exit;
      }

      // Success: Activate email status and clear OTP details
      $stmtVerify = $db->prepare("
        UPDATE users SET
          email_verified = 1,
          otp_code = NULL,
          otp_expiry = NULL,
          otp_attempts = 0,
          otp_created_at = NULL
        WHERE id = ?
      ");
      $stmtVerify->execute([$user['id']]);

      logActivity("Email verified successfully via OTP", "email_verified", $user['id'], $user['role'], $user['name']);

      $loginRedirect = '../index.php#login-portal';
      if ($user['role'] === 'student') {
        $loginRedirect = '../student/login.php';
      } else if ($user['role'] === 'company') {
        $loginRedirect = '../company/login.php';
      }

      echo json_encode([
        'status' => 'success',
        'message' => 'Email verified successfully! Redirecting to login...',
        'redirect' => $loginRedirect
      ]);
      exit;
    }

    // Action 2: Resend OTP
    if ($action === 'resend') {
      if ($user['email_verified']) {
        echo json_encode(['status' => 'error', 'message' => 'Email is already verified.']);
        exit;
      }

      if ($user['resend_blocked']) {
        $seconds = $user['resend_countdown'] > 0 ? (int)$user['resend_countdown'] : 60;
        echo json_encode(['status' => 'error', 'message' => "Please wait {$seconds} seconds before requesting a new OTP."]);
        exit;
      }

      // Generate new cryptographically secure 6-digit OTP
      $otp = (string)random_int(100000, 999999);
      $otpHash = password_hash($otp, PASSWORD_BCRYPT);

      // Save in database
      $stmtUpdate = $db->prepare("
        UPDATE users SET
          otp_code = ?,
          otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
          otp_attempts = 0,
          otp_created_at = NOW()
        WHERE id = ?
      ");
      $stmtUpdate->execute([$otpHash, $user['id']]);

      // Dispatch verification email
      $emailSent = sendOtpEmail($user['email'], $user['name'], $otp);

      if ($emailSent) {
        echo json_encode(['status' => 'success', 'message' => 'A new 6-digit OTP code has been sent to your email.']);
      } else {
        echo json_encode(['status' => 'warning', 'message' => 'Database updated, but verification email failed to send. Please try again.']);
      }
      exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action requested.']);
    exit;

  } catch (Exception $e) {
    error_log("OTP API Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected server error occurred. Please try again later.']);
    exit;
  }
}

// GET request: Render the page
$emailParam = filter_input(INPUT_GET, 'email', FILTER_VALIDATE_EMAIL) ?: '';
$countdownSeconds = 0;
$resendBlocked = false;

if ($emailParam) {
  try {
    $db = getDB();
    $stmt = $db->prepare("
      SELECT email_verified,
             CASE WHEN otp_created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) THEN 1 ELSE 0 END as resend_blocked,
             TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(otp_created_at, INTERVAL 60 SECOND)) as resend_countdown
      FROM users WHERE BINARY email = ? LIMIT 1
    ");
    $stmt->execute([$emailParam]);
    $user = $stmt->fetch();
    if ($user) {
      if ($user['email_verified']) {
        // Redirect to login if email is already verified
        header("Location: ../index.php#login-portal");
        exit;
      }
      if ($user['resend_blocked']) {
        $resendBlocked = true;
        $countdownSeconds = $user['resend_countdown'] > 0 ? (int)$user['resend_countdown'] : 60;
      }
    }
  } catch (Exception $e) {
    // Ignore, let user interact
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Verification - CRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/design-system.css">
  <link rel="stylesheet" href="../css/auth.css">
  <style>
    .otp-input-container {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      margin: var(--space-2) 0;
    }
    .otp-digit {
      width: 48px;
      height: 52px;
      text-align: center;
      font-size: 24px;
      font-weight: 700;
      border-radius: var(--radius-md);
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--border-color);
      color: #FFF;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .otp-digit:focus {
      border-color: #3B82F6;
      background: rgba(59, 130, 246, 0.1);
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
      outline: none;
      transform: translateY(-2px);
    }
    .btn-resend {
      background: transparent;
      border: none;
      color: #3B82F6;
      font-weight: 600;
      cursor: pointer;
      padding: 0;
      font-size: 13px;
      transition: all 0.2s;
    }
    .btn-resend:hover:not(:disabled) {
      color: #60A5FA;
      text-decoration: underline;
    }
    .btn-resend:disabled {
      color: #64748b;
      cursor: not-allowed;
      text-decoration: none;
    }
    .email-display-card {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: rgba(255, 255, 255, 0.03);
      padding: 10px 14px;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      margin-bottom: var(--space-2);
    }
    .checkmark-animation {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: rgba(16, 185, 129, 0.1);
      border: 2px solid var(--color-success);
      display: none;
      align-items: center;
      justify-content: center;
      margin: 0 auto var(--space-3) auto;
      box-shadow: 0 0 15px rgba(16, 185, 129, 0.2);
    }
  </style>
</head>
<body class="auth-body">

  <div class="auth-wrapper">
    <div class="auth-card" id="main-otp-card">
      
      <!-- Logo -->
      <div class="auth-logo-section">
        <div class="auth-brand-name">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
          Campus Recruitment
        </div>
      </div>

      <div class="auth-header">
        <h2 class="auth-title">Email Verification</h2>
        <p class="auth-subtitle" id="otp-subheader">
          <?= $emailParam ? 'Please enter the 6-digit verification code sent to your email.' : 'Verify your corporate or university email address.' ?>
        </p>
      </div>

      <!-- Alert Banners -->
      <div class="auth-alert-banner" id="auth-error-banner">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="auth-error-msg">Validation error</span>
      </div>

      <div class="auth-alert-banner alert-info" id="auth-success-banner" style="background-color:var(--color-success-light); border-color:rgba(16,185,129,0.2); color:var(--color-success); display:none; gap:8px; align-items:center; padding: 10px var(--space-2); border-radius: var(--radius-md); font-size:13px; margin-bottom:var(--space-2);">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="auth-success-msg">Success</span>
      </div>

      <!-- Success Checkmark (hidden by default) -->
      <div class="checkmark-animation" id="success-checkmark">
        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="var(--color-success)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
      </div>

      <!-- Form -->
      <form id="otp-verify-form" novalidate>
        
        <!-- Email Display Group -->
        <div class="form-group" id="email-view-group" style="<?= $emailParam ? '' : 'display:none;' ?>">
          <label class="form-label">Verification Email Address</label>
          <div class="email-display-card">
            <span style="font-weight:600; color:#cbd5e1; word-break:break-all; font-size: 13.5px;" id="static-email"><?= htmlspecialchars($emailParam) ?></span>
            <button type="button" id="edit-email-btn" style="background:transparent; border:none; color:#60A5FA; font-weight:600; cursor:pointer; font-size: 12.5px;">Change</button>
          </div>
          <input type="hidden" id="hidden-email" value="<?= htmlspecialchars($emailParam) ?>">
        </div>

        <!-- Email Entry Input Group (Fallback if not provided in URL) -->
        <div class="form-group" id="email-input-group" style="<?= $emailParam ? 'display:none;' : '' ?>">
          <label class="form-label" for="otp-email">Registered Email Address</label>
          <div class="input-icon-wrapper">
            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" class="input-field" id="otp-email" placeholder="name@university.edu" value="<?= htmlspecialchars($emailParam) ?>">
          </div>
        </div>

        <!-- 6 Digit OTP Fields -->
        <div class="form-group" style="margin-top: var(--space-2); margin-bottom: var(--space-35);">
          <label class="form-label" style="text-align: center; display: block; font-weight: 600;">Verification Code</label>
          <div class="otp-input-container">
            <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
            <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
            <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
            <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
            <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
            <input type="text" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; font-weight: 700;" id="verify-submit-btn">
          Verify Verification Code
        </button>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top: var(--space-3); padding-top: var(--space-2); border-top: 1px solid rgba(255,255,255,0.06);">
          <a href="../index.php#login-portal" style="color:#94A3B8; text-decoration:none; font-size:13px; font-weight: 500;">Back to Login</a>
          <button type="button" id="resend-otp-btn" class="btn-resend" <?= $resendBlocked ? 'disabled' : '' ?>>
            <?= $resendBlocked ? "Resend OTP in {$countdownSeconds}s" : "Resend OTP" ?>
          </button>
        </div>

      </form>

    </div>
  </div>

  <script>
    const errorBanner = document.getElementById("auth-error-banner");
    const errorMsg = document.getElementById("auth-error-msg");
    const successBanner = document.getElementById("auth-success-banner");
    const successMsg = document.getElementById("auth-success-msg");

    function showError(msg) {
      errorMsg.innerText = msg;
      errorBanner.classList.add("active");
    }

    function showSuccess(msg) {
      successMsg.innerText = msg;
      successBanner.style.display = "flex";
    }

    function hideBanners() {
      errorBanner.classList.remove("active");
      successBanner.style.display = "none";
    }

    // Set up Edit Email button
    const editBtn = document.getElementById("edit-email-btn");
    const viewGroup = document.getElementById("email-view-group");
    const inputGroup = document.getElementById("email-input-group");
    const hiddenEmail = document.getElementById("hidden-email");
    const emailInput = document.getElementById("otp-email");

    editBtn.addEventListener("click", () => {
      viewGroup.style.display = "none";
      inputGroup.style.display = "block";
      hiddenEmail.value = "";
      emailInput.focus();
    });

    // Set up OTP Digits Navigation
    const digits = document.querySelectorAll(".otp-digit");
    digits.forEach((digit, idx) => {
      digit.addEventListener("input", (e) => {
        // Enforce digits only
        let val = e.target.value.replace(/\D/g, "");
        digit.value = val;

        if (val.length > 0) {
          if (idx < digits.length - 1) {
            digits[idx + 1].focus();
          }
        }
      });

      digit.addEventListener("keydown", (e) => {
        if (e.key === "Backspace" && digit.value.length === 0) {
          if (idx > 0) {
            digits[idx - 1].focus();
          }
        }
      });

      digit.addEventListener("paste", (e) => {
        e.preventDefault();
        const pasteData = (e.clipboardData || window.clipboardData).getData("text").trim();
        if (/^[0-9]{6}$/.test(pasteData)) {
          pasteData.split("").forEach((char, pIdx) => {
            digits[pIdx].value = char;
          });
          digits[5].focus();
        }
      });
    });

    // Resend Timer countdown handler
    let countdownSeconds = <?= $countdownSeconds ?>;
    const resendBtn = document.getElementById("resend-otp-btn");

    function startCountdown() {
      if (countdownSeconds <= 0) {
        resendBtn.disabled = false;
        resendBtn.innerText = "Resend OTP";
        return;
      }
      resendBtn.disabled = true;
      resendBtn.innerText = `Resend OTP in ${countdownSeconds}s`;
      const interval = setInterval(() => {
        countdownSeconds--;
        if (countdownSeconds <= 0) {
          clearInterval(interval);
          resendBtn.disabled = false;
          resendBtn.innerText = "Resend OTP";
        } else {
          resendBtn.innerText = `Resend OTP in ${countdownSeconds}s`;
        }
      }, 1000);
    }

    if (countdownSeconds > 0) {
      startCountdown();
    }

    // Handle OTP Resend trigger
    resendBtn.addEventListener("click", () => {
      const email = hiddenEmail.value || emailInput.value;

      if (!email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showError("Please enter a valid registered email address first.");
        return;
      }

      resendBtn.disabled = true;
      resendBtn.innerText = "Sending Code...";
      hideBanners();

      const formData = new FormData();
      formData.append("action", "resend");
      formData.append("email", email);

      fetch("verify_otp.php", {
        method: "POST",
        body: formData
      })
      .then(res => res.json())
      .then(res => {
        if (res.status === "success" || res.status === "warning") {
          showSuccess(res.message);
          countdownSeconds = 60;
          startCountdown();
          
          // Transition view group to display email if they had manually input it
          document.getElementById("static-email").innerText = email;
          hiddenEmail.value = email;
          viewGroup.style.display = "block";
          inputGroup.style.display = "none";
          document.getElementById("otp-subheader").innerText = "Please enter the 6-digit verification code sent to your email.";
          
          // Clear digits and focus first box
          digits.forEach(d => d.value = "");
          digits[0].focus();
        } else {
          showError(res.message);
          resendBtn.disabled = false;
          resendBtn.innerText = "Resend OTP";
        }
      })
      .catch(err => {
        showError("Resend service connection error. Try again.");
        resendBtn.disabled = false;
        resendBtn.innerText = "Resend OTP";
      });
    });

    // Handle Form Verification submit
    const form = document.getElementById("otp-verify-form");
    const submitBtn = document.getElementById("verify-submit-btn");

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      hideBanners();

      const email = hiddenEmail.value || emailInput.value;

      if (!email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showError("Please specify a valid registered email address.");
        return;
      }

      const otp = Array.from(digits).map(d => d.value).join("");

      if (otp.length !== 6 || !/^[0-9]{6}$/.test(otp)) {
        showError("Please fill in the 6-digit OTP code.");
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerText = "Verifying Code...";

      const formData = new FormData();
      formData.append("action", "verify");
      formData.append("email", email);
      formData.append("otp", otp);

      fetch("verify_otp.php", {
        method: "POST",
        body: formData
      })
      .then(res => res.json())
      .then(res => {
        if (res.status === "success") {
          showSuccess(res.message);
          submitBtn.innerText = "Redirecting...";
          
          // Show checkmark
          document.getElementById("success-checkmark").style.display = "flex";
          form.style.display = "none";

          setTimeout(() => {
            window.location.href = res.redirect;
          }, 2000);
        } else {
          showError(res.message);
          submitBtn.disabled = false;
          submitBtn.innerText = "Verify Verification Code";
        }
      })
      .catch(err => {
        showError("Verification service connection error.");
        submitBtn.disabled = false;
        submitBtn.innerText = "Verify Verification Code";
      });
    });

    // Auto-focus first digit
    window.addEventListener("DOMContentLoaded", () => {
      if (viewGroup.style.display !== "none") {
        digits[0].focus();
      } else {
        emailInput.focus();
      }
    });
  </script>
</body>
</html>
