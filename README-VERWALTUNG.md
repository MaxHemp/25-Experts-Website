# 25 EXPERTS – Verwaltung der Event-Editionen (/verwaltung/)

Das Backend für das Team: Editionen anlegen, bearbeiten, online stellen, Anmeldung öffnen/schließen,
duplizieren, archivieren, löschen. Ohne IT-Kenntnisse bedienbar; Änderungen sind sofort live
(Browser zeigen HTML bis zu 10 Minuten aus dem Cache).

## Zugang

- Adresse: `https://25-experts.de/verwaltung/`
- Anmeldung: Benutzer `ADMIN_USER` und Passwort aus `ADMIN_PASS_HASH` in `anmeldung/config.php`
  (dieselben Zugangsdaten wie für die Anmeldungs-Übersicht `/anmeldung/admin.php`).
  Passwort-Hash erzeugen: `php -r 'echo password_hash("DeinPasswort", PASSWORD_DEFAULT);'`
  Ohne gesetzten Hash ist die Verwaltung gesperrt.

## Wie es zusammenhängt

- Jede Edition ist eine JSON-Datei in `anmeldung/data/editionen/` (liegt nur auf dem Server,
  nie im Git; regelmäßig per Hostinger-Backup sichern).
- Die öffentlichen Seiten werden serverseitig gerendert (`edition/*.php`, Routing in `.htaccess`):
  `/editionen` (Übersicht), `/editionen/{adresse}/` (Landingpage), `…/anmeldung` (Formular-Wizard),
  `…/danke`, `…/og.jpg` (Share-Bild beim Teilen von Links, automatisch aus Thema/Termin erzeugt).
- Startseite: Die Editions-Karten werden per JavaScript live aus der Verwaltung geladen
  (`edition/karten.php`); ohne JavaScript bleibt der zuletzt gebaute Stand sichtbar.
- Sitemap (`/sitemap.xml`) nimmt Online-Editionen automatisch auf.
- Beim ersten Aufruf werden die Start-Editionen aus `edition/seed/` importiert (nur, wenn die
  Edition noch nicht existiert); danach ist die Verwaltung die einzige Quelle. Die Rahmen-Texte
  der Website (Navigation, Format-Seite, Bausteine) kommen weiterhin aus `content/` im Kit-Repo.

## Status je Edition

| Status       | Wirkung                                                                 |
|--------------|-------------------------------------------------------------------------|
| Entwurf      | Nicht öffentlich; „Ansehen“ in der Verwaltung nutzt einen Vorschau-Link. |
| Angekündigt  | Nur Teaser-Karte auf Startseite und Übersicht, keine Landingpage.        |
| Online       | Landingpage öffentlich; Anmeldung zusätzlich per Schalter steuerbar.     |
| Archiviert   | Von der Website genommen; Anmeldedaten bleiben erhalten.                 |

## Anmeldungen je Edition

- Preis (netto), Plätze und Ticketnummern-Anfang stehen je Edition in der Verwaltung.
- Die Anmeldestrecke (`anmeldung/`) rechnet Zahlung/Rechnung/Ticket automatisch mit den Werten
  der jeweiligen Edition; die Plätze werden je Edition gezählt (voll → Warteliste).
- Anmeldungen ansehen: `/anmeldung/admin.php` (mit Editions-Filter, CSV-Export).
- Löschen einer Edition ist nur ohne Anmeldungen möglich; sonst archivieren.
