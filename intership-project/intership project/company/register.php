<?php
/**
 * Company Recruiter Registration Form
 * Glassmorphic UI matching enterprise standards.
 */
require_once __DIR__ . '/../config/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Company Registration - CRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/design-system.min.css">
  <link rel="stylesheet" href="../css/auth.min.css">
</head>
<body class="auth-body">

  <div class="auth-wrapper wide">
    <div class="auth-card">
      
      <div class="auth-logo-section">
        <div class="auth-brand-name">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
          CampusRecruit
        </div>
      </div>

      <div class="auth-header">
        <h2 class="auth-title">Recruiter Enrollment</h2>
        <p class="auth-subtitle">Register your firm to recruit final-year students</p>
      </div>

      <!-- Alert banners -->
      <div class="auth-alert-banner" id="auth-error-banner">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="auth-error-msg">Validation error</span>
      </div>

      <div class="auth-alert-banner alert-info" id="auth-success-banner" style="background-color:var(--color-success-light); border-color:rgba(16,185,129,0.2); color:var(--color-success); display:none; gap:8px; align-items:center; padding: 10px var(--space-2); border-radius: var(--radius-md); font-size:13px; margin-bottom:var(--space-2);">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="auth-success-msg">Success</span>
      </div>

      <!-- Form -->
      <form id="company-reg-form" novalidate>
        <input type="hidden" name="register_type" value="company">
        
        <div class="grid-12">
          <!-- Recruiter Name -->
          <div class="col-6 col-md-12 form-group">
            <label class="form-label" for="reg-name">Primary Contact Name</label>
            <input type="text" class="input-field" id="reg-name" name="name" placeholder="HR Representative Name" required>
          </div>

          <!-- Email -->
          <div class="col-6 col-md-12 form-group">
            <label class="form-label" for="reg-email">Corporate Email</label>
            <input type="email" class="input-field" id="reg-email" name="email" placeholder="hr@company.com" required>
          </div>

          <!-- Password -->
          <div class="col-6 col-md-12 form-group">
            <label class="form-label" for="reg-password">Password (Min 6 chars)</label>
            <input type="password" class="input-field" id="reg-password" name="password" placeholder="••••••••" required>
          </div>

          <!-- Company Name -->
          <div class="col-6 col-md-12 form-group">
            <label class="form-label" for="reg-cname">Legal Company Name</label>
            <input type="text" class="input-field" id="reg-cname" name="company_name" placeholder="Google Labs Inc." required>
          </div>

          <!-- Industry -->
          <div class="col-6 col-md-12 form-group">
            <label class="form-label" for="reg-industry">Corporate Sector</label>
            <select class="input-field select-custom" id="reg-industry" name="industry" required>
              <option value="Technology">Technology & Software</option>
              <option value="Fintech">Financial Services / Payments</option>
              <option value="Consulting">Strategy & Consulting</option>
              <option value="E-Commerce">E-Commerce</option>
              <option value="Hardware">Semiconductors & Systems</option>
              <option value="Healthcare">Healthcare & Bio</option>
            </select>
          </div>

          <!-- Phone -->
          <div class="col-6 col-md-12 form-group">
            <label class="form-label" for="reg-phone">Office Phone Number</label>
            <div style="display: flex; align-items: center; gap: 8px;">
              <span style="font-weight: 600; padding: 10px 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-secondary); line-height: 1;">+91</span>
              <input type="tel" class="input-field" id="reg-phone" name="phone" placeholder="9876543210" inputmode="numeric" maxlength="10" required style="flex: 1;">
            </div>
          </div>

          <!-- Website -->
          <div class="col-12 form-group">
            <label class="form-label" for="reg-web">Corporate Website URL</label>
            <input type="url" class="input-field" id="reg-web" name="website" placeholder="https://www.company.com" required>
          </div>

          <div class="col-12" style="margin-top: var(--space-2);">
            <button type="submit" class="btn btn-primary" style="width:100%; font-weight:700;" id="reg-submit-btn">
              Submit Employer Profile for Approval
            </button>
          </div>
        </div>

        <div class="auth-footer-link">
          Already registered? <a href="login.php">Back to Login</a>
        </div>
      </form>

    </div>
  </div>

  <script>
    // Digits-only key filtering, input parsing, and pasting restriction on reg-phone
    const phoneInput = document.getElementById("reg-phone");
    if (phoneInput) {
      phoneInput.addEventListener("keydown", (e) => {
        if ([46, 8, 9, 27, 13].indexOf(e.keyCode) !== -1 ||
            (e.ctrlKey === true || e.metaKey === true) ||
            (e.keyCode >= 35 && e.keyCode <= 40)) {
                 return;
        }
        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
            e.preventDefault();
        }
      });
      phoneInput.addEventListener("input", () => {
        let val = phoneInput.value.replace(/\D/g, '');
        if (val.length > 10) val = val.substring(0, 10);
        phoneInput.value = val;
      });
      phoneInput.addEventListener("paste", (e) => {
        e.preventDefault();
        const clipboardData = e.clipboardData || window.clipboardData;
        const pastedData = clipboardData.getData('text');
        let val = pastedData.replace(/\D/g, '');
        if (val.length > 10) val = val.substring(0, 10);
        phoneInput.value = val;
      });
    }

    const form = document.getElementById("company-reg-form");
    const errorBanner = document.getElementById("auth-error-banner");
    const errorMsg = document.getElementById("auth-error-msg");
    const successBanner = document.getElementById("auth-success-banner");
    const successMsg = document.getElementById("auth-success-msg");
    const submitBtn = document.getElementById("reg-submit-btn");

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      errorBanner.classList.remove("active");
      successBanner.style.display = "none";

      const name = document.getElementById("reg-name").value;
      const email = document.getElementById("reg-email").value;
      const pw = document.getElementById("reg-password").value;
      const cname = document.getElementById("reg-cname").value;
      const web = document.getElementById("reg-web").value;
      const phone = document.getElementById("reg-phone").value;

      if (!name.trim() || !email.trim() || !pw.trim() || !cname.trim() || !web.trim() || !phone.trim()) {
        errorMsg.innerText = "Please complete all inputs.";
        errorBanner.classList.add("active");
        return;
      }

      if (!/^[0-9]{10}$/.test(phone)) {
        errorMsg.innerText = "Please enter a valid mobile number in the format +91 XXXXXXXXXX.";
        errorBanner.classList.add("active");
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerText = "Registering Employer...";

      const formData = new FormData(form);

      fetch('../auth/register.php', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
      .then(res => res.json())
      .then(res => {
        if (res.status === 'success') {
          form.style.display = "none";
          successMsg.innerText = res.message;
          successBanner.style.display = "flex";
        } else {
          errorMsg.innerText = res.message;
          errorBanner.classList.add("active");
          submitBtn.disabled = false;
          submitBtn.innerText = "Submit Employer Profile for Approval";
        }
      })
      .catch(err => {
        errorMsg.innerText = "Corporate registration service timeout.";
        errorBanner.classList.add("active");
        submitBtn.disabled = false;
        submitBtn.innerText = "Submit Employer Profile for Approval";
      });
    });
  </script>
</body>
</html>
