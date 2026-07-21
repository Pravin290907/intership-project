<?php
/**
 * Placement Portal Operations Manager
 * Handles user verification, drive cloning, interview schedules, results publishing, and database backups.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/auth.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['status' => 'error', 'message' => 'Unauthorized session.']);
  exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$db = getDB();
$role = $_SESSION['user_role'];

try {
  switch ($action) {
    // 1. APPROVE / SUSPEND / ACTIVATE USERS (Admin / TPO privilege)
    case 'update_user_status':
      if ($role !== 'admin' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient privilege.']);
        exit;
      }
      
      $targetUserId = (int)$_POST['target_user_id'];
      $newStatus = $_POST['status']; // 'approved', 'suspended'
      
      // Prevent deleting or altering self
      if ($targetUserId === (int)$_SESSION['user_id']) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot alter your own status.']);
        exit;
      }

      // Check target role
      $stmtCheck = $db->prepare("SELECT name, role FROM users WHERE id = ?");
      $stmtCheck->execute([$targetUserId]);
      $target = $stmtCheck->fetch();

      if (!$target) {
        echo json_encode(['status' => 'error', 'message' => 'User not found.']);
        exit;
      }

      // TPO cannot alter Admin status
      if ($role === 'tpo' && $target['role'] === 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'TPO cannot suspend or alter Administrator accounts.']);
        exit;
      }

      $stmtUpdate = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
      $stmtUpdate->execute([$newStatus, $targetUserId]);

      if ($newStatus === 'approved') {
        createUserNotification(
          $targetUserId,
          "Registration Approved",
          "Your profile registration has been approved. Welcome to CampusRecruit!",
          "registration_status",
          "high"
        );
      } else if ($newStatus === 'suspended') {
        createUserNotification(
          $targetUserId,
          "Account Suspended",
          "Your account status has been set to suspended. Please contact TPO/Admin.",
          "registration_status",
          "high"
        );
      }

      logActivity("Altered user status of {$target['name']} to $newStatus", "success");
      createAdminNotification(
        "User Status Updated",
        "Account of {$target['name']} ({$target['role']}) has been marked as $newStatus by {$_SESSION['user_name']}.",
        "user_management",
        "medium",
        $target['role'] === 'student' ? 'students' : 'companies'
      );

      echo json_encode(['status' => 'success', 'message' => 'User status updated to ' . $newStatus]);
      break;

    // 2. CLONE RECRUITMENT DRIVE
    case 'clone_drive':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $driveId = (int)$_POST['drive_id'];

      // Fetch drive details
      $stmtDrive = $db->prepare("SELECT * FROM drives WHERE id = ?");
      $stmtDrive->execute([$driveId]);
      $d = $stmtDrive->fetch();

      if (!$d) {
        echo json_encode(['status' => 'error', 'message' => 'Drive not found.']);
        exit;
      }

      // Company can only clone its own drives
      if ($role === 'company' && (int)$d['company_id'] !== (int)$_SESSION['user_id']) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot clone drives of other companies.']);
        exit;
      }

      $newRoleName = $d['job_role'] . ' (Copy)';
      $deadline = date('Y-m-d', strtotime('+7 days'));
      $commence = date('Y-m-d', strtotime('+10 days'));

      $stmtClone = $db->prepare("
        INSERT INTO drives (company_id, job_role, eligibility_cgpa, package_lpa, drive_date, status, skills_required, registration_deadline, departments)
        VALUES (?, ?, ?, ?, ?, 'upcoming', ?, ?, ?)
      ");
      $stmtClone->execute([
        $d['company_id'],
        $newRoleName,
        $d['eligibility_cgpa'],
        $d['package_lpa'],
        $commence,
        $d['skills_required'],
        $deadline,
        $d['departments']
      ]);

      // Notify company/recruiter
      createUserNotification(
        $d['company_id'],
        "Drive Published Successfully",
        "Your new placement drive for the role '$newRoleName' has been published successfully.",
        "drive_published",
        "medium",
        "drives"
      );

      // Broadcast to all active approved students
      $students = $db->query("SELECT id FROM users WHERE role = 'student' AND status = 'approved'")->fetchAll();
      foreach ($students as $stu) {
        createUserNotification(
          $stu['id'],
          "New Placement Drive",
          "A new drive for '$newRoleName' has been published by {$d['company_name']}.",
          "new_drive",
          "medium",
          "drives"
        );
      }

      logActivity("Cloned placement drive: {$d['job_role']}", "success");
      createAdminNotification(
        "Placement Drive Cloned",
        "A duplicate drive '$newRoleName' has been set up for {$d['company_name']}.",
        "placement_drive",
        "low",
        "drives"
      );

      echo json_encode(['status' => 'success', 'message' => 'Drive cloned successfully as draft']);
      break;

    // 2.5 CREATE PLACEMENT DRIVE
    case 'create_drive':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $job_role = trim($_POST['job_role'] ?? '');
      $eligibility_cgpa = $_POST['eligibility_cgpa'] ?? '';
      $package_lpa = $_POST['package_lpa'] ?? '';
      $drive_date = $_POST['drive_date'] ?? '';
      $registration_deadline = $_POST['registration_deadline'] ?? '';
      $departments = trim($_POST['departments'] ?? '');
      $skills_required = trim($_POST['skills_required'] ?? '');

      if ($role === 'company') {
        $company_id = $_SESSION['user_id'];
      } else {
        $company_id = $_POST['company_id'] ?? '';
      }

      // Validations
      if (empty($job_role)) {
        echo json_encode(['status' => 'error', 'message' => 'Drive Title is required.']);
        exit;
      }
      if (empty($company_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Company is required.']);
        exit;
      }
      if (!is_numeric($eligibility_cgpa) || $eligibility_cgpa < 0 || $eligibility_cgpa > 10) {
        echo json_encode(['status' => 'error', 'message' => 'Minimum CGPA Criteria must be a number between 0 and 10.']);
        exit;
      }
      if (!is_numeric($package_lpa) || $package_lpa <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Compensation LPA must be a positive number.']);
        exit;
      }
      if (empty($drive_date) || !strtotime($drive_date)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid interview date.']);
        exit;
      }
      if (empty($registration_deadline) || !strtotime($registration_deadline)) {
        echo json_encode(['status' => 'error', 'message' => 'Registration deadline is required.']);
        exit;
      }
      if (empty($departments)) {
        echo json_encode(['status' => 'error', 'message' => 'Target Branches are required.']);
        exit;
      }

      $db->beginTransaction();

      $stmt = $db->prepare("
        INSERT INTO drives (company_id, job_role, eligibility_cgpa, package_lpa, drive_date, status, skills_required, registration_deadline, departments)
        VALUES (?, ?, ?, ?, ?, 'upcoming', ?, ?, ?)
      ");
      $stmt->execute([
        $company_id,
        $job_role,
        $eligibility_cgpa,
        $package_lpa,
        $drive_date,
        $skills_required,
        $registration_deadline,
        $departments
      ]);

      // Fetch company name for notifications
      $stmtComp = $db->prepare("SELECT company_name FROM companies WHERE user_id = ?");
      $stmtComp->execute([$company_id]);
      $compName = $stmtComp->fetchColumn() ?: 'Recruiter';

      // Notify company/recruiter
      createUserNotification(
        $company_id,
        "Drive Published Successfully",
        "Your new placement drive for the role '$job_role' has been published successfully.",
        "drive_published",
        "medium",
        "drives"
      );

      // Broadcast to all active approved students
      $students = $db->query("SELECT id FROM users WHERE role = 'student' AND status = 'approved'")->fetchAll();
      foreach ($students as $stu) {
        createUserNotification(
          $stu['id'],
          "New Placement Drive",
          "A new drive for '$job_role' has been published by $compName.",
          "new_drive",
          "medium",
          "drives"
        );
      }

      $db->commit();

      logActivity("Created placement drive: $job_role", "success");
      createAdminNotification(
        "Placement Drive Created",
        "A new drive '$job_role' has been set up for $compName.",
        "placement_drive",
        "low",
        "drives"
      );

      echo json_encode(['status' => 'success', 'message' => 'Placement Drive Created Successfully.']);
      break;

    // 3. SCHEDULE INTERVIEWS
    case 'schedule_interview':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $appId = (int)$_POST['application_id'];
      $date = $_POST['date'];
      $time = $_POST['time'];
      $venue = filter_input(INPUT_POST, 'venue', FILTER_SANITIZE_SPECIAL_CHARS);
      $interviewer = filter_input(INPUT_POST, 'interviewer', FILTER_SANITIZE_SPECIAL_CHARS);

      // Verify application exists
      $stmtApp = $db->prepare("SELECT a.id, u.name as stu_name, a.student_id FROM applications a JOIN users u ON a.student_id=u.id WHERE a.id = ?");
      $stmtApp->execute([$appId]);
      $app = $stmtApp->fetch();

      if (!$app) {
        echo json_encode(['status' => 'error', 'message' => 'Application not found.']);
        exit;
      }

      // Save interview
      $stmtInt = $db->prepare("INSERT INTO interviews (application_id, date, time, venue, interviewer, result, attendance) VALUES (?, ?, ?, ?, ?, 'Scheduled', 'Pending')");
      $stmtInt->execute([$appId, $date, $time, $venue, $interviewer]);

      // Move application status to technical or HR
      $db->prepare("UPDATE applications SET status = 'Technical' WHERE id = ? AND status = 'Applied'")->execute([$appId]);

      // Notify the student
      createUserNotification(
        $app['student_id'],
        "Interview Scheduled",
        "An interview has been scheduled for you on $date at $time. Venue: $venue. Interviewer: $interviewer.",
        "interview",
        "high",
        "interviews"
      );

      createAdminNotification(
        "Interview Scheduled",
        "Technical round for {$app['stu_name']} set at $time, $date.",
        "interview",
        "medium",
        "interviews"
      );

      echo json_encode(['status' => 'success', 'message' => 'Interview round scheduled successfully']);
      break;

    // 4. PUBLISH RESULTS / GENERATE OFFER
    case 'publish_selection':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $appId = (int)$_POST['application_id'];
      $result = $_POST['result']; // 'Selected', 'Rejected'

      $db->beginTransaction();

      $stmtApp = $db->prepare("SELECT a.*, u.name as student_name, d.job_role, d.package_lpa, c.company_name FROM applications a JOIN users u ON a.student_id=u.id JOIN drives d ON a.drive_id=d.id JOIN companies c ON d.company_id=c.user_id WHERE a.id = ?");
      $stmtApp->execute([$appId]);
      $app = $stmtApp->fetch();

      if (!$app) {
        echo json_encode(['status' => 'error', 'message' => 'Application record not found.']);
        exit;
      }

      $stmtUpdate = $db->prepare("UPDATE applications SET status = ? WHERE id = ?");
      $stmtUpdate->execute([$result, $appId]);

      if ($result === 'Selected') {
        // Increment hire count
        $db->prepare("UPDATE companies SET students_hired = students_hired + 1 WHERE user_id = ?")->execute([$app['company_id']]);
        
        // Generate draft offer
        $stmtOffer = $db->prepare("INSERT INTO offers (application_id, salary_lpa, designation, joining_date, location, status) VALUES (?, ?, ?, ?, 'Bangalore Center', 'Released')");
        $stmtOffer->execute([
          $appId,
          $app['package_lpa'],
          $app['job_role'],
          date('Y-m-d', strtotime('+30 days'))
        ]);

        // Notify student: application accepted and offer released
        createUserNotification(
          $app['student_id'],
          "Application Accepted",
          "Congratulations! Your application for the role '{$app['job_role']}' at '{$app['company_name']}' has been accepted.",
          "application_status",
          "high",
          "applications"
        );
        createUserNotification(
          $app['student_id'],
          "Offer Released",
          "An offer of ₹{$app['package_lpa']} LPA for the designation '{$app['job_role']}' has been released by {$app['company_name']}.",
          "offer_status",
          "high",
          "applications"
        );
      } else {
        // Notify student: application rejected
        createUserNotification(
          $app['student_id'],
          "Application Rejected",
          "We regret to inform you that your application for the role '{$app['job_role']}' at '{$app['company_name']}' was not accepted.",
          "application_status",
          "medium",
          "applications"
        );
      }

      $db->commit();

      createAdminNotification(
        "Placement Selection Result Published",
        "Student {$app['student_name']} marked as $result by {$app['company_name']}.",
        "selection",
        "high"
      );

      echo json_encode(['status' => 'success', 'message' => 'Selection result updated successfully']);
      break;

    // 5. DATABASE BACKUP UTILITY (SQL Exporter)
    case 'backup_database':
      if ($role !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Only systems administrator can backup database tables.']);
        exit;
      }

      $tables = ['users', 'students', 'companies', 'drives', 'applications', 'interviews', 'offers', 'notifications', 'activity_logs'];
      $sqlDump = "-- Campus Recruitment Portal DUMP\n-- Date: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

      foreach ($tables as $tbl) {
        // Table structure DDL
        $ddl = $db->query("SHOW CREATE TABLE `$tbl`")->fetch();
        $sqlDump .= "DROP TABLE IF EXISTS `$tbl`;\n" . $ddl['Create Table'] . ";\n\n";

        // Rows data DML
        $rows = $db->query("SELECT * FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 0) {
          $sqlDump .= "INSERT INTO `$tbl` VALUES\n";
          $valLines = [];
          foreach ($rows as $row) {
            $escaped = array_map(function($val) use ($db) {
              if ($val === null) return 'NULL';
              return $db->quote($val);
            }, $row);
            $valLines[] = "(" . implode(", ", $escaped) . ")";
          }
          $sqlDump .= implode(",\n", $valLines) . ";\n\n";
        }
      }
      $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

      // Return backup file stream header
      header('Content-Description: File Transfer');
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename="crms_backup_' . date('Ymd_His') . '.sql"');
      echo $sqlDump;
      exit;

    // 6. DATABASE RESTORE UTILITY
    case 'restore_database':
      if ($role !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Access restricted.']);
        exit;
      }

      if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to read uploaded backup file.']);
        exit;
      }

      $sqlContent = file_get_contents($_FILES['backup_file']['tmp_name']);
      
      // Execute queries
      $db->exec($sqlContent);

      logActivity("Restored database backup", "success");
      echo json_encode(['status' => 'success', 'message' => 'Database tables restored successfully!']);
      break;

    // 7. UPDATE PROFILE DETAILS
    case 'update_profile':
      $name = trim($_POST['name'] ?? '');
      if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => 'Full name cannot be empty.']);
        exit;
      }

      $db->beginTransaction();
      
      // Update name in users table
      $stmtUser = $db->prepare("UPDATE users SET name = ? WHERE id = ?");
      $stmtUser->execute([$name, $_SESSION['user_id']]);
      
      // Update session name
      $_SESSION['user_name'] = $name;

      // Update role-specific fields
      if ($role === 'student') {
        $skills = trim($_POST['skills'] ?? '');
        $projects = trim($_POST['projects'] ?? '');
        if (isset($_POST['phone'])) {
          $phone = trim($_POST['phone']);
          if (!preg_match('/^[0-9]{10}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.']);
            exit;
          }
          $stmtStudent = $db->prepare("UPDATE students SET skills = ?, projects = ?, phone = ? WHERE user_id = ?");
          $stmtStudent->execute([$skills, $projects, $phone, $_SESSION['user_id']]);
        } else {
          $stmtStudent = $db->prepare("UPDATE students SET skills = ?, projects = ? WHERE user_id = ?");
          $stmtStudent->execute([$skills, $projects, $_SESSION['user_id']]);
        }
      } else if ($role === 'company') {
        $website = trim($_POST['website'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if (!preg_match('/^[0-9]{10}$/', $phone)) {
          echo json_encode(['status' => 'error', 'message' => 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.']);
          exit;
        }
        $stmtCompany = $db->prepare("UPDATE companies SET website = ?, phone = ? WHERE user_id = ?");
        $stmtCompany->execute([$website, $phone, $_SESSION['user_id']]);
      }

      $db->commit();
      
      logActivity("Updated profile details", "success");
      echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully!', 'user_name' => $name]);
      break;

    // 8. UPDATE SETTINGS (Language & Theme)
    case 'update_settings':
      $language = trim($_POST['language'] ?? 'en');
      $theme = trim($_POST['theme'] ?? 'system');

      // Validate inputs
      if (!in_array($language, ['en', 'hi'])) {
        $language = 'en';
      }
      if (!in_array($theme, ['light', 'dark', 'system'])) {
        $theme = 'system';
      }

      $_SESSION['language'] = $language;
      $_SESSION['theme'] = $theme;

      logActivity("Updated system preferences (language: $language, theme: $theme)", "success");
      echo json_encode(['status' => 'success', 'message' => 'Settings updated successfully!']);
      break;

    default:
      echo json_encode(['status' => 'error', 'message' => 'Unknown operation requested.']);
      break;
  }
} catch (Exception $e) {
  if (isset($db) && $db->inTransaction()) {
    $db->rollBack();
  }
  error_log("API actions Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
  echo json_encode(['status' => 'error', 'message' => 'An unexpected backend operation error occurred. Please try again later.']);
  exit;
}
?>
