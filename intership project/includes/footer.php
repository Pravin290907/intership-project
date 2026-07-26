<?php
/**
 * Dark Sleek Application Footer Component
 * Matching exact layout and design from user specification image.
 */
?>
<footer class="app-dashboard-footer" role="contentinfo" aria-label="Application Footer">
  <div class="custom-dark-footer-bar">
    <div class="footer-left-content">
      <span>Copyright 2023-24</span>
      <span class="footer-pipe-divider">|</span>
      <span>All rights reserved</span>
      <span class="footer-pipe-divider">|</span>
      <a href="javascript:void(0)" onclick="openPrivacyPolicyModal()" class="dark-footer-link">Privacy Policy</a>
      <span class="footer-pipe-divider">|</span>
      <a href="javascript:void(0)" onclick="openTermsModal()" class="dark-footer-link">Terms of Service</a>
      <span class="footer-pipe-divider">|</span>
      <a href="javascript:void(0)" onclick="openRefundPolicyModal()" class="dark-footer-link">Refund Policy</a>
    </div>
    
    <div class="footer-right-socials">
      <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" class="dark-social-icon" title="Facebook" aria-label="Facebook">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
      </a>
      <a href="https://linkedin.com" target="_blank" rel="noopener noreferrer" class="dark-social-icon" title="LinkedIn" aria-label="LinkedIn">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
      </a>
      <a href="https://twitter.com" target="_blank" rel="noopener noreferrer" class="dark-social-icon" title="Twitter / X" aria-label="Twitter">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
      </a>
    </div>
  </div>
</footer>

<style>
/* CSS STYLING FOR DARK FOOTER MATCHING EXACT IMAGE LAYOUT */
.app-dashboard-footer {
  margin-top: auto;
  width: 100%;
  clear: both;
}

.custom-dark-footer-bar {
  background-color: #2D323E;
  color: #E2E8F0;
  padding: 16px 36px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  font-size: 13.5px;
  width: 100%;
  box-sizing: border-box;
}

.footer-left-content {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  color: #E2E8F0;
  font-weight: 500;
}

.footer-pipe-divider {
  color: #64748B;
  font-weight: 400;
  user-select: none;
  margin: 0 2px;
}

.dark-footer-link {
  color: #E2E8F0;
  text-decoration: none;
  transition: color 0.2s ease;
}

.dark-footer-link:hover {
  color: #38BDF8;
  text-decoration: underline;
}

.footer-right-socials {
  display: flex;
  align-items: center;
  gap: 18px;
}

.dark-social-icon {
  color: #E2E8F0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  text-decoration: none;
}

.dark-social-icon:hover {
  color: #38BDF8;
  transform: translateY(-2px);
}

@media (max-width: 768px) {
  .custom-dark-footer-bar {
    flex-direction: column;
    gap: 14px;
    text-align: center;
    padding: 16px 20px;
  }
  .footer-left-content {
    justify-content: center;
  }
}
</style>

<script>
/* INTERACTIVE MODAL DIALOGS FOR FOOTER POLICIES */
if (typeof openPrivacyPolicyModal !== 'function') {
  function openPrivacyPolicyModal() {
    if (window.Swal) {
      Swal.fire({
        title: 'Privacy Policy & Data Security',
        html: `
          <div style="text-align: left; font-size: 13px; line-height: 1.6; color: var(--text-secondary, #475569); max-height: 300px; overflow-y: auto;">
            <p style="margin-bottom: 10px;"><strong>Campus Recruitment System Privacy Policy</strong></p>
            <p style="margin-bottom: 8px;">1. <strong>Data Collection:</strong> We collect student academic profiles, resumes, and company drive specifications exclusively for campus recruitment operations.</p>
            <p style="margin-bottom: 8px;">2. <strong>Confidentiality:</strong> Student resumes and contact details are disclosed only to verified corporate recruiters participating in registered drives.</p>
            <p style="margin-bottom: 8px;">3. <strong>Security:</strong> All passwords and session auth tokens are encrypted using industry-standard hashing protocols.</p>
            <p style="margin-bottom: 0;">4. <strong>Rights:</strong> Users may update or purge their stored application preferences through account settings.</p>
          </div>
        `,
        icon: 'info',
        confirmButtonText: 'I Understand',
        confirmButtonColor: '#2563EB'
      });
    }
  }
}

if (typeof openTermsModal !== 'function') {
  function openTermsModal() {
    if (window.Swal) {
      Swal.fire({
        title: 'Terms of Service',
        html: `
          <div style="text-align: left; font-size: 13px; line-height: 1.6; color: var(--text-secondary, #475569); max-height: 300px; overflow-y: auto;">
            <p style="margin-bottom: 10px;"><strong>Portal Usage Guidelines & Agreement</strong></p>
            <p style="margin-bottom: 8px;">1. <strong>Eligibility:</strong> Students must provide authentic CGPA and academic transcript details.</p>
            <p style="margin-bottom: 8px;">2. <strong>Offer Policy:</strong> Upon accepting a formal offer letter through the portal, candidates agree to abide by the university placement cell's single-offer rule.</p>
            <p style="margin-bottom: 0;">3. <strong>Recruiter Conduct:</strong> Corporate recruiters must adhere to scheduled interview slots and prompt selection status updates.</p>
          </div>
        `,
        icon: 'info',
        confirmButtonText: 'Accept Terms',
        confirmButtonColor: '#2563EB'
      });
    }
  }
}

if (typeof openRefundPolicyModal !== 'function') {
  function openRefundPolicyModal() {
    if (window.Swal) {
      Swal.fire({
        title: 'Refund Policy',
        html: `
          <div style="text-align: left; font-size: 13px; line-height: 1.6; color: var(--text-secondary, #475569);">
            <p style="margin-bottom: 10px;"><strong>Campus Recruitment System Refund Terms</strong></p>
            <p style="margin-bottom: 8px;">1. <strong>Drive Registration Fees:</strong> Fees paid by recruiting partners for campus placement drive hosting are refundable up to 7 days prior to campaign commencement.</p>
            <p style="margin-bottom: 8px;">2. <strong>Student Services:</strong> All placement registration and assessment tools are provided completely free of charge for university students.</p>
            <p style="margin-bottom: 0;">3. <strong>Dispute Resolution:</strong> Contact <strong>billing@campusrecruit.com</strong> for assistance with billing adjustments.</p>
          </div>
        `,
        icon: 'info',
        confirmButtonText: 'Close Policy',
        confirmButtonColor: '#2563EB'
      });
    }
  }
}
</script>
