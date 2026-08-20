# 25 EXPERTS – Anmeldestrecke einrichten und betreiben (Anleitung für Max)

Stand 19.08.2026 (v6: keine Vorprüfung mehr). Die Anmeldestrecke läuft komplett auf dem Hostinger-Webspace (PHP 8.1+, kein n8n, kein Netlify, kein Resend):
Anmeldung → direkt zur Zahlung (PayPal oder Rechnung) → Ticket mit QR-Code. Die Gastgeber behalten sich vor,
Anmeldungen für ungültig zu erklären, wenn die Teilnahmebedingungen nicht erfüllt sind (Admin).
Alles liegt in `anmeldung/`. Konfiguration ausschließlich in `anmeldung/config.php` (nicht im Repo).
Deploy der Website: siehe `README-DEPLOY.md`. Diese Anleitung ergänzt Teil C dort.

---

## 1. Ablauf in acht Zeilen

1. Teilnehmer meldet sich verbindlich auf der eigenen Anmeldeseite an (`/editionen/change-management/anmeldung`, Wizard in vier Schritten: Persönliche Angaben → Rechnung → Dein Platz → Bestätigung; Feldliste v7 inkl. Rechnungsdaten, offene Frage optional → `anmeldung/send.php`). Rechnungsempfänger, -adresse und Bestellnummer erscheinen auf der Rechnung; der Rechnungskontakt erhält eine Kopie der Rechnungsmail.
2. Keine Vorprüfung: Jede gültige Anmeldung ist sofort **zugelassen** und wird direkt auf die Zahlungsseite (`zahlung.php`) weitergeleitet; parallel kommt die Bestätigung mit dem Zahlungslink per Mail. Sind alle `MAX_SEATS` (25) belegt → **warteliste** (Person wird informiert). Die Domainlisten (`domains-zugelassen.txt`, `domains-freemail.txt`) dienen nur noch als Hinweis in der Gastgeber-Mail.
3. Gastgeber erhalten je Anmeldung eine Info-Mail. Erfüllt eine Anmeldung die Teilnahmebedingungen nicht (kein Versicherer/Maklerpool/Vertrieb, falsche Ebene), erklärt Ihr sie im **Admin** für ungültig („Absagen"): Die Person erhält eine freundliche Mail; bereits gezahlte Beträge erstattet Ihr manuell.
4. Alle Mails an Teilnehmer sind in Du-Form (Beschluss 19.08.2026).
5. **Zahlungsseite** (`zahlung.php?t=TOKEN`): 450,00 € netto + 19 % USt. = 535,50 € brutto. (a) PayPal-Buttons: Order und Capture laufen serverseitig über die PayPal-REST-API (`paypal.php`), Betrag/Währung/Order-ID werden geprüft. (b) „Per Rechnung zahlen": Rechnungsnummer `25X-2026-0001` (fortlaufend), Rechnungsmail + druckbare Rechnungsseite (`rechnung.php`, „Als PDF speichern"), Zahlungsziel 14 Tage; Gastgeber erhalten Mail mit Link **Zahlung eingegangen**.
6. **Zahlungseingang** (PayPal-Capture automatisch, Rechnung per Gastgeber-Link oder Admin) → Ticketnummer `25X-CM-001`, Ticket-Mail mit QR-Code (PNG eingebettet) und allen weiteren Informationen (Ort, Zeiten, Hotel, Kontakt), druckbare Ticketseite (`ticket.php`, QR zeigt darauf).
7. **Admin** (`admin.php`, Basic-Auth): Liste, Platzzähler, Aktionen (Zulassen, Absagen, Warteliste, Zahlung eingegangen, Rechnung senden, Ticket erneut senden), CSV-Export, Löschroutine.
8. Speicherung: SQLite `anmeldung/data/anmeldungen.sqlite` (per `.htaccess` gesperrt, nicht im Repo; JSON-Fallback ohne pdo_sqlite). Mails über PHPMailer/SMTP vom Postfach `info@25-experts.de`.

## 2. Dateien in `anmeldung/`

| Datei | Zweck |
|---|---|
| `send.php` | Formular-Handler: Validierung (Feldliste v6, Frage optional), Honeypot, Rate-Limit, Origin, Speicherung, Platzvergabe, Mails, Redirect zur Zahlung |
| `aktion.php` | Gastgeber-Link aus der Rechnungs-Mail: Zahlung eingegangen (HMAC-signiert, einmalig); Zulassen/Absagen bleiben für den Admin |
| `zahlung.php`, `zahlung.js` | Zahlungsseite mit PayPal-Buttons und Rechnungsoption |
| `paypal.php` | PayPal REST v2: Order anlegen, Capture, Verifikation |
| `rechnung.php` | Rechnung als druckbare Seite (Pflichtangaben § 14 UStG) |
| `ticket.php` | Ticketseite; `?qr=svg` / `?qr=png` liefern den QR-Code |
| `admin.php`, `seite.js` | Admin-Übersicht (Basic-Auth, CSRF-geschützt), kleine Seitenhelfer (Drucken, Bestätigungen) |
| `config.example.php` | Vorlage für `config.php` (alle Schalter dokumentiert) |
| `lib/x25.php`, `lib/flow.php`, `lib/store.php` | Grundlage (Konfiguration, Mailer, HTML-Bausteine), fachlicher Ablauf + Mailtexte, Datenablage (SQLite/JSON) |
| `lib/PHPMailer.php` u. a., `lib/barcode.php` | PHPMailer (LGPL, LICENSE liegt bei), QR-Code-Generator (MIT, `LICENSE-barcode.txt`) |
| `data/domains-zugelassen.txt` | Unternehmensdomains (eine je Zeile, `#` Kommentar); seit v6 nur noch Hinweis in der Gastgeber-Mail |
| `data/domains-freemail.txt` | Freemail-Domains; seit v6 nur noch Hinweis in der Gastgeber-Mail |
| `data/.htaccess`, `data/.gitkeep` | Verzeichnis gesperrt; DB-Dateien liegen nur auf dem Server (`.gitignore`) |
| `.htaccess` | Schutz von `config.php`, `lib/`, `data/`; CSP-Ausnahme für das PayPal-SDK auf `zahlung.php`; Basic-Auth-Header für `admin.php` |

## 3. Einmalige Einrichtung (Schritt für Schritt)

Voraussetzung: Website ist nach `README-DEPLOY.md` Teil A/B deployt, PHP 8.1+ (besser 8.2/8.3), Postfach `info@25-experts.de` eingerichtet.

### 3.1 config.php anlegen
1. hPanel → Dateien → Dateimanager → `public_html/anmeldung/` → `config.example.php` öffnen, Inhalt kopieren → „Neue Datei" `config.php` → einfügen.
2. Ausfüllen (alles ohne `[TBD]`-Rest lassen):
   - **SMTP:** `SMTP_PASS` = Postfach-Passwort von `info@25-experts.de` (Host/Port/SSL sind vorbelegt).
   - **Empfänger:** `MAIL_TO` = `info@25-experts.de` (Gastgeber-Postfach für Prüf-Mails und Zahlungsmeldungen; mehrere kommagetrennt).
   - **APP_SECRET:** lange Zufallszeichenfolge (signiert die Gastgeber-Links). Erzeugen: `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` (Hostinger: Erweitert → SSH-Zugang, oder lokal). Notfalls ein 40+ Zeichen langes Passwort aus dem Passwortmanager. **Nach dem Live-Gang nicht mehr ändern** (alte Links würden ungültig).
   - **RATE_SALT:** beliebige Zufallszeichen.
   - **Admin:** `ADMIN_USER` (z. B. `gastgeber`) und `ADMIN_PASS_HASH` (siehe 3.2).
   - **Bank/Rechnung:** `BANK_IBAN`, `BANK_BIC`, `BANK_NAME`, `BANK_HOLDER`, `INVOICE_TAX_ID` oder `INVOICE_VAT_ID` (mindestens eins ist Pflicht), `INVOICE_ISSUER_*` (Firmierung/Anschrift), `INVOICE_YEAR` (2026).
   - **PayPal:** siehe 3.3; bis dahin `PAYPAL_ENV = 'off'` (Zahlungsseite zeigt dann nur „Per Rechnung zahlen").
   - **Edition:** `EDITION_*` sind für 25 CHANGE MANAGEMENT EXPERTS vorbelegt; `EDITION_HOTEL`, `EDITION_KONTAKT` ergänzen.
   - `MAIL_TRANSPORT` bleibt `'smtp'`.
3. Speichern. `config.php` ist per `.htaccess` gesperrt und von Git ignoriert (überlebt jeden Deploy).

### 3.2 Admin-Passwort-Hash erzeugen
Im Terminal (lokal oder Hostinger-SSH):
```
php -r 'echo password_hash("IhrLangesPasswort", PASSWORD_DEFAULT), PHP_EOL;'
```
Ausgabe (`$2y$10$…`) als `ADMIN_PASS_HASH` in `config.php` eintragen. Das Klartext-Passwort merken (Passwortmanager), nur den Hash speichern. Ohne PHP-Terminal: Datei `hash.php` mit genau dieser Zeile per Dateimanager anlegen, einmal im Browser aufrufen, Hash kopieren, **Datei sofort löschen**.

### 3.3 PayPal einrichten (Business-Konto → Developer-App → Sandbox testen → Live)
1. **PayPal-Business-Konto**: paypal.com → Geschäftskonto für die 25 EXPERTS UG anlegen bzw. das bestehende auf „Business" umstellen; Bankkonto verknüpfen und bestätigen. Zahlungen gehen dorthin. [TBD: Kontoinhaber = UG i. G.; nach HR-Eintragung Firmendaten in PayPal aktualisieren]
2. **Developer-Dashboard**: developer.paypal.com → mit dem Business-Konto anmelden → „Apps & Credentials".
3. **Sandbox-App**: Reiter **Sandbox** → „Create App" → Name z. B. `25 EXPERTS Anmeldung` → Typ „Merchant" → Create. Angezeigt werden **Client ID** und **Secret** (Secret ggf. „Show"). Unter Sandbox → „Accounts" gibt es Testkäufer (Personal) mit Passwort für Testzahlungen.
4. **In config.php eintragen** (Dateimanager, nicht per Chat/E-Mail verschicken, nie ins Repo):
   ```
   define('PAYPAL_ENV', 'sandbox');
   define('PAYPAL_CLIENT_ID', 'AS…');   // Sandbox-Client-ID
   define('PAYPAL_SECRET', 'EK…');      // Sandbox-Secret
   ```
5. **Sandbox-Test**: Test-Anmeldung mit einer Allowlist-Domain (oder im Admin zulassen) → Zusage-Mail → Zahlungslink öffnen → PayPal-Button → mit dem Sandbox-Testkäufer bezahlen → Ticket-Mail mit QR kommt, im Admin steht „bezahlt / paypal". Falls die Buttons nicht erscheinen: Browser-Konsole prüfen (CSP-Fehler → `anmeldung/.htaccess`, Files zahlung.php), Client-ID prüfen.
6. **Live schalten**: Developer-Dashboard → Reiter **Live** → App anlegen (oder die Live-Credentials der App anzeigen) → **Live-Client-ID und Live-Secret** (andere Werte als Sandbox!) in `config.php`, dann `PAYPAL_ENV = 'live'`. Eine echte Zahlung über 535,50 € testen (eigene Karte) und anschließend im PayPal-Konto erstatten oder als erste Buchung behalten.
7. Empfehlungen: im PayPal-Konto „Zahlungen ohne Konto (Gastzahlung)" aktiv lassen (Kreditkarte ohne PayPal-Konto), Währung EUR; das Secret bei Verdacht im Dashboard neu erzeugen und in `config.php` ersetzen.
8. Zahlungssicherheit: Der Betrag wird nie aus dem Browser übernommen, sondern serverseitig aus `PRICE_NET`/`VAT_RATE` gebildet; das Capture wird serverseitig ausgeführt und auf Status `COMPLETED`, Betrag `535.50`, Währung `EUR` und die zur Anmeldung gespeicherte Order-ID geprüft. Erst dann gilt die Zahlung.

### 3.4 Bankdaten und Rechnungsangaben
`BANK_*`, `INVOICE_TAX_ID`/`INVOICE_VAT_ID`, `INVOICE_ISSUER_NAME`/`_ADDRESS` in `config.php`. Rechnungsnummern laufen fortlaufend `25X-2026-0001, -0002, …` (Zähler in der Datenablage; auch nach dem Löschen von Anmeldungen wird weitergezählt). Zahlungsziel `PAYMENT_DAYS` = 14. [TBD: USt-Ausweis (19 %) und Kleinunternehmerregelung mit dem Steuerberater bestätigen; Storno-/Teilnahmebedingungen verlinken]

### 3.5 Allowlist pflegen
`anmeldung/data/domains-zugelassen.txt`: eine Domain je Zeile, Subdomains gelten automatisch mit, `#` leitet Kommentare ein. Startbestand: ca. 60 Domains von Versicherern, Maklerpools und Vertrieben als Vorschlag `[TBD: prüfen]`. Pflege per Hostinger-Dateimanager (wirkt sofort) oder im Repo (Deploy). Freemail-Liste `domains-freemail.txt` analog. Domains, die weder in der einen noch in der anderen Liste stehen, gehen in die manuelle Prüfung. Tipp: Nach jeder manuellen Zulassung die Firmendomain in die Allowlist aufnehmen, dann werden Kolleg*innen desselben Hauses künftig automatisch zugelassen.

### 3.6 Test der Strecke ohne echte Mails
Lokal: `python3 07-deploy/test/run_tests.py` (116 Prüfungen: Zulassung per Allowlist/Freemail/unbekannt, Zulassen-/Absagen-Links, Rechnung mit Pflichtangaben, Zahlung markieren → Ticket mit QR, PayPal gegen Mock-Server, Admin-Auth/CSRF, Warteliste ab MAX_SEATS, Löschroutine, JSON-Fallback, Rate-Limit, Injection, Honeypot). Auf dem Server: eine Test-Anmeldung mit eigener Adresse, im Admin ausprobieren, danach Testdatensätze im Admin löschen (Löschroutine oder Absage).

## 4. Was die Gastgeber täglich tun

- **Postfach `info@25-experts.de` lesen.** Drei Mailtypen:
  1. „Neue Anmeldung (in Prüfung)": Angaben prüfen (Unternehmen, Rolle, Domain, LinkedIn) → **Zulassen** oder **Absagen** klicken → auf der Bestätigungsseite bestätigen (bei Absage Grund wählen: nicht Zielgruppe / Ebene / voll). Die Person erhält automatisch Zusage + Zahlungsaufforderung bzw. die Absage. Ziel: innerhalb von `REVIEW_DAYS` (3) Werktagen.
  2. „Rechnung 25X-2026-… erstellt": Kontoauszug beobachten; sobald der Betrag da ist → Link **Zahlung eingegangen** klicken → Ticket geht automatisch raus. (Alternativ im Admin „Zahlung eingegangen".)
  3. „Neue Anmeldung (zugelassen)" / „Zahlung eingegangen (paypal)": nur zur Kenntnis.
- **Admin** `https://25-experts.de/anmeldung/admin.php` (Benutzer/Passwort aus config.php): Überblick, Platzzähler „x / 25", Warteliste nachrücken lassen (Zulassen), Ticket erneut senden, CSV-Export für die Teilnehmerliste/Namensschilder.
- **Rückfragen** beantworten: Auf jede System-Mail kann die Person antworten; die Antwort landet in `MAIL_TO` (Reply-To). Antworten der Gastgeber auf Benachrichtigungen gehen direkt an die Person (Reply-To gesetzt).
- **Zahlungserinnerung** nach 14 Tagen ist manuell (Admin zeigt Zahlungsweg/-status; Rechnungslink im Admin). [TBD: Erinnerungsroutine, falls gewünscht]

## 5. Datenschutz

- Gespeichert werden nur die Formularangaben plus Statusfelder, Rechnungs-/Ticketnummern und PayPal-Order-/Capture-IDs (keine Zahlungsdaten). Kein IP-Klartext (Rate-Limit nur als Hash, kurzlebig). Logs enthalten keine personenbezogenen Daten.
- Datenablage `anmeldung/data/` ist per `.htaccess` gesperrt (Dateirechte 600). Optional `DATA_DIR` außerhalb von `public_html` legen.
- Löschroutine im Admin („Daten der Edition löschen", nach Datum). Rechnungsrelevante Daten vorher als CSV sichern (Aufbewahrungspflicht 10 Jahre für Rechnungen [TBD: mit Steuerberater klären], Rest nach der Edition löschen).
- Die Datenschutzerklärung (`datenschutz.html`) nennt bereits Hostinger, den Zweck (Zugehörigkeitsprüfung anhand der Unternehmensadresse, Zusage, Zahlungsaufforderung, Ticket) und PayPal („Wählen Sie die Zahlung per PayPal, verarbeitet PayPal Ihre Zahlungsdaten in eigener Verantwortung; es gelten die Datenschutzhinweise von PayPal.") mit `[TBD: PayPal-Passus anwaltlich prüfen]`. Vorschlag zur Ergänzung, falls gewünscht: „Beim Aufruf der Zahlungsseite lädt Ihr Browser das PayPal-Skript von paypal.com; dabei erhält PayPal (Europe) S.à r.l. et Cie, S.C.A., Luxemburg, Ihre IP-Adresse. Rechtsgrundlage Art. 6 Abs. 1 lit. b DSGVO."
- AV-Vertrag mit Hostinger (Hosting + Postfächer) abschließen; PayPal ist eigenständiger Verantwortlicher (kein AV-Vertrag nötig).

## 6. Sicherheit (Kurzfassung)

Formular: Pflichtfelder/Werte v5, E-Mail-Validierung, Honeypot, Origin-Prüfung, Header-Injection-Schutz, Rate-Limit (5/Stunde je IP-Hash). Gastgeber-Links: HMAC-SHA256 mit `APP_SECRET`, Einmal-Nonce (nach Ausführung ungültig, Zulassen/Absagen derselben Mail schließen sich aus), Ausführung nur per POST (Mail-Scanner lösen nichts aus). Teilnehmer-Links: 128-Bit-Zufallstoken (kein Erraten, keine fortlaufenden IDs). PayPal: Betrag/Währung/Status/Order-Zuordnung serverseitig geprüft, Secret nur in `config.php`. Admin: Basic-Auth mit `password_hash`, alle Aktionen per POST mit CSRF-Token, keine Aktionen per GET. CSP: PayPal-SDK nur auf `zahlung.php` erlaubt, keine Inline-Skripte.

## 7. Häufige Fehler

| Symptom | Ursache / Lösung |
|---|---|
| Formular meldet „Konfiguration fehlt" | `config.php` fehlt oder heißt anders |
| „konnte nicht übertragen werden" | SMTP-Zugang prüfen (Postfachadresse als Benutzer, Port 465/SSL); Hostinger-Fehlerlog |
| Gastgeber-Link zeigt „bereits verwendet" | Link war schon ausgeführt oder eine andere Aktion hat den Datensatz verändert → Admin nutzen |
| Gastgeber-Link zeigt „ungültig" | `APP_SECRET` wurde geändert oder Link beschädigt (Zeilenumbruch) → Admin nutzen |
| Admin fragt immer wieder nach Passwort | Hash falsch eingetragen (muss mit `$2y$` beginnen) oder Authorization-Header kommt nicht an (Rewrite-Regel in `anmeldung/.htaccess`, „Basic-Auth" – Hostinger-Support fragen, ob `mod_rewrite` aktiv ist) |
| PayPal-Buttons fehlen | `PAYPAL_ENV`/`PAYPAL_CLIENT_ID` leer oder Browser-Konsole zeigt CSP-Fehler |
| „PayPal ist gerade nicht erreichbar" | Client-ID/Secret passen nicht zur Umgebung (Sandbox ≠ Live) oder ausgehende HTTPS-Verbindung/cURL blockiert |
| Rechnungsnummer springt | Zähler zählt fortlaufend weiter, auch nach gelöschten Datensätzen (gewollt) |
| Datenablage-Fehler 500 | `anmeldung/data/` muss für PHP beschreibbar sein (Dateimanager: Rechte 750/755); ohne `pdo_sqlite` `STORE_BACKEND = 'json'` setzen |

## 8. Annahmen / offene Punkte [TBD]

- Prüffrist 3 Werktage (`REVIEW_DAYS`), Zahlungsziel 14 Tage, Ticketpräfix `25X-CM-`, Rechnungspräfix `25X-2026-`.
- Platzzählung: `SEATS_COUNT = 'zugelassen'` (zugelassene inkl. bezahlte belegen einen Platz). Alternative `'bezahlt'`.
- Allowlist-Startbestand ist ein Vorschlag; Steuernummer/USt-IdNr., IBAN/BIC/Bank, Hotel, Kontaktnummer, Rechnungsanschrift der Teilnehmer (aktuell: Unternehmen + Name laut Formular; abweichende Anschrift per E-Mail), Storno-Hinweis auf der Rechnung, PayPal-Kontoinhaber (UG i. G.).
- Datenschutz-Satz zu PayPal-Skript (Vorschlag oben), Aufbewahrungsfristen.
