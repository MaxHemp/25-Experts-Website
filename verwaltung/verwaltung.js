/* 25 EXPERTS – Verwaltung: kleine Helfer.
   - data-confirm auf Formularen: Sicherheitsabfrage vor dem Absenden
   - Wiederholgruppen: Einträge hinzufügen, entfernen, sortieren (Namen werden beim Absenden
     serverseitig neu durchnummeriert, die Reihenfolge im DOM zählt) */
(function () {
  'use strict';

  document.addEventListener('submit', function (e) {
    var f = e.target.closest ? e.target.closest('form[data-confirm]') : null;
    if (f && !window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
  });

  var zaehler = 1000;

  function frischeNamen(row) {
    // eindeutige Indizes je neuer Zeile, damit sich Einträge nicht überschreiben
    zaehler++;
    row.querySelectorAll('input, textarea, select').forEach(function (el) {
      el.name = el.name.replace('__NEU__', 'n' + zaehler);
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof Element)) { return; }

    var addId = t.getAttribute('data-repeat-add');
    if (addId) {
      var tpl = document.querySelector('template[data-repeat-vorlage="' + addId + '"]');
      var ziel = document.querySelector('[data-repeat="' + addId + '"]');
      if (tpl && ziel) {
        var frag = tpl.content.cloneNode(true);
        ziel.appendChild(frag);
        var neu = ziel.lastElementChild;
        if (neu) {
          frischeNamen(neu);
          var erst = neu.querySelector('input, textarea');
          if (erst) { erst.focus(); }
        }
      }
      return;
    }
    if (t.hasAttribute('data-repeat-weg')) {
      var row = t.closest('.v-repeat__row');
      if (row && window.confirm('Diesen Eintrag entfernen?')) { row.remove(); }
      return;
    }
    if (t.hasAttribute('data-repeat-hoch') || t.hasAttribute('data-repeat-runter')) {
      var r = t.closest('.v-repeat__row');
      if (!r) { return; }
      if (t.hasAttribute('data-repeat-hoch') && r.previousElementSibling) {
        r.parentNode.insertBefore(r, r.previousElementSibling);
      } else if (t.hasAttribute('data-repeat-runter') && r.nextElementSibling) {
        r.parentNode.insertBefore(r.nextElementSibling, r);
      }
    }
  });

  // Vor dem Absenden: Zeilen der Wiederholgruppen in DOM-Reihenfolge durchnummerieren,
  // damit die Sortierung (↑/↓) auch serverseitig ankommt.
  var form = document.getElementById('bearbeiten');
  if (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('[data-repeat]').forEach(function (gruppe) {
        var id = gruppe.getAttribute('data-repeat');
        gruppe.querySelectorAll('.v-repeat__row').forEach(function (row, i) {
          row.querySelectorAll('input, textarea, select').forEach(function (el) {
            el.name = el.name.replace(/^rep\[[^\]]+\]\[[^\]]*\]/, 'rep[' + id + '][s' + i + ']');
          });
        });
      });
    });
  }
})();
