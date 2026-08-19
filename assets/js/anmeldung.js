/* 25 EXPERTS — Anmeldeseite: Wizard in vier Schritten (Persönliche Angaben → Rechnung → Dein Platz → Bestätigung).
   Progressive Enhancement: ohne JavaScript stehen alle Schritte untereinander und das Formular
   sendet klassisch an anmeldung/send.php (dort Redirect zur Zahlungsseite).
   Mit JavaScript: Schrittnavigation mit Validierung je Schritt, JSON-POST, Weiterleitung auf pay_url. */
(function () {
  'use strict';

  var form = document.querySelector('form[data-wizard]');
  if (!form) { return; }

  var steps = Array.prototype.slice.call(form.querySelectorAll('.x-wizard__step'));
  var tabs = Array.prototype.slice.call(form.querySelectorAll('.x-wizard__tab'));
  var back = form.querySelector('.x-wizard__back');
  var next = form.querySelector('.x-wizard__next');
  var submit = form.querySelector('.x-wizard__submit');
  var status = form.querySelector('.x-form__status');
  var current = 1;
  var maxVisited = 1;

  function showStatus(kind, text) {
    if (!status) { return; }
    status.hidden = false;
    status.className = 'x-form__status x-form__status--' + kind;
    status.textContent = text;
  }
  function hideStatus() { if (status) { status.hidden = true; } }

  function markInvalid(el, invalid) {
    var field = el.closest('.x-field');
    if (field) { field.classList.toggle('is-invalid', invalid); }
    el.setAttribute('aria-invalid', invalid ? 'true' : 'false');
  }

  function validateStep(n) {
    var step = steps[n - 1];
    var firstBad = null;
    Array.prototype.forEach.call(step.querySelectorAll('[required]'), function (el) {
      var ok = true;
      if (el.type === 'checkbox') { ok = el.checked; }
      else if (el.type === 'radio') {
        var group = step.querySelectorAll('input[name="' + el.name + '"]');
        ok = Array.prototype.some.call(group, function (r) { return r.checked; });
        Array.prototype.forEach.call(group, function (r) { markInvalid(r, !ok); });
        if (!ok && !firstBad) { firstBad = el; }
        return;
      }
      else if (el.type === 'email') { ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim()); }
      else { ok = el.value.trim().length > 0; }
      markInvalid(el, !ok);
      if (!ok && !firstBad) { firstBad = el; }
    });
    Array.prototype.forEach.call(step.querySelectorAll('input[type="email"]:not([required]), input[type="url"]'), function (el) {
      var v = el.value.trim();
      if (!v) { return; }
      var ok = el.type === 'email' ? /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) : /^https?:\/\//i.test(v);
      markInvalid(el, !ok);
      if (!ok && !firstBad) { firstBad = el; }
    });
    return firstBad;
  }

  function goTo(n, quiet) {
    current = n;
    if (n > maxVisited) { maxVisited = n; }
    steps.forEach(function (st, i) { st.classList.toggle('is-active', i === n - 1); });
    tabs.forEach(function (t, i) {
      t.classList.toggle('is-active', i === n - 1);
      t.classList.toggle('is-done', i < n - 1);
    });
    back.hidden = n === 1;
    next.hidden = n === steps.length;
    submit.hidden = n !== steps.length;
    hideStatus();
    // Rechnungsempfänger mit dem Unternehmen aus Schritt 1 vorbelegen
    if (n === 2) {
      var inv = form.querySelector('input[name="invoice_company"]');
      var comp = form.querySelector('input[name="company"]');
      if (inv && comp && !inv.value.trim()) { inv.value = comp.value; }
    }
    if (quiet) { return; }
    var kick = steps[n - 1].querySelector('.x-kicker');
    if (kick) { kick.setAttribute('tabindex', '-1'); kick.focus({ preventScroll: true }); }
    form.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }

  submit.hidden = true;
  goTo(1, true);

  next.addEventListener('click', function () {
    var bad = validateStep(current);
    if (bad) { showStatus('error', form.getAttribute('data-msg-fehler') || 'Bitte prüfe die markierten Felder.'); bad.focus(); return; }
    goTo(current + 1);
  });
  back.addEventListener('click', function () { goTo(current - 1); });
  tabs.forEach(function (t, i) {
    t.querySelector('button').addEventListener('click', function () {
      var target = i + 1;
      if (target === current) { return; }
      if (target < current) { goTo(target); return; }
      // vorwärts nur Schritt für Schritt, mit Validierung
      var bad = validateStep(current);
      if (bad) { showStatus('error', form.getAttribute('data-msg-fehler') || 'Bitte prüfe die markierten Felder.'); bad.focus(); return; }
      if (target <= maxVisited + 1) { goTo(Math.min(target, current + 1)); }
    });
  });

  Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select'), function (el) {
    el.addEventListener('input', function () { markInvalid(el, false); });
    el.addEventListener('change', function () { markInvalid(el, false); });
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    for (var i = 1; i <= steps.length; i++) {
      var bad = validateStep(i);
      if (bad) { goTo(i); showStatus('error', form.getAttribute('data-msg-fehler') || 'Bitte prüfe die markierten Felder.'); bad.focus(); return; }
    }
    var hp = form.querySelector('input[name="website"]');
    if (hp && hp.value) { window.location.href = form.getAttribute('data-thanks') || 'danke.html'; return; }

    var data = {};
    var fd = new FormData(form);
    fd.forEach(function (v, k) { if (k !== 'website' && k !== 'binding') { data[k] = v; } });
    var privacy = form.querySelector('input[name="privacy"]');
    if (privacy) { data.privacy = privacy.checked; }
    data.name = ((data.vorname || '') + ' ' + (data.nachname || '')).trim();
    data.edition = form.getAttribute('data-edition') || '';
    data.source = window.location.href;
    data.submitted_at = new Date().toISOString();

    var endpoint = form.getAttribute('data-endpoint');
    submit.disabled = true;
    var label = submit.textContent;
    submit.textContent = 'Wird gesendet …';
    fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) {
        return r.json().catch(function () { return { ok: r.ok }; }).then(function (j) {
          if (!r.ok || !j.ok) { throw new Error(j && j.error ? j.error : 'HTTP ' + r.status); }
          return j;
        });
      })
      .then(function (j) {
        if (j && j.pay_url && /^https?:\/\//.test(j.pay_url)) { window.location.href = j.pay_url; return; }
        var thanks = form.getAttribute('data-thanks') || 'danke.html';
        window.location.href = thanks + (j && j.status ? '?status=' + encodeURIComponent(j.status) : '');
      })
      .catch(function (err) {
        submit.disabled = false;
        submit.textContent = label;
        var msg = (err && err.message && err.message.indexOf('HTTP') !== 0 && err.message.indexOf('Failed') !== 0 && err.message.indexOf('NetworkError') !== 0)
          ? err.message
          : 'Die Anmeldung konnte nicht übertragen werden. Bitte versuche es erneut oder schreib uns per E-Mail.';
        showStatus('error', msg);
      });
  });
})();
