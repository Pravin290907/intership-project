<?php
/**
 * Logout Endpoint
 * Terminates active session tokens, clears persistent login cookies, and redirects.
 */

require_once __DIR__ . '/../config/auth.php';

if (isset($_SESSION['user_id'])) {
  try {
    $db = getDB();
    
    // Clear remember token
    $stmt = $db->prepare("UPDATE users SET remember_token = NULL, session_expiry = NULL WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    logActivity("User logged out", "success");
  } catch (Exception $e) {
    // Continue
  }
}

// Clear session variables
$_SESSION = [];

// Clear session cookies
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"], $params["domain"],
    $params["secure"], $params["httponly"]
  );
}

// Clear remember_me cookie
if (isset($_COOKIE['remember_me'])) {
  setcookie('remember_me', '', time() - 42000, '/');
}

session_destroy();

header("Location: ../index.php");
exit;
?>
