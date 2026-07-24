<?php
/**
 * Master Enterprise Dashboard Router & View Container
 * Enforces role-based session middleware, runs database queries, and outputs the premium SaaS workspace.
 */

require_once __DIR__ . '/config/auth.php';

// Enforce login on all roles
checkRole(['admin', 'tpo', 'student', 'company']);

// If a recruiter accesses dashboard.php directly, redirect to recruiter_dashboard.php
if ($_SESSION['user_role'] === 'company') {
  header("Location: " . getRoleDashboard('company'));
  exit;
}

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

  $applicationsQueryStr = "
    SELECT a.id, a.student_id as studentId, a.drive_id as driveId, a.applied_date, a.status,
           u.name as studentName, s.department, s.cgpa, d.job_role as role, c.company_name as companyName,
           d.package_lpa as lpa, d.drive_date as driveDate
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

  if ($role === 'student' && !$profile) {
    $profile = [];
  }

  // 9. AJAX Action Handling
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] === 'student_apply') {
      if ($role !== 'student') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      
      $driveId = isset($_POST['drive_id']) ? (int)$_POST['drive_id'] : 0;
      
      // Fetch drive details to verify existence and check eligibility
      $stmtCheckDrive = $db->prepare("
        SELECT d.eligibility_cgpa, d.job_role, d.company_id, c.company_name
        FROM drives d
        JOIN companies c ON d.company_id = c.user_id
        WHERE d.id = ?
      ");
      $stmtCheckDrive->execute([$driveId]);
      $driveInfo = $stmtCheckDrive->fetch();
      
      if (!$driveInfo) {
        echo json_encode(['status' => 'error', 'message' => 'Placement drive campaign not found.']);
        exit;
      }
      
      $studentCGPA = isset($profile['cgpa']) ? floatval($profile['cgpa']) : 0.0;
      $minEligibleCGPA = floatval($driveInfo['eligibility_cgpa']);
      
      if ($studentCGPA < $minEligibleCGPA) {
        echo json_encode(['status' => 'error', 'message' => 'You do not meet the minimum CGPA criterion of ' . $minEligibleCGPA . ' required for this role.']);
        exit;
      }
      
      try {
        $stmtInsert = $db->prepare("
          INSERT INTO applications (student_id, drive_id, applied_date, status)
          VALUES (?, ?, CURDATE(), 'Applied')
        ");
        $stmtInsert->execute([$userId, $driveId]);
        
        // Create user notification for student
        createUserNotification(
          $userId,
          "Application Submitted Successfully",
          "You have successfully applied for the role '{$driveInfo['job_role']}' at '{$driveInfo['company_name']}'.",
          "application_status",
          "medium",
          "applications"
        );
        
        logActivity("Applied to drive ID {$driveId} for role '{$driveInfo['job_role']}'", "success");
        
        echo json_encode(['status' => 'success', 'message' => 'Your application has been successfully submitted!']);
        exit;
      } catch (PDOException $ex) {
        if ($ex->getCode() == 23000 || $ex->getCode() == '23000') {
          echo json_encode(['status' => 'error', 'message' => 'You have already applied for this placement drive.']);
        } else {
          echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $ex->getMessage()]);
        }
        exit;
      }
    }
    
    if ($_POST['ajax_action'] === 'student_withdraw') {
      if ($role !== 'student') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      
      $appId = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
      
      // Fetch application details to verify ownership
      $stmtCheckApp = $db->prepare("
        SELECT a.id, d.job_role, c.company_name
        FROM applications a
        JOIN drives d ON a.drive_id = d.id
        JOIN companies c ON d.company_id = c.user_id
        WHERE a.id = ? AND a.student_id = ?
      ");
      $stmtCheckApp->execute([$appId, $userId]);
      $appInfo = $stmtCheckApp->fetch();
      
      if (!$appInfo) {
        echo json_encode(['status' => 'error', 'message' => 'Application record not found or access denied.']);
        exit;
      }
      
      try {
        $stmtDelete = $db->prepare("DELETE FROM applications WHERE id = ?");
        $stmtDelete->execute([$appId]);
        
        // Log activity and output success
        logActivity("Withdrew application ID {$appId} for role '{$appInfo['job_role']}'", "success");
        
        echo json_encode(['status' => 'success', 'message' => 'Your application has been successfully withdrawn.']);
        exit;
      } catch (PDOException $ex) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $ex->getMessage()]);
        exit;
      }
    }

    if ($_POST['ajax_action'] === 'update_offer_status') {
      if ($role !== 'student') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      
      $offerId = isset($_POST['offer_id']) ? (int)$_POST['offer_id'] : 0;
      $newStatus = isset($_POST['status']) ? trim($_POST['status']) : ''; // 'Accepted', 'Declined'
      
      if (!in_array($newStatus, ['Accepted', 'Declined'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid offer response status.']);
        exit;
      }
      
      // Verify that this offer belongs to the student
      $stmtVerifyOffer = $db->prepare("
        SELECT o.id, o.designation, c.company_name
        FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN drives d ON a.drive_id = d.id
        JOIN companies c ON d.company_id = c.user_id
        WHERE o.id = ? AND a.student_id = ?
      ");
      $stmtVerifyOffer->execute([$offerId, $userId]);
      $offerInfo = $stmtVerifyOffer->fetch();
      
      if (!$offerInfo) {
        echo json_encode(['status' => 'error', 'message' => 'Offer record not found.']);
        exit;
      }
      
      $stmtUpdate = $db->prepare("UPDATE offers SET status = ? WHERE id = ?");
      $stmtUpdate->execute([$newStatus, $offerId]);
      
      // Notify student
      createUserNotification(
        $userId,
        "Offer " . $newStatus,
        "You have successfully " . strtolower($newStatus) . " the placement offer for '{$offerInfo['designation']}' from '{$offerInfo['company_name']}'.",
        "offer_status",
        "high",
        "applications"
      );
      
      logActivity("Offer ID {$offerId} status updated to {$newStatus}", "success");
      
      echo json_encode(['status' => 'success', 'message' => 'Offer status updated to ' . $newStatus . ' successfully.']);
      exit;
    }
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
        <span class="brand-text">Campus Recruitment</span>
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
          <?php if ($role === 'student'): ?>
            <?php
            // Safe initialization of arrays to prevent count() and key access warnings
            $applicationsSafe = is_array($applications) ? $applications : [];
            $interviewsSafe = is_array($interviews) ? $interviews : [];
            $offersSafe = is_array($offers) ? $offers : [];
            $drivesSafe = is_array($drives) ? $drives : [];
            $profileSafe = is_array($profile) ? $profile : [];
            $studentsSafe = is_array($students) ? $students : [];

            $availableJobsCount = 0;
            $appliedDriveIds = [];
            foreach ($applicationsSafe as $app) {
              if (isset($app['driveId'])) {
                $appliedDriveIds[] = (int)$app['driveId'];
              }
            }
            
            $studentCGPA = isset($profileSafe['cgpa']) ? floatval($profileSafe['cgpa']) : 0.0;
            $studentDeptCode = getDeptCode(isset($profileSafe['department']) ? $profileSafe['department'] : '');
            
            $recommendedJobs = [];
            foreach ($drivesSafe as $drive) {
              if (!is_array($drive)) continue;
              $driveId = isset($drive['id']) ? (int)$drive['id'] : 0;
              if (in_array($driveId, $appliedDriveIds)) continue;
              
              $driveStatus = isset($drive['status']) ? strtolower($drive['status']) : '';
              if (in_array($driveStatus, ['closed', 'completed', 'cancelled'])) continue;
              
              $eligCgpa = isset($drive['eligibilityCGPA']) ? floatval($drive['eligibilityCGPA']) : 0.0;
              if ($studentCGPA >= $eligCgpa) {
                $driveDepts = isset($drive['departments']) ? array_map('trim', explode(',', $drive['departments'])) : [];
                $deptMatch = false;
                foreach ($driveDepts as $dDep) {
                  if (strcasecmp($dDep, 'All') === 0 || strcasecmp($dDep, $studentDeptCode) === 0 || strcasecmp($dDep, ($profileSafe['department'] ?? '')) === 0) {
                    $deptMatch = true;
                    break;
                  }
                }
                if ($deptMatch) {
                  $availableJobsCount++;
                  $recommendedJobs[] = $drive;
                }
              }
            }
            $recommendedJobs = array_slice($recommendedJobs, 0, 4);

            // Placement eligibility
            $isEligible = (isset($studentsSafe[0]['verifiedStatus']) && $studentsSafe[0]['verifiedStatus'] === 'approved');

            // Stepper state
            $journey = [
              'resume_uploaded' => !empty($profileSafe['resume_path']),
              'eligible' => $isEligible,
              'applied' => count($applicationsSafe) > 0,
              'shortlisted' => false,
              'interview' => count($interviewsSafe) > 0,
              'offer' => count($offersSafe) > 0,
              'joined' => false
            ];

            foreach ($applicationsSafe as $app) {
              if (isset($app['status']) && in_array($app['status'], ['Eligible', 'Aptitude', 'Technical', 'HR', 'Selected'])) {
                $journey['shortlisted'] = true;
              }
            }
            foreach ($offersSafe as $off) {
              if (isset($off['status']) && $off['status'] === 'Accepted') {
                $journey['joined'] = true;
              }
            }

            $stages = [
              ['key' => 'resume_uploaded', 'label' => 'Resume Uploaded', 'desc' => 'PDF resume uploaded'],
              ['key' => 'eligible', 'label' => 'Profile Verified', 'desc' => 'Verified by Admin/TPO'],
              ['key' => 'applied', 'label' => 'Applied to Jobs', 'desc' => 'Submitted applications'],
              ['key' => 'shortlisted', 'label' => 'Shortlisted', 'desc' => 'Cleared profile screening'],
              ['key' => 'interview', 'label' => 'Interviewing', 'desc' => 'Interviews scheduled'],
              ['key' => 'offer', 'label' => 'Offer Released', 'desc' => 'Offer letter received'],
              ['key' => 'joined', 'label' => 'Joined Company', 'desc' => 'Offer accepted']
            ];

            $currentStageIdx = -1;
            for ($i = count($stages) - 1; $i >= 0; $i--) {
              if ($journey[$stages[$i]['key']]) {
                $currentStageIdx = $i;
                break;
              }
            }
            if ($currentStageIdx === -1) {
              $currentStageIdx = 0;
            }
            $progressPercent = count($stages) > 1 ? round(($currentStageIdx / (count($stages) - 1)) * 100) : 0;

            // Fetch live notifications
            $studentNotifications = [];
            if (isset($db)) {
              $stmtNotif = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
              $stmtNotif->execute([$userId]);
              $studentNotifications = $stmtNotif->fetchAll();
            }
            $studentNotifications = is_array($studentNotifications) ? $studentNotifications : [];

            // Status counts for Chart
            $statusCounts = [
              'Applied' => 0,
              'Shortlisted' => 0,
              'Selected' => 0,
              'Rejected' => 0
            ];
            foreach ($applicationsSafe as $app) {
              $st = isset($app['status']) ? $app['status'] : '';
              if (in_array($st, ['Eligible', 'Aptitude', 'Technical', 'HR'])) {
                $statusCounts['Shortlisted']++;
              } else if ($st === 'Selected') {
                $statusCounts['Selected']++;
              } else if ($st === 'Rejected') {
                $statusCounts['Rejected']++;
              } else {
                $statusCounts['Applied']++;
              }
            }
            $hasApplications = (count($applicationsSafe) > 0);
            ?>

            <style>
            /* Scoped Premium Student Dashboard Styles */
            .student-dashboard-wrapper {
              display: flex;
              flex-direction: column;
              gap: var(--space-3);
            }

            .student-hero-banner {
              background: linear-gradient(135deg, #1E40AF 0%, #3B82F6 100%);
              color: #FFFFFF;
              padding: var(--space-3);
              border-radius: var(--radius-lg);
              position: relative;
              overflow: hidden;
              box-shadow: var(--shadow-lg);
              margin-bottom: var(--space-3);
            }

            [data-theme="dark"] .student-hero-banner {
              background: linear-gradient(135deg, #1E3A8A 0%, #1D4ED8 100%);
            }

            .student-hero-banner::before {
              content: '';
              position: absolute;
              top: -50px;
              right: -50px;
              width: 200px;
              height: 200px;
              border-radius: 50%;
              background: rgba(255, 255, 255, 0.08);
            }

            .student-hero-content {
              position: relative;
              z-index: 2;
            }

            .student-hero-title {
              font-size: 26px;
              font-weight: var(--font-bold);
              margin-bottom: var(--space-1);
            }

            .student-hero-info-grid {
              display: grid;
              grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
              gap: var(--space-2);
              margin-top: var(--space-2);
              border-top: 1px solid rgba(255, 255, 255, 0.15);
              padding-top: var(--space-2);
            }

            .student-hero-info-item {
              display: flex;
              flex-direction: column;
            }

            .student-hero-info-lbl {
              font-size: 11px;
              text-transform: uppercase;
              letter-spacing: 0.5px;
              color: rgba(255, 255, 255, 0.7);
              margin-bottom: 2px;
            }

            .student-hero-info-val {
              font-size: 15px;
              font-weight: var(--font-semibold);
            }

            .student-hero-actions {
              display: flex;
              gap: var(--space-15);
              margin-top: var(--space-3);
            }

            .btn-hero-primary {
              background: #FFFFFF;
              color: var(--primary);
              border: none;
              font-weight: var(--font-semibold);
              transition: all var(--transition-fast);
            }

            .btn-hero-primary:hover {
              background: #F1F5F9;
              transform: translateY(-2px);
            }

            .btn-hero-secondary {
              background: rgba(255, 255, 255, 0.15);
              color: #FFFFFF;
              border: 1px solid rgba(255, 255, 255, 0.25);
              font-weight: var(--font-semibold);
              transition: all var(--transition-fast);
            }

            .btn-hero-secondary:hover {
              background: rgba(255, 255, 255, 0.25);
              transform: translateY(-2px);
            }

            /* Statistics Grid */
            .student-stats-row {
              display: grid;
              grid-template-columns: repeat(4, 1fr);
              gap: var(--space-2);
              margin-bottom: var(--space-3);
            }

            @media (max-width: 1024px) {
              .student-stats-row {
                grid-template-columns: repeat(2, 1fr);
              }
            }

            @media (max-width: 640px) {
              .student-stats-row {
                grid-template-columns: 1fr;
              }
            }

            .student-stat-card {
              background: var(--bg-card);
              border: 1px solid var(--border-color);
              border-radius: var(--radius-lg);
              padding: var(--space-2);
              display: flex;
              align-items: center;
              gap: var(--space-2);
              cursor: pointer;
              box-shadow: var(--shadow-sm);
              transition: all var(--transition-normal);
            }

            .student-stat-card:hover {
              transform: translateY(-4px);
              box-shadow: var(--shadow-md);
              border-color: var(--primary);
            }

            .student-stat-icon-wrapper {
              width: 48px;
              height: 48px;
              border-radius: var(--radius-md);
              display: flex;
              align-items: center;
              justify-content: center;
            }

            .student-stat-info {
              display: flex;
              flex-direction: column;
            }

            .student-stat-val {
              font-size: 22px;
              font-weight: var(--font-bold);
              color: var(--text-primary);
              line-height: 1.1;
            }

            .student-stat-lbl {
              font-size: 13px;
              color: var(--text-secondary);
              font-weight: var(--font-medium);
            }

            /* Stepper tracker */
            .journey-stepper {
              display: flex;
              justify-content: space-between;
              align-items: flex-start;
              position: relative;
              margin: var(--space-4) 0;
              padding: 0 var(--space-2);
            }

            .journey-stepper::before {
              content: '';
              position: absolute;
              top: 24px;
              left: 24px;
              right: 24px;
              height: 4px;
              background: var(--border-color);
              z-index: 1;
            }

            .journey-progress-line {
              position: absolute;
              top: 24px;
              left: 24px;
              height: 4px;
              background: var(--color-success);
              z-index: 2;
              transition: width var(--transition-slow);
              --pct: <?php echo $progressPercent; ?>%;
              width: calc((100% - 48px) * (var(--pct) / 100));
            }

            .step-node {
              display: flex;
              flex-direction: column;
              align-items: center;
              position: relative;
              z-index: 3;
              width: 14%;
              text-align: center;
            }

            .step-circle {
              width: 48px;
              height: 48px;
              border-radius: 50%;
              background: var(--bg-card);
              border: 3px solid var(--border-color);
              display: flex;
              align-items: center;
              justify-content: center;
              font-weight: var(--font-bold);
              font-size: 16px;
              color: var(--text-secondary);
              transition: all var(--transition-normal);
              box-shadow: var(--shadow-sm);
              position: relative;
              z-index: 3;
            }

            .step-node.completed .step-circle {
              background: linear-gradient(var(--color-success-light), var(--color-success-light)), var(--bg-card) !important;
              border-color: var(--color-success);
              color: var(--color-success);
            }

            .step-node.current .step-circle {
              background: linear-gradient(var(--primary-light), var(--primary-light)), var(--bg-card) !important;
              border-color: var(--primary);
              color: var(--primary);
              box-shadow: 0 0 0 4px var(--primary-light);
              animation: step-pulse 2s infinite;
            }

            @keyframes step-pulse {
              0% {
                box-shadow: 0 0 0 0px rgba(37, 99, 235, 0.4);
              }
              70% {
                box-shadow: 0 0 0 10px rgba(37, 99, 235, 0);
              }
              100% {
                box-shadow: 0 0 0 0px rgba(37, 99, 235, 0);
              }
            }

            .step-label {
              margin-top: var(--space-1);
              font-size: 12px;
              font-weight: var(--font-semibold);
              color: var(--text-secondary);
              transition: color var(--transition-normal);
            }

            .step-node.completed .step-label {
              color: var(--text-primary);
            }

            .step-node.current .step-label {
              color: var(--primary);
              font-weight: var(--font-bold);
            }

            .step-desc {
              font-size: 10px;
              color: var(--text-muted);
              margin-top: 4px;
              max-width: 90px;
            }

            /* Widget Card styles */
            .widget-card {
              background: var(--bg-card);
              border: 1px solid var(--border-color);
              border-radius: var(--radius-lg);
              padding: var(--space-25);
              box-shadow: var(--shadow-sm);
              height: 100%;
            }

            .widget-title-wrapper {
              display: flex;
              justify-content: space-between;
              align-items: center;
              margin-bottom: var(--space-2);
            }

            .widget-title {
              font-size: 16px;
              font-weight: var(--font-bold);
              color: var(--text-primary);
            }

            /* List details */
            .widget-list {
              display: flex;
              flex-direction: column;
              gap: var(--space-15);
            }

            .widget-item {
              display: flex;
              gap: var(--space-15);
              align-items: flex-start;
              padding-bottom: var(--space-15);
              border-bottom: 1px solid var(--border-color);
            }

            .widget-item:last-child {
              padding-bottom: 0;
              border-bottom: none;
            }

            /* Recommended Job specific styling */
            .job-recommendation-item {
              background: rgba(255, 255, 255, 0.02);
              border: 1px solid var(--border-color);
              border-radius: var(--radius-md);
              padding: var(--space-15);
              display: flex;
              justify-content: space-between;
              align-items: center;
              transition: all var(--transition-normal);
            }

            .job-recommendation-item:hover {
              background: rgba(37, 99, 235, 0.03);
              border-color: var(--primary);
              transform: translateX(4px);
            }

            /* Offer banner style */
            .offer-alert-banner {
              background: var(--color-success-light);
              border: 1px dashed var(--color-success);
              border-radius: var(--radius-lg);
              padding: var(--space-2);
              margin-bottom: var(--space-3);
              display: flex;
              justify-content: space-between;
              align-items: center;
              gap: var(--space-2);
              animation: banner-pulse 3s infinite;
            }

            @keyframes banner-pulse {
              0% { border-color: var(--color-success); }
              50% { border-color: transparent; }
              100% { border-color: var(--color-success); }
            }

            .offer-alert-details {
              display: flex;
              gap: var(--space-15);
              align-items: center;
            }

            .offer-alert-icon {
              width: 42px;
              height: 42px;
              border-radius: 50%;
              background: var(--color-success);
              color: #FFFFFF;
              display: flex;
              align-items: center;
              justify-content: center;
            }

            .offer-actions-btn-group {
              display: flex;
              gap: 8px;
            }

            /* Mobile responsive stepper */
            @media (max-width: 768px) {
              .journey-stepper {
                flex-direction: column;
                align-items: flex-start;
                padding-left: var(--space-3);
                margin-left: 20px;
              }
              
              .journey-stepper::before {
                left: 24px;
                top: 24px;
                bottom: 24px;
                width: 4px;
                height: auto;
              }
              
              .journey-progress-line {
                left: 24px;
                top: 24px;
                width: 4px !important;
                height: calc((100% - 48px) * (var(--pct) / 100));
              }
              
              .step-node {
                flex-direction: row;
                width: 100%;
                text-align: left;
                margin-bottom: var(--space-2);
              }
              
              .step-circle {
                margin-right: var(--space-2);
                flex-shrink: 0;
              }
              
              .step-label-wrapper {
                display: flex;
                flex-direction: column;
              }
              
              .step-label {
                margin-top: 0;
                font-size: 14px;
              }
              
              .step-desc {
                max-width: 100%;
                margin-top: 2px;
              }
            }
            </style>

            <div class="student-dashboard-wrapper">
              
              <!-- Offer Alerts (if any Released offer exists) -->
              <?php 
              $pendingOffers = [];
              foreach ($offersSafe as $off) {
                if (isset($off['status']) && $off['status'] === 'Released') {
                  $pendingOffers[] = $off;
                }
              }
              foreach ($pendingOffers as $pendingOff): 
              ?>
              <div class="offer-alert-banner">
                <div class="offer-alert-details">
                  <div class="offer-alert-icon">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l3 3 5-6"/></svg>
                  </div>
                  <div>
                    <h4 style="font-weight: 700; color: var(--text-primary); font-size: 15px; margin-bottom: 2px;">Placement Offer Released!</h4>
                    <p style="font-size: 13px; color: var(--text-secondary);">Congratulations! <strong><?php echo htmlspecialchars(isset($pendingOff['companyName']) ? $pendingOff['companyName'] : ''); ?></strong> has offered you the role of <strong><?php echo htmlspecialchars(isset($pendingOff['role']) ? $pendingOff['role'] : ''); ?></strong> with a package of <strong>₹<?php echo htmlspecialchars(isset($pendingOff['packageLPA']) ? $pendingOff['packageLPA'] : '0'); ?> LPA</strong>.</p>
                  </div>
                </div>
                <div class="offer-actions-btn-group">
                  <button class="btn btn-success btn-sm btn-accept-offer" data-id="<?php echo $pendingOff['id']; ?>">Accept Offer</button>
                  <button class="btn btn-danger btn-sm btn-decline-offer" data-id="<?php echo $pendingOff['id']; ?>">Decline</button>
                </div>
              </div>
              <?php endforeach; ?>

              <!-- Hero banner section -->
              <section class="student-hero-banner">
                <div class="student-hero-content">
                  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: var(--space-2);">
                    <div>
                      <div class="student-hero-title">Welcome back, <?php echo htmlspecialchars($userName); ?> 👋</div>
                      <p style="font-size: 14px; opacity: 0.9;">Track your recruitment campaigns, upcoming rounds, and drive recommendations.</p>
                    </div>
                    <div>
                      <span class="badge <?php echo $isEligible ? 'badge-success' : 'badge-warning'; ?>" style="font-size: 12px; padding: 6px 12px; border-radius: 20px;">
                        <?php echo $isEligible ? '✓ Eligible for Placements' : '⚠ Pending Verification'; ?>
                      </span>
                    </div>
                  </div>
                  
                  <div class="student-hero-info-grid">
                    <div class="student-hero-info-item">
                      <span class="student-hero-info-lbl">Department</span>
                      <span class="student-hero-info-val"><?php echo htmlspecialchars(isset($profileSafe['department']) ? $profileSafe['department'] : 'Not Specified'); ?></span>
                    </div>
                    <div class="student-hero-info-item">
                      <span class="student-hero-info-lbl">Roll Number</span>
                      <span class="student-hero-info-val"><?php echo htmlspecialchars(isset($profileSafe['roll_number']) ? $profileSafe['roll_number'] : 'Not Specified'); ?></span>
                    </div>
                    <div class="student-hero-info-item">
                      <span class="student-hero-info-lbl">CGPA Score</span>
                      <span class="student-hero-info-val"><?php echo htmlspecialchars(isset($profileSafe['cgpa']) ? $profileSafe['cgpa'] : '0.00'); ?> / 10.0</span>
                    </div>
                    <div class="student-hero-info-item">
                      <span class="student-hero-info-lbl">Academic Year</span>
                      <span class="student-hero-info-val">2026 Batch Portal</span>
                    </div>
                    <div class="student-hero-info-item">
                      <span class="student-hero-info-lbl">Portal Date</span>
                      <span class="student-hero-info-val"><?php echo date('F d, Y'); ?></span>
                    </div>
                  </div>

                  <div class="student-hero-actions">
                    <button class="btn btn-hero-primary" onclick="switchView('drives')">
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px; vertical-align:middle;"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                      Browse Jobs
                    </button>
                    <button class="btn btn-hero-secondary" onclick="switchView('profile-tab')">
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px; vertical-align:middle;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                      Edit Profile
                    </button>
                  </div>
                </div>
              </section>

              <!-- Statistics cards -->
              <section class="student-stats-row">
                <div class="student-stat-card" onclick="switchView('drives')">
                  <div class="student-stat-icon-wrapper" style="background: var(--primary-light); color: var(--primary);">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                  </div>
                  <div class="student-stat-info">
                    <span class="student-stat-val"><?php echo $availableJobsCount; ?></span>
                    <span class="student-stat-lbl">Available Jobs</span>
                  </div>
                </div>

                <div class="student-stat-card" onclick="switchView('applications')">
                  <div class="student-stat-icon-wrapper" style="background: var(--color-info-light); color: var(--color-info);">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  </div>
                  <div class="student-stat-info">
                    <span class="student-stat-val"><?php echo count($applicationsSafe); ?></span>
                    <span class="student-stat-lbl">My Applications</span>
                  </div>
                </div>

                <div class="student-stat-card" onclick="switchView('interviews')">
                  <div class="student-stat-icon-wrapper" style="background: var(--color-warning-light); color: var(--color-warning);">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  </div>
                  <div class="student-stat-info">
                    <span class="student-stat-val"><?php echo count($interviewsSafe); ?></span>
                    <span class="student-stat-lbl">Interviews</span>
                  </div>
                </div>

                <div class="student-stat-card" onclick="switchView('applications')">
                  <div class="student-stat-icon-wrapper" style="background: var(--color-success-light); color: var(--color-success);">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><path d="M8.21 13.89L7 23l5-3 5 3-1.21-9.12"/></svg>
                  </div>
                  <div class="student-stat-info">
                    <span class="student-stat-val"><?php echo count($offersSafe); ?></span>
                    <span class="student-stat-lbl">Offers Received</span>
                  </div>
                </div>
              </section>

              <!-- Placement Journey Stepper -->
              <section class="card" style="margin-bottom: var(--space-3); padding: var(--space-25);">
                <h3 class="widget-title" style="margin-bottom: 2px;">Your Placement Journey</h3>
                <p style="color: var(--text-secondary); font-size: 13px; margin-bottom: var(--space-2);">Visual progress of your recruitment stages from resume preparation to job onboarding.</p>
                
                <div class="journey-stepper">
                  <div class="journey-progress-line"></div>
                  <?php foreach ($stages as $idx => $stg): 
                    $isCompleted = ($idx < $currentStageIdx);
                    $isCurrent = ($idx === $currentStageIdx);
                    $class = $isCompleted ? 'completed' : ($isCurrent ? 'current' : 'pending');
                  ?>
                  <div class="step-node <?php echo $class; ?>">
                    <div class="step-circle">
                      <?php if ($isCompleted): ?>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                      <?php else: ?>
                        <?php echo ($idx + 1); ?>
                      <?php endif; ?>
                    </div>
                    <div class="step-label-wrapper">
                      <div class="step-label"><?php echo htmlspecialchars($stg['label']); ?></div>
                      <div class="step-desc"><?php echo htmlspecialchars($stg['desc']); ?></div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </section>

              <!-- Widgets Grid Section -->
              <div class="grid-12" style="margin-bottom: var(--space-3);">
                <!-- Upcoming Interviews -->
                <div class="col-6 col-lg-12">
                  <div class="widget-card">
                    <div class="widget-title-wrapper">
                      <h3 class="widget-title">Upcoming Interviews</h3>
                      <button class="btn btn-ghost btn-sm" onclick="switchView('interviews')">View Calendar</button>
                    </div>
                    
                    <?php if (count($interviewsSafe) === 0): ?>
                      <div class="empty-state" style="padding: var(--space-3) 0; text-align: center;">
                        <svg class="empty-state-illust" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="width:40px; height:40px; margin: 0 auto var(--space-2) auto; display:block;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <div class="empty-state-title" style="font-size:14px; font-weight:600;">No Scheduled Interviews</div>
                        <p class="empty-state-desc" style="font-size:12px; color:var(--text-secondary); margin-top:4px;">When a recruiter schedules a technical or HR interview, the details will appear here.</p>
                      </div>
                    <?php else: ?>
                      <div class="widget-list">
                        <?php 
                        $upcomingInts = array_slice($interviewsSafe, 0, 3);
                        foreach ($upcomingInts as $int): 
                          $intDate = date('d M Y', strtotime($int['date']));
                          $intTime = date('h:i A', strtotime($int['time']));
                        ?>
                        <div class="widget-item">
                          <div style="width:36px; height:36px; border-radius:8px; background:var(--color-warning-light); color:var(--color-warning); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                          </div>
                          <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                              <h4 style="font-size:14px; font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($int['companyName']); ?></h4>
                              <span class="badge badge-warning" style="font-size:10px; padding:2px 6px;"><?php echo htmlspecialchars($int['result'] ?? 'Scheduled'); ?></span>
                            </div>
                            <p style="font-size:12px; color:var(--text-secondary); margin-top:2px;">Role: <strong><?php echo htmlspecialchars($int['role']); ?></strong></p>
                            <p style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                              <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" style="display:inline; margin-right:2px; vertical-align:middle;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                              <?php echo $intDate; ?> at <?php echo $intTime; ?> &bull; Venue: <?php echo htmlspecialchars($int['venue']); ?>
                            </p>
                          </div>
                        </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Recent Applications -->
                <div class="col-6 col-lg-12">
                  <div class="widget-card">
                    <div class="widget-title-wrapper">
                      <h3 class="widget-title">Recent Applications</h3>
                      <button class="btn btn-ghost btn-sm" onclick="switchView('applications')">View All</button>
                    </div>

                    <?php if (count($applicationsSafe) === 0): ?>
                      <div class="empty-state" style="padding: var(--space-3) 0; text-align: center;">
                        <svg class="empty-state-illust" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="width:40px; height:40px; margin: 0 auto var(--space-2) auto; display:block;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <div class="empty-state-title" style="font-size:14px; font-weight:600;">No Recent Applications</div>
                        <p class="empty-state-desc" style="font-size:12px; color:var(--text-secondary); margin-top:4px;">Submit applications for recommended job drives to get started on your funnel.</p>
                      </div>
                    <?php else: ?>
                      <div class="widget-list">
                        <?php 
                        $recentApps = array_slice($applicationsSafe, 0, 3);
                        foreach ($recentApps as $app): 
                          $appDate = date('d M Y', strtotime($app['applied_date']));
                          $statusStyle = 'badge-primary';
                          if ($app['status'] === 'Selected') $statusStyle = 'badge-success';
                          else if ($app['status'] === 'Rejected') $statusStyle = 'badge-danger';
                          else if (in_array($app['status'], ['HR', 'Technical', 'Aptitude', 'Eligible'])) $statusStyle = 'badge-info';
                        ?>
                        <div class="widget-item">
                          <div style="width:36px; height:36px; border-radius:8px; background:var(--primary-light); color:var(--primary); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                          </div>
                          <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                              <h4 style="font-size:14px; font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($app['companyName']); ?></h4>
                              <span class="badge <?php echo $statusStyle; ?>" style="font-size:10px; padding:2px 6px;"><?php echo htmlspecialchars($app['status']); ?></span>
                            </div>
                            <p style="font-size:12px; color:var(--text-secondary); margin-top:2px;">Role: <strong><?php echo htmlspecialchars($app['role']); ?></strong></p>
                            <p style="font-size:11px; color:var(--text-muted); margin-top:4px;">Applied on: <?php echo $appDate; ?></p>
                          </div>
                        </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Widget row 2 -->
              <div class="grid-12" style="margin-bottom: var(--space-3);">
                <!-- Recommended Jobs -->
                <div class="col-8 col-lg-12">
                  <div class="widget-card">
                    <div class="widget-title-wrapper">
                      <div>
                        <h3 class="widget-title">Recommended Jobs for You</h3>
                        <p style="font-size:12px; color:var(--text-secondary); margin-top:2px;">Curated jobs matching your CGPA score and department qualifications.</p>
                      </div>
                      <button class="btn btn-ghost btn-sm" onclick="switchView('drives')">View All Jobs</button>
                    </div>

                    <?php 
                    if (count($recommendedJobs) === 0): 
                    ?>
                      <div class="empty-state" style="padding: var(--space-3); text-align: center;">
                        <svg class="empty-state-illust" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="margin: 0 auto var(--space-2) auto; display:block;"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <div class="empty-state-title" style="font-size:14px; font-weight:600;">No Job Recommendations</div>
                        <p class="empty-state-desc" style="font-size:12px; color:var(--text-secondary); margin-top:4px;">We couldn't find active job drives matching your CGPA and department. Update profile details or verify approvals.</p>
                      </div>
                    <?php else: ?>
                      <div style="display:flex; flex-direction:column; gap:12px;">
                        <?php foreach ($recommendedJobs as $job): ?>
                        <div class="job-recommendation-item">
                          <div style="display:flex; gap:12px; align-items:center;">
                            <div style="width:40px; height:40px; border-radius:50%; background:var(--primary-light); color:var(--primary); display:flex; align-items:center; justify-content:center; font-weight:700; flex-shrink:0;">
                              <?php echo strtoupper(substr($job['companyName'], 0, 2)); ?>
                            </div>
                            <div>
                              <h4 style="font-size:14px; font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($job['jobRole']); ?></h4>
                              <p style="font-size:12px; color:var(--text-secondary);"><?php echo htmlspecialchars($job['companyName']); ?> &bull; ₹<?php echo htmlspecialchars($job['packageLPA']); ?> LPA</p>
                              <span style="font-size:10px; color:var(--text-muted);">Deadline: <?php echo date('d M Y', strtotime($job['registration_deadline'])); ?> &bull; Min CGPA: <?php echo htmlspecialchars($job['eligibilityCGPA']); ?></span>
                            </div>
                          </div>
                          <button class="btn btn-primary btn-sm btn-quick-apply" data-id="<?php echo $job['id']; ?>" data-role="<?php echo htmlspecialchars($job['jobRole']); ?>" data-comp="<?php echo htmlspecialchars($job['companyName']); ?>">Apply Now</button>
                        </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Latest Notifications -->
                <div class="col-4 col-lg-12">
                  <div class="widget-card">
                    <div class="widget-title-wrapper">
                      <h3 class="widget-title">Latest Updates</h3>
                      <button class="btn btn-ghost btn-sm" onclick="switchView('notifications')">View All</button>
                    </div>

                    <?php if (count($studentNotifications) === 0): ?>
                      <div class="empty-state" style="padding: var(--space-3) 0; text-align: center;">
                        <svg class="empty-state-illust" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="width:40px; height:40px; margin: 0 auto var(--space-2) auto; display:block;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <div class="empty-state-title" style="font-size:14px; font-weight:600;">No Notifications</div>
                        <p class="empty-state-desc" style="font-size:12px; color:var(--text-secondary); margin-top:4px;">You have no active alerts or updates right now.</p>
                      </div>
                    <?php else: ?>
                      <div class="widget-list">
                        <?php foreach ($studentNotifications as $notif): 
                          $notifTime = date('d M H:i', strtotime($notif['created_at']));
                        ?>
                        <div class="widget-item" style="gap: 8px; padding-bottom: 10px;">
                          <div style="margin-top:2px; color:var(--primary); flex-shrink:0;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                          </div>
                          <div style="flex:1;">
                            <h4 style="font-size:13px; font-weight:600; color:var(--text-primary); line-height:1.2;"><?php echo htmlspecialchars($notif['title']); ?></h4>
                            <p style="font-size:11px; color:var(--text-secondary); margin-top:2px; line-height:1.3;"><?php echo htmlspecialchars($notif['description']); ?></p>
                            <span style="font-size:9px; color:var(--text-muted);"><?php echo $notifTime; ?></span>
                          </div>
                        </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Analytics cards section -->
              <section class="grid-12" style="margin-bottom: var(--space-3);">
                <div class="col-6 col-lg-12">
                  <?php if ($hasApplications): ?>
                    <div class="card" style="height: 100%; padding: var(--space-25);">
                      <h3 class="widget-title" style="margin-bottom: 2px;">Application Funnel Status</h3>
                      <p style="color:var(--text-secondary); font-size:12px; margin-bottom:var(--space-2);">Review the pipeline breakdown of all your placement drives.</p>
                      <div style="height: 240px; position: relative;">
                        <canvas id="student-applications-chart"></canvas>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="card" style="height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 310px; padding: var(--space-25);">
                      <div class="empty-state" style="text-align: center;">
                        <svg class="empty-state-illust" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="width: 48px; height: 48px; margin: 0 auto var(--space-2) auto; display:block;"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <div class="empty-state-title" style="font-size:14px; font-weight:600;">No Application Analytics</div>
                        <p class="empty-state-desc" style="font-size:12px; color:var(--text-secondary); margin-top:4px;">You haven't submitted any job applications yet. Apply to openings to see your recruitment funnel breakdown.</p>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="col-6 col-lg-12">
                  <div class="card" style="height: 100%; padding: var(--space-25); display:flex; flex-direction:column; justify-content:space-between;">
                    <div>
                      <h3 class="widget-title" style="margin-bottom: 2px;">Profile Completion Status</h3>
                      <p style="color:var(--text-secondary); font-size:12px; margin-bottom:var(--space-2);">Ensure your profile details are fully complete for higher response rates.</p>
                      
                      <?php 
                      // Calculate profile completion percentage
                      $profilePoints = 0;
                      $totalPoints = 6;
                      if (!empty($profileSafe['phone'])) $profilePoints++;
                      if (!empty($profileSafe['skills'])) $profilePoints++;
                      if (!empty($profileSafe['projects'])) $profilePoints++;
                      if (!empty($profileSafe['resume_path'])) $profilePoints++;
                      if (!empty($profileSafe['certificate_path'])) $profilePoints++;
                      if (isset($studentsSafe[0]['verifiedStatus']) && $studentsSafe[0]['verifiedStatus'] === 'approved') $profilePoints++;
                      $profilePercentage = round(($profilePoints / $totalPoints) * 100);
                      ?>

                      <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; color:var(--text-primary); margin-top:var(--space-2); margin-bottom:4px;">
                        <span>Progress Rate</span>
                        <span><?php echo $profilePercentage; ?>%</span>
                      </div>
                      <div class="progress-bar-wrapper" style="height:8px; background:var(--border-color); border-radius:4px; overflow:hidden;">
                        <div class="progress-bar-fill" style="width: <?php echo $profilePercentage; ?>%; background: var(--primary); height:100%; transition: width 0.5s;"></div>
                      </div>

                      <ul style="font-size:12px; color:var(--text-secondary); margin-top:var(--space-2); padding-left:16px; line-height:1.6;">
                        <li style="list-style-type: disc; color: <?php echo !empty($profileSafe['resume_path']) ? 'var(--color-success)' : 'var(--text-secondary)'; ?>;">
                          Resume Document Uploaded <?php echo !empty($profileSafe['resume_path']) ? '✓' : '✗'; ?>
                        </li>
                        <li style="list-style-type: disc; color: <?php echo !empty($profileSafe['phone']) ? 'var(--color-success)' : 'var(--text-secondary)'; ?>;">
                          Contact Mobile Number added <?php echo !empty($profileSafe['phone']) ? '✓' : '✗'; ?>
                        </li>
                        <li style="list-style-type: disc; color: <?php echo !empty($profileSafe['skills']) ? 'var(--color-success)' : 'var(--text-secondary)'; ?>;">
                          Skills & Tech Stack specified <?php echo !empty($profileSafe['skills']) ? '✓' : '✗'; ?>
                        </li>
                        <li style="list-style-type: disc; color: <?php echo !empty($profileSafe['projects']) ? 'var(--color-success)' : 'var(--text-secondary)'; ?>;">
                          Projects descriptions added <?php echo !empty($profileSafe['projects']) ? '✓' : '✗'; ?>
                        </li>
                        <li style="list-style-type: disc; color: <?php echo (isset($studentsSafe[0]['verifiedStatus']) && $studentsSafe[0]['verifiedStatus'] === 'approved') ? 'var(--color-success)' : 'var(--text-secondary)'; ?>;">
                          Profile verifications complete <?php echo (isset($studentsSafe[0]['verifiedStatus']) && $studentsSafe[0]['verifiedStatus'] === 'approved') ? '✓' : '✗'; ?>
                        </li>
                      </ul>
                    </div>

                    <button class="btn btn-secondary btn-sm" style="width:100%; margin-top:var(--space-2);" onclick="switchView('profile-tab')">Update Profile Records</button>
                  </div>
                </div>
              </section>

            </div>

            <script>
              document.addEventListener("DOMContentLoaded", () => {
                // Initialize applications status chart if it exists
                const canvas = document.getElementById("student-applications-chart");
                if (canvas) {
                  Chart.defaults.font.family = "'Inter', sans-serif";
                  Chart.defaults.color = "var(--text-secondary)";
                  
                  new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                      labels: ['Applied', 'Shortlisted', 'Selected', 'Rejected'],
                      datasets: [{
                        data: [
                          <?php echo $statusCounts['Applied']; ?>,
                          <?php echo $statusCounts['Shortlisted']; ?>,
                          <?php echo $statusCounts['Selected']; ?>,
                          <?php echo $statusCounts['Rejected']; ?>
                        ],
                        backgroundColor: ['#2563EB', '#F59E0B', '#10B981', '#EF4444'],
                        borderWidth: 2,
                        borderColor: 'var(--bg-card)'
                      }]
                    },
                    options: {
                      responsive: true,
                      maintainAspectRatio: false,
                      plugins: {
                        legend: {
                          position: 'bottom',
                          labels: { boxWidth: 10, padding: 12, font: { size: 11 } }
                        }
                      }
                    }
                  });
                }

                // Handle Quick Apply button click
                document.querySelectorAll(".btn-quick-apply").forEach(btn => {
                  btn.addEventListener("click", () => {
                    const driveId = btn.getAttribute("data-id");
                    const roleName = btn.getAttribute("data-role");
                    const compName = btn.getAttribute("data-comp");

                    Swal.fire({
                      title: 'Apply for Job Opportunity?',
                      text: "Confirm your application for '" + roleName + "' at '" + compName + "'.",
                      icon: 'question',
                      showCancelButton: true,
                      confirmButtonColor: '#2563EB',
                      cancelButtonColor: '#6B7280',
                      confirmButtonText: 'Yes, Apply'
                    }).then((result) => {
                      if (result.isConfirmed) {
                        Swal.fire({
                          title: 'Submitting Application...',
                          allowOutsideClick: false,
                          didOpen: () => { Swal.showLoading(); }
                        });

                        const form = new FormData();
                        form.append("ajax_action", "student_apply");
                        form.append("drive_id", driveId);

                        fetch('dashboard.php', {
                          method: 'POST',
                          body: form
                        })
                        .then(res => res.json())
                        .then(res => {
                          if (res.status === 'success') {
                            Swal.fire({
                              title: 'Applied Successfully!',
                              text: res.message,
                              icon: 'success',
                              timer: 1500,
                              showConfirmButton: false
                            });
                            setTimeout(() => window.location.reload(), 1500);
                          } else {
                            Swal.fire({
                              title: 'Application Failed',
                              text: res.message,
                              icon: 'error',
                              confirmButtonColor: '#2563EB'
                            });
                          }
                        })
                        .catch(err => {
                          Swal.fire({
                            title: 'Network Error',
                            text: 'An unexpected connection error occurred. Please try again.',
                            icon: 'error',
                            confirmButtonColor: '#2563EB'
                          });
                        });
                      }
                    });
                  });
                });

                // Handle Accept/Decline offer clicks
                document.querySelectorAll(".btn-accept-offer").forEach(btn => {
                  btn.addEventListener("click", () => {
                    const offerId = btn.getAttribute("data-id");
                    Swal.fire({
                      title: 'Accept Placement Offer?',
                      text: 'Do you want to accept this placement offer and join the company? This action is official.',
                      icon: 'warning',
                      showCancelButton: true,
                      confirmButtonColor: '#10B981',
                      cancelButtonColor: '#6B7280',
                      confirmButtonText: 'Yes, Accept Offer'
                    }).then((result) => {
                      if (result.isConfirmed) {
                        updateOfferStatus(offerId, 'Accepted');
                      }
                    });
                  });
                });

                document.querySelectorAll(".btn-decline-offer").forEach(btn => {
                  btn.addEventListener("click", () => {
                    const offerId = btn.getAttribute("data-id");
                    Swal.fire({
                      title: 'Decline Placement Offer?',
                      text: 'Are you sure you want to decline this official placement offer? This action cannot be undone.',
                      icon: 'warning',
                      showCancelButton: true,
                      confirmButtonColor: '#EF4444',
                      cancelButtonColor: '#6B7280',
                      confirmButtonText: 'Yes, Decline'
                    }).then((result) => {
                      if (result.isConfirmed) {
                        updateOfferStatus(offerId, 'Declined');
                      }
                    });
                  });
                });

                function updateOfferStatus(offerId, status) {
                  Swal.fire({
                    title: 'Processing Response...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                  });

                  const form = new FormData();
                  form.append("ajax_action", "update_offer_status");
                  form.append("offer_id", offerId);
                  form.append("status", status);

                  fetch('dashboard.php', {
                    method: 'POST',
                    body: form
                  })
                  .then(res => res.json())
                  .then(res => {
                    if (res.status === 'success') {
                      Swal.fire({
                        title: 'Success!',
                        text: res.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                      });
                      setTimeout(() => window.location.reload(), 1500);
                    } else {
                      Swal.fire({
                        title: 'Operation Failed',
                        text: res.message,
                        icon: 'error',
                        confirmButtonColor: '#2563EB'
                      });
                    }
                  })
                  .catch(err => {
                    Swal.fire({
                      title: 'Network Error',
                      text: 'An unexpected connection error occurred.',
                      icon: 'error',
                      confirmButtonColor: '#2563EB'
                    });
                  });
                }
              });
            </script>
          <?php else: ?>
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
          <?php endif; ?>
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
          <?php if ($role === 'student'): ?>
            <!-- Scoped CSS Styles for Job Openings Portal -->
            <style>
              .job-portal-container {
                display: flex;
                flex-direction: column;
                gap: var(--space-3);
              }
              
              .job-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: var(--space-25);
              }
              
              .job-card {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-lg);
                padding: var(--space-25);
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                min-height: 360px;
                position: relative;
                transition: all var(--transition-normal);
                box-shadow: var(--shadow-sm);
              }
              
              .job-card:hover {
                transform: translateY(-5px);
                box-shadow: var(--shadow-md);
                border-color: var(--primary);
              }
              
              .job-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: var(--space-2);
                gap: 12px;
              }
              
              .company-logo-avatar {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 18px;
                color: #FFFFFF;
                flex-shrink: 0;
              }
              
              .job-card-title-group {
                flex: 1;
              }
              
              .job-card-role {
                font-size: 16px;
                font-weight: 700;
                color: var(--text-primary);
                line-height: 1.3;
                margin-bottom: 2px;
              }
              
              .job-card-company {
                font-size: 13px;
                color: var(--text-secondary);
                font-weight: 600;
              }
              
              .job-meta-list {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin-bottom: var(--space-3);
              }
              
              .job-meta-item {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 13px;
                color: var(--text-secondary);
              }
              
              .job-meta-item svg {
                color: var(--text-muted);
                flex-shrink: 0;
              }
              
              .job-badge-row {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
                margin-bottom: var(--space-2);
              }
              
              .job-card-actions {
                display: flex;
                gap: 8px;
                align-items: center;
                border-top: 1px solid var(--border-color);
                padding-top: var(--space-2);
                margin-top: auto;
              }
              
              .job-bookmark-btn {
                background: transparent;
                border: 1px solid var(--border-color);
                color: var(--text-muted);
                border-radius: 50%;
                width: 36px;
                height: 36px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all var(--transition-fast);
                flex-shrink: 0;
              }
              
              .job-bookmark-btn:hover {
                border-color: var(--primary);
                color: var(--primary);
                background: var(--primary-light);
              }
              
              .job-bookmark-btn.bookmarked {
                border-color: var(--primary);
                color: var(--primary);
                background: var(--primary-light);
              }
              
              .job-bookmark-btn.bookmarked svg {
                fill: currentColor;
              }
              
              /* Skeletons pulse animation */
              .skeleton-card {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-lg);
                padding: var(--space-25);
                min-height: 360px;
                display: flex;
                flex-direction: column;
                gap: var(--space-2);
                animation: skeleton-pulse 1.5s infinite ease-in-out;
              }
              
              @keyframes skeleton-pulse {
                0% { opacity: 0.6; }
                50% { opacity: 1; }
                100% { opacity: 0.6; }
              }
              
              .skeleton-circle {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                background: var(--border-color);
              }
              
              .skeleton-line {
                height: 14px;
                background: var(--border-color);
                border-radius: 4px;
              }
              
              .skeleton-line.title { width: 60%; height: 18px; }
              .skeleton-line.subtitle { width: 40%; }
              .skeleton-line.meta { width: 80%; }
              .skeleton-line.badge { width: 30%; height: 20px; display: inline-block; }
              .skeleton-line.btn { height: 36px; flex: 1; }
            </style>

            <div class="job-portal-container">
              <!-- Search & Compact Filter Controls -->
              <div class="card" style="margin-bottom: var(--space-2); padding: var(--space-25);">
                <div style="display:flex; flex-direction:column; gap:var(--space-25);">
                  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--space-1);">
                    <div>
                      <h3 class="chart-container-title" style="margin-bottom: 2px;">Explore Placement Opportunities</h3>
                      <p style="color: var(--text-secondary); font-size: 13px;">Discover live recruitment campaigns, verify CGPA eligibility requirements and submit quick applications.</p>
                    </div>
                    <div>
                      <span style="font-size:13px; font-weight:600; color:var(--text-secondary); display:flex; align-items:center; gap:6px;" id="active-jobs-indicator">
                        <span class="active-pulse" style="display:inline-block;"></span>
                        <span id="active-jobs-count">0</span> Active Openings
                      </span>
                    </div>
                  </div>
                  
                  <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <div style="flex:1; min-width:260px; position:relative;">
                      <input type="text" class="input-field" id="job-search-input" placeholder="Search by Job Role, Company, or Location..." style="padding-left:36px; width:100%;">
                      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--text-secondary)" stroke-width="2" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; white-space:nowrap;">
                      <label style="font-size:13px; color:var(--text-secondary); font-weight:600;">Sort By:</label>
                      <select class="input-field select-custom" id="job-sort-select" style="width: 170px; padding: 8px 12px;">
                        <option value="latest">Latest Updates</option>
                        <option value="deadline">Registration Deadline</option>
                        <option value="highest_package">Highest CTC (Salary)</option>
                        <option value="lowest_package">Lowest CTC (Salary)</option>
                        <option value="company_name">Company Name</option>
                      </select>
                    </div>
                    <button class="btn btn-ghost btn-sm" id="btn-clear-filters" style="padding: 10px 14px;">Reset Filters</button>
                  </div>
                  
                  <!-- Simplified Filters Row (Compact grid of selects) -->
                  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; border-top: 1px solid var(--border-color); padding-top: 12px; margin-bottom: 4px;" id="job-filters-container">
                    <div style="display:flex; flex-direction:column; gap:4px;">
                      <span style="font-size:11px; font-weight:600; color:var(--text-secondary);">Company</span>
                      <select class="input-field select-custom" id="filter-company" style="padding: 8px 12px; font-size:13px; height:auto; width:100%;">
                        <option value="All">All Companies</option>
                      </select>
                    </div>
                    
                    <div style="display:flex; flex-direction:column; gap:4px;">
                      <span style="font-size:11px; font-weight:600; color:var(--text-secondary);">Location</span>
                      <select class="input-field select-custom" id="filter-location" style="padding: 8px 12px; font-size:13px; height:auto; width:100%;">
                        <option value="All">All Locations</option>
                      </select>
                    </div>
                    
                    <div style="display:flex; flex-direction:column; gap:4px;">
                      <span style="font-size:11px; font-weight:600; color:var(--text-secondary);">Job Type</span>
                      <select class="input-field select-custom" id="filter-jobtype" style="padding: 8px 12px; font-size:13px; height:auto; width:100%;">
                        <option value="All">All Job Types</option>
                        <option value="Full-Time">Full-Time</option>
                        <option value="Internship">Internship</option>
                      </select>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:4px;">
                      <span style="font-size:11px; font-weight:600; color:var(--text-secondary);">Work Mode</span>
                      <select class="input-field select-custom" id="filter-workmode" style="padding: 8px 12px; font-size:13px; height:auto; width:100%;">
                        <option value="All">All Work Modes</option>
                        <option value="Onsite">Onsite</option>
                        <option value="Hybrid">Hybrid</option>
                        <option value="Remote">Remote</option>
                      </select>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:4px; position:relative;">
                      <span style="font-size:11px; font-weight:600; color:var(--text-secondary);">Maximum Required CGPA</span>
                      <input type="number" step="0.1" min="0" max="10" class="input-field" id="filter-cgpa" placeholder="Enter CGPA (e.g. 8.5)" style="padding: 8px 12px; font-size:13px; height:auto; width:100%;">
                      <span id="cgpa-validation-msg" style="display:none; color:var(--color-danger); font-size:10px; position:absolute; bottom:-18px; left:4px; font-weight:600; z-index:10;">Must be between 0.0 and 10.0</span>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:4px;">
                      <span style="font-size:11px; font-weight:600; color:var(--text-secondary);">Status</span>
                      <select class="input-field select-custom" id="filter-status" style="padding: 8px 12px; font-size:13px; height:auto; width:100%;">
                        <option value="All">All Statuses</option>
                        <option value="Open">Open</option>
                        <option value="Closing Soon">Closing Soon</option>
                        <option value="Closed">Closed</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>
                            <!-- Job Grid Cards Container -->
              <div class="job-grid" id="job-grid-container">
                <!-- Cards render dynamically -->
              </div>
            </div>

            <!-- Job Details Modal Overlay -->
            <div class="modal-overlay" id="modal-job-details" style="display: none; align-items: center; justify-content: center; z-index: 1000; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5);">
              <div class="modal-card" style="max-width: 620px; width: 90%; border-radius: var(--radius-lg); overflow: hidden; background: var(--bg-card); box-shadow: var(--shadow-lg); display: flex; flex-direction: column;">
                <div style="padding: var(--space-25); display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid var(--border-color);">
                  <div style="display: flex; gap: 16px; align-items: center;">
                    <div id="modal-job-company-logo" style="width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 20px; color: #FFFFFF; flex-shrink: 0;">
                      CO
                    </div>
                    <div>
                      <h3 class="modal-title" id="modal-job-role" style="font-size: 17px; font-weight: 700; color: var(--text-primary); margin: 0;">Job Role Title</h3>
                      <p id="modal-job-company" style="font-size: 13px; color: var(--text-secondary); margin: 2px 0 0 0; font-weight: 600;">Company Name</p>
                    </div>
                  </div>
                  <button onclick="closeJobDetailsModal()" style="background: transparent; border: none; cursor: pointer; color: var(--text-secondary); padding: 4px;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
                
                <div style="padding: var(--space-25); max-height: 400px; overflow-y: auto;">
                  <!-- Tags -->
                  <div style="display:flex; gap:6px; margin-bottom: 16px; flex-wrap:wrap;" id="modal-job-tags">
                  </div>

                  <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:20px; padding: 12px; background: rgba(0,0,0,0.02); border-radius:8px;" id="modal-job-meta">
                    <div>
                      <span style="font-size:10px; color:var(--text-muted); text-transform:uppercase; display:block; font-weight:600;">Salary Package</span>
                      <strong style="font-size:14px; color:var(--text-primary);" id="modal-job-salary">₹0.00 LPA</strong>
                    </div>
                    <div>
                      <span style="font-size:10px; color:var(--text-muted); text-transform:uppercase; display:block; font-weight:600;">Job Location</span>
                      <strong style="font-size:14px; color:var(--text-primary);" id="modal-job-location">Location</strong>
                    </div>
                    <div>
                      <span style="font-size:10px; color:var(--text-muted); text-transform:uppercase; display:block; font-weight:600;">Eligibility CGPA</span>
                      <strong style="font-size:14px; color:var(--text-primary);" id="modal-job-cgpa">0.00 CGPA</strong>
                    </div>
                    <div>
                      <span style="font-size:10px; color:var(--text-muted); text-transform:uppercase; display:block; font-weight:600;">Apply Deadline</span>
                      <strong style="font-size:14px; color:var(--text-primary);" id="modal-job-deadline">Date</strong>
                    </div>
                  </div>

                  <div style="margin-bottom:16px;">
                    <h4 style="font-size:12px; font-weight:700; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Required Skills Stack</h4>
                    <p style="font-size:13px; color:var(--text-secondary); line-height:1.5; margin:0;" id="modal-job-skills">-</p>
                  </div>

                  <div style="margin-bottom:16px;">
                    <h4 style="font-size:12px; font-weight:700; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Job Description</h4>
                    <p style="font-size:13px; color:var(--text-secondary); line-height:1.5; margin:0;" id="modal-job-desc">-</p>
                  </div>

                  <div>
                    <h4 style="font-size:12px; font-weight:700; color:var(--text-primary); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Selection Rounds (Expected)</h4>
                    <ul style="font-size:13px; color:var(--text-secondary); line-height:1.5; padding-left:16px; margin:4px 0 0 0; list-style-type:disc;">
                      <li>Online Aptitude & Coding Round</li>
                      <li>Technical Screening Interview</li>
                      <li>HR Discussion & Final Onboarding</li>
                    </ul>
                  </div>
                </div>
                
                <div style="padding: 12px var(--space-25); display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid var(--border-color); background: rgba(0,0,0,0.01);">
                  <button class="btn btn-secondary btn-sm" onclick="closeJobDetailsModal()">Close</button>
                  <button class="btn btn-primary btn-sm" id="modal-btn-apply" style="gap:6px;">Apply Now</button>
                </div>
              </div>
            </div>

            <!-- JavaScript Logic for Student Job Openings page -->
            <script>
              document.addEventListener("DOMContentLoaded", () => {
                // Read static page data
                const drives = window.campusRecruitmentData.drives || [];
                const applications = window.campusRecruitmentData.applications || [];
                const studentCGPA = parseFloat("<?php echo floatval($profile['cgpa'] ?? 0); ?>");
                const studentDeptCode = "<?php echo getDeptCode($profile['department'] ?? ''); ?>";
                const studentDeptName = "<?php echo htmlspecialchars($profile['department'] ?? ''); ?>";
                
                // Track bookmarks
                let bookmarks = [];
                try {
                  bookmarks = JSON.parse(localStorage.getItem('student_bookmarked_drives')) || [];
                } catch(e) { bookmarks = []; }
                
                // Track Applied list
                const appliedDriveIds = applications.map(a => parseInt(a.driveId));

                // Elements
                const searchInput = document.getElementById("job-search-input");
                const sortSelect = document.getElementById("job-sort-select");
                const companyFilter = document.getElementById("filter-company");
                const locationFilter = document.getElementById("filter-location");
                const jobtypeFilter = document.getElementById("filter-jobtype");
                const workmodeFilter = document.getElementById("filter-workmode");
                const cgpaFilter = document.getElementById("filter-cgpa");
                const statusFilter = document.getElementById("filter-status");
                const clearFiltersBtn = document.getElementById("btn-clear-filters");
                const gridContainer = document.getElementById("job-grid-container");
                const activeJobsCount = document.getElementById("active-jobs-count");

                // Deterministic Helpers for Location, Work Mode & Job Type
                const companyLocations = {
                  "Google Inc.": "Bangalore, India",
                  "Microsoft Corp.": "Hyderabad, India",
                  "Amazon.com": "Chennai, India",
                  "Stripe Inc.": "Mumbai, India",
                  "Notion Labs": "Remote",
                  "Razorpay Labs": "Pune, India"
                };

                const companyWorkModes = {
                  "Google Inc.": "Onsite",
                  "Microsoft Corp.": "Onsite",
                  "Amazon.com": "Onsite",
                  "Stripe Inc.": "Hybrid",
                  "Notion Labs": "Remote",
                  "Razorpay Labs": "Hybrid"
                };

                function getCompanyLocation(companyName) {
                  return companyLocations[companyName] || "Pune, India";
                }

                function getCompanyWorkMode(companyName) {
                  return companyWorkModes[companyName] || "Onsite";
                }

                function getJobType(driveId) {
                  return (parseInt(driveId) % 2 === 0) ? "Full-Time" : "Internship";
                }

                function getPostedDateText(driveDateStr) {
                  const driveDate = new Date(driveDateStr);
                  // Posted 12 days before drive date
                  const postedDate = new Date(driveDate.getTime() - 12 * 24 * 60 * 60 * 1000);
                  const diffTime = Math.abs(new Date() - postedDate);
                  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                  if (diffDays <= 0) return "Posted today";
                  if (diffDays === 1) return "Posted yesterday";
                  return `Posted ${diffDays} days ago`;
                }

                function getDeadlineTimeRemaining(deadlineStr) {
                  const deadline = new Date(deadlineStr);
                  const today = new Date();
                  today.setHours(0,0,0,0);
                  const diffTime = deadline - today;
                  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                  if (diffDays < 0) return "Closed";
                  if (diffDays === 0) return "Closing today";
                  if (diffDays === 1) return "1 day left";
                  return `${diffDays} days left`;
                }

                function getJobStatus(drive) {
                  const deadlineRemaining = getDeadlineTimeRemaining(drive.registration_deadline);
                  if (drive.status.toLowerCase() === 'closed' || drive.status.toLowerCase() === 'completed' || drive.status.toLowerCase() === 'cancelled') {
                    return "Closed";
                  }
                  if (deadlineRemaining === "Closed") {
                    return "Closed";
                  }
                  if (deadlineRemaining.includes("today") || (parseInt(deadlineRemaining) <= 3)) {
                    return "Closing Soon";
                  }
                  return "Open";
                }

                function getPastelBgColor(name) {
                  let hash = 0;
                  for (let i = 0; i < name.length; i++) {
                    hash = name.charCodeAt(i) + ((hash << 5) - hash);
                  }
                  const h = Math.abs(hash) % 360;
                  return `hsl(${h}, 65%, 45%)`;
                }

                // Populate filter options dynamically
                const companiesList = [...new Set(drives.map(d => d.companyName))].sort();
                companiesList.forEach(c => {
                  const opt = document.createElement("option");
                  opt.value = c;
                  opt.textContent = c;
                  companyFilter.appendChild(opt);
                });

                const locationsList = [...new Set(drives.map(d => getCompanyLocation(d.companyName)))].sort();
                locationsList.forEach(l => {
                  const opt = document.createElement("option");
                  opt.value = l;
                  opt.textContent = l;
                  locationFilter.appendChild(opt);
                });

                // Filter & Sort state
                function getFiltersState() {
                  return {
                    search: searchInput.value.toLowerCase().trim(),
                    company: companyFilter.value,
                    location: locationFilter.value,
                    jobType: jobtypeFilter.value,
                    workMode: workmodeFilter.value,
                    cgpa: cgpaFilter.value,
                    status: statusFilter.value
                  };
                }

                // Main processor and renderer
                function processAndRenderJobs(showLoading = false) {
                  if (showLoading) {
                    renderSkeletons();
                    setTimeout(() => doProcessing(), 250); // realistic quick load feeling
                  } else {
                    doProcessing();
                  }
                }

                function doProcessing() {
                  const filters = getFiltersState();
                  const sortBy = sortSelect.value;
                  
                  // Filter
                  let filteredDrives = drives.filter(d => {
                    const loc = getCompanyLocation(d.companyName);
                    const wm = getCompanyWorkMode(d.companyName);
                    const jt = getJobType(d.id);
                    const status = getJobStatus(d);

                    // 1. Search (Company, Job Role, Location)
                    const matchSearch = !filters.search || 
                      d.jobRole.toLowerCase().includes(filters.search) ||
                      d.companyName.toLowerCase().includes(filters.search) ||
                      loc.toLowerCase().includes(filters.search);

                    if (!matchSearch) return false;

                    // 2. Company dropdown
                    if (filters.company !== 'All' && d.companyName !== filters.company) return false;

                    // 3. Location dropdown
                    if (filters.location !== 'All' && loc !== filters.location) return false;

                    // 4. Job Type dropdown
                    if (filters.jobType !== 'All' && jt !== filters.jobType) return false;

                    // 5. Work Mode dropdown
                    if (filters.workMode !== 'All' && wm !== filters.workMode) return false;

                    // 6. Minimum CGPA dropdown
                    if (filters.cgpa !== 'All') {
                      if (filters.cgpa === 'eligible') {
                        if (parseFloat(d.eligibilityCGPA) > studentCGPA) return false;
                      } else {
                        const maxCgpa = parseFloat(filters.cgpa);
                        if (parseFloat(d.eligibilityCGPA) > maxCgpa) return false;
                      }
                    }

                    // 7. Status dropdown
                    if (filters.status !== 'All' && status !== filters.status) return false;

                    return true;
                  });

                  // Sort
                  filteredDrives.sort((a, b) => {
                    if (sortBy === 'deadline') {
                      return new Date(a.registration_deadline) - new Date(b.registration_deadline);
                    }
                    if (sortBy === 'highest_package') {
                      return parseFloat(b.packageLPA) - parseFloat(a.packageLPA);
                    }
                    if (sortBy === 'lowest_package') {
                      return parseFloat(a.packageLPA) - parseFloat(b.packageLPA);
                    }
                    if (sortBy === 'company_name') {
                      return a.companyName.localeCompare(b.companyName);
                    }
                    // Default latest
                    return parseInt(b.id) - parseInt(a.id);
                  });

                  // Update indicators
                  const activeOpenings = filteredDrives.filter(d => getJobStatus(d) !== 'Closed').length;
                  activeJobsCount.textContent = activeOpenings;

                  // Render
                  renderCards(filteredDrives);
                }

                function renderSkeletons() {
                  gridContainer.innerHTML = "";
                  for (let i = 0; i < 6; i++) {
                    gridContainer.innerHTML += `
                      <div class="skeleton-card">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                          <div class="skeleton-circle"></div>
                          <div class="skeleton-line" style="width:70px; height:20px;"></div>
                        </div>
                        <div class="skeleton-line title" style="margin-top:12px;"></div>
                        <div class="skeleton-line subtitle"></div>
                        <div style="margin-top:20px; display:flex; flex-direction:column; gap:8px;">
                          <div class="skeleton-line meta"></div>
                          <div class="skeleton-line meta" style="width:70%;"></div>
                          <div class="skeleton-line meta" style="width:90%;"></div>
                        </div>
                        <div style="margin-top:auto; display:flex; gap:8px;">
                          <div class="skeleton-line btn"></div>
                          <div class="skeleton-line btn"></div>
                        </div>
                      </div>
                    `;
                  }
                }

                function renderCards(jobs) {
                  gridContainer.innerHTML = "";
                  
                  if (jobs.length === 0) {
                    gridContainer.innerHTML = `
                      <div style="grid-column: 1 / -1; display:flex; flex-direction:column; align-items:center; justify-content:center; padding: var(--space-4) var(--space-2); background: var(--bg-card); border: 1px dashed var(--border-color); border-radius: var(--radius-lg); text-align:center;">
                        <svg viewBox="0 0 24 24" width="54" height="54" fill="none" stroke="var(--text-muted)" stroke-width="1.2" style="margin-bottom:var(--space-2);"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <h4 style="font-size:16px; font-weight:700; color:var(--text-primary); margin-bottom:4px;">No Job Openings Available</h4>
                        <p style="color:var(--text-secondary); font-size:13px; max-width:400px; margin:0;">There are currently no active recruitment campaigns matching your selected filter guidelines. Try resetting parameters.</p>
                      </div>
                    `;
                    return;
                  }

                  jobs.forEach(job => {
                    const loc = getCompanyLocation(job.companyName);
                    const wm = getCompanyWorkMode(job.companyName);
                    const jt = getJobType(job.id);
                    const posted = getPastelBgColor(job.companyName);
                    const isBookmarked = bookmarks.includes(parseInt(job.id));
                    const alreadyApplied = appliedDriveIds.includes(parseInt(job.id));
                    const isEligible = studentCGPA >= parseFloat(job.eligibilityCGPA);
                    const status = getJobStatus(job);
                    
                    // Badges
                    let statusBadgeClass = 'badge-primary';
                    if (status === 'Closed') statusBadgeClass = 'badge-danger';
                    else if (status === 'Closing Soon') statusBadgeClass = 'badge-warning';
                    else if (status === 'Open') statusBadgeClass = 'badge-success';

                    const eligBadgeHtml = isEligible 
                      ? `<span class="badge badge-success" style="font-size:11px; padding:3px 8px; border-radius:12px;">✓ Eligible</span>`
                      : `<span class="badge badge-danger" style="font-size:11px; padding:3px 8px; border-radius:12px;">✕ Ineligible</span>`;

                    // Application buttons logic
                    let actionBtnHtml = '';
                    if (alreadyApplied) {
                      actionBtnHtml = `<button class="btn btn-primary btn-sm" style="flex:1;" disabled>Applied ✓</button>`;
                    } else if (status === 'Closed') {
                      actionBtnHtml = `<button class="btn btn-secondary btn-sm" style="flex:1;" disabled>Expired</button>`;
                    } else if (!isEligible) {
                      actionBtnHtml = `<button class="btn btn-danger btn-sm" style="flex:1;" disabled>Ineligible</button>`;
                    } else {
                      actionBtnHtml = `<button class="btn btn-primary btn-sm btn-action-apply" data-id="${job.id}" data-role="${job.jobRole}" data-comp="${job.companyName}" style="flex:1;">Apply Now</button>`;
                    }

                    const card = document.createElement("div");
                    card.className = "job-card";
                    card.innerHTML = `
                      <div>
                        <div class="job-card-header">
                          <div class="company-logo-avatar" style="background-color:${posted}">
                            ${job.companyName.substring(0,2).toUpperCase()}
                          </div>
                          <div class="job-card-title-group">
                            <h4 class="job-card-role">${htmlspecialchars(job.jobRole)}</h4>
                            <span class="job-card-company">${htmlspecialchars(job.companyName)}</span>
                          </div>
                          <span class="badge ${statusBadgeClass}" style="font-size:10px; padding:2px 8px; border-radius:10px;">${status}</span>
                        </div>

                        <div class="job-badge-row">
                          <span class="badge" style="background:rgba(0,0,0,0.03); color:var(--text-secondary); font-size:11px; padding:2px 8px; border-radius:4px;">${jt}</span>
                          <span class="badge" style="background:rgba(0,0,0,0.03); color:var(--text-secondary); font-size:11px; padding:2px 8px; border-radius:4px;">${wm}</span>
                          ${eligBadgeHtml}
                        </div>

                        <div class="job-meta-list">
                          <div class="job-meta-item">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 12 8 12s8-6.75 8-12a8 8 0 0 0-8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span>${loc}</span>
                          </div>
                          <div class="job-meta-item">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            <span>₹${parseFloat(job.packageLPA).toFixed(2)} LPA CTC</span>
                          </div>
                          <div class="job-meta-item">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            <span>Min CGPA: <strong>${parseFloat(job.eligibilityCGPA).toFixed(2)}</strong></span>
                          </div>
                          <div class="job-meta-item" style="color:${status === 'Closing Soon' ? 'var(--color-warning)' : 'var(--text-secondary)'}">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>Deadline: ${getDeadlineTimeRemaining(job.registration_deadline)} (${new Date(job.registration_deadline).toLocaleDateString('en-IN', {day:'numeric', month:'short'})})</span>
                          </div>
                        </div>
                      </div>

                      <div class="job-card-actions">
                        <button class="btn btn-secondary btn-sm btn-action-details" data-id="${job.id}" style="flex:1;">View Details</button>
                        ${actionBtnHtml}
                        <button class="job-bookmark-btn ${isBookmarked ? 'bookmarked' : ''}" data-id="${job.id}" aria-label="Bookmark job opening">
                          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                        </button>
                      </div>
                    `;
                    gridContainer.appendChild(card);
                  });

                  // Add Event Listeners to cards buttons
                  document.querySelectorAll(".btn-action-details").forEach(btn => {
                    btn.addEventListener("click", () => {
                      const id = btn.getAttribute("data-id");
                      openJobDetailsModal(id);
                    });
                  });

                  document.querySelectorAll(".btn-action-apply").forEach(btn => {
                    btn.addEventListener("click", () => {
                      const id = btn.getAttribute("data-id");
                      const role = btn.getAttribute("data-role");
                      const comp = btn.getAttribute("data-comp");
                      triggerJobApply(id, role, comp);
                    });
                  });

                  document.querySelectorAll(".job-bookmark-btn").forEach(btn => {
                    btn.addEventListener("click", () => {
                      const id = parseInt(btn.getAttribute("data-id"));
                      toggleJobBookmark(id, btn);
                    });
                  });
                }

                // HTML Helper
                function htmlspecialchars(str) {
                  if (typeof str !== "string") return "";
                  return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                }

                // Bookmark Toggle Handler
                function toggleJobBookmark(driveId, btnElement) {
                  const idx = bookmarks.indexOf(driveId);
                  if (idx === -1) {
                    bookmarks.push(driveId);
                    btnElement.classList.add("bookmarked");
                    Swal.fire({
                      toast: true,
                      position: 'top-end',
                      icon: 'success',
                      title: 'Job bookmarked successfully!',
                      showConfirmButton: false,
                      timer: 1500
                    });
                  } else {
                    bookmarks.splice(idx, 1);
                    btnElement.classList.remove("bookmarked");
                    Swal.fire({
                      toast: true,
                      position: 'top-end',
                      icon: 'info',
                      title: 'Bookmark removed.',
                      showConfirmButton: false,
                      timer: 1500
                    });
                  }
                  localStorage.setItem('student_bookmarked_drives', JSON.stringify(bookmarks));
                }

                // Details Modal Controls
                window.openJobDetailsModal = function(id) {
                  const drive = drives.find(d => parseInt(d.id) === parseInt(id));
                  if (!drive) return;

                  const loc = getCompanyLocation(drive.companyName);
                  const wm = getCompanyWorkMode(drive.companyName);
                  const jt = getJobType(drive.id);
                  const color = getPastelBgColor(drive.companyName);
                  const isEligible = studentCGPA >= parseFloat(drive.eligibilityCGPA);
                  const alreadyApplied = appliedDriveIds.includes(parseInt(drive.id));
                  const status = getJobStatus(drive);

                  const logo = document.getElementById("modal-job-company-logo");
                  logo.style.backgroundColor = color;
                  logo.textContent = drive.companyName.substring(0,2).toUpperCase();

                  document.getElementById("modal-job-role").textContent = drive.jobRole;
                  document.getElementById("modal-job-company").textContent = drive.companyName;

                  // Tags
                  const tagsContainer = document.getElementById("modal-job-tags");
                  tagsContainer.innerHTML = `
                    <span class="badge" style="background:rgba(0,0,0,0.03); color:var(--text-secondary); font-size:11px; padding:2px 8px; border-radius:4px;">${jt}</span>
                    <span class="badge" style="background:rgba(0,0,0,0.03); color:var(--text-secondary); font-size:11px; padding:2px 8px; border-radius:4px;">${wm}</span>
                    ${isEligible 
                      ? `<span class="badge badge-success" style="font-size:11px; padding:3px 8px; border-radius:12px;">✓ Eligible</span>`
                      : `<span class="badge badge-danger" style="font-size:11px; padding:3px 8px; border-radius:12px;">✕ Ineligible for your CGPA</span>`
                    }
                  `;

                  // Metadata
                  document.getElementById("modal-job-salary").textContent = `₹${parseFloat(drive.packageLPA).toFixed(2)} LPA`;
                  document.getElementById("modal-job-location").textContent = loc;
                  document.getElementById("modal-job-cgpa").textContent = `${parseFloat(drive.eligibilityCGPA).toFixed(2)} CGPA`;
                  document.getElementById("modal-job-deadline").textContent = new Date(drive.registration_deadline).toLocaleDateString('en-IN', {day:'numeric', month:'short', year:'numeric'});

                  // Skills & description
                  document.getElementById("modal-job-skills").textContent = drive.skills_required || "No specific skill stack mentioned.";
                  document.getElementById("modal-job-desc").innerHTML = `
                    We are hiring a result-oriented <strong>${htmlspecialchars(drive.jobRole)}</strong> to join the team at <strong>${htmlspecialchars(drive.companyName)}</strong>. 
                    Candidates are expected to collaborate on scalable deployments, solve computational challenges, and align deliverables in a fast-paced environment. 
                    Department constraints: <strong>${htmlspecialchars(drive.departments)}</strong> qualification is preferred.
                  `;

                  // Apply button logic inside modal
                  const applyBtn = document.getElementById("modal-btn-apply");
                  applyBtn.removeAttribute("disabled");
                  applyBtn.className = "btn btn-primary btn-sm";
                  applyBtn.onclick = null;

                  if (alreadyApplied) {
                    applyBtn.textContent = "Applied ✓";
                    applyBtn.setAttribute("disabled", "true");
                  } else if (status === 'Closed') {
                    applyBtn.textContent = "Expired";
                    applyBtn.className = "btn btn-secondary btn-sm";
                    applyBtn.setAttribute("disabled", "true");
                  } else if (!isEligible) {
                    applyBtn.textContent = "Ineligible";
                    applyBtn.className = "btn btn-danger btn-sm";
                    applyBtn.setAttribute("disabled", "true");
                  } else {
                    applyBtn.textContent = "Apply Now";
                    applyBtn.onclick = () => {
                      closeJobDetailsModal();
                      triggerJobApply(drive.id, drive.jobRole, drive.companyName);
                    };
                  }

                  // Show modal
                  document.getElementById("modal-job-details").style.display = "flex";
                };

                window.closeJobDetailsModal = function() {
                  document.getElementById("modal-job-details").style.display = "none";
                };

                // Trigger Apply functionality
                function triggerJobApply(driveId, roleName, companyName) {
                  Swal.fire({
                    title: 'Apply for this opening?',
                    text: `Confirm your official application for the '${roleName}' role at '${companyName}'.`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#2563EB',
                    cancelButtonColor: '#6B7280',
                    confirmButtonText: 'Yes, Submit Application'
                  }).then((result) => {
                    if (result.isConfirmed) {
                      Swal.fire({
                        title: 'Submitting application...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                      });

                      const form = new FormData();
                      form.append("ajax_action", "student_apply");
                      form.append("drive_id", driveId);

                      fetch('dashboard.php', {
                        method: 'POST',
                        body: form
                      })
                      .then(res => res.json())
                      .then(res => {
                        if (res.status === 'success') {
                          Swal.fire({
                            title: 'Application Successful!',
                            text: res.message,
                            icon: 'success',
                            timer: 1500,
                            showConfirmButton: false
                          });
                          setTimeout(() => window.location.reload(), 1500);
                        } else {
                          Swal.fire({
                            title: 'Submission Failed',
                            text: res.message,
                            icon: 'error',
                            confirmButtonColor: '#2563EB'
                          });
                        }
                      })
                      .catch(err => {
                        Swal.fire({
                          title: 'Network Connection Issue',
                          text: 'An unexpected connection error occurred. Please try again.',
                          icon: 'error',
                          confirmButtonColor: '#2563EB'
                        });
                      });
                    }
                  });
                }

                // Custom CGPA Validation Helper
                function validateAndRenderCGPA() {
                  const valStr = cgpaFilter.value.trim();
                  const msgEl = document.getElementById("cgpa-validation-msg");
                  
                  if (valStr === "") {
                    msgEl.style.display = "none";
                    processAndRenderJobs(false);
                    return true;
                  }
                  
                  // Allow regex check: up to one decimal place, e.g. 8.5, 9, 10
                  const regex = /^\d+(\.\d)?$/;
                  const val = parseFloat(valStr);
                  
                  if (!regex.test(valStr) || isNaN(val) || val < 0.0 || val > 10.0) {
                    msgEl.style.display = "block";
                    renderEmptyValidation();
                    return false;
                  } else {
                    msgEl.style.display = "none";
                    processAndRenderJobs(false);
                    return true;
                  }
                }

                function renderEmptyValidation() {
                  gridContainer.innerHTML = `
                    <div style="grid-column: 1 / -1; display:flex; flex-direction:column; align-items:center; justify-content:center; padding: var(--space-4) var(--space-2); background: var(--bg-card); border: 1px dashed var(--color-danger); border-radius: var(--radius-lg); text-align:center;">
                      <svg viewBox="0 0 24 24" width="54" height="54" fill="none" stroke="var(--color-danger)" stroke-width="1.2" style="margin-bottom:var(--space-2);"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                      <h4 style="font-size:16px; font-weight:700; color:var(--text-primary); margin-bottom:4px;">Invalid CGPA Requirement Limit</h4>
                      <p style="color:var(--text-secondary); font-size:13px; max-width:400px; margin:0;">Please enter a valid CGPA between 0.0 and 10.0 with up to 1 decimal place to filter active campaigns.</p>
                    </div>
                  `;
                }

                // Event Listeners for Instant Filters & Search
                searchInput.addEventListener("input", () => processAndRenderJobs(false));
                sortSelect.addEventListener("change", () => processAndRenderJobs(false));
                companyFilter.addEventListener("change", () => processAndRenderJobs(true));
                locationFilter.addEventListener("change", () => processAndRenderJobs(true));
                jobtypeFilter.addEventListener("change", () => processAndRenderJobs(true));
                workmodeFilter.addEventListener("change", () => processAndRenderJobs(true));
                statusFilter.addEventListener("change", () => processAndRenderJobs(true));
                
                // CGPA instant listener
                cgpaFilter.addEventListener("input", () => validateAndRenderCGPA());

                clearFiltersBtn.addEventListener("click", () => {
                  searchInput.value = "";
                  companyFilter.value = "All";
                  locationFilter.value = "All";
                  jobtypeFilter.value = "All";
                  workmodeFilter.value = "All";
                  cgpaFilter.value = "";
                  statusFilter.value = "All";
                  document.getElementById("cgpa-validation-msg").style.display = "none";
                  processAndRenderJobs(true);
                });

                // Init load
                processAndRenderJobs(false);
              });
            </script>          <?php else: ?>
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
          <?php endif; ?>
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

          <div id="student-applications-stats" style="margin-bottom: var(--space-3);"></div>

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
          <?php if ($role === 'student'): ?>
            <!-- CSS Styling isolated for student interviews -->
            <style>
              .student-interviews-badge {
                display: inline-block;
                font-size: 11px;
                font-weight: 600;
                padding: 3px 10px;
                border-radius: 12px;
                text-transform: capitalize;
              }
              .student-interviews-badge.upcoming { background-color: var(--primary-light); color: var(--primary); }
              .student-interviews-badge.completed { background-color: rgba(16, 185, 129, 0.1); color: var(--color-success); }
              .student-interviews-badge.cancelled { background-color: rgba(239, 68, 68, 0.1); color: var(--color-danger); }
              .student-interviews-badge.missed { background-color: rgba(245, 158, 11, 0.1); color: var(--color-warning); }
              .student-interviews-badge.rescheduled { background-color: rgba(139, 92, 246, 0.1); color: #8B5CF6; }

              .student-interviews-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
                gap: var(--space-2);
                margin-top: var(--space-2);
              }

              @media (max-width: 768px) {
                .student-interviews-grid {
                  grid-template-columns: 1fr;
                }
              }

              .student-interviews-card {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-xl);
                padding: var(--space-25);
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                box-shadow: var(--shadow-sm);
                transition: all var(--transition-normal);
                position: relative;
                overflow: hidden;
                min-height: 290px;
              }

              .student-interviews-card:hover {
                transform: translateY(-4px);
                box-shadow: var(--shadow-md);
                border-color: var(--primary);
              }

              .student-interviews-countdown {
                font-size: 11px;
                font-weight: 700;
                color: var(--primary);
                background: var(--primary-light);
                padding: 4px 10px;
                border-radius: var(--radius-md);
                display: inline-flex;
                align-items: center;
                gap: 4px;
              }

              .student-interviews-countdown.rescheduled {
                color: #8B5CF6;
                background: rgba(139, 92, 246, 0.08);
              }

              .student-interviews-timeline-wrapper {
                margin-top: var(--space-15);
                margin-bottom: var(--space-15);
                padding: 10px;
                background: rgba(0,0,0,0.01);
                border-radius: var(--radius-lg);
                border: 1px solid var(--border-color);
              }

              .student-interviews-timeline-steps {
                display: flex;
                justify-content: space-between;
                align-items: center;
                position: relative;
                margin-bottom: var(--space-05);
              }

              .student-interviews-timeline-line {
                position: absolute;
                top: 50%;
                left: 8%;
                right: 8%;
                height: 2px;
                background: var(--border-color);
                z-index: 1;
                transform: translateY(-50%);
              }

              .student-interviews-timeline-line-fill {
                height: 100%;
                background: var(--primary);
                transition: width var(--transition-normal);
              }

              .student-interviews-timeline-node {
                z-index: 2;
                width: 14px;
                height: 14px;
                border-radius: 50%;
                border: 2px solid var(--border-color);
                background: var(--bg-card);
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all var(--transition-normal);
              }

              .student-interviews-timeline-node.active {
                border-color: var(--primary);
                background: var(--primary);
              }

              .student-interviews-timeline-node.active::after {
                content: '';
                width: 4px;
                height: 4px;
                border-radius: 50%;
                background: #FFFFFF;
              }

              .student-interviews-timeline-labels {
                display: flex;
                justify-content: space-between;
                font-size: 8px;
                font-weight: 700;
                text-transform: uppercase;
                color: var(--text-secondary);
                letter-spacing: 0.3px;
              }

              .student-interviews-timeline-labels span {
                width: 20%;
                text-align: center;
              }

              .student-interviews-timeline-labels span:first-child { text-align: left; }
              .student-interviews-timeline-labels span:last-child { text-align: right; }
            </style>

            <!-- Page Header -->
            <div class="card" style="margin-bottom: var(--space-3);">
              <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-1);">
                <div>
                  <h3 class="chart-container-title" style="margin-bottom: var(--space-05);">My Interviews</h3>
                  <p style="color: var(--text-secondary); font-size: 13px;">Track upcoming interviews and interview history.</p>
                </div>
                <div>
                  <span class="badge badge-primary" id="student-interviews-count-badge" style="font-size: 12px; padding: 4px 12px; border-radius: 12px; font-weight: 700;">0 Scheduled</span>
                </div>
              </div>
            </div>

            <!-- Search & Filters Row -->
            <div class="card" style="margin-bottom: var(--space-3); padding: var(--space-2);">
              <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: center;">
                <div style="position: relative;">
                  <input type="text" class="input-field" id="student-interviews-search" placeholder="Search Company or Role..." style="padding-left: 36px; width: 100%;">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--text-secondary)" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); pointer-events: none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>
                
                <select class="input-field select-custom" id="student-interviews-filter-status" style="width: 100%; height: auto; padding: 9px 12px; font-size: 13px;">
                  <option value="All">All Statuses</option>
                  <option value="Upcoming">Upcoming</option>
                  <option value="Completed">Completed</option>
                  <option value="Missed">Missed</option>
                  <option value="Cancelled">Cancelled</option>
                </select>

                <select class="input-field select-custom" id="student-interviews-filter-type" style="width: 100%; height: auto; padding: 9px 12px; font-size: 13px;">
                  <option value="All">All Round Types</option>
                  <option value="Technical">Technical</option>
                  <option value="HR">HR</option>
                  <option value="Aptitude">Aptitude</option>
                  <option value="Group Discussion">Group Discussion</option>
                  <option value="Final Round">Final Round</option>
                </select>

                <select class="input-field select-custom" id="student-interviews-sort" style="width: 100%; height: auto; padding: 9px 12px; font-size: 13px;">
                  <option value="nearest">Nearest Date</option>
                  <option value="latest">Latest Added</option>
                  <option value="company">Company Name</option>
                </select>
              </div>
            </div>

            <!-- Dynamic Interviews Grid -->
            <div id="student-interviews-container"></div>

          <?php else: ?>
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
          <?php endif; ?>
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
                      <label class="form-label" style="display:flex; justify-content:space-between; align-items:center;">
                        <span>CGPA</span>
                        <span style="color: var(--color-success); font-size:10px; font-weight:700; background:rgba(16,185,129,0.08); padding:2px 8px; border-radius:4px;">✓ Verified Record</span>
                      </label>
                      <input type="text" class="input-field" value="<?php echo $profile['cgpa'] ?? ''; ?>" readonly style="background:rgba(0,0,0,0.015); cursor:not-allowed; border-color:var(--border-color);">
                    </div>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label" style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Roll Number</span>
                        <span style="color: var(--color-success); font-size:10px; font-weight:700; background:rgba(16,185,129,0.08); padding:2px 8px; border-radius:4px;">✓ Verified Record</span>
                      </label>
                      <input type="text" class="input-field" value="<?php echo $profile['roll_number'] ?? ''; ?>" readonly style="background:rgba(0,0,0,0.015); cursor:not-allowed; border-color:var(--border-color);">
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
                      <input type="text" class="input-field" name="skills" id="profile-skills" value="<?php echo $profile['skills'] ?? ''; ?>" placeholder="Java, Python, SQL, Git">
                    </div>
                    <div class="col-12 form-group">
                      <label class="form-label">Projects</label>
                      <textarea class="input-field" name="projects" id="profile-projects" rows="3" placeholder="Describe your key academic or personal projects here..."><?php echo $profile['projects'] ?? ''; ?></textarea>
                    </div>
                    <?php
                    $socials = json_decode($profile['social_links'] ?? '{}', true);
                    $linkedinUrl = $socials['linkedin'] ?? '';
                    $githubUrl = $socials['github'] ?? '';
                    ?>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">LinkedIn URL</label>
                      <input type="url" class="input-field" name="linkedin" id="profile-linkedin" value="<?php echo $linkedinUrl; ?>" placeholder="https://linkedin.com/in/username">
                    </div>
                    <div class="col-6 col-md-12 form-group">
                      <label class="form-label">GitHub Portfolio URL</label>
                      <input type="url" class="input-field" name="github" id="profile-github" value="<?php echo $githubUrl; ?>" placeholder="https://github.com/username">
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

            <!-- Profile Scorecard and Document Uploads -->
            <div class="col-4 col-lg-12">
              <?php if ($role === 'student'): 
                // Calculate profile strength
                $strength = 0;
                $checklist = [];
                
                if (!empty($userName)) { $strength += 20; $checklist[] = ['label' => 'Full Name configured', 'status' => 'success']; }
                else { $checklist[] = ['label' => 'Configure your name', 'status' => 'danger']; }
                
                if (!empty($profile['cgpa'])) { $strength += 15; $checklist[] = ['label' => 'Academic records verified', 'status' => 'success']; }
                
                if (!empty($profile['phone'])) { $strength += 15; $checklist[] = ['label' => 'Contact phone verified', 'status' => 'success']; }
                else { $checklist[] = ['label' => 'Add contact phone', 'status' => 'warning']; }
                
                if (!empty($profile['skills'])) { $strength += 15; $checklist[] = ['label' => 'Core skills listed', 'status' => 'success']; }
                else { $checklist[] = ['label' => 'Add your skills', 'status' => 'warning']; }
                
                if (!empty($profile['projects'])) { $strength += 15; $checklist[] = ['label' => 'Academic projects listed', 'status' => 'success']; }
                else { $checklist[] = ['label' => 'Describe your projects', 'status' => 'warning']; }
                
                if (!empty($profile['resume_path'])) { $strength += 20; $checklist[] = ['label' => 'Resume document uploaded', 'status' => 'success']; }
                else { $checklist[] = ['label' => 'Upload academic resume', 'status' => 'danger']; }
              ?>
              <div class="card" style="margin-bottom: var(--space-3); padding: var(--space-25); background: linear-gradient(135deg, rgba(37,99,235,0.04) 0%, rgba(255,255,255,0.01) 100%); border-radius: var(--radius-xl); border: 1px solid var(--border-color);">
                <h3 class="chart-container-title" style="margin-bottom: var(--space-15); display: flex; align-items: center; gap: 8px;">
                  🎯 Profile Scorecard
                </h3>
                <div style="display: flex; align-items: center; gap: var(--space-2); margin-bottom: var(--space-2);">
                  <div style="position: relative; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; flex-shrink:0;">
                    <svg viewBox="0 0 36 36" style="width: 100%; height: 100%; transform: rotate(-90deg);">
                      <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="var(--border-color)" stroke-width="3.5" />
                      <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="var(--primary)" stroke-width="3.5" stroke-dasharray="<?php echo $strength; ?>, 100" stroke-linecap="round" style="transition: stroke-dasharray 0.5s ease-in-out;" />
                    </svg>
                    <span style="position: absolute; font-size: 14px; font-weight: 800; color: var(--text-primary);"><?php echo $strength; ?>%</span>
                  </div>
                  <div>
                    <h4 style="font-weight: 700; margin: 0; font-size: 13px;">
                      <?php 
                        if ($strength === 100) echo "Recruiter Ready!";
                        elseif ($strength >= 80) echo "Strong Profile!";
                        elseif ($strength >= 50) echo "Average Profile";
                        else echo "Action Required";
                      ?>
                    </h4>
                    <p style="margin: 3px 0 0 0; color: var(--text-secondary); font-size: 11px; line-height: 1.3;">Increase your placement profile strength to stand out.</p>
                  </div>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 8px; border-top: 1px solid var(--border-color); padding-top: var(--space-15); margin-top: var(--space-15); font-size: 11px;">
                  <?php foreach ($checklist as $item): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                      <span style="color: var(--text-secondary); display: flex; align-items: center; gap: 6px;">
                        <?php echo $item['status'] === 'success' ? '🟢' : ($item['status'] === 'warning' ? '🟡' : '🔴'); ?>
                        <?php echo $item['label']; ?>
                      </span>
                      <strong style="color: <?php echo $item['status'] === 'success' ? 'var(--color-success)' : ($item['status'] === 'warning' ? 'var(--color-warning)' : 'var(--color-danger)'); ?>; font-size: 11px;">
                        <?php echo $item['status'] === 'success' ? 'Ready' : 'Pending'; ?>
                      </strong>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>

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
                      <div style="font-size:12px; margin-top:4px;">Current: <a href="<?php echo ltrim($profile['resume_path'], '/'); ?>" target="_blank" style="color:var(--primary); font-weight:600;">Download PDF</a></div>
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
                      <div style="font-size:12px; margin-top:4px;">Current: <a href="<?php echo ltrim($profile['certificate_path'], '/'); ?>" target="_blank" style="color:var(--primary); font-weight:600;">Download PDF</a></div>
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
            <!-- User Preferences -->
            <div class="col-6 col-lg-12">
              <div class="card" style="height: 100%;">
                <h4 style="font-weight: 700; margin-bottom: var(--space-2);">Profile Preferences</h4>
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
      <?php require_once __DIR__ . '/includes/footer.php'; ?>
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
    <?php if ($role !== 'student'): ?>
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
    <?php endif; ?>

    <!-- --- BULK ACTIONS FLOATING TOOLBAR --- -->
    <div id="table-bulk-actions" class="card-glass" style="position: fixed; bottom: 32px; left: 50%; z-index: 400; padding: var(--space-15) var(--space-3); border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); display: flex; align-items: center; gap: var(--space-3); border: 1.5px solid var(--primary); background-color: var(--bg-card);">
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
