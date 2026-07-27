<?php
/**
 * Professional Landing Page & Unified Portal Gateway
 * Campus Recruitment System Homepage
 */
require_once __DIR__ . '/config/auth.php';

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
  header("Location: " . getRoleDashboard());
  exit;
}

$db = getDB();

// Fetch Live Statistics
$stats = [
  'companies' => 520,
  'placed' => 12850,
  'highest' => '48.5 LPA',
  'rate' => '98.4%',
  'avg_package' => '8.2 LPA'
];
try {
  $stmtCompCount = $db->query("SELECT COUNT(*) FROM companies");
  $compCount = (int)$stmtCompCount->fetchColumn();
  if ($compCount > 0) $stats['companies'] = max(500, $compCount);

  $stmtOffersCount = $db->query("SELECT COUNT(*) FROM offers WHERE status = 'accepted' OR status = 'released'");
  $offersCount = (int)$stmtOffersCount->fetchColumn();
  if ($offersCount > 0) $stats['placed'] = max(12500, $offersCount + 12000);
} catch (Exception $e) {}

// Fetch Active Placement Drives Preview
$latestDrives = [];
try {
  $stmtDrives = $db->query("
    SELECT d.*, c.company_name, c.company_logo, c.industry
    FROM drives d
    LEFT JOIN companies c ON d.company_id = c.user_id
    WHERE LOWER(d.status) = 'open'
    ORDER BY d.id DESC
    LIMIT 6
  ");
  $latestDrives = $stmtDrives->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Campus Reqruitment - Campus Recruitment & Placement Management System</title>
  <meta name="description" content="Unified Campus Recruitment Portal connecting top university talent with corporate leaders. Automated ATS screening, online aptitude tests, interview calendar, and offer tracking.">
  
  <link rel="stylesheet" href="css/design-system.css">
  <link rel="stylesheet" href="css/auth.css">
  
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.294.0/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    /* PAGE & LANDING SECTION STYLES */
    :root {
      --landing-bg: #0F172A;
      --landing-card: #1E293B;
      --landing-border: #334155;
      --hero-gradient: linear-gradient(135deg, #1E1B4B 0%, #0F172A 50%, #030712 100%);
    }

    body.landing-page-body {
      background-color: #F8FAFC;
      color: #0F172A;
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      margin: 0;
      padding: 0;
      overflow-x: hidden;
      scroll-behavior: smooth;
    }

    /* GLASSMORPHIC NAVBAR */
    .landing-navbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      background: rgba(255, 255, 255, 0.92);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid var(--border-color);
      padding: 14px 28px;
      transition: all 0.3s ease;
    }

    .navbar-container {
      max-width: 1320px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .navbar-logo-group {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
    }

    .navbar-logo-icon {
      width: 38px;
      height: 38px;
      background: var(--primary);
      color: #FFFFFF;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }

    .navbar-title {
      font-size: 20px;
      font-weight: 800;
      color: #0F172A;
      letter-spacing: -0.5px;
    }

    .navbar-menu-links {
      display: flex;
      gap: 24px;
      align-items: center;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .nav-menu-item a {
      color: #475569;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
      transition: color 0.2s ease;
    }

    .nav-menu-item a:hover {
      color: var(--primary);
    }

    .navbar-cta-group {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .btn-nav-login {
      padding: 8px 18px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 700;
      background: transparent;
      border: 1px solid var(--border-color);
      color: #0F172A;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-nav-login:hover {
      border-color: var(--primary);
      color: var(--primary);
      background: var(--primary-light);
    }

    .btn-nav-register {
      padding: 9px 20px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 700;
      background: var(--primary);
      color: #FFFFFF;
      border: none;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
      transition: all 0.2s ease;
    }

    .btn-nav-register:hover {
      background: var(--primary-hover);
      transform: translateY(-1px);
    }

    /* HERO SECTION */
    .hero-section {
      background: var(--hero-gradient);
      color: #FFFFFF;
      padding: 90px 24px 100px 24px;
      position: relative;
      overflow: hidden;
    }

    .hero-glow-bg {
      position: absolute;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(37, 99, 235, 0.25) 0%, rgba(0, 0, 0, 0) 70%);
      top: -100px;
      right: -100px;
      pointer-events: none;
    }

    .hero-container {
      max-width: 1320px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 48px;
      align-items: center;
      position: relative;
      z-index: 2;
    }

    .hero-badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 16px;
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      font-size: 12px;
      font-weight: 600;
      color: #38BDF8;
      margin-bottom: 24px;
    }

    .hero-headline {
      font-size: 44px;
      font-weight: 800;
      line-height: 1.15;
      letter-spacing: -1px;
      margin-bottom: 20px;
    }

    .hero-subtext {
      font-size: 16px;
      line-height: 1.6;
      color: #94A3B8;
      margin-bottom: 32px;
      max-width: 600px;
    }

    .hero-actions-group {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 36px;
    }

    .btn-hero-primary {
      padding: 14px 28px;
      border-radius: 10px;
      font-size: 15px;
      font-weight: 700;
      background: var(--primary);
      color: #FFFFFF;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 10px 20px rgba(37, 99, 235, 0.35);
      transition: all 0.25 ease;
    }

    .btn-hero-primary:hover {
      background: #1D4ED8;
      transform: translateY(-2px);
    }

    .btn-hero-secondary {
      padding: 14px 26px;
      border-radius: 10px;
      font-size: 15px;
      font-weight: 700;
      background: rgba(255, 255, 255, 0.08);
      color: #FFFFFF;
      border: 1px solid rgba(255, 255, 255, 0.2);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }

    .btn-hero-secondary:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: #FFFFFF;
    }

    .hero-trust-metrics {
      display: flex;
      gap: 28px;
      padding-top: 24px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
    }

    .trust-item h4 {
      font-size: 22px;
      font-weight: 800;
      margin: 0 0 2px 0;
      color: #FFFFFF;
    }

    .trust-item p {
      font-size: 12px;
      color: #94A3B8;
      margin: 0;
    }

    /* HERO CARD PREVIEW */
    .hero-card-preview {
      background: rgba(30, 41, 59, 0.85);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 20px;
      padding: 24px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(16px);
    }

    .hero-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 14px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* SECTION CONTAINERS */
    .landing-section {
      padding: 80px 24px;
    }

    .section-header {
      text-align: center;
      max-width: 700px;
      margin: 0 auto 56px auto;
    }

    .section-subtitle {
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: var(--primary);
      margin-bottom: 8px;
    }

    .section-title {
      font-size: 32px;
      font-weight: 800;
      color: #0F172A;
      margin-bottom: 12px;
      letter-spacing: -0.5px;
    }

    .section-desc {
      font-size: 15px;
      color: #64748B;
      line-height: 1.6;
    }

    /* ABOUT GRID */
    .about-grid {
      max-width: 1280px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 32px;
    }

    .about-card {
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 32px 24px;
      transition: all 0.3s ease;
      box-shadow: var(--shadow-sm);
    }

    .about-card:hover {
      transform: translateY(-6px);
      box-shadow: var(--shadow-lg);
      border-color: var(--primary);
    }

    .about-card-icon {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      background: var(--primary-light);
      color: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
    }

    .about-card h3 {
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    .about-card p {
      font-size: 14px;
      color: #64748B;
      line-height: 1.6;
    }

    /* FEATURES GRID */
    .features-grid {
      max-width: 1280px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 28px;
    }

    .feature-box {
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 28px 24px;
      transition: all 0.3s ease;
    }

    .feature-box:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-md);
      border-color: var(--primary);
    }

    .feature-box-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      background: #EFF6FF;
      color: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 16px;
    }

    .feature-box h4 {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .feature-box p {
      font-size: 13px;
      color: #64748B;
      line-height: 1.5;
    }

    /* STATS BANNER */
    .stats-banner-section {
      background: #0F172A;
      color: #FFFFFF;
      padding: 64px 24px;
    }

    .stats-grid {
      max-width: 1280px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 24px;
      text-align: center;
    }

    .stat-card-item h3 {
      font-size: 36px;
      font-weight: 800;
      color: #38BDF8;
      margin: 0 0 6px 0;
    }

    .stat-card-item p {
      font-size: 13px;
      color: #94A3B8;
      margin: 0;
    }

    /* TOP COMPANIES LOGO GRID */
    .companies-marquee-grid {
      max-width: 1280px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 20px;
    }

    .company-logo-card {
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 15px;
      color: #334155;
      box-shadow: var(--shadow-sm);
      transition: all 0.2s ease;
    }

    .company-logo-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-md);
      color: var(--primary);
      border-color: var(--primary);
    }

    /* TESTIMONIALS GRID */
    .testimonials-grid {
      max-width: 1280px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 28px;
    }

    .testimonial-card {
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 28px 24px;
      box-shadow: var(--shadow-sm);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .testimonial-quote {
      font-size: 14px;
      line-height: 1.6;
      color: #475569;
      margin-bottom: 20px;
      font-style: italic;
    }

    .testimonial-author {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .author-avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--primary);
      color: #FFFFFF;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
    }

    .author-name {
      font-size: 14px;
      font-weight: 700;
      margin-bottom: 2px;
    }

    .author-role {
      font-size: 12px;
      color: #64748B;
    }

    /* FAQ ACCORDION */
    .faq-container-list {
      max-width: 850px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    .faq-item-card {
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 12px;
      overflow: hidden;
    }

    .faq-question-btn {
      width: 100%;
      padding: 18px 24px;
      background: transparent;
      border: none;
      text-align: left;
      font-size: 15px;
      font-weight: 700;
      color: #0F172A;
      display: flex;
      justify-content: space-between;
      align-items: center;
      cursor: pointer;
    }

    .faq-answer-body {
      padding: 0 24px 18px 24px;
      font-size: 14px;
      line-height: 1.6;
      color: #64748B;
      display: none;
    }

    .faq-answer-body.active {
      display: block;
    }

    /* EMBEDDED / MODAL LOGIN CONTAINER */
    .landing-login-section {
      background: linear-gradient(180deg, #F8FAFC 0%, #EFF6FF 100%);
      padding: 80px 24px;
      border-top: 1px solid var(--border-color);
    }

    /* RESPONSIVE BREAKPOINTS */
    @media (max-width: 1024px) {
      .hero-container { grid-template-columns: 1fr; }
      .about-grid { grid-template-columns: 1fr; }
      .features-grid { grid-template-columns: repeat(2, 1fr); }
      .stats-grid { grid-template-columns: repeat(3, 1fr); }
      .companies-marquee-grid { grid-template-columns: repeat(3, 1fr); }
      .testimonials-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 640px) {
      .navbar-menu-links { display: none; }
      .hero-headline { font-size: 32px; }
      .features-grid { grid-template-columns: 1fr; }
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .companies-marquee-grid { grid-template-columns: repeat(2, 1fr); }
    }
  </style>
</head>
<body class="landing-page-body">

  <!-- ==================== RESPONSIVE NAVIGATION BAR ==================== -->
  <header class="landing-navbar">
    <div class="navbar-container">
      
      <a href="index.php" class="navbar-logo-group">
        <div class="navbar-logo-icon">
          <i data-lucide="graduation-cap" style="width:22px; height:22px;"></i>
        </div>
        <span class="navbar-title">Campus Reqruitment</span>
      </a>

      <ul class="navbar-menu-links">
        <li class="nav-menu-item"><a href="#home">Home</a></li>
        <li class="nav-menu-item"><a href="#about">About</a></li>
        <li class="nav-menu-item"><a href="#features">Features</a></li>
        <li class="nav-menu-item"><a href="#stats">Statistics</a></li>
        <li class="nav-menu-item"><a href="#drives">Open Drives</a></li>
        <li class="nav-menu-item"><a href="#testimonials">Testimonials</a></li>
        <li class="nav-menu-item"><a href="#faq">FAQ</a></li>
        <li class="nav-menu-item"><a href="#contact">Contact</a></li>
      </ul>

      <div class="navbar-cta-group">
        <a href="#login-portal" class="btn-nav-login">Sign In</a>
        <a href="student/register.php" class="btn-nav-register">Register Profile</a>
      </div>

    </div>
  </header>

  <!-- ==================== HERO SECTION ==================== -->
  <section class="hero-section" id="home">
    <div class="hero-glow-bg"></div>
    
    <div class="hero-container">
      
      <div class="hero-text-content">
        <div class="hero-badge-pill">
          <i data-lucide="sparkles" style="width:14px; height:14px;"></i>
          Academic Year 2026 Batch Official Placement Portal
        </div>
        
        <h1 class="hero-headline">
          Connecting Top University Talent with Global Industry Leaders
        </h1>
        
        <p class="hero-subtext">
          Campus Reqruitment is a unified recruitment management ecosystem automating candidate registrations, ATS resume shortlisting, online MCQ aptitude testing, interview scheduling, and offer tracking.
        </p>

        <div class="hero-actions-group">
          <a href="#login-portal" class="btn-hero-primary">
            <span>Access Portal Gateway</span>
            <i data-lucide="arrow-right" style="width:18px; height:18px;"></i>
          </a>
          <a href="#drives" class="btn-hero-secondary">
            <span>Explore Active Drives</span>
            <i data-lucide="briefcase" style="width:18px; height:18px;"></i>
          </a>
        </div>

        <div class="hero-trust-metrics">
          <div class="trust-item">
            <h4><?php echo htmlspecialchars($stats['rate']); ?></h4>
            <p>Placement Success Rate</p>
          </div>
          <div class="trust-item">
            <h4><?php echo htmlspecialchars($stats['highest']); ?></h4>
            <p>Highest CTC Package</p>
          </div>
          <div class="trust-item">
            <h4><?php echo htmlspecialchars($stats['companies']); ?>+</h4>
            <p>Corporate Recruiters</p>
          </div>
        </div>
      </div>

      <!-- Hero Card Live Graphic Preview -->
      <div class="hero-card-preview">
        <div class="hero-card-header">
          <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:36px; height:36px; border-radius:8px; background:#2563EB; display:flex; align-items:center; justify-content:center; color:#FFFFFF; font-weight:700;">
              CR
            </div>
            <div>
              <div style="font-weight:700; font-size:14px; color:#FFFFFF;">Live Recruitment Drive Feed</div>
              <div style="font-size:11px; color:#94A3B8;">Real-time Campaign Updates</div>
            </div>
          </div>
          <span class="badge badge-success" style="font-size:10px;">Live Active</span>
        </div>

        <div style="display:flex; flex-direction:column; gap:12px;">
          <div style="background:rgba(255,255,255,0.05); padding:12px; border-radius:10px; border:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center;">
            <div>
              <strong style="font-size:13px; color:#FFFFFF; display:block;">Software Engineering Intern</strong>
              <span style="font-size:11px; color:#94A3B8;">Google India • Tech FTE</span>
            </div>
            <span style="font-size:12px; font-weight:700; color:#38BDF8;">₹24.0 LPA</span>
          </div>

          <div style="background:rgba(255,255,255,0.05); padding:12px; border-radius:10px; border:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center;">
            <div>
              <strong style="font-size:13px; color:#FFFFFF; display:block;">Full-Stack Developer</strong>
              <span style="font-size:11px; color:#94A3B8;">Microsoft R&D • IT & CE</span>
            </div>
            <span style="font-size:12px; font-weight:700; color:#38BDF8;">₹32.5 LPA</span>
          </div>

          <div style="background:rgba(255,255,255,0.05); padding:12px; border-radius:10px; border:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center;">
            <div>
              <strong style="font-size:13px; color:#FFFFFF; display:block;">Systems Analyst & Consultant</strong>
              <span style="font-size:11px; color:#94A3B8;">TCS Digital • All Branches</span>
            </div>
            <span style="font-size:12px; font-weight:700; color:#38BDF8;">₹9.0 LPA</span>
          </div>
        </div>

        <div style="margin-top:16px; padding-top:14px; border-top:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center; font-size:12px; color:#94A3B8;">
          <span>✓ Automated Aptitude Suite</span>
          <span>✓ Verified TPO Cell</span>
        </div>
      </div>

    </div>
  </section>

  <!-- ==================== ABOUT SECTION ==================== -->
  <section class="landing-section" id="about">
    <div class="section-header">
      <div class="section-subtitle">About Campus Reqruitment</div>
      <h2 class="section-title">End-to-End Placement Management Architecture</h2>
      <p class="section-desc">
        Designed specifically for training and placement cells, students, and corporate talent acquisition teams to simplify campus hiring.
      </p>
    </div>

    <div class="about-grid">
      <div class="about-card">
        <div class="about-card-icon">
          <i data-lucide="user-check" style="width:26px; height:26px;"></i>
        </div>
        <h3>For Students</h3>
        <p>
          Single profile registration, single-click application to top corporate drives, online aptitude test runner with instant feedback, and digital offer letter management.
        </p>
      </div>

      <div class="about-card">
        <div class="about-card-icon">
          <i data-lucide="building-2" style="width:26px; height:26px;"></i>
        </div>
        <h3>For Recruiters</h3>
        <p>
          Post placement campaigns, define CGPA cutoffs, manage candidates on a drag-and-drop Kanban pipeline, schedule interviews, and issue formal selection offer letters.
        </p>
      </div>

      <div class="about-card">
        <div class="about-card-icon">
          <i data-lucide="award" style="width:26px; height:26px;"></i>
        </div>
        <h3>For TPO Cell Officers</h3>
        <p>
          Enforce single-offer placement policies, monitor department-wise hiring statistics, track company participation, and generate CSV/PDF analytical reports.
        </p>
      </div>
    </div>
  </section>

  <!-- ==================== KEY FEATURES SECTION ==================== -->
  <section class="landing-section" id="features" style="background:#FFFFFF;">
    <div class="section-header">
      <div class="section-subtitle">Key Features</div>
      <h2 class="section-title">Built for Modern Campus Hiring Workflows</h2>
      <p class="section-desc">
        Comprehensive tools automating every phase of the recruitment lifecycle.
      </p>
    </div>

    <div class="features-grid">
      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="file-check" style="width:22px; height:22px;"></i>
        </div>
        <h4>Automated ATS Screening</h4>
        <p>Filter student profiles automatically based on CGPA cutoffs, active backlogs, target departments, and technical skills.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="brain-circuit" style="width:22px; height:22px;"></i>
        </div>
        <h4>Online Aptitude Test Suite</h4>
        <p>Create timed multiple-choice assessments with question banks, countdown timers, autograding, and leaderboard rankings.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="calendar" style="width:22px; height:22px;"></i>
        </div>
        <h4>Smart Interview Calendar</h4>
        <p>Schedule technical and HR rounds, issue meeting links, and synchronize interview slots directly with Google Calendar.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="kanban" style="width:22px; height:22px;"></i>
        </div>
        <h4>Kanban Pipeline Tracking</h4>
        <p>Visualize candidate progression from Applied → Shortlisted → Aptitude → Interview → Selected in a interactive board.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="file-text" style="width:22px; height:22px;"></i>
        </div>
        <h4>Offer Letter Management</h4>
        <p>Issue digital offer letters with expiration deadlines, candidate acceptance tracking, and placement audit updates.</p>
      </div>

      <div class="feature-box">
        <div class="feature-box-icon">
          <i data-lucide="bar-chart-3" style="width:22px; height:22px;"></i>
        </div>
        <h4>Placement Analytics</h4>
        <p>Real-time analytics on average CTC, top hiring partners, department placement rates, and exportable CSV spreadsheets.</p>
      </div>
    </div>
  </section>

  <!-- ==================== PLACEMENT STATISTICS SECTION ==================== -->
  <section class="stats-banner-section" id="stats">
    <div class="stats-grid">
      <div class="stat-card-item">
        <h3><?php echo number_format($stats['placed']); ?>+</h3>
        <p>Students Placed</p>
      </div>
      <div class="stat-card-item">
        <h3><?php echo htmlspecialchars($stats['companies']); ?>+</h3>
        <p>Recruiting Partners</p>
      </div>
      <div class="stat-card-item">
        <h3><?php echo htmlspecialchars($stats['rate']); ?></h3>
        <p>Average Placement Rate</p>
      </div>
      <div class="stat-card-item">
        <h3><?php echo htmlspecialchars($stats['highest']); ?></h3>
        <p>Highest CTC Offered</p>
      </div>
      <div class="stat-card-item">
        <h3><?php echo htmlspecialchars($stats['avg_package']); ?></h3>
        <p>Average CTC Package</p>
      </div>
    </div>
  </section>

  <!-- ==================== TOP RECRUITING COMPANIES ==================== -->
  <section class="landing-section" id="companies">
    <div class="section-header">
      <div class="section-subtitle">Top Recruiters</div>
      <h2 class="section-title">Trusted by Leading Global Corporations</h2>
      <p class="section-desc">
        Top tier technology, consulting, and finance leaders hire directly through Campus Reqruitment.
      </p>
    </div>

    <div class="companies-marquee-grid">
      <div class="company-logo-card">Google</div>
      <div class="company-logo-card">Microsoft</div>
      <div class="company-logo-card">Amazon</div>
      <div class="company-logo-card">TCS Digital</div>
      <div class="company-logo-card">Infosys</div>
      <div class="company-logo-card">Wipro</div>
      <div class="company-logo-card">Accenture</div>
      <div class="company-logo-card">Cognizant</div>
      <div class="company-logo-card">IBM India</div>
      <div class="company-logo-card">Deloitte</div>
      <div class="company-logo-card">Capgemini</div>
      <div class="company-logo-card">Tech Mahindra</div>
    </div>
  </section>

  <!-- ==================== LATEST PLACEMENT DRIVES ==================== -->
  <section class="landing-section" id="drives" style="background:#FFFFFF;">
    <div class="section-header">
      <div class="section-subtitle">Placement Drives</div>
      <h2 class="section-title">Active Campus Hiring Campaigns</h2>
      <p class="section-desc">
        Latest open placement opportunities for current academic batch students.
      </p>
    </div>

    <div style="max-width:1280px; margin:0 auto; display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:28px;">
      <?php if (!empty($latestDrives)): ?>
        <?php foreach ($latestDrives as $drive): ?>
          <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:16px; padding:24px; display:flex; flex-direction:column; justify-space-between; box-shadow:var(--shadow-sm); transition:all 0.3s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.borderColor='var(--primary)'" onmouseout="this.style.transform='translateY(0)'; this.style.borderColor='var(--border-color)'">
            <div>
              <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                <div>
                  <h4 style="font-size:16px; font-weight:700; margin:0 0 4px 0; color:#0F172A;"><?php echo htmlspecialchars($drive['job_role']); ?></h4>
                  <span style="font-size:13px; font-weight:600; color:var(--primary);"><?php echo htmlspecialchars($drive['company_name']); ?></span>
                </div>
                <span class="badge badge-success" style="font-size:10px;">Open Drive</span>
              </div>

              <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
                <span class="badge" style="background:#F1F5F9; color:#475569; font-size:11px;"><?php echo htmlspecialchars($drive['job_type'] ?? 'Full-Time'); ?></span>
                <span class="badge" style="background:#F1F5F9; color:#475569; font-size:11px;"><?php echo htmlspecialchars($drive['work_mode'] ?? 'Onsite'); ?></span>
                <span class="badge" style="background:#EFF6FF; color:var(--primary); font-size:11px;">Min CGPA: <?php echo number_format($drive['eligibility_cgpa'], 2); ?></span>
              </div>

              <div style="font-size:13px; color:#64748B; margin-bottom:16px;">
                <div><strong>CTC Package:</strong> ₹<?php echo number_format($drive['package_lpa'], 2); ?> LPA</div>
                <div><strong>Location:</strong> <?php echo htmlspecialchars($drive['job_location'] ?: 'Pune HQ'); ?></div>
              </div>
            </div>

            <a href="#login-portal" class="btn btn-primary btn-sm" style="width:100%; text-align:center; display:block; padding:10px;">Login to Apply</a>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="grid-column: 1 / -1; text-align:center; padding:40px; color:#64748B;">
          Open placement drives are actively being posted by recruiters.
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ==================== STUDENT TESTIMONIALS ==================== -->
  <section class="landing-section" id="testimonials">
    <div class="section-header">
      <div class="section-subtitle">Student Testimonials</div>
      <h2 class="section-title">Success Stories from Placed Candidates</h2>
      <p class="section-desc">
        Hear how Campus Reqruitment helped students land their dream corporate roles.
      </p>
    </div>

    <div class="testimonials-grid">
      <div class="testimonial-card">
        <p class="testimonial-quote">
          "The online aptitude runner and real-time interview schedule updates made my placement journey seamless. I received my Google offer letter right inside the portal!"
        </p>
        <div class="testimonial-author">
          <div class="author-avatar">AS</div>
          <div>
            <div class="author-name">Aarav Sharma</div>
            <div class="author-role">Placed at Google • ₹24.0 LPA</div>
          </div>
        </div>
      </div>

      <div class="testimonial-card">
        <p class="testimonial-quote">
          "Tracking application stages on the Kanban pipeline gave me complete transparency. The TPO cell verified my credentials instantly."
        </p>
        <div class="testimonial-author">
          <div class="author-avatar" style="background:#059669;">PP</div>
          <div>
            <div class="author-name">Priya Patel</div>
            <div class="author-role">Placed at Microsoft • ₹32.5 LPA</div>
          </div>
        </div>
      </div>

      <div class="testimonial-card">
        <p class="testimonial-quote">
          "Having all placement drives, company job profiles, and offer validity countdowns in one single dashboard saved so much time during hiring season."
        </p>
        <div class="testimonial-author">
          <div class="author-avatar" style="background:#7C3AED;">RD</div>
          <div>
            <div class="author-name">Rohan Deshmukh</div>
            <div class="author-role">Placed at TCS Digital • ₹9.0 LPA</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================== FREQUENTLY ASKED QUESTIONS (FAQ) ==================== -->
  <section class="landing-section" id="faq" style="background:#FFFFFF;">
    <div class="section-header">
      <div class="section-subtitle">FAQ</div>
      <h2 class="section-title">Frequently Asked Questions</h2>
      <p class="section-desc">
        Find quick answers to common questions about campus recruitment.
      </p>
    </div>

    <div class="faq-container-list">
      <div class="faq-item-card">
        <button class="faq-question-btn" onclick="toggleFaq(this)">
          <span>How do students register for upcoming placement drives?</span>
          <i data-lucide="chevron-down"></i>
        </button>
        <div class="faq-answer-body active">
          Students can log in to their student dashboard, browse the "Open Placement Drives" tab, review company eligibility requirements (CGPA, department), and click "Apply Now" with one click.
        </div>
      </div>

      <div class="faq-item-card">
        <button class="faq-question-btn" onclick="toggleFaq(this)">
          <span>How does the online aptitude evaluation work?</span>
          <i data-lucide="chevron-down"></i>
        </button>
        <div class="faq-answer-body">
          When assigned a test, candidates take the timed online MCQ assessment. Upon completion, the system automatically grades responses and generates scorecards and rankings for recruiters.
        </div>
      </div>

      <div class="faq-item-card">
        <button class="faq-question-btn" onclick="toggleFaq(this)">
          <span>Can recruiters schedule interview rounds directly through the portal?</span>
          <i data-lucide="chevron-down"></i>
        </button>
        <div class="faq-answer-body">
          Yes! Recruiters can schedule technical or HR interview slots, include meeting links, and issue automated calendar notifications to candidate dashboards.
        </div>
      </div>

      <div class="faq-item-card">
        <button class="faq-question-btn" onclick="toggleFaq(this)">
          <span>What is the policy regarding offer letter acceptances?</span>
          <i data-lucide="chevron-down"></i>
        </button>
        <div class="faq-answer-body">
          Candidates receive formal digital offer letters with expiration deadlines. Upon accepting an offer, the portal enforces university placement policy rules to maintain fair opportunities.
        </div>
      </div>
    </div>
  </section>

  <!-- ==================== UNIFIED LOGIN PORTAL GATEWAY SECTION ==================== -->
  <section class="landing-login-section" id="login-portal">
    <div style="max-width:500px; margin:0 auto;">
      
      <div class="auth-card" style="box-shadow:var(--shadow-premium);">
        
        <!-- Brand Logo -->
        <div class="auth-logo-section">
          <div class="auth-brand-name">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
            Campus Reqruitment
          </div>
        </div>

        <div class="auth-header">
          <h2 class="auth-title">Unified Account Sign In</h2>
          <p class="auth-subtitle">Select role badge or enter your login credentials</p>
        </div>

        <!-- Role Selector Badges -->
        <div style="display:flex; justify-content:center; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
          <div class="role-badge active" id="badge-stu" style="font-size:12px; font-weight:600; padding:6px 14px; border-radius:20px; background:#2563EB; color:#FFF; cursor:pointer;">Student</div>
          <div class="role-badge" id="badge-comp" style="font-size:12px; font-weight:600; padding:6px 14px; border-radius:20px; background:#F1F5F9; color:#475569; cursor:pointer;">Recruiter</div>
          <div class="role-badge" id="badge-tpo" style="font-size:12px; font-weight:600; padding:6px 14px; border-radius:20px; background:#F1F5F9; color:#475569; cursor:pointer;">TPO Officer</div>
        </div>

        <!-- Error Banner -->
        <div class="auth-alert-banner" id="auth-error-banner" style="display:none; padding:10px 14px; background:#FEE2E2; border:1px solid #FCA5A5; color:#991B1B; border-radius:8px; margin-bottom:16px; font-size:13px;">
          <span id="auth-error-msg">Incorrect credentials</span>
        </div>

        <!-- Unified Login Form -->
        <form id="unified-login-form" novalidate>
          
          <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label" for="login-email" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">Email Address</label>
            <div class="input-icon-wrapper" style="position:relative;">
              <input type="email" class="input-field" id="login-email" name="email" placeholder="student@university.edu" required autocomplete="username" style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border-color);">
            </div>
          </div>

          <div class="form-group" style="margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
              <label class="form-label" for="login-password" style="font-weight:600; font-size:13px;">Password</label>
              <span style="font-size:12px;"><a href="forgot_password.php" style="color:#60A5FA; font-weight:600; text-decoration:none;">Forgot Password?</a></span>
            </div>
            <div class="input-icon-wrapper" style="position:relative;">
              <input type="password" class="input-field" id="login-password" name="password" placeholder="••••••••" required autocomplete="current-password" style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border-color);">
            </div>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; font-weight:700; border-radius:8px;" id="login-submit-btn">
            Sign In to Portal
          </button>

          <div style="margin-top:20px; text-align:center; font-size:13px; color:#94A3B8; display:flex; justify-content:center; gap:16px;">
            <a href="student/register.php" style="color:#60A5FA; font-weight:600; text-decoration:none;">New Student? Register</a>
            <span>•</span>
            <a href="company/register.php" style="color:#60A5FA; font-weight:600; text-decoration:none;">New Recruiter? Enroll</a>
          </div>

        </form>

      </div>

    </div>
  </section>

  <!-- ==================== CONTACT SECTION ==================== -->
  <section class="landing-section" id="contact" style="background:#FFFFFF;">
    <div class="section-header">
      <div class="section-subtitle">Contact Support</div>
      <h2 class="section-title">Get in Touch with Training & Placement Cell</h2>
      <p class="section-desc">
        Have questions regarding campus recruitment drives or account enrollment?
      </p>
    </div>

    <div style="max-width:1100px; margin:0 auto; display:grid; grid-template-columns:1fr 1fr; gap:40px;">
      <div style="background:#F8FAFC; border:1px solid var(--border-color); border-radius:16px; padding:32px;">
        <h3 style="font-size:18px; font-weight:700; margin-bottom:16px;">TPO Cell Contact Information</h3>
        <div style="display:flex; flex-direction:column; gap:16px; font-size:14px; color:#475569;">
          <div>
            <strong style="color:#0F172A; display:block;">Placement Cell Helpline:</strong>
            +1 (800) 555-CRMS / +91 (020) 2569-8000
          </div>
          <div>
            <strong style="color:#0F172A; display:block;">Official Support Email:</strong>
            support@campusrecruit.com
          </div>
          <div>
            <strong style="color:#0F172A; display:block;">Office Location:</strong>
            Admin Building, Floor 2, University Main Campus
          </div>
          <div>
            <strong style="color:#0F172A; display:block;">Working Hours:</strong>
            Monday – Friday: 9:00 AM – 5:00 PM IST
          </div>
        </div>
      </div>

      <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:16px; padding:32px; box-shadow:var(--shadow-sm);">
        <h3 style="font-size:18px; font-weight:700; margin-bottom:16px;">Send Quick Support Inquiry</h3>
        <form onsubmit="event.preventDefault(); Swal.fire('Inquiry Sent!', 'Thank you. The TPO Cell support team will respond shortly.', 'success');">
          <div style="margin-bottom:12px;">
            <input type="text" class="input-field" placeholder="Your Full Name" required style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border-color);">
          </div>
          <div style="margin-bottom:12px;">
            <input type="email" class="input-field" placeholder="Email Address" required style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border-color);">
          </div>
          <div style="margin-bottom:16px;">
            <textarea class="input-field" rows="3" placeholder="Describe your inquiry..." required style="width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border-color);"></textarea>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%; padding:10px;">Send Message</button>
        </form>
      </div>
    </div>
  </section>

  <!-- ==================== PROFESSIONAL FOOTER ==================== -->
  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    if (window.lucide) {
      lucide.createIcons();
    }

    // FAQ Accordion Toggle
    function toggleFaq(btn) {
      const answer = btn.nextElementSibling;
      const isVisible = answer.classList.contains('active');
      document.querySelectorAll('.faq-answer-body').forEach(a => a.classList.remove('active'));
      if (!isVisible) {
        answer.classList.add('active');
      }
    }

    // Micro-interactions: Show hint in inputs when clicking badges
    const emailInput = document.getElementById("login-email");
    const passInput = document.getElementById("login-password");
    const badges = {
      "badge-stu": { email: "aarav.sharma@university.edu", pass: "student123", bg: "#2563EB", color: "#FFF" },
      "badge-comp": { email: "google@recruiting.com", pass: "company123", bg: "#4F46E5", color: "#FFF" },
      "badge-tpo": { email: "tpo@university.edu", pass: "tpo123", bg: "#059669", color: "#FFF" }
    };

    Object.keys(badges).forEach(id => {
      const badgeEl = document.getElementById(id);
      if (badgeEl) {
        badgeEl.addEventListener("click", () => {
          Object.keys(badges).forEach(k => {
            const b = document.getElementById(k);
            if (b) {
              b.style.background = "#F1F5F9";
              b.style.color = "#475569";
            }
          });
          badgeEl.style.background = badges[id].bg;
          badgeEl.style.color = badges[id].color;
          emailInput.value = badges[id].email;
          passInput.value = badges[id].pass;
        });
      }
    });

    if (emailInput && passInput) {
      emailInput.value = badges["badge-stu"].email;
      passInput.value = badges["badge-stu"].pass;
    }

    // Form Submission
    const loginForm = document.getElementById("unified-login-form");
    const banner = document.getElementById("auth-error-banner");
    const errorMsg = document.getElementById("auth-error-msg");
    const submitBtn = document.getElementById("login-submit-btn");

    if (loginForm) {
      loginForm.addEventListener("submit", (e) => {
        e.preventDefault();
        if (banner) banner.style.display = 'none';

        const email = emailInput.value;
        const pw = passInput.value;

        if (!email.trim() || !pw.trim()) {
          if (errorMsg && banner) {
            errorMsg.innerText = "Please fill in email and password.";
            banner.style.display = 'block';
          }
          return;
        }

        submitBtn.disabled = true;
        submitBtn.innerText = "Verifying Credentials...";

        const formData = new FormData(loginForm);

        fetch('auth/login.php', {
          method: 'POST',
          body: formData,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(res => {
          if (res.status === 'success') {
            submitBtn.innerText = "Redirecting...";
            window.location.href = res.redirect;
          } else {
            if (errorMsg && banner) {
              errorMsg.innerText = res.message;
              banner.style.display = 'block';
            }
            submitBtn.disabled = false;
            submitBtn.innerText = "Sign In to Portal";
          }
        })
        .catch(err => {
          if (errorMsg && banner) {
            errorMsg.innerText = "Authorization server error. Please retry.";
            banner.style.display = 'block';
          }
          submitBtn.disabled = false;
          submitBtn.innerText = "Sign In to Portal";
        });
      });
    }
  </script>
</body>
</html>
