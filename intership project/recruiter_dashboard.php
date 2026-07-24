<?php
/**
 * CRMS Premium Recruiter Dashboard View Container
 * High-fidelity redesign with Student Management, Offer Release, Interview CRUD, and professional Google/LinkedIn styling.
 */
require_once __DIR__ . '/config/auth.php';

if (!isset($_SESSION['user_id'])) {
  header('Location: ' . BASE_URL . 'company/login.php');
  exit;
}
if ($_SESSION['user_role'] !== 'company') {
  header('Location: ' . getRoleDashboard($_SESSION['user_role']));
  exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];
$userEmail = $_SESSION['user_email'];

// Fetch recruiter company details
$stmtComp = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
$stmtComp->execute([$userId]);
$companyProfile = $stmtComp->fetch() ?: [];

$companyName = $companyProfile['company_name'] ?? $_SESSION['user_name'] ?? 'Recruiter Company';
$logoPath = $companyProfile['company_logo'] ?? '';
$companyLogo = $logoPath ? BASE_URL . ltrim($logoPath, '/') : '';
$bannerPath = $companyProfile['banner_image'] ?? '';
$companyBanner = $bannerPath ? BASE_URL . ltrim($bannerPath, '/') : '';
$recruiterName = $companyProfile['recruiter_name'] ?? $userName;
$designation = $companyProfile['designation'] ?? 'Talent Acquisition Head';
$companySize = $companyProfile['company_size'] ?? '500-1000 Employees';
$industry = $companyProfile['industry'] ?? 'Information Technology';
$hrName = $companyProfile['hr_name'] ?? $userName;
$website = $companyProfile['website'] ?? '';
$phone = $companyProfile['phone'] ?? '';
$gst = $companyProfile['gst'] ?? '';
$pan = $companyProfile['pan'] ?? '';
$officeAddress = $companyProfile['office_address'] ?? '';
$description = $companyProfile['description'] ?? '';
$vision = $companyProfile['vision'] ?? '';
$mission = $companyProfile['mission'] ?? '';
$country = $companyProfile['country'] ?? 'India';
$state = $companyProfile['state'] ?? '';
$city = $companyProfile['city'] ?? '';
$pincode = $companyProfile['pincode'] ?? '';
$foundedYear = $companyProfile['founded_year'] ?? '';
$employeeCount = $companyProfile['employee_count'] ?? '';
$hiringPreferences = json_decode($companyProfile['hiring_preferences'] ?? '[]', true) ?: [];
$socialLinks = json_decode($companyProfile['social_links'] ?? '{}', true) ?: [];
$companyDocs = json_decode($companyProfile['company_docs'] ?? '[]', true) ?: [];
foreach ($companyDocs as &$doc) {
  if (isset($doc['path']) && strpos($doc['path'], 'http') !== 0) {
    $doc['path'] = BASE_URL . ltrim($doc['path'], '/');
  }
}
unset($doc);

// Fetch active placement drives count
$stmtActiveDrives = $db->prepare("SELECT COUNT(*) FROM drives WHERE company_id = ? AND status IN ('open', 'upcoming')");
$stmtActiveDrives->execute([$userId]);
$activeDrivesCount = (int)$stmtActiveDrives->fetchColumn();

// Open Jobs (drives with open status)
$stmtOpenJobs = $db->prepare("SELECT COUNT(*) FROM drives WHERE company_id = ? AND status = 'open'");
$stmtOpenJobs->execute([$userId]);
$openJobsCount = (int)$stmtOpenJobs->fetchColumn();

// Total Hiring (Selected applications for this company)
$stmtTotalHiring = $db->prepare("SELECT COUNT(*) FROM applications a JOIN drives d ON a.drive_id = d.id WHERE d.company_id = ? AND a.status = 'Selected'");
$stmtTotalHiring->execute([$userId]);
$totalHiringCount = (int)$stmtTotalHiring->fetchColumn();

// Last Login audit
$stmtLastLogin = $db->prepare("SELECT created_at FROM activity_logs WHERE user_id = ? AND action LIKE '%login%' ORDER BY id DESC LIMIT 1");
$stmtLastLogin->execute([$userId]);
$lastLoginTime = $stmtLastLogin->fetchColumn();
if (!$lastLoginTime) {
  $lastLoginTime = date('Y-m-d H:i:s');
}

// Fetch placement drives list for tables
$stmtDrives = $db->prepare("
  SELECT d.id, d.company_id, d.job_role as jobRole, d.eligibility_cgpa as eligibilityCGPA,
         d.package_lpa as packageLPA, d.drive_date as date, d.status, d.skills_required,
         d.registration_deadline, d.departments, c.company_name as companyName,
         (SELECT COUNT(*) FROM applications a WHERE a.drive_id = d.id) as registrationCount,
         (SELECT COUNT(*) FROM applications a WHERE a.drive_id = d.id AND a.status = 'Selected') as shortlistedCount,
         (SELECT COUNT(*) FROM interviews i JOIN applications a ON i.application_id = a.id WHERE a.drive_id = d.id) as interviewCount
  FROM drives d
  JOIN companies c ON d.company_id = c.user_id
  WHERE d.company_id = ?
  ORDER BY d.id DESC
");
$stmtDrives->execute([$userId]);
$recruiterDrives = $stmtDrives->fetchAll();

// Fetch applications for this recruiter
$stmtApp = $db->prepare("
  SELECT a.id, a.student_id as studentId, a.applied_date, a.status,
         u.name as studentName, s.department, s.cgpa, s.phone, s.skills, s.projects, s.resume_path, s.profile_pic,
         d.job_role as role, c.company_name as companyName, u.email as studentEmail
  FROM applications a
  JOIN users u ON a.student_id = u.id
  JOIN students s ON u.id = s.user_id
  JOIN drives d ON a.drive_id = d.id
  JOIN companies c ON d.company_id = c.user_id
  WHERE d.company_id = ?
  ORDER BY a.id DESC
");
$stmtApp->execute([$userId]);
$recruiterApps = $stmtApp->fetchAll();

// Fetch interviews for this recruiter
$stmtInt = $db->prepare("
  SELECT i.id, i.application_id, i.date, i.time, i.venue, i.interviewer, i.remarks, i.result, i.attendance,
         i.meeting_link, i.rating, i.feedback, i.interview_round, i.interview_type, i.instructions, i.notes,
         u.name as studentName, s.department, d.job_role as role, c.company_name as companyName
  FROM interviews i
  JOIN applications a ON i.application_id = a.id
  JOIN users u ON a.student_id = u.id
  JOIN students s ON u.id = s.user_id
  JOIN drives d ON a.drive_id = d.id
  JOIN companies c ON d.company_id = c.user_id
  WHERE d.company_id = ?
  ORDER BY i.date ASC, i.time ASC
");
$stmtInt->execute([$userId]);
$recruiterInterviews = $stmtInt->fetchAll();

// Fetch all system students
$stmtAllStudents = $db->query("
  SELECT u.id, u.name, u.email, s.roll_number, s.department, s.cgpa, s.phone, s.academic_year
  FROM users u
  JOIN students s ON u.id = s.user_id
  ORDER BY u.name ASC
");
$allStudents = $stmtAllStudents->fetchAll();

// Fetch department enrollments
$stmtDeptEnroll = $db->query("
  SELECT department, COUNT(*) as count
  FROM students
  GROUP BY department
");
$deptEnrollments = $stmtDeptEnroll->fetchAll();

// Fetch placement history
$stmtPlacementHistory = $db->query("
  SELECT a.id, u.name as studentName, s.department, s.cgpa, d.job_role as role, c.company_name as companyName, d.package_lpa as packageLPA, a.applied_date, COALESCE(o.status, 'Selected') as status
  FROM applications a
  JOIN users u ON a.student_id = u.id
  JOIN students s ON u.id = s.user_id
  JOIN drives d ON a.drive_id = d.id
  JOIN companies c ON d.company_id = c.user_id
  LEFT JOIN offers o ON a.id = o.application_id
  WHERE a.status = 'Selected'
  ORDER BY a.applied_date DESC
");
$placementHistory = $stmtPlacementHistory->fetchAll();

// Fetch company released offers
$stmtOffers = $db->prepare("
  SELECT o.id, o.application_id, o.salary_lpa, o.designation, o.joining_date, o.location, o.status, o.offer_letter_path,
         u.name as studentName, u.email as studentEmail, s.department, s.cgpa
  FROM offers o
  JOIN applications a ON o.application_id = a.id
  JOIN users u ON a.student_id = u.id
  JOIN students s ON u.id = s.user_id
  JOIN drives d ON a.drive_id = d.id
  WHERE d.company_id = ?
  ORDER BY o.id DESC
");
$stmtOffers->execute([$userId]);
$companyOffers = $stmtOffers->fetchAll();

// Fetch recruiter activity logs
$stmtLogs = $db->prepare("SELECT action, ip_address, browser, created_at FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 50");
$stmtLogs->execute([$userId]);
$recruiterLogs = $stmtLogs->fetchAll() ?: [];

// Helper to determine department codes
if (!function_exists('recruiterGetDeptCode')) {
  function recruiterGetDeptCode($dept) {
    if (!$dept) return 'GEN';
    if (strpos($dept, 'Computer') !== false || strpos($dept, 'CE') !== false) return 'CE';
    if (strpos($dept, 'Information') !== false || strpos($dept, 'IT') !== false) return 'IT';
    if (strpos($dept, 'Electronics') !== false || strpos($dept, 'ENTC') !== false) return 'ENTC';
    if (strpos($dept, 'Intelligence') !== false) return 'AI';
    if (strpos($dept, 'Mechanical') !== false) return 'ME';
    if (strpos($dept, 'Civil') !== false) return 'CE';
    if (strpos($dept, 'Electrical') !== false) return 'EE';
    return 'GEN';
  }
}

foreach ($recruiterApps as &$app) {
  $app['deptCode'] = recruiterGetDeptCode($app['department']);
}
unset($app);

foreach ($recruiterInterviews as &$int) {
  $int['deptCode'] = recruiterGetDeptCode($int['department']);
}
unset($int);

// Fetch recruiter activity logs
$stmtLogs = $db->prepare("
  SELECT id, action, ip_address, browser, status, created_at
  FROM activity_logs
  WHERE user_id = ?
  ORDER BY id DESC
  LIMIT 20
");
$stmtLogs->execute([$userId]);
// Fetch User Settings
$stmtSettings = $db->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$stmtSettings->execute([$userId]);
$userSettings = $stmtSettings->fetch();
if (!$userSettings) {
  $db->prepare("INSERT INTO user_settings (user_id, theme, language, notifications_enabled, timezone, date_format, email_preferences, privacy_settings, security_settings) VALUES (?, 'light', 'en', 1, 'UTC', 'Y-m-d', 'all', '', '')")->execute([$userId]);
  $stmtSettings->execute([$userId]);
  $userSettings = $stmtSettings->fetch();
}

// Fetch Recruiter Notifications
$stmtNotifications = $db->prepare("
  SELECT id, title, description, category, priority, created_at
  FROM notifications
  WHERE user_id = ? OR user_id = 1
  ORDER BY id DESC
  LIMIT 30
");
$stmtNotifications->execute([$userId]);
$recruiterNotifications = $stmtNotifications->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($userSettings['theme'] ?? 'light'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CRMS Recruiter Dashboard Workspace</title>
  
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/design-system.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/recruiter_style.css">
  
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.294.0/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <script>
    window.API_BASE_URL = "<?php echo BASE_URL; ?>api/";
    window.crmsTranslations = <?php echo json_encode(require __DIR__ . '/config/lang.php'); ?>;
    window.currentLanguage = "<?php echo $_SESSION['language'] ?? 'en'; ?>";
    window.campusRecruitmentData = {
      students: <?php echo json_encode($allStudents); ?>,
      drives: <?php echo json_encode($recruiterDrives); ?>,
      applications: <?php echo json_encode($recruiterApps); ?>,
      interviews: <?php echo json_encode($recruiterInterviews); ?>,
      offers: <?php echo json_encode($companyOffers); ?>,
      notifications: <?php echo json_encode($recruiterNotifications); ?>,
      role: 'company',
      userId: <?php echo $userId; ?>,
      userName: '<?php echo htmlspecialchars($userName); ?>',
      csrfToken: '<?php echo getCsrfToken(); ?>'
    };
  </script>
</head>
<body>

  <div class="app-layout">
    
    <!-- --- SIDEBAR --- -->
    <aside class="recruiter-sidebar" id="recruiter-sidebar-menu">
      <div class="sidebar-header">
        <div class="logo-container">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
        </div>
        <span class="logo-text">CampusRecruit</span>
      </div>

      <nav class="sidebar-navigation">
        <div class="sidebar-section">
          <div class="sidebar-section-title">Workspace</div>
          <div class="nav-item-link active" data-target="dashboard" data-tooltip="Dashboard">
            <i class="icon" data-lucide="layout-dashboard"></i>
            <span class="nav-item-label">Dashboard</span>
          </div>
          <div class="nav-item-link" data-target="student_management" data-tooltip="Student Management">
            <i class="icon" data-lucide="users"></i>
            <span class="nav-item-label">Student Management</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Recruitment</div>
          <div class="nav-item-link" data-target="drives" data-tooltip="Placement Drives">
            <i class="icon" data-lucide="briefcase"></i>
            <span class="nav-item-label">Placement Drives</span>
          </div>
          <div class="nav-item-link" data-target="applications" data-tooltip="Applications">
            <i class="icon" data-lucide="file-text"></i>
            <span class="nav-item-label">Applications</span>
          </div>
          <div class="nav-item-link" data-target="pipeline" data-tooltip="Pipeline (Kanban)">
            <i class="icon" data-lucide="git-pull-request"></i>
            <span class="nav-item-label">Pipeline</span>
          </div>
          <div class="nav-item-link" data-target="interviews" data-tooltip="Interviews">
            <i class="icon" data-lucide="calendar"></i>
            <span class="nav-item-label">Interviews</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Offboard / Selection</div>
          <div class="nav-item-link" data-target="offers" data-tooltip="Offer Letters">
            <i class="icon" data-lucide="award"></i>
            <span class="nav-item-label">Offer Letter</span>
          </div>
          <div class="nav-item-link" data-target="messages" data-tooltip="Messages">
            <i class="icon" data-lucide="message-square"></i>
            <span class="nav-item-label">Messages</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Analytics & Audit</div>
          <div class="nav-item-link" data-target="analytics" data-tooltip="Analytics">
            <i class="icon" data-lucide="bar-chart-2"></i>
            <span class="nav-item-label">Analytics</span>
          </div>
          <div class="nav-item-link" data-target="reports" data-tooltip="Reports">
            <i class="icon" data-lucide="clipboard"></i>
            <span class="nav-item-label">Reports</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Account</div>
          <div class="nav-item-link" data-target="notifications" data-tooltip="Notifications">
            <i class="icon" data-lucide="bell"></i>
            <span class="nav-item-label">Notifications</span>
            <span class="badge badge-danger sidebar-notif-badge" id="recruiter-sidebar-notif-badge" style="display: none; margin-left: auto; padding: 2px 6px; font-size: 10px; border-radius: 10px; min-width: 16px; text-align: center;">0</span>
          </div>
          <div class="nav-item-link" data-target="profile" data-tooltip="Company Profile">
            <i class="icon" data-lucide="building"></i>
            <span class="nav-item-label">Company Profile</span>
          </div>
          <div class="nav-item-link" data-target="settings" data-tooltip="Settings">
            <i class="icon" data-lucide="settings"></i>
            <span class="nav-item-label">Settings</span>
          </div>
        </div>
      </nav>

      <div class="sidebar-footer-profile">
        <div class="avatar-profile">
          <?php echo getInitials($userName); ?>
        </div>
        <div class="profile-details">
          <div style="font-weight:600; font-size:13px; color:var(--text-primary);"><?php echo htmlspecialchars($companyName); ?></div>
          <div style="font-size:11px; color:var(--text-secondary);"><?php echo htmlspecialchars($userName); ?></div>
        </div>
      </div>
    </aside>

    <!-- --- MAIN PANEL --- -->
    <main class="recruiter-main">
      
      <!-- --- TOP NAVBAR --- -->
      <header class="recruiter-navbar">
        <div style="display:flex; align-items:center; gap:16px;">
          <button class="navbar-btn-icon" id="recruiter-sidebar-toggle" style="border:none; outline:none;">
            <i data-lucide="menu" style="width:20px; height:20px;"></i>
          </button>
          <div class="nav-breadcrumbs">
            <span>Corporate Recruitment Portal</span>
            <span>/</span>
            <span class="breadcrumb-active" id="nav-crumb-title">Dashboard</span>
          </div>
        </div>

        <div class="navbar-right-actions">
          <form class="nav-search-bar" action="<?php echo BASE_URL; ?>search_results.php" method="GET">
            <i class="search-icon" data-lucide="search"></i>
            <input type="search" name="query" placeholder="Search profiles, drives, campaigns..." required>
          </form>

          <!-- Notification indicator -->
          <button class="navbar-btn-icon" onclick="window.switchRecruiterView('notifications')">
            <i data-lucide="bell" style="width:20px; height:20px;"></i>
            <span class="navbar-badge" id="recruiter-header-notif-badge" style="display: none;">0</span>
          </button>

          <!-- Chat messages button -->
          <button class="navbar-btn-icon" onclick="window.switchRecruiterView('messages')">
            <i data-lucide="mail" style="width:20px; height:20px;"></i>
          </button>

          <!-- Profile Dropdown Trigger -->
          <div class="recruiter-profile-trigger" id="recruiter-avatar-trigger">
            <div class="avatar-profile">
              <?php echo getInitials($userName); ?>
            </div>
            <i data-lucide="chevron-down" style="width:14px; height:14px;"></i>
            
            <div class="dropdown-profile-menu" id="recruiter-avatar-dropdown">
              <div style="padding:12px; border-bottom:1px solid var(--border-color);">
                <div style="font-weight:600; font-size:13px;"><?php echo htmlspecialchars($userName); ?></div>
                <div style="font-size:11px; color:var(--text-secondary);"><?php echo htmlspecialchars($companyName); ?></div>
              </div>
              <div class="dropdown-profile-item" onclick="window.switchRecruiterView('profile')">
                <i data-lucide="user" style="width:14px; height:14px;"></i>
                Company Profile
              </div>
              <div class="dropdown-profile-item" onclick="window.switchRecruiterView('settings')">
                <i data-lucide="settings" style="width:14px; height:14px;"></i>
                Workspace Settings
              </div>
              <div style="border-top:1px solid var(--border-color); margin-top:4px;"></div>
              <a href="auth/logout.php" class="dropdown-profile-item danger" style="color:var(--color-danger); text-decoration:none;">
                <i data-lucide="log-out" style="width:14px; height:14px;"></i>
                Logout Account
              </a>
            </div>
          </div>
        </div>
      </header>

      <!-- --- VIEWPORTS CANVAS --- -->
      <div class="view-container">
        
        <!-- ==================== DASHBOARD VIEW ==================== -->
        <div class="page-view-section active" id="dashboard">
          
          <!-- Top Welcome Banner -->
          <!-- Top Welcome Banner Redesigned -->
          <div class="recruiter-welcome-banner" style="background: linear-gradient(135deg, #1E40AF, #3B82F6); position:relative; overflow:hidden; border-radius:16px; padding:28px 32px; color:white; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px; box-shadow:0 10px 25px -5px rgba(37,99,235,0.25);">
            
            <!-- Soft graphic background overlay -->
            <div style="position:absolute; right:-50px; bottom:-50px; width:260px; height:260px; background:radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%); pointer-events:none;"></div>
            
            <div style="display:flex; align-items:center; gap:20px; z-index:2;">
              <!-- Recruiter Profile Badge -->
              <div class="avatar-profile" style="width:68px; height:68px; font-size:22px; border:3px solid rgba(255,255,255,0.3); background-color:rgba(255,255,255,0.15); backdrop-filter:blur(4px); box-shadow:0 4px 10px rgba(0,0,0,0.1); color:white;">
                <?php echo getInitials($userName); ?>
              </div>
              
              <div>
                <h1 class="banner-title" id="recruiter-welcome-msg" style="font-size:26px; font-weight:800; letter-spacing:-0.5px; margin-bottom:4px; text-shadow:0 2px 4px rgba(0,0,0,0.1);">Good Morning, Recruiter 👋</h1>
                <p class="banner-subtitle" style="font-size:13px; opacity:0.9; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                  <span class="badge badge-success" style="background-color:rgba(255,255,255,0.2); color:#FFFFFF; border:1px solid rgba(255,255,255,0.3); font-size:11px; padding:2px 8px;">2026 Batch Season</span>
                  <span>&bull; Today: <?php echo date('l, F d, Y'); ?></span>
                  <span id="live-banner-time" style="margin-left: 6px; font-weight: 600; background-color: rgba(255, 255, 255, 0.15); padding: 2px 8px; border-radius: 6px; font-size: 11px;">Loading Time...</span>
                </p>
              </div>
            </div>

            <!-- Quick statistics and Logo inside header -->
            <div style="display:flex; align-items:center; gap:24px; z-index:2; flex-wrap:wrap;">
              
              <!-- Quick Stats glass badges -->
              <div style="display:flex; gap:12px;">
                <div style="background-color:rgba(255,255,255,0.12); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,0.2); padding:8px 14px; border-radius:12px; text-align:center; min-width:80px; box-shadow:0 4px 6px rgba(0,0,0,0.02);">
                  <div style="font-size:11px; opacity:0.85; font-weight:500; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Active Drives</div>
                  <div style="font-size:18px; font-weight:800; color:#FFFFFF;"><?php echo $activeDrivesCount; ?></div>
                </div>
                <div style="background-color:rgba(255,255,255,0.12); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,0.2); padding:8px 14px; border-radius:12px; text-align:center; min-width:80px; box-shadow:0 4px 6px rgba(0,0,0,0.02);">
                  <div style="font-size:11px; opacity:0.85; font-weight:500; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">Hired</div>
                  <div style="font-size:18px; font-weight:800; color:#22C55E;"><?php echo $totalHiringCount; ?></div>
                </div>
              </div>

              <!-- Company Branding Logo Emblem -->
              <div class="banner-company-logo" style="width:68px; height:68px; background-color:#FFFFFF; border-radius:16px; display:flex; align-items:center; justify-content:center; padding:8px; box-shadow:0 8px 20px rgba(0,0,0,0.08); border:1px solid rgba(226,232,240,0.8);">
                <?php if ($companyLogo): ?>
                  <img src="<?php echo $companyLogo; ?>" alt="Branding Logo" style="max-width:100%; max-height:100%; object-fit:contain;">
                <?php else: ?>
                  <i data-lucide="building-2" style="width:32px; height:32px; color:#2563EB;"></i>
                <?php endif; ?>
              </div>

            </div>
          </div>

          <!-- Two Column Grid: Recruiter Card (Left) and Clickable KPIs (Right) -->
          <div class="grid-container" style="margin-bottom:24px;">
            
            <!-- Sleek Recruiter Profile Card (Google/LinkedIn style) -->
            <div class="dashboard-card col-4 col-lg-12" style="padding:24px; align-self: start; box-shadow:0 4px 20px rgba(0,0,0,0.04); border-radius:16px; background:#FFFFFF; border:1px solid #E2E8F0;">
              <?php
              $profileFields = [
                $companyName, $industry, $recruiterName, $designation, $companySize,
                $website, $phone, $gst, $pan, $officeAddress, $description,
                $vision, $mission, $country, $state, $city, $pincode, $foundedYear, $employeeCount
              ];
              $filledFields = 0;
              foreach ($profileFields as $fieldVal) {
                if (!empty($fieldVal)) $filledFields++;
              }
              $profileCompletion = round(($filledFields / count($profileFields)) * 100);
              ?>
              <div>
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                  <div style="display:flex; gap:14px; align-items:center;">
                    <div class="avatar-profile" style="width:56px; height:56px; font-size:20px; font-weight:700; color:white; background:linear-gradient(135deg, #2563EB, #4F46E5); box-shadow:0 4px 10px rgba(37,99,235,0.2);">
                      <?php echo getInitials($userName); ?>
                    </div>
                    <div>
                      <h3 style="font-size:16px; font-weight:800; display:inline-flex; align-items:center; gap:6px; color:#0F172A; margin-bottom:2px;">
                        <?php echo htmlspecialchars($companyName); ?>
                        <span style="display:inline-flex; background-color:#2563EB; color:white; border-radius:50%; width:16px; height:16px; align-items:center; justify-content:center; font-size:8px; box-shadow:0 2px 4px rgba(37,99,235,0.3);" title="Verified Enterprise Recruiter">✔</span>
                      </h3>
                      <p style="color:#64748B; font-size:12px; font-weight:500;"><?php echo htmlspecialchars($industry); ?></p>
                    </div>
                  </div>
                </div>

                <!-- Profile Completion Indicator -->
                <div style="margin-bottom:20px; background:#F8FAFC; border-radius:12px; padding:12px; border:1px solid #E2E8F0;">
                  <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:700; color:#0F172A; margin-bottom:6px;">
                    <span>Profile Completion</span>
                    <span style="color:#2563EB;"><?php echo $profileCompletion; ?>%</span>
                  </div>
                  <div style="height:6px; background-color:#E2E8F0; border-radius:10px; overflow:hidden;">
                    <div style="height:100%; width:<?php echo $profileCompletion; ?>%; background:linear-gradient(90deg, #2563EB, #4F46E5); border-radius:10px; transition:width 0.4s ease;"></div>
                  </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:12px; font-size:12px; border-top:1px solid #E2E8F0; padding-top:16px; margin-bottom:20px;">
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Recruiter Head</span>
                    <strong style="color:#0F172A;"><?php echo htmlspecialchars($recruiterName); ?></strong>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Designation</span>
                    <strong style="color:#0F172A;"><?php echo htmlspecialchars($designation); ?></strong>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Company Size</span>
                    <strong style="color:#0F172A;"><?php echo htmlspecialchars($companySize); ?></strong>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Active Drive Campaigns</span>
                    <strong style="color:#2563EB; background:rgba(37,99,235,0.08); padding:2px 8px; border-radius:6px; font-size:11px;"><?php echo $activeDrivesCount; ?> drives</strong>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Open Positions</span>
                    <strong style="color:#22C55E; background:rgba(34,197,94,0.08); padding:2px 8px; border-radius:6px; font-size:11px;"><?php echo $openJobsCount; ?> roles</strong>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Total Hiring count</span>
                    <strong style="color:#0F172A;"><?php echo $totalHiringCount; ?> students</strong>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Audit Log Timestamp</span>
                    <strong style="font-size:11px; color:#0F172A;"><?php echo $lastLoginTime; ?></strong>
                  </div>
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="color:#64748B; font-weight:500;">Live Workspace Time (IST)</span>
                    <strong id="live-profile-time" style="font-size:11px; color:#0F172A;">Loading...</strong>
                  </div>
                </div>
              </div>
              
              <button class="btn btn-secondary" onclick="window.switchRecruiterView('profile')" style="width:100%; font-size:13px; font-weight:600; padding:10px; background:#F1F5F9; border:1px solid #E2E8F0; color:#0F172A; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                <i data-lucide="edit-3" style="width:14px; height:14px;"></i>
                Edit Corporate Profile
              </button>
            </div>

            <!-- Clickable Analytics KPI Cards Grid (15 Cards Redesigned) -->
            <div class="col-8 col-lg-12" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px;">
              
              <!-- 1. Total Applications -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer; border-top: 4px solid #2563EB; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Total Applications</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(37,99,235,0.08); color:#2563EB; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="file-text" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-applications" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; View</span><span>candidate apps</span>
                </div>
              </div>

              <!-- 2. Active Jobs -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('drives')" style="cursor:pointer; border-top: 4px solid #4F46E5; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Active Jobs</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(79,70,229,0.08); color:#4F46E5; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="briefcase" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-active-drives" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Manage</span><span>drives panel</span>
                </div>
              </div>

              <!-- 3. Students Hired -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('pipeline')" style="cursor:pointer; border-top: 4px solid #16A34A; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Students Hired</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(22,163,74,0.08); color:#16A34A; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="smile" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-hired" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Selections</span><span>Kanban</span>
                </div>
              </div>

              <!-- 4. Shortlisted -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer; border-top: 4px solid #0891B2; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Shortlisted</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(8,145,178,0.08); color:#0891B2; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="user-check" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-shortlisted" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Screen</span><span>candidates</span>
                </div>
              </div>

              <!-- 5. Interviews Scheduled -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('interviews')" style="cursor:pointer; border-top: 4px solid #F59E0B; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Pending Interviews</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(245,158,11,0.08); color:#F59E0B; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="calendar" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-interviews" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; View</span><span>calendar</span>
                </div>
              </div>

              <!-- 6. Offers Released -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('offers')" style="cursor:pointer; border-top: 4px solid #7C3AED; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Offers Released</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(124,58,237,0.08); color:#7C3AED; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="award" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-offers" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Dispatch</span><span>letters</span>
                </div>
              </div>

              <!-- 7. Hiring Rate -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer; border-top: 4px solid #059669; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Hiring Rate</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(5,150,105,0.08); color:#059669; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="percent" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-hiring-rate" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0%</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Ratio</span><span>apps to hires</span>
                </div>
              </div>

              <!-- 8. Student Database -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('student_management')" style="cursor:pointer; border-top: 4px solid #0D9488; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Student Database</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(13,148,136,0.08); color:#0D9488; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="users" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-total-students" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Browse</span><span>directory</span>
                </div>
              </div>

              <!-- 9. Rejected Applications -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer; border-top: 4px solid #DC2626; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Rejected Applications</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(220,38,38,0.08); color:#DC2626; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="user-x" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-rejected-apps" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-down" style="color:#DC2626; font-weight:600;">&darr; Review</span><span>rejections</span>
                </div>
              </div>

              <!-- 10. Pending Applications -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer; border-top: 4px solid #0EA5E9; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">New Applications</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(14,165,233,0.08); color:#0EA5E9; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="clock" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-pending-apps" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Awaiting</span><span>evaluation</span>
                </div>
              </div>

              <!-- 11. Upcoming Drives -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('drives')" style="cursor:pointer; border-top: 4px solid #EA580C; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Upcoming Drives</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(234,88,12,0.08); color:#EA580C; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="send" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-upcoming-drives" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Next</span><span>campaigns</span>
                </div>
              </div>

              <!-- 12. Average Package -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer; border-top: 4px solid #8B5CF6; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Average Package</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(139,92,246,0.08); color:#8B5CF6; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="dollar-sign" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-avg-pkg" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">₹0 LPA</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Mean</span><span>CTC offered</span>
                </div>
              </div>

              <!-- 13. Highest Package -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer; border-top: 4px solid #16A34A; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Highest Package</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(22,163,74,0.08); color:#16A34A; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="trending-up" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-highest-pkg" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">₹0 LPA</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Peak</span><span>offer compensation</span>
                </div>
              </div>

              <!-- 14. Lowest Package -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer; border-top: 4px solid #475569; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Lowest Package</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(71,85,105,0.08); color:#475569; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="shield-alert" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-lowest-pkg" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">₹0 LPA</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Floor</span><span>base package</span>
                </div>
              </div>

              <!-- 15. Selection Ratio -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer; border-top: 4px solid #2563EB; border-radius:16px; padding:20px; background:#FFFFFF; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); transition:all 0.3s ease;">
                <div class="kpi-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                  <span style="font-size:14px; font-weight:600; color:#64748B;">Selection Ratio</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(37,99,235,0.08); color:#2563EB; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; transition:transform 0.3s ease;">
                    <i data-lucide="pie-chart" style="width:18px; height:18px;"></i>
                  </div>
                </div>
                <div class="kpi-body" style="margin-bottom:8px;">
                  <span class="kpi-value-text" id="kpi-selection-ratio" style="font-size:32px; font-weight:800; color:#0F172A; line-height:1.2;">0%</span>
                </div>
                <div class="kpi-footer" style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:4px;">
                  <span class="trend-up" style="color:#16A34A; font-weight:600;">&uarr; Hired /</span><span>Shortlisted</span>
                </div>
              </div>

            </div>

          </div>

          <!-- Redesigned Quick Actions Section -->
          <div style="margin-bottom:28px;">
            <h3 style="font-size:15px; font-weight:700; color:#0F172A; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
              <i data-lucide="zap" style="width:16px; height:16px; color:#F59E0B;"></i>
              Quick Recruitment Workspace Actions
            </h3>
            
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
              
              <!-- 1. Create Placement Drive -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="openRecruiterModal('modal-create-drive')" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(37,99,235,0.08); color:#2563EB; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="plus-circle" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Create Drive</h4>
                  <p style="font-size:11px; color:#64748B;">Launch new campaign</p>
                </div>
              </div>

              <!-- 2. Review Applications -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(79,70,229,0.08); color:#4F46E5; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="file-text" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Applications</h4>
                  <p style="font-size:11px; color:#64748B;">Screen candidates</p>
                </div>
              </div>

              <!-- 3. Schedule Interview -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="openScheduleInterviewModalDirectly()" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(245,158,11,0.08); color:#F59E0B; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="calendar-plus" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Schedule Round</h4>
                  <p style="font-size:11px; color:#64748B;">Book assessment session</p>
                </div>
              </div>

              <!-- 4. Release Offer Letter -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="openOfferModalDirectly()" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(34,197,94,0.08); color:#22C55E; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="file-plus" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Release Offer</h4>
                  <p style="font-size:11px; color:#64748B;">Dispatch selection letter</p>
                </div>
              </div>

              <!-- 5. Campaign Analytics -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(6,182,212,0.08); color:#06B6D4; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="bar-chart-3" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Analytics</h4>
                  <p style="font-size:11px; color:#64748B;">Hiring funnel insight</p>
                </div>
              </div>

              <!-- 6. Generate Reports -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="window.switchRecruiterView('reports')" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(239,68,68,0.08); color:#EF4444; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="clipboard" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Reports</h4>
                  <p style="font-size:11px; color:#64748B;">Export drive metrics</p>
                </div>
              </div>

              <!-- 7. Candidate Messaging -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="window.switchRecruiterView('messages')" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(124,58,237,0.08); color:#7C3AED; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="mail-open" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Messages</h4>
                  <p style="font-size:11px; color:#64748B;">Chat with applicants</p>
                </div>
              </div>

              <!-- 8. Portal Settings -->
              <div class="dashboard-card quick-action-card-premium lift" onclick="window.switchRecruiterView('settings')" style="cursor:pointer; padding:18px; border-radius:14px; background:#FFFFFF; border:1px solid #E2E8F0; display:flex; align-items:center; gap:14px; transition:all 0.2s ease;">
                <div style="width:42px; height:42px; border-radius:10px; background-color:rgba(100,116,139,0.08); color:#64748B; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <i data-lucide="sliders" style="width:20px; height:20px;"></i>
                </div>
                <div>
                  <h4 style="font-size:13px; font-weight:700; color:#0F172A; margin-bottom:2px;">Settings</h4>
                  <p style="font-size:11px; color:#64748B;">Workspace preferences</p>
                </div>
              </div>

            </div>
          </div>



          <!-- Graphs Area -->
          <div class="grid-container" style="margin-bottom:24px;">
            <div class="dashboard-card col-8 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:16px;">Placement Performance Selection Curve</h3>
              <div style="height:280px; position:relative;">
                <canvas id="chart-placement-trend"></canvas>
              </div>
            </div>
            <div class="dashboard-card col-4 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:16px;">Applicants branch selection ratios</h3>
              <div style="height:280px; position:relative;">
                <canvas id="chart-students-dept"></canvas>
              </div>
            </div>
          </div>

          <!-- Activity Row -->
          <div class="grid-container" style="margin-bottom:24px;">
            <div class="dashboard-card col-6 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:12px;">Hiring Stage Funnel Conversion</h3>
              <div id="hiring-funnel-container" style="display:flex; flex-direction:column; justify-content:center; height:240px;">
              </div>
            </div>
            <div class="dashboard-card col-6 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:12px;">Hiring Monthly Distribution</h3>
              <div style="height:240px; position:relative;">
                <canvas id="chart-applications-month"></canvas>
              </div>
            </div>
          </div>

        </div>

        <!-- ==================== STUDENT MANAGEMENT VIEW ==================== -->
        <div class="page-view-section" id="student_management">
          
          <!-- View Tabs Header -->
          <div class="dashboard-card" style="margin-bottom:20px; padding:12px 16px;">
            <div class="sub-tab-nav-bar" role="tablist" aria-label="Student Module Navigation" style="display:flex; gap:10px; overflow-x:auto;">
              <button class="sub-tab-btn student-tab active" id="tab-student-list" data-tab="student-list" onclick="switchStudentTab('student-list')" role="tab" aria-selected="true" tabindex="0">
                Student List Directory
              </button>
              <button class="sub-tab-btn student-tab" id="tab-add-student" data-tab="add-student" onclick="switchStudentTab('add-student')" role="tab" aria-selected="false" tabindex="-1">
                Add Student Profile
              </button>
              <button class="sub-tab-btn student-tab" id="tab-department-enrollments" data-tab="department-enrollments" onclick="switchStudentTab('department-enrollments')" role="tab" aria-selected="false" tabindex="-1">
                Department Enrollments
              </button>
              <button class="sub-tab-btn student-tab" id="tab-placement-history" data-tab="placement-history" onclick="switchStudentTab('placement-history')" role="tab" aria-selected="false" tabindex="-1">
                Placement Selections History
              </button>
            </div>
          </div>

          <!-- Sub-Tab 1: Student List -->
          <div class="sub-tab-panel active" id="student-list">
            <div class="dashboard-card" style="margin-bottom:20px; padding:16px; display:flex; gap:12px;">
              <div class="nav-search-bar" style="width:300px;">
                <i class="search-icon" data-lucide="search"></i>
                <input type="text" id="student-search-input" oninput="filterStudentManagementList()" placeholder="Search name or roll number...">
              </div>
              <select class="input-field-custom" id="student-branch-filter" onchange="filterStudentManagementList()" style="width:240px; height:40px;">
                <option value="All">All Branches</option>
                <option value="Information Technology (IT)">Information Technology (IT)</option>
                <option value="Computer Engineering (CE)">Computer Engineering (CE)</option>
                <option value="Artificial Intelligence & Data Science (AIDS)">Artificial Intelligence & Data Science (AIDS)</option>
                <option value="Artificial Intelligence & Machine Learning (AIML)">Artificial Intelligence & Machine Learning (AIML)</option>
                <option value="Electronics & Telecommunication (ENTC)">Electronics & Telecommunication (ENTC)</option>
                <option value="Mechanical Engineering">Mechanical Engineering</option>
                <option value="Civil Engineering">Civil Engineering</option>
                <option value="Electrical Engineering">Electrical Engineering</option>
              </select>
            </div>
            <div class="dashboard-card" style="padding:0; overflow-x:auto;">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Roll Number</th>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>Department Branch</th>
                    <th>GPA Score</th>
                    <th>Academic Session</th>
                    <th>Phone number</th>
                    <th width="120">Actions</th>
                  </tr>
                </thead>
                <tbody id="student-directory-tbody">
                  <?php foreach ($allStudents as $stu): ?>
                    <tr id="recruiter-student-row-<?php echo $stu['id']; ?>">
                      <td><strong><?php echo htmlspecialchars($stu['roll_number']); ?></strong></td>
                      <td><?php echo htmlspecialchars($stu['name']); ?></td>
                      <td><code><?php echo htmlspecialchars($stu['email']); ?></code></td>
                      <td><span class="badge badge-primary"><?php echo htmlspecialchars($stu['department']); ?></span></td>
                      <td><strong><?php echo number_format($stu['cgpa'], 2); ?></strong></td>
                      <td><?php echo htmlspecialchars($stu['academic_year'] ?? 'Final Year'); ?></td>
                      <td><?php echo htmlspecialchars($stu['phone'] ?? 'N/A'); ?></td>
                      <td>
                        <div style="display:inline-flex; gap:4px;">
                          <button class="btn btn-ghost btn-sm btn-icon-only" onclick="viewStudentDetailsDirectly(<?php echo $stu['id']; ?>)" title="View Details">
                            <i data-lucide="eye" style="width:14px; height:14px;"></i>
                          </button>
                          <button class="btn btn-ghost btn-sm btn-icon-only" onclick="openEditStudentModalDirectly(<?php echo $stu['id']; ?>)" title="Modify Details">
                            <i data-lucide="edit" style="width:14px; height:14px;"></i>
                          </button>
                          <button class="btn btn-ghost btn-sm btn-icon-only" onclick="deleteStudentDirectly(<?php echo $stu['id']; ?>)" style="color:var(--color-danger);" title="Remove Profile">
                            <i data-lucide="trash-2" style="width:14px; height:14px;"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Sub-Tab 2: Add Student Form -->
          <div class="sub-tab-panel" id="add-student">
            <div class="dashboard-card" style="max-width:700px; margin:0 auto;">
              <h3 style="font-size:16px; font-weight:700; margin-bottom:20px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Register Student Profile</h3>
              <form id="form-recruiter-add-student" onsubmit="submitAddStudentForm(event)" autocomplete="off">
                <!-- Dummy hidden inputs to confuse autocompletion algorithms -->
                <input type="text" name="prevent_autofill" style="display:none;" />
                <input type="password" name="prevent_autofill_pwd" style="display:none;" />
                
                <div class="grid-container">
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Student Name *</label>
                    <input type="text" class="input-field-custom" name="name" placeholder="Example: Rahul Sharma" required autocomplete="off">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Email Address *</label>
                    <input type="email" class="input-field-custom" name="email" placeholder="Example: rahul@gmail.com" required autocomplete="off">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Setup Password *</label>
                    <input type="password" class="input-field-custom" name="password" placeholder="Min 6 characters" required autocomplete="new-password">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Roll Number *</label>
                    <input type="text" class="input-field-custom" name="roll_number" placeholder="Example: 2026CS102" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Department Branch *</label>
                    <select class="input-field-custom" name="department" required>
                      <option value="">Select Branch</option>
                      <option value="Information Technology (IT)">Information Technology (IT)</option>
                      <option value="Computer Engineering (CE)">Computer Engineering (CE)</option>
                      <option value="Artificial Intelligence & Data Science (AIDS)">Artificial Intelligence & Data Science (AIDS)</option>
                      <option value="Artificial Intelligence & Machine Learning (AIML)">Artificial Intelligence & Machine Learning (AIML)</option>
                      <option value="Electronics & Telecommunication (ENTC)">Electronics & Telecommunication (ENTC)</option>
                      <option value="Mechanical Engineering">Mechanical Engineering</option>
                      <option value="Civil Engineering">Civil Engineering</option>
                      <option value="Electrical Engineering">Electrical Engineering</option>
                    </select>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Cumulative CGPA (1.00 - 10.00) *</label>
                    <input type="number" class="input-field-custom" name="cgpa" placeholder="Example: 8.75" step="0.01" min="1.00" max="10.00" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Mobile Phone (10 Digits) *</label>
                    <input type="text" class="input-field-custom" name="phone" placeholder="Example: 9876543210" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Academic Year *</label>
                    <input type="text" class="input-field-custom" name="academic_year" placeholder="Example: 2024" pattern="\d{4}" maxlength="4" minlength="4" required>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;">
                  <i data-lucide="check-circle" style="width:14px; height:14px; vertical-align:middle; margin-right:6px;"></i>
                  Save Student Profile
                </button>
              </form>
            </div>
          </div>

          <!-- Sub-Tab 3: Department Enrollments -->
          <div class="sub-tab-panel" id="department-enrollments">
            <div class="grid-container">
              <div class="dashboard-card col-6 col-lg-12">
                <h3 style="font-size:14px; font-weight:700; margin-bottom:16px;">Department Branch Enrollments Distribution</h3>
                <div style="display:flex; flex-direction:column; gap:16px;">
                  <?php foreach ($deptEnrollments as $de): ?>
                    <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                      <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; margin-bottom:6px; align-items:center;">
                        <span><?php echo htmlspecialchars($de['department']); ?></span>
                        <div style="display:flex; align-items:center; gap:12px;">
                          <span><?php echo $de['count']; ?> Enrolled</span>
                          <button class="btn btn-ghost btn-sm" onclick="viewBranchStudentsDirectly('<?php echo addslashes($de['department']); ?>')" style="padding: 2px 8px; font-size: 11px;">View Enrolled</button>
                        </div>
                      </div>
                      <div style="height:10px; background-color:#E2E8F0; border-radius:10px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo min(100, $de['count'] * 10); ?>%; background:linear-gradient(90deg, var(--primary), var(--secondary));"></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Sub-Tab 4: Placement History -->
          <div class="sub-tab-panel" id="placement-history">
            <!-- Search, filter and pagination header -->
            <div class="dashboard-card" style="margin-bottom:20px; padding:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
              <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <div class="nav-search-bar" style="width:260px;">
                  <i class="search-icon" data-lucide="search"></i>
                  <input type="text" id="placement-history-search" oninput="filterPlacementHistoryTable()" placeholder="Search student, role, company...">
                </div>
                <select class="input-field-custom" id="placement-history-filter-status" onchange="filterPlacementHistoryTable()" style="width:160px; height:40px;">
                  <option value="All">All Statuses</option>
                  <option value="Selected">Selected</option>
                  <option value="Released">Released</option>
                  <option value="Accepted">Accepted</option>
                  <option value="Declined">Declined</option>
                </select>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span id="placement-history-pagination-info" style="font-size:12px; color:var(--text-secondary); font-weight:600;">Showing 1-10</span>
                <div style="display:flex; gap:4px;">
                  <button type="button" class="btn btn-secondary btn-sm" onclick="changePlacementHistoryPage(-1)" id="btn-placement-prev" style="padding:4px 12px; font-weight:700;">&lt;</button>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="changePlacementHistoryPage(1)" id="btn-placement-next" style="padding:4px 12px; font-weight:700;">&gt;</button>
                </div>
              </div>
            </div>

            <div class="dashboard-card" style="padding:0; overflow-x:auto;">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Student Name</th>
                    <th>Company</th>
                    <th>Job Role</th>
                    <th>Package</th>
                    <th>Placement Date</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="placement-history-tbody">
                  <?php foreach ($placementHistory as $ph): ?>
                    <tr class="placement-history-row" data-name="<?php echo htmlspecialchars(strtolower($ph['studentName'])); ?>" data-company="<?php echo htmlspecialchars(strtolower($ph['companyName'])); ?>" data-role="<?php echo htmlspecialchars(strtolower($ph['role'])); ?>" data-status="<?php echo htmlspecialchars($ph['status']); ?>">
                      <td><strong><?php echo htmlspecialchars($ph['studentName']); ?></strong></td>
                      <td><?php echo htmlspecialchars($ph['companyName']); ?></td>
                      <td><strong><?php echo htmlspecialchars($ph['role']); ?></strong></td>
                      <td><span class="badge badge-success">₹<?php echo $ph['packageLPA']; ?> LPA</span></td>
                      <td><?php echo $ph['applied_date']; ?></td>
                      <td>
                        <?php
                        $statusClass = 'badge-primary';
                        if ($ph['status'] === 'Accepted') $statusClass = 'badge-success';
                        else if ($ph['status'] === 'Released') $statusClass = 'badge-info';
                        else if ($ph['status'] === 'Declined') $statusClass = 'badge-danger';
                        ?>
                        <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($ph['status']); ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ==================== PLACEMENT DRIVES VIEW ==================== -->
        <div class="page-view-section" id="drives">
          <div class="dashboard-card" style="margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
              <div>
                <h2 style="font-size:18px; font-weight:700; margin-bottom:4px;">Recruitment Drive Campaigns</h2>
                <p style="font-size:13px; color:var(--text-secondary);">Manage campus placement title eligibility criteria, package rates, and timelines.</p>
              </div>
              <button class="btn btn-primary" onclick="openRecruiterModal('modal-create-drive')">
                <i data-lucide="plus-circle" style="width:16px; height:16px; vertical-align:middle; margin-right:4px;"></i>
                Create Placement Drive
              </button>
            </div>
          </div>

          <div class="dashboard-card" style="margin-bottom:20px; padding:16px;">
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
              <div class="nav-search-bar" style="width:260px;">
                <i class="search-icon" data-lucide="search"></i>
                <input type="text" id="drive-search-input" placeholder="Search role title...">
              </div>
              <select class="input-field-custom" id="drive-filter-status" style="width:160px; height:40px;">
                <option value="All">All Drive Statuses</option>
                <option value="open">Open</option>
                <option value="upcoming">Upcoming</option>
                <option value="completed">Completed</option>
                <option value="closed">Closed</option>
              </select>
            </div>
          </div>

          <!-- Drives Table -->
          <div class="dashboard-card" style="padding:0; overflow-x:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th width="40">
                    <label class="checkbox-label" style="padding:0;">
                      <input type="checkbox" class="checkbox-custom" id="drive-select-all">
                      <div class="checkbox-box"></div>
                    </label>
                  </th>
                  <th>Company</th>
                  <th>Job Title</th>
                  <th>Branches</th>
                  <th>Min GPA</th>
                  <th>CTC Package</th>
                  <th>Deadline</th>
                  <th>Status</th>
                  <th width="100">Actions</th>
                </tr>
              </thead>
              <tbody id="recruiter-drives-tbody">
                <!-- Loaded dynamically -->
              </tbody>
            </table>
          </div>

          <!-- Bulk Actions Toolbar -->
          <div id="drives-bulk-toolbar" class="dashboard-card" style="position:fixed; bottom:30px; left:50%; transform:translateX(-50%); z-index:300; display:none; align-items:center; gap:24px; border:2px solid var(--primary); padding:16px 32px; box-shadow:var(--shadow-lg);">
            <span id="drives-selected-count" style="font-weight:700;">0 selected</span>
            <div style="display:flex; gap:8px;">
              <button class="btn btn-secondary btn-sm" onclick="executeDrivesBulkAction('close')">Close Drives</button>
              <button class="btn btn-secondary btn-sm" onclick="executeDrivesBulkAction('archive')">Archive Drives</button>
              <button class="btn btn-danger btn-sm" onclick="executeDrivesBulkAction('delete')">Delete Selected</button>
            </div>
          </div>
        </div>

        <!-- ==================== APPLICATIONS VIEW ==================== -->
        <div class="page-view-section" id="applications">
          <div class="ats-container">
            <!-- Left panel candidates list -->
            <div class="ats-left-panel">
              <div class="ats-panel-search">
                <input type="search" id="ats-search-input" placeholder="Search candidates...">
              </div>
              <div class="ats-candidate-list" id="ats-candidates-list">
                <!-- Loaded by recruiter_app.js -->
              </div>
            </div>

            <!-- Center Panel candidate detail cards -->
            <div class="ats-center-panel" id="ats-candidate-details">
              <div class="empty-illustration-container" style="height:100%;">
                <i data-lucide="user" style="width:48px; height:48px; color:var(--text-muted); margin-bottom:12px;"></i>
                <div class="empty-heading">Select a Candidate</div>
                <div class="empty-subtext">Click on any candidate card in the left list to review detailed academics, CGPA scores, and resume files.</div>
              </div>
            </div>

            <!-- Right Panel resume iframe -->
            <div class="ats-right-panel">
              <div class="pdf-viewer-header" id="ats-resume-header-filename">
                <span>Resume Preview</span>
              </div>
              <iframe class="pdf-viewer-iframe" id="ats-resume-iframe" src=""></iframe>
            </div>
          </div>
        </div>

        <!-- ==================== PIPELINE KANBAN VIEW ==================== -->
        <div class="page-view-section" id="pipeline">
          <div class="kanban-board-scroll">
            <div class="kanban-board-container" id="kanban-board-wrapper">
              <!-- Column stages injected dynamically -->
            </div>
          </div>
        </div>

        <!-- ==================== INTERVIEWS VIEW ==================== -->
        <div class="page-view-section" id="interviews">
          
          <!-- Tabbed Header for Interviews -->
          <div class="dashboard-card" style="margin-bottom:20px; padding:8px 16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">
              <div style="display:flex; gap:12px; padding-bottom:8px;">
                <button class="sub-tab-btn active" id="btn-tab-interview-calendar" onclick="switchInterviewTab('interview-calendar')">
                  Interview Calendar
                </button>
                <button class="sub-tab-btn" id="btn-tab-interview-directory" onclick="switchInterviewTab('interview-directory')">
                  Directory / Logs
                </button>
              </div>
              
              <button class="btn btn-primary" onclick="openScheduleInterviewModalDirectly()">
                <i data-lucide="calendar-plus" style="width:16px; height:16px; vertical-align:middle; margin-right:4px;"></i>
                Schedule Interview
              </button>
            </div>
          </div>

          <!-- Tab Panel 1: Interview Calendar -->
          <div class="sub-interview-panel active" id="tab-interview-calendar-panel">
            <div class="grid-container">
              <!-- Calendar grid -->
              <div class="dashboard-card col-8 col-lg-12">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                  <h3 style="font-size:14px; font-weight:700;" id="calendar-month-year-label">July 2026</h3>
                  <div style="display:flex; gap:6px;">
                    <button class="btn btn-secondary btn-sm" onclick="navigateCalendar(-1)">&lt;</button>
                    <button class="btn btn-secondary btn-sm" onclick="navigateCalendar(1)">&gt;</button>
                  </div>
                </div>

                <div class="calendar-view-grid" style="margin-bottom:8px;">
                  <div class="calendar-day-header">Sun</div>
                  <div class="calendar-day-header">Mon</div>
                  <div class="calendar-day-header">Tue</div>
                  <div class="calendar-day-header">Wed</div>
                  <div class="calendar-day-header">Thu</div>
                  <div class="calendar-day-header">Fri</div>
                  <div class="calendar-day-header">Sat</div>
                </div>
                <div class="calendar-view-grid" id="calendar-grid-container">
                  <!-- Days grid -->
                </div>
              </div>

              <!-- Scheduled list -->
              <div class="dashboard-card col-4 col-lg-12" style="max-height:600px; overflow-y:auto;">
                <h3 style="font-size:14px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Interviews Lined Up</h3>
                <div id="calendar-interviews-list">
                  <!-- Day interview cards -->
                </div>
              </div>
            </div>
          </div>

          <!-- Tab Panel 2: Interview Directory -->
          <div class="sub-interview-panel" id="tab-interview-directory-panel">
            <div class="dashboard-card" style="padding:0; overflow-x:auto;">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Candidate</th>
                    <th>Role Designation</th>
                    <th>Round Title</th>
                    <th>Interview Type</th>
                    <th>Schedule Timing</th>
                    <th>Location / link</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($recruiterInterviews)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:32px; color:var(--text-muted);">No interviews logged.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($recruiterInterviews as $int): ?>
                    <tr>
                      <td><strong><?php echo htmlspecialchars($int['studentName']); ?></strong></td>
                      <td><?php echo htmlspecialchars($int['role']); ?></td>
                      <td><span class="badge badge-primary"><?php echo htmlspecialchars($int['interview_round'] ?? 'Technical'); ?></span></td>
                      <td><?php echo htmlspecialchars($int['interview_type'] ?? 'Online'); ?></td>
                      <td><strong><?php echo $int['date']; ?></strong> at <?php echo $int['time']; ?></td>
                      <td>
                        <?php if ($int['meeting_link']): ?>
                          <a href="<?php echo htmlspecialchars($int['meeting_link']); ?>" target="_blank" style="color:var(--primary); font-size:12px;">Join Link</a>
                        <?php else: ?>
                          <?php echo htmlspecialchars($int['venue']); ?>
                        <?php endif; ?>
                      </td>
                      <td><span class="badge <?php echo $int['result'] === 'Scheduled' ? 'badge-primary' : ($int['result'] === 'Passed' ? 'badge-success' : 'badge-danger'); ?>"><?php echo $int['result']; ?></span></td>
                      <td>
                        <div style="display:inline-flex; gap:4px;">
                          <button class="btn btn-ghost btn-sm btn-icon-only" onclick="openEditInterviewModalDirectly(<?php echo $int['id']; ?>)" title="Modify details">
                            <i data-lucide="edit" style="width:14px; height:14px;"></i>
                          </button>
                          <button class="btn btn-ghost btn-sm btn-icon-only" onclick="deleteInterviewDirectly(<?php echo $int['id']; ?>)" style="color:var(--color-danger);" title="Delete Round">
                            <i data-lucide="trash-2" style="width:14px; height:14px;"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ==================== OFFER RELEASE VIEW ==================== -->
        <div class="page-view-section" id="offers">
          
          <!-- View Tabs Header -->
          <div class="dashboard-card" style="margin-bottom:20px; padding:8px 16px;">
            <div style="display:flex; gap:12px; padding-bottom:8px;">
              <button class="sub-tab-btn active" id="btn-tab-release-offer" onclick="switchOfferTab('release-offer')">
                Release Offer Letter
              </button>
              <button class="sub-tab-btn" id="btn-tab-offer-history" onclick="switchOfferTab('offer-history')">
                Offers Status Tracker
              </button>
            </div>
          </div>

          <!-- Offer Sub-Tab 1: Create & Release Offer -->
          <div class="sub-offer-panel active" id="tab-release-offer-panel">
            <div class="dashboard-card" style="max-width:700px; margin:0 auto;">
              <h3 style="font-size:16px; font-weight:700; margin-bottom:20px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Generate & Dispatch Offer Letter</h3>
              <form id="form-release-offer-letter" onsubmit="submitReleaseOfferForm(event)">
                <div class="grid-container">
                  <div class="form-input-wrapper col-12">
                    <label class="form-input-label">Select Student Application *</label>
                    <select class="input-field-custom" name="application_id" required>
                      <option value="">Choose Application...</option>
                      <?php foreach ($recruiterApps as $app): ?>
                        <?php if ($app['status'] !== 'Rejected'): ?>
                          <option value="<?php echo $app['id']; ?>">
                            <?php echo htmlspecialchars($app['studentName']); ?> (Branch: <?php echo htmlspecialchars($app['department']); ?>) - Designation: <?php echo htmlspecialchars($app['role']); ?>
                          </option>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Hired Designation *</label>
                    <input type="text" class="input-field-custom" name="designation" placeholder="Example: Software Engineer Intern" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Compensation Salary (LPA) *</label>
                    <input type="number" class="input-field-custom" name="salary_lpa" placeholder="Example: 12.00" step="0.01" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Joining Date *</label>
                    <input type="date" class="input-field-custom" name="joining_date" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Job HQ Location *</label>
                    <input type="text" class="input-field-custom" name="location" placeholder="Example: Pune" required>
                  </div>
                  <div class="form-input-wrapper col-12">
                    <label class="form-input-label">Upload Offer Letter File (PDF only) *</label>
                    <input type="file" class="input-field-custom" name="offer_letter" accept="application/pdf" style="padding-top:8px;" required>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;">
                  <i data-lucide="navigation" style="width:14px; height:14px; vertical-align:middle; margin-right:6px;"></i>
                  Send Offer Letter
                </button>
              </form>
            </div>
          </div>

          <!-- Offer Sub-Tab 2: Offer History & Status Tracker -->
          <div class="sub-offer-panel" id="tab-offer-history-panel">
            
            <!-- Filters & Search Toolbar -->
            <div class="dashboard-card" style="margin-bottom:16px; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
                
                <!-- Search Box -->
                <div class="nav-search-bar" style="flex:1; max-width:300px; min-width:200px; margin:0;">
                  <i class="search-icon" data-lucide="search"></i>
                  <input type="search" id="offer-tracker-search" placeholder="Search candidate, job role..." oninput="window.renderOfferTrackerTable()">
                </div>

                <!-- Match Filters & Sort Selector -->
                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                  <select class="input-field-custom" id="offer-tracker-status-filter" style="width:160px; height:38px; font-size:12px; padding:0 12px;" onchange="window.renderOfferTrackerTable()">
                    <option value="All">All Statuses</option>
                    <option value="Draft">Draft</option>
                    <option value="Released">Released</option>
                    <option value="Sent">Sent</option>
                    <option value="Viewed">Viewed</option>
                    <option value="Accepted">Accepted</option>
                    <option value="Rejected">Rejected</option>
                    <option value="Expired">Expired</option>
                  </select>

                  <select class="input-field-custom" id="offer-tracker-sort" style="width:160px; height:38px; font-size:12px; padding:0 12px;" onchange="window.renderOfferTrackerTable()">
                    <option value="id-desc">Newest First</option>
                    <option value="name-asc">Candidate A-Z</option>
                    <option value="salary-desc">Highest CTC package</option>
                    <option value="date-desc">Offer Date</option>
                    <option value="expiry-asc">Expiry Date</option>
                  </select>
                </div>

              </div>
            </div>

            <!-- Dynamic Table Container -->
            <div class="dashboard-card" style="padding:0; overflow-x:auto;">
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Candidate</th>
                    <th>Company Name</th>
                    <th>Job Role</th>
                    <th>Offer Date</th>
                    <th>Expiry Date</th>
                    <th>Offer Status</th>
                    <th>Sent Date</th>
                    <th>Viewed Date</th>
                    <th>Accepted Date / Rejected Date</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="offer-history-tbody">
                  <!-- Rendered dynamically in js/recruiter_app.js -->
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ==================== MESSAGES VIEW ==================== -->
        <div class="page-view-section" id="messages">
          <div class="chat-viewport-layout">
            <!-- Chat sidebar contacts -->
            <div class="chat-contacts-sidebar">
              <div style="padding:16px; border-bottom:1px solid var(--border-color); font-weight:700;">Candidates Thread</div>
              <div class="ats-candidate-list" id="chat-contacts-list" style="padding:8px;">
                <!-- Loaded dynamically -->
              </div>
            </div>

            <!-- Chat message bubble space -->
            <div class="chat-active-window">
              <div style="padding:16px; border-bottom:1px solid var(--border-color); font-weight:700;" id="chat-active-contact-title">Select Candidate</div>
              <div class="chat-messages-container" id="chat-messages-scroll">
                <div class="empty-illustration-container" style="height:100%;">
                  <i data-lucide="message-square" style="width:48px; height:48px; color:var(--text-muted); margin-bottom:12px;"></i>
                  <h4 class="empty-heading">Candidate Thread Chat</h4>
                  <p class="empty-subtext">Click on any candidate contact on the left to start sending real-time messages directly.</p>
                </div>
              </div>
              <form class="chat-input-bar" id="chat-message-form">
                <input type="text" id="chat-message-input" class="input-field-custom" style="flex:1;" placeholder="Type your message here..." required>
                <button type="submit" class="btn btn-primary">Send</button>
              </form>
            </div>
          </div>
        </div>

        <!-- ==================== ANALYTICS VIEW ==================== -->
        <div class="page-view-section" id="analytics">
          <!-- Row of 9 KPI panels -->
          <div class="grid-container" style="margin-bottom:24px;">
            <!-- 1. Total Students -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('student_management')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Total Students</span>
                <i data-lucide="users" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-total-students">0</div>
            </div>
            
            <!-- 2. Total Companies -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('profile')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Total Companies</span>
                <i data-lucide="building" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-total-companies">0</div>
            </div>

            <!-- 3. Active Drives -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('drives')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Active Drives</span>
                <i data-lucide="briefcase" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-active-drives">0</div>
            </div>

            <!-- 4. Applications -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Applications</span>
                <i data-lucide="file-text" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-applications">0</div>
            </div>

            <!-- 5. Interviews -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('interviews')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Interviews</span>
                <i data-lucide="calendar" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-interviews">0</div>
            </div>

            <!-- 6. Shortlisted -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Shortlisted</span>
                <i data-lucide="user-check" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-shortlisted">0</div>
            </div>

            <!-- 7. Offers Released -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('offers')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Offers Released</span>
                <i data-lucide="award" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-offers">0</div>
            </div>

            <!-- 8. Hired Students -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('pipeline')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Hired Students</span>
                <i data-lucide="check-circle" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-hired">0</div>
            </div>

            <!-- 9. Placement Percentage -->
            <div class="dashboard-card col-4 col-md-6 lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer; padding:16px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <span style="font-size:12px; font-weight:600; color:var(--text-secondary);">Placement Percentage</span>
                <i data-lucide="trending-up" style="width:16px; height:16px; color:var(--primary);"></i>
              </div>
              <div style="font-size:24px; font-weight:700; color:var(--text-primary);" id="analytics-kpi-placement-rate">0%</div>
            </div>
          </div>

          <div class="grid-container">
            <!-- Highest, Avg, Lowest Packages -->
            <div class="dashboard-card col-4">
              <div style="font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:4px;">Highest Compensation LPA</div>
              <div style="font-size:24px; font-weight:700; color:var(--color-success);" id="analytics-pkg-highest">₹0 LPA</div>
            </div>
            <div class="dashboard-card col-4">
              <div style="font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:4px;">Average Compensation LPA</div>
              <div style="font-size:24px; font-weight:700; color:var(--primary);" id="analytics-pkg-avg">₹0 LPA</div>
            </div>
            <div class="dashboard-card col-4">
              <div style="font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:4px;">Lowest Compensation LPA</div>
              <div style="font-size:24px; font-weight:700; color:var(--color-warning);" id="analytics-pkg-lowest">₹0 LPA</div>
            </div>
          </div>

          <div class="grid-container" style="margin-top:24px;">
            <!-- Offers acceptance rate OAR -->
            <div class="dashboard-card col-6 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:12px;">Offer Acceptance Rate (OAR)</h3>
              <div style="font-size:42px; font-weight:700; color:var(--primary); margin-bottom:12px;" id="analytics-oar-value">0%</div>
              <p style="font-size:13px; color:var(--text-secondary); line-height:1.6;">Matches the ratio of student candidates that clicked accept offer letter against total candidates shortlisted and offered.</p>
            </div>

            <!-- Academic CGPA Distributon -->
            <div class="dashboard-card col-6 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:12px;">Candidate CGPA Distribution</h3>
              <div style="display:flex; flex-direction:column; gap:10px; font-size:13px; margin-top:12px;">
                <div>
                  <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>CGPA &ge; 9.0 (Outstanding Profiles)</span><strong>45%</strong></div>
                  <div style="height:6px; background-color:#F1F5F9; border-radius:10px; overflow:hidden;"><div style="height:100%; width:45%; background-color:#10B981;"></div></div>
                </div>
                <div>
                  <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>8.0 &le; CGPA &lt; 9.0 (Distinction Profiles)</span><strong>40%</strong></div>
                  <div style="height:6px; background-color:#F1F5F9; border-radius:10px; overflow:hidden;"><div style="height:100%; width:40%; background-color:#2563EB;"></div></div>
                </div>
                <div>
                  <div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>CGPA &lt; 8.0 (Eligible Profiles)</span><strong>15%</strong></div>
                  <div style="height:6px; background-color:#F1F5F9; border-radius:10px; overflow:hidden;"><div style="height:100%; width:15%; background-color:#F59E0B;"></div></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ==================== NOTIFICATIONS VIEW ==================== -->
        <div class="page-view-section" id="notifications">
          <div class="dashboard-card">
            <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px; display:flex; align-items:center; justify-content:space-between;">
              <div style="display:flex; align-items:center; gap:8px;">
                <i data-lucide="bell" style="width:18px; height:18px; color:var(--primary);"></i>
                System Activity & Broadcast Notifications
              </div>
              <button class="btn btn-secondary btn-sm" id="recruiter-mark-all-read" style="padding: 4px 8px; font-size: 11px;">Mark All as Read</button>
            </h3>
            
            <div id="notifications-list-container" style="display:flex; flex-direction:column; gap:12px;">
              <!-- Loaded dynamically -->
            </div>
          </div>
        </div>

        <!-- ==================== REPORTS VIEW ==================== -->
        <div class="page-view-section" id="reports">
          <div class="dashboard-card">
            <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Generate Placement Reports</h3>
            <div class="grid-container">
              <div class="form-input-wrapper col-4 col-md-12">
                <label class="form-input-label">Select Campaign Date Range</label>
                <input type="date" class="input-field-custom" id="report-filter-date" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31" value="2026-07-01">
              </div>
              <div class="form-input-wrapper col-4 col-md-12">
                <label class="form-input-label">Filter Drive Status</label>
                <select class="input-field-custom" id="report-filter-status">
                  <option value="All">All Drives</option>
                  <option value="open">Open Campaigns</option>
                  <option value="closed">Closed Campaigns</option>
                </select>
              </div>
              <div class="col-4 col-md-12" style="display:flex; align-items:flex-end; padding-bottom:16px;">
                <button class="btn btn-primary" onclick="triggerDataExport('csv')" style="width:100%; height:42px;">Export CSV Spreadsheet</button>
              </div>
            </div>
          </div>
        </div>

        <!-- ==================== COMPANY PROFILE VIEW ==================== -->
        <div class="page-view-section" id="profile">
          
          <!-- Sub-Tab Header for Profile -->
          <div class="dashboard-card" style="margin-bottom:20px; padding:12px 16px;">
            <div class="sub-tab-nav-bar" role="tablist" style="display:flex; gap:10px; overflow-x:auto;">
              <button type="button" class="sub-tab-btn active" onclick="switchProfileTab('sec-branding')">1. Company Branding</button>
              <button type="button" class="sub-tab-btn" onclick="switchProfileTab('sec-corporate')">2. Corporate Info</button>
              <button type="button" class="sub-tab-btn" onclick="switchProfileTab('sec-location')">3. Office Location</button>
              <button type="button" class="sub-tab-btn" onclick="switchProfileTab('sec-preferences')">4. Hiring Preferences</button>
              <button type="button" class="sub-tab-btn" onclick="switchProfileTab('sec-social')">5. Social Media</button>
              <button type="button" class="sub-tab-btn" onclick="switchProfileTab('sec-documents')">6. Verification Docs</button>
              <button type="button" class="sub-tab-btn" onclick="switchProfileTab('sec-password')">Security & Password</button>
              <button type="button" class="sub-tab-btn" onclick="switchProfileTab('sec-audit')">Activity Audit Timeline</button>
            </div>
          </div>

          <!-- SECTION 1: Company Branding -->
          <div class="profile-sub-panel active" id="sec-branding">
            <div class="grid-container">
              
              <!-- Logo Upload Dropzone -->
              <div class="dashboard-card col-6 col-lg-12">
                <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px; display:flex; align-items:center; justify-content:space-between;">
                  <span>Corporate Logo Asset</span>
                  <span class="badge badge-primary" style="font-size:10px;">Recommended: 400x400 px</span>
                </h3>
                
                <div class="branding-logo-preview" style="width:120px; height:120px; border-radius:16px; border:2px dashed #CBD5E1; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto; overflow:hidden; background-color:#F8FAFC;">
                  <?php if ($companyLogo): ?>
                    <img src="<?php echo htmlspecialchars($companyLogo); ?>" id="preview-company-logo-img" alt="Branding Logo" style="width:100%; height:100%; object-fit:contain;">
                  <?php else: ?>
                    <span id="preview-company-logo-placeholder" style="color:var(--text-muted); font-size:12px;">No Logo</span>
                  <?php endif; ?>
                </div>

                <div class="branding-dropzone" onclick="document.getElementById('logo-file-input').click()" ondragover="event.preventDefault(); this.classList.add('dragover');" ondragleave="this.classList.remove('dragover');" ondrop="handleBrandingDrop(event, 'company_logo')">
                  <i data-lucide="upload-cloud" style="width:32px; height:32px; color:var(--primary); margin-bottom:8px;"></i>
                  <p style="font-weight:600; font-size:13px; margin-bottom:4px;">Drag & Drop Corporate Logo Here</p>
                  <p style="font-size:11px; color:var(--text-muted);">Supports PNG, JPG, WEBP or SVG up to 5MB</p>
                </div>

                <input type="file" id="logo-file-input" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="display:none;" onchange="triggerBrandingUpload('logo-file-input', 'company_logo')">

                <!-- Progress & Action Bar -->
                <div class="crop-preview-toolbar" style="margin-top:16px;">
                  <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('logo-file-input').click()">
                    <i data-lucide="image" style="width:12px; height:12px; margin-right:4px;"></i> Choose File
                  </button>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="removeBrandingAsset('company_logo')" style="color:var(--color-danger);">
                    <i data-lucide="trash-2" style="width:12px; height:12px; margin-right:4px;"></i> Remove
                  </button>
                </div>
              </div>

              <!-- Banner Upload Dropzone -->
              <div class="dashboard-card col-6 col-lg-12">
                <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px; display:flex; align-items:center; justify-content:space-between;">
                  <span>Corporate Banner Header</span>
                  <span class="badge badge-primary" style="font-size:10px;">Recommended: 1200x400 px</span>
                </h3>
                
                <div class="branding-banner-preview" style="width:100%; height:120px; border-radius:16px; border:2px dashed #CBD5E1; display:flex; align-items:center; justify-content:center; margin-bottom:16px; overflow:hidden; background-color:#F8FAFC;">
                  <?php if ($companyBanner): ?>
                    <img src="<?php echo htmlspecialchars($companyBanner); ?>" id="preview-company-banner-img" alt="Branding Banner" style="width:100%; height:100%; object-fit:cover;">
                  <?php else: ?>
                    <span id="preview-company-banner-placeholder" style="color:var(--text-muted); font-size:12px;">No Banner Header</span>
                  <?php endif; ?>
                </div>

                <div class="branding-dropzone" onclick="document.getElementById('banner-file-input').click()" ondragover="event.preventDefault(); this.classList.add('dragover');" ondragleave="this.classList.remove('dragover');" ondrop="handleBrandingDrop(event, 'company_banner')">
                  <i data-lucide="image-plus" style="width:32px; height:32px; color:var(--primary); margin-bottom:8px;"></i>
                  <p style="font-weight:600; font-size:13px; margin-bottom:4px;">Drag & Drop Cover Banner Here</p>
                  <p style="font-size:11px; color:var(--text-muted);">Supports PNG, JPG, WEBP up to 8MB</p>
                </div>

                <input type="file" id="banner-file-input" accept="image/png,image/jpeg,image/webp" style="display:none;" onchange="triggerBrandingUpload('banner-file-input', 'company_banner')">

                <!-- Progress & Action Bar -->
                <div class="crop-preview-toolbar" style="margin-top:16px;">
                  <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('banner-file-input').click()">
                    <i data-lucide="image" style="width:12px; height:12px; margin-right:4px;"></i> Choose File
                  </button>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="removeBrandingAsset('company_banner')" style="color:var(--color-danger);">
                    <i data-lucide="trash-2" style="width:12px; height:12px; margin-right:4px;"></i> Remove
                  </button>
                </div>
              </div>

            </div>
          </div>

          <!-- SECTION 2: Corporate Information Form -->
          <div class="profile-sub-panel" id="sec-corporate" style="display:none;">
            <div class="dashboard-card">
              <h3 style="font-size:16px; font-weight:700; margin-bottom:20px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Corporate Parameters & Recruiter Head Details</h3>
              <form id="recruiter-profile-form" onsubmit="submitRecruiterProfileForm(event)">
                <div class="grid-container">
                  
                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">HR Representative Name *</label>
                    <input type="text" class="input-field-custom" name="hr_name" value="<?php echo htmlspecialchars($hrName); ?>" placeholder="Example: Rahul Sharma" required>
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Recruiter Head Title *</label>
                    <input type="text" class="input-field-custom" name="recruiter_name" value="<?php echo htmlspecialchars($recruiterName); ?>" placeholder="Example: Recruiting Officer" required>
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Recruiter Head Designation</label>
                    <input type="text" class="input-field-custom" name="designation" value="<?php echo htmlspecialchars($designation); ?>" placeholder="Example: Talent Acquisition Head">
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Industry Domain *</label>
                    <input type="text" class="input-field-custom" name="industry" value="<?php echo htmlspecialchars($industry); ?>" placeholder="Example: Information Technology" required>
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Company Name *</label>
                    <input type="text" class="input-field-custom" name="company_name" value="<?php echo htmlspecialchars($companyName); ?>" placeholder="Example: Google Inc." required>
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Corporate Company Size</label>
                    <input type="text" class="input-field-custom" name="company_size" value="<?php echo htmlspecialchars($companySize); ?>" placeholder="Example: 500-1000 Employees">
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Corporate Website URL *</label>
                    <input type="url" class="input-field-custom" name="website" value="<?php echo htmlspecialchars($website); ?>" placeholder="Example: https://google.com" required>
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Official Contact Phone (10 digits) *</label>
                    <input type="text" class="input-field-custom" name="phone" value="<?php echo htmlspecialchars($phone); ?>" placeholder="Example: 9876543210" required>
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Official HR Email Address *</label>
                    <input type="email" class="input-field-custom" value="<?php echo htmlspecialchars($userEmail); ?>" disabled style="background-color:#F1F5F9;">
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">GSTIN ID Number</label>
                    <input type="text" class="input-field-custom" name="gst" value="<?php echo htmlspecialchars($gst); ?>" placeholder="Example: 27AAAAA1111A1Z1">
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">PAN ID Card</label>
                    <input type="text" class="input-field-custom" name="pan" value="<?php echo htmlspecialchars($pan); ?>" placeholder="Example: ABCDE1234F">
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Founded Year</label>
                    <input type="number" class="input-field-custom" name="founded_year" value="<?php echo htmlspecialchars($foundedYear); ?>" placeholder="Example: 2010">
                  </div>

                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Total Employee Count</label>
                    <input type="text" class="input-field-custom" name="employee_count" value="<?php echo htmlspecialchars($employeeCount); ?>" placeholder="Example: 1200+ Employees">
                  </div>

                  <div class="form-input-wrapper col-12">
                    <label class="form-input-label">Headquarters Address</label>
                    <textarea class="textarea-field-custom" name="office_address" rows="2" placeholder="Example: Cyber City, Tower B, Level 6, Pune, India"><?php echo htmlspecialchars($officeAddress); ?></textarea>
                  </div>

                  <div class="form-input-wrapper col-12">
                    <label class="form-input-label">Company Description</label>
                    <textarea class="textarea-field-custom" name="description" rows="3" placeholder="Describe core business operations and technology stack..."><?php echo htmlspecialchars($description); ?></textarea>
                  </div>

                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Corporate Vision</label>
                    <textarea class="textarea-field-custom" name="vision" rows="2" placeholder="Enter corporate vision statement..."><?php echo htmlspecialchars($vision); ?></textarea>
                  </div>

                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Corporate Mission</label>
                    <textarea class="textarea-field-custom" name="mission" rows="2" placeholder="Enter corporate mission statement..."><?php echo htmlspecialchars($mission); ?></textarea>
                  </div>

                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;">
                  <i data-lucide="save" style="width:14px; height:14px; margin-right:6px;"></i> Save Corporate Information
                </button>
              </form>
            </div>
          </div>

          <!-- SECTION 3: Office Location -->
          <div class="profile-sub-panel" id="sec-location" style="display:none;">
            <div class="grid-container">
              <div class="dashboard-card col-6 col-lg-12">
                <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Office Address Details</h3>
                <form onsubmit="submitRecruiterProfileForm(event)">
                  <div class="grid-container">
                    <div class="form-input-wrapper col-6 col-md-12">
                      <label class="form-input-label">Country *</label>
                      <input type="text" class="input-field-custom" name="country" value="<?php echo htmlspecialchars($country); ?>" required>
                    </div>
                    <div class="form-input-wrapper col-6 col-md-12">
                      <label class="form-input-label">State / Province *</label>
                      <input type="text" class="input-field-custom" name="state" value="<?php echo htmlspecialchars($state); ?>" placeholder="Example: Maharashtra" required>
                    </div>
                    <div class="form-input-wrapper col-6 col-md-12">
                      <label class="form-input-label">City *</label>
                      <input type="text" class="input-field-custom" name="city" value="<?php echo htmlspecialchars($city); ?>" placeholder="Example: Pune" required>
                    </div>
                    <div class="form-input-wrapper col-6 col-md-12">
                      <label class="form-input-label">Pincode / Postal Code *</label>
                      <input type="text" class="input-field-custom" name="pincode" value="<?php echo htmlspecialchars($pincode); ?>" placeholder="Example: 411001" required>
                    </div>
                  </div>
                  <button type="submit" class="btn btn-primary" style="margin-top:12px;">Save Office Location</button>
                </form>
              </div>

              <!-- Interactive Location Preview Card -->
              <div class="dashboard-card col-6 col-lg-12">
                <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Location Map Preview</h3>
                <div style="width:100%; height:220px; background-color:#E2E8F0; border-radius:12px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:16px;">
                  <i data-lucide="map-pin" style="width:36px; height:36px; color:var(--primary); margin-bottom:8px;"></i>
                  <h4 style="font-size:14px; font-weight:700; margin-bottom:4px;"><?php echo htmlspecialchars($companyName); ?> HQ</h4>
                  <p style="font-size:12px; color:var(--text-secondary);"><?php echo htmlspecialchars($officeAddress ?: 'Pune, Maharashtra, India'); ?></p>
                </div>
              </div>
            </div>
          </div>

          <!-- SECTION 4: Hiring Preferences -->
          <div class="profile-sub-panel" id="sec-preferences" style="display:none;">
            <div class="dashboard-card">
              <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Campus Recruitment Eligibility Criteria & Preferences</h3>
              <form onsubmit="submitRecruiterProfileForm(event)">
                <div class="grid-container">
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Target Eligible Branches</label>
                    <input type="text" class="input-field-custom" name="eligible_branches" value="<?php echo htmlspecialchars($hiringPreferences['eligible_branches'] ?? 'CE, IT, ENTC, AI'); ?>" placeholder="Example: CE, IT, ENTC, AI">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Minimum CGPA Criteria (1.00 - 10.00)</label>
                    <input type="number" class="input-field-custom" name="min_cgpa" step="0.01" value="<?php echo htmlspecialchars($hiringPreferences['min_cgpa'] ?? '7.50'); ?>">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Maximum Allowed Backlogs</label>
                    <input type="number" class="input-field-custom" name="max_backlogs" value="<?php echo htmlspecialchars($hiringPreferences['max_backlogs'] ?? '0'); ?>">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Compensation Salary Range (LPA)</label>
                    <input type="text" class="input-field-custom" name="salary_range" value="<?php echo htmlspecialchars($hiringPreferences['salary_range'] ?? '8.0 - 24.0 LPA'); ?>">
                  </div>
                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Work Mode Preference</label>
                    <select class="input-field-custom" name="work_mode">
                      <option value="Onsite" <?php echo ($hiringPreferences['work_mode'] ?? '') === 'Onsite' ? 'selected' : ''; ?>>Onsite Office</option>
                      <option value="Hybrid" <?php echo ($hiringPreferences['work_mode'] ?? '') === 'Hybrid' ? 'selected' : ''; ?>>Hybrid Mode</option>
                      <option value="Remote" <?php echo ($hiringPreferences['work_mode'] ?? '') === 'Remote' ? 'selected' : ''; ?>>Remote Work</option>
                    </select>
                  </div>
                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Opportunity Type</label>
                    <select class="input-field-custom" name="job_type">
                      <option value="Full-Time" <?php echo ($hiringPreferences['job_type'] ?? '') === 'Full-Time' ? 'selected' : ''; ?>>Full-Time Permanent</option>
                      <option value="Internship + FTE" <?php echo ($hiringPreferences['job_type'] ?? '') === 'Internship + FTE' ? 'selected' : ''; ?>>Internship + Full-Time Conversion</option>
                      <option value="Internship Only" <?php echo ($hiringPreferences['job_type'] ?? '') === 'Internship Only' ? 'selected' : ''; ?>>Internship Only</option>
                    </select>
                  </div>
                  <div class="form-input-wrapper col-4 col-md-12">
                    <label class="form-input-label">Service Bond Requirement</label>
                    <input type="text" class="input-field-custom" name="bond" value="<?php echo htmlspecialchars($hiringPreferences['bond'] ?? 'No Bond'); ?>" placeholder="Example: 1 Year Service Agreement or None">
                  </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:12px;">Save Hiring Preferences</button>
              </form>
            </div>
          </div>

          <!-- SECTION 5: Social Media -->
          <div class="profile-sub-panel" id="sec-social" style="display:none;">
            <div class="dashboard-card">
              <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Corporate Social Media Links</h3>
              <form onsubmit="submitRecruiterProfileForm(event)">
                <div class="grid-container">
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">LinkedIn Organization URL</label>
                    <input type="url" class="input-field-custom" name="social_linkedin" value="<?php echo htmlspecialchars($socialLinks['linkedin'] ?? ''); ?>" placeholder="https://linkedin.com/company/example">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Twitter / X Page</label>
                    <input type="url" class="input-field-custom" name="social_twitter" value="<?php echo htmlspecialchars($socialLinks['twitter'] ?? ''); ?>" placeholder="https://x.com/example">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Facebook Page</label>
                    <input type="url" class="input-field-custom" name="social_facebook" value="<?php echo htmlspecialchars($socialLinks['facebook'] ?? ''); ?>" placeholder="https://facebook.com/example">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Instagram Profile</label>
                    <input type="url" class="input-field-custom" name="social_instagram" value="<?php echo htmlspecialchars($socialLinks['instagram'] ?? ''); ?>" placeholder="https://instagram.com/example">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">GitHub Organization</label>
                    <input type="url" class="input-field-custom" name="social_github" value="<?php echo htmlspecialchars($socialLinks['github'] ?? ''); ?>" placeholder="https://github.com/example">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">YouTube Channel</label>
                    <input type="url" class="input-field-custom" name="social_youtube" value="<?php echo htmlspecialchars($socialLinks['youtube'] ?? ''); ?>" placeholder="https://youtube.com/@example">
                  </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:12px;">Save Social Media Handles</button>
              </form>
            </div>
          </div>

          <!-- SECTION 6: Company Verification Documents -->
          <div class="profile-sub-panel" id="sec-documents" style="display:none;">
            <div class="dashboard-card" style="margin-bottom:20px;">
              <h3 style="font-size:15px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
                <span>Corporate Verification Certificates & Documents</span>
                <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('company-doc-file-input').click()">
                  <i data-lucide="upload" style="width:14px; height:14px; margin-right:4px;"></i> Upload Document
                </button>
              </h3>
              
              <input type="file" id="company-doc-file-input" accept="application/pdf,image/png,image/jpeg" style="display:none;" onchange="uploadCompanyVerificationDoc(event)">

              <div class="dashboard-card" style="padding:0; overflow-x:auto;">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>Document Title</th>
                      <th>File Size</th>
                      <th>Uploaded Timestamp</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="company-docs-tbody">
                    <?php if (empty($companyDocs)): ?>
                      <tr>
                        <td colspan="4" style="text-align:center; padding:32px; color:var(--text-muted);">
                          No verification documents uploaded. Please upload GST Certificate, PAN, or Company Registration.
                        </td>
                      </tr>
                    <?php endif; ?>
                    <?php foreach ($companyDocs as $doc): ?>
                      <tr>
                        <td><strong><?php echo htmlspecialchars($doc['name']); ?></strong></td>
                        <td><code><?php echo htmlspecialchars($doc['size'] ?? 'PDF'); ?></code></td>
                        <td><?php echo htmlspecialchars($doc['uploaded_at'] ?? date('Y-m-d')); ?></td>
                        <td>
                          <div style="display:inline-flex; gap:6px;">
                            <a href="<?php echo htmlspecialchars($doc['path']); ?>" target="_blank" class="btn btn-ghost btn-sm btn-icon-only" title="Preview / Download">
                              <i data-lucide="eye" style="width:14px; height:14px;"></i>
                            </a>
                            <button type="button" class="btn btn-ghost btn-sm btn-icon-only" onclick="deleteCompanyDoc('<?php echo $doc['id']; ?>')" style="color:var(--color-danger);" title="Delete Document">
                              <i data-lucide="trash-2" style="width:14px; height:14px;"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- SECURITY & PASSWORD SECTION -->
          <div class="profile-sub-panel" id="sec-password" style="display:none;">
            <div class="dashboard-card" style="max-width:650px; margin:0 auto;">
              <h3 style="font-size:16px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Change Security Password</h3>
              <form id="recruiter-password-form" onsubmit="submitRecruiterPasswordForm(event)">
                
                <div class="form-input-wrapper">
                  <label class="form-input-label">Current Password *</label>
                  <div style="position:relative;">
                    <input type="password" class="input-field-custom" id="pwd-current" required placeholder="Enter current account password">
                    <button type="button" onclick="togglePasswordVisibility('pwd-current')" style="position:absolute; right:12px; top:12px; border:none; background:none; cursor:pointer; color:var(--text-muted);">
                      <i data-lucide="eye" style="width:16px; height:16px;"></i>
                    </button>
                  </div>
                </div>

                <div class="form-input-wrapper">
                  <label class="form-input-label">New Password *</label>
                  <div style="position:relative;">
                    <input type="password" class="input-field-custom" id="pwd-new" onkeyup="checkPasswordRequirements(this.value)" required placeholder="Enter new password (Min 8 chars)">
                    <button type="button" onclick="togglePasswordVisibility('pwd-new')" style="position:absolute; right:12px; top:12px; border:none; background:none; cursor:pointer; color:var(--text-muted);">
                      <i data-lucide="eye" style="width:16px; height:16px;"></i>
                    </button>
                  </div>
                  
                  <!-- Live Password Strength Meter Bar -->
                  <div class="password-strength-container">
                    <div class="password-meter-bar">
                      <div class="password-meter-fill" id="pwd-strength-fill"></div>
                    </div>
                    <div class="caps-lock-warning" id="caps-lock-alert">
                      <i data-lucide="alert-triangle" style="width:14px; height:14px;"></i> Caps Lock is ON
                    </div>
                    
                    <div class="password-requirements-list">
                      <div class="password-req-item" id="req-len"><span>&bull;</span> Min 8 characters</div>
                      <div class="password-req-item" id="req-upper"><span>&bull;</span> 1 Uppercase letter</div>
                      <div class="password-req-item" id="req-num"><span>&bull;</span> 1 Number</div>
                      <div class="password-req-item" id="req-spec"><span>&bull;</span> 1 Special character</div>
                    </div>
                  </div>
                </div>

                <div class="form-input-wrapper">
                  <label class="form-input-label">Confirm New Password *</label>
                  <div style="position:relative;">
                    <input type="password" class="input-field-custom" id="pwd-confirm" required placeholder="Re-enter new password">
                    <button type="button" onclick="togglePasswordVisibility('pwd-confirm')" style="position:absolute; right:12px; top:12px; border:none; background:none; cursor:pointer; color:var(--text-muted);">
                      <i data-lucide="eye" style="width:16px; height:16px;"></i>
                    </button>
                  </div>
                </div>

                <button type="submit" class="btn btn-primary" id="btn-change-password-submit" style="width:100%; margin-top:8px;">
                  <i data-lucide="lock" style="width:14px; height:14px; margin-right:6px;"></i> Change Password
                </button>
              </form>
            </div>
          </div>

          <!-- ACTIVITY AUDIT TIMELINE SECTION -->
          <div class="profile-sub-panel" id="sec-audit" style="display:none;">
            <div class="dashboard-card">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                <h3 style="font-size:16px; font-weight:700;">Activity Audit History Timeline</h3>
                
                <div style="display:flex; gap:10px;">
                  <input type="search" id="audit-timeline-search" class="input-field-custom" placeholder="Search event history..." oninput="filterAuditTimeline()" style="width:220px; height:36px; font-size:12px;">
                  <button type="button" class="btn btn-secondary btn-sm" onclick="exportAuditHistoryCSV()">
                    <i data-lucide="download" style="width:12px; height:12px; margin-right:4px;"></i> Export Log CSV
                  </button>
                </div>
              </div>

              <div class="audit-timeline-container" id="audit-timeline-list">
                <?php foreach (($recruiterLogs ?? []) as $log): ?>
                  <div class="audit-timeline-node">
                    <div class="audit-timeline-dot"></div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                      <strong style="font-size:13px; color:var(--text-primary);"><?php echo htmlspecialchars($log['action']); ?></strong>
                      <span class="badge badge-primary" style="font-size:10px;"><?php echo htmlspecialchars($log['status'] ?? 'Success'); ?></span>
                    </div>
                    <div style="font-size:11px; color:var(--text-secondary); display:flex; gap:16px;">
                      <span><i data-lucide="clock" style="width:12px; height:12px; vertical-align:middle;"></i> <?php echo $log['created_at']; ?></span>
                      <span><i data-lucide="globe" style="width:12px; height:12px; vertical-align:middle;"></i> IP: <code><?php echo $log['ip_address']; ?></code></span>
                      <span><i data-lucide="laptop" style="width:12px; height:12px; vertical-align:middle;"></i> <?php echo htmlspecialchars($log['browser']); ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

        </div>

        <!-- ==================== SETTINGS VIEW ==================== -->
        <div class="page-view-section" id="settings">
          <form id="recruiter-settings-form" onsubmit="submitRecruiterSettingsForm(event)">
            <div class="grid-container">
              <!-- Column 1: UI & Theme Preferences -->
              <div class="dashboard-card col-6 col-lg-12">
                <h3 style="font-size:14px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px; display:flex; align-items:center; gap:8px;">
                  <i data-lucide="settings" style="width:18px; height:18px; color:var(--primary);"></i>
                  Workspace UI & Theme Preferences
                </h3>
                
                <div class="form-input-wrapper">
                  <label class="form-input-label">Workspace Theme Mode *</label>
                  <select class="input-field-custom" name="theme" id="settings-theme-select" required>
                    <option value="light" <?php echo $userSettings['theme'] === 'light' ? 'selected' : ''; ?>>Light Mode Default</option>
                    <option value="dark" <?php echo $userSettings['theme'] === 'dark' ? 'selected' : ''; ?>>Dark Mode Override</option>
                  </select>
                </div>

                <div class="form-input-wrapper">
                  <label class="form-input-label">Default Interface Language *</label>
                  <select class="input-field-custom" name="language" id="settings-language-select" required>
                    <option value="en" <?php echo $userSettings['language'] === 'en' ? 'selected' : ''; ?>>English (United States)</option>
                    <option value="hi" <?php echo $userSettings['language'] === 'hi' ? 'selected' : ''; ?>>Hindi (India)</option>
                    <option value="es" <?php echo $userSettings['language'] === 'es' ? 'selected' : ''; ?>>Español (España)</option>
                  </select>
                </div>
              </div>

              <!-- Column 2: Notification & Security Configurations -->
              <div class="dashboard-card col-6 col-lg-12">
                <h3 style="font-size:14px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px; display:flex; align-items:center; gap:8px;">
                  <i data-lucide="shield" style="width:18px; height:18px; color:var(--primary);"></i>
                  Notification & Security Settings
                </h3>
                
                <div class="form-input-wrapper">
                  <label class="form-input-label" style="margin-bottom:8px;">System Notifications Delivery</label>
                  <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; margin-bottom:12px;">
                    <input type="checkbox" name="notifications_enabled" id="settings-notifications-enabled" value="1" style="width:16px; height:16px;" <?php echo $userSettings['notifications_enabled'] ? 'checked' : ''; ?>>
                    <label for="settings-notifications-enabled">Enable In-App Activity & Broadcast Notifications</label>
                  </div>
                </div>

                <div class="form-input-wrapper">
                  <label class="form-input-label">Email Subscription Level *</label>
                  <select class="input-field-custom" name="email_preferences" required>
                    <option value="all" <?php echo $userSettings['email_preferences'] === 'all' ? 'selected' : ''; ?>>Send all updates (Drives, Applications, Chats)</option>
                    <option value="important" <?php echo $userSettings['email_preferences'] === 'important' ? 'selected' : ''; ?>>Important alerts only (Interviews, Offers)</option>
                    <option value="none" <?php echo $userSettings['email_preferences'] === 'none' ? 'selected' : ''; ?>>Do not send any emails</option>
                  </select>
                </div>

                <div class="form-input-wrapper">
                  <label class="form-input-label" style="margin-bottom:8px;">Security Settings (Two-Factor Auth)</label>
                  <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; margin-bottom:12px;">
                    <input type="checkbox" name="security_settings" id="settings-2fa-enabled" value="2fa_totp" style="width:16px; height:16px;" <?php echo $userSettings['security_settings'] === '2fa_totp' ? 'checked' : ''; ?>>
                    <label for="settings-2fa-enabled">Require TOTP 2FA Verification Codes on Login</label>
                  </div>
                </div>

                <div class="form-input-wrapper">
                  <label class="form-input-label" style="margin-bottom:8px;">Privacy Settings</label>
                  <div style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600;">
                    <input type="checkbox" name="privacy_settings" id="settings-privacy-enabled" value="privacy_public" style="width:16px; height:16px;" <?php echo $userSettings['privacy_settings'] === 'privacy_public' ? 'checked' : ''; ?>>
                    <label for="settings-privacy-enabled">Make corporate recruitment status public to search engines</label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Save settings action panel -->
            <div class="dashboard-card" style="margin-top:20px; display:flex; justify-content:flex-end; gap:12px; padding:16px;">
              <button type="button" class="btn btn-secondary" onclick="window.location.reload()">Reset Changes</button>
              <button type="submit" class="btn btn-primary" id="btn-save-settings-submit">Save Settings permanently</button>
            </div>
          </form>
        </div>

        <!-- ==================== SYSTEM NOTIFICATIONS VIEW ==================== -->
        <div class="page-view-section" id="notifications">
          <div class="dashboard-card" style="margin-bottom:24px;">
            <h2 style="font-size:18px; font-weight:700; margin-bottom:4px;">Workspace System Notifications</h2>
            <p style="font-size:13px; color:var(--text-secondary);">Manage system push alerts, deadlines and candidate selections updates.</p>
          </div>

          <div class="dashboard-card" style="padding:0;">
            <div style="padding:16px;" id="recruiter-notifications-page-list">
              <div class="empty-illustration-container">
                <i data-lucide="bell" style="width:48px; height:48px; color:var(--text-muted); margin-bottom:12px;"></i>
                <h4 class="empty-heading">No new notifications</h4>
                <p class="empty-subtext">You are completely up to date with candidate registrations and campaigns.</p>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>

    <!-- --- CREATE DRIVE DIALOG POPUP --- -->
    <div class="recruiter-modal-overlay" id="modal-create-drive">
      <div class="recruiter-modal-content">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title">Initialize Placement Campaign</h3>
          <button type="button" class="recruiter-modal-close" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <form id="form-add-drive-recruiter">
          <div class="recruiter-modal-body">
            <div class="form-input-wrapper">
              <label class="form-input-label">Job Designation Role *</label>
              <input type="text" class="input-field-custom" name="job_role" placeholder="Example: Software Engineer Intern" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Eligibility Criteria (Min CGPA: 1.00 - 10.00) *</label>
              <input type="number" class="input-field-custom" name="eligibility_cgpa" placeholder="Example: 8.75" min="1.00" max="10.00" step="0.01" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Compensation LPA package *</label>
              <input type="number" class="input-field-custom" name="package_lpa" placeholder="Example: 8" step="0.1" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Commencement Date *</label>
              <input type="date" class="input-field-custom" name="drive_date" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Registration Deadline *</label>
              <input type="date" class="input-field-custom" name="registration_deadline" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Target Campus Branches (Select Branches dropdown) *</label>
              <select class="input-field-custom" name="departments" required>
                <option value="">Select Branch</option>
                <option value="Information Technology (IT)">Information Technology (IT)</option>
                <option value="Computer Engineering (CE)">Computer Engineering (CE)</option>
                <option value="Artificial Intelligence & Data Science (AIDS)">Artificial Intelligence & Data Science (AIDS)</option>
                <option value="Artificial Intelligence & Machine Learning (AIML)">Artificial Intelligence & Machine Learning (AIML)</option>
                <option value="Electronics & Telecommunication (ENTC)">Electronics & Telecommunication (ENTC)</option>
                <option value="Mechanical Engineering">Mechanical Engineering</option>
                <option value="Civil Engineering">Civil Engineering</option>
                <option value="Electrical Engineering">Electrical Engineering</option>
              </select>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Required Tech Stack Skills Profile</label>
              <input type="text" class="input-field-custom" name="skills_required" placeholder="Example: Java, SQL, OOPs">
            </div>
          </div>
          <div class="recruiter-modal-footer">
            <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-add-drive-recruiter-submit">Publish Campaign</button>
          </div>
        </form>
      </div>
    </div>

    <!-- --- EDIT STUDENT DIALOG POPUP --- -->
    <div class="recruiter-modal-overlay" id="modal-edit-student">
      <div class="recruiter-modal-content" style="max-width:600px;">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title">Modify Candidate Record</h3>
          <button type="button" class="recruiter-modal-close" onclick="closeRecruiterModal('modal-edit-student')" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <form id="form-edit-student-api" onsubmit="submitEditStudentForm(event)">
          <input type="hidden" name="student_id" id="edit-student-id">
          <div class="recruiter-modal-body">
            
            <div class="form-input-wrapper">
              <label class="form-input-label">Full Candidate Name *</label>
              <input type="text" class="input-field-custom" name="name" id="edit-student-name" required>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Email Address *</label>
              <input type="email" class="input-field-custom" name="email" id="edit-student-email" required>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Roll Number *</label>
              <input type="text" class="input-field-custom" name="roll_number" id="edit-student-roll" required>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Department Branch *</label>
              <select class="input-field-custom" name="department" id="edit-student-dept" required>
                <option value="Information Technology (IT)">Information Technology (IT)</option>
                <option value="Computer Engineering (CE)">Computer Engineering (CE)</option>
                <option value="Artificial Intelligence & Data Science (AIDS)">Artificial Intelligence & Data Science (AIDS)</option>
                <option value="Artificial Intelligence & Machine Learning (AIML)">Artificial Intelligence & Machine Learning (AIML)</option>
                <option value="Electronics & Telecommunication (ENTC)">Electronics & Telecommunication (ENTC)</option>
                <option value="Mechanical Engineering">Mechanical Engineering</option>
                <option value="Civil Engineering">Civil Engineering</option>
                <option value="Electrical Engineering">Electrical Engineering</option>
              </select>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Cumulative CGPA (1.00 - 10.00) *</label>
              <input type="number" class="input-field-custom" name="cgpa" id="edit-student-cgpa" step="0.01" min="1.00" max="10.00" required>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Academic Year *</label>
              <input type="text" class="input-field-custom" name="academic_year" id="edit-student-year" placeholder="Example: 2024" pattern="\d{4}" maxlength="4" minlength="4" required>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Contact Phone *</label>
              <input type="text" class="input-field-custom" name="phone" id="edit-student-phone" required>
            </div>

          </div>
          <div class="recruiter-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeRecruiterModal('modal-edit-student')">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-edit-student-submit">Update Profile</button>
          </div>
        </form>
      </div>
    </div>

    <!-- --- VIEW STUDENT DETAILS OVERLAY MODAL --- -->
    <div class="recruiter-modal-overlay" id="modal-view-student">
      <div class="recruiter-modal-content" style="max-width:500px;">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title">Student Profile Details</h3>
          <button type="button" class="recruiter-modal-close" onclick="closeRecruiterModal('modal-view-student')" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <div class="recruiter-modal-body" id="view-student-details-body" style="display:flex; flex-direction:column; gap:16px;">
          <!-- Loaded dynamically -->
        </div>
        <div class="recruiter-modal-footer">
          <button class="btn btn-secondary" onclick="closeRecruiterModal('modal-view-student')">Close</button>
        </div>
      </div>
    </div>

    <!-- --- VIEW GENERIC DETAILS OVERLAY MODAL --- -->
    <div class="recruiter-modal-overlay" id="modal-view-generic-details">
      <div class="recruiter-modal-content" style="max-width:550px;">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title" id="generic-details-title">Details View</h3>
          <button type="button" class="recruiter-modal-close" onclick="closeRecruiterModal('modal-view-generic-details')" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <div class="recruiter-modal-body" id="generic-details-body" style="display:flex; flex-direction:column; gap:16px; font-size:13px; line-height:1.6;">
          <!-- Loaded dynamically -->
        </div>
        <div class="recruiter-modal-footer">
          <button class="btn btn-secondary" onclick="closeRecruiterModal('modal-view-generic-details')">Close</button>
        </div>
      </div>
    </div>

    <!-- --- EDIT OFFER OVERLAY MODAL --- -->
    <div class="recruiter-modal-overlay" id="modal-edit-offer">
      <div class="recruiter-modal-content" style="max-width:600px;">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title">Edit Offer Details</h3>
          <button type="button" class="recruiter-modal-close" onclick="closeRecruiterModal('modal-edit-offer')" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <form id="form-edit-offer-api" onsubmit="window.submitEditOfferForm(event)">
          <input type="hidden" name="offer_id" id="edit-offer-id">
          <div class="recruiter-modal-body">
            <div class="grid-container">
              
              <div class="form-input-wrapper col-6 col-md-12">
                <label class="form-input-label">Hired Designation *</label>
                <input type="text" class="input-field-custom" name="designation" id="edit-offer-designation" required>
              </div>
              
              <div class="form-input-wrapper col-6 col-md-12">
                <label class="form-input-label">Compensation Salary (LPA) *</label>
                <input type="number" class="input-field-custom" name="salary_lpa" id="edit-offer-salary" step="0.01" required>
              </div>

              <div class="form-input-wrapper col-6 col-md-12">
                <label class="form-input-label">Joining Date *</label>
                <input type="date" class="input-field-custom" name="joining_date" id="edit-offer-joining" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31" required>
              </div>

              <div class="form-input-wrapper col-6 col-md-12">
                <label class="form-input-label">Job HQ Location *</label>
                <input type="text" class="input-field-custom" name="location" id="edit-offer-location" required>
              </div>

              <div class="form-input-wrapper col-6 col-md-12">
                <label class="form-input-label">Offer Status *</label>
                <select class="input-field-custom" name="status" id="edit-offer-status" required onchange="window.toggleEditOfferTimestamps()">
                  <option value="Draft">Draft</option>
                  <option value="Released">Released</option>
                  <option value="Sent">Sent</option>
                  <option value="Viewed">Viewed</option>
                  <option value="Accepted">Accepted</option>
                  <option value="Rejected">Rejected</option>
                  <option value="Expired">Expired</option>
                </select>
              </div>

              <div class="form-input-wrapper col-6 col-md-12">
                <label class="form-input-label">Expiry Date *</label>
                <input type="date" class="input-field-custom" name="expiry_date" id="edit-offer-expiry" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31" required>
              </div>

              <div class="form-input-wrapper col-6 col-md-12">
                <label class="form-input-label">Offer Date</label>
                <input type="date" class="input-field-custom" name="offer_date" id="edit-offer-date" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31">
              </div>

              <div class="form-input-wrapper col-6 col-md-12" id="wrapper-edit-offer-sent" style="display:none;">
                <label class="form-input-label">Sent Date</label>
                <input type="date" class="input-field-custom" name="sent_date" id="edit-offer-sent" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31">
              </div>

              <div class="form-input-wrapper col-6 col-md-12" id="wrapper-edit-offer-viewed" style="display:none;">
                <label class="form-input-label">Viewed Date</label>
                <input type="date" class="input-field-custom" name="viewed_date" id="edit-offer-viewed" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31">
              </div>

              <div class="form-input-wrapper col-6 col-md-12" id="wrapper-edit-offer-decision" style="display:none;">
                <label class="form-input-label" id="label-edit-offer-decision">Decision Date</label>
                <input type="date" class="input-field-custom" name="decision_date" id="edit-offer-decision" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31">
              </div>

            </div>
          </div>
          <div class="recruiter-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeRecruiterModal('modal-edit-offer')">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-edit-offer-submit">Save Settings</button>
          </div>
        </form>
      </div>
    </div>

    <!-- --- VIEW BRANCH DETAILS OVERLAY MODAL --- -->
    <div class="recruiter-modal-overlay" id="modal-view-branch">
      <div class="recruiter-modal-content" style="max-width:700px;">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title" id="modal-view-branch-title">Enrolled Candidates</h3>
          <button type="button" class="recruiter-modal-close" onclick="closeRecruiterModal('modal-view-branch')" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <div class="recruiter-modal-body" style="padding:0; overflow-x:auto; max-height:450px;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Roll Number</th>
                <th>Candidate Name</th>
                <th>GPA Score</th>
                <th>Academic Year</th>
                <th>Phone Number</th>
              </tr>
            </thead>
            <tbody id="branch-students-details-body">
              <!-- Injected by recruiter_app.js -->
            </tbody>
          </table>
        </div>
        <div class="recruiter-modal-footer">
          <button class="btn btn-secondary" onclick="closeRecruiterModal('modal-view-branch')">Close Panel</button>
        </div>
      </div>
    </div>

    <!-- --- SCHEDULE / EDIT INTERVIEW DIALOG POPUP --- -->
    <div class="recruiter-modal-overlay" id="modal-schedule-interview">
      <div class="recruiter-modal-content">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title" id="modal-interview-title">Schedule Interview Round</h3>
          <button type="button" class="recruiter-modal-close" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <form id="form-schedule-interview-api" onsubmit="submitInterviewForm(event)">
          <input type="hidden" name="interview_id" id="interview-edit-id">
          <div class="recruiter-modal-body">
            
            <div class="form-input-wrapper" id="interview-student-wrapper">
              <label class="form-input-label">Select Candidate Application *</label>
              <select class="input-field-custom" name="application_id" id="interview-app-id" required>
                <option value="">Choose Application...</option>
                <?php foreach ($recruiterApps as $app): ?>
                  <option value="<?php echo $app['id']; ?>">
                    <?php echo htmlspecialchars($app['studentName']); ?> - <?php echo htmlspecialchars($app['role']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Interview Round *</label>
              <select class="input-field-custom" name="interview_round" id="interview-round" required>
                <option value="Aptitude">Aptitude Test</option>
                <option value="Technical">Technical Interview</option>
                <option value="HR">HR Interview</option>
                <option value="Managerial">Managerial Round</option>
              </select>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Interview Type *</label>
              <select class="input-field-custom" name="interview_type" id="interview-type" required>
                <option value="Online">Online Virtual Meeting</option>
                <option value="In-Person">In-Person Campus Drive</option>
              </select>
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Meeting Link (For Virtual rounds)</label>
              <input type="url" class="input-field-custom" name="meeting_link" id="interview-link" placeholder="Example: https://meet.google.com/abc-defg-hij">
            </div>

            <div class="form-input-wrapper">
              <label class="form-input-label">Round Date *</label>
              <input type="date" class="input-field-custom" name="date" id="interview-date" min="<?php echo date('Y-m-d'); ?>" max="2030-12-31" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Round Time Slot *</label>
              <input type="time" class="input-field-custom" name="time" id="interview-time" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Location / Room Venue *</label>
              <input type="text" class="input-field-custom" name="venue" id="interview-venue" placeholder="Example: Zoom Meeting or Block 3, Seminar Room" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Interviewer Title Name *</label>
              <input type="text" class="input-field-custom" name="interviewer" id="interview-interviewer" placeholder="Example: Principal HR / Staff Engineer" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Instructions for Candidate</label>
              <textarea class="textarea-field-custom" name="instructions" id="interview-instructions" rows="2" placeholder="Example: Keep identity cards and printed resume ready."></textarea>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Notes / Description</label>
              <textarea class="textarea-field-custom" name="notes" id="interview-notes" rows="2" placeholder="Enter private reviewer remarks..."></textarea>
            </div>
            <div class="form-input-wrapper" id="interview-status-wrapper" style="display:none;">
              <label class="form-input-label">Interview Status</label>
              <select class="input-field-custom" name="status" id="interview-status">
                <option value="Scheduled">Scheduled</option>
                <option value="Completed">Completed</option>
                <option value="Cancelled">Cancelled</option>
              </select>
            </div>
          </div>
          <div class="recruiter-modal-footer">
            <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-schedule-int-submit">Confirm Schedule</button>
          </div>
        </form>
      </div>
    </div>

    <!-- --- INTERVIEW EVALUATION FEEDBACK DIALOG POPUP --- -->
    <div class="recruiter-modal-overlay" id="modal-interview-feedback">
      <div class="recruiter-modal-content">
        <div class="recruiter-modal-header">
          <h3 class="recruiter-modal-title">Evaluation Rating & Feedback</h3>
          <button type="button" class="recruiter-modal-close" aria-label="Close modal">
            <i data-lucide="x" style="width:18px; height:18px;"></i>
          </button>
        </div>
        <form id="form-interview-feedback-api">
          <input type="hidden" name="interview_id" id="feedback-interview-id">
          <div class="recruiter-modal-body">
            <div class="form-input-wrapper">
              <label class="form-input-label">Performance Rating (1-10) *</label>
              <input type="number" class="input-field-custom" name="rating" min="1" max="10" placeholder="Example: 8" required>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Round Final Decision *</label>
              <select class="input-field-custom" name="result" required>
                <option value="Passed">Passed (Shortlisted to next round)</option>
                <option value="Failed">Failed (Rejected)</option>
              </select>
            </div>
            <div class="form-input-wrapper">
              <label class="form-input-label">Interview Evaluation Remarks/Feedback *</label>
              <textarea class="textarea-field-custom" name="feedback" rows="4" placeholder="Enter tech-stack evaluation notes here..." required></textarea>
            </div>
          </div>
          <div class="recruiter-modal-footer">
            <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-feedback-submit">Submit Evaluation</button>
          </div>
        </form>
      </div>
    </div>

    <!-- --- TOAST BAR HOLDER --- -->
    <div class="toast-container" id="toast-holder"></div>

  </div>

  <script src="<?php echo BASE_URL; ?>js/recruiter_app.js"></script>
</body>
</html>
