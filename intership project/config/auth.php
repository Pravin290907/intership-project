<?php
/**
 * Authentication & Security Middleware
 * Manages secure sessions, idle timeouts, CSRF tokens, and activity logs.
 */

// Secure session settings
if (session_status() === PHP_SESSION_NONE) {
  ini_set('session.cookie_httponly', 1);
  ini_set('session.use_only_cookies', 1);
  ini_set('session.cookie_samesite', 'Lax');
  
  // Set secure cookie if running on HTTPS
  if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
  }
  
  session_start();
}

require_once __DIR__ . '/db.php';

function getProjectBase() {
  // Dynamically determine project base path relative to Document Root
  $projectRoot = realpath(__DIR__ . '/..');
  $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
  
  if ($projectRoot && $docRoot) {
    $projectRoot = str_replace('\\', '/', $projectRoot);
    $docRoot = str_replace('\\', '/', $docRoot);
    if (strpos($projectRoot, $docRoot) === 0) {
      $base = substr($projectRoot, strlen($docRoot));
      return rtrim($base, '/');
    }
  }

  // Fallback: search using the last occurrence of the project folder name
  $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
  $search = '/intership project';
  $pos = strrpos($scriptName, $search);
  if ($pos !== false) {
    return substr($scriptName, 0, $pos + strlen($search));
  }
  return '';
}

if (!defined('BASE_URL')) {
  define('BASE_URL', getProjectBase() . '/');
}

// 1. Session Idle Timeout Check (30 Minutes)
$timeout_duration = 1800; // 30 minutes in seconds
if (isset($_SESSION['user_id'])) {
  if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    // Terminate session due to inactivity
    logActivity("Session expired due to inactivity", "timeout", $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name']);
    
    // Clear session variables
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
      );
    }
    session_destroy();
    
    // Redirect to home or login
    header("Location: " . getProjectBase() . "/index.php?error=timeout");
    exit;
  }
  $_SESSION['last_activity'] = time();
}

// 2. Remember Me Cookie Check
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_me'])) {
  $token = $_COOKIE['remember_me'];
  $db = getDB();
  $stmt = $db->prepare("SELECT id, name, email, role, status FROM users WHERE remember_token = ? AND session_expiry > NOW() AND status = 'approved' LIMIT 1");
  $stmt->execute([$token]);
  $user = $stmt->fetch();
  
  if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['language'] = 'en';
    $_SESSION['theme'] = 'system';
    $_SESSION['last_activity'] = time();
    logActivity("Automatic login via remember-me cookie", "success", $user['id'], $user['role'], $user['name']);
  }
}

// 3. Page Access Protection Guard
function checkRole($allowedRoles) {
  if (!isset($_SESSION['user_id'])) {
    // Determine redirect login page based on directory path
    $currentPath = $_SERVER['PHP_SELF'];
    $base = getProjectBase();
    if (strpos($currentPath, '/admin/') !== false) $redirect = $base . '/admin/login.php';
    else if (strpos($currentPath, '/tpo/') !== false) $redirect = $base . '/tpo/login.php';
    else if (strpos($currentPath, '/student/') !== false) $redirect = $base . '/student/login.php';
    else if (strpos($currentPath, '/company/') !== false) $redirect = $base . '/company/login.php';
    else $redirect = $base . '/index.php';
    
    header("Location: " . $redirect);
    exit;
  }

  $userRole = $_SESSION['user_role'] ?? '';
  
  // Self-healing session role: reload from database if current session role is not allowed
  $roleMatch = false;
  if (is_array($allowedRoles)) {
    $roleMatch = in_array($userRole, $allowedRoles);
  } else {
    $roleMatch = ($userRole === $allowedRoles);
  }

  if (!$roleMatch) {
    try {
      $db = getDB();
      $stmt = $db->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
      $stmt->execute([$_SESSION['user_id']]);
      $realRole = $stmt->fetchColumn();
      if ($realRole) {
        $_SESSION['user_role'] = $realRole;
        $userRole = $realRole;
        if (is_array($allowedRoles)) {
          $roleMatch = in_array($userRole, $allowedRoles);
        } else {
          $roleMatch = ($userRole === $allowedRoles);
        }
      }
    } catch (Exception $e) {
      // Ignore
    }
  }

  if (!$roleMatch) {
    redirectAccessDenied();
  }
}

function redirectAccessDenied() {
  header("HTTP/1.1 403 Forbidden");
  // Simple clean message, or redirect to home dashboard
  echo "<div style='font-family: sans-serif; text-align: center; margin-top: 100px;'>
          <h2 style='color: #EF4444;'>Access Denied</h2>
          <p>You do not have administrative privilege to access this resource.</p>
          <a href='" . getProjectBase() . "/dashboard.php' style='color: #2563EB; text-decoration: none;'>Return to Dashboard</a>
        </div>";
  exit;
}

// 4. CSRF Tokens Creation & Validation
function getCsrfToken() {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
  if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
    header("HTTP/1.1 403 Forbidden");
    echo json_encode(['status' => 'error', 'message' => 'CSRF verification failed']);
    exit;
  }
  return true;
}

// 5. Activity Logging Engine
function logActivity($action, $status, $userId = null, $role = null, $username = null) {
  try {
    $db = getDB();
    
    // Autodetect from session if arguments not passed
    $uid = $userId ?? ($_SESSION['user_id'] ?? null);
    $r = $role ?? ($_SESSION['user_role'] ?? 'guest');
    $uname = $username ?? ($_SESSION['user_name'] ?? 'Guest');
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $browser = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $stmt = $db->prepare("INSERT INTO `activity_logs` (`user_id`, `username`, `role`, `action`, `ip_address`, `browser`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$uid, $uname, $r, $action, $ip, $browser, $status]);
  } catch (Exception $e) {
    // Silently continue to prevent database logging issues from crashing pages
  }
}

// 6. Real-time Notifications Emitter
function createAdminNotification($title, $description, $category, $priority = 'medium', $url = null) {
  try {
    $db = getDB();
    // Get all admin and TPO user ids to broadcast
    $admins = $db->query("SELECT id FROM users WHERE role IN ('admin', 'tpo')")->fetchAll();
    
    $stmt = $db->prepare("INSERT INTO `notifications` (`user_id`, `title`, `description`, `category`, `priority`, `url`) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($admins as $admin) {
      $stmt->execute([$admin['id'], $title, $description, $category, $priority, $url]);
    }
  } catch (Exception $e) {
    // Continue
  }
}

function createUserNotification($userId, $title, $description, $category, $priority = 'medium', $url = null) {
  try {
    $db = getDB();
    // Prevent duplicate notifications in the last 1 minute
    $stmtCheck = $db->prepare("SELECT id FROM notifications WHERE user_id = ? AND title = ? AND description = ? AND is_read = 0 AND created_at > NOW() - INTERVAL 1 MINUTE LIMIT 1");
    $stmtCheck->execute([$userId, $title, $description]);
    if ($stmtCheck->fetch()) {
      return; // Skip duplicate
    }
    
    $stmt = $db->prepare("INSERT INTO `notifications` (`user_id`, `title`, `description`, `category`, `priority`, `url`) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $title, $description, $category, $priority, $url]);
  } catch (Exception $e) {
    // Continue
  }
}

/**
 * Reusable helper to generate initials from a user's full name.
 * Handles prefixes, multiple spaces, and single-word names correctly.
 */
function getInitials($name) {
    $name = trim($name);
    if (empty($name)) {
        return 'U';
    }

    // Clean up multiple spaces
    $name = preg_replace('/\s+/', ' ', $name);

    // Strip common titles/prefixes case-insensitively if followed by space
    $name = preg_replace('/^(mr|ms|mrs|dr|prof|prof\.)\s+/i', '', $name);
    $name = trim($name);

    if (empty($name)) {
        return 'U';
    }

    $words = explode(' ', $name);
    $count = count($words);

    if ($count === 1) {
        return strtoupper(mb_substr($words[0], 0, 1));
    }

    // First letter of first word and first letter of last word
    $firstInitial = mb_substr($words[0], 0, 1);
    $lastInitial = mb_substr($words[$count - 1], 0, 1);

    return strtoupper($firstInitial . $lastInitial);
}
?>
