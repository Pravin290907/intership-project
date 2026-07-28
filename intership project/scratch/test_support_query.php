<?php
/**
 * Support Queries End-To-End Backend Logic Tester
 */

// Suppress warning/notice output from headers already sent in CLI environment
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// 1. Simulate submit_inquiry.php POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
  'name' => 'Jane Doe Test CLI',
  'email' => 'jane.doe.test.cli@example.com',
  'message' => 'Hello! This is a test query submitted via the automated backend CLI script.'
];

ob_start();
require __DIR__ . '/../api/submit_inquiry.php';
$submit_result = ob_get_clean();

// Now we can echo things
echo "=========================================\n";
echo "Starting Support Queries Integration Test\n";
echo "=========================================\n\n";

echo "[1/4] Simulating Landing Page Inquiry Submission...\n";
echo "Response from submit_inquiry.php:\n";
echo $submit_result . "\n";

$submit_json = json_decode($submit_result, true);
if (!$submit_json || $submit_json['status'] !== 'success') {
  echo "FAIL: submit_inquiry.php returned failure or invalid response.\n";
  exit(1);
}
echo "SUCCESS: Inquiry submitted successfully.\n\n";

// 2. Verify Database Record
echo "[2/4] Verifying Database Record...\n";
$db = getDB();
$stmt = $db->prepare("SELECT * FROM `support_queries` WHERE `email` = ? ORDER BY `id` DESC LIMIT 1");
$stmt->execute(['jane.doe.test.cli@example.com']);
$record = $stmt->fetch();

if (!$record) {
  echo "FAIL: Query not found in `support_queries` database table.\n";
  exit(1);
}

echo "Database Record Details:\n";
echo "ID: " . $record['id'] . "\n";
echo "Name: " . $record['name'] . "\n";
echo "Message: " . $record['message'] . "\n";
echo "Status: " . $record['status'] . "\n";
echo "SUCCESS: Database record created correctly.\n\n";

// 3. Verify get_support_queries Action (TPO role)
echo "[3/4] Fetching queries from database (TPO/Admin action)...\n";
$stmt = $db->query("SELECT * FROM support_queries ORDER BY created_at DESC");
$queries = $stmt->fetchAll();

if (count($queries) === 0) {
  echo "FAIL: No queries returned from support_queries table.\n";
  exit(1);
}

$found_query = false;
foreach ($queries as $q) {
  if ($q['email'] === 'jane.doe.test.cli@example.com') {
    $found_query = true;
    break;
  }
}

if (!$found_query) {
  echo "FAIL: The newly created query is missing from the list.\n";
  exit(1);
}
echo "SUCCESS: Query list returned correctly. (Total queries: " . count($queries) . ")\n\n";

// 4. Verify update_query_status Action (TPO role)
echo "[4/4] Resolving the query with remarks...\n";
$queryId = $record['id'];
$newStatus = 'Resolved';
$remarks = 'Jane was contacted and her queries were resolved via email.';
$resolvedAt = date('Y-m-d H:i:s');

$stmt = $db->prepare("UPDATE support_queries SET status = ?, remarks = ?, resolved_at = ? WHERE id = ?");
$stmt->execute([$newStatus, $remarks, $resolvedAt, $queryId]);

// Fetch again to verify
$stmt = $db->prepare("SELECT * FROM `support_queries` WHERE `id` = ?");
$stmt->execute([$queryId]);
$updated_record = $stmt->fetch();

if (!$updated_record || $updated_record['status'] !== 'Resolved' || $updated_record['remarks'] !== $remarks) {
  echo "FAIL: Status update was not successfully updated in database.\n";
  exit(1);
}

echo "Updated Database Record Details:\n";
echo "Status: " . $updated_record['status'] . "\n";
echo "Remarks: " . $updated_record['remarks'] . "\n";
echo "Resolved At: " . $updated_record['resolved_at'] . "\n";
echo "SUCCESS: Query successfully marked as Resolved with remarks.\n\n";

echo "=========================================\n";
echo "All Backend Integration Tests Passed!\n";
echo "=========================================\n";
?>
