<?php
/**
 * Interview Scheduling Email Notification Logic Tester
 */

// Suppress header/session warning output in CLI environment
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

echo "=========================================\n";
echo "Starting Interview Email Notification Test\n";
echo "=========================================\n\n";

// Clear credentials to force local log output for verification
putenv('SMTP_USER=');
putenv('SMTP_PASS=');
$_ENV['SMTP_USER'] = '';
$_ENV['SMTP_PASS'] = '';

// Clear previous mail logs to ensure clean assertions
$logFile = __DIR__ . '/../uploads/mail_logs/system_emails.log';
if (file_exists($logFile)) {
  unlink($logFile);
}

// 1. Initialize DB and find a valid application
require_once __DIR__ . '/../config/db.php';
$db = getDB();

$app = $db->query("
  SELECT a.id, u.name as stu_name, u.email as stu_email 
  FROM applications a 
  JOIN users u ON a.student_id = u.id 
  LIMIT 1
")->fetch();

if (!$app) {
  echo "FAIL: No student applications found in database to schedule a test interview.\n";
  exit(1);
}

echo "Found target student candidate: " . $app['stu_name'] . " (" . $app['stu_email'] . ") for application ID " . $app['id'] . "\n\n";

// 2. Mock Recruiter/TPO Session
$_SESSION['user_id'] = 2; // Pravin Kadu (TPO)
$_SESSION['user_role'] = 'tpo';
$_SESSION['user_name'] = 'Mr. Pravin Kadu';
$_SESSION['user_email'] = 'tpo@university.edu';

// 3. Mock POST parameters for schedule_interview action
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
  'action' => 'schedule_interview',
  'application_id' => $app['id'],
  'date' => '2026-08-15',
  'time' => '10:00:00',
  'venue' => 'Virtual Zoom Meeting Room 1',
  'interviewer' => 'Dr. Jane Smith (HR Head)',
  'interview_round' => 'HR Round',
  'interview_type' => 'Online',
  'meeting_link' => 'https://zoom.us/j/9876543210',
  'instructions' => 'Please keep your resumes and IDs ready.',
  'notes' => 'Internal TPO note'
];

// Run the actions script
ob_start();
require __DIR__ . '/../api/actions.php';
$result = ob_get_clean();

echo "Response from api/actions.php:\n";
echo $result . "\n\n";

$res_json = json_decode($result, true);
if (!$res_json || $res_json['status'] !== 'success') {
  echo "FAIL: schedule_interview API action failed.\n";
  exit(1);
}
echo "SUCCESS: Interview scheduled successfully via API.\n\n";

// 4. Assert that the email log was created and contains correct details
echo "Verifying Email Log file...\n";
if (!file_exists($logFile)) {
  echo "FAIL: Email log file was not created. No email was sent.\n";
  exit(1);
}

$logContents = file_get_contents($logFile);
echo "Email Log Contents:\n";
echo "-----------------------------------------\n";
echo $logContents;
echo "-----------------------------------------\n\n";

// Check assertions
$recipient_check = "TO: " . $app['stu_name'] . " <" . $app['stu_email'] . ">";
$round_check = "HR Round";
$date_check = "2026-08-15";
$time_check = "10:00:00";
$venue_check = "Virtual Zoom Meeting Room 1";

if (strpos($logContents, $recipient_check) === false) {
  echo "FAIL: Recipient email/name not matching or missing.\n";
  exit(1);
}

if (strpos($logContents, $round_check) === false || strpos($logContents, $date_check) === false || strpos($logContents, $time_check) === false || strpos($logContents, $venue_check) === false) {
  echo "FAIL: Email body details do not match scheduled interview parameters.\n";
  exit(1);
}

echo "=========================================\n";
echo "All Email Notification Integration Tests Passed!\n";
echo "=========================================\n";
?>
