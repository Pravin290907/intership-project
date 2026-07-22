<?php
/**
 * CRMS Premium Recruiter Dashboard View Container
 * High-fidelity redesign with Student Management, Offer Release, Interview CRUD, and professional Google/LinkedIn styling.
 */

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'company') {
  header('Location: auth/login.php');
  exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];
$userEmail = $_SESSION['user_email'];

// Fetch recruiter company details
$stmtComp = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
$stmtComp->execute([$userId]);
$companyProfile = $stmtComp->fetch();

$companyName = $companyProfile['company_name'] ?? 'Recruiter Company';
$companyLogo = $companyProfile['company_logo'] ?? '';
$companyBanner = $companyProfile['banner_image'] ?? '';
$recruiterName = $companyProfile['recruiter_name'] ?? 'Recruiting Officer';
$designation = $companyProfile['designation'] ?? 'Talent Acquisition Head';
$companySize = $companyProfile['company_size'] ?? '500-1000 Employees';
$industry = $companyProfile['industry'] ?? 'Information Technology';

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
  
  <link rel="stylesheet" href="css/design-system.css">
  <link rel="stylesheet" href="css/recruiter_style.css">
  
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.294.0/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <script>
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
          <div class="nav-item-link active" data-target="dashboard">
            <i class="icon" data-lucide="layout-dashboard"></i>
            <span class="nav-item-label">Dashboard</span>
          </div>
          <div class="nav-item-link" data-target="student_management">
            <i class="icon" data-lucide="users"></i>
            <span class="nav-item-label">Student</span>
          </div>
        </div>


        <div class="sidebar-section">
          <div class="sidebar-section-title">Recruitment</div>
          <div class="nav-item-link" data-target="drives">
            <i class="icon" data-lucide="briefcase"></i>
            <span class="nav-item-label">Placement Drives</span>
          </div>
          <div class="nav-item-link" data-target="applications">
            <i class="icon" data-lucide="file-text"></i>
            <span class="nav-item-label">Applications</span>
          </div>
          <div class="nav-item-link" data-target="pipeline">
            <i class="icon" data-lucide="git-pull-request"></i>
            <span class="nav-item-label">Pipeline (Kanban)</span>
          </div>
          <div class="nav-item-link" data-target="interviews">
            <i class="icon" data-lucide="calendar"></i>
            <span class="nav-item-label">Interviews</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Offboard / Selection</div>
          <div class="nav-item-link" data-target="offers">
            <i class="icon" data-lucide="award"></i>
            <span class="nav-item-label">Offer Letter</span>
          </div>
          <div class="nav-item-link" data-target="messages">
            <i class="icon" data-lucide="message-square"></i>
            <span class="nav-item-label">Messages</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Analytics & Audit</div>
          <div class="nav-item-link" data-target="analytics">
            <i class="icon" data-lucide="bar-chart-2"></i>
            <span class="nav-item-label">Analytics</span>
          </div>
          <div class="nav-item-link" data-target="reports">
            <i class="icon" data-lucide="clipboard"></i>
            <span class="nav-item-label">Reports</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-section-title">Account</div>
          <div class="nav-item-link" data-target="notifications">
            <i class="icon" data-lucide="bell"></i>
            <span class="nav-item-label">Notifications</span>
            <span class="badge badge-danger sidebar-notif-badge" id="recruiter-sidebar-notif-badge" style="display: none; margin-left: auto; padding: 2px 6px; font-size: 10px; border-radius: 10px; min-width: 16px; text-align: center;">0</span>
          </div>
          <div class="nav-item-link" data-target="profile">
            <i class="icon" data-lucide="building"></i>
            <span class="nav-item-label">Company Profile</span>
          </div>
          <div class="nav-item-link" data-target="settings">
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
          <div class="nav-search-bar">
            <i class="search-icon" data-lucide="search"></i>
            <input type="search" placeholder="Search profiles, drives, campaigns...">
          </div>

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
          <div class="recruiter-welcome-banner">
            <div class="banner-left">
              <h1 class="banner-title" id="recruiter-welcome-msg">Good Morning, Recruiter 👋</h1>
              <p class="banner-subtitle">Campus Drive Batch Year: 2026 Batch Portal &bull; Today: <?php echo date('l, F d, Y'); ?></p>
            </div>
            <div class="banner-right">
              <div class="banner-company-logo">
                <?php if ($companyLogo): ?>
                  <img src="<?php echo $companyLogo; ?>" alt="Branding Logo" style="width:100%; height:100%; object-fit:contain;">
                <?php else: ?>
                  <i data-lucide="building-2" style="width:32px; height:32px; color:var(--primary);"></i>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Two Column Grid: Recruiter Card (Left) and Clickable KPIs (Right) -->
          <div class="grid-container" style="margin-bottom:24px;">
            
            <!-- Sleek Recruiter Profile Card (Google/LinkedIn style) -->
            <div class="dashboard-card col-4 col-lg-12" style="padding:20px; display:flex; flex-direction:column; justify-content:space-between;">
              <div>
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px;">
                  <div style="display:flex; gap:12px; align-items:center;">
                    <div class="avatar-profile" style="width:50px; height:50px; font-size:18px;">
                      <?php echo getInitials($userName); ?>
                    </div>
                    <div>
                      <h3 style="font-size:15px; font-weight:700; display:inline-flex; align-items:center; gap:4px;">
                        <?php echo htmlspecialchars($companyName); ?>
                        <span style="display:inline-flex; background-color:rgba(37,99,235,0.1); color:var(--primary); border-radius:50%; width:16px; height:16px; align-items:center; justify-content:center; font-size:9px;" title="Verified Enterprise Recruiter">✔</span>
                      </h3>
                      <p style="color:var(--text-secondary); font-size:11px;"><?php echo htmlspecialchars($industry); ?></p>
                    </div>
                  </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:10px; font-size:12px; border-top:1px solid var(--border-color); padding-top:12px; margin-bottom:16px;">
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Recruiter Head</span>
                    <strong><?php echo htmlspecialchars($recruiterName); ?></strong>
                  </div>
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Designation</span>
                    <strong><?php echo htmlspecialchars($designation); ?></strong>
                  </div>
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Company Size</span>
                    <strong><?php echo htmlspecialchars($companySize); ?></strong>
                  </div>
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Active Drive Campaigns</span>
                    <strong style="color:var(--primary);"><?php echo $activeDrivesCount; ?> drives</strong>
                  </div>
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Open Positions</span>
                    <strong style="color:var(--color-success);"><?php echo $openJobsCount; ?> roles</strong>
                  </div>
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Total Hiring count</span>
                    <strong><?php echo $totalHiringCount; ?> students</strong>
                  </div>
                  <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-secondary);">Audit Log Timestamp</span>
                    <strong style="font-size:10px;"><?php echo $lastLoginTime; ?></strong>
                  </div>
                </div>
              </div>
              
              <button class="btn btn-secondary" onclick="window.switchRecruiterView('profile')" style="width:100%; font-size:12px; padding:8px;">
                <i data-lucide="edit-3" style="width:12px; height:12px; vertical-align:middle; margin-right:4px;"></i>
                Edit Corporate Profile
              </button>
            </div>

            <!-- Clickable Analytics KPI Cards Grid (8 Cards) -->
            <div class="col-8 col-lg-12" style="display:grid; grid-template-columns: repeat(4, 1fr); gap:16px;">
              
              <!-- 1. Total Applications (Clicks to Applications) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Total Applications</span>
                  <div class="kpi-icon-wrapper" style="background-color:var(--primary-light); color:var(--primary);">
                    <i data-lucide="file-text" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-applications">0</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; View all</span><span>student profiles</span>
                </div>
              </div>

              <!-- 2. Active Jobs (Clicks to Drives) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('drives')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Active Jobs</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(124,58,237,0.08); color:var(--secondary);">
                    <i data-lucide="briefcase" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-active-drives">0</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; Manage</span><span>drives panel</span>
                </div>
              </div>

              <!-- 3. Students Hired (Clicks to Pipeline) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('pipeline')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Students Hired</span>
                  <div class="kpi-icon-wrapper" style="background-color:var(--color-success-light); color:var(--color-success);">
                    <i data-lucide="smile" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-hired">0</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; Selections</span><span>Kanban board</span>
                </div>
              </div>

              <!-- 4. Shortlisted Candidates (Clicks to Applications) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('applications')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Shortlisted Candidates</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(6,182,212,0.08); color:var(--color-info);">
                    <i data-lucide="user-check" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-shortlisted">0</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; Screen</span><span>shortlisted cards</span>
                </div>
              </div>

              <!-- 5. Interviews Scheduled (Clicks to Interviews) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('interviews')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Interviews Scheduled</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(245,158,11,0.08); color:var(--color-warning);">
                    <i data-lucide="calendar" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-interviews">0</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; View</span><span>calendar agenda</span>
                </div>
              </div>

              <!-- 6. Offers Released (Clicks to Offers) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('offers')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Offers Released</span>
                  <div class="kpi-icon-wrapper" style="background-color:var(--color-success-light); color:var(--color-success);">
                    <i data-lucide="award" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-offers">0</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; Dispatch</span><span>PDF templates</span>
                </div>
              </div>

              <!-- 7. Hiring Rate (Clicks to Analytics) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('analytics')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Hiring Rate</span>
                  <div class="kpi-icon-wrapper" style="background-color:rgba(239,68,68,0.08); color:var(--color-danger);">
                    <i data-lucide="percent" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-hiring-rate">0%</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; Ratio</span><span>against total apps</span>
                </div>
              </div>

              <!-- 8. Total Students (Clicks to Student Management) -->
              <div class="dashboard-card kpi-card-premium lift" onclick="window.switchRecruiterView('student_management')" style="cursor:pointer;">
                <div class="kpi-header">
                  <span>Total Students</span>
                  <div class="kpi-icon-wrapper" style="background-color:var(--primary-light); color:var(--primary);">
                    <i data-lucide="users" style="width:16px; height:16px;"></i>
                  </div>
                </div>
                <div class="kpi-body">
                  <span class="kpi-value-text" id="kpi-total-students">0</span>
                </div>
                <div class="kpi-footer">
                  <span class="trend-up">&uarr; Manage</span><span>candidate database</span>
                </div>
              </div>

            </div>

          </div>

          <!-- Quick Actions & Visualizations -->
          <div class="quick-actions-row">
            <button class="quick-action-btn" onclick="openRecruiterModal('modal-create-drive')">
              <i data-lucide="plus-circle" style="width:24px; height:24px;"></i>
              <span>Create Drive</span>
            </button>

            <button class="quick-action-btn" onclick="openScheduleInterviewModalDirectly()">
              <i data-lucide="calendar-plus" style="width:24px; height:24px;"></i>
              <span>Schedule Round</span>
            </button>
            <button class="quick-action-btn" onclick="openOfferModalDirectly()">
              <i data-lucide="file-plus" style="width:24px; height:24px;"></i>
              <span>Release Offer</span>
            </button>
            <button class="quick-action-btn" onclick="window.switchRecruiterView('messages')">
              <i data-lucide="mail-open" style="width:24px; height:24px;"></i>
              <span>Chat Candidate</span>
            </button>
            <button class="quick-action-btn" onclick="window.switchRecruiterView('settings')">
              <i data-lucide="sliders" style="width:24px; height:24px;"></i>
              <span>Preferences</span>
            </button>
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
              <form id="form-recruiter-add-student" onsubmit="submitAddStudentForm(event)">
                <div class="grid-container">
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Student Name *</label>
                    <input type="text" class="input-field-custom" name="name" placeholder="Example: Rahul Sharma" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Email Address *</label>
                    <input type="email" class="input-field-custom" name="email" placeholder="Example: rahul@gmail.com" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Setup Password *</label>
                    <input type="password" class="input-field-custom" name="password" placeholder="Min 6 characters" required>
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
              <div style="display:flex; gap:16px; border-bottom:1px solid var(--border-color); padding-bottom:0;">
                <span class="nav-item-link active" id="btn-tab-interview-calendar" onclick="switchInterviewTab('interview-calendar')" style="margin-bottom:-1px; padding:12px 16px; border-bottom:2px solid var(--primary); border-radius:0; background:none; font-weight:600; box-shadow:none;">
                  Interview Calendar
                </span>
                <span class="nav-item-link" id="btn-tab-interview-directory" onclick="switchInterviewTab('interview-directory')" style="margin-bottom:-1px; padding:12px 16px; border-bottom:2px solid transparent; border-radius:0; background:none; font-weight:600; box-shadow:none;">
                  Directory / Logs
                </span>
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
            <div style="display:flex; gap:16px; border-bottom:1px solid var(--border-color); padding-bottom:0;">
              <span class="nav-item-link active" id="btn-tab-release-offer" onclick="switchOfferTab('release-offer')" style="margin-bottom:-1px; padding:12px 16px; border-bottom:2px solid var(--primary); border-radius:0; background:none; font-weight:600; box-shadow:none;">
                Release Offer Letter
              </span>
              <span class="nav-item-link" id="btn-tab-offer-history" onclick="switchOfferTab('offer-history')" style="margin-bottom:-1px; padding:12px 16px; border-bottom:2px solid transparent; border-radius:0; background:none; font-weight:600; box-shadow:none;">
                Offers Status Tracker
              </span>
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
          <div class="grid-container">
            <!-- Branding uploads logo / banner -->
            <div class="dashboard-card col-4 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Upload Branding Assets</h3>
              
              <div style="margin-bottom:20px;">
                <label class="form-input-label">Corporate Logo Preview</label>
                <div class="branding-logo-preview">
                  <?php if ($companyLogo): ?>
                    <img src="<?php echo $companyLogo; ?>" alt="Branding Logo">
                  <?php else: ?>
                    <span style="color:var(--text-muted); font-size:11px;">No Logo</span>
                  <?php endif; ?>
                </div>
                <input type="file" id="logo-file-input" style="display:none;" onchange="triggerBrandingUpload('logo-file-input', 'company_logo')">
                <button class="btn btn-secondary btn-sm" onclick="document.getElementById('logo-file-input').click()" style="width:100%; margin-top:8px;">Choose Logo File</button>
              </div>

              <div>
                <label class="form-input-label">Branding Banner Preview</label>
                <div class="branding-banner-preview">
                  <?php if ($companyBanner): ?>
                    <img src="<?php echo $companyBanner; ?>" alt="Branding Banner">
                  <?php else: ?>
                    <span style="color:var(--text-muted); font-size:11px;">No Banner</span>
                  <?php endif; ?>
                </div>
                <input type="file" id="banner-file-input" style="display:none;" onchange="triggerBrandingUpload('banner-file-input', 'company_banner')">
                <button class="btn btn-secondary btn-sm" onclick="document.getElementById('banner-file-input').click()" style="width:100%; margin-top:8px;">Choose Banner File</button>
              </div>
            </div>

            <!-- Profile Info Form -->
            <div class="dashboard-card col-8 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Corporate Parameters</h3>
              <form id="recruiter-profile-form">
                <div class="grid-container">
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">HR Representative Name *</label>
                    <input type="text" class="input-field-custom" name="hr_name" value="<?php echo htmlspecialchars($companyProfile['hr_name'] ?? $userName); ?>" placeholder="Example: Rahul Sharma" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Recruiter Head Title *</label>
                    <input type="text" class="input-field-custom" name="recruiter_name" value="<?php echo htmlspecialchars($companyProfile['recruiter_name'] ?? ''); ?>" placeholder="Example: Recruiting Officer" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Recruiter Head Designation</label>
                    <input type="text" class="input-field-custom" name="designation" value="<?php echo htmlspecialchars($companyProfile['designation'] ?? ''); ?>" placeholder="Example: Talent Acquisition Head">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Corporate Company Size</label>
                    <input type="text" class="input-field-custom" name="company_size" value="<?php echo htmlspecialchars($companyProfile['company_size'] ?? ''); ?>" placeholder="Example: 500-1000 Employees">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Corporate Website URL *</label>
                    <input type="url" class="input-field-custom" name="website" value="<?php echo htmlspecialchars($companyProfile['website'] ?? ''); ?>" placeholder="Example: https://google.com" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">Corporate Contact Phone (10 digits) *</label>
                    <input type="text" class="input-field-custom" name="phone" value="<?php echo htmlspecialchars($companyProfile['phone'] ?? ''); ?>" placeholder="Example: 9876543210" required>
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">GSTIN ID Number</label>
                    <input type="text" class="input-field-custom" name="gst" value="<?php echo htmlspecialchars($companyProfile['gst'] ?? ''); ?>" placeholder="Example: 27AAAAA1111A1Z1">
                  </div>
                  <div class="form-input-wrapper col-6 col-md-12">
                    <label class="form-input-label">PAN ID Card</label>
                    <input type="text" class="input-field-custom" name="pan" value="<?php echo htmlspecialchars($companyProfile['pan'] ?? ''); ?>" placeholder="Example: ABCDE1234F">
                  </div>
                  <div class="form-input-wrapper col-12">
                    <label class="form-input-label">Headquarters Address</label>
                    <textarea class="textarea-field-custom" name="office_address" rows="3" placeholder="Example: Pune"><?php echo htmlspecialchars($companyProfile['office_address'] ?? ''); ?></textarea>
                  </div>
                  <div class="form-input-wrapper col-12">
                    <label class="form-input-label">Corporate Profile Description</label>
                    <textarea class="textarea-field-custom" name="description" rows="3" placeholder="Explain your core corporate activities..."><?php echo htmlspecialchars($companyProfile['description'] ?? ''); ?></textarea>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;">Save Parameters</button>
              </form>
            </div>
          </div>

          <!-- Change password card -->
          <div class="grid-container" style="margin-top:24px;">
            <div class="dashboard-card col-6 col-lg-12">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Change Security Password</h3>
              <form id="recruiter-password-form">
                <div class="form-input-wrapper">
                  <label class="form-input-label">Current Password</label>
                  <input type="password" class="input-field-custom" id="pwd-current" placeholder="Enter current hash" required>
                </div>
                <div class="form-input-wrapper">
                  <label class="form-input-label">New Password</label>
                  <input type="password" class="input-field-custom" id="pwd-new" placeholder="Enter new password" required>
                </div>
                <div class="form-input-wrapper">
                  <label class="form-input-label">Confirm New Password</label>
                  <input type="password" class="input-field-custom" id="pwd-confirm" placeholder="Confirm new password" required>
                </div>
                <button type="submit" class="btn btn-primary">Change Password</button>
              </form>
            </div>

            <!-- Activity Logs list -->
            <div class="dashboard-card col-6 col-lg-12" style="max-height:360px; overflow-y:auto;">
              <h3 style="font-size:14px; font-weight:700; margin-bottom:16px; border-bottom:1px solid var(--border-color); padding-bottom:12px;">Activity Audit History</h3>
              <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach (($recruiterLogs ?? []) as $log): ?>
                  <div style="font-size:12px; border-bottom:1px solid var(--border-color); padding-bottom:6px;">
                    <div style="display:flex; justify-content:space-between; font-weight:600; margin-bottom:2px;">
                      <span><?php echo htmlspecialchars($log['action']); ?></span>
                      <span style="color:var(--text-muted); font-size:10px;"><?php echo $log['created_at']; ?></span>
                    </div>
                    <div style="color:var(--text-secondary); font-size:11px;">IP Address: <code><?php echo $log['ip_address']; ?></code> &bull; Browser: <?php echo htmlspecialchars($log['browser']); ?></div>
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

  <script src="js/recruiter_app.js"></script>
</body>
</html>
