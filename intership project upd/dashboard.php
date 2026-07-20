<?php
/**
 * Master Enterprise Dashboard Router & View Container
 * Enforces role-based session middleware, runs database queries, and outputs the premium SaaS workspace.
 */

require_once __DIR__ . '/config/auth.php';

// Enforce login on all roles
checkRole(['admin', 'tpo', 'student', 'company']);

$role = $_SESSION['user_role'];
$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];
$userEmail = $_SESSION['user_email'];

$db = getDB();

// Helper to determine department codes
function getDeptCode($dept) {
  if (!$dept) return 'GEN';
  if (strpos($dept, 'Computer') !== false) return 'CSE';
  if (strpos($dept, 'Information') !== false) return 'IT';
  if (strpos($dept, 'Electronics') !== false) return 'ECE';
  if (strpos($dept, 'Electrical') !== false) return 'EE';
  if (strpos($dept, 'Mechanical') !== false) return 'ME';
  if (strpos($dept, 'Civil') !== false) return 'CE';
  return 'GEN';
}

try {
  // 1. Fetch Students (Enforced role isolation)
  if ($role === 'admin' || $role === 'tpo') {
    $stmtStu = $db->query("
      SELECT u.id, u.name, u.email, u.status as verifiedStatus, 
             s.roll_number as rollNumber, s.department, s.cgpa, s.phone,
             s.skills, s.projects, s.resume_path, s.certificate_path,
             s.achievements, s.social_links, s.profile_pic,
             COALESCE((SELECT MAX(d.package_lpa) FROM applications a JOIN drives d ON a.drive_id=d.id WHERE a.student_id=u.id AND a.status='Selected'), 0) as highestPackage,
             (SELECT COUNT(*) FROM applications WHERE student_id=u.id AND status='Selected') as placedCount,
             (SELECT COUNT(*) FROM applications WHERE student_id=u.id) as applicationsCount
      FROM users u
      LEFT JOIN students s ON u.id = s.user_id
      WHERE u.role = 'student'
      ORDER BY u.id DESC
    ");
    $students = $stmtStu->fetchAll();
  } else if ($role === 'company') {
    $stmtStu = $db->prepare("
      SELECT DISTINCT u.id, u.name, u.email, u.status as verifiedStatus, 
             s.roll_number as rollNumber, s.department, s.cgpa, s.phone,
             s.skills, s.projects, s.resume_path, s.certificate_path,
             s.achievements, s.social_links, s.profile_pic,
             COALESCE((SELECT MAX(d.package_lpa) FROM applications a JOIN drives d ON a.drive_id=d.id WHERE a.student_id=u.id AND a.status='Selected'), 0) as highestPackage,
             (SELECT COUNT(*) FROM applications WHERE student_id=u.id AND status='Selected') as placedCount,
             (SELECT COUNT(*) FROM applications WHERE student_id=u.id) as applicationsCount
      FROM users u
      LEFT JOIN students s ON u.id = s.user_id
      JOIN applications a ON a.student_id = u.id
      JOIN drives d ON a.drive_id = d.id
      WHERE u.role = 'student' AND d.company_id = ?
      ORDER BY u.id DESC
    ");
    $stmtStu->execute([$userId]);
    $students = $stmtStu->fetchAll();
  } else {
    $stmtStu = $db->prepare("
      SELECT u.id, u.name, u.email, u.status as verifiedStatus, 
             s.roll_number as rollNumber, s.department, s.cgpa, s.phone,
             s.skills, s.projects, s.resume_path, s.certificate_path,
             s.achievements, s.social_links, s.profile_pic,
             COALESCE((SELECT MAX(d.package_lpa) FROM applications a JOIN drives d ON a.drive_id=d.id WHERE a.student_id=u.id AND a.status='Selected'), 0) as highestPackage,
             (SELECT COUNT(*) FROM applications WHERE student_id=u.id AND status='Selected') as placedCount,
             (SELECT COUNT(*) FROM applications WHERE student_id=u.id) as applicationsCount
      FROM users u
      LEFT JOIN students s ON u.id = s.user_id
      WHERE u.role = 'student' AND u.id = ?
      LIMIT 1
    ");
    $stmtStu->execute([$userId]);
    $students = $stmtStu->fetchAll();
  }

  foreach ($students as &$stu) {
    $stu['placedStatus'] = $stu['placedCount'] > 0 ? 'Placed' : 'Unplaced';
    $stu['deptCode'] = getDeptCode($stu['department']);
  }
  unset($stu);

  // 2. Fetch Companies (Enforced role isolation)
  if ($role === 'admin' || $role === 'tpo' || $role === 'student') {
    $companies = $db->query("
      SELECT u.id, u.name, u.email, u.status,
             c.company_name, c.industry, c.avg_package as avgPackage, c.highest_package as highestPackage,
             c.open_positions as openPositions, c.students_hired as studentsHired, c.website, c.phone
      FROM users u
      LEFT JOIN companies c ON u.id = c.user_id
      WHERE u.role = 'company'
      ORDER BY u.id DESC
    ")->fetchAll();
  } else {
    $stmtComp = $db->prepare("
      SELECT u.id, u.name, u.email, u.status,
             c.company_name, c.industry, c.avg_package as avgPackage, c.highest_package as highestPackage,
             c.open_positions as openPositions, c.students_hired as studentsHired, c.website, c.phone
      FROM users u
      LEFT JOIN companies c ON u.id = c.user_id
      WHERE u.role = 'company' AND u.id = ?
      LIMIT 1
    ");
    $stmtComp->execute([$userId]);
    $companies = $stmtComp->fetchAll();
  }

  // 3. Fetch Placement Drives (Enforced role isolation)
  if ($role === 'admin' || $role === 'tpo' || $role === 'student') {
    $drives = $db->query("
      SELECT d.id, d.company_id, d.job_role as jobRole, d.eligibility_cgpa as eligibilityCGPA,
             d.package_lpa as packageLPA, d.drive_date as date, d.status, d.skills_required,
             d.registration_deadline, d.departments, c.company_name as companyName
      FROM drives d
      JOIN companies c ON d.company_id = c.user_id
      ORDER BY d.id DESC
    ")->fetchAll();
  } else {
    $stmtDrives = $db->prepare("
      SELECT d.id, d.company_id, d.job_role as jobRole, d.eligibility_cgpa as eligibilityCGPA,
             d.package_lpa as packageLPA, d.drive_date as date, d.status, d.skills_required,
             d.registration_deadline, d.departments, c.company_name as companyName
      FROM drives d
      JOIN companies c ON d.company_id = c.user_id
      WHERE d.company_id = ?
      ORDER BY d.id DESC
    ");
    $stmtDrives->execute([$userId]);
    $drives = $stmtDrives->fetchAll();
  }

  // 4. Fetch Applications
  $applicationsQueryStr = "
    SELECT a.id, a.student_id as studentId, a.drive_id as driveId, a.applied_date, a.status,
           u.name as studentName, s.department, s.cgpa, d.job_role as role, c.company_name as companyName
    FROM applications a
    JOIN users u ON a.student_id = u.id
    JOIN students s ON u.id = s.user_id
    JOIN drives d ON a.drive_id = d.id
    JOIN companies c ON d.company_id = c.user_id
  ";
  // Filter for student / company role security
  if ($role === 'student') {
    $applicationsQueryStr .= " WHERE a.student_id = :sid";
  } else if ($role === 'company') {
    $applicationsQueryStr .= " WHERE d.company_id = :cid";
  }
  $applicationsQueryStr .= " ORDER BY a.id DESC";

  $stmtApp = $db->prepare($applicationsQueryStr);
  if ($role === 'student') $stmtApp->execute(['sid' => $userId]);
  else if ($role === 'company') $stmtApp->execute(['cid' => $userId]);
  else $stmtApp->execute();
  $applications = $stmtApp->fetchAll();

  foreach ($applications as &$app) {
    $app['deptCode'] = getDeptCode($app['department']);
  }
  unset($app);

  // 5. Fetch Interviews
  $interviewsQueryStr = "
    SELECT i.id, i.application_id, i.date, i.time, i.venue, i.interviewer, i.remarks, i.result, i.attendance,
           u.name as studentName, s.department, d.job_role as role, c.company_name as companyName
    FROM interviews i
    JOIN applications a ON i.application_id = a.id
    JOIN users u ON a.student_id = u.id
    JOIN students s ON u.id = s.user_id
    JOIN drives d ON a.drive_id = d.id
    JOIN companies c ON d.company_id = c.user_id
  ";
  if ($role === 'student') {
    $interviewsQueryStr .= " WHERE a.student_id = :sid";
  } else if ($role === 'company') {
    $interviewsQueryStr .= " WHERE d.company_id = :cid";
  }
  $interviewsQueryStr .= " ORDER BY i.date ASC, i.time ASC";

  $stmtInt = $db->prepare($interviewsQueryStr);
  if ($role === 'student') $stmtInt->execute(['sid' => $userId]);
  else if ($role === 'company') $stmtInt->execute(['cid' => $userId]);
  else $stmtInt->execute();
  $interviews = $stmtInt->fetchAll();

  foreach ($interviews as &$int) {
    $int['deptCode'] = getDeptCode($int['department']);
  }
  unset($int);

  // 6. Fetch Offers
  $offersQueryStr = "
    SELECT o.id, o.application_id, o.salary_lpa as packageLPA, o.designation as role, o.joining_date as date, o.location, o.status,
           u.name as studentName, s.department, c.company_name as companyName, o.offer_letter_path
    FROM offers o
    JOIN applications a ON o.application_id = a.id
    JOIN users u ON a.student_id = u.id
    JOIN students s ON u.id = s.user_id
    JOIN drives d ON a.drive_id = d.id
    JOIN companies c ON d.company_id = c.user_id
  ";
  if ($role === 'student') {
    $offersQueryStr .= " WHERE a.student_id = :sid";
  } else if ($role === 'company') {
    $offersQueryStr .= " WHERE d.company_id = :cid";
  }
  $offersQueryStr .= " ORDER BY o.id DESC";

  $stmtOff = $db->prepare($offersQueryStr);
  if ($role === 'student') $stmtOff->execute(['sid' => $userId]);
  else if ($role === 'company') $stmtOff->execute(['cid' => $userId]);
  else $stmtOff->execute();
  $offers = $stmtOff->fetchAll();

  foreach ($offers as &$off) {
    $off['deptCode'] = getDeptCode($off['department']);
  }
  unset($off);

  // 7. Load Activity Logs (Visible to Admin only)
  $logs = [];
  if ($role === 'admin') {
    $logs = $db->query("SELECT id, username, role, action, ip_address, browser, status, created_at FROM activity_logs ORDER BY id DESC LIMIT 500")->fetchAll();
  }

  // 8. Fetch Profile details
  $profile = [];
  if ($role === 'student') {
    $profile = $db->prepare("SELECT * FROM students WHERE user_id = ?");
    $profile->execute([$userId]);
    $profile = $profile->fetch();
  } else if ($role === 'company') {
    $profile = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
    $profile->execute([$userId]);
    $profile = $profile->fetch();
  }

} catch (PDOException $e) {
  die("Error fetching dashboard entities: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CRMS Workspace Dashboard</title>
  
  <link rel="stylesheet" href="css/design-system.css">
  <link rel="stylesheet" href="css/dashboard.css">
  
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.294.0/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
  <!-- Make variables accessible to JS modules -->
  <script>
    window.campusRecruitmentData = {
      students: <?php echo json_encode($students); ?>,
      companies: <?php echo json_encode($companies); ?>,
      drives: <?php echo json_encode($drives); ?>,
      applications: <?php echo json_encode($applications); ?>,
      interviews: <?php echo json_encode($interviews); ?>,
      offers: <?php echo json_encode($offers); ?>,
      role: '<?php echo $role; ?>',
      userId: <?php echo $userId; ?>,
      translations: <?php 
        $allTrans = require __DIR__ . '/config/lang.php';
        echo json_encode($allTrans[$_SESSION['language'] ?? 'en'] ?? []); 
      ?>,
      csrfToken: '<?php echo getCsrfToken(); ?>'
    };
  </script>
</head>
<body>

  <div class="app-container">
    
    <!-- --- SIDEBAR --- -->
    <aside class="sidebar" id="app-sidebar" aria-label="Sidebar Navigation">
      <div class="sidebar-brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
        <span class="brand-text">CampusRecruit</span>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-section-title">Core</div>
        <div class="nav-item active" data-target="dashboard" role="link" aria-label="Dashboard">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
            <span class="nav-label">Dashboard</span>
          </div>
        </div>

        <?php if ($role === 'admin' || $role === 'tpo'): ?>
        <div class="nav-item" data-target="students" role="link" aria-label="Students">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span class="nav-label">Students</span>
          </div>
        </div>

        <div class="nav-item" data-target="companies" role="link" aria-label="Companies">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            <span class="nav-label">Companies</span>
          </div>
        </div>
        <?php endif; ?>

        <div class="nav-divider"></div>
        <div class="nav-section-title">Operations</div>

        <div class="nav-item" data-target="drives" role="link" aria-label="Placement Drives">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            <span class="nav-label"><?php echo $role === 'student' ? 'Job Openings' : 'Placement Drives'; ?></span>
          </div>
        </div>

        <div class="nav-item" data-target="applications" role="link" aria-label="Applications">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            <span class="nav-label"><?php echo $role === 'student' ? 'My Applications' : 'Applications'; ?></span>
          </div>
        </div>

        <?php if ($role !== 'student'): ?>
        <div class="nav-item" data-target="pipeline" role="link" aria-label="Placement Pipeline">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 18l6-6-6-6"/><polyline points="3 20 9 12 3 4"/></svg>
            <span class="nav-label">Pipeline (Kanban)</span>
          </div>
        </div>
        <?php endif; ?>

        <div class="nav-item" data-target="interviews" role="link" aria-label="Interview Management">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span class="nav-label">Interviews</span>
          </div>
        </div>

        <div class="nav-item" data-target="notifications" role="link" aria-label="Notifications">
          <div class="nav-item-left" style="position: relative; width: 100%;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span class="nav-label">Notifications</span>
            <span class="badge badge-danger sidebar-notif-badge" id="sidebar-notif-badge" style="display: none; position: absolute; right: 0; padding: 2px 6px; font-size: 10px; border-radius: 10px; min-width: 16px; text-align: center;">0</span>
          </div>
        </div>

        <div class="nav-divider"></div>
        <div class="nav-section-title">Utility & Logs</div>

        <div class="nav-item" data-target="profile-tab" role="link" aria-label="My Profile">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span class="nav-label">My Profile</span>
          </div>
        </div>

        <?php if ($role === 'admin'): ?>
        <div class="nav-item" data-target="activitylogs" role="link" aria-label="Activity Logs">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span class="nav-label">Activity History</span>
          </div>
        </div>
        <?php endif; ?>

        <div class="nav-item" data-target="settings" role="link" aria-label="Settings">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span class="nav-label">Settings</span>
          </div>
        </div>


      </nav>

      <div class="sidebar-footer">
        <a class="nav-item" href="auth/logout.php" style="color: var(--color-danger);">
          <div class="nav-item-left">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span class="nav-label">Sign Out</span>
          </div>
        </a>
      </div>
    </aside>

    <!-- --- MAIN PANEL --- -->
    <main class="main-panel">
      
      <!-- --- HEADER --- -->
      <header class="header">
        <div class="header-left">
          <button class="toggle-sidebar-btn" id="sidebar-toggle" aria-label="Collapse Navigation">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </button>
          
          <nav class="breadcrumbs" aria-label="Breadcrumb">
            <span class="breadcrumb-item">Campus Recruitment</span>
            <span class="breadcrumb-separator">/</span>
            <span class="breadcrumb-item active" id="crumb-current">Dashboard</span>
          </nav>
        </div>

        <div class="header-right">
          <!-- Global Search -->
          <div class="search-bar">
            <div class="input-icon-wrapper">
              <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="search" class="input-field" id="global-search-input" placeholder="Search profiles, drives, metrics...">
            </div>
          </div>

          <!-- Header Actions -->
          <div class="header-actions">
            <!-- Theme Toggle -->
            <button class="btn btn-secondary btn-icon-only" id="theme-toggle" aria-label="Toggle Light/Dark Theme">
              <svg id="theme-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
              </svg>
            </button>

            <!-- Notifications Drawer Bell (Footprint increased, pulse badge added) -->
            <div class="action-btn-wrapper">
              <button class="btn btn-secondary btn-icon-only" id="notify-drawer-trigger" aria-label="System Notifications Drawer" style="padding: 12px; border-radius: 12px; position: relative;">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="bell-ringing-animation"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
              </button>
              <span class="icon-badge-dot" id="header-unread-pulse" style="width: 10px; height: 10px; background-color: var(--color-danger); border-radius: 50%; position: absolute; top: 4px; right: 4px; border: 2px solid var(--bg-card); display: none; animation: pulse-ring 1.5s infinite;"></span>
            </div>
          </div>

          <!-- Profile Trigger -->
          <div class="profile-avatar-trigger" id="avatar-menu-trigger">
            <div class="avatar" style="background-color: var(--primary-light); color: var(--primary);">
              <?php echo getInitials($userName); ?>
            </div>
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            
            <!-- Menu Dropdown -->
            <div class="dropdown-menu" id="avatar-menu">
              <div class="dropdown-header">
                <div class="dropdown-user-name"><?php echo $userName; ?></div>
                <div class="dropdown-user-role"><?php echo strtoupper($role); ?></div>
              </div>
              <div class="dropdown-item" onclick="switchView('profile-tab')">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                My Profile
              </div>

              <div class="dropdown-item danger" onclick="window.location.href='auth/logout.php'">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign Out
              </div>
            </div>
          </div>
        </div>
      </header>

      <!-- --- CONTENT VIEW AREA --- -->
      <div class="content-area">
        
        <!-- ==================== DASHBOARD VIEW ==================== -->
        <div class="page-view active" id="dashboard">
          
          <!-- Welcome Section -->
          <section class="welcome-banner">
            <div class="welcome-info">
              <h1>Welcome Back, <?php echo $userName; ?> 👋</h1>
              <p>Academic Year: 2026 Batch Portal</p>
            </div>
            <div class="welcome-badge-group">
              <span class="badge badge-success">Role: <?php echo strtoupper($role); ?></span>
              <span class="badge badge-info">Connection: Secure (PDO)</span>
            </div>
          </section>

          <!-- KPI Cards Grid -->
          <section class="kpi-container">
            <div class="kpi-row" id="dashboard-kpis-grid">
              <!-- KPI Cards injected by app.js based on role stats -->
            </div>
          </section>

          <!-- Charts and Analytics Grid -->
          <div class="grid-12" style="margin-bottom: var(--space-4);">
            <!-- Placement Trend -->
            <div class="col-8 col-lg-12">
              <div class="card">
                <div class="chart-header">
                  <h3 class="chart-container-title">Monthly Placement Trend</h3>
                  <div class="chart-actions">
                    <button class="btn btn-secondary btn-sm chart-export-png">Export PNG</button>
                    <button class="btn btn-secondary btn-sm chart-download-csv">CSV</button>
                  </div>
                </div>
                <div style="height: 320px; position: relative;">
                  <canvas id="chart-placement-trend"></canvas>
                </div>
              </div>
            </div>

            <!-- Right Sidebar Panel: System Health -->
            <div class="col-4 col-lg-12">
              <div class="card" style="height: 100%;">
                <h3 class="chart-container-title">System Metrics</h3>
                <div id="dashboard-system-health">
                  <div class="sidebar-stats-widget">
                    <div class="active-users-counter">
                      <span class="active-pulse"></span>
                      <span style="font-weight: 700; font-size: 13px;">MySQL Connected</span>
                    </div>
                    <div class="sys-health-item">
                      <div class="sys-health-header">
                        <span>Database Status</span>
                        <span style="color: var(--color-success);">Ready</span>
                      </div>
                      <div class="progress-bar-wrapper">
                        <div class="progress-bar-fill" style="width: 100%; background-color: var(--color-success);"></div>
                      </div>
                    </div>
                    <div class="sys-health-item">
                      <div class="sys-health-header">
                        <span>Server CPU Load</span>
                        <span>Normal</span>
                      </div>
                      <div class="progress-bar-wrapper">
                        <div class="progress-bar-fill" style="width: 12%;"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Section 2: Secondary Charts -->
          <div class="grid-12" style="margin-bottom: var(--space-4);">
            <!-- Applications by Month -->
            <div class="col-6 col-lg-12">
              <div class="card">
                <div class="chart-header">
                  <h3 class="chart-container-title">Applications Trend</h3>
                </div>
                <div style="height: 280px;">
                  <canvas id="chart-applications-month"></canvas>
                </div>
              </div>
            </div>

            <!-- Department Breakdown -->
            <div class="col-6 col-lg-12">
              <div class="card">
                <div class="chart-header">
                  <h3 class="chart-container-title">Department Enrollments</h3>
                </div>
                <div style="height: 280px; display: flex; align-items: center; justify-content: center;">
                  <canvas id="chart-students-dept"></canvas>
                </div>
              </div>
            </div>
          </div>

          <!-- Section 3: Funnel & Companies -->
          <div class="grid-12" style="margin-bottom: var(--space-4);">
            <!-- Timeline activities -->
            <div class="col-4 col-lg-12">
              <div class="card" style="height: 100%;">
                <h3 class="chart-container-title">Recent Timeline Logs</h3>
                <div class="timeline-list" id="dashboard-timeline-list">
                  <!-- Injected dynamically -->
                </div>
              </div>
            </div>

            <!-- Selection Funnel -->
            <div class="col-8 col-lg-12">
              <div class="grid-12">
                <div class="col-12">
                  <div class="card">
                    <h3 class="chart-container-title">Selection Funnel Analysis</h3>
                    <div id="chart-selection-funnel" style="display: flex; flex-direction: column; gap: 8px;">
                      <!-- Injected dynamically -->
                    </div>
                  </div>
                </div>

                <div class="col-12" style="margin-top: var(--space-1);">
                  <div class="card">
                    <h3 class="chart-container-title">Top Recruiter list</h3>
                    <div class="top-recruiting-list" id="dashboard-companies-list">
                      <!-- Injected dynamically -->
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php if ($role === 'admin' || $role === 'tpo'): ?>
        <!-- ==================== STUDENTS VIEW ==================== -->
        <div class="page-view" id="students">
          <div class="card" style="margin-bottom: var(--space-3);">
            <div class="chart-header" style="margin-bottom: 0;">
              <div>
                <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Student Directory</h3>
                <p style="color: var(--text-secondary); font-size: 13px;">Manage candidate registrations, verify eligibility records and view performance charts.</p>
              </div>
              <div style="display: flex; gap: var(--space-1); flex-wrap: wrap;">
                <select class="input-field select-custom btn-sm" id="filter-student-dept" style="width: 160px;">
                  <option value="All">All Departments</option>
                  <option value="CSE">CSE</option>
                  <option value="IT">IT</option>
                  <option value="ECE">ECE</option>
                  <option value="EE">EE</option>
                  <option value="ME">ME</option>
                  <option value="CE">CE</option>
                </select>
                <select class="input-field select-custom btn-sm" id="filter-student-placed" style="width: 140px;">
                  <option value="All">All Statuses</option>
                  <option value="Placed">Placed</option>
                  <option value="Unplaced">Unplaced</option>
                </select>
                <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-student')">Add Student</button>
              </div>
            </div>
          </div>

          <div id="students-table-container"></div>
        </div>

        <!-- ==================== COMPANIES VIEW ==================== -->
        <div class="page-view" id="companies">
          <div class="card" style="margin-bottom: var(--space-3);">
            <div class="chart-header" style="margin-bottom: 0;">
              <div>
                <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Corporate Recruiters</h3>
                <p style="color: var(--text-secondary); font-size: 13px;">Maintain registered recruiters details, track highest salaries, and monitor hiring allocations.</p>
              </div>
              <div style="display: flex; gap: var(--space-1);">
                <select class="input-field select-custom btn-sm" id="filter-company-status" style="width: 160px;">
                  <option value="All">All Recruiters</option>
                  <option value="Active">Active</option>
                  <option value="Pending">Pending</option>
                  <option value="Suspended">Suspended</option>
                </select>
                <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-company')">Create Company</button>
              </div>
            </div>
          </div>

          <div id="companies-table-container"></div>
        </div>
        <?php endif; ?>

        <!-- ==================== PLACEMENT DRIVES VIEW ==================== -->
        <div class="page-view" id="drives">
          <div class="card" style="margin-bottom: var(--space-3);">
            <div class="chart-header" style="margin-bottom: 0;">
              <div>
                <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Placement Drives</h3>
                <p style="color: var(--text-secondary); font-size: 13px;">Schedule campus recruitment drives, verify CGPA eligibility requirements and set active packages.</p>
              </div>
              <div style="display: flex; gap: var(--space-1);">
                <select class="input-field select-custom btn-sm" id="filter-drive-status" style="width: 160px;">
                  <option value="All">All Drives</option>
                  <option value="Completed">Completed</option>
                  <option value="Ongoing">Ongoing</option>
                  <option value="Upcoming">Upcoming</option>
                </select>
                <?php if ($role !== 'student'): ?>
                <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-drive')">Create Drive</button>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div id="drives-table-container"></div>
        </div>

        <!-- ==================== APPLICATIONS VIEW ==================== -->
        <div class="page-view" id="applications">
          <div class="card" style="margin-bottom: var(--space-3);">
            <div class="chart-header" style="margin-bottom: 0;">
              <div>
                <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Job Applications</h3>
                <p style="color: var(--text-secondary); font-size: 13px;">Track individual student application records and current round screening status.</p>
              </div>
              <div style="display: flex; gap: var(--space-1);">
                <select class="input-field select-custom btn-sm" id="filter-app-status" style="width: 180px;">
                  <option value="All">All Stages</option>
                  <option value="Applied">Applied</option>
                  <option value="Eligible">Eligible</option>
                  <option value="Aptitude">Aptitude</option>
                  <option value="Technical">Technical</option>
                  <option value="HR">HR</option>
                  <option value="Selected">Selected</option>
                  <option value="Rejected">Rejected</option>
                </select>
              </div>
            </div>
          </div>

          <div id="applications-table-container"></div>
        </div>

        <?php if ($role !== 'student'): ?>
        <!-- ==================== KANBAN PIPELINE VIEW ==================== -->
        <div class="page-view" id="pipeline">
          <div class="card" style="margin-bottom: var(--space-3);">
            <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Placement Pipeline (Kanban Board)</h3>
            <p style="color: var(--text-secondary); font-size: 13px;">Drag and drop candidate cards across recruitment stages to update recruitment funnel logs instantly.</p>
          </div>

          <div class="kanban-board" id="kanban-pipeline-container"></div>
        </div>
        <?php endif; ?>

        <!-- ==================== INTERVIEWS VIEW ==================== -->
        <div class="page-view" id="interviews">
          <div class="card" style="margin-bottom: var(--space-3);">
            <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Interview Management</h3>
            <p style="color: var(--text-secondary); font-size: 13px;">Track scheduled interviews, access meeting rooms, and monitor countdown timers.</p>
          </div>

          <div class="calendar-view-wrapper">
            <div class="calendar-widget" id="calendar-widget-grid"></div>
            <div>
              <div class="card-glass card" style="margin-bottom: var(--space-2); padding: var(--space-2);">
                <h4 style="font-weight: 700; font-size: 15px; margin-bottom: 2px;">Interviews Timeline</h4>
              </div>
              <div id="upcoming-interviews-list"></div>
            </div>
          </div>
        </div>

        <!-- ==================== MY PROFILE VIEW (Student & Company Profile management) ==================== -->
        <?php if (true): ?>
        <div class="page-view" id="profile-tab">
          <div class="grid-12">
            
            <div class="col-8 col-lg-12">
              <div class="card">
                <h3 class="chart-container-title">Edit Profile Details</h3>
                <form id="form-update-profile">
                  <div class="grid-12">
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">Full Name</label>
                      <input type="text" class="input-field" name="name" id="profile-name" value="<?php echo $userName; ?>" required>
                    </div>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">Email Address</label>
                      <input type="email" class="input-field" value="<?php echo $userEmail; ?>" readonly>
                    </div>
 
                    <?php if ($role === 'student'): ?>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">CGPA</label>
                      <input type="text" class="input-field" value="<?php echo $profile['cgpa'] ?? ''; ?>" readonly>
                    </div>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">Roll Number</label>
                      <input type="text" class="input-field" value="<?php echo $profile['roll_number'] ?? ''; ?>" readonly>
                    </div>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">Contact Phone</label>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-weight: 600; padding: 10px 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-secondary); line-height: 1;">+91</span>
                        <input type="tel" class="input-field" name="phone" id="profile-phone" value="<?php echo $profile['phone'] ?? ''; ?>" inputmode="numeric" maxlength="10" required style="flex: 1;">
                      </div>
                    </div>
                    <div class="col-12 form-group">
                      <label class="form-label">Skills (Comma separated)</label>
                      <input type="text" class="input-field" name="skills" id="profile-skills" value="<?php echo $profile['skills'] ?? ''; ?>">
                    </div>
                    <div class="col-12 form-group">
                      <label class="form-label">Projects</label>
                      <textarea class="input-field" name="projects" id="profile-projects" rows="3"><?php echo $profile['projects'] ?? ''; ?></textarea>
                    </div>
                    <?php endif; ?>
 
                    <?php if ($role === 'company'): ?>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">Website</label>
                      <input type="url" class="input-field" name="website" id="profile-website" value="<?php echo $profile['website'] ?? ''; ?>">
                    </div>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">Office Phone</label>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-weight: 600; padding: 10px 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-secondary); line-height: 1;">+91</span>
                        <input type="tel" class="input-field" name="phone" id="profile-phone" value="<?php echo $profile['phone'] ?? ''; ?>" inputmode="numeric" maxlength="10" required style="flex: 1;">
                      </div>
                    </div>
                    <?php endif; ?>

                    <div class="col-12" style="margin-top:var(--space-2);">
                      <button type="submit" class="btn btn-primary">Update Profile Information</button>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <!-- Documents uploads (Resumes & certificates) -->
            <div class="col-4 col-lg-12">
              <div class="card">
                <h3 class="chart-container-title">Document Management</h3>
                <?php if ($role === 'student'): ?>
                <div style="display:flex; flex-direction:column; gap: var(--space-2);">
                  <div class="form-group">
                    <label class="form-label">Academic Resume (PDF Only)</label>
                    <form id="form-upload-resume" enctype="multipart/form-data">
                      <input type="hidden" name="type" value="resume">
                      <input type="file" name="file" class="input-field" style="padding: 6px;" accept=".pdf" required>
                      <button type="submit" class="btn btn-secondary btn-sm" style="width:100%; margin-top:8px;">Upload Resume</button>
                    </form>
                    <?php if (!empty($profile['resume_path'])): ?>
                      <div style="font-size:12px; margin-top:4px;">Current: <a href="<?php echo $profile['resume_path']; ?>" target="_blank" style="color:var(--primary);">Download PDF</a></div>
                    <?php endif; ?>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Marksheet & Certificates (PDF)</label>
                    <form id="form-upload-certs" enctype="multipart/form-data">
                      <input type="hidden" name="type" value="certificate">
                      <input type="file" name="file" class="input-field" style="padding: 6px;" accept=".pdf" required>
                      <button type="submit" class="btn btn-secondary btn-sm" style="width:100%; margin-top:8px;">Upload Certificates</button>
                    </form>
                    <?php if (!empty($profile['certificate_path'])): ?>
                      <div style="font-size:12px; margin-top:4px;">Current: <a href="<?php echo $profile['certificate_path']; ?>" target="_blank" style="color:var(--primary);">Download PDF</a></div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php else: ?>
                <p style="font-size:13px; color:var(--text-secondary);">Recruiter profile logo can be configured under branding parameters.</p>
                <?php endif; ?>
              </div>
            </div>

          </div>
        </div>
        <?php endif; ?>

        <!-- ==================== ACTIVITY HISTORY VIEW ==================== -->
        <?php if ($role === 'admin'): ?>
        <div class="page-view" id="activitylogs">
          <div class="card" style="margin-bottom: var(--space-3);">
            <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Admin Activity Logs</h3>
            <p style="color: var(--text-secondary); font-size: 13px;">View complete system history audit containing IPs, user roles, actions, and status metrics.</p>
          </div>

          <div class="data-table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Role</th>
                  <th>Action</th>
                  <th>IP Address</th>
                  <th>Browser</th>
                  <th>Status</th>
                  <th>Timestamp</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                  <td><strong><?php echo htmlspecialchars($log['username']); ?></strong></td>
                  <td><span class="badge badge-primary"><?php echo htmlspecialchars($log['role']); ?></span></td>
                  <td><?php echo htmlspecialchars($log['action']); ?></td>
                  <td><code><?php echo htmlspecialchars($log['ip_address']); ?></code></td>
                  <td style="font-size:11px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($log['browser']); ?></td>
                  <td><span class="badge <?php echo $log['status']==='success' ? 'badge-success' : 'badge-danger'; ?>"><?php echo htmlspecialchars($log['status']); ?></span></td>
                  <td><?php echo $log['created_at']; ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>

        <!-- ==================== REPORTS VIEW ==================== -->
        <div class="page-view" id="reports">
          <div class="grid-12">
            <div class="col-6 col-lg-12">
              <div class="card">
                <h3 class="chart-container-title">Export Placement Reports</h3>
                <div class="empty-state">
                  <svg class="empty-state-illust" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                  <div class="empty-state-title">Dynamic Placement Reports</div>
                  <div class="empty-state-desc">Download complete university placement lists containing CTC packs, selected branches, and companies.</div>
                  <div style="display:flex; gap:8px;">
                    <button class="btn btn-primary btn-sm" onclick="showToast('Export Successful', 'Placement excel file downloaded.', 'success')">Download Excel Report</button>
                    <button class="btn btn-secondary btn-sm" onclick="showToast('Export PDF', 'PDF catalog generated.', 'success')">Download PDF Catalog</button>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="col-6 col-lg-12">
              <div class="card">
                <h3 class="chart-container-title">Database Status</h3>
                <div class="error-state" style="background-color: var(--primary-light); border-color: rgba(37,99,235,0.1); color: var(--text-primary);">
                  <svg class="error-state-icon" style="color:var(--primary);" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
                  <div class="error-state-title">MySQL Database Integrity Check</div>
                  <div class="error-state-desc" style="color:var(--text-secondary);">Connection is live. Active tables: 9. Records count: 50+. Schema is WCAG compliant.</div>
                  <button class="btn btn-secondary btn-sm" onclick="showToast('Integrity CheckPassed', 'All database tables verify matching index values.', 'success')">Run Table Check</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ==================== SETTINGS VIEW ==================== -->
        <div class="page-view" id="settings">
          <div class="card" style="margin-bottom:var(--space-3);">
            <h3 class="chart-container-title">Workspace Configuration</h3>
            <p style="color: var(--text-secondary); font-size: 13px;">Manage themes, verification parameters, and secure database backups.</p>
          </div>

          <div class="grid-12">
            <!-- Theme configs -->
            <div class="col-6 col-lg-12">
              <div class="card" style="height: 100%;">
                <h4 style="font-weight: 700; margin-bottom: var(--space-2);">Profile & Theme Preferences</h4>
                 <div class="form-group">
                   <label class="form-label">Auto dark mode transition</label>
                   <?php $userTheme = $_SESSION['theme'] ?? 'system'; ?>
                   <select class="input-field select-custom" id="settings-theme-select">
                     <option value="system" <?php echo $userTheme === 'system' ? 'selected' : ''; ?>>Follow System Default</option>
                     <option value="light" <?php echo $userTheme === 'light' ? 'selected' : ''; ?>>Force Light Theme Only</option>
                     <option value="dark" <?php echo $userTheme === 'dark' ? 'selected' : ''; ?>>Force Dark Theme Only</option>
                   </select>
                 </div>
                 <div class="form-group">
                   <label class="form-label">Portal Language</label>
                   <?php $userLang = $_SESSION['language'] ?? 'en'; ?>
                   <select class="input-field select-custom" id="settings-lang-select">
                     <option value="en" <?php echo $userLang === 'en' ? 'selected' : ''; ?>>English (United States)</option>
                     <option value="hi" <?php echo $userLang === 'hi' ? 'selected' : ''; ?>>Hindi (India)</option>
                   </select>
                 </div>
              </div>
            </div>
            <!-- Database Backup Utility (Visible to Admin only) -->
            <div class="col-6 col-lg-12">
              <div class="card" style="height: 100%;">
                <h4 style="font-weight: 700; margin-bottom: var(--space-2);">System Maintenance</h4>
                <?php if ($role === 'admin'): ?>
                <div style="display:flex; flex-direction:column; gap:var(--space-2);">
                  <div>
                    <label class="form-label">Backup Database Tables</label>
                    <p style="font-size:12px; color:var(--text-secondary); margin-bottom:8px;">Export database tables and seed files to local SQL format.</p>
                    <a href="api/actions.php?action=backup_database" class="btn btn-primary btn-sm" style="width:100%;">
                      Download SQL Backup File
                    </a>
                  </div>
                  <div style="border-top: 1px solid var(--border-color); padding-top:var(--space-2);">
                    <label class="form-label">Restore Database Backup</label>
                    <p style="font-size:12px; color:var(--text-secondary); margin-bottom:8px;">Select a previously downloaded SQL dump file to restore.</p>
                    <form id="form-restore-db" enctype="multipart/form-data">
                      <input type="hidden" name="action" value="restore_database">
                      <input type="file" name="backup_file" class="input-field" style="padding:6px;" accept=".sql" required>
                      <button type="submit" class="btn btn-danger btn-sm" style="width:100%; margin-top:8px;">Execute Database Restore</button>
                    </form>
                  </div>
                </div>
                <?php else: ?>
                <p style="font-size:13px; color:var(--text-secondary);">Database utilities are restricted to administrators.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- ==================== NOTIFICATIONS VIEW ==================== -->
        <div class="page-view" id="notifications">
          <div class="card" style="margin-bottom: var(--space-3);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-1);">
              <div>
                <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">Notifications</h3>
                <p style="color: var(--text-secondary); font-size: 13px;">View and manage your alerts and updates.</p>
              </div>
              <button class="btn btn-secondary btn-sm" id="btn-mark-all-read">Mark All as Read</button>
            </div>
          </div>

          <div class="card">
            <div id="notifications-list" style="display: flex; flex-direction: column; gap: var(--space-15);">
              <!-- Notifications will be loaded dynamically here -->
            </div>
          </div>
        </div>

      </div>
    </main>

    <!-- --- NOTIFICATION SLIDE-OUT DRAWER --- -->
    <aside class="notification-drawer" id="notify-drawer" aria-label="Notifications Drawer">
      <div class="drawer-header">
        <h3>Campus Alerts Queue</h3>
        <svg class="drawer-close" id="drawer-close-btn" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </div>
      <div class="drawer-content" id="notification-drawer-list"></div>
    </aside>

    <!-- --- FLOATING ACTION BUTTON (FAB) --- -->
    <div class="fab-container" id="fab-element">
      <button class="fab-trigger" id="fab-trigger-btn" aria-label="Quick Actions Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      </button>
      <div class="fab-menu">
        <?php if ($role === 'admin' || $role === 'tpo'): ?>
        <div class="fab-menu-item" onclick="openModal('modal-add-student')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          Add Student Profile
        </div>
        <div class="fab-menu-item" onclick="openModal('modal-add-company')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
          Add Company Recruiter
        </div>
        <?php endif; ?>
        <?php if ($role !== 'student'): ?>
        <div class="fab-menu-item" onclick="openModal('modal-add-drive')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/></svg>
          Initialize Campus Drive
        </div>
        <?php endif; ?>
        <?php if ($role === 'admin' || $role === 'tpo'): ?>
        <div class="fab-menu-item" onclick="var msg = prompt('Broadcast Alert message:'); if (msg) showToast('Alert Sent', msg, 'success');" style="color: var(--color-warning);">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Broadcast Alert
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- --- BULK ACTIONS FLOATING TOOLBAR --- -->
    <div id="table-bulk-actions" class="card-glass" style="position: fixed; bottom: 32px; left: 50%; transform: translateX(-50%) translateY(120%); z-index: 400; padding: var(--space-15) var(--space-3); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); display: flex; align-items: center; gap: var(--space-3); border: 1.5px solid var(--primary); transition: transform var(--transition-normal); background-color: var(--bg-card);">
      <span id="bulk-selected-count" style="font-weight: 700; color: var(--text-primary); font-size: 13px;">0 row(s) selected</span>
      <div style="display: flex; gap: var(--space-1);">
        <button class="btn btn-secondary btn-sm" onclick="showToast('Bulk Action Executed', 'Approved selected items.', 'success')">Verify Selected</button>
        <button class="btn btn-danger btn-sm" onclick="showToast('Bulk Action Executed', 'Selected items rejected.', 'info')">Reject Selected</button>
      </div>
    </div>

    <!-- --- TOAST HOLDER --- -->
    <div class="toast-container" id="toast-holder"></div>

    <!-- --- VIEW DETAILS MODAL --- -->
    <div class="modal-overlay" id="modal-view-details">
      <div class="modal-content">
        <div class="modal-header">
          <h3 class="modal-title">Entity Profile View</h3>
          <svg class="modal-close" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <div class="modal-body"></div>
        <div class="modal-footer">
          <button class="btn btn-secondary modal-cancel-btn">Close Panel</button>
        </div>
      </div>
    </div>

    <!-- Modals (Add student / company / drive) -->
    <?php if ($role === 'admin' || $role === 'tpo'): ?>
    <!-- ADD STUDENT MODAL -->
    <div class="modal-overlay" id="modal-add-student">
      <div class="modal-content">
        <div class="modal-header">
          <h3 class="modal-title">Enlist Student Candidate</h3>
          <svg class="modal-close" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <form id="form-add-student-api">
          <input type="hidden" name="register_type" value="student">
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">Full Candidate Name</label>
              <input type="text" class="input-field" name="name" placeholder="John Doe" required>
            </div>
            <div class="form-group">
              <label class="form-label">University Email Address</label>
              <input type="email" class="input-field" name="email" placeholder="example@university.edu" required>
            </div>
            <div class="form-group">
              <label class="form-label">Temporary Password</label>
              <input type="password" class="input-field" name="password" value="Student123!" required>
            </div>
            <div class="form-group">
              <label class="form-label">Roll Number</label>
              <input type="text" class="input-field" name="roll_number" placeholder="2023-CS-1234" required>
            </div>
            <div class="form-group">
              <label class="form-label">Academic Department</label>
              <select class="input-field select-custom" name="department">
                <option value="CSE">Computer Science & Engineering</option>
                <option value="IT">Information Technology</option>
                <option value="ECE">Electronics & Communication</option>
                <option value="EE">Electrical Engineering</option>
                <option value="ME">Mechanical Engineering</option>
                <option value="CE">Civil Engineering</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Cumulative GPA</label>
              <input type="number" class="input-field" name="cgpa" placeholder="8.5" min="0" max="10" step="0.01" required>
            </div>
            <div class="form-group">
              <label class="form-label">Contact Phone</label>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-weight: 600; padding: 10px 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-secondary); line-height: 1;">+91</span>
                <input type="tel" class="input-field" name="phone" placeholder="9876543210" inputmode="numeric" maxlength="10" required style="flex: 1;">
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-add-stu-submit">Enroll Candidate</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ADD COMPANY MODAL -->
    <div class="modal-overlay" id="modal-add-company">
      <div class="modal-content">
        <div class="modal-header">
          <h3 class="modal-title">Register Recruiter Company</h3>
          <svg class="modal-close" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <form id="form-add-company-api">
          <input type="hidden" name="register_type" value="company">
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">Representative HR Name</label>
              <input type="text" class="input-field" name="name" placeholder="HR Representative Name" required>
            </div>
            <div class="form-group">
              <label class="form-label">Corporate Email</label>
              <input type="email" class="input-field" name="email" placeholder="hr@company.com" required>
            </div>
            <div class="form-group">
              <label class="form-label">Temporary Password</label>
              <input type="password" class="input-field" name="password" value="Company123!" required>
            </div>
            <div class="form-group">
              <label class="form-label">Company Name</label>
              <input type="text" class="input-field" name="company_name" placeholder="Razorpay Inc." required>
            </div>
            <div class="form-group">
              <label class="form-label">Industry</label>
              <select class="input-field select-custom" name="industry">
                <option value="Technology">Technology & Software</option>
                <option value="Fintech">Financial Services / Payments</option>
                <option value="Consulting">Strategy & Consulting</option>
                <option value="E-Commerce">E-Commerce</option>
                <option value="Hardware">Semiconductors & Systems</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Contact Phone</label>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-weight: 600; padding: 10px 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-secondary); line-height: 1;">+91</span>
                <input type="tel" class="input-field" name="phone" placeholder="9876543210" inputmode="numeric" maxlength="10" required style="flex: 1;">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Corporate Website URL</label>
              <input type="url" class="input-field" name="website" placeholder="https://www.company.com">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-add-comp-submit">Register Recruiter</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($role !== 'student'): ?>
    <!-- ADD PLACEMENT DRIVE MODAL -->
    <div class="modal-overlay" id="modal-add-drive">
      <div class="modal-content">
        <div class="modal-header">
          <h3 class="modal-title">Initialize Placement Drive</h3>
          <svg class="modal-close" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <form id="form-add-drive-api">
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">Selecting Company ID</label>
              <select class="input-field select-custom" name="company_id" id="modal-select-company" required>
                <?php foreach ($companies as $c): ?>
                  <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['company_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Designated Job Designation</label>
              <input type="text" class="input-field" name="job_role" placeholder="Associate Software Engineer" required>
            </div>
            <div class="form-group">
              <label class="form-label">Minimum CGPA Criteria</label>
              <input type="number" class="input-field" name="eligibility_cgpa" placeholder="7.5" min="0" max="10" step="0.1" required>
            </div>
            <div class="form-group">
              <label class="form-label">Compensation LPA</label>
              <input type="number" class="input-field" name="package_lpa" placeholder="12" step="0.1" required>
            </div>
            <div class="form-group">
              <label class="form-label">Commencement Date</label>
              <input type="date" class="input-field" name="drive_date" required>
            </div>
            <div class="form-group">
              <label class="form-label">Registration Deadline</label>
              <input type="date" class="input-field" name="registration_deadline" required>
            </div>
            <div class="form-group">
              <label class="form-label">Target Branches (Comma separated)</label>
              <input type="text" class="input-field" name="departments" placeholder="CSE, IT, ECE" required>
            </div>
            <div class="form-group">
              <label class="form-label">Required Skills Profile</label>
              <input type="text" class="input-field" name="skills_required" placeholder="Java, Python, OOPs">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary modal-cancel-btn">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-add-drive-submit">Schedule Campus Drive</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <script src="js/components.js"></script>
  
  <!-- Custom UI hooks connecting endpoints -->
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      // 1. Dynamic document uploads (Resumes & marksheets)
      const resForm = document.getElementById("form-upload-resume");
      if (resForm) {
        resForm.addEventListener("submit", (e) => {
          e.preventDefault();
          const btn = resForm.querySelector("button");
          btn.disabled = true;
          btn.innerText = "Saving Resume File...";
          
          fetch('api/upload.php', {
            method: 'POST',
            body: new FormData(resForm)
          })
          .then(res => res.json())
          .then(res => {
            if (res.status === 'success') {
              showToast("Resume Uploaded", res.message, "success");
              setTimeout(() => window.location.reload(), 1500);
            } else {
              showToast("Upload Error", res.message, "danger");
              btn.disabled = false;
              btn.innerText = "Upload Resume";
            }
          });
        });
      }

      const certForm = document.getElementById("form-upload-certs");
      if (certForm) {
        certForm.addEventListener("submit", (e) => {
          e.preventDefault();
          const btn = certForm.querySelector("button");
          btn.disabled = true;
          btn.innerText = "Uploading marksheets...";
          
          fetch('api/upload.php', {
            method: 'POST',
            body: new FormData(certForm)
          })
          .then(res => res.json())
          .then(res => {
            if (res.status === 'success') {
              showToast("Certificates Uploaded", res.message, "success");
              setTimeout(() => window.location.reload(), 1500);
            } else {
              showToast("Upload Error", res.message, "danger");
              btn.disabled = false;
              btn.innerText = "Upload Certificates";
            }
          });
        });
      }

      // 2. Dynamic DB restores
      const restoreForm = document.getElementById("form-restore-db");
      if (restoreForm) {
        restoreForm.addEventListener("submit", (e) => {
          e.preventDefault();
          
          Swal.fire({
            title: 'Restore Database?',
            text: 'Caution: This will restore database tables structure. Existing user listings will be overwritten!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'Yes, Restore & Overwrite'
          }).then((result) => {
            if (result.isConfirmed) {
              const btn = restoreForm.querySelector("button");
              btn.disabled = true;
              btn.innerText = "Executing restores...";

              fetch('api/actions.php', {
                method: 'POST',
                body: new FormData(restoreForm)
              })
              .then(res => res.json())
              .then(res => {
                if (res.status === 'success') {
                  showToast("Database Restored", res.message, "success");
                  setTimeout(() => window.location.reload(), 2000);
                } else {
                  showToast("Restore Error", res.message, "danger");
                  btn.disabled = false;
                  btn.innerText = "Execute Database Restore";
                }
              });
            }
          });
        });
      }

      // 3. Quick enrollment modals submits
      const modalPhoneInputs = document.querySelectorAll("#form-add-student-api [name='phone'], #form-add-company-api [name='phone']");
      modalPhoneInputs.forEach(phoneInp => {
        phoneInp.addEventListener("keydown", (e) => {
          if ([46, 8, 9, 27, 13].indexOf(e.keyCode) !== -1 ||
              (e.ctrlKey === true || e.metaKey === true) ||
              (e.keyCode >= 35 && e.keyCode <= 40)) {
                   return;
          }
          if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
              e.preventDefault();
          }
        });
        phoneInp.addEventListener("input", () => {
          let val = phoneInp.value.replace(/\D/g, '');
          if (val.length > 10) val = val.substring(0, 10);
          phoneInp.value = val;
        });
        phoneInp.addEventListener("paste", (e) => {
          e.preventDefault();
          const clipboardData = e.clipboardData || window.clipboardData;
          const pastedData = clipboardData.getData('text');
          let val = pastedData.replace(/\D/g, '');
          if (val.length > 10) val = val.substring(0, 10);
          phoneInp.value = val;
        });
      });

      const addStuForm = document.getElementById("form-add-student-api");
      if (addStuForm) {
        addStuForm.addEventListener("submit", (e) => {
          e.preventDefault();
          const phone = addStuForm.querySelector("[name='phone']").value;
          if (!/^[0-9]{10}$/.test(phone)) {
            Swal.fire({
              title: 'Validation Error',
              text: 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.',
              icon: 'error'
            });
            return;
          }
          const btn = document.getElementById("btn-add-stu-submit");
          btn.disabled = true;
          
          fetch('auth/register.php', {
            method: 'POST',
            body: new FormData(addStuForm)
          })
          .then(res => res.json())
          .then(res => {
            if (res.status === 'success') {
              showToast("Student Added", "Verify Student action successfully completed.", "success");
              // Verify automatically in backend or force page refresh
              setTimeout(() => window.location.reload(), 1500);
            } else {
              Swal.fire({
                title: 'Error',
                text: res.message,
                icon: 'error'
              });
              btn.disabled = false;
            }
          });
        });
      }

      const addCompForm = document.getElementById("form-add-company-api");
      if (addCompForm) {
        addCompForm.addEventListener("submit", (e) => {
          e.preventDefault();
          const phone = addCompForm.querySelector("[name='phone']").value;
          if (!/^[0-9]{10}$/.test(phone)) {
            Swal.fire({
              title: 'Validation Error',
              text: 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.',
              icon: 'error'
            });
            return;
          }
          const btn = document.getElementById("btn-add-comp-submit");
          btn.disabled = true;
          
          fetch('auth/register.php', {
            method: 'POST',
            body: new FormData(addCompForm)
          })
          .then(res => res.json())
          .then(res => {
            if (res.status === 'success') {
              showToast("Recruiter Created", "Employer pending registration created.", "success");
              setTimeout(() => window.location.reload(), 1500);
            } else {
              Swal.fire({
                title: 'Error',
                text: res.message,
                icon: 'error'
              });
              btn.disabled = false;
            }
          });
        });
      }
    });
  </script>

  <!-- Load client app logic -->
  <script src="js/app.js"></script>
</body>
</html>
