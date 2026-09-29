<?php
declare(strict_types=1);

/**
 * POST api/scan.php   (multipart form + X-CSRF-Token header)
 *
 *   image        the captured camera frame or uploaded photo (JPEG / PNG / WebP)
 *   client_words optional JSON list of words read by the in-browser OCR fallback
 *                (used instead of `image` when server OCR is unavailable)
 *
 * Success: {"status":"success","data":{"plate_number":"ABC1234","confidence":0.98,"box":{...},"engine":"tesseract"}}
 * Failure: {"status":"error","error":{"code":"PLATE_UNREADABLE|NO_PLATE_DETECTED|OCR_UNAVAILABLE|...","message":"..."}}
 */
require_once __DIR__ . '/../includes/helpers.php';

api_bootstrap('scan', 'POST', true);

class OcrUnavailable extends RuntimeException
{
}

/* -----------------------------------------------------------------------------
 * Token = one word found by OCR:
 *   ['text' => 'ABC', 'x' => 0.1, 'y' => 0.4, 'w' => 0.2, 'h' => 0.1, 'conf' => 0.93|null]
 * x / y / w / h are fractions (0..1) of the image, so boxes work at any display size.
 * -------------------------------------------------------------------------- */

function alnum(string $s): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($s)) ?? '';
}

function make_token(string $text, float $x, float $y, float $w, float $h, int $imgW, int $imgH, ?float $conf): array
{
    $imgW = max(1, $imgW);
    $imgH = max(1, $imgH);
    return [
        'text' => $text,
        'x' => min(1.0, max(0.0, $x / $imgW)),
        'y' => min(1.0, max(0.0, $y / $imgH)),
        'w' => min(1.0, max(0.0, $w / $imgW)),
        'h' => min(1.0, max(0.0, $h / $imgH)),
        'conf' => $conf === null ? null : min(1.0, max(0.0, $conf)),
    ];
}

/** Two tokens belong to the same text line if they overlap vertically and sit close together. */
function same_line(array $a, array $b): bool
{
    if ($a['h'] <= 0 || $b['h'] <= 0) {
        return true;    // engine gave no geometry: rely on reading order
    }
    $ay = $a['y'] + $a['h'] / 2;
    $by = $b['y'] + $b['h'] / 2;
    $maxH = max($a['h'], $b['h']);
    $gap = $b['x'] - ($a['x'] + $a['w']);
    return abs($ay - $by) < 0.6 * $maxH && $gap < 2.5 * $maxH && $gap > -$maxH;
}

function score_candidate(string $s, float $height, bool $hasLetters): float
{
    $len = strlen($s);
    $score = 0.0;
    $score += $hasLetters ? 3.0 : 0.5;                                   // real plates mix letters and digits
    if (preg_match('/^[A-Z]{1,3}[0-9]{1,4}[A-Z]?$/', $s)) {
        $score += 2.0;                                                    // e.g. ABC1234, W1234A, SBS1234A
    }
    $score += ($len >= 5 && $len <= 8) ? 1.5 : (($len === 4 || $len === 9) ? 0.5 : -1.5);
    $score += min(3.0, $height * 20);                                     // big text is more likely the plate
    return $score;
}

/**
 * Choose the most plate-like text from a list of tokens.
 * @return array{plate:string, confidence:float, box:?array, tokens_used:int}|null
 */
function pick_plate(array $tokens, bool $trusted = false): ?array
{
    $clean = [];
    foreach ($tokens as $t) {
        $a = alnum((string) $t['text']);
        if ($a !== '') {
            $clean[] = ['a' => $a] + $t;
        }
    }
    $best = null;
    $bestScore = -INF;

    foreach ($clean as $i => $_) {
        for ($len = 1; $len <= 3 && $i + $len <= count($clean); $len++) {
            $seq = array_slice($clean, $i, $len);
            $ok = true;
            for ($k = 1; $k < $len; $k++) {
                if (!same_line($seq[$k - 1], $seq[$k])) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                break;
            }
            $text = implode('', array_column($seq, 'a'));
            if (!valid_plate($text)) {
                continue;
            }
            $hasDigit = (bool) preg_match('/[0-9]/', $text);
            $hasLetter = (bool) preg_match('/[A-Z]/', $text);
            if (!$hasDigit && !$trusted) {
                continue;   // brand badges like "TOYOTA" are not plates
            }
            $height = max(array_column($seq, 'h'));
            $score = $trusted
                ? (float) ($seq[0]['conf'] ?? 0)
                : score_candidate($text, $height, $hasLetter && $hasDigit);
            if (!$trusted && $score < 2.5) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['text' => $text, 'seq' => $seq];
            }
        }
    }
    if ($best === null) {
        return null;
    }

    // Union box of the words that make up the plate.
    $x1 = $y1 = 1.0;
    $x2 = $y2 = 0.0;
    $confs = [];
    foreach ($best['seq'] as $t) {
        $x1 = min($x1, $t['x']);
        $y1 = min($y1, $t['y']);
        $x2 = max($x2, $t['x'] + $t['w']);
        $y2 = max($y2, $t['y'] + $t['h']);
        if ($t['conf'] !== null) {
            $confs[] = $t['conf'];
        }
    }
    $box = ($x2 > $x1 && $y2 > $y1) ? ['x' => round($x1, 4), 'y' => round($y1, 4), 'w' => round($x2 - $x1, 4), 'h' => round($y2 - $y1, 4)] : null;

    $strict = (bool) preg_match('/^[A-Z]{1,3}[0-9]{1,4}[A-Z]?$/', $best['text']);
    $len = strlen($best['text']);
    if ($confs) {
        $confidence = array_sum($confs) / count($confs);
        if (!$trusted && !$strict) {
            $confidence -= 0.10;
        }
    } else {
        // The engine gives no per-word confidence (ocr.space): estimate it from how plate-like the text is.
        $confidence = 0.55 + ($strict ? 0.25 : 0.0) + ($len >= 5 && $len <= 8 ? 0.10 : 0.0);
    }

    return [
        'plate'       => $best['text'],
        'confidence'  => round(min(0.99, max(0.0, $confidence)), 2),
        'box'         => $box,
        'tokens_used' => count($best['seq']),
    ];
}

/* -----------------------------------------------------------------------------
 * Image preparation
 * -------------------------------------------------------------------------- */

/** @return array{0:string,1:int,2:int} [jpeg bytes, width, height] */
function prepare_image(string $bytes): array
{
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        json_fail('INVALID_IMAGE', 'That file is not a valid JPG, PNG or WebP image.', 415);
    }
    [$w, $h] = $info;
    if ($w < 40 || $h < 20) {
        json_fail('INVALID_IMAGE', 'The image is too small to read. Use a larger photo.', 422);
    }
    if ($w * $h > 40_000_000) {
        json_fail('IMAGE_TOO_LARGE', 'The image resolution is too large. Please use a smaller photo.', 413);
    }
    if (!function_exists('imagecreatefromstring')) {
        // No GD: pass JPEGs through as they are (the browser already downsizes before uploading).
        if ($info[2] !== IMAGETYPE_JPEG) {
            json_fail('INVALID_IMAGE', 'Please upload a JPG image.', 415);
        }
        return [$bytes, $w, $h];
    }

    $img = @imagecreatefromstring($bytes);
    if ($img === false) {
        json_fail('INVALID_IMAGE', 'That file is not a valid JPG, PNG or WebP image.', 415);
    }
    $max = 1400;
    if (max($w, $h) > $max) {
        $scale = $max / max($w, $h);
        $small = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
        if ($small !== false) {
            $img = $small;
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);

    foreach ([82, 70, 55] as $quality) {        // keep under 1 MB (the ocr.space free limit)
        ob_start();
        imagejpeg($img, null, $quality);
        $jpeg = (string) ob_get_clean();
        if (strlen($jpeg) < 900_000) {
            break;
        }
    }
    return [$jpeg, $w, $h];
}

/* -----------------------------------------------------------------------------
 * OCR engines. Each returns a list of tokens, or throws OcrUnavailable.
 * -------------------------------------------------------------------------- */

function ocr_tesseract(string $jpeg, int $w, int $h): array
{
    if (!function_exists('exec')) {
        throw new OcrUnavailable('exec() is disabled');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ocr');
    if ($tmp === false || file_put_contents($tmp, $jpeg) === false) {
        throw new OcrUnavailable('cannot write temp file');
    }
    // Several page-segmentation modes: which one finds a plate depends on the scene
    // (6 = uniform block, 11 = sparse text, 7 = single line). Keep the first pass that yields a plate.
    // No character whitelist: it makes Tesseract 5 report a confidence of 0.
    $fallback = [];
    try {
        foreach ([6, 11, 7] as $psm) {
            $lines = [];
            $code = 0;
            exec(escapeshellarg(TESSERACT_PATH) . ' ' . escapeshellarg($tmp) . " stdout --psm $psm tsv 2>/dev/null", $lines, $code);
            if ($code !== 0) {
                throw new OcrUnavailable('tesseract not available (exit ' . $code . ')');
            }
            $tokens = [];
            foreach ($lines as $line) {
                $c = explode("\t", $line);
                // level page block par line word left top width height conf text
                if (count($c) < 12 || $c[0] !== '5') {
                    continue;
                }
                $text = trim($c[11]);
                if ($text === '' || (float) $c[10] < 0) {
                    continue;
                }
                $tokens[] = make_token($text, (float) $c[6], (float) $c[7], (float) $c[8], (float) $c[9], $w, $h, (float) $c[10] / 100);
            }
            $plate = pick_plate($tokens);
            if ($plate !== null && $plate['confidence'] >= OCR_MIN_CONFIDENCE) {
                return $tokens;
            }
            if (!$fallback && $tokens) {
                $fallback = $tokens;
            }
        }
    } finally {
        @unlink($tmp);
    }
    return $fallback;
}

function ocr_ocrspace(string $jpeg, int $w, int $h): array
{
    if (OCR_SPACE_KEY === '') {
        throw new OcrUnavailable('OCR.space key missing');
    }
    $r = http_request(OCR_SPACE_URL, [
        'apikey'             => OCR_SPACE_KEY,
        'base64Image'        => 'data:image/jpeg;base64,' . base64_encode($jpeg),
        'language'           => 'eng',
        'isOverlayRequired'  => 'true',
        'OCREngine'          => '2',
        'scale'              => 'true',
        'detectOrientation'  => 'true',
    ], [], 20);
    $j = $r['json'] ?? null;
    if (!$r || $r['code'] !== 200 || !$j || !empty($j['IsErroredOnProcessing'])) {
        $msg = is_array($j['ErrorMessage'] ?? null) ? implode('; ', $j['ErrorMessage']) : (string) ($j['ErrorMessage'] ?? 'no response');
        throw new OcrUnavailable('OCR.space failed: ' . $msg);
    }
    $tokens = [];
    foreach (($j['ParsedResults'][0]['TextOverlay']['Lines'] ?? []) as $line) {
        foreach (($line['Words'] ?? []) as $word) {
            $text = trim((string) ($word['WordText'] ?? ''));
            if ($text !== '') {
                $tokens[] = make_token($text, (float) ($word['Left'] ?? 0), (float) ($word['Top'] ?? 0),
                    (float) ($word['Width'] ?? 0), (float) ($word['Height'] ?? 0), $w, $h, null);
            }
        }
    }
    if (!$tokens) {   // overlay missing: fall back to the plain text (no geometry)
        foreach (preg_split('/\s+/', trim((string) ($j['ParsedResults'][0]['ParsedText'] ?? ''))) ?: [] as $word) {
            if ($word !== '') {
                $tokens[] = ['text' => $word, 'x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0, 'conf' => null];
            }
        }
    }
    return $tokens;
}

function ocr_platerecognizer(string $jpeg, int $w, int $h): array
{
    if (PLATE_RECOGNIZER_TOKEN === '') {
        throw new OcrUnavailable('Plate Recognizer token missing');
    }
    $fields = ['upload' => base64_encode($jpeg)];
    if (PLATE_RECOGNIZER_REGIONS !== '') {
        $fields['regions'] = PLATE_RECOGNIZER_REGIONS;
    }
    $r = http_request(PLATE_RECOGNIZER_URL, $fields,
        ['Authorization: Token ' . PLATE_RECOGNIZER_TOKEN], 20);
    $j = $r['json'] ?? null;
    if (!$r || !in_array($r['code'], [200, 201], true) || !$j || !isset($j['results'])) {
        throw new OcrUnavailable('Plate Recognizer failed (HTTP ' . ($r['code'] ?? 0) . ')');
    }
    $tokens = [];
    foreach ($j['results'] as $res) {
        $b = $res['box'] ?? [];
        $tokens[] = make_token((string) ($res['plate'] ?? ''), (float) ($b['xmin'] ?? 0), (float) ($b['ymin'] ?? 0),
            (float) (($b['xmax'] ?? 0) - ($b['xmin'] ?? 0)), (float) (($b['ymax'] ?? 0) - ($b['ymin'] ?? 0)), $w, $h,
            isset($res['score']) ? (float) $res['score'] : null);
    }
    return $tokens;
}

/** Validate the words sent by the browser-side OCR fallback. */
function parse_client_words(string $json): array
{
    $list = json_decode(mb_substr($json, 0, 60000), true);
    if (!is_array($list)) {
        json_fail('INVALID_JSON', 'The OCR data could not be read.', 400);
    }
    $tokens = [];
    foreach (array_slice($list, 0, 300) as $w) {
        if (!is_array($w) || !isset($w['text']) || !is_string($w['text'])) {
            continue;
        }
        $num = static fn ($k) => isset($w[$k]) && is_numeric($w[$k]) ? min(1.0, max(0.0, (float) $w[$k])) : 0.0;
        $tokens[] = [
            'text' => mb_substr($w['text'], 0, 40),
            'x' => $num('x'), 'y' => $num('y'), 'w' => $num('w'), 'h' => $num('h'),
            'conf' => isset($w['conf']) && is_numeric($w['conf']) ? $num('conf') : null,
        ];
    }
    return $tokens;
}

/* -----------------------------------------------------------------------------
 * Response
 * -------------------------------------------------------------------------- */

const MSG_UNREADABLE = 'Unable to read license plate clearly. Please adjust lighting or reposition the camera.';
const MSG_NO_PLATE = 'No license plate detected. Fill the guide frame with the plate, reduce glare and hold the camera steady.';

function finish(array $tokens, string $engine, bool $trusted = false): never
{
    if (!$tokens) {
        json_fail('NO_PLATE_DETECTED', MSG_NO_PLATE, 422);
    }
    $plate = pick_plate($tokens, $trusted);
    if ($plate === null || $plate['confidence'] < OCR_MIN_CONFIDENCE) {
        json_fail('PLATE_UNREADABLE', MSG_UNREADABLE, 422);
    }
    json_ok([
        'plate_number' => $plate['plate'],
        'confidence'   => $plate['confidence'],
        'box'          => $plate['box'],
        'engine'       => $engine,
    ]);
}

// --- A) words already read by the browser -------------------------------------
if (isset($_POST['client_words']) && is_string($_POST['client_words'])) {
    finish(parse_client_words($_POST['client_words']), 'browser');
}

// --- B) an image to read on the server ----------------------------------------
$file = $_FILES['image'] ?? null;
if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    json_fail('NO_IMAGE', 'No image received. Capture a frame or choose a photo first.', 400);
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    $tooBig = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    json_fail($tooBig ? 'IMAGE_TOO_LARGE' : 'NO_IMAGE', $tooBig ? 'The image is too large.' : 'The upload failed. Please try again.', $tooBig ? 413 : 400);
}
if ($file['size'] > MAX_UPLOAD_BYTES) {
    json_fail('IMAGE_TOO_LARGE', 'The image is too large (maximum ' . (int) (MAX_UPLOAD_BYTES / 1048576) . ' MB).', 413);
}
$bytes = (string) file_get_contents($file['tmp_name']);
[$jpeg, $imgW, $imgH] = prepare_image($bytes);

$engines = array_values(array_filter(array_map('trim', explode(',', strtolower(OCR_PROVIDER)))));
$sawText = false;
$anyEngineRan = false;

foreach ($engines as $engine) {
    try {
        $tokens = match ($engine) {
            'tesseract'       => ocr_tesseract($jpeg, $imgW, $imgH),
            'ocrspace'        => ocr_ocrspace($jpeg, $imgW, $imgH),
            'platerecognizer' => ocr_platerecognizer($jpeg, $imgW, $imgH),
            default           => throw new OcrUnavailable('browser OCR mode'),
        };
    } catch (OcrUnavailable $e) {
        if ($engine !== 'browser') {
            error_log("OCR engine '$engine' unavailable: " . $e->getMessage());
        }
        continue;
    }
    $anyEngineRan = true;
    if (!$tokens) {
        continue;
    }
    $sawText = true;
    $trusted = $engine === 'platerecognizer';
    $plate = pick_plate($tokens, $trusted);
    if ($plate !== null && $plate['confidence'] >= OCR_MIN_CONFIDENCE) {
        json_ok([
            'plate_number' => $plate['plate'],
            'confidence'   => $plate['confidence'],
            'box'          => $plate['box'],
            'engine'       => $engine,
        ]);
    }
}

if (!$anyEngineRan) {
    // Nothing on the server could read images: tell the browser to use Tesseract.js instead.
    json_fail('OCR_UNAVAILABLE', 'The scanner service is busy, so we are switching to in-browser scanning.', 503, ['fallback' => 'browser']);
}
$sawText
    ? json_fail('PLATE_UNREADABLE', MSG_UNREADABLE, 422)
    : json_fail('NO_PLATE_DETECTED', MSG_NO_PLATE, 422);
