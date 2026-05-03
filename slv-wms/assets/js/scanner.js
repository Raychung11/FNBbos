// SLV WMS — assets/js/scanner.js
// Shared barcode-scanner shell used by every mobile scan page.
//
// Public surface:
//   SLVScanner.start(elementId, onScan, opts)  // begin camera scan
//   SLVScanner.stop()                          // tear down
//   SLVScanner.beep()                          // audible feedback
//   SLVScanner.api(path, method, body)         // CSRF-aware fetch wrapper
//
// onScan is called with the *normalised* code string. We debounce for
// 1.5s so a single barcode held in front of the camera doesn't fire
// twenty times.

(function (global) {
  let html5qr = null;
  let lastCode = null;
  let lastAt   = 0;

  function libReady() {
    return new Promise((resolve, reject) => {
      if (typeof Html5Qrcode !== 'undefined') return resolve();
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode';
      s.onload  = () => resolve();
      s.onerror = () => reject(new Error('Failed to load html5-qrcode CDN'));
      document.head.appendChild(s);
    });
  }

  async function start(elementId, onScan, opts) {
    opts = opts || {};
    await libReady();
    if (html5qr) await stop();

    html5qr = new Html5Qrcode(elementId);
    const config = {
      fps: 12,
      qrbox: opts.qrbox || { width: 240, height: 160 },
      aspectRatio: opts.aspectRatio || 1.4,
      formatsToSupport: undefined, // accept all 1D + 2D
    };

    try {
      await html5qr.start(
        { facingMode: 'environment' },
        config,
        (decoded) => {
          const now  = Date.now();
          const code = String(decoded || '').trim();
          if (!code) return;
          if (code === lastCode && (now - lastAt) < 1500) return;
          lastCode = code;
          lastAt   = now;
          beep();
          if (navigator.vibrate) navigator.vibrate(60);
          try { onScan(code); } catch (e) { console.warn(e); }
        },
        () => { /* per-frame fail callback — quiet */ }
      );
    } catch (e) {
      console.warn('Scanner start failed:', e);
      throw e;
    }
  }

  async function stop() {
    if (!html5qr) return;
    try { await html5qr.stop(); } catch (_) {}
    try { await html5qr.clear(); } catch (_) {}
    html5qr = null;
    lastCode = null;
    lastAt   = 0;
  }

  // 880Hz blip via WebAudio. Works without any asset load.
  function beep() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'square';
      osc.frequency.value = 880;
      gain.gain.value     = 0.05;
      osc.connect(gain); gain.connect(ctx.destination);
      osc.start();
      setTimeout(() => { osc.stop(); ctx.close(); }, 80);
    } catch (_) {}
  }

  // CSRF-aware fetch to /api/v1/*. Returns the parsed JSON; throws on
  // non-2xx with the server's error message.
  async function api(path, method, body) {
    const opts = {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    if (window.SLV_CSRF) {
      opts.headers['X-CSRF-Token'] = window.SLV_CSRF;
    }
    const res = await fetch(path, opts);
    let data;
    try { data = await res.json(); }
    catch (_) { throw new Error('Server returned non-JSON (HTTP ' + res.status + ')'); }
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || ('HTTP ' + res.status));
    }
    return data;
  }

  global.SLVScanner = { start, stop, beep, api };
})(window);
