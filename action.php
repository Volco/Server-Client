<?php
// Same-origin proxy for Pokemon Showdown loginserver action.php
// Ensures Set-Cookie from play.pokemonshowdown.com is rewritten to our domain for persistence

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$server = isset($_GET['server']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['server']) : 'showdown';
$loginserver = 'http://play.pokemonshowdown.com/~~' . $server . '/action.php';

$postKeys = [];
if (isset($_GET['post'])) {
    $postSpec = urldecode($_GET['post']);
    $postKeys = array_filter(explode('|', $postSpec));
}
$body = [];
if (!empty($postKeys)) {
    foreach ($postKeys as $k) {
        if ($k === '') continue;
        if (isset($_POST[$k])) $body[$k] = $_POST[$k];
    }
} else {
    foreach ($_POST as $k => $v) $body[$k] = $v;
}

$ch = curl_init();
$qs = $_GET;
unset($qs['server']);
$query = http_build_query($qs);
$url = $loginserver . ($query ? ('?' . $query) : '');

curl_setopt($ch, CURLOPT_URL, $url);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
}
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_ENCODING, '');
// Forward incoming cookies to upstream
$cookiePairs = [];
foreach ($_COOKIE as $ck => $cv) {
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $ck)) continue;
    $cookiePairs[] = $ck . '=' . $cv;
}
$headers = ['User-Agent: DawnPS-LoginProxy/1.0', 'Accept: */*'];
if ($cookiePairs) { $headers[] = 'Cookie: ' . implode('; ', $cookiePairs); }
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$resp = curl_exec($ch);
if ($resp === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo ']{"actionerror":"Login proxy error: ' . htmlspecialchars(curl_error($ch)) . '"}';
    curl_close($ch);
    exit;
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$rawHeaders = substr($resp, 0, $headerSize);
$bodyOut = substr($resp, $headerSize);

$lines = preg_split('/\r\n|\n|\r/', $rawHeaders);
foreach ($lines as $line) {
    if (!$line || stripos($line, 'HTTP/') === 0) continue;
    if (stripos($line, 'Set-Cookie:') === 0) continue;
    if (stripos($line, 'Transfer-Encoding:') === 0) continue;
    header($line, false);
}

foreach ($lines as $line) {
    if (stripos($line, 'Set-Cookie:') !== 0) continue;
    $cookie = trim(substr($line, strlen('Set-Cookie:')));
    $parts = explode(';', $cookie);
    $kv = array_shift($parts);
    $attrs = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (stripos($p, 'domain=') === 0) continue;
        if (stripos($p, 'samesite=') === 0) continue;
        $attrs[] = $p;
    }
    $hasPath = false;
    foreach ($attrs as $a) if (stripos($a, 'path=') === 0) { $hasPath = true; break; }
    if (!$hasPath) $attrs[] = 'Path=/';
    $host = $_SERVER['HTTP_HOST'];
    $attrs[] = 'Domain=' . $host;
    $hasSecure = false;
    foreach ($attrs as $a) if (strcasecmp(trim($a), 'secure') === 0) { $hasSecure = true; break; }
    if (!$hasSecure) $attrs[] = 'Secure';
    $attrs[] = 'SameSite=None';
    header('Set-Cookie: ' . $kv . '; ' . implode('; ', $attrs), false);
}

http_response_code($status ?: 200);
if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
echo $bodyOut;