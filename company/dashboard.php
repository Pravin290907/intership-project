<?php
/**
 * Company Recruiter Dashboard Gateway
 * CampusRecruit Recruiter Portal Entry
 */
require_once __DIR__ . '/../config/auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'company') {
  header("Location: login.php");
  exit;
}

require_once __DIR__ . '/../recruiter_dashboard.php';
