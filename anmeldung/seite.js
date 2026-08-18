/* 25 EXPERTS – kleine Helfer der Anmeldeseiten (Rechnung, Ticket, Admin): Druck-Schaltfläche, Bestätigungsabfragen.
   Liegt extern, weil die Content-Security-Policy keine Inline-Skripte erlaubt. */
(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('[data-print]'), function (b) {
    b.addEventListener('click', function () { window.print(); });
  });
  Array.prototype.forEach.call(document.querySelectorAll('form[data-confirm]'), function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); } });
  });
})();
