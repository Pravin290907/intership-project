<?php
/**
 * Secure File Upload Handler
 * Validates document formats (Resumes, Certificates, Offer Letters), restricts sizes, and maps paths.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/auth.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['status' => 'error', 'message' => 'Unauthorized session.']);
  exit;
}

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'];

$uploadType = $_POST['type'] ?? ''; // 'resume', 'certificate', 'offer_letter'

if (!in_array($uploadType, ['resume', 'certificate', 'offer_letter'])) {
  echo json_encode(['status' => 'error', 'message' => 'Invalid upload category.']);
  exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
  echo json_encode(['status' => 'error', 'message' => 'No file uploaded or error during transfer.']);
  exit;
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileSize = $file['size'];
$fileTmp = $file['tmp_name'];

// Validation Criteria
$allowedExtensions = [];
$maxSize = 5 * 1024 * 1024; // 5 MB

if ($uploadType === 'resume') {
  if ($role !== 'student') {
    echo json_encode(['status' => 'error', 'message' => 'Only student candidates can upload resumes.']);
    exit;
  }
  $allowedExtensions = ['pdf', 'doc', 'docx'];
} else if ($uploadType === 'certificate') {
  if ($role !== 'student') {
    echo json_encode(['status' => 'error', 'message' => 'Only students can upload certificates.']);
    exit;
  }
  $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg'];
} else if ($uploadType === 'offer_letter') {
  if ($role !== 'company' && $role !== 'admin' && $role !== 'tpo') {
    echo json_encode(['status' => 'error', 'message' => 'Insufficient access rights to upload offer letters.']);
    exit;
  }
  $allowedExtensions = ['pdf'];
}

// Check size
if ($fileSize > $maxSize) {
  echo json_encode(['status' => 'error', 'message' => 'File size exceeds maximum 5MB limit.']);
  exit;
}

// Check extension
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($fileExt, $allowedExtensions)) {
  echo json_encode(['status' => 'error', 'message' => 'File format not allowed. Supported formats: ' . implode(', ', $allowedExtensions)]);
  exit;
}

// Check real mime type (Basic safeguard)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fileTmp);
finfo_close($finfo);

$allowedMimes = [
  'application/pdf', 
  'application/msword', 
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'image/png',
  'image/jpeg',
  'image/pjpeg'
];

if (!in_array($mimeType, $allowedMimes)) {
  echo json_encode(['status' => 'error', 'message' => 'File contents match restricted parameters. Security warning.']);
  exit;
}

// Determine destination folder
$subDir = '';
if ($uploadType === 'resume') $subDir = 'resumes';
else if ($uploadType === 'certificate') $subDir = 'certificates';
else if ($uploadType === 'offer_letter') $subDir = 'offers';

$destDir = __DIR__ . '/../uploads/' . $subDir;

// Create directory if not exists
if (!is_dir($destDir)) {
  mkdir($destDir, 0755, true);
}

// Save name using random hash
$newFileName = $uploadType . '_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
$destPath = $destDir . '/' . $newFileName;
$relativeUrlPath = '/uploads/' . $subDir . '/' . $newFileName;

try {
  $db = getDB();

  if (move_uploaded_file($fileTmp, $destPath)) {
    
    if ($uploadType === 'resume') {
      $stmt = $db->prepare("UPDATE students SET resume_path = ? WHERE user_id = ?");
      $stmt->execute([$relativeUrlPath, $userId]);
      logActivity("Uploaded resume file", "success");
      createAdminNotification("Resume Uploaded", "Student {$_SESSION['user_name']} uploaded a new resume.", "resume_update");
      
    } else if ($uploadType === 'certificate') {
      $stmt = $db->prepare("UPDATE students SET certificate_path = ? WHERE user_id = ?");
      $stmt->execute([$relativeUrlPath, $userId]);
      logActivity("Uploaded certificates", "success");
      createAdminNotification("Certificates Uploaded", "Student {$_SESSION['user_name']} uploaded academic certificates.", "certificate_update");
      
    } else if ($uploadType === 'offer_letter') {
      $appId = (int)$_POST['application_id'];
      
      // Update offer letter path
      $stmt = $db->prepare("UPDATE offers SET offer_letter_path = ? WHERE application_id = ?");
      $stmt->execute([$relativeUrlPath, $appId]);
      
      logActivity("Uploaded offer letter for App ID $appId", "success");
      createAdminNotification("Offer Letter Uploaded", "A recruitment offer letter has been published by Recruiter.", "offer_generated");
    }

    echo json_encode([
      'status' => 'success', 
      'message' => 'File uploaded successfully!', 
      'filepath' => $relativeUrlPath
    ]);
    exit;
  } else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to write file to storage block.']);
    exit;
  }

} catch (Exception $e) {
  echo json_encode(['status' => 'error', 'message' => 'File mapping database write failed: ' . $e->getMessage()]);
  exit;
}
?>
