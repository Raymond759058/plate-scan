<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

render_header('index', 'page.index', 'Scan & Register', true);
?>
<main class="wrap">
  <div class="page-head">
    <h1 data-i18n="hero.title">Scan a plate, register the vehicle</h1>
    <p class="muted" data-i18n="hero.sub">Point the camera at a plate or upload a photo. We read the plate; you confirm the vehicle details.</p>
  </div>

  <div class="layout">
    <!-- ============================ Scanner ============================ -->
    <section class="card scanner" aria-labelledby="scan-h">
      <div class="card-head">
        <h2 id="scan-h" data-i18n="scan.title">Plate scanner</h2>
      </div>

      <div class="tabs" role="tablist" aria-label="Scan source">
        <button type="button" role="tab" id="tab-camera" aria-selected="true" aria-controls="viewport" data-mode="camera" data-i18n="tab.camera">Camera</button>
        <button type="button" role="tab" id="tab-upload" aria-selected="false" aria-controls="viewport" data-mode="upload" tabindex="-1" data-i18n="tab.upload">Upload photo</button>
      </div>

      <div class="viewport" id="viewport" role="tabpanel" data-mode="camera" data-view="idle">
        <video id="video" playsinline muted autoplay></video>
        <canvas id="preview" aria-label="Captured image"></canvas>
        <div class="guide" id="guide" aria-hidden="true"><i></i><i></i><i></i><i></i><span class="beam"></span></div>
        <div class="placeholder" id="cam-idle">
          <p id="cam-status" role="status" data-i18n="cam.idle">Camera is off. Start the camera to scan a plate.</p>
        </div>
        <label class="dropzone" id="dropzone" for="file-input">
          <input type="file" id="file-input" accept="image/jpeg,image/png,image/webp">
          <strong data-i18n="upload.drop">Drop a plate photo here or click to browse</strong>
          <span data-i18n="upload.hint">JPG, PNG or WebP, up to 8 MB</span>
        </label>
        <div class="scrim" id="scrim" hidden><span class="spinner" aria-hidden="true"></span><span id="scrim-text" role="status" data-i18n="scan.scanning">Reading plate…</span></div>
      </div>

      <p class="hint" id="cam-hint" data-i18n="cam.hint">Fit the plate inside the frame, keep the camera steady and avoid glare.</p>

      <div class="actions" id="scan-actions">
        <button type="button" class="btn primary" id="btn-start" data-i18n="cam.start">Start camera</button>
        <button type="button" class="btn primary" id="btn-capture" hidden data-i18n="cam.capture">Capture &amp; scan</button>
        <button type="button" class="btn" id="btn-switch" hidden data-i18n="cam.switch">Switch camera</button>
        <button type="button" class="btn ghost" id="btn-stop" hidden data-i18n="cam.stop">Stop camera</button>
        <button type="button" class="btn" id="btn-retake" hidden data-i18n="scan.retake">Scan another</button>
      </div>

      <div class="alert" id="scan-alert" role="alert" hidden></div>

      <div class="result" id="result" hidden aria-live="polite">
        <div class="plate" aria-label="Scanned plate"><span id="result-plate">—</span></div>
        <div class="result-meta">
          <div class="conf">
            <span class="conf-label" data-i18n="scan.confidence">Confidence</span>
            <span class="conf-track"><span class="conf-fill" id="conf-fill"></span></span>
            <strong id="conf-text">0%</strong>
          </div>
          <p class="muted small" id="result-note"></p>
        </div>
      </div>
    </section>

    <!-- ============================ Registration ============================ -->
    <section class="card" aria-labelledby="reg-h">
      <div class="card-head">
        <h2 id="reg-h" data-i18n="reg.title">Vehicle registration</h2>
        <p class="muted small" data-i18n="reg.subtitle">Enter the vehicle details yourself so the record is exact. The plate is filled in by the scanner but you can edit it.</p>
      </div>

      <form id="reg-form" autocomplete="off">
        <div class="grid">
          <div class="field span-2">
            <label for="plate_number" data-i18n="f.plate">License plate number</label>
            <input class="plate-input" type="text" id="plate_number" name="plate_number" required maxlength="14" pattern="[A-Za-z0-9 \-]{2,14}" inputmode="text" autocapitalize="characters" spellcheck="false" data-i18n-placeholder="f.plate.ph" placeholder="e.g. WXY 1234">
            <small class="field-error" data-err-for="plate_number"></small>
          </div>
          <div class="field span-2">
            <label for="owner_name" data-i18n="f.owner">Owner full name</label>
            <input type="text" id="owner_name" name="owner_name" required minlength="2" maxlength="100" autocomplete="name">
            <small class="field-error" data-err-for="owner_name"></small>
          </div>
          <div class="field span-2">
            <label for="phone" data-i18n="f.phone">Phone number</label>
            <input type="tel" id="phone" name="phone" required maxlength="20" pattern="\+?[0-9][0-9 \-]{5,18}[0-9]" inputmode="tel" data-i18n-placeholder="f.phone.ph" placeholder="+60 12-345 6789" autocomplete="tel">
            <small class="field-error" data-err-for="phone"></small>
          </div>
          <div class="field">
            <label for="make" data-i18n="f.make">Make / brand</label>
            <input type="text" id="make" name="make" required maxlength="50" placeholder="Toyota, Honda, Proton">
            <small class="field-error" data-err-for="make"></small>
          </div>
          <div class="field">
            <label for="model" data-i18n="f.model">Model</label>
            <input type="text" id="model" name="model" required maxlength="50" placeholder="Corolla, Civic, Saga">
            <small class="field-error" data-err-for="model"></small>
          </div>
          <div class="field">
            <label for="color" data-i18n="f.color">Colour</label>
            <input type="text" id="color" name="color" required maxlength="30">
            <small class="field-error" data-err-for="color"></small>
          </div>
          <div class="field">
            <label for="body_type" data-i18n="f.body">Body type</label>
            <select id="body_type" name="body_type" required>
              <option value="" data-i18n="f.body.select">Select type</option>
<?php foreach (BODY_TYPES as $type): ?>
              <option value="<?= e($type) ?>" data-i18n="type.<?= e($type) ?>"><?= e($type) ?></option>
<?php endforeach; ?>
            </select>
            <small class="field-error" data-err-for="body_type"></small>
          </div>
          <div class="field span-2">
            <label for="currency" data-i18n="f.currency">Payment currency</label>
            <select id="currency" name="currency" required>
<?php foreach (CURRENCIES as $code => $name): ?>
              <option value="<?= e($code) ?>" data-i18n="cur.<?= e($code) ?>"><?= e($code . ' - ' . $name) ?></option>
<?php endforeach; ?>
            </select>
            <small class="field-error" data-err-for="currency"></small>
          </div>
        </div>

        <div class="fee" id="fee" aria-live="polite" data-base="<?= e((string) REGISTRATION_FEE_USD) ?>">
          <div class="fee-row"><span data-i18n="fee.base">Registration fee (USD)</span><span id="fee-base">—</span></div>
          <div class="fee-row"><span data-i18n="fee.rate">Exchange rate</span><span id="fee-rate">—</span></div>
          <div class="fee-total"><span data-i18n="fee.total">You pay</span><strong id="fee-total">—</strong></div>
          <p class="muted small" id="fee-source"></p>
        </div>

        <div class="alert" id="reg-alert" role="alert" hidden></div>

        <div class="actions">
          <button type="submit" class="btn primary wide" id="btn-submit" data-i18n="reg.submit">Register vehicle</button>
          <button type="reset" class="btn ghost" id="btn-reset" data-i18n="reg.reset">Clear form</button>
        </div>
      </form>
    </section>
  </div>
</main>
<?php render_footer();
