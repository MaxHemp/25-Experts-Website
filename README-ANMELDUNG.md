# 25 EXPERTS – Anmeldestrecke einrichten und betreiben

Stand: 12.11.2026. PHP 8.1+, Hostinger, PHPMailer/SMTP, SQLite mit JSON-Fallback.
Der aktuelle Ablauf ist: **kostenfreie Anfrage → persönliche Zusage → ausdrücklich verbindliche Buchung mit Rechnungsdaten → Zahlung → Ticket**.
Eine Anfrage oder Zusage allein löst keine Zahlungspflicht aus. Persönliche Einladungen überspringen die fachliche Prüfung, nicht die separate Buchung.

## 1. Tatsächlicher Ablauf

1. `/editionen/{slug}/anmeldung` zeigt drei Schritte: Kontaktdaten → Anliegen → Prüfen und senden. Erforderlich sind Name, Unternehmen, Rolle, gültige E-Mail, Unternehmenstyp, aktuelle Frage sowie die Bestätigungen zur Anfrage und zum Datenschutz. Telefon und LinkedIn sind optional. Das alte Feld `name` wird weiterhin akzeptiert; die Ebene ist im aktuellen Formular kein Pflichtfeld.
2. `anmeldung/send.php` prüft Eingaben, Honeypot, Origin und Rate-Limit. Reguläre neue Anfragen erhalten durch `x25_new_admission()` den Status `pruefung`, auch bei voller Edition. Sie werden nicht anhand einer E-Mail-Domain automatisch zugelassen. Der Teilnehmer erhält eine Eingangsbestätigung, das Team eine Prüf-Mail; persönliche Rückmeldung innerhalb von zwei Werktagen (Montag bis Freitag, keine Feiertagsberechnung).
3. Persönliche Einladungen sind an Edition, E-Mail-Adresse und Gültigkeitszeitraum gebunden und einmalig verwendbar. Ist die Einladung gültig, entscheidet die Kapazität: `zugelassen` oder `warteliste`. Bei der Vergabe gelten das Editionskontingent und höchstens zwei zugelassene Teilnehmer pro Unternehmen. Eine Einladung ersetzt keine verbindliche Buchung.
4. Das Team prüft reguläre Anfragen im Admin und sagt zu, ab oder setzt auf die Warteliste. Auch die manuelle Zulassung prüft Kapazität und Unternehmenslimit unter einer Datensperre. Die Zusage-Mail enthält den persönlichen Buchungslink `zahlung.php?t=TOKEN`. Die Danke-Seite verwendet den kurzlebigen serverseitigen Eingangsbeleg für den Status; ein URL-Parameter allein bestätigt nichts.
5. **Erst nach Zusage** zeigt `zahlung.php` das Buchungsformular. Rechnungsempfänger und Rechnungsadresse sind Pflichtfelder, Bestellnummer und Rechnungskontakt-E-Mail optional. Erst der gültige POST mit Buchungssignatur, Teilnahmebedingungen und Klick auf **„Zahlungspflichtig buchen“** speichert diese vier Angaben zusammen mit `booking_confirmed_at`. Ohne diesen Schritt sind Rechnung und PayPal für neue Anfragen gesperrt. Die erste bestätigte Buchung wird durch einen wiederholten POST nicht überschrieben.
6. Danach wählt der Teilnehmer PayPal (falls konfiguriert) oder Rechnung. Preis und Kontingent stammen aus der jeweiligen Edition; für Change Management am 2./3. Dezember 2026 in Köln beträgt der Preis 450 € netto zuzüglich 19 % USt., also 535,50 € brutto. PayPal-Order/Capture werden serverseitig geprüft. Bei Rechnung werden eine fortlaufende Rechnungsnummer, Datum und Zahlungsziel vergeben. Der optionale Rechnungskontakt erhält erst jetzt eine Rechnungskopie.
7. Zahlungseingang (PayPal automatisch, Überweisung durch Gastgeber-Link oder Admin) führt zur Ticketnummer und Ticket-Mail mit QR-Code. Zusage und verbindliche Buchung werden getrennt gespeichert; `status=zugelassen` allein bedeutet noch nicht „gebucht“ oder „bezahlt“.

JSON-Antwort von `send.php`: `{ok:true,status:"pruefung"|"zugelassen"|"warteliste",pay_url?}`. Nur bei sofortiger Zusage per Einladung gibt es einen Buchungslink. Ohne JavaScript folgt ein 303-Redirect zur Buchung oder Danke-Seite. Die serverseitige Sitzung enthält einen Eingangsbeleg für zehn Minuten, keine Rechnungsdaten.

## 2. Rechnungsdaten: bewusste Grenze der Anfrage

Für die fachliche Prüfung werden keine Rechnungsdaten benötigt. Das aktuelle Formular erhebt sie deshalb nicht. `send.php` übernimmt auch aus alten oder manipulierten Clients keine Rechnungswerte; die vier Felder bleiben bis zur Buchung leer. Ihre Validierung erfolgt auf der Buchungsseite. Es wird keine frühzeitige Speicherung mehr versprochen.

| Variante | Nutzen | Aufwand und Auswirkung |
|---|---|---|
| Erfassung bei verbindlicher Buchung (umgesetzt) | Keine Rechnungsanschrift oder Buchhaltungskontakte für abgelehnte oder nicht gebuchte Anfragen; ein klarer Bestätigungsschritt | Nach Zusage einmal ausfüllen; vorhandene Kontaktdaten dienen als Empfängervorschlag |
| Optionale Speicherung schon bei der Anfrage (nicht umgesetzt) | Angaben könnten bei der späteren Buchung vorausgefüllt werden | Zusätzliche Daten vor der Auswahl; benötigt eindeutige Formulierung, Validierung, geschützte Speicherung, Löschregeln und erneute Bestätigung bei Buchung; darf niemals automatisch eine Buchung auslösen |

Die Änderung bestätigt den im Backend bereits implementierten Ablauf; sie stellt keine nachträgliche Entscheidung über produktive Bestandsdaten dar. Historische Datensätze ohne `admission_mode=persoenlich` behalten die bisherige Kompatibilitätsbehandlung. Keine Migration, Löschung oder Änderung produktiver Teilnehmerdaten gehört zu dieser Korrektur.

## 3. Dateien und Konfiguration

| Datei | Aufgabe |
|---|---|
| `edition/anmeldung.php`, `assets/js/anmeldung.js` | Drei Formularschritte, Validierung, JSON-POST; HTML-POST ohne JavaScript |
| `anmeldung/send.php` | Kostenfreie Anfrage, persönliche Prüfung/Einladung, Speicherung, Bestätigung |
| `anmeldung/lib/curation.php` | Auswahl, Einladung, Unternehmens-/Platzlimit, Buchungssperre |
| `edition/danke.php`, `anmeldung/lib/receipt.php` | Statusgerechte Eingangsbestätigung aus serverseitigem Beleg |
| `anmeldung/zahlung.php`, `zahlung.js`, `paypal.php` | Verbindliche Buchung mit Rechnungsdaten, danach Zahlungswege |
| `anmeldung/lib/flow.php` | Zulassung, Absage, Warteliste, Rechnung, Zahlung, Ticket und Mailtexte |
| `anmeldung/lib/store.php` | SQLite oder JSON unter exklusiver Transaktionssperre |
| `anmeldung/admin.php`, `aktion.php` | Teamverwaltung und signierte Zahlungseingangslinks |
| `anmeldung/rechnung.php`, `ticket.php` | Druckbare Rechnung und Ticket |
| `anmeldung/config.example.php` | Vorlage für ausschließlich serverseitige `config.php` |
| `edition/seed/*.json`, `edition/experience.json`, `edition/texte.json` | Erstkonfiguration und Textquellen; bestehende Editionen liegen separat auf dem Server |

`config.php` liegt nicht im Git. Einrichtung und Hosting: `README-DEPLOY.md`.

- SMTP: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`; Produktion mit `MAIL_TRANSPORT='smtp'`.
- Absender und Team: `MAIL_FROM`, `MAIL_TO`, optional `MAIL_CONFIRM_REPLY_TO`, `HOST_SIGNATURE`, `MAIL_FOOTER`.
- Sicherheit: zufällige `APP_SECRET` und `RATE_SALT`, `ADMIN_USER` und `ADMIN_PASS_HASH`; Hash lokal mit `php -r 'echo password_hash("IhrPasswort", PASSWORD_DEFAULT), PHP_EOL;'` erzeugen. `APP_SECRET` nach Freigabe bestehender Links nicht beiläufig ändern.
- Zahlung: `BANK_*`, `INVOICE_ISSUER_*`, `INVOICE_TAX_ID`/`INVOICE_VAT_ID`, `INVOICE_YEAR`, `PAYMENT_DAYS`. Editionspreis und Kontingent in `/verwaltung/` prüfen; `PRICE_NET` und `MAX_SEATS` sind Fallbacks.
- PayPal: `PAYPAL_ENV='off'|'sandbox'|'live'`, zur Umgebung passende `PAYPAL_CLIENT_ID` und `PAYPAL_SECRET`. `PAYPAL_API_BASE` ausschließlich für lokale Mocks verwenden. Keine echten Zugangsdaten in Tests.
- Speicherung: `STORE_BACKEND='auto'|'sqlite'|'json'`, optional `DATA_DIR` außerhalb des Webroots. `SEATS_COUNT` wird vom persönlichen Zulassungslimit nicht ausgewertet: dieses zählt `zugelassen`, unabhängig von Buchung/Zahlung.
- Domainlisten sind keine Freigaberegeln. Auch bekannte Versichererdomains durchlaufen die persönliche Prüfung.

## 4. Betrieb und Schutz

Das Team bearbeitet neue Anfragen im Admin, beobachtet Zahlungseingänge und beantwortet Rückfragen. Zusage und Rechnung haben getrennte Zeitpunkte. Nach einer Absage bereits gebuchter/gezahlter Teilnahmen sind Rückabwicklung und Erstattung manuell zu prüfen. CSV-Export, Löschfunktion und erneuter Ticketversand stehen im Admin bereit. Löschungen und produktive Tests brauchen einen gesondert abgestimmten Auftrag.

`config.php`, `lib/`, `data/` und interne Dateien sind per `.htaccess` gesperrt. Teilnehmerlinks enthalten geheime Zufallstoken; Gastgeberlinks sind signiert. Origin, Honeypot, Validierung und Rate-Limit schützen die Anfrage. Rechnungsfelder werden begrenzt/bereinigt und bei HTML-Ausgabe maskiert. Die Datenablage enthält personenbezogene Angaben. Auch `mail.log` und Maildateien enthalten Empfängeradressen; sie sind keine anonymen technischen Logs. Aufbewahrung und Löschung mit den bestehenden Datenschutz-/Buchhaltungsregeln abstimmen.

Bestehende Editionstexte werden nicht einfach durch geänderte Seeds ersetzt. Der fachliche Rechnungsdatenhinweis wird deshalb zusätzlich in `x25ed_txt()` vereinheitlicht, ohne gespeicherte Editionen oder Teilnehmer zu migrieren. Änderungen aus einem übergeordneten Website-Generator müssen diese Korrekturen übernehmen, damit ein späterer Export sie nicht zurücksetzt.

## 5. Automatisierte Prüfung ohne echte Teilnehmer

Voraussetzung: Python 3 und PHP 8.1+ mit `mbstring`, PDO und `pdo_sqlite` (Standardmodule `ctype`, `iconv`, `session`).

```sh
python3 -m unittest discover -s tests -v
```

Optional `PHP_BINARY=/pfad/zu/php` setzen. Jeder Test erstellt eine frische temporäre Website-Kopie mit eigener Konfiguration, Datenablage, Sitzung und Mailablage. Es werden weder eine vorhandene `config.php` noch Teilnehmerdaten kopiert. HTTP läuft ausschließlich auf `127.0.0.1`, Mails werden als `.eml` abgelegt (`MAIL_TRANSPORT='file'`), alle Adressen verwenden `example.test`. PayPal ist nur mit fiktiven Zugangsdaten und einem lokalen, nicht externen API-Ziel eingerichtet; getestet wird die Sperre vor Buchung, keine echte Zahlung.

Die Suite prüft beide Speicherbackends: öffentliche Formularfelder und veraltete Editionstexte, keine Rechnungsdatenübernahme bei Anfrage, Pflichtfelder, JSON/HTML-POST, Duplikate, persönliche Prüfung, gültige/ungültige/abgelaufene/verbrauchte Einladungen, Kapazität und Unternehmenslimit, Statusbeleg der Danke-Seite, Buchungssignatur/Bedingungen/Adressvalidierung, Speicherung bei Buchung, Wiederholungs-POST sowie den Ablauf Zusage → Buchung → Rechnung inklusive Rechnungskontakt → fiktiv bestätigter Zahlungseingang → Ticket. Die Suite ersetzt keinen Test des echten SMTP, PayPal-Captures oder der Apache-Konfiguration.

GitHub Actions führt die Suite mit PHP 8.1 und 8.3 aus. Der Workflow enthält kein Deployment und keine produktiven Zugangsdaten.

## 6. Prüfung vor einer separat freizugebenden Veröffentlichung

Nur die öffentliche Formularseite wurde am 12.11.2026 lesend geprüft: drei Schritte, keine Rechnungsfelder, kostenfreie Anfrage. Produktive `config.php`, gespeicherte Editionen, Teilnehmerbestand, SMTP- und PayPal-Konfiguration wurden nicht eingesehen oder verändert. Der produktive POST-Ablauf ist damit nicht als getestet ausgewiesen.

Vor einem Deployment nach ausdrücklicher Freigabe: aktive Edition und Kontingent sowie den Versionsstand auf dem Host prüfen; anschließend in einer isolierten Staging-Kopie mit Dateimails testen. Ein Merge, Deployment oder Eingriff in produktive Teilnehmerdaten ist mit diesem Branch nicht beauftragt.
