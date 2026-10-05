<?php

declare(strict_types=1);

use Bitenxt\Studio\App;
use Bitenxt\Studio\Config;
use Bitenxt\Studio\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

// Never let PHP print warnings, paths or stack traces to the client.
ini_set('display_errors', '0');
error_reporting(E_ALL);

$config = Config::fromEnvironment(dirname(__DIR__) . '/.env');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Local `php -S` only: let the built-in server serve static files (studio.html, *.js).
if (PHP_SAPI === 'cli-server' && preg_match('#^/[\w-]+\.(html|js|css)$#', $path) && is_file(__DIR__ . $path)) {
    return false;
}

// --- CORS: the Pro frontend may call /api, the studio frontend(s) may call /studio/api ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$sameOrigin = $origin !== '' && preg_replace('#^https?://#', '', $origin) === ($_SERVER['HTTP_HOST'] ?? null);
$allowed = str_starts_with($path, '/studio/') ? $config->studioAllowedOrigins : $config->allowedOrigins;
$originAllowed = $sameOrigin || in_array($origin, $allowed, true);
if ($origin !== '' && $originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Access-Control-Max-Age: 600');
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($path === '/health') {
    $respond(200, ['ok' => true]);
}
if ($path === '/' || $path === '/studio' || $path === '/studio/') {
    header('Location: /studio.html', true, 302);
    exit;
}
if ($path === '/order-messages.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('Access-Control-Allow-Origin: *');
    // Kept outside public/ like the chatbot's widget, so it is always served through here.
    readfile(dirname(__DIR__) . '/src/Widget/order-messages.js');
    exit;
}
if ($origin !== '' && !$originAllowed) {
    $respond(403, ['error' => 'origin_not_allowed']);
}

// Behind Cloud Run REMOTE_ADDR is the proxy; count back the trusted hops in X-Forwarded-For.
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if ($config->trustedProxyHops > 0 && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $hops = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
    $clientIp = $hops[count($hops) - $config->trustedProxyHops] ?? $clientIp;
}

// Body: JSON, or multipart form fields + files[] for uploads.
$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$body = [];
$files = [];
if (str_starts_with($contentType, 'multipart/form-data')) {
    $body = $_POST;
    $upload = $_FILES['files'] ?? null;
    if (is_array($upload)) {
        foreach ((array) $upload['name'] as $i => $name) {
            $tmp = (string) ((array) $upload['tmp_name'])[$i];
            $error = (int) ((array) $upload['error'])[$i];
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            // Only real uploads from this request ever reach the storage layer.
            if ($error === UPLOAD_ERR_OK && !is_uploaded_file($tmp)) {
                $respond(400, ['error' => 'invalid_upload']);
            }
            $files[] = ['name' => (string) $name, 'tmp' => $tmp, 'size' => (int) ((array) $upload['size'])[$i], 'error' => $error];
        }
    }
} elseif ($method === 'POST') {
    $decoded = json_decode((string) file_get_contents('php://input', length: 65536), true);
    $body = is_array($decoded) ? $decoded : [];
}
// A POST over post_max_size arrives with no fields or files; say so instead of "empty message".
if ($method === 'POST' && str_starts_with($contentType, 'multipart/') && $_POST === [] && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $respond(413, ['error' => 'too_large', 'message' => 'The upload is too large.']);
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
    }
}

try {
    $response = App::api($config)->handle(new Request(
        $method,
        $path,
        $headers,
        array_map('strval', array_filter($_GET, 'is_scalar')),
        $body,
        $files,
        $clientIp,
    ));

    if ($response->isFile()) {
        // Always a download, never rendered in the browser.
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $response->fileName);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize((string) $response->filePath));
        header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($response->fileName));
        header('Content-Security-Policy: sandbox');
        readfile((string) $response->filePath);
        exit;
    }
    $respond($response->status, $response->body);
} catch (\Throwable $e) {
    error_log('[bitenxt-studio] ' . $e::class . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    $respond(500, ['error' => 'internal_error', 'message' => 'Something went wrong on our side. Please try again shortly.']);
}
