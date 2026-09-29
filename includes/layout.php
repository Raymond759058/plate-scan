<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** URL for a static asset with a cache-busting version (file modification time). */
function asset(string $path): string
{
    $file = __DIR__ . '/../' . $path;
    return e($path . '?v=' . (is_file($file) ? filemtime($file) : '1'));
}

/**
 * Print the top of every page.
 * $page      index | vehicles | manual  (highlights the nav link, selects the JS module)
 * $titleKey  i18n key for the <title>
 * $title     English fallback title (used before JavaScript runs)
 */
function render_header(string $page, string $titleKey, string $title, bool $needsCsrf = false): void
{
    send_security_headers(true);
    header('Content-Type: text/html; charset=utf-8');
    $token = $needsCsrf ? csrf_token() : '';
    if ($needsCsrf) {
        session_write_close();
    }
    $nav = [
        'index'    => ['index.php', 'nav.scan', 'Scan & Register'],
        'vehicles' => ['vehicles.php', 'nav.vehicles', 'Registry'],
        'manual'   => ['manual.php', 'nav.manual', 'Manual & API'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0f141b">
<title><?= e($title . ' | ' . APP_NAME) ?></title>
<?php if ($needsCsrf): ?><meta name="csrf-token" content="<?= e($token) ?>">
<?php endif; ?>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%23ffc93c'/%3E%3Cpath d='M7 20V12h5a3 3 0 010 6H7m12-6h6m-6 4h6m-6 4h6' stroke='%2316181c' stroke-width='2.4' fill='none'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body data-page="<?= e($page) ?>" data-title-key="<?= e($titleKey) ?>" data-app="<?= e(APP_NAME) ?>">
<header class="topbar">
  <div class="topbar-in">
    <a class="brand" href="index.php" aria-label="<?= e(APP_NAME) ?>">
      <span class="brand-plate" aria-hidden="true">PS</span>
      <span class="brand-name"><?= e(APP_NAME) ?></span>
    </a>
    <nav class="nav" aria-label="Main">
<?php foreach ($nav as $key => [$href, $i18n, $label]): ?>
      <a href="<?= e($href) ?>"<?= $key === $page ? ' aria-current="page"' : '' ?> data-i18n="<?= e($i18n) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
    </nav>
    <div class="lang" role="group" aria-label="Language">
      <button type="button" data-lang="en" aria-pressed="true">EN</button>
      <button type="button" data-lang="zh" aria-pressed="false" lang="zh">中文</button>
    </div>
  </div>
</header>
<?php
}

function render_footer(): void
{
    ?>
<footer class="foot">
  <p data-i18n="foot.text">Vehicle registration with live exchange rates.</p>
</footer>
<script src="<?= asset('assets/js/i18n.js') ?>" defer></script>
<script src="<?= asset('assets/js/ocr-fallback.js') ?>" defer></script>
<script src="<?= asset('assets/js/app.js') ?>" defer></script>
</body>
</html>
<?php
}
