<?php
/**
 * Test Landing Page Dynamic Calculations
 */
require_once __DIR__ . '/../config/auth.php';

$db = getDB();

echo "=== DATABASE DIAGNOSTICS ===\n";

// 1. Check companies
$stmtCompCount = $db->query("SELECT COUNT(*) FROM companies");
$compCount = (int)$stmtCompCount->fetchColumn();
echo "Companies Count in DB: $compCount\n";

// 2. Check offers
$stmtOffersCount = $db->query("SELECT COUNT(*) FROM offers WHERE LOWER(status) IN ('accepted', 'released')");
$offersCount = (int)$stmtOffersCount->fetchColumn();
echo "Accepted/Released Offers Count in DB: $offersCount\n";

// 3. Check drives
$stmtDrivesCount = $db->query("SELECT COUNT(*) FROM drives");
$drivesCount = (int)$stmtDrivesCount->fetchColumn();
echo "Total Drives Count in DB: $drivesCount\n";

// 4. Check active drives
$stmtActiveDrives = $db->query("SELECT COUNT(*) FROM drives WHERE LOWER(status) IN ('open', 'upcoming')");
$activeDrivesCount = (int)$stmtActiveDrives->fetchColumn();
echo "Active/Upcoming Drives: $activeDrivesCount\n";

// 5. Test stats array values
$stats = [
  'companies' => 520,
  'placed' => 12850,
  'highest' => '48.5 LPA',
  'rate' => '98.4%',
  'avg_package' => '8.2 LPA'
];

if ($compCount > 0) {
  $stats['companies'] = max(500, $compCount + 510);
}
if ($offersCount > 0) {
  $stats['placed'] = max(12500, $offersCount + 12800);
}
$stmtMaxDrive = $db->query("SELECT MAX(package_lpa) FROM drives");
$maxDrive = (float)$stmtMaxDrive->fetchColumn();
$stmtMaxComp = $db->query("SELECT MAX(highest_package) FROM companies");
$maxComp = (float)$stmtMaxComp->fetchColumn();
$maxPackage = max($maxDrive, $maxComp, 48.5);
$stats['highest'] = number_format($maxPackage, 1) . ' LPA';

$stmtAvg = $db->query("SELECT AVG(package_lpa) FROM drives WHERE LOWER(status) IN ('open', 'upcoming')");
$avgVal = (float)$stmtAvg->fetchColumn();
if ($avgVal > 0) {
  $stats['avg_package'] = number_format($avgVal, 1) . ' LPA';
}
$stmtTotalStudents = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'approved'");
$totalStudents = (int)$stmtTotalStudents->fetchColumn();
$stmtPlacedStudents = $db->query("
  SELECT COUNT(DISTINCT a.student_id) 
  FROM offers o
  JOIN applications a ON o.application_id = a.id
  WHERE LOWER(o.status) IN ('accepted', 'released')
");
$placedStudents = (int)$stmtPlacedStudents->fetchColumn();
if ($totalStudents > 0) {
  $rateVal = ($placedStudents / $totalStudents) * 100;
  $stats['rate'] = number_format(max(95.0, $rateVal), 1) . '%';
}

echo "\n=== COMPUTED STATS ===\n";
print_r($stats);

// 6. Test Live feed
$stmtFeed = $db->query("
  SELECT d.job_role, d.package_lpa, c.company_name, c.industry
  FROM drives d
  LEFT JOIN companies c ON d.company_id = c.user_id
  WHERE LOWER(d.status) IN ('open', 'upcoming')
  ORDER BY CASE WHEN LOWER(d.status) = 'open' THEN 1 ELSE 2 END ASC, d.id DESC
  LIMIT 3
");
$feedDrives = $stmtFeed->fetchAll();
echo "\n=== HERO FEED DRIVES (Count: " . count($feedDrives) . ") ===\n";
print_r($feedDrives);

// 7. Test drives list
$stmtDrives = $db->query("
  SELECT d.*, c.company_name, c.company_logo, c.industry
  FROM drives d
  LEFT JOIN companies c ON d.company_id = c.user_id
  WHERE LOWER(d.status) IN ('open', 'upcoming')
  ORDER BY CASE WHEN LOWER(d.status) = 'open' THEN 1 ELSE 2 END ASC, d.id DESC
  LIMIT 6
");
$latestDrives = $stmtDrives->fetchAll();
echo "\n=== ACTIVE/UPCOMING DRIVES (Count: " . count($latestDrives) . ") ===\n";
foreach ($latestDrives as $ld) {
  echo "- Role: {$ld['job_role']} | Company: {$ld['company_name']} | Status: {$ld['status']} | Package: {$ld['package_lpa']} LPA\n";
}

// 8. Test dynamic testimonials
$stmtTestimonials = $db->query("
  SELECT u.name, o.salary_lpa, o.designation, c.company_name
  FROM offers o
  JOIN applications a ON o.application_id = a.id
  JOIN users u ON a.student_id = u.id
  JOIN drives d ON a.drive_id = d.id
  JOIN companies c ON d.company_id = c.user_id
  WHERE LOWER(o.status) IN ('accepted', 'released')
  ORDER BY o.id DESC
  LIMIT 3
");
$dynamicTestimonials = $stmtTestimonials->fetchAll();
echo "\n=== TESTIMONIALS (Count: " . count($dynamicTestimonials) . ") ===\n";
print_r($dynamicTestimonials);
?>
