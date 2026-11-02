<?php
/**
 * Гео: РФ + СНГ. Работает даже на слабом хостинге:
 * 1) Cloudflare CF-IPCOUNTRY
 * 2) lookup по IP (если curl/fopen есть)
 * 3) fallback-заголовки
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

function geo_out(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$cis = ['RU','BY','KZ','KG','AM','AZ','MD','TJ','TM','UZ','UA','GE'];

function geo_client_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (empty($_SERVER[$k])) continue;
    $v = (string)$_SERVER[$k];
    if ($k === 'HTTP_X_FORWARDED_FOR') {
      $parts = array_map('trim', explode(',', $v));
      $v = $parts[0] ?? $v;
    }
    if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
  }
  return '';
}

function geo_http_get(string $url): ?string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 6,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_USERAGENT => 'StreamLive-Geo/1.1',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return is_string($raw) ? $raw : null;
  }
  $ctx = stream_context_create([
    'http' => ['timeout' => 6, 'header' => "User-Agent: StreamLive-Geo/1.1\r\n"],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  return is_string($raw) ? $raw : null;
}

function geo_lookup(string $ip): array {
  $debug = [];
  // Cloudflare
  if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $c = strtoupper(trim((string)$_SERVER['HTTP_CF_IPCOUNTRY']));
    $debug[] = 'cf=' . $c;
    if (preg_match('/^[A-Z]{2}$/', $c) && $c !== 'XX') {
      return ['country' => $c, 'via' => 'cloudflare', 'debug' => $debug];
    }
  }
  // Some panels
  foreach (['HTTP_X_COUNTRY_CODE','HTTP_X_APPENGINE_COUNTRY','GEOIP_COUNTRY_CODE'] as $h) {
    if (empty($_SERVER[$h])) continue;
    $c = strtoupper(trim((string)$_SERVER[$h]));
    $debug[] = $h . '=' . $c;
    if (preg_match('/^[A-Z]{2}$/', $c)) return ['country' => $c, 'via' => $h, 'debug' => $debug];
  }

  if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
    return ['country' => '', 'via' => 'local', 'debug' => $debug];
  }

  $urls = [
    'https://ipapi.co/' . rawurlencode($ip) . '/country/',
    'https://api.country.is/' . rawurlencode($ip),
    'https://ipwho.is/' . rawurlencode($ip),
  ];
  foreach ($urls as $url) {
    $raw = geo_http_get($url);
    $debug[] = substr($url, 0, 40) . '=>' . substr((string)$raw, 0, 40);
    if ($raw === null || $raw === '') continue;
    $raw = trim($raw);
    if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
      return ['country' => strtoupper($raw), 'via' => 'http', 'debug' => $debug];
    }
    $j = json_decode($raw, true);
    if (is_array($j)) {
      $c = $j['country'] ?? $j['country_code'] ?? '';
      if (is_string($c) && preg_match('/^[A-Za-z]{2}$/', $c)) {
        return ['country' => strtoupper($c), 'via' => 'http-json', 'debug' => $debug];
      }
    }
  }
  return ['country' => '', 'via' => 'none', 'debug' => $debug];
}

$ip = geo_client_ip();
$look = geo_lookup($ip);
$country = (string)($look['country'] ?? '');
$allowed = ($country !== '' && in_array($country, $cis, true));

geo_out([
  'ok' => true,
  'ip' => $ip,
  'country' => $country,
  'allowed' => $allowed,
  'via' => $look['via'] ?? '',
  'region' => 'RU_CIS',
  'message' => $allowed
    ? 'OK'
    : 'Похоже, вы используете VPN. Сервис доступен в России и странах СНГ.',
  'debug' => $look['debug'] ?? [],
]);
