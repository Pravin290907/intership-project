<?php
require_once __DIR__ . '/config/auth.php';
// Get theme settings if logged in, else default
$theme = 'light';
if (isset($_SESSION['user_id'])) {
    $db = getDB();
    $stmt = $db->prepare("SELECT theme FROM user_settings WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $theme = $stmt->fetchColumn() ?: 'light';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo htmlspecialchars($theme); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Terms & Conditions - Campus Recruitment</title>
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/design-system.css">
  <style>
    body {
      background-color: var(--bg-body, #F8FAFC);
      color: var(--text-primary, #0F172A);
      font-family: 'Outfit', 'Inter', system-ui, -apple-system, sans-serif;
      margin: 0;
      padding: 0;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .terms-container {
      max-width: 800px;
      margin: 60px auto;
      padding: 40px;
      background: var(--bg-card, #FFFFFF);
      border: 1px solid var(--border-color, #E2E8F0);
      border-radius: 16px;
      box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
    }
    .terms-header {
      border-bottom: 1px solid var(--border-color, #E2E8F0);
      padding-bottom: 24px;
      margin-bottom: 32px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .terms-title {
      font-size: 28px;
      font-weight: 800;
      margin: 0;
      background: linear-gradient(135deg, #2563EB, #3B82F6);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      background: var(--bg-body, #F1F5F9);
      border: 1px solid var(--border-color, #E2E8F0);
      border-radius: 8px;
      color: var(--text-secondary, #475569);
      text-decoration: none;
      font-weight: 600;
      font-size: 13px;
      transition: all 0.2s ease;
    }
    .btn-back:hover {
      background: var(--border-color, #E2E8F0);
      color: var(--text-primary, #0F172A);
    }
    .terms-content h3 {
      font-size: 18px;
      font-weight: 700;
      margin-top: 28px;
      margin-bottom: 12px;
      color: var(--primary, #2563EB);
    }
    .terms-content p {
      font-size: 15px;
      line-height: 1.6;
      color: var(--text-secondary, #475569);
      margin-bottom: 20px;
    }
    .terms-content ul {
      color: var(--text-secondary, #475569);
      font-size: 15px;
      line-height: 1.6;
      padding-left: 20px;
      margin-bottom: 20px;
    }
    .terms-footer {
      text-align: center;
      margin-top: 40px;
      padding-top: 24px;
      border-top: 1px solid var(--border-color, #E2E8F0);
      font-size: 13px;
      color: var(--text-muted, #94A3B8);
    }
  </style>
</head>
<body>
  <div class="terms-container">
    <div class="terms-header">
      <h1 class="terms-title">Terms &amp; Conditions</h1>
      <a href="javascript:history.back()" class="btn-back">
        &larr; Go Back
      </a>
    </div>
    <div class="terms-content">
      <p>Welcome to Campus Recruitment. By accessing or using our platform, you agree to comply with and be bound by the following terms and conditions of use. Please read these terms carefully before using the system.</p>
      
      <h3>1. Agreement to Terms</h3>
      <p>By registering an account as a student, recruiter, or training &amp; placement officer (TPO), you acknowledge that you have read, understood, and agree to be bound by these terms.</p>
      
      <h3>2. User Responsibilities &amp; Code of Conduct</h3>
      <ul>
        <li><strong>Students:</strong> You agree to provide accurate academic records, resume information, and credentials. Misrepresentation of CGPA, skills, or backlogs will result in immediate disqualification and suspension.</li>
        <li><strong>Recruiters:</strong> You agree to conduct fair evaluation processes, provide clear job descriptions, specify correct CTC packaging details, and honor issued digital selection offer letters.</li>
        <li><strong>TPO Coordinators:</strong> You agree to moderate student credentials fairly and manage company drives according to institutional policies.</li>
      </ul>
      
      <h3>3. Placement Policy</h3>
      <p>The platform enforces a single-offer policy depending on institutional rules. Once an offer is accepted by a candidate, they may be automatically barred from applying to other drives to ensure fair allocation of employment opportunities.</p>
      
      <h3>4. Intellectual Property &amp; Data Privacy</h3>
      <p>All source code, platform graphics, assessment test structures, and database engines are the intellectual property of Campus Recruitment. User details are processed securely and shared strictly for recruitment purposes between verified recruiters and students.</p>
      
      <h3>5. Limitation of Liability</h3>
      <p>Campus Recruitment acts as a portal gateway to facilitate hiring drives. We do not guarantee employment for candidates or candidate onboarding for recruiters. We are not liable for scheduling conflicts, communication discrepancies, or interview results.</p>
      
      <h3>6. Platform Modifications</h3>
      <p>We reserve the right to patch, update, modify, or terminate sections of the application, including the MCQ aptitude testing modules and Kanban application pipeline trackers, at any time to preserve system integrity.</p>
    </div>
    <div class="terms-footer">
      Last Updated: July 2026 | Campus Recruitment Security &amp; Compliance Team
    </div>
  </div>
</body>
</html>
