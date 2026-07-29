<?php
/**
 * Notifications Management & Real-time Alert Drawer API
 * Returns grouped list of notices, filters read states, and updates counters.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/auth.php';

if (!isset($_SESSION['user_id'])) {
  echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
  exit;
}

$userId = $_SESSION['user_id'];
$db = getDB();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

try {
  if ($action === 'mark_read') {
    $notifyId = (int)($_POST['notification_id'] ?? $_GET['notification_id'] ?? 0);
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notifyId, $userId]);
    echo json_encode(['status' => 'success', 'message' => 'Notification marked as read']);
    exit;
  }

  if ($action === 'mark_all_read') {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['status' => 'success', 'message' => 'All alerts marked as read']);
    exit;
  }
  
  if ($action === 'delete') {
    $notifyId = (int)($_POST['notification_id'] ?? $_GET['notification_id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notifyId, $userId]);
    echo json_encode(['status' => 'success', 'message' => 'Notification deleted']);
    exit;
  }

  // Retrieve notifications
  $search = $_GET['search'] ?? '';
  $filter = $_GET['filter'] ?? 'all'; // 'all', 'unread', 'read'
  
  $queryStr = "SELECT id, title, description, is_read, category, priority, url, created_at FROM notifications WHERE user_id = :uid";
  $params = ['uid' => $userId];

  if ($filter === 'unread') {
    $queryStr .= " AND is_read = 0";
  } else if ($filter === 'read') {
    $queryStr .= " AND is_read = 1";
  }

  if (!empty($search)) {
    $queryStr .= " AND (title LIKE :search OR description LIKE :search)";
    $params['search'] = '%' . $search . '%';
  }

  $queryStr .= " ORDER BY created_at DESC LIMIT 50";

  $stmt = $db->prepare($queryStr);
  $stmt->execute($params);
  $list = $stmt->fetchAll();

  // Grouping notifications into Today, Yesterday, This Week, Older
  $today = [];
  $yesterday = [];
  $thisWeek = [];
  $older = [];

  $now = time();
  $todayDate = date('Y-m-d');
  $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
  $oneWeekAgo = strtotime('-7 days');

  foreach ($list as $n) {
    $createdDate = date('Y-m-d', strtotime($n['created_at']));
    $createdTime = strtotime($n['created_at']);

    if ($createdDate === $todayDate) {
      $today[] = $n;
    } else if ($createdDate === $yesterdayDate) {
      $yesterday[] = $n;
    } else if ($createdTime >= $oneWeekAgo) {
      $thisWeek[] = $n;
    } else {
      $older[] = $n;
    }
  }

  // Count unread
  $unreadCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
  $unreadCount->execute([$userId]);
  $cnt = (int)$unreadCount->fetchColumn();

  echo json_encode([
    'status' => 'success',
    'unread_count' => $cnt,
    'notifications' => [
      'today' => $today,
      'yesterday' => $yesterday,
      'thisWeek' => $thisWeek,
      'older' => $older
    ]
  ]);
  exit;

} catch (PDOException $e) {
  echo json_encode(['status' => 'error', 'message' => 'Database operation error: ' . $e->getMessage()]);
  exit;
}
?>
