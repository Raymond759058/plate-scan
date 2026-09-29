<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/* =============================================================================
 * Output escaping & security headers
 * ========================================================================== */

/** Escape a value for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function send_security_headers(bool $page = false): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if ($page) {
        // Tesseract.js (in-browser OCR fallback) is the only third-party code, served from jsDelivr.
        header("Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net 'wasm-unsafe-eval'; "
            . "worker-src 'self' blob: https://cdn.jsdelivr.net; "
            . "connect-src 'self' https://cdn.jsdelivr.net https://tessdata.projectnaptha.com; "
            . "img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self'; "
            . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
}

/* =============================================================================
 * Sessions & CSRF
 * ========================================================================== */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('platescan_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Checks the token sent in the X-CSRF-Token header (or a csrf_token form field). */
function csrf_valid(): bool
{
    start_session();
    $expected = $_SESSION['csrf'] ?? '';
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    return is_string($expected) && $expected !== '' && is_string($sent) && hash_equals($expected, $sent);
}

/* =============================================================================
 * JSON API plumbing
 * ========================================================================== */

/** Send a JSON body and stop. */
function json_out(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function json_ok(array $data, int $status = 200): never
{
    json_out(['status' => 'success', 'data' => $data], $status);
}

/** $extra is merged into the "error" object (e.g. fields, retry_after, fallback). */
function json_fail(string $code, string $message, int $status = 400, array $extra = []): never
{
    json_out(['status' => 'error', 'error' => ['code' => $code, 'message' => $message] + $extra], $status);
}

/**
 * Every API file calls this first: JSON headers, safe error handling,
 * method check, CSRF check (POST) and rate limiting.
 */
function api_bootstrap(string $endpoint, string $method, bool $needCsrf): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    send_security_headers();

    set_exception_handler(static function (Throwable $t): void {
        error_log('Unhandled ' . get_class($t) . ': ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
        json_fail('SERVER_ERROR', 'Something went wrong on our side. Please try again in a moment.', 500);
    });
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo '{"status":"error","error":{"code":"SERVER_ERROR","message":"Something went wrong on our side. Please try again in a moment."}}';
        }
    });

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        json_fail('METHOD_NOT_ALLOWED', 'This endpoint only accepts ' . $method . ' requests.', 405);
    }

    if ($needCsrf) {
        $ok = csrf_valid();
        session_write_close();            // release the session lock early
        if (!$ok) {
            json_fail('CSRF_INVALID', 'Your session has expired. Please refresh the page and try again.', 403);
        }
    }

    rate_limit($endpoint);
}

/** Decode a JSON request body (max 64 KB). */
function read_json_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        json_fail('INVALID_JSON', 'The request body must be valid JSON.', 400);
    }
    return $data;
}

/* =============================================================================
 * Rate limiting (fixed window, stored in MySQL)
 * ========================================================================== */

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (TRUST_PROXY_HEADER !== '' && !empty($_SERVER[TRUST_PROXY_HEADER])) {
        $candidate = trim(explode(',', (string) $_SERVER[TRUST_PROXY_HEADER])[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return substr($ip, 0, 45);
}

function rate_limit(string $endpoint): void
{
    $pdo = db();
    $ip = client_ip();
    $now = time();
    $windowStart = $now - RATE_LIMIT_WINDOW;

    // One atomic statement: start a new window if the old one expired, otherwise count up.
    // (MySQL evaluates the SET list left to right, so request_count sees the OLD last_request.)
    $pdo->prepare(
        'INSERT INTO rate_limits (ip_address, endpoint, request_count, last_request)
         VALUES (?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE
           request_count = IF(last_request <= ?, 1, request_count + 1),
           last_request  = IF(last_request <= ?, ?, last_request)'
    )->execute([$ip, $endpoint, $now, $windowStart, $windowStart, $now]);

    $st = $pdo->prepare('SELECT request_count, last_request FROM rate_limits WHERE ip_address = ? AND endpoint = ?');
    $st->execute([$ip, $endpoint]);
    $row = $st->fetch();

    if (random_int(1, 100) === 1) {   // occasional housekeeping
        $pdo->prepare('DELETE FROM rate_limits WHERE last_request < ?')->execute([$now - 3600]);
    }

    if ($row && (int) $row['request_count'] > RATE_LIMIT_MAX) {
        $retry = max(1, (int) $row['last_request'] + RATE_LIMIT_WINDOW - $now);
        header('Retry-After: ' . $retry);
        json_fail('RATE_LIMITED', "Too many requests. Please wait $retry seconds and try again.", 429, ['retry_after' => $retry]);
    }
}

/* =============================================================================
 * Validation & sanitising
 * ========================================================================== */

function clean_text(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

/** "abc 1234" / "ABC-1234" -> "ABC1234" */
function normalize_plate(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    return preg_replace('/[\s\-.]+/u', '', mb_strtoupper(trim($value), 'UTF-8')) ?? '';
}

function valid_plate(string $plate): bool
{
    return (bool) preg_match('/^[A-Z0-9]{2,12}$/', $plate);
}

/**
 * Validate a registration payload.
 * @return array{0: array<string,string>, 1: array<string,string>} [clean values, field => error message]
 */
function validate_vehicle(array $in): array
{
    $v = [];
    $errors = [];

    $v['plate_number'] = normalize_plate($in['plate_number'] ?? '');
    if (!valid_plate($v['plate_number'])) {
        $errors['plate_number'] = 'Enter a valid plate number: 2 to 12 letters or digits.';
    }

    $v['owner_name'] = clean_text($in['owner_name'] ?? '');
    if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'’\-\/@]{1,99}$/u', $v['owner_name'])) {
        $errors['owner_name'] = 'Enter the owner\'s full name (2 to 100 characters, letters only).';
    }

    $v['phone'] = clean_text($in['phone'] ?? '');
    if (!preg_match('/^\+?[0-9][0-9 \-]{5,18}[0-9]$/', $v['phone'])) {
        $errors['phone'] = 'Enter a valid phone number, e.g. +60 12-345 6789.';
    }

    foreach (['make' => 'Enter the vehicle make.', 'model' => 'Enter the vehicle model.'] as $f => $msg) {
        $v[$f] = clean_text($in[$f] ?? '');
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\-\/+]{0,49}$/u', $v[$f])) {
            $errors[$f] = $msg;
        }
    }

    $v['color'] = clean_text($in['color'] ?? '');
    if (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} \-]{1,29}$/u', $v['color'])) {
        $errors['color'] = 'Enter the vehicle colour, e.g. Silver.';
    }

    $v['body_type'] = clean_text($in['body_type'] ?? '');
    if (!in_array($v['body_type'], BODY_TYPES, true)) {
        $errors['body_type'] = 'Choose a body type from the list.';
    }

    $v['currency'] = strtoupper(clean_text($in['currency'] ?? ''));
    if (!array_key_exists($v['currency'], CURRENCIES)) {
        $errors['currency'] = 'Choose a currency from the list.';
    }

    return [$v, $errors];
}

/** Hide the middle of a phone number for the public registry. */
function mask_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($digits) < 6) {
        return '••••';
    }
    return ($phone[0] === '+' ? '+' : '') . substr($digits, 0, 2) . '••••' . substr($digits, -3);
}

/* =============================================================================
 * HTTP client (cURL, with a streams fallback)
 * ========================================================================== */

/**
 * @param array<string,mixed>|string|null $body  array => form-encoded POST, string => raw POST body
 * @return array{code:int, json:?array}|null     null on network failure
 */
function http_request(string $url, array|string|null $body = null, array $headers = [], int $timeout = 6): ?array
{
    $headers[] = 'Accept: application/json';
    $raw = false;
    $code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'PlateScan/1.0',
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false && curl_errno($ch) === 60) {
            // SSL cert bundle missing (common in local XAMPP): retry with SSL verification off
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        if ($raw === false) {
            error_log('HTTP request failed (' . parse_url($url, PHP_URL_HOST) . '): ' . curl_error($ch));
        }
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'method'        => $body === null ? 'GET' : 'POST',
            'header'        => implode("\r\n", $headers) . ($body !== null && is_array($body) ? "\r\nContent-Type: application/x-www-form-urlencoded" : ''),
            'content'       => $body === null ? '' : (is_array($body) ? http_build_query($body) : $body),
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (function_exists('http_get_last_response_headers')) {
            $responseHeaders = http_get_last_response_headers();
        } else {
            $responseHeaders = $http_response_header ?? null;
        }
        if (isset($responseHeaders[0]) && preg_match('#\s(\d{3})\s#', $responseHeaders[0], $m)) {
            $code = (int) $m[1];
        }
    }

    if ($raw === false) {
        return null;
    }
    $json = json_decode((string) $raw, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null];
}

/* =============================================================================
 * Exchange rates: MySQL cache (1 h) -> open.er-api.com -> frankfurter.dev -> stale cache
 * ========================================================================== */

/** Keep only supported currencies with sane numeric rates (USD is always 1). */
function filter_rates(array $rates): ?array
{
    $out = ['USD' => 1.0];
    foreach (CURRENCIES as $code => $_name) {
        if ($code === 'USD') {
            continue;
        }
        if (!isset($rates[$code]) || !is_numeric($rates[$code]) || (float) $rates[$code] <= 0) {
            return null;    // incomplete payload: reject it so we never price with missing data
        }
        $out[$code] = (float) $rates[$code];
    }
    return $out;
}

function parse_er_api(?array $json): ?array
{
    if (!$json || ($json['result'] ?? '') !== 'success' || !isset($json['rates']) || !is_array($json['rates'])) {
        return null;
    }
    $rates = filter_rates($json['rates']);
    return $rates ? ['rates' => $rates, 'provider' => 'open.er-api.com'] : null;
}

function parse_frankfurter(?array $json): ?array
{
    if (!$json || !isset($json['rates']) || !is_array($json['rates'])) {
        return null;
    }
    $rates = filter_rates($json['rates'] + ['USD' => 1]);
    return $rates ? ['rates' => $rates, 'provider' => 'api.frankfurter.dev'] : null;
}

/** @return array{rates:array<string,float>, provider:string, fetched_at:int}|null */
function rates_latest_cached(): ?array
{
    $st = db()->prepare("SELECT rates_json, fetched_at FROM rate_cache WHERE base_currency = 'USD' ORDER BY fetched_at DESC, id DESC LIMIT 1");
    $st->execute();
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $data = json_decode((string) $row['rates_json'], true);
    if (!is_array($data) || !isset($data['rates']) || !is_array($data['rates'])) {
        return null;
    }
    return [
        'rates'      => $data['rates'],
        'provider'   => (string) ($data['provider'] ?? 'cache'),
        'fetched_at' => (int) $row['fetched_at'],
    ];
}

function rates_response(array $cached, string $source): array
{
    return [
        'base'       => 'USD',
        'base_fee'   => (float) REGISTRATION_FEE_USD,
        'rates'      => $cached['rates'],
        'source'     => $source,               // live | cache | stale
        'provider'   => $cached['provider'],
        'fetched_at' => $cached['fetched_at'],
        'expires_at' => $cached['fetched_at'] + RATES_TTL,
    ];
}

/**
 * Returns rates for conversion, using the cache whenever possible.
 * Throws RuntimeException('RATES_UNAVAILABLE') only if there is no cache AND both APIs fail.
 */
function get_rates(): array
{
    $pdo = db();
    $now = time();

    $cached = rates_latest_cached();
    if ($cached && $now - $cached['fetched_at'] < RATES_TTL) {
        return rates_response($cached, 'cache');
    }

    // Only one request refreshes; the others keep using the old cache instead of stampeding the APIs.
    $gotLock = (int) $pdo->query("SELECT GET_LOCK('platescan_rates', " . ($cached ? 0 : 6) . ')')->fetchColumn() === 1;
    if (!$gotLock) {
        if ($cached) {
            return rates_response($cached, 'cache');
        }
        throw new RuntimeException('RATES_UNAVAILABLE');
    }

    try {
        // Someone else may have refreshed while we waited for the lock.
        $cached = rates_latest_cached();
        if ($cached && $now - $cached['fetched_at'] < RATES_TTL) {
            return rates_response($cached, 'cache');
        }

        // Recently failed? Don't hammer dead APIs on every request.
        $st = $pdo->prepare("SELECT MAX(fetched_at) FROM rate_cache WHERE base_currency = 'FAIL'");
        $st->execute();
        $lastFail = (int) $st->fetchColumn();
        $skipApis = $lastFail > 0 && $now - $lastFail < RATES_RETRY_AFTER;

        $fresh = null;
        if (!$skipApis) {
            $r = http_request(RATES_PRIMARY_URL);
            $fresh = ($r && $r['code'] === 200) ? parse_er_api($r['json']) : null;
            if (!$fresh) {
                error_log('Primary rate API failed, trying fallback');
                $r = http_request(RATES_FALLBACK_URL);
                $fresh = ($r && $r['code'] === 200) ? parse_frankfurter($r['json']) : null;
            }
            if (!$fresh) {
                error_log('Both rate APIs failed');
                $pdo->prepare("INSERT INTO rate_cache (base_currency, rates_json, fetched_at) VALUES ('FAIL', '{}', ?)")->execute([$now]);
            }
        }

        if ($fresh) {
            $pdo->prepare("INSERT INTO rate_cache (base_currency, rates_json, fetched_at) VALUES ('USD', ?, ?)")
                ->execute([json_encode(['provider' => $fresh['provider'], 'rates' => $fresh['rates']]), $now]);
            // Keep the table small: only the newest 20 rows.
            $pdo->exec('DELETE FROM rate_cache WHERE id NOT IN (SELECT id FROM (SELECT id FROM rate_cache ORDER BY id DESC LIMIT 20) t)');
            return rates_response(['rates' => $fresh['rates'], 'provider' => $fresh['provider'], 'fetched_at' => $now], 'live');
        }

        if ($cached) {
            return rates_response($cached, 'stale');   // both APIs down: old rates beat no rates
        }
        throw new RuntimeException('RATES_UNAVAILABLE');
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('platescan_rates')")->fetchColumn();
    }
}

/** USD amount -> target currency, rounded to 2 decimals. */
function convert_usd(float $usd, string $currency, array $rates): float
{
    return round($usd * (float) $rates[$currency], 2);
}
