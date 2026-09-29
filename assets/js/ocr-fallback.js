/**
 * In-browser OCR fallback. Only used when api/scan.php answers OCR_UNAVAILABLE
 * (no server-side OCR engine configured, e.g. no cURL and no local Tesseract binary).
 * Loads Tesseract.js from jsDelivr on first use, reads the image with the browser's
 * CPU, and sends the recognised words to api/scan.php as client_words so the SAME
 * plate-picking logic the server uses (line-grouping, scoring) selects the plate.
 */
(function () {
  'use strict';

  var CDN_SCRIPT = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var scriptPromise = null;
  var workerPromise = null;

  function loadScript() {
    if (scriptPromise) return scriptPromise;
    scriptPromise = new Promise(function (resolve, reject) {
      if (window.Tesseract) return resolve();
      var s = document.createElement('script');
      s.src = CDN_SCRIPT;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Failed to load Tesseract.js')); };
      document.head.appendChild(s);
    });
    return scriptPromise;
  }

  function getWorker(onProgress) {
    if (workerPromise) return workerPromise;
    workerPromise = loadScript().then(function () {
      return window.Tesseract.createWorker('eng', 1, {
        logger: function (m) {
          if (onProgress && m.status === 'recognizing text') {
            onProgress(m.progress);
          }
        }
      });
    });
    return workerPromise;
  }

  /**
   * @param {Blob} blob captured frame or uploaded photo
   * @param {function(string)} onStatus optional progress callback (translated status text)
   * @returns {Promise<{plate_number:string, confidence:number, box:?object, engine:'browser'}|null>}
   */
  function recognize(blob, onStatus) {
    var T = function (k) { return window.I18N ? window.I18N.t(k) : k; };
    return getWorker(function (progress) {
      if (onStatus) onStatus(T('scan.browser.reading') + ' ' + Math.round(progress * 100) + '%');
    }).then(function (worker) {
      return worker.setParameters({ tessedit_pageseg_mode: '11' }).then(function () {
        return worker.recognize(blob);
      });
    }).then(function (out) {
      var words = [];
      var data = out && out.data;
      var w = (data && data.imageWidth) || 1;
      var h = (data && data.imageHeight) || 1;
      var list = (data && data.words) || [];
      list.forEach(function (word) {
        if (!word.text || !word.text.trim()) return;
        var b = word.bbox || {};
        words.push({
          text: word.text.trim(),
          x: (b.x0 || 0) / w,
          y: (b.y0 || 0) / h,
          w: ((b.x1 || 0) - (b.x0 || 0)) / w,
          h: ((b.y1 || 0) - (b.y0 || 0)) / h,
          conf: typeof word.confidence === 'number' ? word.confidence / 100 : null
        });
      });

      var fd = new FormData();
      fd.append('client_words', JSON.stringify(words));
      return fetch('api/scan.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF },
        credentials: 'same-origin',
        body: fd
      }).then(function (res) { return res.json(); });
    }).then(function (json) {
      if (json && json.status === 'success') return json.data;
      return null;
    }).catch(function (err) {
      console.error('Browser OCR failed:', err);
      return null;
    });
  }

  window.PlateScanBrowserOCR = { recognize: recognize };
})();
