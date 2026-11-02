<?php
/**
 * EPG для WebApp. Обёртка над includes/epg_live.php + fallback из канала.
 * GET channel|name|q|slug|channel_id
 */
$root = dirname(__DIR__);
if (!is_file($root . '/includes/functions.php')) {
  $root = dirname(__DIR__, 2);
}
require_once $root . '/includes/functions.php';
if (is_file($root . '/includes/auth.php')) {
  // не обязателен, но session может помочь
  try { require_once $root . '/includes/auth.php'; } catch (Throwable $e) {}
}
if (is_file($root . '/includes/epg_live.php')) {
  require_once $root . '/includes/epg_live.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

function ne_out(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$name = trim((string)($_GET['channel'] ?? $_GET['name'] ?? $_GET['q'] ?? $_GET['title'] ?? ''));
$slug = trim((string)($_GET['slug'] ?? ''));
$channelId = (int)($_GET['channel_id'] ?? 0);

// Подтянуть title/slug из БД
$channelRow = null;
try {
  if ($channelId > 0) {
    $st = db()->prepare('SELECT id, title, slug, epg_id, program FROM channels WHERE id = ? LIMIT 1');
    $st->execute([$channelId]);
    $channelRow = $st->fetch() ?: null;
  } elseif ($slug !== '') {
    $st = db()->prepare('SELECT id, title, slug, epg_id, program FROM channels WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $channelRow = $st->fetch() ?: null;
  } elseif ($name !== '') {
    $st = db()->prepare('SELECT id, title, slug, epg_id, program FROM channels WHERE title = ? OR slug = ? LIMIT 1');
    $st->execute([$name, $name]);
    $channelRow = $st->fetch() ?: null;
  }
} catch (Throwable $e) {
  // колонки epg_id/program могут отсутствовать
  try {
    if ($channelId > 0) {
      $st = db()->prepare('SELECT id, title, slug FROM channels WHERE id = ? LIMIT 1');
      $st->execute([$channelId]);
      $channelRow = $st->fetch() ?: null;
    } elseif ($slug !== '') {
      $st = db()->prepare('SELECT id, title, slug FROM channels WHERE slug = ? LIMIT 1');
      $st->execute([$slug]);
      $channelRow = $st->fetch() ?: null;
    }
  } catch (Throwable $e2) {}
}

$candidates = [];
foreach ([$name, $slug, $channelRow['title'] ?? '', $channelRow['slug'] ?? '', $channelRow['epg_id'] ?? '', $channelRow['program'] ?? ''] as $c) {
  $c = trim((string)$c);
  if ($c !== '' && !in_array($c, $candidates, true)) $candidates[] = $c;
}

if (!$candidates) {
  ne_out(['ok' => false, 'items' => [], 'error' => 'Укажите channel, slug или channel_id']);
}

$result = null;
$errors = [];
if (function_exists('epg_live_schedule_for')) {
  foreach ($candidates as $cand) {
    try {
      $r = epg_live_schedule_for($cand);
      if (!empty($r['ok']) && !empty($r['items'])) {
        $result = $r;
        $result['matched_query'] = $cand;
        break;
      }
      if (is_array($r) && !empty($r['error'])) $errors[] = $cand . ': ' . $r['error'];
    } catch (Throwable $e) {
      $errors[] = $cand . ': ' . $e->getMessage();
    }
  }
} else {
  $errors[] = 'includes/epg_live.php не подключён на сервере';
}

// Fallback: поле program у канала (если есть «сейчас»)
if ((!$result || empty($result['items'])) && $channelRow) {
  $prog = trim((string)($channelRow['program'] ?? ''));
  if ($prog !== '') {
    ne_out([
      'ok' => true,
      'source' => 'channel.program',
      'matched' => $channelRow['title'] ?? '',
      'now' => ['title' => $prog],
      'items' => [['title' => $prog, 'start' => '', 'stop' => '']],
      'note' => 'Только текущая передача из карточки канала (полный XMLTV недоступен)',
    ]);
  }
}

if ($result && !empty($result['ok'])) {
  ne_out($result);
}

ne_out([
  'ok' => false,
  'items' => [],
  'error' => 'Программа не найдена. На free-хостинге часто блокируется загрузка XMLTV снаружи.',
  'tried' => $candidates,
  'hints' => $errors,
]);
