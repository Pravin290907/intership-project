<?php
/**
 * Sleek Modern Glassmorphic Copyright Footer Component
 */
?>
<footer class="app-dashboard-footer" role="contentinfo" aria-label="Application Footer">
  <div class="custom-premium-footer-bar">
    <div class="footer-copyright-text">
      &copy; <?php echo date('Y'); ?> <span class="brand-highlight">Campus Recruitment</span>. All rights reserved.
    </div>
    <div class="footer-links">
      <a href="<?php echo BASE_URL; ?>terms.php" class="footer-link">Terms &amp; Conditions</a>
    </div>
  </div>
</footer>

<style>
.app-dashboard-footer {
  margin-top: 40px;
  width: 100%;
  clear: both;
}

.custom-premium-footer-bar {
  background: rgba(15, 23, 42, 0.7);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  color: #94A3B8;
  padding: 20px 36px;
  display: flex;
  flex-direction: row;
  justify-content: center;
  align-items: center;
  gap: 24px;
  flex-wrap: wrap;
  font-family: 'Outfit', 'Inter', system-ui, -apple-system, sans-serif;
  font-size: 13px;
  width: 100%;
  box-sizing: border-box;
  letter-spacing: 0.5px;
}

.footer-copyright-text {
  font-weight: 500;
  text-align: center;
  opacity: 0.85;
  transition: opacity 0.3s ease;
}

.footer-copyright-text:hover {
  opacity: 1;
}

.brand-highlight {
  color: #38BDF8;
  font-weight: 600;
}

.footer-links {
  display: flex;
  gap: 24px;
}

.footer-link {
  color: #94A3B8;
  text-decoration: none;
  font-weight: 600;
  transition: color 0.3s ease;
}

.footer-link:hover {
  color: #38BDF8;
}
</style>
