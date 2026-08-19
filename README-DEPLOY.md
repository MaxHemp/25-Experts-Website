# 25 EXPERTS – Website auf Hostinger veröffentlichen (Schritt für Schritt)

Stand 18.08.2026 (Messaging v5: keine Sponsoring-Seite mehr; /sponsoring leitet auf /kontakt#partner). Dieses Verzeichnis ist der komplette Inhalt des GitHub-Repos, das Hostinger nach `public_html` ausrollt.
Domain: **https://25-experts.de/** (Domain und E-Mail liegen bei Hostinger). Kein n8n, kein Netlify, kein Resend:
Die komplette Anmeldestrecke (Anmeldung → Zahlung direkt bei der Anmeldung per PayPal oder Rechnung → Ticket mit QR-Code → Admin; keine Vorprüfung mehr, Stand 19.08.2026) läuft in `anmeldung/` auf dem Hostinger-Webspace und verschickt alle Mails über ein Hostinger-Postfach. **Einrichtung und Betrieb der Anmeldestrecke (PayPal, Bankdaten, Admin, Allowlist, tägliche Aufgaben): `README-ANMELDUNG.md`.**

Was du selbst tust: **GitHub-Repo anlegen → Dateien hochladen → Repo in Hostinger verbinden → config.php und Postfach anlegen → testen.**
Alles andere (Fotos nachladen, Redirects, Caching, Sicherheits-Header) ist vorbereitet.

---

## Was liegt hier?

```
.github/workflows/fotos.yml   GitHub-Workflow: lädt die 12 Symbolbilder vom CDN nach assets/img/fotos/ und committet sie
.gitignore                    schließt anmeldung/config.php (Zugangsdaten) und Systemdateien aus
.htaccess                     HTTPS-Zwang, www → 25-experts.de, saubere URLs (/format), Redirect /sponsoring → /kontakt#partner, Caching, Sicherheits-Header, 404
404.html, index.html, format.html, editionen.html, neutralitaetskodex.html, ueber-uns.html,
kontakt.html, impressum.html, datenschutz.html, sitemap.xml, robots.txt      die Website
editionen/change-management/index.html + danke.html                          Landingpage 25 CHANGE MANAGEMENT EXPERTS
assets/css, assets/js, assets/fonts, assets/img                              Stylesheets, Skript, Schriften, Logos
assets/img/fotos/             Symbolbilder (leer, bis der Workflow oder tools/fetch_fotos.py sie geladen hat)
assets/img/25experts-logo-horizontal.png   Logo als PNG für E-Mails/Signatur (URL: https://25-experts.de/assets/img/25experts-logo-horizontal.png)
anmeldung/send.php            Formular-Handler (PHP 8.1+): Validierung, Speicherung, automatische Zulassung, Mails (PHPMailer per SMTP)
anmeldung/aktion.php, zahlung.php, paypal.php, rechnung.php, ticket.php, admin.php   Gastgeber-Links, Zahlungsseite (PayPal/Rechnung), Rechnung, Ticket mit QR, Admin
anmeldung/config.example.php  Vorlage für anmeldung/config.php (SMTP, PayPal, Bank, Admin, Secrets, Edition) – config.php liegt NICHT im Repo
anmeldung/.htaccess, anmeldung/lib/, anmeldung/data/   Zugriffsschutz; PHPMailer + QR-Bibliothek (Lizenzen liegen bei); Datenablage (SQLite, nicht im Repo) und Domainlisten
README-ANMELDUNG.md           Anleitung Anmeldestrecke: PayPal-Konto/App, Bankdaten, Admin-Passwort, Allowlist, täglicher Betrieb, Datenschutz
tools/fetch_fotos.py, tools/fotos.json   Foto-Manifest und Ladeskript (Python 3, ohne Zusatzpakete)
```

---

## Teil A: GitHub-Repo anlegen und füllen

1. **Repo anlegen:** github.com → „New repository" → Name z. B. `25-experts-website`, **Private**, ohne README/.gitignore-Vorlage (die Dateien bringen wir mit) → „Create repository".
2. **Dateien hochladen.** Zwei Wege:
   - **GitHub Desktop** (empfohlen): Repo klonen → den kompletten Inhalt dieses Ordners `repo/` (inklusive der versteckten Dateien `.github/`, `.gitignore`, `.htaccess`) in den geklonten Ordner kopieren → Commit „Website 25 EXPERTS" → Push. Versteckte Dateien im Windows-Explorer über „Ansicht → Einblenden → Ausgeblendete Elemente" anzeigen.
   - **Web-Upload:** Auf der Repo-Seite „uploading an existing file" → Ordnerinhalt per Drag-and-drop hochladen (Browser nimmt ganze Ordner) → „Commit changes". Falls `.github/`, `.gitignore` oder `.htaccess` nicht mitkommen: im Repo „Add file → Create new file", als Namen `.github/workflows/fotos.yml` eintippen und den Inhalt aus der Datei einfügen (ebenso `.gitignore`, `.htaccess`, `anmeldung/.htaccess`, `anmeldung/lib/.htaccess`).
3. **Branch prüfen:** Standard-Branch heißt `main` (Settings → General → Default branch).
4. **Fotos laden lassen:** Reiter **Actions** → Workflow „Fotos ins Repo laden" → er startet beim ersten Push automatisch; sonst „Run workflow" → „Run workflow". Nach ein bis zwei Minuten liegt ein Commit „Fotos: Symbolbilder … nachgeladen" mit 12 JPEGs in `assets/img/fotos/`.
   Falls Actions im Repo deaktiviert sind: Settings → Actions → General → „Allow all actions" und unter „Workflow permissions" **„Read and write permissions"** wählen (der Workflow braucht Schreibrecht, um zu committen).
   **Fallback ohne Actions:** lokal im Repo-Ordner `python tools/fetch_fotos.py --site` ausführen (Python 3), dann `assets/img/fotos/` committen und pushen.

## Teil B: Repo in Hostinger verbinden (Deploy)

5. **hPanel** → **Websites** → bei 25-experts.de **Verwalten** → linke Leiste **Erweitert → Git**.
6. **Repository erstellen/verbinden:**
   - Repository-URL: die HTTPS-URL des Repos, z. B. `https://github.com/DEIN-NAME/25-experts-website.git`
   - Branch: `main`
   - Verzeichnis: leer lassen bzw. `public_html` (Ziel ist das Web-Root)
   - Bei **privatem** Repo zeigt Hostinger einen **SSH-Schlüssel** an („Deploy key"): kopieren → GitHub-Repo → Settings → **Deploy keys** → „Add deploy key" → Schlüssel einfügen (Read-only genügt). Dann in Hostinger die **SSH-URL** verwenden (`git@github.com:DEIN-NAME/25-experts-website.git`).
   - „Erstellen" → Hostinger klont das Repo nach `public_html`. Vorher vorhandene Platzhalterdateien (`default.php` o. ä.) im Dateimanager löschen, wenn Hostinger meckert, dass der Ordner nicht leer ist.
7. **Auto-Deploy einschalten:** In derselben Git-Ansicht steht eine **Webhook-URL** („Auto-Deployment") → kopieren → GitHub-Repo → Settings → **Webhooks** → „Add webhook" → Payload URL einfügen, Content type `application/json`, Event „Just the push event" → „Add webhook". Ab jetzt zieht Hostinger jeden Push automatisch nach (auch den Foto-Commit des Workflows). Ohne Webhook: in Hostinger auf „Deploy" klicken.
8. **PHP-Version:** Erweitert → **PHP-Konfiguration** → PHP **8.2** oder neuer (8.1 mindestens); Erweiterungen `mbstring`, `openssl` sind standardmäßig aktiv.

## Teil C: Formular und Anmeldestrecke scharf schalten (Postfach + config.php)

Kurzfassung hier; die vollständige Schritt-für-Schritt-Anleitung (PayPal, Bankverbindung, Admin-Passwort-Hash, Allowlist, was die Gastgeber täglich tun) steht in **`README-ANMELDUNG.md`**.

9. **Postfach:** `info@25-experts.de` ist bereits eingerichtet und wird sowohl als Absender (SMTP) als auch als Empfänger genutzt.
   SPF/DKIM/DMARC richtet Hostinger für seine Postfächer automatisch ein; unter E-Mails → „E-Mail-Zustellbarkeit" prüfen, ob alles grün ist.
10. **config.php anlegen:** hPanel → **Dateien → Dateimanager** → `public_html/anmeldung/` → `config.example.php` öffnen → Inhalt kopieren → „Neue Datei" `config.php` → einfügen und ausfüllen:
    - `SMTP_USER` / `MAIL_FROM` = `info@25-experts.de`, `SMTP_PASS` = Postfach-Passwort (Host `smtp.hostinger.com`, Port 465, SSL sind vorbelegt)
    - `MAIL_TO` = `info@25-experts.de` (bereits eingerichtet)
    - `RATE_SALT` und `APP_SECRET` = jeweils eine lange Zufallszeichenfolge (APP_SECRET signiert die Gastgeber-Links; später nicht mehr ändern)
    - `ADMIN_USER` / `ADMIN_PASS_HASH` (Hash mit `php -r 'echo password_hash("…", PASSWORD_DEFAULT);'`), `BANK_*`, `INVOICE_*`, `PAYPAL_*` (siehe README-ANMELDUNG.md)
    - `MAIL_FOOTER`: Anschrift/HRB/USt-IdNr. ergänzen, sobald bekannt (die `[TBD: …]`-Platzhalter stehen sonst in der Bestätigungsmail)
    - `MAIL_TRANSPORT` bleibt `'smtp'`
    - Ordner `anmeldung/data/` muss für PHP beschreibbar sein (Standard bei Hostinger; sonst Rechte 755).
    Speichern. Die Datei ist per `.htaccess` gegen Direktaufruf gesperrt und wird von Git ignoriert (überlebt Deploys).
11. **Test-Anmeldung:** https://25-experts.de/editionen/change-management/#anmeldung ausfüllen und absenden → Weiterleitung auf die Danke-Seite (Text je nach Status) → Weiterleitung direkt auf die Zahlungsseite; zwei Mails: Benachrichtigung an `MAIL_TO`, Bestätigung mit Zahlungslink an die eingetragene Adresse. Danach die Strecke einmal durchspielen: Zahlungsseite → „Per Rechnung zahlen" → Rechnungsmail → Link „Zahlung eingegangen" → Ticket-Mail mit QR; Admin unter /anmeldung/admin.php. Auch einmal mit deaktiviertem JavaScript testen (Redirect statt JSON) und einmal ein Pflichtfeld leer lassen (Fehlermeldung im Formular).
    Fehlersuche: Antwort „Konfiguration fehlt" → config.php fehlt/heißt falsch. „konnte nicht übertragen werden" → SMTP-Zugangsdaten prüfen (Postfachadresse als Benutzername, Port 465/SSL); Hostinger-Fehlerlog unter Erweitert → PHP-Konfiguration/„Fehlerprotokoll".

## Teil D: Domain, SSL, Suche

12. **DNS:** Domain liegt bei Hostinger; unter Domains → 25-experts.de → DNS prüfen, dass A-Record `@` und CNAME/A `www` auf den Hosting-Server zeigen (bei Hostinger-Hosting + Hostinger-Domain in der Regel schon so). Website → Verwalten zeigt „Domain verbunden".
13. **SSL:** Websites → Verwalten → **Sicherheit → SSL** → kostenloses Lifetime-SSL installieren (falls nicht automatisch geschehen) und „HTTPS erzwingen" einschalten. Zusätzlich erzwingt die `.htaccess` HTTPS und leitet `www.25-experts.de` auf `25-experts.de` um. Prüfen: http://www.25-experts.de/format → landet auf https://25-experts.de/format.
14. **Google Search Console:** search.google.com/search-console → Property „Domain: 25-experts.de" → DNS-TXT-Eintrag bei Hostinger (Domains → DNS) anlegen → bestätigen → **Sitemaps** → `https://25-experts.de/sitemap.xml` einreichen. Impressum/Datenschutz sind bewusst `noindex`, die Danke-Seite ist per robots.txt gesperrt.
15. **Optional prüfen:** securityheaders.com (Header aus der `.htaccess`), PageSpeed Insights (Fotos sind bereits 1920 px JPEG), Mail-Testadresse bei mail-tester.com für die Bestätigungsmail.

---

## So aktualisierst du die Seite später

Quelle der Wahrheit bleibt das Hauptpaket (`04-website/build_site.py`, `05-landingpage/build_landing.py`, Design-System, Fotos-Manifest). Ablauf:

1. Text/Struktur ändern → im Hauptpaket `python3 04-website/build_site.py` und `python3 05-landingpage/build_landing.py` ausführen.
2. `python3 07-deploy/build_repo.py` → schreibt die neuen Seiten/Assets nach `07-deploy/repo/` (Fotos, `anmeldung/`, `.htaccess`, Workflow bleiben erhalten). Mit `--zip` entsteht zusätzlich `25experts-repo.zip`.
3. Geänderte Dateien ins GitHub-Repo kopieren (GitHub Desktop: Ordner überschreiben → Commit → Push; oder Web-Upload der geänderten Dateien).
4. Der Push löst den Hostinger-Webhook aus; nach etwa einer Minute ist die Seite aktuell. Browser-Cache: HTML ist auf 10 Minuten gecacht, CSS/JS auf 7 Tage; bei CSS/JS-Änderungen ggf. hart neu laden (Strg+F5) oder den Dateinamen versionieren.

Nur Formular-Texte/Empfänger ändern (z. B. neuer `MAIL_TO`, neue Edition): `anmeldung/config.php` direkt im Hostinger-Dateimanager bearbeiten, kein Deploy nötig. Neue Editionen: `EDITION`, `EDITION_NAME`, `EDITION_DATUM`, `EDITION_BETRAG` in `config.php`; die Landingpage schickt ihren Editionsnamen (`data-edition`) mit, er erscheint in der Benachrichtigung.

Ändert sich der Inline-Script in der Seitenhülle (`<script>document.documentElement.classList.add('js');</script>`), den CSP-Hash in `.htaccess` neu berechnen (Kommentar dort).

## Sicherheit und Datenschutz (Kurzfassung)

- `send.php` prüft Pflichtfelder (Feldliste v5), E-Mail-Syntax, Ebene/Unternehmenstyp-Werte, Honeypot (`website`), Origin-Header, entfernt Zeilenumbrüche aus Kopfzeilenfeldern (kein Header-Injection), begrenzt je IP-Hash 5 Anmeldungen/Stunde (Sperrdateien ohne Klartext-IP in `sys_get_temp_dir()`), speichert die Anmeldung in `anmeldung/data/` (SQLite, gesperrt, nicht im Repo), protokolliert keine personenbezogenen Daten.
- Gastgeber-Links sind HMAC-signiert und einmalig, Teilnehmer-Links tragen Zufallstoken, PayPal-Zahlungen werden serverseitig verifiziert, der Admin ist per Basic-Auth + CSRF-Token geschützt (Details: README-ANMELDUNG.md §6).
- `anmeldung/config.php`, `anmeldung/lib/`, `anmeldung/data/`, `tools/`, `README*.md`, `.git*` sind per `.htaccess` gegen Direktaufruf gesperrt.
- Datenschutzerklärung (`datenschutz.html`) nennt Hostinger als Hoster und Mailversand über die eigenen Postfächer; die `[TBD]`-Stellen dort weiter anwaltlich klären.

## Lokal testen (optional)

`php -S 127.0.0.1:8765 -t repo/` im Ordner `07-deploy/` und `anmeldung/config.php` mit `MAIL_TRANSPORT = 'file'` anlegen: Mails landen als `.eml` in `MAIL_DUMP_DIR` statt verschickt zu werden. Automatisierter Test: `python3 07-deploy/test/run_tests.py` (116 Prüfungen: JSON- und Formular-POST, Validierung v5, Honeypot, Injection, Rate-Limit, SMTP-Fehlerpfad, Zulassung Allowlist/Freemail/unbekannt, Zulassen-/Absagen-Links, Rechnung, Zahlung markieren → Ticket mit QR, PayPal-Mock, Admin, Warteliste, Löschroutine, JSON-Fallback).
