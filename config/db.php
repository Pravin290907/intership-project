<?php
/**
 * Database Configuration & Auto-Initialization
 * Sets up PDO connection and initializes SQL tables and default seed data if not present.
 */

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/../.env')) {
  $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0) {
      continue;
    }
    $parts = explode('=', $line, 2);
    if (count($parts) === 2) {
      $key = trim($parts[0]);
      $val = trim($parts[1]);
      $val = trim($val, '"\'');
      if (getenv($key) === false) {
        putenv("$key=$val");
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
      }
    }
  }
}

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'campus_recruitment');
define('DB_PORT', getenv('DB_PORT') ?: '3307');

function getDB() {
  static $pdo = null;
  if ($pdo !== null) {
    return $pdo;
  }

  try {
    // Connect to MySQL server first to check database existence
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $temp_pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Create database if not exists
    $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $temp_pdo = null;

    // Connect to specific database
    $dsnWithDB = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsnWithDB, DB_USER, DB_PASS, $options);

    // Initialize tables if they don't exist
    initializeTables($pdo);

    return $pdo;
  } catch (PDOException $e) {
    // Print error details in enterprise JSON format if it's an AJAX call, otherwise normal text
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
      header('Content-Type: application/json');
      echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
      exit;
    }
    die("Database Connection Error: " . $e->getMessage());
  }
}

function initializeTables($pdo) {
  // Check if users table exists, if so assume initialized
  try {
    $pdo->query("SELECT 1 FROM `users` LIMIT 1");
    migrateUsersTable($pdo);
    migrateInterviewsTable($pdo);
    migrateCompaniesTable($pdo);
    migrateReportsTable($pdo);
    migrateAptitudeTables($pdo);
    migrateOffersTable($pdo);
    migrateUserSettingsTable($pdo);
    migrateTpoDetailsTable($pdo);
    migrateSupportQueriesTable($pdo);
    return; // Tables already exist — do not overwrite any data
  } catch (PDOException $e) {
    // Table does not exist yet — continue with creation below
  }

  // Create tables
  $sql = "
    -- Users Table
    CREATE TABLE IF NOT EXISTS `users` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(100) NOT NULL,
      `email` VARCHAR(100) NOT NULL UNIQUE,
      `password_hash` VARCHAR(255) NOT NULL,
      `role` ENUM('admin', 'tpo', 'student', 'company') NOT NULL,
      `status` ENUM('pending', 'approved', 'suspended') DEFAULT 'pending',
      `remember_token` VARCHAR(100) DEFAULT NULL,
      `session_expiry` DATETIME DEFAULT NULL,
      `reset_token` VARCHAR(255) DEFAULT NULL,
      `reset_token_expiry` DATETIME DEFAULT NULL,
      `email_verified` TINYINT(1) NOT NULL DEFAULT 1,
      `otp_code` VARCHAR(255) DEFAULT NULL,
      `otp_expiry` DATETIME DEFAULT NULL,
      `otp_attempts` INT NOT NULL DEFAULT 0,
      `otp_created_at` DATETIME DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Students Details Table
    CREATE TABLE IF NOT EXISTS `students` (
      `user_id` INT PRIMARY KEY,
      `roll_number` VARCHAR(30) NOT NULL UNIQUE,
      `department` VARCHAR(100) NOT NULL,
      `cgpa` DECIMAL(4,2) NOT NULL,
      `phone` VARCHAR(15) DEFAULT NULL,
      `skills` TEXT DEFAULT NULL,
      `projects` TEXT DEFAULT NULL,
      `resume_path` VARCHAR(255) DEFAULT NULL,
      `certificate_path` VARCHAR(255) DEFAULT NULL,
      `achievements` TEXT DEFAULT NULL,
      `social_links` TEXT DEFAULT NULL,
      `profile_pic` VARCHAR(255) DEFAULT NULL,
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Companies Details Table
    CREATE TABLE IF NOT EXISTS `companies` (
      `user_id` INT PRIMARY KEY,
      `company_name` VARCHAR(100) NOT NULL UNIQUE,
      `industry` VARCHAR(100) NOT NULL,
      `avg_package` DECIMAL(5,2) DEFAULT NULL,
      `highest_package` DECIMAL(5,2) DEFAULT NULL,
      `open_positions` INT DEFAULT 0,
      `students_hired` INT DEFAULT 0,
      `company_logo` VARCHAR(255) DEFAULT NULL,
      `phone` VARCHAR(15) DEFAULT NULL,
      `website` VARCHAR(100) DEFAULT NULL,
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- TPO Details Table
    CREATE TABLE IF NOT EXISTS `tpo_details` (
      `user_id` INT PRIMARY KEY,
      `designation` VARCHAR(100) DEFAULT 'Training & Placement Officer',
      `department` VARCHAR(100) DEFAULT 'Training & Placement Cell',
      `phone` VARCHAR(20) DEFAULT NULL,
      `office_location` VARCHAR(100) DEFAULT NULL,
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Placement Drives Table
    CREATE TABLE IF NOT EXISTS `drives` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `company_id` INT NOT NULL,
      `job_role` VARCHAR(100) NOT NULL,
      `eligibility_cgpa` DECIMAL(4,2) NOT NULL,
      `package_lpa` DECIMAL(5,2) NOT NULL,
      `drive_date` DATE NOT NULL,
      `status` ENUM('upcoming', 'open', 'closed', 'completed', 'cancelled') DEFAULT 'upcoming',
      `skills_required` TEXT DEFAULT NULL,
      `registration_deadline` DATE NOT NULL,
      `departments` VARCHAR(255) NOT NULL,
      FOREIGN KEY (`company_id`) REFERENCES `companies` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Applications Table
    CREATE TABLE IF NOT EXISTS `applications` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `student_id` INT NOT NULL,
      `drive_id` INT NOT NULL,
      `applied_date` DATE NOT NULL,
      `status` ENUM('Applied', 'Eligible', 'Aptitude', 'Technical', 'HR', 'Selected', 'Rejected') DEFAULT 'Applied',
      FOREIGN KEY (`student_id`) REFERENCES `students` (`user_id`) ON DELETE CASCADE,
      FOREIGN KEY (`drive_id`) REFERENCES `drives` (`id`) ON DELETE CASCADE,
      UNIQUE KEY `unique_student_drive` (`student_id`, `drive_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Interviews Table
    CREATE TABLE IF NOT EXISTS `interviews` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `application_id` INT NOT NULL,
      `date` DATE NOT NULL,
      `time` TIME NOT NULL,
      `venue` VARCHAR(100) NOT NULL,
      `interviewer` VARCHAR(100) NOT NULL,
      `remarks` TEXT DEFAULT NULL,
      `result` ENUM('Scheduled', 'Completed', 'Cancelled', 'Passed', 'Failed') DEFAULT 'Scheduled',
      `attendance` ENUM('Present', 'Absent', 'Pending') DEFAULT 'Pending',
      `meeting_link` VARCHAR(255) DEFAULT NULL,
      `rating` INT DEFAULT NULL,
      `feedback` TEXT DEFAULT NULL,
      `interview_round` VARCHAR(50) DEFAULT NULL,
      `interview_type` VARCHAR(50) DEFAULT NULL,
      `instructions` TEXT DEFAULT NULL,
      `notes` TEXT DEFAULT NULL,
      FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Offers Table
    CREATE TABLE IF NOT EXISTS `offers` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `application_id` INT NOT NULL UNIQUE,
      `salary_lpa` DECIMAL(5,2) NOT NULL,
      `designation` VARCHAR(100) NOT NULL,
      `joining_date` DATE NOT NULL,
      `location` VARCHAR(100) NOT NULL,
      `status` ENUM('Released', 'Accepted', 'Declined') DEFAULT 'Released',
      `offer_letter_path` VARCHAR(255) DEFAULT NULL,
      FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Notifications Table
    CREATE TABLE IF NOT EXISTS `notifications` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `title` VARCHAR(150) NOT NULL,
      `description` TEXT NOT NULL,
      `is_read` TINYINT DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `category` VARCHAR(50) NOT NULL,
      `priority` ENUM('low', 'medium', 'high') DEFAULT 'medium',
      `url` VARCHAR(255) DEFAULT NULL,
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Activity Log Table
    CREATE TABLE IF NOT EXISTS `activity_logs` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT DEFAULT NULL,
      `username` VARCHAR(100) DEFAULT NULL,
      `role` VARCHAR(50) DEFAULT NULL,
      `action` VARCHAR(255) NOT NULL,
      `ip_address` VARCHAR(45) NOT NULL,
      `browser` VARCHAR(255) DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Reports Table
    CREATE TABLE IF NOT EXISTS `reports` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `report_name` VARCHAR(150) NOT NULL,
      `date_range` VARCHAR(100) NOT NULL,
      `filter_status` VARCHAR(50) NOT NULL,
      `format` VARCHAR(10) NOT NULL,
      `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Aptitude Tests Table
    CREATE TABLE IF NOT EXISTS `aptitude_tests` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `company_id` INT NOT NULL,
      `drive_id` INT DEFAULT NULL,
      `title` VARCHAR(255) NOT NULL,
      `description` TEXT DEFAULT NULL,
      `duration_minutes` INT NOT NULL DEFAULT 30,
      `total_marks` INT NOT NULL DEFAULT 100,
      `pass_marks` INT NOT NULL DEFAULT 40,
      `status` ENUM('Draft', 'Scheduled', 'Active', 'Completed', 'Archived') DEFAULT 'Draft',
      `scheduled_date` DATE DEFAULT NULL,
      `start_time` TIME DEFAULT NULL,
      `end_time` TIME DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`company_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      FOREIGN KEY (`drive_id`) REFERENCES `drives` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Aptitude Questions Table
    CREATE TABLE IF NOT EXISTS `aptitude_questions` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `test_id` INT NOT NULL,
      `question_text` TEXT NOT NULL,
      `option_a` TEXT NOT NULL,
      `option_b` TEXT NOT NULL,
      `option_c` TEXT NOT NULL,
      `option_d` TEXT NOT NULL,
      `correct_option` ENUM('A', 'B', 'C', 'D') NOT NULL,
      `marks` INT NOT NULL DEFAULT 1,
      `explanation` TEXT DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`test_id`) REFERENCES `aptitude_tests` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Aptitude Test Assignments Table
    CREATE TABLE IF NOT EXISTS `aptitude_assignments` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `test_id` INT NOT NULL,
      `student_id` INT NOT NULL,
      `application_id` INT DEFAULT NULL,
      `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `status` ENUM('Assigned', 'In Progress', 'Submitted', 'Evaluated', 'Expired') DEFAULT 'Assigned',
      `score` DECIMAL(5,2) DEFAULT NULL,
      `total_questions` INT DEFAULT 0,
      `correct_answers` INT DEFAULT 0,
      `wrong_answers` INT DEFAULT 0,
      `unanswered` INT DEFAULT 0,
      `start_time` DATETIME DEFAULT NULL,
      `submit_time` DATETIME DEFAULT NULL,
      `rank` INT DEFAULT NULL,
      FOREIGN KEY (`test_id`) REFERENCES `aptitude_tests` (`id`) ON DELETE CASCADE,
      FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
      FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL,
      UNIQUE KEY `unique_student_test` (`student_id`, `test_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    -- Aptitude Responses Table
    CREATE TABLE IF NOT EXISTS `aptitude_responses` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `assignment_id` INT NOT NULL,
      `question_id` INT NOT NULL,
      `selected_option` ENUM('A', 'B', 'C', 'D') DEFAULT NULL,
      `is_correct` TINYINT(1) DEFAULT 0,
      `marks_obtained` DECIMAL(5,2) DEFAULT 0,
      FOREIGN KEY (`assignment_id`) REFERENCES `aptitude_assignments` (`id`) ON DELETE CASCADE,
      FOREIGN KEY (`question_id`) REFERENCES `aptitude_questions` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";

  $pdo->exec($sql);
  migrateSupportQueriesTable($pdo);

  // Seed default admin and TPO accounts
  $adminPass = password_hash('admin123', PASSWORD_BCRYPT);
  $tpoPass = password_hash('tpo123', PASSWORD_BCRYPT);

  $pdo->exec("
    INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES
    ('Dr. Amit Dev', 'admin@university.edu', '{$adminPass}', 'admin', 'approved'),
    ('Mr. Pravin Kadu', 'tpo@university.edu', '{$tpoPass}', 'tpo', 'approved')
  ");

  // Seed default recruiters
  $compPass = password_hash('company123', PASSWORD_BCRYPT);
  $companiesSeed = [
    ['Google Inc.', 'google@recruiting.com', 'Technology', 32.5, 48.0, 12, 'google.com'],
    ['Microsoft Corp.', 'microsoft@recruiting.com', 'Technology', 28.0, 44.0, 15, 'microsoft.com'],
    ['Amazon.com', 'amazon@recruiting.com', 'E-Commerce', 24.0, 40.0, 18, 'amazon.com'],
    ['Stripe Inc.', 'stripe@recruiting.com', 'Fintech', 26.0, 42.0, 8, 'stripe.com'],
    ['Notion Labs', 'notion@recruiting.com', 'Software', 18.0, 28.0, 5, 'notion.so']
  ];

  foreach ($companiesSeed as $c) {
    // Create user record
    $stmtUser = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES (?, ?, ?, 'company', 'approved')");
    $stmtUser->execute([$c[0], $c[1], $compPass]);
    $userId = $pdo->lastInsertId();

    // Create company details record
    $stmtComp = $pdo->prepare("INSERT INTO `companies` (`user_id`, `company_name`, `industry`, `avg_package`, `highest_package`, `open_positions`, `website`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtComp->execute([$userId, $c[0], $c[2], $c[3], $c[4], $c[5], $c[6]]);
  }

  // Seed default students
  $stuPass = password_hash('student123', PASSWORD_BCRYPT);
  $studentsSeed = [
    ['Aarav Sharma', 'aarav.sharma@university.edu', '2023-CS-1082', 'CSE', 9.2, 'Java, React, SQL', 'University Portal'],
    ['Ananya Gupta', 'ananya.gupta@university.edu', '2023-CS-3490', 'CSE', 8.9, 'Python, Django, AWS', 'AI Chatbot'],
    ['Harsh Patel', 'harsh.patel@university.edu', '2023-EC-5612', 'ECE', 7.8, 'Embedded C, IoT, Verilog', 'Smart City Node'],
    ['Divya Nair', 'divya.nair@university.edu', '2023-IT-2234', 'IT', 8.5, 'JavaScript, Node.js, MongoDB', 'E-Commerce App'],
    ['Aditya Kumar', 'aditya.kumar@university.edu', '2023-ME-9011', 'ME', 6.8, 'AutoCAD, SolidWorks', 'Robotic Arm Design'],
    ['Siddharth Joshi', 'siddharth.joshi@university.edu', '2023-EE-3345', 'EE', 7.2, 'MATLAB, LabVIEW, Power Systems', 'Solar Grid Sync']
  ];

  foreach ($studentsSeed as $s) {
    // Create user record
    $stmtUser = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES (?, ?, ?, 'student', 'approved')");
    $stmtUser->execute([$s[0], $s[1], $stuPass]);
    $userId = $pdo->lastInsertId();

    // Create student details record
    $stmtStu = $pdo->prepare("INSERT INTO `students` (`user_id`, `roll_number`, `department`, `cgpa`, `skills`, `projects`) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtStu->execute([$userId, $s[2], $s[3], $s[4], $s[5], $s[6]]);
  }

  // Seed pending registrations for approval queue testing
  $pdo->exec("
    INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES
    ('Karan Mehta', 'karan.mehta@university.edu', '{$stuPass}', 'student', 'pending'),
    ('Preeti Sen', 'preeti.sen@university.edu', '{$stuPass}', 'student', 'pending')
  ");
  $karanId = $pdo->lastInsertId() - 1;
  $preetiId = $pdo->lastInsertId();

  $pdo->exec("
    INSERT INTO `students` (`user_id`, `roll_number`, `department`, `cgpa`) VALUES
    ({$karanId}, '2023-CS-9801', 'CSE', 8.1),
    ({$preetiId}, '2023-IT-4512', 'IT', 7.9)
  ");

  $pdo->exec("
    INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `status`) VALUES
    ('Razorpay Labs', 'razorpay@recruiting.com', '{$compPass}', 'company', 'pending')
  ");
  $razorId = $pdo->lastInsertId();
  $pdo->exec("
    INSERT INTO `companies` (`user_id`, `company_name`, `industry`, `avg_package`, `highest_package`, `open_positions`, `website`) VALUES
    ({$razorId}, 'Razorpay Labs', 'Fintech', 16.5, 26.0, 5, 'razorpay.com')
  ");

  // Seed drives
  // First get company user ids
  $companiesQuery = $pdo->query("SELECT user_id, company_name FROM companies")->fetchAll();
  $compMap = [];
  foreach ($companiesQuery as $cq) {
    $compMap[$cq['company_name']] = $cq['user_id'];
  }

  $driveDate1 = date('Y-m-d', strtotime('+10 days'));
  $driveDate2 = date('Y-m-d', strtotime('+20 days'));
  $driveDate3 = date('Y-m-d', strtotime('-5 days'));

  // Google Ongoing/Upcoming Drive
  if (isset($compMap['Google Inc.'])) {
    $pdo->prepare("
      INSERT INTO `drives` (`company_id`, `job_role`, `eligibility_cgpa`, `package_lpa`, `drive_date`, `status`, `skills_required`, `registration_deadline`, `departments`) VALUES
      (?, 'Software Engineering Intern', 8.00, 32.00, ?, 'open', 'Java, Data Structures, Algorithms', ?, 'CSE, IT, ECE')
    ")->execute([$compMap['Google Inc.'], $driveDate1, date('Y-m-d', strtotime('+8 days'))]);
  }

  // Microsoft Ongoing/Upcoming Drive
  if (isset($compMap['Microsoft Corp.'])) {
    $pdo->prepare("
      INSERT INTO `drives` (`company_id`, `job_role`, `eligibility_cgpa`, `package_lpa`, `drive_date`, `status`, `skills_required`, `registration_deadline`, `departments`) VALUES
      (?, 'Associate Software Engineer', 7.50, 28.00, ?, 'upcoming', 'C#, OOPs, System Design', ?, 'CSE, IT')
    ")->execute([$compMap['Microsoft Corp.'], $driveDate2, date('Y-m-d', strtotime('+18 days'))]);
  }

  // Notion Completed Drive
  if (isset($compMap['Notion Labs'])) {
    $pdo->prepare("
      INSERT INTO `drives` (`company_id`, `job_role`, `eligibility_cgpa`, `package_lpa`, `drive_date`, `status`, `skills_required`, `registration_deadline`, `departments`) VALUES
      (?, 'Product Designer', 7.00, 18.00, ?, 'completed', 'UI/UX, Figma, Prototyping', ?, 'CSE, IT, ECE')
    ")->execute([$compMap['Notion Labs'], $driveDate3, date('Y-m-d', strtotime('-6 days'))]);
  }

  // Seed applications
  $studentsQuery = $pdo->query("SELECT user_id, name FROM users WHERE role='student' AND status='approved'")->fetchAll();
  $drivesQuery = $pdo->query("SELECT id, company_id FROM drives")->fetchAll();

  if (count($studentsQuery) > 0 && count($drivesQuery) > 0) {
    $appCount = 0;
    foreach ($studentsQuery as $sq) {
      foreach ($drivesQuery as $dq) {
        // Create realistic distribution: apply to some drives
        if (($sq['user_id'] + $dq['id']) % 2 === 0) {
          $status = 'Applied';
          if ($dq['id'] % 3 === 0) $status = 'Selected';
          else if ($dq['id'] % 2 === 0) $status = 'Rejected';
          
          $stmtApp = $pdo->prepare("INSERT INTO `applications` (`student_id`, `drive_id`, `applied_date`, `status`) VALUES (?, ?, ?, ?)");
          $stmtApp->execute([$sq['user_id'], $dq['id'], date('Y-m-d', strtotime('-3 days')), $status]);
          $appId = $pdo->lastInsertId();

          // Increment hired counts
          if ($status === 'Selected') {
            $pdo->exec("UPDATE `companies` SET `students_hired` = `students_hired` + 1 WHERE `user_id` = {$dq['company_id']}");
            
            // Create offer
            $stmtOffer = $pdo->prepare("INSERT INTO `offers` (`application_id`, `salary_lpa`, `designation`, `joining_date`, `location`, `status`) VALUES (?, 18.00, 'Product Designer', ?, 'Bangalore, India', 'Released')");
            $stmtOffer->execute([$appId, date('Y-m-d', strtotime('+60 days'))]);
          }

          // Create mock interviews
          if ($status === 'Selected' || $dq['id'] % 3 === 1) {
            $stmtInt = $pdo->prepare("INSERT INTO `interviews` (`application_id`, `date`, `time`, `venue`, `interviewer`, `result`, `attendance`) VALUES (?, ?, '11:00:00', 'Virtual - Google Meet', 'Mr. David Vance (HR)', ?, 'Present')");
            $stmtInt->execute([$appId, date('Y-m-d', strtotime('-1 days')), $status === 'Selected' ? 'Passed' : 'Scheduled']);
          }
        }
      }
    }
  }

  // Seed default activities and notifications
  $pdo->exec("
    INSERT INTO `activity_logs` (`user_id`, `username`, `role`, `action`, `ip_address`, `browser`, `status`) VALUES
    (1, 'Dr. Amit Dev', 'admin', 'System initialization and seeding successfully completed', '127.0.0.1', 'Mozilla/Firefox', 'success'),
    (2, 'Mr. Pravin Kadu', 'tpo', 'TPO session loaded placement drive metrics', '127.0.0.1', 'Mozilla/Firefox', 'success')
  ");

  $pdo->exec("
    INSERT INTO `notifications` (`user_id`, `title`, `description`, `category`, `priority`) VALUES
    (1, 'New Student Registration', 'Karan Mehta registered for Computer Science department verification.', 'student_registration', 'medium'),
    (1, 'New Recruiter Pending Verification', 'Razorpay Labs requesting portal permissions.', 'company_registration', 'high')
  ");
}

function migrateUsersTable($pdo) {
  $cols = [
    'reset_token' => "VARCHAR(255) DEFAULT NULL",
    'reset_token_expiry' => "DATETIME DEFAULT NULL",
    'email_verified' => "TINYINT(1) NOT NULL DEFAULT 1",
    'otp_code' => "VARCHAR(255) DEFAULT NULL",
    'otp_expiry' => "DATETIME DEFAULT NULL",
    'otp_attempts' => "INT NOT NULL DEFAULT 0",
    'otp_created_at' => "DATETIME DEFAULT NULL"
  ];
  
  foreach ($cols as $colName => $colType) {
    try {
      $pdo->query("SELECT `$colName` FROM `users` LIMIT 1");
    } catch (PDOException $e) {
      $pdo->exec("ALTER TABLE `users` ADD COLUMN `$colName` $colType");
    }
  }
}

function migrateInterviewsTable($pdo) {
  try {
    $pdo->query("SELECT 1 FROM `interviews` LIMIT 1");
  } catch (PDOException $e) {
    // Table does not exist, it will be initialized normally
    return;
  }

  $cols = [
    'meeting_link' => "VARCHAR(255) DEFAULT NULL",
    'rating' => "INT DEFAULT NULL",
    'feedback' => "TEXT DEFAULT NULL",
    'interview_round' => "VARCHAR(50) DEFAULT NULL",
    'interview_type' => "VARCHAR(50) DEFAULT NULL",
    'instructions' => "TEXT DEFAULT NULL",
    'notes' => "TEXT DEFAULT NULL"
  ];
  
  foreach ($cols as $colName => $colType) {
    try {
      $pdo->query("SELECT `$colName` FROM `interviews` LIMIT 1");
    } catch (PDOException $e) {
      // Column doesn't exist, add it
      $pdo->exec("ALTER TABLE `interviews` ADD COLUMN `$colName` $colType");
    }
  }
}

function migrateCompaniesTable($pdo) {
  try {
    $pdo->query("SELECT 1 FROM `companies` LIMIT 1");
  } catch (PDOException $e) {
    return;
  }

  $cols = [
    'recruiter_name' => "VARCHAR(100) DEFAULT NULL",
    'designation' => "VARCHAR(100) DEFAULT NULL",
    'company_size' => "VARCHAR(100) DEFAULT NULL",
    'hr_name' => "VARCHAR(100) DEFAULT NULL",
    'gst' => "VARCHAR(50) DEFAULT NULL",
    'pan' => "VARCHAR(50) DEFAULT NULL",
    'office_address' => "TEXT DEFAULT NULL",
    'description' => "TEXT DEFAULT NULL",
    'banner_image' => "VARCHAR(255) DEFAULT NULL",
    'vision' => "TEXT DEFAULT NULL",
    'mission' => "TEXT DEFAULT NULL",
    'country' => "VARCHAR(100) DEFAULT 'India'",
    'state' => "VARCHAR(100) DEFAULT NULL",
    'city' => "VARCHAR(100) DEFAULT NULL",
    'pincode' => "VARCHAR(20) DEFAULT NULL",
    'founded_year' => "INT DEFAULT NULL",
    'employee_count' => "VARCHAR(50) DEFAULT NULL",
    'hiring_preferences' => "TEXT DEFAULT NULL",
    'social_links' => "TEXT DEFAULT NULL",
    'company_docs' => "TEXT DEFAULT NULL"
  ];
  
  foreach ($cols as $colName => $colType) {
    try {
      $pdo->query("SELECT `$colName` FROM `companies` LIMIT 1");
    } catch (PDOException $e) {
      $pdo->exec("ALTER TABLE `companies` ADD COLUMN `$colName` $colType");
    }
  }
}

function migrateReportsTable($pdo) {
  try {
    $pdo->query("SELECT 1 FROM `reports` LIMIT 1");
  } catch (PDOException $e) {
    $sql = "
      CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `report_name` VARCHAR(150) NOT NULL,
        `date_range` VARCHAR(100) NOT NULL,
        `filter_status` VARCHAR(50) NOT NULL,
        `format` VARCHAR(10) NOT NULL,
        `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
  }
}

function migrateAptitudeTables($pdo) {
  try {
    $pdo->query("SELECT 1 FROM `aptitude_tests` LIMIT 1");
  } catch (PDOException $e) {
    $sql = "
      CREATE TABLE IF NOT EXISTS `aptitude_tests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_id` INT NOT NULL,
        `drive_id` INT DEFAULT NULL,
        `title` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `duration_minutes` INT NOT NULL DEFAULT 30,
        `total_marks` INT NOT NULL DEFAULT 100,
        `pass_marks` INT NOT NULL DEFAULT 40,
        `status` ENUM('Draft', 'Scheduled', 'Active', 'Completed', 'Archived') DEFAULT 'Draft',
        `scheduled_date` DATE DEFAULT NULL,
        `start_time` TIME DEFAULT NULL,
        `end_time` TIME DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`company_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        FOREIGN KEY (`drive_id`) REFERENCES `drives` (`id`) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS `aptitude_questions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `test_id` INT NOT NULL,
        `question_text` TEXT NOT NULL,
        `option_a` TEXT NOT NULL,
        `option_b` TEXT NOT NULL,
        `option_c` TEXT NOT NULL,
        `option_d` TEXT NOT NULL,
        `correct_option` ENUM('A', 'B', 'C', 'D') NOT NULL,
        `marks` INT NOT NULL DEFAULT 1,
        `explanation` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`test_id`) REFERENCES `aptitude_tests` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS `aptitude_assignments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `test_id` INT NOT NULL,
        `student_id` INT NOT NULL,
        `application_id` INT DEFAULT NULL,
        `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `status` ENUM('Assigned', 'In Progress', 'Submitted', 'Evaluated', 'Expired') DEFAULT 'Assigned',
        `score` DECIMAL(5,2) DEFAULT NULL,
        `total_questions` INT DEFAULT 0,
        `correct_answers` INT DEFAULT 0,
        `wrong_answers` INT DEFAULT 0,
        `unanswered` INT DEFAULT 0,
        `start_time` DATETIME DEFAULT NULL,
        `submit_time` DATETIME DEFAULT NULL,
        `rank` INT DEFAULT NULL,
        FOREIGN KEY (`test_id`) REFERENCES `aptitude_tests` (`id`) ON DELETE CASCADE,
        FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL,
        UNIQUE KEY `unique_student_test` (`student_id`, `test_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

      CREATE TABLE IF NOT EXISTS `aptitude_responses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `assignment_id` INT NOT NULL,
        `question_id` INT NOT NULL,
        `selected_option` ENUM('A', 'B', 'C', 'D') DEFAULT NULL,
        `is_correct` TINYINT(1) DEFAULT 0,
        `marks_obtained` DECIMAL(5,2) DEFAULT 0,
        FOREIGN KEY (`assignment_id`) REFERENCES `aptitude_assignments` (`id`) ON DELETE CASCADE,
        FOREIGN KEY (`question_id`) REFERENCES `aptitude_questions` (`id`) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($sql);
  }
}

function migrateOffersTable($pdo) {
  try {
    $pdo->query("SELECT 1 FROM `offers` LIMIT 1");
  } catch (PDOException $e) {
    return;
  }

  $cols = [
    'offer_date' => "DATE DEFAULT NULL",
    'expiry_date' => "DATE DEFAULT NULL",
    'sent_date' => "DATETIME DEFAULT NULL",
    'viewed_date' => "DATETIME DEFAULT NULL",
    'accepted_date' => "DATETIME DEFAULT NULL",
    'rejected_date' => "DATETIME DEFAULT NULL"
  ];
  
  foreach ($cols as $colName => $colType) {
    try {
      $pdo->query("SELECT `$colName` FROM `offers` LIMIT 1");
    } catch (PDOException $e) {
      $pdo->exec("ALTER TABLE `offers` ADD COLUMN `$colName` $colType");
    }
  }

  try {
    $pdo->exec("
      UPDATE `offers` 
      SET `offer_date` = COALESCE(`offer_date`, CURDATE()),
          `expiry_date` = COALESCE(`expiry_date`, DATE_ADD(CURDATE(), INTERVAL 15 DAY)),
          `sent_date` = COALESCE(`sent_date`, NOW())
      WHERE `offer_date` IS NULL OR `expiry_date` IS NULL OR `sent_date` IS NULL
    ");
  } catch (Exception $e) {}
}

function migrateUserSettingsTable($pdo) {
  try {
    $pdo->query("SELECT 1 FROM `user_settings` LIMIT 1");
  } catch (PDOException $e) {
    return;
  }

  $cols = [
    'extra_config' => "TEXT DEFAULT NULL"
  ];
  
  foreach ($cols as $colName => $colType) {
    try {
      $pdo->query("SELECT `$colName` FROM `user_settings` LIMIT 1");
    } catch (PDOException $e) {
      $pdo->exec("ALTER TABLE `user_settings` ADD COLUMN `$colName` $colType");
    }
  }
}

function migrateTpoDetailsTable($pdo) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `tpo_details` (
      `user_id` INT PRIMARY KEY,
      `designation` VARCHAR(100) DEFAULT 'Training & Placement Officer',
      `department` VARCHAR(100) DEFAULT 'Training & Placement Cell',
      `phone` VARCHAR(20) DEFAULT NULL,
      `office_location` VARCHAR(100) DEFAULT NULL,
      FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    INSERT INTO `tpo_details` (`user_id`, `designation`, `department`, `phone`, `office_location`)
    VALUES (2, 'Training & Placement Officer', 'Training & Placement Cell', '+919876543210', 'Placement Cell, Administrative Block')
    ON DUPLICATE KEY UPDATE user_id=user_id
  ");
}

function migrateSupportQueriesTable($pdo) {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `support_queries` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(100) NOT NULL,
      `email` VARCHAR(100) NOT NULL,
      `message` TEXT NOT NULL,
      `status` ENUM('Pending', 'Resolved', 'Ignored') DEFAULT 'Pending',
      `remarks` TEXT DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `resolved_at` DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

// Global initialization
getDB();

// Global localization helper
function __($text) {
  return $text;
}
?>
