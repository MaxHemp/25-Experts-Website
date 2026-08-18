/* 25 EXPERTS — Vanilla-JS v2 („Am Tisch.")
   1) Header: Burger-Menü (aria-expanded, Escape schließt, Klick außerhalb schließt),
      Overlay-Header wird beim Scrollen zu Papier (.is-scrolled).
   2) Motion (nur ohne prefers-reduced-motion): Reveal-on-Scroll ([data-reveal], [data-reveal-group]),
      Grid25 füllt sich ([data-grid25]), Zähler ([data-count]), Marquee-Pause bei Hover (CSS) und
      bei Reduced Motion (CSS). Ken-Burns läuft in CSS (html.js + .x-hero--kenburns).
   3) Anmeldeformular: Validierung, Honeypot, JSON-POST per fetch an data-endpoint,
      Fallback ohne JS: klassischer POST an action. Danke-Seite: Text je Status (?status=…). */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Header: Burger ---------- */
  var header = document.getElementById('x-header');
  var burger = document.querySelector('.x-burger');
  var nav = document.getElementById('x-nav');
  if (burger && nav) {
    var setOpen = function (open) {
      nav.classList.toggle('is-open', open);
      if (header) { header.classList.toggle('is-open', open); }
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      burger.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
    };
    burger.addEventListener('click', function () {
      setOpen(burger.getAttribute('aria-expanded') !== 'true');
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && burger.getAttribute('aria-expanded') === 'true') { setOpen(false); burger.focus(); }
    });
    document.addEventListener('click', function (e) {
      if (!nav.contains(e.target) && !burger.contains(e.target) && burger.getAttribute('aria-expanded') === 'true') { setOpen(false); }
    });
  }

  /* ---------- Header: Overlay → Papier beim Scrollen ---------- */
  if (header && header.classList.contains('x-header--overlay')) {
    var onScroll = function () {
      header.classList.toggle('is-scrolled', (window.scrollY || window.pageYOffset) > 24);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ---------- Motion ---------- */
  var supportsIO = 'IntersectionObserver' in window;

  // Reveal-on-Scroll
  var revealEls = document.querySelectorAll('[data-reveal], [data-reveal-group]');
  if (reduced || !supportsIO) {
    Array.prototype.forEach.call(revealEls, function (el) { el.classList.add('is-visible'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { entry.target.classList.add('is-visible'); io.unobserve(entry.target); }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    Array.prototype.forEach.call(revealEls, function (el) { io.observe(el); });
    // Sicherheitsnetz: nach 2,5 s alles sichtbar (z. B. wenn Elemente über dem Fold liegen und nie schneiden)
    window.setTimeout(function () {
      Array.prototype.forEach.call(revealEls, function (el) { el.classList.add('is-visible'); });
    }, 2500);
  }

  // Grid25: Punkte nacheinander, das orange Feld (14) zuletzt
  var grids = document.querySelectorAll('[data-grid25]');
  var fillGrid = function (g) {
    if (g.classList.contains('is-filled')) { return; }
    var dots = g.querySelectorAll('i');
    if (reduced) { g.classList.add('is-filled'); return; }
    Array.prototype.forEach.call(dots, function (d, i) {
      var order = (i === 13) ? dots.length : i;   // 14. Feld zuletzt
      d.style.transitionDelay = (order * 40) + 'ms';
    });
    g.classList.add('is-filled');
  };
  if (reduced || !supportsIO) {
    Array.prototype.forEach.call(grids, fillGrid);
  } else {
    var gio = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { if (entry.isIntersecting) { fillGrid(entry.target); gio.unobserve(entry.target); } });
    }, { threshold: 0.2 });
    Array.prototype.forEach.call(grids, function (g) { gio.observe(g); });
  }

  // Zähler: [data-count="25"] zählt in 900 ms hoch
  var counters = document.querySelectorAll('[data-count]');
  var runCounter = function (el) {
    if (el.getAttribute('data-counted')) { return; }
    el.setAttribute('data-counted', '1');
    var target = parseInt(el.getAttribute('data-count'), 10);
    if (isNaN(target) || reduced) { el.textContent = el.getAttribute('data-count'); return; }
    var start = null, dur = 900;
    var step = function (ts) {
      if (!start) { start = ts; }
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = String(Math.round(target * eased));
      if (p < 1) { window.requestAnimationFrame(step); } else { el.textContent = String(target); }
    };
    el.textContent = '0';
    window.requestAnimationFrame(step);
  };
  if (reduced || !supportsIO) {
    Array.prototype.forEach.call(counters, function (el) { el.textContent = el.getAttribute('data-count'); });
  } else {
    var cio = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { if (entry.isIntersecting) { runCounter(entry.target); cio.unobserve(entry.target); } });
    }, { threshold: 0.4 });
    Array.prototype.forEach.call(counters, function (el) { cio.observe(el); });
  }

  /* ---------- Danke-Seite: Text passend zum Status aus send.php (?status=zugelassen|pruefung|warteliste) ---------- */
  var statusMatch = window.location.search.match(/[?&]status=([a-z]+)/);
  if (statusMatch) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-status-' + statusMatch[1] + ']'), function (el) {
      el.textContent = el.getAttribute('data-status-' + statusMatch[1]);
    });
  }

  /* ---------- Formular ---------- */
  var form = document.querySelector('form[data-endpoint]');
  if (!form) { return; }

  var status = form.querySelector('.x-form__status');
  var submit = form.querySelector('button[type="submit"]');

  function showStatus(kind, text) {
    if (!status) { return; }
    status.hidden = false;
    status.className = 'x-form__status x-form__status--' + kind;
    status.textContent = text;
    status.focus();
  }

  function markInvalid(el, invalid) {
    var field = el.closest('.x-field');
    if (field) { field.classList.toggle('is-invalid', invalid); }
    el.setAttribute('aria-invalid', invalid ? 'true' : 'false');
  }

  function validate() {
    var firstBad = null;
    var required = form.querySelectorAll('[required]');
    Array.prototype.forEach.call(required, function (el) {
      var ok = true;
      if (el.type === 'checkbox') { ok = el.checked; }
      else if (el.type === 'radio') {
        var group = form.querySelectorAll('input[name="' + el.name + '"]');
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
    var url = form.querySelector('input[type="url"]');
    if (url && url.value.trim() && !/^https?:\/\//i.test(url.value.trim())) { markInvalid(url, true); if (!firstBad) { firstBad = url; } }
    return firstBad;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var bad = validate();
    if (bad) {
      showStatus('error', 'Bitte prüfen Sie die markierten Felder.');
      bad.focus();
      return;
    }
    // Honeypot: Bots füllen das versteckte Feld; dann still „erfolgreich" beenden.
    var hp = form.querySelector('input[name="website"]');
    if (hp && hp.value) { window.location.href = form.getAttribute('data-thanks') || 'danke.html'; return; }

    var data = {};
    var fd = new FormData(form);
    fd.forEach(function (v, k) { if (k !== 'website') { data[k] = v; } });
    var privacy = form.querySelector('input[name="privacy"]') || form.querySelector('input[name="consent"]');
    if (privacy) { data[privacy.name] = privacy.checked; }
    data.edition = form.getAttribute('data-edition') || '';
    data.source = window.location.href;
    data.submitted_at = new Date().toISOString();

    var endpoint = form.getAttribute('data-endpoint');
    if (!endpoint || endpoint.indexOf('TBD') !== -1) {
      showStatus('error', 'Der Formular-Endpunkt ist noch nicht konfiguriert. Bitte schreiben Sie uns per E-Mail.');
      return;
    }

    submit.disabled = true;
    submit.textContent = 'Wird gesendet …';
    fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(data) })
      .then(function (r) {
        // Server (anmeldung/send.php) antwortet JSON {ok:true} bzw. {ok:false,error:"…"}
        return r.json().catch(function () { return { ok: r.ok }; }).then(function (j) {
          if (!r.ok || !j.ok) { throw new Error(j && j.error ? j.error : 'HTTP ' + r.status); }
          return j;
        });
      })
      .then(function (j) {
        // send.php meldet den Status der Anmeldung (zugelassen | pruefung | warteliste); die Danke-Seite passt ihren Text daran an
        var thanks = form.getAttribute('data-thanks') || 'danke.html';
        window.location.href = thanks + (j && j.status ? '?status=' + encodeURIComponent(j.status) : '');
      })
      .catch(function (err) {
        submit.disabled = false;
        submit.textContent = 'Anmeldung absenden';
        var msg = (err && err.message && err.message.indexOf('HTTP') !== 0 && err.message.indexOf('Failed') !== 0 && err.message.indexOf('NetworkError') !== 0)
          ? err.message
          : 'Die Anmeldung konnte nicht übertragen werden. Bitte versuchen Sie es erneut oder schreiben Sie uns per E-Mail.';
        showStatus('error', msg);
      });
  });

  Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select'), function (el) {
    el.addEventListener('input', function () { markInvalid(el, false); });
    el.addEventListener('change', function () { markInvalid(el, false); });
  });

  // Rückkehr vom No-JS-Fallback: send.php leitet bei Fehlern auf …/?fehler=1#anmeldung zurück
  if (/[?&]fehler=1/.test(window.location.search)) {
    var reason = (window.location.search.match(/[?&]grund=([^&]*)/) || [])[1];
    var texte = {
      pflicht: 'Bitte füllen Sie alle Pflichtfelder aus und prüfen Sie Ihre Angaben.',
      email: 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
      limit: 'Zu viele Versuche in kurzer Zeit. Bitte versuchen Sie es in einer Stunde erneut.',
      versand: 'Die Anmeldung konnte nicht übertragen werden. Bitte versuchen Sie es erneut oder schreiben Sie uns per E-Mail.'
    };
    showStatus('error', texte[reason] || texte.versand);
  }
})();
