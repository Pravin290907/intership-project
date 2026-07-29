<?php
/**
 * Registration Endpoint
 * Processes candidate student registrations and company recruitment requests.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
  exit;
}

$registerType = $_POST['register_type'] ?? '';

if ($registerType !== 'student' && $registerType !== 'company') {
  echo json_encode(['status' => 'error', 'message' => 'Invalid registration type.']);
  exit;
}

$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$password = $_POST['password'] ?? '';
$name_raw = trim($_POST['name'] ?? '');
$name_clean = preg_replace('/\s{2,}/', ' ', $name_raw);

if (empty($name_clean) || !$email || empty($password)) {
  echo json_encode(['status' => 'error', 'message' => 'Please provide a valid name, email, and password.']);
  exit;
}

if (!preg_match('/^[a-zA-Z\s\'\-\.]+$/', $name_clean)) {
  echo json_encode(['status' => 'error', 'message' => 'Full name can only contain letters, spaces, hyphens, apostrophes, and periods.']);
  exit;
}

$name = htmlspecialchars($name_clean, ENT_QUOTES, 'UTF-8');

if (strlen($password) < 6) {
  echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
  exit;
}

try {
  $db = getDB();

  // Check if email already exists
  $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
  $stmtCheck->execute([$email]);
  if ($stmtCheck->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Email is already registered.']);
    exit;
  }

  $passwordHash = password_hash($password, PASSWORD_BCRYPT);
  
  $db->beginTransaction();

  if ($registerType === 'student') {
    $roll = filter_input(INPUT_POST, 'roll_number', FILTER_SANITIZE_SPECIAL_CHARS);
    $dept = filter_input(INPUT_POST, 'department', FILTER_SANITIZE_SPECIAL_CHARS);
    $cgpa = filter_input(INPUT_POST, 'cgpa', FILTER_VALIDATE_FLOAT);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS);

    if (empty($roll) || empty($dept) || $cgpa === false) {
      echo json_encode(['status' => 'error', 'message' => 'Please provide roll number, department, and valid CGPA.']);
      exit;
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
      echo json_encode(['status' => 'error', 'message' => 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.']);
      exit;
    }

    // Check if roll number already exists
    $stmtRollCheck = $db->prepare("SELECT user_id FROM students WHERE roll_number = ? LIMIT 1");
    $stmtRollCheck->execute([$roll]);
    if ($stmtRollCheck->fetch()) {
      echo json_encode(['status' => 'error', 'message' => 'Roll number is already registered.']);
      exit;
    }

    // Generate secure 6-digit OTP
    $otp = (string)random_int(100000, 999999);
    $otpHash = password_hash($otp, PASSWORD_BCRYPT);

    // Insert user — status 'pending' to require manual TPO/Admin approval before login
    $stmtUser = $db->prepare("INSERT INTO users (name, email, password_hash, role, status, email_verified, otp_code, otp_expiry, otp_attempts, otp_created_at) VALUES (?, ?, ?, 'student', 'pending', 0, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW())");
    $stmtUser->execute([$name, $email, $passwordHash, $otpHash]);
    $userId = $db->lastInsertId();

    // Insert student details
    $stmtStudent = $db->prepare("INSERT INTO students (user_id, roll_number, department, cgpa, phone) VALUES (?, ?, ?, ?, ?)");
    $stmtStudent->execute([$userId, $roll, $dept, $cgpa, $phone]);

    // Create Admin notification
    createAdminNotification(
      "New Student Registration Pending",
      "$name ($roll) registered under $dept department. Review required.",
      "student_registration",
      "medium",
      "students"
    );

    logActivity("Student registration submitted: $name ($roll)", "pending_registration", $userId, "student", $name);

  } else {
    // Company Registration
    $cName = filter_input(INPUT_POST, 'company_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $industry = filter_input(INPUT_POST, 'industry', FILTER_SANITIZE_SPECIAL_CHARS);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS);
    $website = filter_input(INPUT_POST, 'website', FILTER_VALIDATE_URL);

    if (empty($cName) || empty($industry)) {
      echo json_encode(['status' => 'error', 'message' => 'Please provide company name and industry.']);
      exit;
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
      echo json_encode(['status' => 'error', 'message' => 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.']);
      exit;
    }

    // Check if company name already exists
    $stmtNameCheck = $db->prepare("SELECT user_id FROM companies WHERE company_name = ? LIMIT 1");
    $stmtNameCheck->execute([$cName]);
    if ($stmtNameCheck->fetch()) {
      echo json_encode(['status' => 'error', 'message' => 'Company name is already registered.']);
      exit;
    }

    // Generate secure 6-digit OTP
    $otp = (string)random_int(100000, 999999);
    $otpHash = password_hash($otp, PASSWORD_BCRYPT);

    // Insert user
    $stmtUser = $db->prepare("INSERT INTO users (name, email, password_hash, role, status, email_verified, otp_code, otp_expiry, otp_attempts, otp_created_at) VALUES (?, ?, ?, 'company', 'pending', 0, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW())");
    $stmtUser->execute([$name, $email, $passwordHash, $otpHash]);
    $userId = $db->lastInsertId();

    // Insert company details
    $stmtCompany = $db->prepare("INSERT INTO companies (user_id, company_name, industry, phone, website) VALUES (?, ?, ?, ?, ?)");
    $stmtCompany->execute([$userId, $cName, $industry, $phone, $website]);

    // Create Admin notification
    createAdminNotification(
      "New Recruiter Registration Pending",
      "$cName ($industry) requests portal placement permissions.",
      "company_registration",
      "high",
      "companies"
    );

    logActivity("Company registration submitted: $cName", "pending_registration", $userId, "company", $name);
  }

  $db->commit();

  // Try sending the OTP verification email
  $emailSent = sendOtpEmail($email, $name, $otp);
  $redirectUrl = BASE_URL . 'auth/verify_otp.php?email=' . urlencode($email);

  if ($emailSent) {
    echo json_encode([
      'status' => 'success',
      'message' => 'Registration successful! A 6-digit verification code has been sent to your email.',
      'redirect' => $redirectUrl
    ]);
  } else {
    echo json_encode([
      'status' => 'success',
      'message' => 'Registration successful, but the verification email could not be sent. Please request a new OTP on the verification page.',
      'redirect' => $redirectUrl
    ]);
  }
  exit;

} catch (Exception $e) {
  if (isset($db) && $db->inTransaction()) {
    $db->rollBack();
  }
  error_log("Registration Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
  echo json_encode(['status' => 'error', 'message' => 'An unexpected registration error occurred. Please try again later.']);
  exit;
}
?>
