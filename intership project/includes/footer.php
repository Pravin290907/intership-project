<?php
/**
 * Application Footer Component
 * Modern, responsive footer matching design system typography, spacing, and colors.
 */
?>
<footer class="app-footer" role="contentinfo" aria-label="Site Footer">
  <div class="footer-inner">
    <div class="footer-left">
      <div class="footer-brand">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
          <path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/>
        </svg>
        <span>Campus Recruitment</span>
      </div>
      <nav class="footer-nav" aria-label="Footer Quick Links">
        <a href="javascript:void(0)" onclick="openAboutUsModal()" class="footer-link">About Us</a>
        <span class="footer-divider" aria-hidden="true">•</span>
        <a href="javascript:void(0)" onclick="openContactUsModal()" class="footer-link">Contact Us</a>
      </nav>
    </div>
    <div class="footer-right">
      <p class="footer-copyright">&copy; 2026 Campus Recruitment. All Rights Reserved.</p>
    </div>
  </div>
</footer>

<script>
  if (typeof openAboutUsModal !== 'function') {
    function openAboutUsModal() {
      if (window.Swal) {
        Swal.fire({
          title: 'About Campus Recruitment',
          html: `
            <div style="text-align: left; font-size: 14px; line-height: 1.6; color: var(--text-secondary, #475569);">
              <p style="margin-bottom: 12px;"><strong>Campus Recruitment</strong> is a modern, unified campus recruitment and placement management platform.</p>
              <p style="margin-bottom: 12px;">Engineered for universities, Training & Placement Officers (TPOs), students, and corporate recruiters to streamline drive registrations, resume screening, interview scheduling, and placement analytics.</p>
              <div style="background: var(--bg-app, #F8FAFC); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color, #E2E8F0); font-size: 12px; color: var(--text-muted, #94A3B8);">
                Academic Year 2026 Batch Portal • Enterprise Edition
              </div>
            </div>
          `,
          icon: 'info',
          confirmButtonText: 'Close',
          confirmButtonColor: '#2563EB'
        });
      } else {
        alert('Campus Recruitment - Unified Placement & Internship Management Portal (2026)');
      }
    }
  }

  if (typeof openContactUsModal !== 'function') {
    function openContactUsModal() {
      if (window.Swal) {
        Swal.fire({
          title: 'Contact Support & TPO Cell',
          html: `
            <div style="text-align: left; font-size: 14px; line-height: 1.6; color: var(--text-secondary, #475569);">
              <p style="margin-bottom: 10px;"><strong>Training & Placement Cell</strong></p>
              <p style="margin-bottom: 6px;">📧 <strong>Email:</strong> placement@university.edu</p>
              <p style="margin-bottom: 6px;">📞 <strong>Phone:</strong> +91 (020) 2569-8000</p>
              <p style="margin-bottom: 12px;">📍 <strong>Office:</strong> Admin Building, Floor 2, TPO Section</p>
              <div style="background: var(--bg-app, #F8FAFC); padding: 10px; border-radius: 8px; border: 1px solid var(--border-color, #E2E8F0); font-size: 12px; color: var(--text-muted, #94A3B8);">
                ⏰ <strong>Hours:</strong> Monday – Friday, 9:00 AM – 5:00 PM IST
              </div>
            </div>
          `,
          icon: 'question',
          confirmButtonText: 'Close',
          confirmButtonColor: '#2563EB'
        });
      } else {
        alert('Contact Support: placement@university.edu | Phone: +91 (020) 2569-8000');
      }
    }
  }
</script>
