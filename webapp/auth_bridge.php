<?php
/**
 * StreamLive WebApp Auth Bridge
 * Обычный form POST (не fetch/XHR) — чаще проходит anti-bot.
 * Ответ: HTML с postMessage родителю или JSON если Accept: application/json.
 */
error_reporting(0);
@ini_set('display_errors', '0');

$root = dirname(__DIR__); // site root when this is /webapp/auth_bridge.php
if (!is_file($root . '/includes/functions.php')) {
  // try parent of webapp
  $root = dirname(dirname(__DIR__));
}
if (!is_file($root . '/includes/functions.php')) {
  $root = dirname(__DIR__);
}

function bridge_json($a, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function bridge_html_msg($payload) {
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $jsonSafe = str_replace(['</', '<!--'], ['<\/', '<\!--'], $json);
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>auth</title></head><body>';
  echo '<script>(function(){var d=' . $jsonSafe . ';';
  echo 'try{if(window.parent&&window.parent!==window)window.parent.postMessage({type:"sl_auth",data:d},"*");}catch(e){}';
  echo 'document.write("<pre>"+JSON.stringify(d)+"</pre>");';
  echo '})();</script></body></html>';
  exit;
}

function bridge_out($a, $code = 200) {
  $wantJson = false;
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  if (strpos($accept, 'application/json') !== false) $wantJson = true;
  if (!empty($_GET['json']) || !empty($_POST['json'])) $wantJson = true;
  if ($wantJson) bridge_json($a, $code);
  bridge_html_msg($a);
}

// Includes
try {
  require_once $root . '/includes/functions.php';
  require_once $root . '/includes/auth.php';
  if (is_file($root . '/includes/api_auth.php')) require_once $root . '/includes/api_auth.php';
  if (is_file($root . '/includes/totp.php')) require_once $root . '/includes/totp.php';
} catch (Throwable $e) {
  bridge_out(['ok' => false, 'error' => 'bootstrap: ' . $e->getMessage()], 500);
}

function b_user_payload(array $u): array {
  return [
    'id' => (int)$u['id'],
    'username' => (string)($u['username'] ?? ''),
    'email' => (string)($u['email'] ?? ''),
    'avatar_url' => (string)($u['avatar_url'] ?? ''),
    'role' => (string)($u['role'] ?? 'user'),
  ];
}

function b_find_user(string $login) {
  $login = trim($login);
  if ($login === '') return null;
  try {
    $st = db()->prepare('SELECT * FROM users WHERE LOWER(email)=LOWER(?) OR username=? LIMIT 1');
    $st->execute([$login, $login]);
    return $st->fetch() ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $action = (string)($_GET['action'] ?? 'ping');
  if ($action === 'ping') {
    bridge_json(['ok' => true, 'ping' => 1, 'bridge' => 1]);
  }
  if ($action === 'me') {
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) bridge_json(['ok' => false, 'error' => 'Не авторизован'], 401);
    bridge_json(['ok' => true, 'user' => b_user_payload($u)]);
  }
  // mini form for debug
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><body style="font:14px system-ui;background:#111;color:#eee;padding:20px">';
  echo '<h3>StreamLive Auth Bridge</h3>';
  echo '<form method="post"><input type="hidden" name="action" value="login">';
  echo '<p><input name="login" placeholder="login" style="padding:8px;width:100%;max-width:280px"></p>';
  echo '<p><input name="password" type="password" placeholder="password" style="padding:8px;width:100%;max-width:280px"></p>';
  echo '<button type="submit">login</button></form></body></html>';
  exit;
}

// POST
$in = $_POST ?: [];
if (!$in) {
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  if (is_array($j)) $in = $j;
  elseif ($raw) { parse_str($raw, $p); if (is_array($p)) $in = $p; }
}
$action = (string)($in['action'] ?? '');

if ($action === 'me') {
  $u = function_exists('current_user') ? current_user() : null;
  if (!$u) bridge_out(['ok' => false, 'error' => 'Не авторизован'], 401);
  bridge_out(['ok' => true, 'user' => b_user_payload($u)]);
}

if ($action === 'logout') {
  $_SESSION = [];
  if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();
  bridge_out(['ok' => true]);
}

if ($action === 'login') {
  $login = trim((string)($in['login'] ?? $in['email'] ?? ''));
  $password = (string)($in['password'] ?? '');
  $code = preg_replace('/\s+/', '', (string)($in['code'] ?? ''));
  if ($login === '' || $password === '') {
    bridge_out(['ok' => false, 'error' => 'Введите логин и пароль'], 400);
  }
  $user = b_find_user($login);
  if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
    bridge_out(['ok' => false, 'error' => 'Неверный логин или пароль'], 401);
  }
  if (!empty($user['is_banned'])) {
    bridge_out(['ok' => false, 'error' => 'Аккаунт заблокирован'], 403);
  }
  $user2fa = !empty($user['totp_enabled']) && !empty($user['totp_secret']);
  if ($user2fa) {
    if ($code === '') {
      bridge_out(['ok' => false, 'need_2fa' => true, 'error' => 'Введите код 2FA']);
    }
    if (!class_exists('Totp') || !Totp::verify($user['totp_secret'], $code)) {
      bridge_out(['ok' => false, 'need_2fa' => true, 'error' => 'Неверный код 2FA'], 401);
    }
  }
  if (function_exists('login_user')) login_user((int)$user['id']);
  else {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['user_id'] = (int)$user['id'];
  }
  try { db()->prepare('UPDATE users SET last_seen=NOW() WHERE id=?')->execute([(int)$user['id']]); } catch (Throwable $e) {}
  $out = ['ok' => true, 'user' => b_user_payload($user)];
  if (function_exists('api_issue_key')) {
    try { $out['api_key'] = api_issue_key((int)$user['id']); } catch (Throwable $e) {}
  }
  bridge_out($out);
}

if ($action === 'login_2fa' || $action === 'totp_only') {
  $login = trim((string)($in['login'] ?? ''));
  $code = preg_replace('/\s+/', '', (string)($in['code'] ?? ''));
  if ($login === '' || $code === '') bridge_out(['ok' => false, 'error' => 'Логин и код 2FA'], 400);
  $user = b_find_user($login);
  if (!$user || empty($user['totp_enabled']) || empty($user['totp_secret'])) {
    bridge_out(['ok' => false, 'error' => 'Нужна 2FA на аккаунте'], 401);
  }
  if (!class_exists('Totp') || !Totp::verify($user['totp_secret'], $code)) {
    bridge_out(['ok' => false, 'error' => 'Неверный код 2FA'], 401);
  }
  if (!empty($user['is_banned'])) bridge_out(['ok' => false, 'error' => 'Аккаунт заблокирован'], 403);
  if (function_exists('login_user')) login_user((int)$user['id']);
  else {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['user_id'] = (int)$user['id'];
  }
  bridge_out(['ok' => true, 'user' => b_user_payload($user)]);
}

if ($action === 'register') {
  $email = trim((string)($in['email'] ?? ''));
  $username = trim((string)($in['username'] ?? $in['login'] ?? ''));
  $password = (string)($in['password'] ?? '');
  $phone = trim((string)($in['phone'] ?? ''));
  if ($email === '' || $username === '' || strlen($password) < 6) {
    bridge_out(['ok' => false, 'error' => 'Email, логин и пароль (от 6 символов)'], 400);
  }
  try {
    $st = db()->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
    $st->execute([$email, $username]);
    if ($st->fetch()) bridge_out(['ok' => false, 'error' => 'Email или логин уже заняты'], 400);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db()->prepare('INSERT INTO users (email, username, password_hash, phone, created_at) VALUES (?,?,?,?,NOW())')
      ->execute([$email, $username, $hash, $phone ?: null]);
    $id = (int)db()->lastInsertId();
    $user = b_find_user($username) ?: ['id' => $id, 'username' => $username, 'email' => $email, 'role' => 'user'];
    if (function_exists('login_user')) login_user($id);
    else {
      if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
      $_SESSION['user_id'] = $id;
    }
    bridge_out(['ok' => true, 'user' => b_user_payload($user)]);
  } catch (Throwable $e) {
    bridge_out(['ok' => false, 'error' => 'Регистрация не удалась'], 500);
  }
}

bridge_out(['ok' => false, 'error' => 'unknown action'], 400);
