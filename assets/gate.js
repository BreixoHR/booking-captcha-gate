/* Booking Captcha Gate: resuelve el captcha, pide el widget al servidor y lo inserta. */
(function () {
  'use strict';

  var cfg = window.BookingCaptchaGate;
  if (!cfg) return;

  function whenRecaptchaReady(fn) {
    if (window.grecaptcha && typeof grecaptcha.ready === 'function') {
      grecaptcha.ready(fn);
    } else {
      setTimeout(function () { whenRecaptchaReady(fn); }, 150);
    }
  }

  // innerHTML no ejecuta <script>: se recrean para que el widget del proveedor arranque
  function insertWithScripts(container, html) {
    container.innerHTML = html;
    container.querySelectorAll('script').forEach(function (old) {
      var s = document.createElement('script');
      Array.prototype.forEach.call(old.attributes, function (a) { s.setAttribute(a.name, a.value); });
      s.text = old.text;
      old.replaceWith(s);
    });
  }

  function unlock(gate, token) {
    gate.classList.add('bcg-loading');
    return fetch(cfg.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ post: Number(gate.dataset.post), gate: gate.dataset.gate, token: token }),
    })
      .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
      .then(function (res) {
        if (!res.ok || !res.body.html) throw new Error(res.body.error || 'error');
        insertWithScripts(gate, res.body.html);
        gate.classList.remove('bcg-gate', 'bcg-loading');
      })
      .catch(function () {
        gate.classList.remove('bcg-loading');
        var msg = gate.querySelector('.bcg-message');
        if (msg) msg.textContent = cfg.errorText;
        if (cfg.mode === 'v2' && gate.dataset.widgetId) grecaptcha.reset(Number(gate.dataset.widgetId));
      });
  }

  function init(gate) {
    whenRecaptchaReady(function () {
      if (cfg.mode === 'v3') {
        // Invisible: se pide el token al cargar y Google puntúa el comportamiento
        grecaptcha.execute(cfg.siteKey, { action: 'booking_gate' }).then(function (token) { unlock(gate, token); });
        return;
      }
      var id = grecaptcha.render(gate.querySelector('.bcg-captcha'), {
        sitekey: cfg.siteKey,
        callback: function (token) { unlock(gate, token); },
      });
      gate.dataset.widgetId = id;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.bcg-gate').forEach(init);
  });
})();
