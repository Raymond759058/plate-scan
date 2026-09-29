/* PlateScan client app. Vanilla JS, no frameworks. */
(function () {
  'use strict';

  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var PAGE = document.body.getAttribute('data-page');
  var T = function (k, v) { return window.I18N ? window.I18N.t(k, v) : k; };

  /* ===========================================================================
   * API helper
   * ======================================================================== */

  /** @returns {Promise<{ok:boolean, status:number, body:object}>} never rejects on HTTP/API errors */
  function api(method, url, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    var init = { method: method, headers: headers, credentials: 'same-origin' };
    if (method !== 'GET') headers['X-CSRF-Token'] = CSRF;
    if (opts.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.json);
    } else if (opts.formData) {
      init.body = opts.formData; // browser sets multipart boundary
    }
    return fetch(url, init).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (body) {
        return { ok: res.ok, status: res.status, body: body };
      });
    }).catch(function () {
      return { ok: false, status: 0, body: { status: 'error', error: { code: 'NETWORK', message: T('err.NETWORK') } } };
    });
  }

  /** Human-readable message for a failed api() result, translated when we recognise the code. */
  function apiErrorMessage(result) {
    var err = result.body && result.body.error;
    var code = err && err.code;
    var extra = code === 'RATE_LIMITED' ? { s: err.retry_after || 30 } : {};
    if (code && (window.I18N.t('err.' + code) !== 'err.' + code)) return T('err.' + code, extra);
    return (err && err.message) || T('err.SERVER_ERROR');
  }

  function showAlert(el, message, kind) {
    if (!el) return;
    el.textContent = message;
    el.className = 'alert ' + (kind || 'err');
    el.hidden = !message;
  }

  /* ===========================================================================
   * Shared: field-level validation error rendering
   * ======================================================================== */

  function clearFieldErrors(form) {
    var i;
    var msgs = form.querySelectorAll('.field-error');
    for (i = 0; i < msgs.length; i++) msgs[i].textContent = '';
    var inputs = form.querySelectorAll('[aria-invalid]');
    for (i = 0; i < inputs.length; i++) inputs[i].removeAttribute('aria-invalid');
  }

  function showFieldErrors(form, fields) {
    Object.keys(fields || {}).forEach(function (name) {
      var input = form.elements[name];
      var msgEl = form.querySelector('[data-err-for="' + name + '"]');
      var key = 'val.' + name;
      var translated = window.I18N.t(key);
      var text = translated !== key ? translated : fields[name];
      if (input) input.setAttribute('aria-invalid', 'true');
      if (msgEl) msgEl.textContent = text;
    });
  }

  /* ===========================================================================
   * Index page: scanner + registration form
   * ======================================================================== */

  function initScanPage() {
    var viewport = document.getElementById('viewport');
    var video = document.getElementById('video');
    var canvas = document.getElementById('preview');
    var ctx = canvas.getContext('2d');
    var tabCamera = document.getElementById('tab-camera');
    var tabUpload = document.getElementById('tab-upload');
    var camStatus = document.getElementById('cam-status');
    var camHint = document.getElementById('cam-hint');
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('file-input');
    var scrim = document.getElementById('scrim');
    var scrimText = document.getElementById('scrim-text');
    var btnStart = document.getElementById('btn-start');
    var btnCapture = document.getElementById('btn-capture');
    var btnSwitch = document.getElementById('btn-switch');
    var btnStop = document.getElementById('btn-stop');
    var btnRetake = document.getElementById('btn-retake');
    var scanAlert = document.getElementById('scan-alert');
    var result = document.getElementById('result');
    var resultPlate = document.getElementById('result-plate');
    var resultNote = document.getElementById('result-note');
    var confFill = document.getElementById('conf-fill');
    var confText = document.getElementById('conf-text');

    var stream = null;
    var facing = 'environment';
    var lastImageBlob = null;       // for a possible browser-OCR retry
    var scanToken = 0;              // guards against a stale async result overwriting a newer one

    /* ---- tabs ---- */
    function setMode(mode) {
      stopCamera();
      viewport.setAttribute('data-mode', mode);
      viewport.setAttribute('data-view', 'idle');
      tabCamera.setAttribute('aria-selected', String(mode === 'camera'));
      tabCamera.tabIndex = mode === 'camera' ? 0 : -1;
      tabUpload.setAttribute('aria-selected', String(mode === 'upload'));
      tabUpload.tabIndex = mode === 'upload' ? 0 : -1;
      document.getElementById('scan-actions').hidden = mode !== 'camera';
      camHint.hidden = mode !== 'camera';
      hideResult();
    }
    tabCamera.addEventListener('click', function () { setMode('camera'); });
    tabUpload.addEventListener('click', function () { setMode('upload'); });
    [tabCamera, tabUpload].forEach(function (tab, i, arr) {
      tab.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        arr[i === 0 ? 1 : 0].focus();
        arr[i === 0 ? 1 : 0].click();
      });
    });

    /* ---- camera ---- */
    function cameraSupported() {
      return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    function startCamera() {
      if (!window.isSecureContext) { showAlert(scanAlert, T('cam.insecure'), 'warn'); return; }
      if (!cameraSupported()) { showAlert(scanAlert, T('cam.notfound'), 'warn'); return; }
      showAlert(scanAlert, '');
      camStatus.textContent = T('cam.starting');
      btnStart.disabled = true;
      navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 960 } },
        audio: false
      }).then(function (s) {
        stream = s;
        video.srcObject = stream;
        return video.play();
      }).then(function () {
        viewport.setAttribute('data-view', 'live');
        btnStart.hidden = true;
        btnCapture.hidden = false;
        btnStop.hidden = false;
        btnSwitch.hidden = !supportsFacingSwitch();
      }).catch(function (e) {
        var key = e && e.name === 'NotAllowedError' ? 'cam.denied'
          : e && e.name === 'NotFoundError' ? 'cam.notfound' : 'cam.error';
        showAlert(scanAlert, T(key), 'warn');
      }).finally(function () { btnStart.disabled = false; });
    }

    function supportsFacingSwitch() {
      // Best-effort: only offer "switch" when several video inputs are likely available.
      return !!(navigator.mediaDevices && navigator.mediaDevices.enumerateDevices);
    }

    function stopCamera() {
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
      video.srcObject = null;
      btnStart.hidden = false;
      btnCapture.hidden = true;
      btnStop.hidden = true;
      btnSwitch.hidden = true;
      if (viewport.getAttribute('data-mode') === 'camera') {
        viewport.setAttribute('data-view', 'idle');
        camStatus.textContent = T('cam.idle');
      }
    }

    btnStart.addEventListener('click', startCamera);
    btnStop.addEventListener('click', stopCamera);
    btnSwitch.addEventListener('click', function () {
      facing = facing === 'environment' ? 'user' : 'environment';
      stopCamera();
      startCamera();
    });
    btnRetake.addEventListener('click', function () {
      hideResult();
      if (viewport.getAttribute('data-mode') === 'camera') {
        viewport.setAttribute('data-view', stream ? 'live' : 'idle');
        btnCapture.hidden = !stream;
        if (!stream) startCamera();
      } else {
        viewport.setAttribute('data-view', 'idle');
      }
    });

    btnCapture.addEventListener('click', function () {
      var w = video.videoWidth || 1280;
      var h = video.videoHeight || 960;
      canvas.width = w;
      canvas.height = h;
      ctx.drawImage(video, 0, 0, w, h);
      viewport.setAttribute('data-view', 'preview');
      canvas.toBlob(function (blob) {
        if (!blob) { showAlert(scanAlert, T('upload.readfail'), 'err'); return; }
        runScan(blob, w, h);
      }, 'image/jpeg', 0.9);
    });

    /* ---- upload ---- */
    var ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
    var MAX_BYTES = 8 * 1024 * 1024;

    function handleFile(file) {
      if (!file) return;
      if (ACCEPTED.indexOf(file.type) === -1) { showAlert(scanAlert, T('upload.badtype'), 'err'); return; }
      if (file.size > MAX_BYTES) { showAlert(scanAlert, T('upload.toolarge'), 'err'); return; }
      showAlert(scanAlert, '');
      var img = new Image();
      var url = URL.createObjectURL(file);
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight;
        canvas.width = w;
        canvas.height = h;
        ctx.drawImage(img, 0, 0, w, h);
        viewport.setAttribute('data-view', 'preview');
        URL.revokeObjectURL(url);
        runScan(file, w, h);
      };
      img.onerror = function () { URL.revokeObjectURL(url); showAlert(scanAlert, T('upload.readfail'), 'err'); };
      img.src = url;
    }

    fileInput.addEventListener('change', function () { handleFile(fileInput.files && fileInput.files[0]); });
    ['dragenter', 'dragover'].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) { e.preventDefault(); dropzone.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) { e.preventDefault(); dropzone.classList.remove('drag'); });
    });
    dropzone.addEventListener('drop', function (e) {
      var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      handleFile(file);
    });

    /* ---- scanning ---- */
    function setBusy(busy, text) {
      scrim.hidden = !busy;
      scrimText.textContent = text || T('scan.scanning');
      viewport.classList.toggle('scanning', busy);
      btnCapture.disabled = busy;
    }

    function runScan(blob, w, h) {
      lastImageBlob = blob;
      var myToken = ++scanToken;
      hideResult();
      showAlert(scanAlert, '');
      setBusy(true, T('scan.scanning'));

      var fd = new FormData();
      fd.append('image', blob, 'frame.jpg');
      api('POST', 'api/scan.php', { formData: fd }).then(function (res) {
        if (myToken !== scanToken) return;
        if (res.ok && res.body && res.body.status === 'success') {
          setBusy(false);
          showScanResult(res.body.data, w, h);
          return;
        }
        var code = res.body && res.body.error && res.body.error.code;
        if (code === 'OCR_UNAVAILABLE' && window.PlateScanBrowserOCR) {
          setBusy(true, T('scan.browser.loading'));
          return window.PlateScanBrowserOCR.recognize(blob, function (msg) { if (myToken === scanToken) setBusy(true, msg); })
            .then(function (data) {
              if (myToken !== scanToken) return;
              setBusy(false);
              if (data) showScanResult(data, w, h);
              else showAlert(scanAlert, T('err.BROWSER_OCR_FAILED'), 'err');
            });
        }
        setBusy(false);
        showAlert(scanAlert, apiErrorMessage(res), 'err');
      });
    }

    function hideResult() {
      result.hidden = true;
      result.classList.remove('flash');
      btnRetake.hidden = true;
    }

    function showScanResult(data, imgW, imgH) {
      resultPlate.textContent = data.plate_number;
      var pct = Math.round((data.confidence || 0) * 100);
      confText.textContent = pct + '%';
      confFill.style.width = pct + '%';
      confFill.className = 'conf-fill' + (pct < 50 ? ' low' : pct < 80 ? ' mid' : '');
      resultNote.textContent = data.engine === 'browser' ? T('scan.engine.browser') : (pct < 60 ? T('scan.verify') : '');
      result.hidden = false;
      result.classList.add('flash');
      btnRetake.hidden = false;

      drawBox(data.box, imgW, imgH);

      var plateInput = document.getElementById('plate_number');
      plateInput.value = data.plate_number;
      plateInput.removeAttribute('aria-invalid');
      var errEl = document.querySelector('[data-err-for="plate_number"]');
      if (errEl) errEl.textContent = '';
      plateInput.classList.add('filled');
      showAlert(scanAlert, '');
    }

    function drawBox(box, w, h) {
      if (!box) return;
      ctx.save();
      ctx.strokeStyle = '#ffc93c';
      ctx.lineWidth = Math.max(3, w * 0.006);
      ctx.shadowColor = 'rgba(255,201,60,.9)';
      ctx.shadowBlur = 10;
      ctx.strokeRect(box.x * w, box.y * h, box.w * w, box.h * h);
      ctx.restore();
    }

    setMode('camera');

    /* ===========================================================================
     * Fees: fetch rates once, recompute on currency change
     * ======================================================================== */
    var feeEl = document.getElementById('fee');
    var feeBase = document.getElementById('fee-base');
    var feeRate = document.getElementById('fee-rate');
    var feeTotal = document.getElementById('fee-total');
    var feeSource = document.getElementById('fee-source');
    var currencySelect = document.getElementById('currency');
    var baseFeeUsd = parseFloat(feeEl.getAttribute('data-base')) || 50;
    var ratesState = null;

    function fmtMoney(amount, currency) {
      try {
        return new Intl.NumberFormat(window.I18N.locale(), { style: 'currency', currency: currency, currencyDisplay: 'code' }).format(amount);
      } catch (e) {
        return currency + ' ' + amount.toFixed(2);
      }
    }

    function renderFee() {
      if (!ratesState) { feeBase.textContent = T('fee.loading'); feeRate.textContent = '—'; feeTotal.textContent = '—'; feeSource.textContent = ''; return; }
      var currency = currencySelect.value;
      var rate = ratesState.rates[currency];
      feeBase.textContent = fmtMoney(baseFeeUsd, 'USD');
      if (!rate) { feeTotal.textContent = '—'; return; }
      feeRate.textContent = '1 USD = ' + rate + ' ' + currency;
      feeTotal.textContent = fmtMoney(Math.round(baseFeeUsd * rate * 100) / 100, currency);
      var when = new Date(ratesState.fetched_at * 1000);
      var timeStr = when.toLocaleString(window.I18N.locale(), { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' });
      var srcKey = ratesState.source === 'live' ? 'fee.source.live' : ratesState.source === 'stale' ? 'fee.source.stale' : 'fee.source.cache';
      feeSource.textContent = T(srcKey, { p: ratesState.provider }) + ' ' + T('fee.updated', { time: timeStr });
      feeEl.classList.toggle('stale', ratesState.source === 'stale');
    }

    function loadRates() {
      feeBase.textContent = T('fee.loading');
      return api('GET', 'api/rates.php').then(function (res) {
        if (res.ok && res.body && res.body.status === 'success') {
          ratesState = res.body.data;
          renderFee();
        } else {
          feeBase.textContent = '—';
          feeSource.textContent = apiErrorMessage(res);
        }
      });
    }

    currencySelect.addEventListener('change', renderFee);
    loadRates();
    document.addEventListener('langchange', renderFee);

    /* ===========================================================================
     * Registration form
     * ======================================================================== */
    var form = document.getElementById('reg-form');
    var regAlert = document.getElementById('reg-alert');
    var btnSubmit = document.getElementById('btn-submit');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearFieldErrors(form);
      showAlert(regAlert, '');

      var payload = {
        plate_number: form.plate_number.value,
        owner_name: form.owner_name.value,
        phone: form.phone.value,
        make: form.make.value,
        model: form.model.value,
        color: form.color.value,
        body_type: form.body_type.value,
        currency: form.currency.value
      };

      btnSubmit.disabled = true;
      var savingLabel = btnSubmit.textContent;
      btnSubmit.textContent = T('reg.saving');

      api('POST', 'api/register.php', { json: payload }).then(function (res) {
        btnSubmit.disabled = false;
        btnSubmit.textContent = savingLabel;
        if (res.ok && res.body && res.body.status === 'success') {
          var d = res.body.data;
          showAlert(regAlert, T('reg.success', { plate: d.plate_number, fee: fmtMoney(d.fee_converted, d.currency) }), 'ok');
          form.reset();
          currencySelect.value = 'USD';
          renderFee();
          hideResult();
          return;
        }
        var err = res.body && res.body.error;
        if (err && err.code === 'VALIDATION_FAILED' && err.fields) {
          showFieldErrors(form, err.fields);
          showAlert(regAlert, T('err.VALIDATION_FAILED'), 'err');
        } else if (err && err.code === 'PLATE_EXISTS') {
          showFieldErrors(form, { plate_number: T('err.PLATE_EXISTS') });
          showAlert(regAlert, T('err.PLATE_EXISTS'), 'err');
        } else {
          showAlert(regAlert, apiErrorMessage(res), 'err');
        }
      });
    });
  }

  /* ===========================================================================
   * Vehicles page: search + pagination
   * ======================================================================== */

  function initVehiclesPage() {
    var form = document.getElementById('search-form');
    var q = document.getElementById('q');
    var type = document.getElementById('type');
    var dateFrom = document.getElementById('date_from');
    var dateTo = document.getElementById('date_to');
    var btnReset = document.getElementById('btn-reset-filters');
    var tbody = document.getElementById('veh-body');
    var empty = document.getElementById('veh-empty');
    var countEl = document.getElementById('veh-count');
    var pager = document.getElementById('pager');
    var tableWrap = document.querySelector('.table-wrap');
    var page = 1;
    var seq = 0;

    function fmtMoney(amount, currency) {
      try { return new Intl.NumberFormat(window.I18N.locale(), { style: 'currency', currency: currency, currencyDisplay: 'code' }).format(amount); }
      catch (e) { return currency + ' ' + Number(amount).toFixed(2); }
    }
    function fmtDate(s) {
      var d = new Date(s.replace(' ', 'T'));
      return isNaN(d) ? s : d.toLocaleString(window.I18N.locale(), { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function query() {
      var params = new URLSearchParams();
      if (q.value.trim()) params.set('q', q.value.trim());
      if (type.value) params.set('type', type.value);
      if (dateFrom.value) params.set('date_from', dateFrom.value);
      if (dateTo.value) params.set('date_to', dateTo.value);
      params.set('page', page);
      return params.toString();
    }

    function renderRows(items) {
      tbody.innerHTML = '';
      items.forEach(function (v) {
        var tr = document.createElement('tr');
        function td(label, text, extraClass) {
          var cell = document.createElement('td');
          cell.setAttribute('data-label', label);
          if (extraClass) cell.className = extraClass;
          return cell;
        }
        var c1 = td(T('th.plate'), '');
        var tag = document.createElement('span');
        tag.className = 'tag-plate';
        tag.textContent = v.plate_number;
        c1.appendChild(tag);
        tr.appendChild(c1);

        var c2 = td(T('th.owner'), v.owner_name); c2.textContent = v.owner_name; tr.appendChild(c2);
        var c3 = td(T('th.phone'), v.phone); c3.textContent = v.phone; tr.appendChild(c3);
        var c4 = td(T('th.vehicle'), ''); c4.textContent = v.make + ' ' + v.model; tr.appendChild(c4);
        var c5 = td(T('th.color'), v.color); c5.textContent = v.color; tr.appendChild(c5);

        var c6 = td(T('th.type'), '');
        var badge = document.createElement('span');
        badge.className = 'badge';
        badge.setAttribute('data-i18n', 'type.' + v.body_type);
        badge.textContent = T('type.' + v.body_type);
        c6.appendChild(badge);
        tr.appendChild(c6);

        var c7 = td(T('th.fee.usd'), '', 'num'); c7.textContent = fmtMoney(v.base_fee, 'USD'); tr.appendChild(c7);
        var c8 = td(T('th.fee.paid'), '', 'num'); c8.textContent = fmtMoney(v.fee_converted, v.currency); tr.appendChild(c8);
        var c9 = td(T('th.date'), '', 'num'); c9.textContent = fmtDate(v.created_at); tr.appendChild(c9);

        tbody.appendChild(tr);
      });
    }

    function renderPager(data) {
      pager.innerHTML = '';
      if (data.total_pages <= 1) return;
      function makeBtn(label, target, disabled, current) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn';
        b.textContent = label;
        if (current) b.setAttribute('aria-current', 'page');
        if (disabled) b.disabled = true;
        else b.addEventListener('click', function () { page = target; load(); tableWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
        return b;
      }
      pager.appendChild(makeBtn('‹ ' + T('page.prev'), data.page - 1, data.page <= 1));
      var start = Math.max(1, data.page - 2);
      var end = Math.min(data.total_pages, start + 4);
      start = Math.max(1, end - 4);
      for (var p = start; p <= end; p++) pager.appendChild(makeBtn(String(p), p, false, p === data.page));
      pager.appendChild(makeBtn(T('page.next') + ' ›', data.page + 1, data.page >= data.total_pages));
    }

    function load() {
      var mySeq = ++seq;
      tbody.innerHTML = '';
      empty.hidden = true;
      countEl.textContent = T('veh.loading');
      api('GET', 'api/vehicles.php?' + query()).then(function (res) {
        if (mySeq !== seq) return;
        if (!res.ok || !res.body || res.body.status !== 'success') {
          countEl.textContent = apiErrorMessage(res);
          return;
        }
        var data = res.body.data;
        page = data.page;
        if (!data.items.length) {
          empty.hidden = false;
          countEl.textContent = T('veh.count', { n: 0 });
          pager.innerHTML = '';
          return;
        }
        renderRows(data.items);
        countEl.textContent = data.total === 1 ? T('veh.count.one') : T('veh.count', { n: data.total });
        renderPager(data);
      });
    }

    var debounceTimer;
    function debouncedSearch() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () { page = 1; load(); }, 350);
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); page = 1; load(); });
    q.addEventListener('input', debouncedSearch);
    type.addEventListener('change', function () { page = 1; load(); });
    dateFrom.addEventListener('change', function () { page = 1; load(); });
    dateTo.addEventListener('change', function () { page = 1; load(); });
    btnReset.addEventListener('click', function () {
      form.reset();
      page = 1;
      load();
    });
    document.addEventListener('langchange', load);

    load();
  }

  /* ===========================================================================
   * Init
   * ======================================================================== */

  document.addEventListener('DOMContentLoaded', function () {
    if (PAGE === 'index') initScanPage();
    else if (PAGE === 'vehicles') initVehiclesPage();
  });
})();
