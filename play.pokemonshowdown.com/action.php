<?php
// Same-origin proxy for Pokemon Showdown loginserver action.php
// Ensures Set-Cookie from play.pokemonshowdown.com is rewritten to our domain for persistence

// Basic CORS for client
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$server = isset($_GET['server']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['server']) : 'showdown';
$loginserver = 'https://play.pokemonshowdown.com/~~' . $server . '/action.php';

// Collect POST body fields (supports our jQuery postProxy helper and standard form posts)
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
    // fallback: take entire POST
    foreach ($_POST as $k => $v) $body[$k] = $v;
}

// Forward cookies we already have for loginserver domain (rare) and any sid we store locally
// We do NOT require credentials here; loginserver returns assertion and its own cookies

$ch = curl_init();

// Append query string (excluding our server param)
$qs = $_GET;
unset($qs['server']);
$query = http_build_query($qs);
$url = $loginserver . ($query ? ('?' . $query) : '');

curl_setopt($ch, CURLOPT_URL, $url);
// Use POST only if the incoming request was POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
}
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_ENCODING, ''); // auto-decode gzip/deflate
// Forward incoming cookies (notably `sid`) to the upstream loginserver
$cookiePairs = [];
foreach ($_COOKIE as $ck => $cv) {
    // basic safety: only allow simple cookie names
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $ck)) continue;
    $cookiePairs[] = $ck . '=' . $cv;
}
$headers = ['User-Agent: DawnPS-LoginProxy/1.0', 'Accept: */*'];
if ($cookiePairs) { $headers[] = 'Cookie: ' . implode('; ', $cookiePairs); }
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Execute
$resp = curl_exec($ch);
if ($resp === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo ']{"actionerror":"Login proxy error: ' . htmlspecialchars(curl_error($ch)) . '"}';
    curl_close($ch);
    exit;
}

// Debug log for troubleshooting (remove after fixing)
$debugInfo = "DawnPS Proxy: " . $url . " -> " . strlen($resp) . " bytes, Status: " . curl_getinfo($ch, CURLINFO_HTTP_CODE);
$debugInfo .= " POST: " . json_encode($body);
$debugInfo .= " COOKIES: " . json_encode($_COOKIE);
error_log($debugInfo);

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$rawHeaders = substr($resp, 0, $headerSize);
$bodyOut = substr($resp, $headerSize);

// If it's an error response, log it for debugging
if ($status >= 400 || strpos($bodyOut, '"actionerror"') !== false) {
    error_log("DawnPS Proxy ERROR Response: " . substr($bodyOut, 0, 500));
}

// Relay non-cookie headers
$lines = preg_split('/\r\n|\n|\r/', $rawHeaders);
foreach ($lines as $line) {
    if (!$line || stripos($line, 'HTTP/') === 0) continue;
    if (stripos($line, 'Set-Cookie:') === 0) continue; // handle below
    if (stripos($line, 'Transfer-Encoding:') === 0) continue;
    header($line, false);
}

// Rewrite Set-Cookie to our domain for persistence
foreach ($lines as $line) {
    if (stripos($line, 'Set-Cookie:') !== 0) continue;
    $cookie = trim(substr($line, strlen('Set-Cookie:')));
    // Force attributes: Secure; SameSite=None; Path=/; Domain=<our host>; Max-Age preserved if present
    $parts = explode(';', $cookie);
    $kv = array_shift($parts); // name=value
    $attrs = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        // Skip original Domain and SameSite
        if (stripos($p, 'domain=') === 0) continue;
        if (stripos($p, 'samesite=') === 0) continue;
        // Keep other attributes (Expires, Max-Age, Path, HttpOnly, Secure)
        $attrs[] = $p;
    }
    // Ensure Path=/
    $hasPath = false;
    foreach ($attrs as $a) if (stripos($a, 'path=') === 0) { $hasPath = true; break; }
    if (!$hasPath) $attrs[] = 'Path=/';
    // Set cookie for current host
    $host = $_SERVER['HTTP_HOST'];
    $attrs[] = 'Domain=' . $host;
    // Ensure Secure and SameSite=None to allow third-party scenarios with iframes, but mainly for HTTPS
    $hasSecure = false;
    foreach ($attrs as $a) if (strcasecmp(trim($a), 'secure') === 0) { $hasSecure = true; break; }
    if (!$hasSecure) $attrs[] = 'Secure';
    $attrs[] = 'SameSite=None';
    header('Set-Cookie: ' . $kv . '; ' . implode('; ', $attrs), false);
}

http_response_code($status ?: 200);
// Ensure text/plain to match loginserver
if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
echo $bodyOut;