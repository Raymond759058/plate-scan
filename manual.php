<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

render_header('manual', 'page.manual', 'Manual & API');

$errors = [
    ['PLATE_UNREADABLE', 422], ['NO_PLATE_DETECTED', 422], ['OCR_UNAVAILABLE', 503],
    ['INVALID_IMAGE', 415], ['IMAGE_TOO_LARGE', 413], ['NO_IMAGE', 400],
    ['VALIDATION_FAILED', 422], ['PLATE_EXISTS', 409], ['RATES_UNAVAILABLE', 503],
    ['CSRF_INVALID', 403], ['RATE_LIMITED', 429], ['METHOD_NOT_ALLOWED', 405],
    ['INVALID_JSON', 400], ['SERVER_ERROR', 500],
];
?>
<main class="wrap">
  <div class="page-head">
    <h1 data-i18n="man.title">Manual &amp; API reference</h1>
    <p class="muted" data-i18n="man.intro">How to scan and register a vehicle, and how the JSON API works.</p>
  </div>

  <div class="card prose">

    <h2 data-i18n="man.h.start">Quick start</h2>
    <ol>
      <li data-i18n="man.s1">Open Scan &amp; Register and choose Camera or Upload photo.</li>
      <li data-i18n="man.s2">Camera: press Start camera, allow access when the browser asks, fit the plate inside the yellow frame and press Capture &amp; scan.</li>
      <li data-i18n="man.s3">Upload: drop a photo of the plate on the drop area, or click it to choose a file. Scanning starts by itself.</li>
      <li data-i18n="man.s4">Check the scanned plate and its confidence. The plate is filled into the form; edit it if any character is wrong.</li>
      <li data-i18n="man.s5">Enter the owner, phone, make, model, colour and body type, choose your currency and press Register vehicle.</li>
    </ol>

    <h2 data-i18n="man.h.tips">Getting a good scan</h2>
    <ul>
      <li data-i18n="man.t1">Fill the yellow frame with the plate. Move closer rather than zooming into a blurry image.</li>
      <li data-i18n="man.t2">Avoid glare and shadows across the plate. Tilt the camera slightly if the plate reflects a light.</li>
      <li data-i18n="man.t3">Hold the camera steady for a moment before pressing Capture &amp; scan.</li>
      <li data-i18n="man.t4">If a scan fails twice, type the plate manually. The field is always editable.</li>
    </ul>

    <h2 data-i18n="man.h.fees">Fees and currencies</h2>
    <p data-i18n="man.fees.p">The registration fee is fixed in US dollars. When you pick another currency, the amount is converted with live exchange rates. Rates are saved on the server and reused for one hour; if the rate services are down, the latest saved rates are used. The server recalculates the fee when you register, so the amount stored is always correct.</p>

    <h2 data-i18n="man.h.search">Searching the registry</h2>
    <p data-i18n="man.search.p">On the Registry page, type a plate, owner name, make or model. Combine the search with a body type or a registration date range. Results are paginated; each row shows the fee in US dollars and in the currency the owner paid in.</p>

    <h2 data-i18n="man.h.privacy">Privacy</h2>
    <p data-i18n="man.privacy.p">Phone numbers are stored in full but shown masked in the public registry.</p>

    <h2 data-i18n="man.h.api">API reference</h2>
    <p data-i18n="man.api.intro">All endpoints live under /api/ and always answer with JSON.</p>
    <p data-i18n="man.api.format">Success responses look like { "status": "success", "data": { ... } }. Errors look like { "status": "error", "error": { "code": "...", "message": "..." } }.</p>
    <p data-i18n="man.api.csrf">POST requests must send the CSRF token from the page's csrf-token meta tag in the X-CSRF-Token header, with the same session cookie.</p>
    <p data-i18n="man.api.limit">Each IP address may make 30 requests per minute to each endpoint. Beyond that the API answers 429 with a retry_after value.</p>

    <div class="doc-card">
      <div class="endpoint"><span class="verb post">POST</span><code>/api/scan.php</code></div>
      <p class="small muted" data-i18n="man.api.scan.d">Reads a plate from an image. Send the image as multipart/form-data in the field "image" (JPG, PNG or WebP, up to 8 MB). The box is relative to the image (0 to 1).</p>
<pre>curl -X POST https://yoursite.tld/api/scan.php \
  -H "X-CSRF-Token: &lt;token&gt;" -b cookies.txt \
  -F "image=@plate.jpg"

{
  "status": "success",
  "data": {
    "plate_number": "ABC1234",
    "confidence": 0.98,
    "box": { "x": 0.31, "y": 0.62, "w": 0.38, "h": 0.09 },
    "engine": "tesseract"
  }
}</pre>
    </div>

    <div class="doc-card">
      <div class="endpoint"><span class="verb post">POST</span><code>/api/register.php</code></div>
      <p class="small muted" data-i18n="man.api.register.d">Validates and stores a registration. Send a JSON body. The fee is calculated on the server.</p>
<pre>curl -X POST https://yoursite.tld/api/register.php \
  -H "X-CSRF-Token: &lt;token&gt;" -H "Content-Type: application/json" -b cookies.txt \
  -d '{
    "plate_number": "ABC1234",
    "owner_name": "Ahmad bin Ali",
    "phone": "+60 12-345 6789",
    "make": "Proton",
    "model": "Saga",
    "color": "Silver",
    "body_type": "Sedan",
    "currency": "MYR"
  }'

{
  "status": "success",
  "data": {
    "id": 42,
    "plate_number": "ABC1234",
    "base_fee": 50.00,
    "currency": "MYR",
    "fee_converted": 210.00,
    "rate": 4.2,
    "rate_source": "cache"
  }
}</pre>
    </div>

    <div class="doc-card">
      <div class="endpoint"><span class="verb get">GET</span><code>/api/rates.php</code></div>
      <p class="small muted" data-i18n="man.api.rates.d">Returns USD-based exchange rates from the MySQL cache, refreshing from open.er-api.com and then api.frankfurter.dev when the cache is older than one hour.</p>
<pre>GET /api/rates.php

{
  "status": "success",
  "data": {
    "base": "USD",
    "base_fee": 50,
    "rates": { "MYR": 4.2, "SGD": 1.3, "...": "..." },
    "source": "cache",
    "provider": "open.er-api.com",
    "fetched_at": 1735000000,
    "expires_at": 1735003600
  }
}</pre>
    </div>

    <div class="doc-card">
      <div class="endpoint"><span class="verb get">GET</span><code>/api/vehicles.php</code></div>
      <p class="small muted" data-i18n="man.api.vehicles.d">Searches and paginates registered vehicles. All parameters are optional: q (plate, owner, make or model), type, date_from, date_to (YYYY-MM-DD) and page.</p>
<pre>GET /api/vehicles.php?q=ABC1234&amp;type=Sedan&amp;page=1

{
  "status": "success",
  "data": {
    "items": [ { "id": 42, "plate_number": "ABC1234", "owner_name": "Ahmad bin Ali",
                 "phone": "+60••••789", "make": "Proton", "model": "Saga", "color": "Silver",
                 "body_type": "Sedan", "base_fee": 50, "currency": "MYR",
                 "fee_converted": 210, "created_at": "2026-01-14 09:32:00" } ],
    "page": 1, "per_page": 10, "total": 1, "total_pages": 1
  }
}</pre>
    </div>

    <h2 data-i18n="man.h.errors">Error codes</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th data-i18n="man.th.code">Code</th><th data-i18n="man.th.http">HTTP</th><th data-i18n="man.th.meaning">Meaning</th></tr></thead>
        <tbody>
<?php foreach ($errors as [$code, $http]): ?>
          <tr>
            <td data-label="Code"><code><?= e($code) ?></code></td>
            <td data-label="HTTP" class="num"><?= (int) $http ?></td>
            <td data-label="Meaning" data-i18n="err.<?= e($code) ?>"><?= e($code) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</main>
<?php render_footer();
