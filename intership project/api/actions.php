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

        if ($target['role'] === 'student') {
          // Auto-assign any active general/global tests to this newly approved student
          $stmtGenTests = $db->query("SELECT id, title, duration_minutes, (SELECT name FROM users WHERE id = company_id) as company_name FROM aptitude_tests WHERE (drive_id IS NULL OR drive_id = 0) AND status IN ('Scheduled', 'Active')");
          $genTests = $stmtGenTests->fetchAll();
          foreach ($genTests as $t) {
            $db->prepare("INSERT INTO aptitude_assignments (test_id, student_id, application_id, status) VALUES (?, ?, NULL, 'Assigned') ON DUPLICATE KEY UPDATE status = VALUES(status)")
               ->execute([$t['id'], $targetUserId]);
               
            createUserNotification(
              $targetUserId,
              "Aptitude Test Scheduled",
              "You have been assigned the Aptitude Test '{$t['title']}' by {$t['company_name']}. Duration: {$t['duration_minutes']} mins.",
              "aptitude_test",
              "high",
              "aptitude"
            );
          }
        }
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

      // Fetch drive details with company name joined
      $stmtDrive = $db->prepare("
        SELECT d.*, c.company_name 
        FROM drives d 
        JOIN companies c ON d.company_id = c.user_id 
        WHERE d.id = ?
      ");
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

    // 2.3 BULK ACTION ON PLACEMENT DRIVES (CLOSE, ARCHIVE, DELETE)
    case 'bulk_drives':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $operation = trim($_POST['operation'] ?? '');
      $driveIds = $_POST['drive_ids'] ?? [];

      if (empty($driveIds) || !is_array($driveIds)) {
        echo json_encode(['status' => 'error', 'message' => 'No drives selected.']);
        exit;
      }

      // Sanitize drive IDs
      $driveIds = array_map('intval', $driveIds);

      // Verify that if company role is logged in, they only operate on their own drives
      if ($role === 'company') {
        $inQuery = implode(',', array_fill(0, count($driveIds), '?'));
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM drives WHERE id IN ($inQuery) AND company_id != ?");
        
        $params = $driveIds;
        $params[] = $userId;
        $stmtCheck->execute($params);
        $unauthorizedCount = $stmtCheck->fetchColumn();

        if ($unauthorizedCount > 0) {
          echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Some selected drives do not belong to your company.']);
          exit;
        }
      }

      // Execute bulk action
      if ($operation === 'delete') {
        $inQuery = implode(',', array_fill(0, count($driveIds), '?'));
        // Note: foreign keys are ON DELETE CASCADE, so applications, interviews, offers will cascadingly delete correctly!
        $stmt = $db->prepare("DELETE FROM drives WHERE id IN ($inQuery)");
        $stmt->execute($driveIds);

        logActivity("Bulk deleted drives: " . implode(', ', $driveIds), "success");
        echo json_encode(['status' => 'success', 'message' => 'Selected placement drives deleted successfully.']);
      } else if ($operation === 'close') {
        $inQuery = implode(',', array_fill(0, count($driveIds), '?'));
        $stmt = $db->prepare("UPDATE drives SET status = 'closed' WHERE id IN ($inQuery)");
        $stmt->execute($driveIds);

        logActivity("Bulk closed drives: " . implode(', ', $driveIds), "success");
        echo json_encode(['status' => 'success', 'message' => 'Selected placement drives closed successfully.']);
      } else if ($operation === 'archive') {
        $inQuery = implode(',', array_fill(0, count($driveIds), '?'));
        $stmt = $db->prepare("UPDATE drives SET status = 'completed' WHERE id IN ($inQuery)");
        $stmt->execute($driveIds);

        logActivity("Bulk archived (completed) drives: " . implode(', ', $driveIds), "success");
        echo json_encode(['status' => 'success', 'message' => 'Selected placement drives archived successfully.']);
      } else {
        echo json_encode(['status' => 'error', 'message' => 'Unsupported operation: ' . $operation]);
      }
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
      $students = $db->query("SELECT id, email, name FROM users WHERE role = 'student' AND status = 'approved'")->fetchAll();
      $bccList = [];
      foreach ($students as $stu) {
        createUserNotification(
          $stu['id'],
          "New Placement Drive",
          "A new drive for '$job_role' has been published by $compName.",
          "new_drive",
          "medium",
          "drives"
        );
        $bccList[] = ['email' => $stu['email'], 'name' => $stu['name']];
      }

      if (!empty($bccList)) {
        // Send email notification to all students in a single BCC mail to avoid SMTP connect overhead/timeout
        $emailSubject = "New Placement Drive: " . $job_role . " at " . $compName;
        $emailBody = "
          <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
            <h2 style='color: #2563EB;'>New Placement Drive Launched</h2>
            <p>Dear Student,</p>
            <p>A new placement drive has been published on the Campus Recruitment Management System.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
              <tr style='background-color: #f3f4f6;'>
                <td style='padding: 10px; font-weight: bold;'>Company:</td>
                <td style='padding: 10px;'>" . htmlspecialchars($compName) . "</td>
              </tr>
              <tr>
                <td style='padding: 10px; font-weight: bold;'>Job Role:</td>
                <td style='padding: 10px;'>" . htmlspecialchars($job_role) . "</td>
              </tr>
              <tr style='background-color: #f3f4f6;'>
                <td style='padding: 10px; font-weight: bold;'>Compensation:</td>
                <td style='padding: 10px;'>" . htmlspecialchars($package_lpa) . " LPA</td>
              </tr>
              <tr>
                <td style='padding: 10px; font-weight: bold;'>Eligibility CGPA:</td>
                <td style='padding: 10px;'>" . htmlspecialchars($eligibility_cgpa) . "</td>
              </tr>
              <tr style='background-color: #f3f4f6;'>
                <td style='padding: 10px; font-weight: bold;'>Registration Deadline:</td>
                <td style='padding: 10px;'>" . htmlspecialchars($registration_deadline) . "</td>
              </tr>
            </table>
            <p>Please log into your dashboard to view the details and apply before the deadline.</p>
            <p style='margin-top: 30px; font-size: 12px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 15px;'>This is an automated notification from CampusRecruit. Please do not reply directly to this email.</p>
          </div>
        ";
        sendSystemEmail('', '', $emailSubject, $emailBody, $bccList);
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
      
      $interviewRound = filter_input(INPUT_POST, 'interview_round', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Technical';
      $interviewType = filter_input(INPUT_POST, 'interview_type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Online';
      $meetingLink = filter_input(INPUT_POST, 'meeting_link', FILTER_SANITIZE_URL) ?: null;
      $instructions = filter_input(INPUT_POST, 'instructions', FILTER_SANITIZE_SPECIAL_CHARS) ?: null;
      $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_SPECIAL_CHARS) ?: null;

      // Verify application exists
      $stmtApp = $db->prepare("SELECT a.id, u.name as stu_name, a.student_id FROM applications a JOIN users u ON a.student_id=u.id WHERE a.id = ?");
      $stmtApp->execute([$appId]);
      $app = $stmtApp->fetch();

      if (!$app) {
        echo json_encode(['status' => 'error', 'message' => 'Application not found.']);
        exit;
      }

      // Save interview
      $stmtInt = $db->prepare("INSERT INTO interviews (application_id, date, time, venue, interviewer, result, attendance, meeting_link, interview_round, interview_type, instructions, notes) VALUES (?, ?, ?, ?, ?, 'Scheduled', 'Pending', ?, ?, ?, ?, ?)");
      $stmtInt->execute([$appId, $date, $time, $venue, $interviewer, $meetingLink, $interviewRound, $interviewType, $instructions, $notes]);

      // Move application status to technical or HR
      $db->prepare("UPDATE applications SET status = 'Technical' WHERE id = ? AND status = 'Applied'")->execute([$appId]);

      // Notify the student
      createUserNotification(
        $app['student_id'],
        "Interview Scheduled",
        "A {$interviewRound} ({$interviewType}) interview has been scheduled for you on $date at $time. Venue: $venue. Interviewer: $interviewer." . ($meetingLink ? " Link: $meetingLink" : ""),
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

    case 'edit_interview':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $interviewId = (int)$_POST['interview_id'];
      $date = $_POST['date'];
      $time = $_POST['time'];
      $venue = filter_input(INPUT_POST, 'venue', FILTER_SANITIZE_SPECIAL_CHARS);
      $interviewer = filter_input(INPUT_POST, 'interviewer', FILTER_SANITIZE_SPECIAL_CHARS);
      
      $interviewRound = filter_input(INPUT_POST, 'interview_round', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Technical';
      $interviewType = filter_input(INPUT_POST, 'interview_type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Online';
      $meetingLink = filter_input(INPUT_POST, 'meeting_link', FILTER_SANITIZE_URL) ?: null;
      $instructions = filter_input(INPUT_POST, 'instructions', FILTER_SANITIZE_SPECIAL_CHARS) ?: null;
      $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_SPECIAL_CHARS) ?: null;
      $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Scheduled';

      // Verify interview exists
      $stmtInt = $db->prepare("SELECT i.id, i.application_id, u.name as stu_name, a.student_id FROM interviews i JOIN applications a ON i.application_id = a.id JOIN users u ON a.student_id = u.id WHERE i.id = ?");
      $stmtInt->execute([$interviewId]);
      $interview = $stmtInt->fetch();

      if (!$interview) {
        echo json_encode(['status' => 'error', 'message' => 'Interview not found.']);
        exit;
      }

      $stmtUpdate = $db->prepare("UPDATE interviews SET date = ?, time = ?, venue = ?, interviewer = ?, result = ?, meeting_link = ?, interview_round = ?, interview_type = ?, instructions = ?, notes = ? WHERE id = ?");
      $stmtUpdate->execute([$date, $time, $venue, $interviewer, $status, $meetingLink, $interviewRound, $interviewType, $instructions, $notes, $interviewId]);

      // Move application status based on interview status if needed
      if ($status === 'Failed') {
        $db->prepare("UPDATE applications SET status = 'Rejected' WHERE id = ?")->execute([$interview['application_id']]);
      }

      // Notify the student
      createUserNotification(
        $interview['student_id'],
        "Interview Schedule Updated",
        "Your scheduled {$interviewRound} interview has been modified. Date: $date at $time. Venue: $venue. Interviewer: $interviewer. Status: $status.",
        "interview",
        "high",
        "interviews"
      );

      echo json_encode(['status' => 'success', 'message' => 'Interview updated successfully']);
      break;

    case 'complete_interview':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $interviewId = (int)$_POST['interview_id'];
      $rating = (int)$_POST['rating'];
      $result = filter_input(INPUT_POST, 'result', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'Passed';
      $feedback = filter_input(INPUT_POST, 'feedback', FILTER_SANITIZE_SPECIAL_CHARS);

      // Verify interview exists
      $stmtInt = $db->prepare("SELECT i.id, i.application_id, u.name as stu_name, a.student_id, i.interview_round FROM interviews i JOIN applications a ON i.application_id = a.id JOIN users u ON a.student_id = u.id WHERE i.id = ?");
      $stmtInt->execute([$interviewId]);
      $interview = $stmtInt->fetch();

      if (!$interview) {
        echo json_encode(['status' => 'error', 'message' => 'Interview not found.']);
        exit;
      }

      $stmtUpdate = $db->prepare("UPDATE interviews SET rating = ?, result = ?, feedback = ?, attendance = 'Present' WHERE id = ?");
      $stmtUpdate->execute([$rating, $result, $feedback, $interviewId]);

      // Move application status based on result
      if ($result === 'Failed') {
        $db->prepare("UPDATE applications SET status = 'Rejected' WHERE id = ?")->execute([$interview['application_id']]);
      } else if ($result === 'Passed') {
        // If passed Technical, set status to HR
        if ($interview['interview_round'] === 'Technical') {
          $db->prepare("UPDATE applications SET status = 'HR' WHERE id = ? AND status = 'Technical'")->execute([$interview['application_id']]);
        }
      }

      // Notify the student
      createUserNotification(
        $interview['student_id'],
        "Interview Evaluation Submitted",
        "Feedback and rating have been updated for your {$interview['interview_round']} round. Result: $result.",
        "interview",
        "medium",
        "interviews"
      );

      echo json_encode(['status' => 'success', 'message' => 'Interview evaluation submitted successfully']);
      break;

    case 'delete_interview':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $interviewId = (int)$_POST['interview_id'];

      // Verify interview exists
      $stmtInt = $db->prepare("SELECT i.id, i.application_id, a.student_id FROM interviews i JOIN applications a ON i.application_id = a.id WHERE i.id = ?");
      $stmtInt->execute([$interviewId]);
      $interview = $stmtInt->fetch();

      if (!$interview) {
        echo json_encode(['status' => 'error', 'message' => 'Interview not found.']);
        exit;
      }

      $stmtDelete = $db->prepare("DELETE FROM interviews WHERE id = ?");
      $stmtDelete->execute([$interviewId]);

      echo json_encode(['status' => 'success', 'message' => 'Interview deleted successfully']);
      break;

    // 3.75 EDIT STUDENT PROFILE
    case 'edit_student':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $studentId = (int)($_POST['student_id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $rollNumber = trim($_POST['roll_number'] ?? '');
      $department = trim($_POST['department'] ?? '');
      $cgpa = (float)($_POST['cgpa'] ?? 0.0);
      $academicYear = trim($_POST['academic_year'] ?? '');
      $phone = trim($_POST['phone'] ?? '');

      if (!$studentId || empty($name) || empty($email) || empty($rollNumber) || empty($department) || $cgpa <= 0 || empty($academicYear)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
        exit;
      }

      $db->beginTransaction();

      // Check if student exists in users table
      $stmtCheck = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'student'");
      $stmtCheck->execute([$studentId]);
      if (!$stmtCheck->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'Student record not found in users.']);
        exit;
      }

      // Check if email already taken by someone else
      $stmtEmailCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
      $stmtEmailCheck->execute([$email, $studentId]);
      if ($stmtEmailCheck->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'Email address is already in use by another account.']);
        exit;
      }

      // Check if roll number already taken by someone else
      $stmtRollCheck = $db->prepare("SELECT user_id FROM students WHERE roll_number = ? AND user_id != ?");
      $stmtRollCheck->execute([$rollNumber, $studentId]);
      if ($stmtRollCheck->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'Roll number is already in use by another student.']);
        exit;
      }

      // Update users table
      $stmtUser = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
      $stmtUser->execute([$name, $email, $studentId]);

      // Check if student record exists in students table
      $stmtStudentExist = $db->prepare("SELECT user_id FROM students WHERE user_id = ?");
      $stmtStudentExist->execute([$studentId]);
      
      if ($stmtStudentExist->fetchColumn()) {
        // Update students table
        $stmtStudent = $db->prepare("UPDATE students SET roll_number = ?, department = ?, cgpa = ?, phone = ?, academic_year = ? WHERE user_id = ?");
        $stmtStudent->execute([$rollNumber, $department, $cgpa, $phone, $academicYear, $studentId]);
      } else {
        // Insert into students table if somehow missing
        $stmtStudent = $db->prepare("INSERT INTO students (user_id, roll_number, department, cgpa, phone, academic_year) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtStudent->execute([$studentId, $rollNumber, $department, $cgpa, $phone, $academicYear]);
      }

      $db->commit();

      logActivity("Modified student profile: $name (ID: $studentId)", "success");
      echo json_encode(['status' => 'success', 'message' => 'Profile Updated Successfully']);
      break;

    // 3.8 DELETE STUDENT PROFILE
    case 'delete_student':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $studentId = (int)($_POST['student_id'] ?? 0);

      // Verify the student exists and actually has the role 'student'
      $stmtCheck = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
      $stmtCheck->execute([$studentId]);
      $studentName = $stmtCheck->fetchColumn();

      if (!$studentName) {
        echo json_encode(['status' => 'error', 'message' => 'Student record not found.']);
        exit;
      }

      $stmtDelete = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
      $stmtDelete->execute([$studentId]);

      logActivity("Deleted student profile: $studentName", "success");

      echo json_encode(['status' => 'success', 'message' => 'Student deleted successfully']);
      break;

    case 'delete_company':
      if ($role !== 'admin' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $companyId = (int)($_POST['company_id'] ?? 0);

      // Verify the company exists and actually has the role 'company'
      $stmtCheck = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'company'");
      $stmtCheck->execute([$companyId]);
      $companyName = $stmtCheck->fetchColumn();

      if (!$companyName) {
        echo json_encode(['status' => 'error', 'message' => 'Company record not found.']);
        exit;
      }

      $stmtDelete = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'company'");
      $stmtDelete->execute([$companyId]);

      logActivity("Deleted company profile: $companyName", "success");

      echo json_encode(['status' => 'success', 'message' => 'Company deleted successfully']);
      break;

    // 4. PUBLISH RESULTS / GENERATE OFFER / UPDATE CANDIDATE FUNNEL
    case 'publish_selection':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $appId = (int)$_POST['application_id'];
      $result = $_POST['result']; // 'Applied', 'Eligible', 'Aptitude', 'Technical', 'HR', 'Selected', 'Rejected'

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
        // Increment hire count if not already selected before
        if ($app['status'] !== 'Selected') {
          $db->prepare("UPDATE companies SET students_hired = students_hired + 1 WHERE user_id = ?")->execute([$app['company_id']]);
        }
        
        // Generate draft offer or update existing offer
        $stmtCheckOffer = $db->prepare("SELECT id FROM offers WHERE application_id = ?");
        $stmtCheckOffer->execute([$appId]);
        if ($stmtCheckOffer->fetchColumn()) {
          $stmtOffer = $db->prepare("UPDATE offers SET salary_lpa = ?, designation = ?, status = 'Released', offer_date = COALESCE(offer_date, CURDATE()), expiry_date = COALESCE(expiry_date, DATE_ADD(CURDATE(), INTERVAL 15 DAY)), sent_date = COALESCE(sent_date, NOW()) WHERE application_id = ?");
          $stmtOffer->execute([
            $app['package_lpa'],
            $app['job_role'],
            $appId
          ]);
        } else {
          $stmtOffer = $db->prepare("INSERT INTO offers (application_id, salary_lpa, designation, joining_date, location, status, offer_date, expiry_date, sent_date) VALUES (?, ?, ?, ?, 'Bangalore Center', 'Released', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), NOW())");
          $stmtOffer->execute([
            $appId,
            $app['package_lpa'],
            $app['job_role'],
            date('Y-m-d', strtotime('+30 days'))
          ]);
        }

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
      } else if ($result === 'Rejected') {
        // Notify student: application rejected
        createUserNotification(
          $app['student_id'],
          "Application Status Update",
          "We regret to inform you that your application for the role '{$app['job_role']}' at '{$app['company_name']}' was marked as Rejected.",
          "application_status",
          "medium",
          "applications"
        );
      } else {
        // Round progression (Eligible, Aptitude, Technical, HR)
        createUserNotification(
          $app['student_id'],
          "Application Round Progression",
          "Your application for '{$app['job_role']}' at '{$app['company_name']}' has progressed to the {$result} round.",
          "application_status",
          "medium",
          "applications"
        );
      }

      $db->commit();

      createAdminNotification(
        "Placement Selection Result Published",
        "Student {$app['student_name']} status updated to $result by {$app['company_name']}.",
        "selection",
        "high"
      );

      echo json_encode(['status' => 'success', 'message' => 'Candidate status updated successfully to ' . $result]);
      break;

    // 4.5 OFFER MANAGEMENT CRUD
    case 'create_offer':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $appId = (int)($_POST['application_id'] ?? 0);
      $designation = trim($_POST['designation'] ?? '');
      $salaryLpa = (float)($_POST['salary_lpa'] ?? 0.0);
      $joiningDate = trim($_POST['joining_date'] ?? '');
      $location = trim($_POST['location'] ?? '');

      if (!$appId || !$designation || !$salaryLpa || !$joiningDate || !$location) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
        exit;
      }

      // Check application
      $stmtCheck = $db->prepare("
        SELECT a.*, d.company_id, u.email as student_email, u.name as student_name, c.company_name
        FROM applications a 
        JOIN drives d ON a.drive_id = d.id 
        JOIN users u ON a.student_id = u.id
        JOIN companies c ON d.company_id = c.user_id
        WHERE a.id = ?
      ");
      $stmtCheck->execute([$appId]);
      $app = $stmtCheck->fetch();

      if (!$app) {
        echo json_encode(['status' => 'error', 'message' => 'Application not found.']);
        exit;
      }

      // Check file upload
      $offerLetterPath = null;
      if (isset($_FILES['offer_letter']) && $_FILES['offer_letter']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['offer_letter'];
        $fileName = $file['name'];
        $fileSize = $file['size'];
        $fileTmp = $file['tmp_name'];
        
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExt !== 'pdf') {
          echo json_encode(['status' => 'error', 'message' => 'Only PDF files are allowed for offer letters.']);
          exit;
        }
        
        $destDir = __DIR__ . '/../uploads/offers';
        if (!is_dir($destDir)) {
          mkdir($destDir, 0755, true);
        }
        
        $newFileName = 'offer_' . $_SESSION['user_id'] . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $destPath = $destDir . '/' . $newFileName;
        if (move_uploaded_file($fileTmp, $destPath)) {
          $offerLetterPath = 'uploads/offers/' . $newFileName;
        } else {
          echo json_encode(['status' => 'error', 'message' => 'Failed to save offer letter file.']);
          exit;
        }
      } else {
        echo json_encode(['status' => 'error', 'message' => 'Offer letter PDF file is required.']);
        exit;
      }

      $db->beginTransaction();

      // Check if offer already exists for this application
      $stmtOfferCheck = $db->prepare("SELECT id FROM offers WHERE application_id = ?");
      $stmtOfferCheck->execute([$appId]);
      $existingOfferId = $stmtOfferCheck->fetchColumn();

      if ($existingOfferId) {
        // Update existing offer
        $stmtOffer = $db->prepare("UPDATE offers SET salary_lpa = ?, designation = ?, joining_date = ?, location = ?, status = 'Released', offer_letter_path = ?, offer_date = COALESCE(offer_date, CURDATE()), expiry_date = COALESCE(expiry_date, DATE_ADD(CURDATE(), INTERVAL 15 DAY)), sent_date = COALESCE(sent_date, NOW()) WHERE id = ?");
        $stmtOffer->execute([$salaryLpa, $designation, $joiningDate, $location, $offerLetterPath, $existingOfferId]);
      } else {
        // Insert new offer
        $stmtOffer = $db->prepare("INSERT INTO offers (application_id, salary_lpa, designation, joining_date, location, status, offer_letter_path, offer_date, expiry_date, sent_date) VALUES (?, ?, ?, ?, ?, 'Released', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), NOW())");
        $stmtOffer->execute([$appId, $salaryLpa, $designation, $joiningDate, $location, $offerLetterPath]);
      }

      // Update application status to 'Selected'
      $stmtUpdateApp = $db->prepare("UPDATE applications SET status = 'Selected' WHERE id = ?");
      $stmtUpdateApp->execute([$appId]);

      // Increment company's students_hired count
      $stmtInc = $db->prepare("UPDATE companies SET students_hired = students_hired + 1 WHERE user_id = ?");
      $stmtInc->execute([$app['company_id']]);

      // Create student notification
      createUserNotification(
        $app['student_id'],
        "Offer Letter Released",
        "An official offer letter for the role '{$designation}' has been released by your recruiter. Please check the offers panel.",
        "offer",
        "high",
        "applications"
      );

      // Send email notification to student about the offer letter
      $emailSubject = "Congratulations! Offer Letter Released - " . $app['company_name'];
      $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
          <h2 style='color: #10B981;'>Congratulations!</h2>
          <p>Dear " . htmlspecialchars($app['student_name']) . ",</p>
          <p>We are pleased to inform you that <strong>" . htmlspecialchars($app['company_name']) . "</strong> has released an official offer letter for you!</p>
          <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
            <tr style='background-color: #f3f4f6;'>
              <td style='padding: 10px; font-weight: bold;'>Role:</td>
              <td style='padding: 10px;'>" . htmlspecialchars($designation) . "</td>
            </tr>
            <tr>
              <td style='padding: 10px; font-weight: bold;'>Compensation (LPA):</td>
              <td style='padding: 10px;'>" . htmlspecialchars($salaryLpa) . " LPA</td>
            </tr>
            <tr style='background-color: #f3f4f6;'>
              <td style='padding: 10px; font-weight: bold;'>Location:</td>
              <td style='padding: 10px;'>" . htmlspecialchars($location) . "</td>
            </tr>
            <tr>
              <td style='padding: 10px; font-weight: bold;'>Joining Date:</td>
              <td style='padding: 10px;'>" . htmlspecialchars($joiningDate) . "</td>
            </tr>
          </table>
          <p>Please log into the student portal to review the offer letter document, accept or decline the offer, and complete any required onboarding steps.</p>
          <p style='margin-top: 30px; font-size: 12px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 15px;'>This is an automated notification from CampusRecruit. Please do not reply directly to this email.</p>
        </div>
      ";
      sendSystemEmail($app['student_email'], $app['student_name'], $emailSubject, $emailBody);

      $db->commit();

      logActivity("Created offer letter for Application ID: $appId", "success");

      echo json_encode(['status' => 'success', 'message' => 'Offer released successfully']);
      break;

    case 'edit_offer':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $offerId = (int)($_POST['offer_id'] ?? 0);
      $designation = trim($_POST['designation'] ?? '');
      $salaryLpa = (float)($_POST['salary_lpa'] ?? 0.0);
      $joiningDate = trim($_POST['joining_date'] ?? '');
      $location = trim($_POST['location'] ?? '');
      $status = trim($_POST['status'] ?? 'Released');
      $expiryDate = trim($_POST['expiry_date'] ?? '');

      if (!$offerId || !$designation || !$salaryLpa || !$joiningDate || !$location) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
        exit;
      }

      // Verify the offer exists
      $stmtCheck = $db->prepare("SELECT o.*, a.student_id, a.drive_id, d.company_id FROM offers o JOIN applications a ON o.application_id = a.id JOIN drives d ON a.drive_id = d.id WHERE o.id = ?");
      $stmtCheck->execute([$offerId]);
      $offer = $stmtCheck->fetch();

      if (!$offer) {
        echo json_encode(['status' => 'error', 'message' => 'Offer record not found.']);
        exit;
      }

      // Check if new file uploaded
      $offerLetterPath = $offer['offer_letter_path'];
      if (isset($_FILES['offer_letter']) && $_FILES['offer_letter']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['offer_letter'];
        $fileName = $file['name'];
        $fileSize = $file['size'];
        $fileTmp = $file['tmp_name'];
        
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExt !== 'pdf') {
          echo json_encode(['status' => 'error', 'message' => 'Only PDF files are allowed for offer letters.']);
          exit;
        }
        
        $destDir = __DIR__ . '/../uploads/offers';
        if (!is_dir($destDir)) {
          mkdir($destDir, 0755, true);
        }
        
        $newFileName = 'offer_' . $_SESSION['user_id'] . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $destPath = $destDir . '/' . $newFileName;
        if (move_uploaded_file($fileTmp, $destPath)) {
          $offerLetterPath = 'uploads/offers/' . $newFileName;
        } else {
          echo json_encode(['status' => 'error', 'message' => 'Failed to save offer letter file.']);
          exit;
        }
      }

      $db->beginTransaction();

      $offerDate = trim($_POST['offer_date'] ?? '');

      // Update offer
      $stmtUpdate = $db->prepare("UPDATE offers SET salary_lpa = ?, designation = ?, joining_date = ?, location = ?, status = ?, offer_date = COALESCE(NULLIF(?, ''), offer_date, CURDATE()), expiry_date = COALESCE(NULLIF(?, ''), expiry_date, DATE_ADD(CURDATE(), INTERVAL 15 DAY)), sent_date = COALESCE(sent_date, NOW()), offer_letter_path = ? WHERE id = ?");
      $stmtUpdate->execute([$salaryLpa, $designation, $joiningDate, $location, $status, $offerDate, $expiryDate, $offerLetterPath, $offerId]);

      // If status changed to Rejected/Declined, update application status
      if ($status === 'Declined') {
        $stmtUpdateApp = $db->prepare("UPDATE applications SET status = 'Rejected' WHERE id = ?");
        $stmtUpdateApp->execute([$offer['application_id']]);
        
        $stmtDec = $db->prepare("UPDATE companies SET students_hired = GREATEST(0, students_hired - 1) WHERE user_id = ?");
        $stmtDec->execute([$offer['company_id']]);
      } else {
        $stmtUpdateApp = $db->prepare("UPDATE applications SET status = 'Selected' WHERE id = ?");
        $stmtUpdateApp->execute([$offer['application_id']]);
      }

      $db->commit();

      logActivity("Updated offer letter ID: $offerId", "success");

      echo json_encode(['status' => 'success', 'message' => 'Offer updated successfully!']);
      break;

    case 'delete_offer':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $offerId = (int)($_POST['offer_id'] ?? 0);

      // Verify the offer exists
      $stmtCheck = $db->prepare("SELECT o.*, a.drive_id, d.company_id FROM offers o JOIN applications a ON o.application_id = a.id JOIN drives d ON a.drive_id = d.id WHERE o.id = ?");
      $stmtCheck->execute([$offerId]);
      $offer = $stmtCheck->fetch();

      if (!$offer) {
        echo json_encode(['status' => 'error', 'message' => 'Offer record not found.']);
        exit;
      }

      $db->beginTransaction();

      // Delete the offer record
      $stmtDelete = $db->prepare("DELETE FROM offers WHERE id = ?");
      $stmtDelete->execute([$offerId]);

      // Revert application status to 'Applied'
      $stmtRevertApp = $db->prepare("UPDATE applications SET status = 'Applied' WHERE id = ?");
      $stmtRevertApp->execute([$offer['application_id']]);

      // Decrement company's students_hired count
      $stmtDec = $db->prepare("UPDATE companies SET students_hired = GREATEST(0, students_hired - 1) WHERE user_id = ?");
      $stmtDec->execute([$offer['company_id']]);

      $db->commit();

      logActivity("Deleted offer letter ID: $offerId", "success");

      echo json_encode(['status' => 'success', 'message' => 'Offer letter deleted successfully']);
      break;

    case 'get_student_offers':
      if ($role !== 'student') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $stmtOff = $db->prepare("
        SELECT o.id, o.application_id, o.salary_lpa as packageLPA, o.designation as role, o.joining_date as date, o.location, o.status,
               o.offer_letter_path, c.company_name as companyName, c.company_logo as companyLogo
        FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN drives d ON a.drive_id = d.id
        JOIN companies c ON d.company_id = c.user_id
        WHERE a.student_id = ?
        ORDER BY o.id DESC
      ");
      $stmtOff->execute([$_SESSION['user_id']]);
      $offers = $stmtOff->fetchAll();
      echo json_encode(['status' => 'success', 'offers' => $offers]);
      break;

    case 'respond_offer':
      if ($role !== 'student') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }

      $offerId = (int)($_POST['offer_id'] ?? 0);
      $status = trim($_POST['status'] ?? ''); // 'Accepted' or 'Declined'

      if (!$offerId || !in_array($status, ['Accepted', 'Declined'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid offer response.']);
        exit;
      }

      // Verify offer belongs to the logged in student
      $stmtCheck = $db->prepare("
        SELECT o.id, o.application_id, o.status as current_status, a.student_id, d.company_id, d.job_role, u.name as student_name
        FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN drives d ON a.drive_id = d.id
        JOIN users u ON a.student_id = u.id
        WHERE o.id = ? AND a.student_id = ?
      ");
      $stmtCheck->execute([$offerId, $_SESSION['user_id']]);
      $offer = $stmtCheck->fetch();

      if (!$offer) {
        echo json_encode(['status' => 'error', 'message' => 'Offer letter not found.']);
        exit;
      }

      if ($offer['current_status'] !== 'Released') {
        echo json_encode(['status' => 'error', 'message' => 'This offer has already been responded to.']);
        exit;
      }

      $db->beginTransaction();

      // Update offer status
      $stmtUpdate = $db->prepare("UPDATE offers SET status = ? WHERE id = ?");
      $stmtUpdate->execute([$status, $offerId]);

      if ($status === 'Declined') {
        // Set application status to Rejected
        $db->prepare("UPDATE applications SET status = 'Rejected' WHERE id = ?")->execute([$offer['application_id']]);
        // Decrement hired count
        $db->prepare("UPDATE companies SET students_hired = GREATEST(0, students_hired - 1) WHERE user_id = ?")->execute([$offer['company_id']]);

        // Notify company
        createUserNotification(
          $offer['company_id'],
          "Offer Letter Declined",
          "Candidate {$offer['student_name']} has declined the offer letter for the role '{$offer['job_role']}'.",
          "offer_status",
          "high",
          "offers"
        );
      } else {
        // Set application status to Selected
        $db->prepare("UPDATE applications SET status = 'Selected' WHERE id = ?")->execute([$offer['application_id']]);

        // Notify company
        createUserNotification(
          $offer['company_id'],
          "Offer Letter Accepted",
          "Candidate {$offer['student_name']} has accepted the offer letter for the role '{$offer['job_role']}'.",
          "offer_status",
          "high",
          "offers"
        );
      }

      $db->commit();

      logActivity("Student {$_SESSION['user_name']} marked offer ID $offerId as $status", "success");
      echo json_encode(['status' => 'success', 'message' => "Offer successfully $status!"]);
      break;

    case 'update_company_profile':
      if ($role !== 'company' && $role !== 'admin' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $targetUserId = $_SESSION['user_id'];

      // Fetch existing company record
      $stmtExisting = $db->prepare("SELECT * FROM companies WHERE user_id = ?");
      $stmtExisting->execute([$targetUserId]);
      $comp = $stmtExisting->fetch();

      $companyName = trim($_POST['company_name'] ?? ($comp['company_name'] ?? ''));
      $industry = trim($_POST['industry'] ?? ($comp['industry'] ?? ''));
      $recruiterName = trim($_POST['recruiter_name'] ?? ($comp['recruiter_name'] ?? ''));
      $designation = trim($_POST['designation'] ?? ($comp['designation'] ?? ''));
      $companySize = trim($_POST['company_size'] ?? ($comp['company_size'] ?? ''));
      $website = trim($_POST['website'] ?? ($comp['website'] ?? ''));
      $phone = trim($_POST['phone'] ?? ($comp['phone'] ?? ''));
      $gst = trim($_POST['gst'] ?? ($comp['gst'] ?? ''));
      $pan = trim($_POST['pan'] ?? ($comp['pan'] ?? ''));
      $officeAddress = trim($_POST['office_address'] ?? ($comp['office_address'] ?? ''));
      $description = trim($_POST['description'] ?? ($comp['description'] ?? ''));
      $vision = trim($_POST['vision'] ?? ($comp['vision'] ?? ''));
      $mission = trim($_POST['mission'] ?? ($comp['mission'] ?? ''));
      $country = trim($_POST['country'] ?? ($comp['country'] ?? 'India'));
      $state = trim($_POST['state'] ?? ($comp['state'] ?? ''));
      $city = trim($_POST['city'] ?? ($comp['city'] ?? ''));
      $pincode = trim($_POST['pincode'] ?? ($comp['pincode'] ?? ''));
      $foundedYear = isset($_POST['founded_year']) && $_POST['founded_year'] !== '' ? (int)$_POST['founded_year'] : ($comp['founded_year'] ?? null);
      $employeeCount = trim($_POST['employee_count'] ?? ($comp['employee_count'] ?? ''));

      // Construct JSON blobs if present
      $hiringPreferences = json_decode($comp['hiring_preferences'] ?? '{}', true) ?: [];
      if (isset($_POST['eligible_branches'])) $hiringPreferences['eligible_branches'] = trim($_POST['eligible_branches']);
      if (isset($_POST['min_cgpa'])) $hiringPreferences['min_cgpa'] = trim($_POST['min_cgpa']);
      if (isset($_POST['max_backlogs'])) $hiringPreferences['max_backlogs'] = trim($_POST['max_backlogs']);
      if (isset($_POST['salary_range'])) $hiringPreferences['salary_range'] = trim($_POST['salary_range']);
      if (isset($_POST['work_mode'])) $hiringPreferences['work_mode'] = trim($_POST['work_mode']);
      if (isset($_POST['job_type'])) $hiringPreferences['job_type'] = trim($_POST['job_type']);
      if (isset($_POST['bond'])) $hiringPreferences['bond'] = trim($_POST['bond']);

      $socialLinks = json_decode($comp['social_links'] ?? '{}', true) ?: [];
      if (isset($_POST['social_linkedin'])) $socialLinks['linkedin'] = trim($_POST['social_linkedin']);
      if (isset($_POST['social_twitter'])) $socialLinks['twitter'] = trim($_POST['social_twitter']);
      if (isset($_POST['social_facebook'])) $socialLinks['facebook'] = trim($_POST['social_facebook']);
      if (isset($_POST['social_instagram'])) $socialLinks['instagram'] = trim($_POST['social_instagram']);
      if (isset($_POST['social_github'])) $socialLinks['github'] = trim($_POST['social_github']);
      if (isset($_POST['social_youtube'])) $socialLinks['youtube'] = trim($_POST['social_youtube']);

      if ($comp) {
        $stmtUp = $db->prepare("
          UPDATE companies SET 
            company_name = ?, industry = ?, recruiter_name = ?, designation = ?, company_size = ?,
            website = ?, phone = ?, gst = ?, pan = ?, office_address = ?, description = ?,
            vision = ?, mission = ?, country = ?, state = ?, city = ?, pincode = ?,
            founded_year = ?, employee_count = ?, hiring_preferences = ?, social_links = ?
          WHERE user_id = ?
        ");
        $stmtUp->execute([
          $companyName, $industry, $recruiterName, $designation, $companySize,
          $website, $phone, $gst, $pan, $officeAddress, $description,
          $vision, $mission, $country, $state, $city, $pincode,
          $foundedYear, $employeeCount, json_encode($hiringPreferences), json_encode($socialLinks),
          $targetUserId
        ]);
      } else {
        $stmtIns = $db->prepare("
          INSERT INTO companies (user_id, company_name, industry, recruiter_name, designation, company_size, website, phone, gst, pan, office_address, description, vision, mission, country, state, city, pincode, founded_year, employee_count, hiring_preferences, social_links)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([
          $targetUserId, $companyName, $industry, $recruiterName, $designation, $companySize,
          $website, $phone, $gst, $pan, $officeAddress, $description,
          $vision, $mission, $country, $state, $city, $pincode,
          $foundedYear, $employeeCount, json_encode($hiringPreferences), json_encode($socialLinks)
        ]);
      }

      logActivity("Updated company profile details", "success");
      echo json_encode(['status' => 'success', 'message' => 'Company profile updated successfully!']);
      break;

    case 'save_recruiter_settings':
      if ($role !== 'company' && $role !== 'admin' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $targetUserId = $_SESSION['user_id'];
      $theme = trim($_POST['theme'] ?? 'light');
      $language = trim($_POST['language'] ?? 'en');
      $notifEnabled = isset($_POST['notifications_enabled']) ? 1 : 0;
      $timezone = trim($_POST['timezone'] ?? 'Asia/Kolkata');
      $dateFormat = trim($_POST['date_format'] ?? 'Y-m-d');
      $emailPrefs = trim($_POST['email_preferences'] ?? 'all');
      $privacySettings = trim($_POST['privacy_settings'] ?? 'private');
      $securitySettings = trim($_POST['security_settings'] ?? 'standard');

      $extraConfig = [
        'accent_color' => trim($_POST['accent_color'] ?? 'blue'),
        'compact_mode' => isset($_POST['compact_mode']) ? 1 : 0,
        'default_tab' => trim($_POST['default_tab'] ?? 'dashboard'),
        'auto_shortlist_cgpa' => trim($_POST['auto_shortlist_cgpa'] ?? '7.50'),
        'expiry_warning_days' => (int)($_POST['expiry_warning_days'] ?? 3),
        'offer_validity_days' => (int)($_POST['offer_validity_days'] ?? 15),
        'auto_send_interview_email' => isset($_POST['auto_send_interview_email']) ? 1 : 0,
        'auto_promote_aptitude_pass' => isset($_POST['auto_promote_aptitude_pass']) ? 1 : 0,
        'desktop_push_notif' => isset($_POST['desktop_push_notif']) ? 1 : 0,
        'sound_alert' => trim($_POST['sound_alert'] ?? 'chime'),
        'session_timeout_mins' => (int)($_POST['session_timeout_mins'] ?? 30),
        'api_key' => trim($_POST['api_key'] ?? ''),
        'webhook_url' => trim($_POST['webhook_url'] ?? ''),
        'calendar_sync' => isset($_POST['calendar_sync']) ? 1 : 0
      ];

      // Check if user_settings record exists
      $stmtCheck = $db->prepare("SELECT user_id FROM user_settings WHERE user_id = ?");
      $stmtCheck->execute([$targetUserId]);
      if ($stmtCheck->fetchColumn()) {
        $stmtUp = $db->prepare("
          UPDATE user_settings SET 
            theme = ?, language = ?, notifications_enabled = ?, timezone = ?,
            date_format = ?, email_preferences = ?, privacy_settings = ?,
            security_settings = ?, extra_config = ?
          WHERE user_id = ?
        ");
        $stmtUp->execute([$theme, $language, $notifEnabled, $timezone, $dateFormat, $emailPrefs, $privacySettings, $securitySettings, json_encode($extraConfig), $targetUserId]);
      } else {
        $stmtIns = $db->prepare("
          INSERT INTO user_settings (user_id, theme, language, notifications_enabled, timezone, date_format, email_preferences, privacy_settings, security_settings, extra_config)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([$targetUserId, $theme, $language, $notifEnabled, $timezone, $dateFormat, $emailPrefs, $privacySettings, $securitySettings, json_encode($extraConfig)]);
      }

      $_SESSION['recruiter_theme'] = $theme;
      logActivity("Updated recruiter workspace settings", "success");
      echo json_encode(['status' => 'success', 'message' => 'Workspace settings saved successfully!']);
      break;

    // 5. DATABASE BACKUP UTILITY (SQL Exporter)
    case 'backup_database':
      if ($role !== 'admin' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Only systems administrator or placement coordinator can backup database tables.']);
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
      if ($role !== 'admin' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Access restricted. Only administrators or placement coordinators can restore backups.']);
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
        $phone = trim($_POST['phone'] ?? '');
        $linkedin = trim($_POST['linkedin'] ?? '');
        $github = trim($_POST['github'] ?? '');
        $social_links = json_encode(['linkedin' => $linkedin, 'github' => $github]);

        if (!empty($phone)) {
          if (!preg_match('/^[0-9]{10}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.']);
            exit;
          }
        }

        $stmtStudent = $db->prepare("UPDATE students SET skills = ?, projects = ?, phone = ?, social_links = ? WHERE user_id = ?");
        $stmtStudent->execute([$skills, $projects, $phone, $social_links, $_SESSION['user_id']]);
      } else if ($role === 'company') {
        $website = trim($_POST['website'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $industry = trim($_POST['industry'] ?? '');
        $hr_name = trim($_POST['hr_name'] ?? '');
        $recruiter_name = trim($_POST['recruiter_name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $company_size = trim($_POST['company_size'] ?? '');
        $gst = trim($_POST['gst'] ?? '');
        $pan = trim($_POST['pan'] ?? '');
        $office_address = trim($_POST['office_address'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $vision = trim($_POST['vision'] ?? '');
        $mission = trim($_POST['mission'] ?? '');
        $country = trim($_POST['country'] ?? 'India');
        $state = trim($_POST['state'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');
        $founded_year = (int)($_POST['founded_year'] ?? 0);
        $employee_count = trim($_POST['employee_count'] ?? '');
        $hiring_preferences = is_array($_POST['hiring_preferences'] ?? null) ? json_encode($_POST['hiring_preferences']) : ($_POST['hiring_preferences'] ?? '');
        $social_links = is_array($_POST['social_links'] ?? null) ? json_encode($_POST['social_links']) : ($_POST['social_links'] ?? '');

        if (!empty($phone) && !preg_match('/^[0-9]{10}$/', $phone)) {
          echo json_encode(['status' => 'error', 'message' => 'Please enter a valid 10-digit mobile number.']);
          exit;
        }

        $stmtCompany = $db->prepare("
          UPDATE companies SET 
            website = ?, phone = ?, hr_name = ?, recruiter_name = ?, designation = ?, 
            company_size = ?, gst = ?, pan = ?, office_address = ?, description = ?, 
            vision = ?, mission = ?, country = ?, state = ?, city = ?, pincode = ?, 
            founded_year = ?, employee_count = ?, hiring_preferences = ?, social_links = ?
          WHERE user_id = ?
        ");
        $stmtCompany->execute([
          $website, $phone, $hr_name, $recruiter_name, $designation,
          $company_size, $gst, $pan, $office_address, $description,
          $vision, $mission, $country, $state, $city, $pincode,
          $founded_year ?: null, $employee_count, $hiring_preferences, $social_links,
          $_SESSION['user_id']
        ]);

        if (!empty($company_name)) {
          $db->prepare("UPDATE companies SET company_name = ? WHERE user_id = ?")->execute([$company_name, $_SESSION['user_id']]);
        }
        if (!empty($industry)) {
          $db->prepare("UPDATE companies SET industry = ? WHERE user_id = ?")->execute([$industry, $_SESSION['user_id']]);
        }
      } else if ($role === 'tpo') {
        $designation = trim($_POST['designation'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $office_location = trim($_POST['office_location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!empty($phone) && !preg_match('/^[0-9]{10}$/', $phone)) {
          echo json_encode(['status' => 'error', 'message' => 'Please enter a valid 10-digit mobile number.']);
          exit;
        }

        $stmtTpo = $db->prepare("
          UPDATE tpo_details SET 
            designation = ?, department = ?, office_location = ?, phone = ?
          WHERE user_id = ?
        ");
        $stmtTpo->execute([
          $designation, $department, $office_location, $phone,
          $_SESSION['user_id']
        ]);
      }

      $db->commit();
      
      logActivity("Updated profile details", "success");
      echo json_encode(['status' => 'success', 'message' => 'Profile updated successfully!', 'user_name' => $name]);
      break;

    // 7.5 UPDATE PASSWORD
    case 'update_password':
      $currentPassword = $_POST['current_password'] ?? '';
      $newPassword = $_POST['new_password'] ?? '';
      $confirmPassword = $_POST['confirm_password'] ?? '';

      if (empty($currentPassword) || empty($newPassword)) {
        echo json_encode(['status' => 'error', 'message' => 'Both current and new password are required.']);
        exit;
      }

      if ($newPassword !== $confirmPassword) {
        echo json_encode(['status' => 'error', 'message' => 'New password and confirmation do not match.']);
        exit;
      }

      if (strlen($newPassword) < 8) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long.']);
        exit;
      }

      // Check current password
      $stmtUser = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
      $stmtUser->execute([$_SESSION['user_id']]);
      $userHash = $stmtUser->fetchColumn();

      if (!password_verify($currentPassword, $userHash)) {
        echo json_encode(['status' => 'error', 'message' => 'Incorrect current password.']);
        exit;
      }

      $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
      $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $_SESSION['user_id']]);

      logActivity("Updated account security password", "success");
      echo json_encode(['status' => 'success', 'message' => 'Security password updated successfully!']);
      break;

    // 8. UPDATE SETTINGS (Language & Theme)
    case 'save_user_settings':
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

    case 'save_report':
      $reportName = trim($_POST['report_name'] ?? '');
      $dateRange = trim($_POST['date_range'] ?? '');
      $filterStatus = trim($_POST['filter_status'] ?? '');
      $format = trim($_POST['format'] ?? '');

      if (empty($reportName) || empty($dateRange) || empty($filterStatus) || empty($format)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing report details.']);
        exit;
      }

      $stmt = $db->prepare("INSERT INTO reports (user_id, report_name, date_range, filter_status, format, generated_at) VALUES (?, ?, ?, ?, ?, NOW())");
      $stmt->execute([$_SESSION['user_id'], $reportName, $dateRange, $filterStatus, $format]);

      logActivity("Generated and saved placement report: $reportName", "success");
      echo json_encode(['status' => 'success', 'message' => 'Report saved successfully.']);
      break;

    case 'get_reports':
      if ($role === 'admin' || $role === 'tpo') {
        $stmt = $db->query("SELECT r.*, u.name as generated_by FROM reports r JOIN users u ON r.user_id = u.id ORDER BY r.id DESC");
      } else {
        $stmt = $db->prepare("SELECT r.*, u.name as generated_by FROM reports r JOIN users u ON r.user_id = u.id WHERE r.user_id = ? ORDER BY r.id DESC");
        $stmt->execute([$_SESSION['user_id']]);
      }
      $reports = $stmt->fetchAll();
      echo json_encode(['status' => 'success', 'reports' => $reports]);
      break;

    // --- 9. APTITUDE TEST MODULE API ENDPOINTS ---
    case 'create_aptitude_test':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $title = trim($_POST['title'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $duration = (int)($_POST['duration_minutes'] ?? 30);
      $totalMarks = (int)($_POST['total_marks'] ?? 100);
      $passMarks = (int)($_POST['pass_marks'] ?? 40);
      $scheduledDate = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
      $startTime = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
      $endTime = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
      $driveId = !empty($_POST['drive_id']) ? (int)$_POST['drive_id'] : null;
      $status = !empty($scheduledDate) ? 'Scheduled' : 'Draft';

      if (empty($title)) {
        echo json_encode(['status' => 'error', 'message' => 'Test title is required.']);
        exit;
      }

      $stmt = $db->prepare("INSERT INTO aptitude_tests (company_id, drive_id, title, description, duration_minutes, total_marks, pass_marks, status, scheduled_date, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$_SESSION['user_id'], $driveId, $title, $description, $duration, $totalMarks, $passMarks, $status, $scheduledDate, $startTime, $endTime]);
      $testId = $db->lastInsertId();

      logActivity("Created Aptitude Test: $title (ID: $testId)", "success");
      autoAssignAptitudeTest($db, $testId);
      echo json_encode(['status' => 'success', 'message' => 'Aptitude Test created successfully!', 'test_id' => $testId]);
      break;

    case 'edit_aptitude_test':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $testId = (int)($_POST['test_id'] ?? 0);
      $title = trim($_POST['title'] ?? '');
      $description = trim($_POST['description'] ?? '');
      $duration = (int)($_POST['duration_minutes'] ?? 30);
      $totalMarks = (int)($_POST['total_marks'] ?? 100);
      $passMarks = (int)($_POST['pass_marks'] ?? 40);
      $scheduledDate = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
      $startTime = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
      $endTime = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
      $status = $_POST['status'] ?? 'Draft';

      if (!$testId || empty($title)) {
        echo json_encode(['status' => 'error', 'message' => 'Test ID and Title are required.']);
        exit;
      }

      $stmt = $db->prepare("UPDATE aptitude_tests SET title = ?, description = ?, duration_minutes = ?, total_marks = ?, pass_marks = ?, status = ?, scheduled_date = ?, start_time = ?, end_time = ? WHERE id = ?");
      $stmt->execute([$title, $description, $duration, $totalMarks, $passMarks, $status, $scheduledDate, $startTime, $endTime, $testId]);

      echo json_encode(['status' => 'success', 'message' => 'Aptitude Test details updated.']);
      autoAssignAptitudeTest($db, $testId);
      break;

    case 'delete_aptitude_test':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $testId = (int)($_POST['test_id'] ?? 0);
      $stmt = $db->prepare("DELETE FROM aptitude_tests WHERE id = ?");
      $stmt->execute([$testId]);

      echo json_encode(['status' => 'success', 'message' => 'Aptitude Test deleted.']);
      break;

    case 'get_aptitude_tests':
      if ($role === 'student') {
        $stmt = $db->prepare("
          SELECT t.*, c.company_name, a.status as candidate_status, a.score, a.rank, a.id as assignment_id
          FROM aptitude_assignments a
          JOIN aptitude_tests t ON a.test_id = t.id
          JOIN users c ON t.company_id = c.id
          WHERE a.student_id = ?
          ORDER BY t.id DESC
        ");
        $stmt->execute([$_SESSION['user_id']]);
      } else {
        $stmt = $db->prepare("
          SELECT t.*, u.name as company_name,
                 (SELECT COUNT(*) FROM aptitude_questions q WHERE q.test_id = t.id) as question_count,
                 (SELECT COUNT(*) FROM aptitude_assignments a WHERE a.test_id = t.id) as assigned_count,
                 (SELECT COUNT(*) FROM aptitude_assignments a WHERE a.test_id = t.id AND a.status = 'Evaluated') as evaluated_count
          FROM aptitude_tests t
          LEFT JOIN users u ON t.company_id = u.id
          WHERE t.company_id = ? OR ? IN ('admin', 'tpo')
          ORDER BY t.id DESC
        ");
        $stmt->execute([$_SESSION['user_id'], $role]);
      }
      $tests = $stmt->fetchAll();
      echo json_encode(['status' => 'success', 'tests' => $tests]);
      break;

    case 'get_aptitude_questions':
      $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
      $stmt = $db->prepare("SELECT * FROM aptitude_questions WHERE test_id = ? ORDER BY id ASC");
      $stmt->execute([$testId]);
      $questions = $stmt->fetchAll();
      echo json_encode(['status' => 'success', 'questions' => $questions]);
      break;

    case 'save_aptitude_question':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $testId = (int)($_POST['test_id'] ?? 0);
      $questionId = (int)($_POST['question_id'] ?? 0);
      $questionText = trim($_POST['question_text'] ?? '');
      $optionA = trim($_POST['option_a'] ?? '');
      $optionB = trim($_POST['option_b'] ?? '');
      $optionC = trim($_POST['option_c'] ?? '');
      $optionD = trim($_POST['option_d'] ?? '');
      $correctOption = strtoupper(trim($_POST['correct_option'] ?? 'A'));
      $marks = (int)($_POST['marks'] ?? 1);
      $explanation = trim($_POST['explanation'] ?? '');

      if (!$testId || empty($questionText) || empty($optionA) || empty($optionB) || empty($optionC) || empty($optionD)) {
        echo json_encode(['status' => 'error', 'message' => 'All question details and options A-D are required.']);
        exit;
      }

      if ($questionId > 0) {
        $stmt = $db->prepare("UPDATE aptitude_questions SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, marks = ?, explanation = ? WHERE id = ?");
        $stmt->execute([$questionText, $optionA, $optionB, $optionC, $optionD, $correctOption, $marks, $explanation, $questionId]);
        $msg = 'Question updated successfully.';
      } else {
        $stmt = $db->prepare("INSERT INTO aptitude_questions (test_id, question_text, option_a, option_b, option_c, option_d, correct_option, marks, explanation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$testId, $questionText, $optionA, $optionB, $optionC, $optionD, $correctOption, $marks, $explanation]);
        $msg = 'Question added to test.';
      }

      // Re-calculate total marks of test
      $stmtSum = $db->prepare("SELECT SUM(marks) FROM aptitude_questions WHERE test_id = ?");
      $stmtSum->execute([$testId]);
      $total = (int)$stmtSum->fetchColumn();
      if ($total > 0) {
        $db->prepare("UPDATE aptitude_tests SET total_marks = ? WHERE id = ?")->execute([$total, $testId]);
      }

      echo json_encode(['status' => 'success', 'message' => $msg]);
      break;

    case 'delete_aptitude_question':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $questionId = (int)($_POST['question_id'] ?? 0);
      $stmtCheck = $db->prepare("SELECT test_id FROM aptitude_questions WHERE id = ?");
      $stmtCheck->execute([$questionId]);
      $testId = $stmtCheck->fetchColumn();

      if ($testId) {
        $stmt = $db->prepare("DELETE FROM aptitude_questions WHERE id = ?");
        $stmt->execute([$questionId]);

        // Recalculate test marks
        $stmtSum = $db->prepare("SELECT SUM(marks) FROM aptitude_questions WHERE test_id = ?");
        $stmtSum->execute([$testId]);
        $total = (int)($stmtSum->fetchColumn() ?: 0);
        $db->prepare("UPDATE aptitude_tests SET total_marks = ? WHERE id = ?")->execute([$total, $testId]);
      }

      echo json_encode(['status' => 'success', 'message' => 'Question deleted.']);
      break;

    case 'assign_aptitude_test':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $testId = (int)($_POST['test_id'] ?? 0);
      $driveId = !empty($_POST['drive_id']) ? (int)$_POST['drive_id'] : 0;

      // Get test info
      $stmtTest = $db->prepare("SELECT t.*, u.name as company_name FROM aptitude_tests t JOIN users u ON t.company_id = u.id WHERE t.id = ?");
      $stmtTest->execute([$testId]);
      $test = $stmtTest->fetch();

      if (!$test) {
        echo json_encode(['status' => 'error', 'message' => 'Aptitude Test not found.']);
        exit;
      }

      $studentTargets = [];
      if ($driveId > 0) {
        // Fetch candidates who applied to this drive
        $stmtApps = $db->prepare("SELECT student_id, id as app_id FROM applications WHERE drive_id = ? AND status NOT IN ('Rejected')");
        $stmtApps->execute([$driveId]);
        $studentTargets = $stmtApps->fetchAll();
      } else {
        // Assign to all approved active students
        $stmtStus = $db->query("SELECT id as student_id, NULL as app_id FROM users WHERE role = 'student' AND status = 'approved'");
        $studentTargets = $stmtStus->fetchAll();
      }

      if (empty($studentTargets)) {
        echo json_encode(['status' => 'error', 'message' => 'No eligible candidates found to assign this test.']);
        exit;
      }

      $count = 0;
      $stmtAssign = $db->prepare("INSERT INTO aptitude_assignments (test_id, student_id, application_id, status) VALUES (?, ?, ?, 'Assigned') ON DUPLICATE KEY UPDATE status = VALUES(status)");

      foreach ($studentTargets as $target) {
        $stmtAssign->execute([$testId, $target['student_id'], $target['app_id']]);
        $count++;

        // Send notification to student
        createUserNotification(
          $target['student_id'],
          "Aptitude Test Scheduled",
          "You have been assigned the Aptitude Test '{$test['title']}' by {$test['company_name']}. Duration: {$test['duration_minutes']} mins.",
          "aptitude_test",
          "high",
          "aptitude"
        );

        // Update application status to Aptitude if applicable
        if (!empty($target['app_id'])) {
          $db->prepare("UPDATE applications SET status = 'Aptitude' WHERE id = ? AND status = 'Applied'")->execute([$target['app_id']]);
        }
      }

      // Mark test as Active/Scheduled
      $db->prepare("UPDATE aptitude_tests SET status = 'Active' WHERE id = ?")->execute([$testId]);

      logActivity("Assigned Aptitude Test '{$test['title']}' to $count candidates", "success");
      echo json_encode(['status' => 'success', 'message' => "Successfully assigned aptitude test to $count candidates."]);
      break;

    case 'get_student_tests':
      $stmt = $db->prepare("
        SELECT a.id as assignment_id, a.status as assignment_status, a.score, a.rank, a.total_questions, a.correct_answers, a.wrong_answers, a.unanswered, a.submit_time,
               t.id as test_id, t.title, t.description, t.duration_minutes, t.total_marks, t.pass_marks, t.scheduled_date, t.start_time, t.end_time,
               u.name as company_name
        FROM aptitude_assignments a
        JOIN aptitude_tests t ON a.test_id = t.id
        JOIN users u ON t.company_id = u.id
        WHERE a.student_id = ?
        ORDER BY a.id DESC
      ");
      $stmt->execute([$_SESSION['user_id']]);
      $tests = $stmt->fetchAll();
      echo json_encode(['status' => 'success', 'tests' => $tests]);
      break;

    case 'start_aptitude_test':
      $assignmentId = (int)($_POST['assignment_id'] ?? 0);
      $stmtAssign = $db->prepare("
        SELECT a.*, t.title, t.duration_minutes, t.total_marks
        FROM aptitude_assignments a
        JOIN aptitude_tests t ON a.test_id = t.id
        WHERE a.id = ? AND a.student_id = ?
      ");
      $stmtAssign->execute([$assignmentId, $_SESSION['user_id']]);
      $assign = $stmtAssign->fetch();

      if (!$assign) {
        echo json_encode(['status' => 'error', 'message' => 'Test assignment not found.']);
        exit;
      }

      if ($assign['status'] === 'Evaluated' || $assign['status'] === 'Submitted') {
        echo json_encode(['status' => 'error', 'message' => 'You have already submitted this test.']);
        exit;
      }

      // Mark in progress & record start time
      $db->prepare("UPDATE aptitude_assignments SET status = 'In Progress', start_time = NOW() WHERE id = ? AND start_time IS NULL")->execute([$assignmentId]);

      // Fetch questions (omit correct_option from candidate payload)
      $stmtQ = $db->prepare("SELECT id, question_text, option_a, option_b, option_c, option_d, marks FROM aptitude_questions WHERE test_id = ? ORDER BY id ASC");
      $stmtQ->execute([$assign['test_id']]);
      $questions = $stmtQ->fetchAll();

      echo json_encode([
        'status' => 'success',
        'assignment_id' => $assignmentId,
        'title' => $assign['title'],
        'duration_minutes' => (int)$assign['duration_minutes'],
        'total_marks' => (int)$assign['total_marks'],
        'questions' => $questions
      ]);
      break;

    case 'submit_aptitude_test':
      $assignmentId = (int)($_POST['assignment_id'] ?? 0);
      $rawAnswers = $_POST['answers'] ?? '{}';
      $userAnswers = is_string($rawAnswers) ? json_decode($rawAnswers, true) : (array)$rawAnswers;

      $stmtAssign = $db->prepare("
        SELECT a.*, t.id as test_id, t.pass_marks, t.total_marks, t.title, u.name as student_name
        FROM aptitude_assignments a
        JOIN aptitude_tests t ON a.test_id = t.id
        JOIN users u ON a.student_id = u.id
        WHERE a.id = ? AND a.student_id = ?
      ");
      $stmtAssign->execute([$assignmentId, $_SESSION['user_id']]);
      $assign = $stmtAssign->fetch();

      if (!$assign) {
        echo json_encode(['status' => 'error', 'message' => 'Assignment record not found.']);
        exit;
      }

      if ($assign['status'] === 'Evaluated') {
        echo json_encode(['status' => 'error', 'message' => 'This test score has already been evaluated.']);
        exit;
      }

      // Fetch all questions with correct option
      $stmtQ = $db->prepare("SELECT * FROM aptitude_questions WHERE test_id = ?");
      $stmtQ->execute([$assign['test_id']]);
      $questions = $stmtQ->fetchAll();

      $totalQuestions = count($questions);
      $correctCount = 0;
      $wrongCount = 0;
      $unansweredCount = 0;
      $totalScore = 0.0;

      $db->beginTransaction();

      // Delete existing responses for this assignment
      $db->prepare("DELETE FROM aptitude_responses WHERE assignment_id = ?")->execute([$assignmentId]);

      $stmtResp = $db->prepare("INSERT INTO aptitude_responses (assignment_id, question_id, selected_option, is_correct, marks_obtained) VALUES (?, ?, ?, ?, ?)");

      foreach ($questions as $q) {
        $qId = $q['id'];
        $selectedOpt = isset($userAnswers[$qId]) ? strtoupper(trim($userAnswers[$qId])) : null;

        if (empty($selectedOpt) || !in_array($selectedOpt, ['A', 'B', 'C', 'D'])) {
          $unansweredCount++;
          $stmtResp->execute([$assignmentId, $qId, null, 0, 0]);
        } else {
          $isCorrect = ($selectedOpt === strtoupper($q['correct_option'])) ? 1 : 0;
          if ($isCorrect) {
            $correctCount++;
            $marksObtained = (float)$q['marks'];
            $totalScore += $marksObtained;
          } else {
            $wrongCount++;
            $marksObtained = 0.0;
          }
          $stmtResp->execute([$assignmentId, $qId, $selectedOpt, $isCorrect, $marksObtained]);
        }
      }

      // Update assignment
      $stmtUpdateAssign = $db->prepare("
        UPDATE aptitude_assignments
        SET status = 'Evaluated', score = ?, total_questions = ?, correct_answers = ?, wrong_answers = ?, unanswered = ?, submit_time = NOW()
        WHERE id = ?
      ");
      $stmtUpdateAssign->execute([$totalScore, $totalQuestions, $correctCount, $wrongCount, $unansweredCount, $assignmentId]);

      // Calculate ranks for this test
      $stmtAllScores = $db->prepare("SELECT id, score FROM aptitude_assignments WHERE test_id = ? AND status = 'Evaluated' ORDER BY score DESC, submit_time ASC");
      $stmtAllScores->execute([$assign['test_id']]);
      $allScores = $stmtAllScores->fetchAll();
      
      $rank = 1;
      $stmtRank = $db->prepare("UPDATE aptitude_assignments SET rank = ? WHERE id = ?");
      foreach ($allScores as $row) {
        $stmtRank->execute([$rank, $row['id']]);
        if ($row['id'] == $assignmentId) {
          $currentRank = $rank;
        }
        $rank++;
      }

      // If test linked to application, update status based on pass/fail
      $isPassed = ($totalScore >= (float)$assign['pass_marks']);
      if (!empty($assign['application_id'])) {
        if ($isPassed) {
          $db->prepare("UPDATE applications SET status = 'Technical' WHERE id = ? AND status = 'Aptitude'")->execute([$assign['application_id']]);
        }
      }

      $db->commit();

      // Notify candidate
      $resultText = $isPassed ? "PASSED" : "FAILED";
      createUserNotification(
        $_SESSION['user_id'],
        "Aptitude Test Results Evaluated",
        "Your score for '{$assign['title']}' is $totalScore / {$assign['total_marks']} ($resultText). Rank: #{$currentRank}.",
        "aptitude_result",
        "high",
        "aptitude"
      );

      logActivity("Completed Aptitude Test '{$assign['title']}': Score $totalScore", "success");

      echo json_encode([
        'status' => 'success',
        'message' => 'Test submitted and evaluated successfully!',
        'score' => $totalScore,
        'total_marks' => (float)$assign['total_marks'],
        'pass_marks' => (float)$assign['pass_marks'],
        'is_passed' => $isPassed,
        'correct_answers' => $correctCount,
        'wrong_answers' => $wrongCount,
        'unanswered' => $unansweredCount,
        'rank' => $currentRank
      ]);
      break;

    case 'get_test_results_analytics':
      if ($role !== 'admin' && $role !== 'company' && $role !== 'tpo') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
        exit;
      }
      $testId = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);

      $stmtTest = $db->prepare("SELECT * FROM aptitude_tests WHERE id = ?");
      $stmtTest->execute([$testId]);
      $test = $stmtTest->fetch();

      if (!$test) {
        echo json_encode(['status' => 'error', 'message' => 'Aptitude Test not found.']);
        exit;
      }

      // Fetch leaderboard rankings
      $stmtLeaderboard = $db->prepare("
        SELECT a.id as assignment_id, a.score, a.rank, a.status, a.total_questions, a.correct_answers, a.wrong_answers, a.unanswered, a.submit_time,
               u.name as student_name, u.email as student_email, s.roll_number, s.department
        FROM aptitude_assignments a
        JOIN users u ON a.student_id = u.id
        LEFT JOIN students s ON u.id = s.user_id
        WHERE a.test_id = ?
        ORDER BY a.rank ASC, a.score DESC
      ");
      $stmtLeaderboard->execute([$testId]);
      $leaderboard = $stmtLeaderboard->fetchAll();

      // Compute analytics
      $totalAssigned = count($leaderboard);
      $evaluatedCount = 0;
      $passedCount = 0;
      $failedCount = 0;
      $sumScore = 0.0;
      $maxScore = 0.0;

      foreach ($leaderboard as $row) {
        if ($row['status'] === 'Evaluated') {
          $evaluatedCount++;
          $score = (float)$row['score'];
          $sumScore += $score;
          if ($score > $maxScore) $maxScore = $score;
          if ($score >= (float)$test['pass_marks']) {
            $passedCount++;
          } else {
            $failedCount++;
          }
        }
      }

      $avgScore = $evaluatedCount > 0 ? round($sumScore / $evaluatedCount, 2) : 0;
      $passPercentage = $evaluatedCount > 0 ? round(($passedCount / $evaluatedCount) * 100, 1) : 0;

      echo json_encode([
        'status' => 'success',
        'test' => $test,
        'leaderboard' => $leaderboard,
        'analytics' => [
          'total_assigned' => $totalAssigned,
          'evaluated_count' => $evaluatedCount,
          'passed_count' => $passedCount,
          'failed_count' => $failedCount,
          'pass_percentage' => $passPercentage,
          'avg_score' => $avgScore,
          'highest_score' => $maxScore
        ]
      ]);
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

function autoAssignAptitudeTest($db, $testId) {
  // Fetch test info
  $stmtTest = $db->prepare("SELECT t.*, u.name as company_name FROM aptitude_tests t JOIN users u ON t.company_id = u.id WHERE t.id = ?");
  $stmtTest->execute([$testId]);
  $test = $stmtTest->fetch();

  if (!$test) {
    return;
  }

  // Only assign if status is Scheduled or Active
  if ($test['status'] === 'Draft') {
    return;
  }

  $driveId = (int)($test['drive_id'] ?? 0);
  $studentTargets = [];
  if ($driveId > 0) {
    // Fetch candidates who applied to this drive
    $stmtApps = $db->prepare("SELECT student_id, id as app_id FROM applications WHERE drive_id = ? AND status NOT IN ('Rejected')");
    $stmtApps->execute([$driveId]);
    $studentTargets = $stmtApps->fetchAll();
  } else {
    // Assign to all approved active students
    $stmtStus = $db->query("SELECT id as student_id, NULL as app_id FROM users WHERE role = 'student' AND status = 'approved'");
    $studentTargets = $stmtStus->fetchAll();
  }

  if (empty($studentTargets)) {
    return;
  }

  // Check who is already assigned
  $stmtExisting = $db->prepare("SELECT student_id FROM aptitude_assignments WHERE test_id = ?");
  $stmtExisting->execute([$testId]);
  $existingStudents = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);

  $stmtAssign = $db->prepare("INSERT INTO aptitude_assignments (test_id, student_id, application_id, status) VALUES (?, ?, ?, 'Assigned') ON DUPLICATE KEY UPDATE status = VALUES(status)");

  foreach ($studentTargets as $target) {
    $studentId = $target['student_id'];
    $appId = $target['app_id'];

    $stmtAssign->execute([$testId, $studentId, $appId]);

    if (!in_array($studentId, $existingStudents)) {
      // Send notification only if they were not already assigned
      createUserNotification(
        $studentId,
        "Aptitude Test Scheduled",
        "You have been assigned the Aptitude Test '{$test['title']}' by {$test['company_name']}. Duration: {$test['duration_minutes']} mins.",
        "aptitude_test",
        "high",
        "aptitude"
      );

      // Update application status to Aptitude if applicable
      if (!empty($appId)) {
        $db->prepare("UPDATE applications SET status = 'Aptitude' WHERE id = ? AND status = 'Applied'")->execute([$appId]);
      }
    }
  }
}
?>
