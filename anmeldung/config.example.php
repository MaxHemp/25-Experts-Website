<?php
/**
 * 25 EXPERTS – Konfiguration der Anmeldestrecke (anmeldung/*.php)
 *
 * ANLEITUNG: Diese Datei als  config.php  im selben Ordner speichern (Hostinger: Dateimanager,
 * public_html/anmeldung/config.php) und die Werte eintragen. config.php liegt NICHT im Git-Repo
 * (.gitignore) und wird durch anmeldung/.htaccess vor direktem Aufruf geschützt.
 * Alles, was mit [TBD] markiert ist, muss vor dem Live-Gang ersetzt werden. Schritt-für-Schritt: README-ANMELDUNG.md.
 * Geheimnisse (SMTP_PASS, PAYPAL_SECRET, APP_SECRET, ADMIN_PASS_HASH) niemals ins Repo, niemals per Chat/E-Mail verschicken.
 */

// --- SMTP (Hostinger-Postfach) ------------------------------------------------------------
// Postfach info@25-experts.de ist bei Hostinger bereits eingerichtet; hier dessen Zugangsdaten eintragen.
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);              // 465 = SSL/SMTPS (Hostinger-Standard). Alternativ 587 mit SMTP_SECURE 'tls'.
define('SMTP_SECURE', 'ssl');          // 'ssl' für Port 465, 'tls' für Port 587
define('SMTP_USER', 'info@25-experts.de');   // vollständige E-Mail-Adresse des Postfachs
define('SMTP_PASS', 'HIER-DAS-POSTFACH-PASSWORT');

// --- Absender / Empfänger ------------------------------------------------------------------
define('MAIL_FROM', 'info@25-experts.de');   // muss zum SMTP-Postfach passen (sonst lehnt Hostinger ab)
define('MAIL_FROM_NAME', '25 EXPERTS');
define('MAIL_TO', 'info@25-experts.de');     // Gastgeber-Postfach: Benachrichtigung je Anmeldung, Zahlungsmeldungen; mehrere: kommagetrennt
define('MAIL_TO_NAME', '25 EXPERTS Anmeldungen');
// Antwortadresse für Mails an Anmelder (wenn sie auf Bestätigung/Zusage/Rechnung/Ticket antworten). Leer lassen = MAIL_TO.
define('MAIL_CONFIRM_REPLY_TO', '');
define('HOSTS_SIGNATURE', 'Maximilian Hempel und Simon Moser');   // Grußformel in allen Mails an Anmelder

// --- Edition / Website ---------------------------------------------------------------------
define('EDITION', '25 CHANGE MANAGEMENT EXPERTS · 03./04.12.2026 · Köln');   // Kopfzeile in Mails/Seiten
define('EDITION_NAME', '25 CHANGE MANAGEMENT EXPERTS');
define('EDITION_DATUM', '3. und 4. Dezember 2026');
define('EDITION_ORT', 'Köln');
define('EDITION_LEISTUNGSDATUM', '03.–04.12.2026');   // Leistungsdatum auf der Rechnung (Veranstaltungstage) und Leistungsbeschreibung
define('EDITION_VENUE', 'SESSEL HUB Rheinauhafen, Kranhaus Nord (Erdgeschoss), Im Zollhafen 12, 50678 Köln');   // Ticket/Zusage
define('EDITION_ZEITEN', 'Tag 1: 09:45 bis 17:15 Uhr, anschließend Abend (Aperitif 18:15 Uhr, Dinner 19:00 Uhr) · Tag 2: 09:30 bis 13:30 Uhr');
define('EDITION_HOTEL', 'Hotel buchen Sie bitte selbst; ein Zimmerkontingent ist angefragt [TBD: Hotel, Stichwort], aber nicht garantiert.');
define('EDITION_KONTAKT', 'info@25-experts.de · [TBD: Kontaktnummer]');
define('SITE_URL', 'https://25-experts.de/');            // mit abschließendem Slash
define('LANDING_PATH', 'editionen/change-management/');  // relativ zu SITE_URL; danke.html liegt darin
define('ANMELDUNG_URL', SITE_URL . 'anmeldung/');        // öffentliche URL dieses Ordners (Links in Mails: zahlung.php, ticket.php, aktion.php, admin.php)
define('LOGO_URL', SITE_URL . 'assets/img/25experts-logo-horizontal.png');   // Logo in HTML-Mails/Seiten ('' = ohne Logo)

// Impressumszeile im Fuß aller Mails und Seiten (Pflichtangaben im Geschäftsverkehr; nach HR-Eintragung ergänzen)
define('MAIL_FOOTER', '25 EXPERTS UG (haftungsbeschränkt) i. G. · Sitz Köln · Moitzfeld 17, 51429 Bergisch Gladbach · Geschäftsführer: Maximilian Hempel · Amtsgericht Köln (Eintragung beantragt)');

// --- Plätze (seit v6 keine Vorprüfung: jede gültige Anmeldung ist sofort zugelassen) --------
define('MAX_SEATS', 25);               // Plätze; ist das Kontingent belegt, landen neue Anmeldungen auf der Warteliste
define('SEATS_COUNT', 'zugelassen');   // was zählt als belegt: 'zugelassen' (zugelassen inkl. bezahlt) oder 'bezahlt' (nur bezahlte)
// Domainlisten (eine Domain je Zeile, # = Kommentar): seit v6 nur noch Hinweis in der Gastgeber-Mail.
// define('DOMAINS_ALLOW_FILE', __DIR__ . '/data/domains-zugelassen.txt');
// define('DOMAINS_FREEMAIL_FILE', __DIR__ . '/data/domains-freemail.txt');

// --- Preis / Rechnung ---------------------------------------------------------------------
define('PRICE_NET', 450.00);           // Teilnahmebeitrag netto in EUR (450,00 € netto + 19 % USt. = 535,50 € brutto)
define('VAT_RATE', 0.19);              // Umsatzsteuersatz [TBD: USt-Ausweis mit Steuerberater bestätigen]
define('CURRENCY', 'EUR');
define('PAYMENT_DAYS', 14);            // Zahlungsziel der Rechnung in Tagen
define('INVOICE_PREFIX', '25X-');      // Rechnungsnummer: 25X-2026-0001, fortlaufend (Zähler in der Datenablage)
define('INVOICE_YEAR', '2026');        // Jahreszahl in der Rechnungsnummer
define('TICKET_PREFIX', '25X-CM-');    // Ticketnummer: 25X-CM-001, fortlaufend
define('INVOICE_ISSUER_NAME', '25 EXPERTS UG (haftungsbeschränkt) i. G.');   // Aussteller (Pflichtangabe § 14 UStG)
define('INVOICE_ISSUER_ADDRESS', 'Moitzfeld 17 · 51429 Bergisch Gladbach');
define('INVOICE_TAX_ID', 'Steuernummer: [TBD]');      // Steuernummer ODER USt-IdNr. ist Pflicht
define('INVOICE_VAT_ID', 'USt-IdNr.: [TBD]');
// Bankverbindung für Rechnungszahlungen [TBD]
define('BANK_HOLDER', '25 EXPERTS UG (haftungsbeschränkt)');
define('BANK_IBAN', '[TBD: IBAN]');
define('BANK_BIC', '[TBD: BIC]');
define('BANK_NAME', '[TBD: Bank]');

// --- PayPal (REST API v2 + JS-SDK) --------------------------------------------------------
// 'off' = PayPal ausgeblendet (nur Rechnung); 'sandbox' = Testumgebung (developer.paypal.com, Sandbox-App); 'live' = echtes Geld.
// Client-ID und Secret der jeweiligen App (Sandbox und Live haben eigene Zugangsdaten!). Anleitung: README-ANMELDUNG.md.
define('PAYPAL_ENV', 'off');
define('PAYPAL_CLIENT_ID', '');
define('PAYPAL_SECRET', '');
// define('PAYPAL_API_BASE', '');   // nur für automatische Tests (Mock-Server); in Produktion nicht setzen

// --- Admin (anmeldung/admin.php UND /verwaltung/, HTTP-Basic-Auth) ------------------------
// Gilt für die Anmeldungs-Übersicht und die Editions-Verwaltung (/verwaltung/, README-VERWALTUNG.md).
// Passwort-Hash erzeugen (Terminal oder Hostinger-SSH):  php -r 'echo password_hash("IhrPasswort", PASSWORD_DEFAULT), PHP_EOL;'
define('ADMIN_USER', 'gastgeber');
define('ADMIN_PASS_HASH', '');         // z. B. '$2y$10$…' ; leer = Admin und Verwaltung gesperrt

// --- Schutz -------------------------------------------------------------------------------
// APP_SECRET signiert die Gastgeber-Links (Zahlung eingegangen) und die Admin-Formulare (HMAC).
// Lange Zufallszeichenfolge (mind. 32 Zeichen), z. B.:  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
define('APP_SECRET', 'BITTE-LANGE-ZUFAELLIGE-ZEICHENFOLGE-EINTRAGEN');
define('RATE_LIMIT', 5);               // höchstens N Anmeldungen je IP-Hash …
define('RATE_WINDOW', 3600);           // … je Zeitfenster in Sekunden (Sperrdateien in sys_get_temp_dir()/25x-anmeldung/)
define('RATE_SALT', 'BITTE-ZUFAELLIGE-ZEICHENFOLGE-EINTRAGEN');   // beliebige Zufallszeichen; macht den IP-Hash nicht rückrechenbar
define('CHECK_ORIGIN', true);          // Origin-Header (falls gesendet) muss zu SITE_URL passen

// --- Datenablage --------------------------------------------------------------------------
// SQLite in anmeldung/data/anmeldungen.sqlite (Verzeichnis per .htaccess gesperrt, nicht im Repo). Fehlt pdo_sqlite auf
// dem Server, wird bei 'auto' automatisch data/anmeldungen.json (mit Dateisperre) verwendet. 'sqlite' | 'json' erzwingen.
define('STORE_BACKEND', 'auto');
// define('DATA_DIR', __DIR__ . '/data');   // anderes Verzeichnis (z. B. außerhalb von public_html), falls gewünscht

// --- Versandart ---------------------------------------------------------------------------
// 'smtp' = echter Versand (Produktion). 'file' = Testmodus: Mails werden NICHT verschickt, sondern als .eml
// in MAIL_DUMP_DIR abgelegt (nur für lokale Tests, nie auf dem Live-Server verwenden).
define('MAIL_TRANSPORT', 'smtp');
define('MAIL_DUMP_DIR', sys_get_temp_dir() . '/25x-mails');
