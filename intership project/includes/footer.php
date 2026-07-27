<?php
/**
 * Sleek Modern Glassmorphic Copyright Footer Component
 */
?>
<footer class="app-dashboard-footer" role="contentinfo" aria-label="Application Footer">
  <div class="custom-premium-footer-bar">
    <div class="footer-copyright-text">
      &copy; <?php echo date('Y'); ?> <span class="brand-highlight">Campus Reqruitment</span>. All rights reserved.
    </div>
  </div>
</footer>

<style>
.app-dashboard-footer {
  margin-top: auto;
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
  justify-content: center;
  align-items: center;
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
</style>
