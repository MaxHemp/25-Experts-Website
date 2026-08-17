<?php
/**
 * 25 EXPERTS – Konfiguration des Anmeldeformular-Handlers (anmeldung/send.php)
 *
 * ANLEITUNG: Diese Datei als  config.php  im selben Ordner speichern (Hostinger: Dateimanager,
 * public_html/anmeldung/config.php) und die Werte eintragen. config.php liegt NICHT im Git-Repo
 * (.gitignore) und wird durch anmeldung/.htaccess vor direktem Aufruf geschützt.
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
define('MAIL_TO', 'info@25-experts.de');   // Empfänger der Anmeldungen (vorerst); mehrere: kommagetrennt
define('MAIL_TO_NAME', '25 EXPERTS Anmeldungen');
// Antwortadresse für die Bestätigungsmail an den Anmelder (wenn er auf die Bestätigung antwortet).
// Leer lassen = MAIL_TO.
define('MAIL_CONFIRM_REPLY_TO', '');

// --- Edition / Website ---------------------------------------------------------------------
define('EDITION', '25 CHANGE MANAGEMENT EXPERTS · 03./04.12.2026 · Köln');
define('EDITION_NAME', '25 CHANGE MANAGEMENT EXPERTS');
define('EDITION_DATUM', '3. und 4. Dezember 2026');
define('EDITION_ORT', 'Köln');
define('EDITION_BETRAG', '450 €');     // netto, für den Satz „Die Rechnung über … netto erhalten Sie erst nach unserer Zusage."
define('SITE_URL', 'https://25-experts.de/');            // mit abschließendem Slash
define('LANDING_PATH', 'editionen/change-management/');  // relativ zu SITE_URL; danke.html liegt darin
define('LOGO_URL', SITE_URL . 'assets/img/25experts-logo-horizontal.png');   // Logo in der HTML-Bestätigung ('' = ohne Logo)

// Impressumszeile im Fuß der Bestätigungsmail (Pflichtangaben im Geschäftsverkehr; nach HR-Eintragung ergänzen)
define('MAIL_FOOTER', '25 Experts Cologne UG (haftungsbeschränkt) i. G. · Sitz Köln · Moitzfeld 17, 51429 Bergisch Gladbach · Geschäftsführer: Maximilian Hempel, Simon Moser · Amtsgericht Köln (Eintragung beantragt)');

// --- Schutz -------------------------------------------------------------------------------
define('RATE_LIMIT', 5);               // höchstens N Anmeldungen je IP-Hash …
define('RATE_WINDOW', 3600);           // … je Zeitfenster in Sekunden (Sperrdateien in sys_get_temp_dir()/25x-anmeldung/)
define('RATE_SALT', 'BITTE-ZUFAELLIGE-ZEICHENFOLGE-EINTRAGEN');   // beliebige Zufallszeichen; macht den IP-Hash nicht rückrechenbar
define('CHECK_ORIGIN', true);          // Origin-Header (falls gesendet) muss zu SITE_URL passen

// --- Versandart ---------------------------------------------------------------------------
// 'smtp' = echter Versand (Produktion). 'file' = Testmodus: Mails werden NICHT verschickt, sondern als .eml
// in MAIL_DUMP_DIR abgelegt (nur für lokale Tests, nie auf dem Live-Server verwenden).
define('MAIL_TRANSPORT', 'smtp');
define('MAIL_DUMP_DIR', sys_get_temp_dir() . '/25x-mails');
