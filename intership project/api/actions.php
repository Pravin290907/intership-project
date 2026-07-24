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
      $stmtCheck = $db->prepare("SELECT a.*, d.company_id FROM applications a JOIN drives d ON a.drive_id = d.id WHERE a.id = ?");
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
        $stmtOffer = $db->prepare("UPDATE offers SET salary_lpa = ?, designation = ?, joining_date = ?, location = ?, status = 'Released', offer_letter_path = ?, offer_date = CURDATE() WHERE id = ?");
        $stmtOffer->execute([$salaryLpa, $designation, $joiningDate, $location, $offerLetterPath, $existingOfferId]);
      } else {
        // Insert new offer
        $stmtOffer = $db->prepare("INSERT INTO offers (application_id, salary_lpa, designation, joining_date, location, status, offer_letter_path, offer_date) VALUES (?, ?, ?, ?, ?, 'Released', ?, CURDATE())");
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

      // Update offer
      $stmtUpdate = $db->prepare("UPDATE offers SET salary_lpa = ?, designation = ?, joining_date = ?, location = ?, status = ?, expiry_date = ?, offer_letter_path = ? WHERE id = ?");
      $stmtUpdate->execute([$salaryLpa, $designation, $joiningDate, $location, $status, empty($expiryDate) ? null : $expiryDate, $offerLetterPath, $offerId]);

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
