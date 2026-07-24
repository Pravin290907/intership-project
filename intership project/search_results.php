<?php
require_once __DIR__ . '/config/auth.php';

// Enforce login
checkRole(['admin', 'tpo', 'student', 'company']);

$role = $_SESSION['user_role'];
$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];
$userEmail = $_SESSION['user_email'];

$query = trim($_GET['query'] ?? '');

$db = getDB();

$studentResults = [];
$companyResults = [];
$driveResults = [];

if ($query !== '') {
    $searchPattern = '%' . $query . '%';

    // 1. Search Students
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.email, s.roll_number, s.department, s.cgpa, s.skills
        FROM users u
        JOIN students s ON u.id = s.user_id
        WHERE u.role = 'student' AND (
            u.name LIKE ? OR 
            u.email LIKE ? OR 
            s.roll_number LIKE ? OR 
            s.department LIKE ? OR 
            s.skills LIKE ?
        )
        ORDER BY u.name ASC
    ");
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern]);
    $studentResults = $stmt->fetchAll();

    // 2. Search Companies
    $stmt = $db->prepare("
        SELECT u.id, c.company_name, u.email, c.industry, c.website, c.phone
        FROM users u
        JOIN companies c ON u.id = c.user_id
        WHERE u.role = 'company' AND (
            c.company_name LIKE ? OR 
            u.email LIKE ? OR 
            c.industry LIKE ? OR 
            c.website LIKE ?
        )
        ORDER BY c.company_name ASC
    ");
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
    $companyResults = $stmt->fetchAll();

    // 3. Search Drives
    $stmt = $db->prepare("
        SELECT d.id, d.job_role, d.skills_required, d.package_lpa, d.eligibility_cgpa, c.company_name
        FROM drives d
        JOIN companies c ON d.company_id = c.user_id
        WHERE d.job_role LIKE ? OR 
              d.skills_required LIKE ? OR 
              c.company_name LIKE ?
        ORDER BY d.id DESC
    ");
    $stmt->execute([$searchPattern, $searchPattern, $searchPattern]);
    $driveResults = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $_SESSION['theme'] ?? 'system'; ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Global Search Results - CampusRecruit</title>
  <link rel="stylesheet" href="css/design-system.css">
  <link rel="stylesheet" href="css/dashboard.css">
  <script src="https://cdn.jsdelivr.net/npm/lucide@0.294.0/dist/umd/lucide.min.js"></script>
</head>
<body>
  <div class="app-container">
    <!-- Main content area -->
    <main class="main-content" style="margin-left: 0; padding: var(--space-4); width: 100%; max-width: 1200px; margin: 0 auto;">
      <header class="header" style="justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: var(--space-2); margin-bottom: var(--space-4);">
        <div style="display:flex; align-items:center; gap: var(--space-2);">
          <a href="<?php echo $role === 'company' ? 'company/dashboard.php' : 'dashboard.php'; ?>" class="btn btn-secondary btn-icon-only" title="Back to Dashboard">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          </a>
          <h2 style="font-weight:700;">Search Results for "<?php echo htmlspecialchars($query); ?>"</h2>
        </div>
        <div class="brand" style="font-weight:800; font-size:18px; color:var(--primary); display:flex; align-items:center; gap:8px;">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
          CampusRecruit
        </div>
      </header>

      <?php if ($query === ''): ?>
        <div class="card" style="text-align:center; padding: var(--space-8);">
          <p style="color:var(--text-secondary);">Please enter a search query.</p>
        </div>
      <?php else: ?>
        <div class="grid-12" style="gap: var(--space-3);">
          
          <!-- Students Section -->
          <div class="col-12">
            <div class="card">
              <h3 class="chart-container-title" style="margin-bottom: var(--space-2); display:flex; align-items:center; gap:8px;">
                <i data-lucide="users" style="color: var(--primary);"></i> Students Matching (<?php echo count($studentResults); ?>)
              </h3>
              <?php if (count($studentResults) > 0): ?>
                <div class="data-table-wrapper">
                  <table class="data-table">
                    <thead>
                      <tr>
                        <th>Roll Number</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>CGPA</th>
                        <th>Skills</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($studentResults as $student): ?>
                        <tr>
                          <td><strong><?php echo htmlspecialchars($student['roll_number']); ?></strong></td>
                          <td><?php echo htmlspecialchars($student['name']); ?></td>
                          <td><span class="badge badge-primary"><?php echo htmlspecialchars($student['department']); ?></span></td>
                          <td><?php echo htmlspecialchars($student['cgpa']); ?></td>
                          <td><code style="font-size:12px; color:var(--text-secondary);"><?php echo htmlspecialchars($student['skills']); ?></code></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p style="color:var(--text-secondary); padding: var(--space-2);">No students matching your search criteria.</p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Companies Section -->
          <div class="col-12">
            <div class="card">
              <h3 class="chart-container-title" style="margin-bottom: var(--space-2); display:flex; align-items:center; gap:8px;">
                <i data-lucide="briefcase" style="color: var(--primary);"></i> Companies Matching (<?php echo count($companyResults); ?>)
              </h3>
              <?php if (count($companyResults) > 0): ?>
                <div class="data-table-wrapper">
                  <table class="data-table">
                    <thead>
                      <tr>
                        <th>Company Name</th>
                        <th>Industry</th>
                        <th>Email</th>
                        <th>Website</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($companyResults as $company): ?>
                        <tr>
                          <td><strong><?php echo htmlspecialchars($company['company_name']); ?></strong></td>
                          <td><span class="badge badge-success"><?php echo htmlspecialchars($company['industry']); ?></span></td>
                          <td><?php echo htmlspecialchars($company['email']); ?></td>
                          <td><a href="<?php echo htmlspecialchars($company['website']); ?>" target="_blank" style="color:var(--primary); font-weight:600; text-decoration:none;"><?php echo htmlspecialchars($company['website']); ?></a></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p style="color:var(--text-secondary); padding: var(--space-2);">No companies matching your search criteria.</p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Placement Drives Section -->
          <div class="col-12">
            <div class="card">
              <h3 class="chart-container-title" style="margin-bottom: var(--space-2); display:flex; align-items:center; gap:8px;">
                <i data-lucide="calendar" style="color: var(--primary);"></i> Placement Drives Matching (<?php echo count($driveResults); ?>)
              </h3>
              <?php if (count($driveResults) > 0): ?>
                <div class="data-table-wrapper">
                  <table class="data-table">
                    <thead>
                      <tr>
                        <th>Job Role</th>
                        <th>Company</th>
                        <th>Package (LPA)</th>
                        <th>Skills Required</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($driveResults as $drive): ?>
                        <tr>
                          <td><strong><?php echo htmlspecialchars($drive['job_role']); ?></strong></td>
                          <td><?php echo htmlspecialchars($drive['company_name']); ?></td>
                          <td><span class="badge badge-warning">₹<?php echo htmlspecialchars($drive['package_lpa']); ?> LPA</span></td>
                          <td><span style="font-size:13px; color:var(--text-secondary);"><?php echo htmlspecialchars($drive['skills_required']); ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <p style="color:var(--text-secondary); padding: var(--space-2);">No placement drives matching your search criteria.</p>
              <?php endif; ?>
            </div>
          </div>

        </div>
      <?php endif; ?>
    </main>
  </div>
  <script>
    lucide.createIcons();
  </script>
</body>
</html>
