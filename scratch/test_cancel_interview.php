<?php
/**
 * Interview Cancellation Logic Tester
 */

// Suppress header/session warning output in CLI environment
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

echo "=========================================\n";
echo "Starting Interview Cancellation Test\n";
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

// 1. Initialize DB and find a scheduled interview (or create one)
require_once __DIR__ . '/../config/db.php';
$db = getDB();

$int = $db->query("
  SELECT i.id, u.name as stu_name, u.email as stu_email, i.interview_round 
  FROM interviews i 
  JOIN applications a ON i.application_id = a.id 
  JOIN users u ON a.student_id = u.id 
  WHERE i.result = 'Scheduled' 
  LIMIT 1
")->fetch();

if (!$int) {
  // Create a mock interview if none scheduled
  echo "No scheduled interviews found. Seeding a temporary interview...\n";
  
  $app = $db->query("SELECT id FROM applications LIMIT 1")->fetch();
  if (!$app) {
    echo "FAIL: No applications found to seed test interview.\n";
    exit(1);
  }
  
  $db->prepare("
    INSERT INTO interviews (application_id, date, time, venue, interviewer, result, interview_round, interview_type) 
    VALUES (?, CURDATE(), '12:00:00', 'Virtual Room', 'Test Recruiter', 'Scheduled', 'Technical Round', 'Online')
  ")->execute([$app['id']]);
  
  $interviewId = $db->lastInsertId();
  
  $int = $db->query("
    SELECT i.id, u.name as stu_name, u.email as stu_email, i.interview_round 
    FROM interviews i 
    JOIN applications a ON i.application_id = a.id 
    JOIN users u ON a.student_id = u.id 
    WHERE i.id = {$interviewId}
  ")->fetch();
}

echo "Found target interview to cancel: ID " . $int['id'] . " | Candidate: " . $int['stu_name'] . " (" . $int['stu_email'] . ")\n\n";

// 2. Mock Session
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = 'tpo';
$_SESSION['user_name'] = 'Mr. Pravin Kadu';
$_SESSION['user_email'] = 'tpo@university.edu';

// 3. Mock POST parameters for cancel_interview action
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
  'action' => 'cancel_interview',
  'interview_id' => $int['id']
];

// Run the actions script
ob_start();
require __DIR__ . '/../api/actions.php';
$result = ob_get_clean();

echo "Response from api/actions.php:\n";
echo $result . "\n\n";

$res_json = json_decode($result, true);
if (!$res_json || $res_json['status'] !== 'success') {
  echo "FAIL: cancel_interview API action failed.\n";
  exit(1);
}
echo "SUCCESS: Interview cancelled successfully via API.\n\n";

// 4. Verify Database update
echo "Verifying Database status...\n";
$stmt = $db->prepare("SELECT result FROM interviews WHERE id = ?");
$stmt->execute([$int['id']]);
$db_status = $stmt->fetchColumn();

if ($db_status !== 'Cancelled') {
  echo "FAIL: Interview status in database is '$db_status', expected 'Cancelled'.\n";
  exit(1);
}
echo "SUCCESS: Database status updated to 'Cancelled'.\n\n";

// 5. Assert that the email log was created and contains correct details
echo "Verifying Email Log file...\n";
if (!file_exists($logFile)) {
  echo "FAIL: Email log file was not created. No cancellation email was sent.\n";
  exit(1);
}

$logContents = file_get_contents($logFile);
echo "Email Log Contents:\n";
echo "-----------------------------------------\n";
echo $logContents;
echo "-----------------------------------------\n\n";

// Check assertions
$recipient_check = "TO: " . $int['stu_name'] . " <" . $int['stu_email'] . ">";
$subject_check = "Interview Cancelled - " . $int['interview_round'];

if (strpos($logContents, $recipient_check) === false) {
  echo "FAIL: Recipient email/name not matching or missing.\n";
  exit(1);
}

if (strpos($logContents, $subject_check) === false) {
  echo "FAIL: Subject line does not match cancelled interview details.\n";
  exit(1);
}

echo "=========================================\n";
echo "All Cancel Interview Integration Tests Passed!\n";
echo "=========================================\n";
?>
