<?php
require_once __DIR__ . '/../config/db.php';
$db = getDB();
$userId = 3;

$stmtApp = $db->prepare("
  SELECT a.id, a.student_id as studentId, u.name as studentName, u.email as studentEmail, a.status, d.job_role as role
  FROM applications a
  JOIN users u ON a.student_id = u.id
  JOIN drives d ON a.drive_id = d.id
  WHERE d.company_id = ?
");
$stmtApp->execute([$userId]);
$apps = $stmtApp->fetchAll(PDO::FETCH_ASSOC);

foreach ($apps as $a) {
    echo "AppID: {$a['id']}, StudentID: {$a['studentId']}, Name: {$a['studentName']}, Email: {$a['studentEmail']}, Role: {$a['role']}, Status: {$a['status']}\n";
}
