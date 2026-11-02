<?php
/**
 * JSON-студия автора для WebApp (данные как platforma/studio).
 * GET/POST action=dashboard|analytics|videos|channels
 */
$root = dirname(__DIR__);
// support both /api and /webapp/api layout
if (!is_file($root . '/includes/functions.php')) {
  $root = dirname(__DIR__, 2);
}
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

function ns_out(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function ns_user(): ?array {
  if (function_exists('current_user')) {
    $u = current_user();
    if ($u && !empty($u['id'])) return $u;
  }
  $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? ''));
  if ($key === '') {
    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $key = trim((string)($in['api_key'] ?? ''));
  }
  if ($key !== '') {
    try {
      $st = db()->prepare('SELECT u.* FROM users u INNER JOIN api_keys k ON k.user_id = u.id WHERE k.api_key = ? LIMIT 1');
      $st->execute([$key]);
      $u = $st->fetch();
      if ($u) return $u;
    } catch (Throwable $e) {}
  }
  return null;
}

$user = ns_user();
if (!$user) ns_out(['ok' => false, 'error' => 'Нужно войти'], 401);
$uid = (int)$user['id'];
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'dashboard'));

// channels owned
$channels = [];
try {
  $st = db()->prepare('SELECT id, title, slug, type, status, views, cover_url, logo_url FROM channels WHERE owner_id = ? ORDER BY id DESC');
  $st->execute([$uid]);
  $channels = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $st = db()->prepare('SELECT id, title, slug, type, status FROM channels WHERE user_id = ? ORDER BY id DESC');
    $st->execute([$uid]);
    $channels = $st->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

$videos = [];
try {
  $st = db()->prepare(
    "SELECT id, title, slug, views_count, likes_count, comments_count, status, created_at, thumbnail_url, channel_id
     FROM videos WHERE user_id = ? ORDER BY views_count DESC LIMIT 50"
  );
  $st->execute([$uid]);
  $videos = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $st = db()->prepare('SELECT id, title, slug, views_count, status, created_at FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 50');
    $st->execute([$uid]);
    $videos = $st->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

// also videos on owned channels
try {
  $st = db()->prepare(
    'SELECT v.id, v.title, v.slug, v.views_count, v.status, v.created_at, v.thumbnail_url, v.channel_id
     FROM videos v INNER JOIN channels c ON c.id = v.channel_id
     WHERE c.owner_id = ? ORDER BY v.views_count DESC LIMIT 50'
  );
  $st->execute([$uid]);
  $via = $st->fetchAll() ?: [];
  if (count($via) > count($videos)) $videos = $via;
} catch (Throwable $e) {}

$videoViews = 0;
$likes = 0;
$comments = 0;
foreach ($videos as $v) {
  $videoViews += (int)($v['views_count'] ?? 0);
  $likes += (int)($v['likes_count'] ?? 0);
  $comments += (int)($v['comments_count'] ?? 0);
}
$channelViews = 0;
foreach ($channels as $c) $channelViews += (int)($c['views'] ?? 0);

$favCount = 0;
try {
  $st = db()->prepare('SELECT COUNT(*) FROM favorites f JOIN channels c ON c.id = f.channel_id WHERE c.owner_id = ?');
  $st->execute([$uid]);
  $favCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}

if ($action === 'channels') {
  ns_out(['ok' => true, 'channels' => $channels]);
}
if ($action === 'videos') {
  ns_out(['ok' => true, 'videos' => $videos]);
}

// dashboard / analytics
ns_out([
  'ok' => true,
  'user' => [
    'id' => $uid,
    'username' => (string)($user['username'] ?? ''),
  ],
  'stats' => [
    'channels' => count($channels),
    'videos' => count($videos),
    'video_views' => $videoViews,
    'channel_views' => $channelViews,
    'likes' => $likes,
    'comments' => $comments,
    'favorites' => $favCount,
  ],
  'channels' => array_slice($channels, 0, 20),
  'top_videos' => array_slice($videos, 0, 15),
  'studio_web' => '/platforma/studio/',
]);
