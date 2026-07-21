<?php
/**
 * Authentication Endpoint
 * Validates credentials via AJAX, checks account status, initializes sessions, and handles cookies.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
  exit;
}

$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);

if (empty($email) || empty($password)) {
  echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
  exit;
}

try {
  $db = getDB();
  
  // Find user by email (role is dynamically auto-detected!) - forced case-sensitive via BINARY operator
  $stmt = $db->prepare("SELECT id, name, password_hash, role, status FROM users WHERE BINARY email = ? LIMIT 1");
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if (!$user) {
    logActivity("Failed login attempt for email: $email", "failure");
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
    exit;
  }

  // Verify password hash
  if (!password_verify($password, $user['password_hash'])) {
    logActivity("Incorrect password attempt for user: {$user['name']} ({$user['role']})", "failure", $user['id'], $user['role'], $user['name']);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password.']);
    exit;
  }

  // Check registration status
  if ($user['status'] === 'pending') {
    logActivity("Attempted login by unapproved user: {$user['name']}", "pending_blocked", $user['id'], $user['role'], $user['name']);
    echo json_encode(['status' => 'error', 'message' => 'Your account registration is pending approval by TPO/Admin.']);
    exit;
  }

  if ($user['status'] === 'suspended') {
    logActivity("Attempted login by suspended user: {$user['name']}", "suspended_blocked", $user['id'], $user['role'], $user['name']);
    echo json_encode(['status' => 'error', 'message' => 'Your account has been suspended by TPO/Admin. Please contact support.']);
    exit;
  }

  // Login successful
  $_SESSION['user_id'] = $user['id'];
  $_SESSION['user_name'] = $user['name'];
  $_SESSION['user_email'] = $email;
  $_SESSION['user_role'] = $user['role'];
  $_SESSION['language'] = 'en';
  $_SESSION['theme'] = 'system';
  $_SESSION['last_activity'] = time();

  // Remember Me Cookie Generation
  if ($remember) {
    $token = bin2hex(random_bytes(32));
    $expiryDays = 14;
    $expiryTime = date('Y-m-d H:i:s', time() + ($expiryDays * 24 * 60 * 60));
    
    // Save to user DB
    $stmtUpdate = $db->prepare("UPDATE users SET remember_token = ?, session_expiry = ? WHERE id = ?");
    $stmtUpdate->execute([$token, $expiryTime, $user['id']]);

    // Set cookie
    $cookieExpiry = time() + ($expiryDays * 24 * 60 * 60);
    $params = session_get_cookie_params();
    setcookie('remember_me', $token, $cookieExpiry, 
      $params["path"], $params["domain"], 
      $params["secure"], $params["httponly"]
    );
  }

  logActivity("User logged in successfully", "success", $user['id'], $user['role'], $user['name']);
  
  echo json_encode([
    'status' => 'success', 
    'message' => 'Login successful. Redirecting to dashboard...',
    'redirect' => getProjectBase() . '/dashboard.php'
  ]);
  exit;

} catch (PDOException $e) {
  error_log("Login PDOException: " . $e->getMessage() . "\n" . $e->getTraceAsString());
  echo json_encode(['status' => 'error', 'message' => 'An unexpected database error occurred. Please try again later.']);
  exit;
}
?>
