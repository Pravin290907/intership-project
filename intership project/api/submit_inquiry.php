<?php
/**
 * Public Support Inquiry Submission Endpoint
 * Receives visitor inquiries from the landing page contact form and saves them to the database.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
  exit;
}

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

// Simple server-side validation
if ($name === '' || $email === '' || $message === '') {
  echo json_encode(['status' => 'error', 'message' => 'All fields (Name, Email, Message) are required.']);
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(['status' => 'error', 'message' => 'Please provide a valid email address.']);
  exit;
}

try {
  $db = getDB();
  $stmt = $db->prepare("INSERT INTO `support_queries` (`name`, `email`, `message`, `status`) VALUES (?, ?, ?, 'Pending')");
  $stmt->execute([$name, $email, $message]);

  // Log visitor activity
  logActivity("Visitor inquiry submitted by $name ($email)", "success");

  echo json_encode([
    'status' => 'success',
    'message' => 'Your inquiry has been received. The TPO Cell support team will respond shortly.'
  ]);
} catch (PDOException $e) {
  echo json_encode([
    'status' => 'error',
    'message' => 'An error occurred while sending your message. Please try again later.'
  ]);
}
?>
