<?php
/**
 * Application configuration.
 *
 * Edit the values below, OR create includes/config.local.php containing
 * define('DB_NAME', '...'); lines. Anything defined there wins, so your
 * settings survive re-uploading this file.
 */
declare(strict_types=1);

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// ---- Database ---------------------------------------------------------------
defined('DB_HOST')    || define('DB_HOST', 'localhost');   // iFastNet: the MySQL host shown in your control panel
defined('DB_PORT')    || define('DB_PORT', 3306);
defined('DB_NAME')    || define('DB_NAME', 'synergy1_raymondtanzijian_plate_scan');
defined('DB_USER')    || define('DB_USER', 'synergy1_yenping');
defined('DB_PASS')    || define('DB_PASS', 'R.zb0ZwEuGZ}*fW2');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

// ---- Application ------------------------------------------------------------
defined('APP_NAME')             || define('APP_NAME', 'PlateScan');
defined('APP_TIMEZONE')         || define('APP_TIMEZONE', 'UTC');
defined('REGISTRATION_FEE_USD') || define('REGISTRATION_FEE_USD', 50.00);
defined('PER_PAGE')             || define('PER_PAGE', 10);

// Client IP detection for rate limiting. Leave empty unless you are behind a trusted
// proxy, e.g. 'HTTP_CF_CONNECTING_IP' for Cloudflare. Never set it otherwise: it can be spoofed.
defined('TRUST_PROXY_HEADER')   || define('TRUST_PROXY_HEADER', '');

// ---- Rate limiting -----------------------------------------------------------
defined('RATE_LIMIT_MAX')    || define('RATE_LIMIT_MAX', 30);    // requests ...
defined('RATE_LIMIT_WINDOW') || define('RATE_LIMIT_WINDOW', 60); // ... per this many seconds, per IP, per endpoint

// ---- Exchange rates ----------------------------------------------------------
defined('RATES_TTL')          || define('RATES_TTL', 3600);        // cache lifetime: 1 hour
defined('RATES_RETRY_AFTER')  || define('RATES_RETRY_AFTER', 300); // pause between API attempts while both are down
defined('RATES_PRIMARY_URL')  || define('RATES_PRIMARY_URL', 'https://open.er-api.com/v6/latest/USD');
defined('RATES_FALLBACK_URL') || define('RATES_FALLBACK_URL', 'https://api.frankfurter.dev/v1/latest?base=USD');

// Currencies offered to users. Each one is supported by BOTH APIs above,
// so the fallback can always price every option.
defined('CURRENCIES') || define('CURRENCIES', [
    'USD' => 'US Dollar',
    'MYR' => 'Malaysian Ringgit',
    'SGD' => 'Singapore Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'JPY' => 'Japanese Yen',
    'CNY' => 'Chinese Yuan',
    'HKD' => 'Hong Kong Dollar',
    'AUD' => 'Australian Dollar',
    'NZD' => 'New Zealand Dollar',
    'CAD' => 'Canadian Dollar',
    'CHF' => 'Swiss Franc',
    'IDR' => 'Indonesian Rupiah',
    'THB' => 'Thai Baht',
    'PHP' => 'Philippine Peso',
    'INR' => 'Indian Rupee',
    'KRW' => 'South Korean Won',
]);

defined('BODY_TYPES') || define('BODY_TYPES', ['Sedan', 'SUV', 'Hatchback', 'Pickup', 'Van', 'Motorcycle', 'Lorry']);

// ---- Plate scanner (OCR) -----------------------------------------------------
// Comma-separated list of engines, tried in order:
//   tesseract        local `tesseract` binary via exec()   (free; rarely available on shared hosting)
//   ocrspace         ocr.space API                          (free key, 1 MB image limit)
//   platerecognizer  platerecognizer.com API                (most accurate for plates, needs a token)
//   browser          skip server OCR; the visitor's browser runs Tesseract.js
// If every server engine fails, the browser automatically falls back to Tesseract.js.
defined('OCR_PROVIDER')             || define('OCR_PROVIDER', 'tesseract,ocrspace');
defined('OCR_SPACE_URL')            || define('OCR_SPACE_URL', 'https://api.ocr.space/parse/image');
defined('PLATE_RECOGNIZER_URL')     || define('PLATE_RECOGNIZER_URL', 'https://api.platerecognizer.com/v1/plate-reader/');
defined('OCR_SPACE_KEY')            || define('OCR_SPACE_KEY', 'helloworld');  // shared demo key: get your own free key at ocr.space/ocrapi
defined('PLATE_RECOGNIZER_TOKEN')   || define('PLATE_RECOGNIZER_TOKEN', '');
defined('PLATE_RECOGNIZER_REGIONS') || define('PLATE_RECOGNIZER_REGIONS', 'my,sg,id,th'); // country codes, '' = all
defined('TESSERACT_PATH')           || define('TESSERACT_PATH', 'tesseract');
defined('OCR_MIN_CONFIDENCE')       || define('OCR_MIN_CONFIDENCE', 0.30);
defined('MAX_UPLOAD_BYTES')         || define('MAX_UPLOAD_BYTES', 8 * 1024 * 1024);

// ---- Errors: never shown to visitors, logged privately -----------------------
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
if (is_writable(__DIR__)) {
    ini_set('error_log', __DIR__ . '/error.log'); // blocked from the web by .htaccess
}
error_reporting(E_ALL);
date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');
