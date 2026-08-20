<?php
/**
 * 25 EXPERTS – gemeinsame Grundlage der Anmeldestrecke (anmeldung/*.php).
 *
 * Lädt config.php, PHPMailer und die Datenablage, stellt Hilfsfunktionen bereit:
 *   - x25_cfg()            Konfigurationswert mit Fallback
 *   - x25_store()          Datenablage (lib/store.php)
 *   - x25_sign()/x25_verify_sig()  HMAC-Signaturen für Gastgeber-Links (APP_SECRET)
 *   - x25_mailer()/x25_dispatch()  PHPMailer (SMTP oder Testmodus 'file')
 *   - x25_line()/x25_multiline()/x25_e()  Eingabebereinigung, HTML-Escaping
 *   - x25_h_*()/x25_html_shell()  Bausteine der HTML-Mails (Stil aus 06-assets/emails/build_emails.py)
 *   - x25_page()           schlichte HTML-Seite (Zahlung, Rechnung, Ticket, Admin, Aktionen)
 *   - x25_money()/x25_amounts()  Beträge (PRICE_NET, VAT_RATE)
 * Keine personenbezogenen Daten im Log: x25_log() schreibt nur technische Meldungen.
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

ini_set('display_errors', '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');

define('X25_DIR', dirname(__DIR__));
if (!is_file(X25_DIR . '/config.php')) {
    http_response_code(500);
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')); $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ctype, 'application/json') || str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Die Anmeldung ist gerade nicht erreichbar. Bitte versuche es später erneut oder schreib uns an info@25-experts.de.'], JSON_UNESCAPED_UNICODE);   // Ursache serverseitig: anmeldung/config.php fehlt
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Die Anmeldung ist gerade nicht erreichbar. Bitte versuche es später erneut oder schreib uns an info@25-experts.de.';
    }
    exit;
}
require X25_DIR . '/config.php';
require __DIR__ . '/Exception.php';
require __DIR__ . '/PHPMailer.php';
require __DIR__ . '/SMTP.php';
require __DIR__ . '/store.php';

// Farben/Schriften der HTML-Mails (wie 06-assets/emails/build_emails.py)
const X25_FONT = 'Arial, Helvetica, sans-serif';
const X25_MONO = "'Courier New', Courier, monospace";
const X25_INK = '#0B1F26'; const X25_PETROL = '#0B6470'; const X25_PAPER = '#FBFAF6';
const X25_BODY = '#3D4442'; const X25_META = '#6B6E6A'; const X25_LINE = '#DDDAD1'; const X25_ORANGE = '#C2410C';

// Feldwerte v5 (05-landingpage/form-handler.md, Feldliste v5)
const X25_LEVELS = ['teamleitung' => 'Teamleitung', 'abteilungsleitung' => 'Abteilungsleitung', 'bereichsleitung' => 'Bereichsleitung', 'vorstandsstab' => 'Vorstandsstab', 'sonstiges' => 'Sonstiges'];
const X25_CATEGORIES = ['versicherer' => 'Versicherer', 'maklerpool' => 'Maklerpool', 'vertrieb' => 'Versicherungsvertrieb'];
const X25_STATUS = ['pruefung' => 'in Prüfung', 'zugelassen' => 'zugelassen', 'abgesagt' => 'abgesagt', 'warteliste' => 'Warteliste'];
const X25_PAY = ['offen' => 'offen', 'bezahlt' => 'bezahlt'];

/** Konfigurationswert (define in config.php) mit Fallback. */
function x25_cfg(string $name, $default = null)
{
    return defined($name) ? constant($name) : $default;
}

/** Häufig gebrauchte, abgeleitete Konfiguration. */
function x25_conf(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $site = rtrim((string)x25_cfg('SITE_URL', 'https://25-experts.de/'), '/') . '/';
    $landing = $site . trim((string)x25_cfg('LANDING_PATH', 'editionen/change-management/'), '/') . '/';
    $base = rtrim((string)x25_cfg('ANMELDUNG_URL', $site . 'anmeldung/'), '/') . '/';
    $c = [
        'site' => $site, 'landing' => $landing, 'thanks' => $landing . 'danke.html', 'base' => $base,
        'edition' => (string)x25_cfg('EDITION', '25 CHANGE MANAGEMENT EXPERTS · 03./04.12.2026 · Köln'),
        'edition_name' => (string)x25_cfg('EDITION_NAME', '25 CHANGE MANAGEMENT EXPERTS'),
        'edition_datum' => (string)x25_cfg('EDITION_DATUM', '3. und 4. Dezember 2026'),
        'edition_ort' => (string)x25_cfg('EDITION_ORT', 'Köln'),
        'edition_venue' => (string)x25_cfg('EDITION_VENUE', 'SESSEL HUB Rheinauhafen, Kranhaus Nord (Erdgeschoss), Im Zollhafen 12, 50678 Köln'),
        'edition_zeiten' => (string)x25_cfg('EDITION_ZEITEN', 'Tag 1: 09:45 bis 17:15 Uhr, anschließend Abend (Aperitif 18:15 Uhr, Dinner 19:00 Uhr) · Tag 2: 09:30 bis 13:30 Uhr'),
        'edition_hotel' => (string)x25_cfg('EDITION_HOTEL', 'Hotel buchst Du bitte selbst; ein Zimmerkontingent ist angefragt [TBD: Hotel, Stichwort], aber nicht garantiert.'),
        'edition_kontakt' => (string)x25_cfg('EDITION_KONTAKT', 'info@25-experts.de · [TBD: Kontaktnummer]'),
        'leistungsdatum' => (string)x25_cfg('EDITION_LEISTUNGSDATUM', '03.–04.12.2026'),
        'logo' => (string)x25_cfg('LOGO_URL', ''),
        'footer' => (string)x25_cfg('MAIL_FOOTER', '25 Experts Cologne UG (haftungsbeschränkt) · Sitz Köln'),
        'mail_to' => (string)x25_cfg('MAIL_TO', ''),
        'transport' => (string)x25_cfg('MAIL_TRANSPORT', 'smtp'),
        'dump' => (string)x25_cfg('MAIL_DUMP_DIR', sys_get_temp_dir() . '/25x-mails'),
        'review_days' => (int)x25_cfg('REVIEW_DAYS', 3),
        'max_seats' => (int)x25_cfg('MAX_SEATS', 25),
        'seats_rule' => (string)x25_cfg('SEATS_COUNT', 'zugelassen'),
        'payment_days' => (int)x25_cfg('PAYMENT_DAYS', 14),
        'paypal_env' => (string)x25_cfg('PAYPAL_ENV', 'off'),
        'paypal_client' => (string)x25_cfg('PAYPAL_CLIENT_ID', ''),
        'gastgeber' => (string)x25_cfg('HOSTS_SIGNATURE', 'Maximilian Hempel und Simon Moser'),
    ];
    return $c;
}

/** Datenablage (Singleton). */
function x25_store(): X25Store
{
    static $s = null;
    if ($s === null) {
        $s = X25Store::open((string)x25_cfg('DATA_DIR', X25_DIR . '/data'), (string)x25_cfg('STORE_BACKEND', 'auto'));
    }
    return $s;
}

/** Technisches Log ohne personenbezogene Daten. */
function x25_log(string $msg): void
{
    error_log('25x anmeldung: ' . substr($msg, 0, 300));
}

// ------------------------------------------------------------------ Signaturen / Zufall
function x25_secret(): string
{
    $s = (string)x25_cfg('APP_SECRET', '');
    if (strlen($s) < 16) { throw new RuntimeException('APP_SECRET fehlt oder ist zu kurz (config.php).'); }
    return $s;
}
function x25_sign(string $data): string
{
    return hash_hmac('sha256', $data, x25_secret());
}
function x25_verify_sig(string $data, string $sig): bool
{
    return $sig !== '' && hash_equals(x25_sign($data), $sig);
}
function x25_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}
/** Signierter Gastgeber-Link (Aktion an einem Datensatz, gültig solange die Nonce im Datensatz steht). */
function x25_action_url(array $rec, string $action): string
{
    $nonce = (string)($rec['action_nonce'] ?? '');
    $sig = x25_sign($action . '|' . $rec['id'] . '|' . $nonce);
    return x25_conf()['base'] . 'aktion.php?a=' . rawurlencode($action) . '&id=' . (int)$rec['id'] . '&n=' . rawurlencode($nonce) . '&s=' . $sig;
}

// ------------------------------------------------------------------ Editions-Kontext je Anmeldung (v8: mehrere Editionen)
/**
 * Editionswerte für einen Anmeldedatensatz: zuerst die Live-Edition (edition/lib.php, Slug aus dem
 * Datensatz), dann der beim Anmelden gespeicherte Snapshot ($rec['ed']), dann die config.php-Werte.
 * Liefert: name, datum, datum_kurz, ort, venue, zeiten, hotel, kontakt, leistungsdatum,
 *          label (Kopfzeile), landing, thanks, preis_net, ticket_prefix, max_seats, slug
 */
function x25_edition_for(?array $rec): array
{
    $c = x25_conf();
    $snap = is_array($rec['ed'] ?? null) ? $rec['ed'] : [];
    $slug = (string)($rec['edition_slug'] ?? ($snap['slug'] ?? ''));
    $live = [];
    if ($slug !== '') {
        try {
            require_once dirname(X25_DIR) . '/edition/lib.php';
            $live = x25ed_get($slug) ?? [];
        } catch (Throwable) {
            $live = [];
        }
    }
    $w = static fn(string $liveKey, string $snapKey, $fallback) => ($live[$liveKey] ?? null) !== null && $live[$liveKey] !== '' ? $live[$liveKey] : (($snap[$snapKey] ?? null) !== null && ($snap[$snapKey] ?? '') !== '' ? $snap[$snapKey] : $fallback);
    $name = (string)$w('name', 'name', $c['edition_name']);
    $datumKurz = (string)$w('datum_kurz', 'datum_kurz', '');
    $ort = (string)$w('ort', 'ort', $c['edition_ort']);
    $landing = $slug !== '' ? rtrim($c['site'], '/') . '/editionen/' . $slug . '/' : $c['landing'];
    return [
        'slug' => $slug,
        'name' => $name,
        'datum' => (string)$w('datum_text', 'datum_text', $c['edition_datum']),
        'datum_kurz' => $datumKurz,
        'ort' => $ort,
        'venue' => (string)$w('venue', 'venue', $c['edition_venue']),
        'zeiten' => (string)$w('zeiten', 'zeiten', $c['edition_zeiten']),
        'hotel' => (string)$w('hotel', 'hotel', $c['edition_hotel']),
        'kontakt' => (string)$w('kontakt_zeile', 'kontakt_zeile', $c['edition_kontakt']),
        'leistungsdatum' => (string)$w('leistungsdatum', 'leistungsdatum', $c['leistungsdatum']),
        'label' => (string)($rec['edition'] ?? '') !== '' ? (string)$rec['edition'] : trim(implode(' · ', array_filter([$name, $datumKurz, $ort]))),
        'landing' => $landing,
        'thanks' => $slug !== '' ? $landing . 'danke' : $c['thanks'],
        'preis_net' => round((float)$w('preis_net', 'preis_net', x25_cfg('PRICE_NET', 450.0)), 2),
        'ticket_prefix' => (string)$w('ticket_prefix', 'ticket_prefix', x25_cfg('TICKET_PREFIX', '25X-CM-')),
        'max_seats' => (int)$w('max_plaetze', 'max_plaetze', $c['max_seats']),
    ];
}

// ------------------------------------------------------------------ Beträge
/** Beträge; mit $rec gilt der Preis der Edition des Datensatzes (Snapshot bei der Anmeldung). */
function x25_amounts(?array $rec = null): array
{
    $net = $rec !== null ? x25_edition_for($rec)['preis_net'] : round((float)x25_cfg('PRICE_NET', 450.0), 2);
    $rate = (float)x25_cfg('VAT_RATE', 0.19);
    $vat = round($net * $rate, 2);
    return ['net' => $net, 'rate' => $rate, 'vat' => $vat, 'gross' => round($net + $vat, 2), 'currency' => (string)x25_cfg('CURRENCY', 'EUR')];
}
function x25_money(float $v): string
{
    return number_format($v, 2, ',', '.') . ' €';
}
/** Betrag im API-Format (Punkt, zwei Nachkommastellen). */
function x25_money_api(float $v): string
{
    return number_format($v, 2, '.', '');
}

// ------------------------------------------------------------------ Eingaben
/** Einzeiliger Text: trimmen, Steuerzeichen/Zeilenumbrüche entfernen (Header-Injection), kürzen. */
function x25_line($v, int $max): string
{
    if (is_array($v)) { return ''; }
    $s = (string)$v;
    if (!mb_check_encoding($s, 'UTF-8')) { $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8'); }
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}
/** Mehrzeiliger Text: Steuerzeichen außer Zeilenumbruch entfernen, kürzen. */
function x25_multiline($v, int $max): string
{
    if (is_array($v)) { return ''; }
    $s = str_replace(["\r\n", "\r"], "\n", (string)$v);
    if (!mb_check_encoding($s, 'UTF-8')) { $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8'); }
    $s = preg_replace('/[^\P{C}\n\t]+/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}
function x25_truthy($v): bool
{
    if (is_bool($v)) { return $v; }
    if (is_array($v)) { return false; }
    return in_array(strtolower(trim((string)$v)), ['ja', 'true', '1', 'on', 'yes'], true);
}
function x25_wrap(string $s): string
{
    return wordwrap($s, 76, "\n", false);
}
function x25_e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
function x25_date(?string $iso, string $fmt = 'd.m.Y H:i'): string
{
    if (!$iso) { return '–'; }
    try { return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Berlin'))->format($fmt); } catch (Throwable) { return $iso; }
}

// ------------------------------------------------------------------ Rate-Limit (Datei, IP-Hash, kein Klartext)
/** höchstens $limit Einträge je IP-Hash und Zähler ($bucket) in $window Sekunden; Dateien in sys_get_temp_dir()/25x-anmeldung/. */
function x25_rate_ok(int $limit, int $window, string $salt, string $bucket = 'm'): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $dir = rtrim(sys_get_temp_dir(), '/') . '/25x-anmeldung';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { return true; }   // kein Schutz möglich, aber Formular nicht blockieren
    $key = $bucket . '-' . hash('sha256', $salt . '|' . $ip . '|' . date('Y-m'));
    $file = $dir . '/' . $key;
    $now = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) { return true; }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $times = array_values(array_filter(array_map('intval', $raw !== '' ? explode(',', $raw) : []), static fn($t) => $t > $now - $window));
    $ok = count($times) < $limit;
    if ($ok) { $times[] = $now; }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, implode(',', $times));
    flock($fh, LOCK_UN); fclose($fh);
    if (random_int(1, 50) === 1) {   // gelegentlich aufräumen
        foreach (glob($dir . '/*') ?: [] as $f) { if (@filemtime($f) < $now - 2 * $window) { @unlink($f); } }
    }
    return $ok;
}

// ------------------------------------------------------------------ Mailversand
/** Konfigurierter PHPMailer (SMTP). */
function x25_mailer(): PHPMailer
{
    $m = new PHPMailer(true);
    $m->CharSet = PHPMailer::CHARSET_UTF8;
    $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
    $m->XMailer = '25 EXPERTS Anmeldung';
    $m->isSMTP();
    $m->Host = (string)x25_cfg('SMTP_HOST', 'smtp.hostinger.com');
    $m->Port = (int)x25_cfg('SMTP_PORT', 465);
    $m->SMTPSecure = (string)x25_cfg('SMTP_SECURE', 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $m->SMTPAuth = true;
    $m->Username = (string)x25_cfg('SMTP_USER', '');
    $m->Password = (string)x25_cfg('SMTP_PASS', '');
    $m->Timeout = 20;
    $m->setFrom((string)x25_cfg('MAIL_FROM', $m->Username), (string)x25_cfg('MAIL_FROM_NAME', '25 EXPERTS'));
    return $m;
}
/** Versand oder (Testmodus 'file') Ablage als .eml in MAIL_DUMP_DIR. */
function x25_dispatch(PHPMailer $m, string $tag): void
{
    $c = x25_conf();
    if ($c['transport'] === 'file') {
        if (!is_dir($c['dump'])) { @mkdir($c['dump'], 0700, true); }
        if (!$m->preSend()) { throw new MailException('preSend fehlgeschlagen'); }
        $file = rtrim($c['dump'], '/') . '/' . date('Ymd-His') . '-' . $tag . '-' . bin2hex(random_bytes(3)) . '.eml';
        file_put_contents($file, $m->getSentMIMEMessage());
        return;
    }
    $m->send();
}
/** Mail an die Gastgeber (MAIL_TO). */
function x25_send_hosts(string $subject, string $html, string $text, string $tag, ?array $replyTo = null): void
{
    $m = x25_mailer();
    foreach (explode(',', x25_conf()['mail_to']) as $to) {
        $to = trim($to);
        if ($to !== '') { $m->addAddress($to, (string)x25_cfg('MAIL_TO_NAME', '')); }
    }
    if ($replyTo && PHPMailer::validateAddress($replyTo[0])) { $m->addReplyTo($replyTo[0], $replyTo[1]); }
    $m->Subject = $subject; $m->isHTML(true); $m->Body = $html; $m->AltBody = $text;
    x25_dispatch($m, $tag);
}
/** Mail an den Anmelder; $embed = ['cid' => [bytes, name, mime]] für eingebettete Bilder (QR-Code). */
function x25_send_person(array $rec, string $subject, string $html, string $text, string $tag, array $embed = []): void
{
    $m = x25_mailer();
    $m->addAddress($rec['email'], $rec['name']);
    $reply = trim((string)x25_cfg('MAIL_CONFIRM_REPLY_TO', ''));
    if ($reply === '') { $reply = trim(explode(',', x25_conf()['mail_to'])[0]); }
    if ($reply !== '' && PHPMailer::validateAddress($reply)) { $m->addReplyTo($reply, '25 EXPERTS'); }
    foreach ($embed as $cid => [$bytes, $name, $mime]) { $m->addStringEmbeddedImage($bytes, $cid, $name, PHPMailer::ENCODING_BASE64, $mime); }
    $m->Subject = $subject; $m->isHTML(true); $m->Body = $html; $m->AltBody = $text;
    $m->addCustomHeader('Auto-Submitted', 'auto-replied');
    x25_dispatch($m, $tag);
}

// ---- HTML-Bausteine (E-Mail-tauglich, Tabellenlayout, Farben aus 06-assets/emails/build_emails.py)
function x25_h_p(string $inner, string $extra = ''): string
{
    return '<p style="margin:0 0 16px 0;font-family:' . X25_FONT . ';font-size:16px;line-height:24px;color:' . X25_BODY . ';' . $extra . '">' . $inner . '</p>';
}
function x25_h_h1(string $inner): string
{
    return '<h1 style="margin:0 0 20px 0;font-family:' . X25_FONT . ';font-size:24px;line-height:32px;font-weight:700;color:' . X25_INK . ';">' . $inner . '</h1>';
}
function x25_h_sub(string $inner): string
{
    return '<h2 style="margin:24px 0 8px 0;font-family:' . X25_FONT . ';font-size:17px;line-height:24px;font-weight:700;color:' . X25_INK . ';">' . $inner . '</h2>';
}
function x25_h_kicker(string $inner): string
{
    return '<p style="margin:0 0 12px 0;font-family:' . X25_MONO . ';font-size:12px;line-height:16px;letter-spacing:1px;text-transform:uppercase;color:' . X25_PETROL . ';">' . $inner . '</p>';
}
function x25_h_box(string $inner, string $accent = X25_PETROL): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">'
        . '<tr><td width="4" bgcolor="' . $accent . '" style="width:4px;font-size:0;line-height:0;">&nbsp;</td>'
        . '<td style="padding:16px 20px;border-top:1px solid ' . X25_LINE . ';border-right:1px solid ' . X25_LINE . ';border-bottom:1px solid ' . X25_LINE . ';background-color:#FFFFFF;">' . $inner . '</td></tr></table>';
}
/** Schaltfläche (Tabellen-Button, in allen Mailclients klickbar). */
function x25_h_btn(string $url, string $label, string $bg = X25_PETROL): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 12px 16px 0;display:inline-table;"><tr><td bgcolor="' . $bg . '" style="border-radius:4px;">'
        . '<a href="' . x25_e($url) . '" style="display:inline-block;padding:12px 20px;font-family:' . X25_FONT . ';font-size:15px;font-weight:700;color:#FFFFFF;text-decoration:none;">' . x25_e($label) . '</a></td></tr></table>';
}
function x25_h_rows(array $rows): string
{
    $tr = '';
    foreach ($rows as [$k, $v]) {
        $v = (string)$v;
        $val = x25_e($v);
        if (preg_match('~^https?://~i', $v)) { $val = '<a href="' . $val . '" style="color:' . X25_PETROL . ';">' . $val . '</a>'; }
        $tr .= '<tr><td valign="top" style="padding:6px 12px 6px 0;font-family:' . X25_MONO . ';font-size:13px;line-height:20px;color:' . X25_META . ';white-space:nowrap;">' . x25_e($k) . '</td>'
            . '<td valign="top" style="padding:6px 0;font-family:' . X25_FONT . ';font-size:15px;line-height:20px;color:' . X25_INK . ';word-break:break-word;">' . $val . '</td></tr>';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">' . $tr . '</table>';
}
function x25_h_sig(): string
{
    return x25_h_p('Mit freundlichen Grüßen<br>' . x25_e(x25_conf()['gastgeber']) . '<br><span style="color:' . X25_META . ';">Gastgeber und Moderator · 25 EXPERTS</span>');
}
function x25_t_sig(): string
{
    $c = x25_conf();
    return "Mit freundlichen Grüßen\n" . $c['gastgeber'] . "\nGastgeber und Moderator · 25 EXPERTS\n\n--\n25 EXPERTS\n" . $c['footer'] . "\n"
        . "Datenschutz: " . $c['site'] . "datenschutz · Impressum: " . $c['site'] . "impressum\n";
}
/** Komplette HTML-Mail. $withLinks: Datenschutz/Impressum im Fuß; $editionLabel: Kopfzeile rechts (Standard: config EDITION). */
function x25_html_shell(string $subject, string $body, bool $withLinks = true, string $preheader = '', ?string $editionLabel = null): string
{
    $c = x25_conf();
    if ($editionLabel !== null && $editionLabel !== '') { $c['edition'] = $editionLabel; }
    $logo = $c['logo'] !== ''
        ? '<img src="' . x25_e($c['logo']) . '" width="180" height="40" alt="25 EXPERTS" style="display:block;border:0;outline:none;width:180px;height:auto;font-family:' . X25_FONT . ';font-size:14px;font-weight:700;color:' . X25_INK . ';">'
        : '<span style="font-family:' . X25_FONT . ';font-size:16px;font-weight:700;letter-spacing:1px;color:' . X25_INK . ';">25 EXPERTS</span>';
    $pre = $preheader !== ''
        ? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:' . X25_PAPER . ';">' . x25_e($preheader) . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>'
        : '';
    $links = $withLinks
        ? '<p style="margin:0;font-family:' . X25_FONT . ';font-size:12px;line-height:18px;color:' . X25_META . ';">'
          . '<a href="' . x25_e($c['site']) . 'datenschutz" style="color:' . X25_PETROL . ';text-decoration:underline;">Datenschutz</a> · '
          . '<a href="' . x25_e($c['site']) . 'impressum" style="color:' . X25_PETROL . ';text-decoration:underline;">Impressum</a></p>'
        : '';
    return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
        . '<html xmlns="http://www.w3.org/1999/xhtml" lang="de"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0" /><meta name="x-apple-disable-message-reformatting" />'
        . '<title>' . x25_e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background-color:' . X25_PAPER . ';">' . $pre
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . X25_PAPER . '" style="background-color:' . X25_PAPER . ';"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">'
        . '<tr><td style="padding:8px 0 20px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td align="left" valign="middle">' . $logo . '</td>'
        . '<td align="right" valign="middle" style="font-family:' . X25_MONO . ';font-size:12px;line-height:16px;letter-spacing:1px;text-transform:uppercase;color:' . X25_META . ';">' . x25_e($c['edition']) . '</td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="border-top:2px solid ' . X25_INK . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:32px 32px 16px 32px;border-left:1px solid ' . X25_LINE . ';border-right:1px solid ' . X25_LINE . ';border-bottom:1px solid ' . X25_LINE . ';">'
        . $body
        . '</td></tr>'
        . '<tr><td style="padding:24px 8px 8px 8px;">'
        . '<p style="margin:0 0 8px 0;font-family:' . X25_MONO . ';font-size:12px;line-height:18px;letter-spacing:1px;text-transform:uppercase;color:' . X25_INK . ';">25 EXPERTS</p>'
        . '<p style="margin:0 0 8px 0;font-family:' . X25_FONT . ';font-size:12px;line-height:18px;color:' . X25_META . ';">' . x25_e($c['footer']) . '</p>'
        . $links
        . '</td></tr></table></td></tr></table></body></html>';
}

// ------------------------------------------------------------------ Schlichte HTML-Seiten (Zahlung, Rechnung, Ticket, Admin)
/** Gibt eine vollständige Seite aus. $extraHead z. B. für das PayPal-SDK; $editionLabel: Kopfzeile rechts. */
function x25_page(string $title, string $body, string $extraHead = '', bool $wide = false, ?string $editionLabel = null): string
{
    $c = x25_conf();
    if ($editionLabel !== null && $editionLabel !== '') { $c['edition'] = $editionLabel; }
    $logo = $c['logo'] !== '' ? '<img src="' . x25_e($c['logo']) . '" alt="25 EXPERTS" width="160" height="36">' : '<strong>25 EXPERTS</strong>';
    return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>' . x25_e($title) . ' · 25 EXPERTS</title>'
        . '<style>
:root{--ink:' . X25_INK . ';--petrol:' . X25_PETROL . ';--paper:' . X25_PAPER . ';--body:' . X25_BODY . ';--meta:' . X25_META . ';--line:' . X25_LINE . ';--orange:' . X25_ORANGE . '}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--body);font:16px/1.5 Arial,Helvetica,sans-serif}
.wrap{max-width:' . ($wide ? '1180px' : '720px') . ';margin:0 auto;padding:24px 16px 48px}
header.top{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:8px 0 16px;border-bottom:2px solid var(--ink);margin-bottom:24px}
header.top img{display:block;height:36px;width:auto}
.kicker,.mono{font-family:"Courier New",Courier,monospace;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:var(--petrol)}
.meta{color:var(--meta);font-size:14px}h1{color:var(--ink);font-size:26px;line-height:1.25;margin:0 0 16px}h2{color:var(--ink);font-size:18px;margin:28px 0 8px}
.card{background:#fff;border:1px solid var(--line);border-left:4px solid var(--petrol);padding:20px 24px;margin:0 0 20px}
.card.warn{border-left-color:var(--orange)}.card.ok{border-left-color:#2F7A3B}
table.rows td{padding:6px 12px 6px 0;vertical-align:top;font-size:15px;color:var(--ink)}table.rows td:first-child{font-family:"Courier New",Courier,monospace;font-size:13px;color:var(--meta);white-space:nowrap}
table.list{width:100%;border-collapse:collapse;font-size:14px;background:#fff}table.list th,table.list td{border-bottom:1px solid var(--line);padding:8px 8px;text-align:left;vertical-align:top}table.list th{font-family:"Courier New",Courier,monospace;font-size:12px;letter-spacing:.5px;text-transform:uppercase;color:var(--meta);background:#F4F2EC}
.btn{display:inline-block;background:var(--petrol);color:#fff;border:0;border-radius:4px;padding:12px 20px;font:700 15px Arial,Helvetica,sans-serif;text-decoration:none;cursor:pointer;margin:0 8px 8px 0}
.btn.sec{background:#fff;color:var(--ink);border:1px solid var(--ink)}.btn.danger{background:var(--orange)}.btn.small{padding:6px 10px;font-size:13px;margin:0 4px 4px 0}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:#E9EEEE;color:var(--ink)}.badge.zugelassen,.badge.bezahlt{background:#DDF1E1;color:#1E5A2A}.badge.pruefung,.badge.offen{background:#FBE9D8;color:#8A3A10}.badge.abgesagt{background:#F0E4E4;color:#7A2E2E}.badge.warteliste{background:#E5E9F5;color:#2E3F7A}
input[type=text],input[type=date],select{padding:6px 8px;border:1px solid var(--line);border-radius:4px;font:14px Arial,Helvetica,sans-serif}
.amount{font-size:22px;color:var(--ink);font-weight:700}.qr{max-width:220px;height:auto;display:block}
footer.bottom{margin-top:40px;padding-top:12px;border-top:1px solid var(--line);font-size:12px;color:var(--meta)}footer.bottom a{color:var(--petrol)}
@media print{.noprint{display:none!important}body{background:#fff}.wrap{max-width:none;padding:0}.card{break-inside:avoid}}
</style>' . $extraHead . '</head><body><div class="wrap">'
        . '<header class="top"><a href="' . x25_e($c['site']) . '">' . $logo . '</a><span class="mono" style="color:var(--meta)">' . x25_e($c['edition']) . '</span></header>'
        . $body
        . '<footer class="bottom"><span class="mono" style="color:var(--ink)">25 EXPERTS</span><br>' . x25_e($c['footer']) . '<br><a href="' . x25_e($c['site']) . 'datenschutz">Datenschutz</a> · <a href="' . x25_e($c['site']) . 'impressum">Impressum</a></footer>'
        . '</div></body></html>';
}
function x25_out(string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Cache-Control: no-store', true);
    echo $html;
    exit;
}
function x25_json(array $out, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store', true);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------ Zulassung / Plätze
/** Prüft eine E-Mail-Domain: 'zugelassen' (Allowlist), 'freemail' oder 'unbekannt'. */
function x25_domain_check(string $email): string
{
    $domain = strtolower(trim((string)substr(strrchr($email, '@') ?: '', 1)));
    if ($domain === '') { return 'unbekannt'; }
    $lists = ['zugelassen' => (string)x25_cfg('DOMAINS_ALLOW_FILE', X25_DIR . '/data/domains-zugelassen.txt'), 'freemail' => (string)x25_cfg('DOMAINS_FREEMAIL_FILE', X25_DIR . '/data/domains-freemail.txt')];
    foreach ($lists as $result => $file) {
        foreach (x25_read_domains($file) as $d) {
            if ($domain === $d || str_ends_with($domain, '.' . $d)) { return $result; }
        }
    }
    return 'unbekannt';
}
/** Domainliste: eine Domain je Zeile, # und [TBD …] als Kommentar, Groß-/Kleinschreibung egal. */
function x25_read_domains(string $file): array
{
    if (!is_file($file)) { return []; }
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = strtolower(trim(preg_replace('/(#|\[).*$/', '', $line) ?? ''));
        $line = ltrim($line, '@');
        if ($line !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $line)) { $out[] = $line; }
    }
    return $out;
}
/** Standard-Slug für Altdatensätze ohne edition_slug (aus LANDING_PATH abgeleitet). */
function x25_default_slug(): string
{
    return basename(trim((string)x25_cfg('LANDING_PATH', 'editionen/change-management/'), '/'));
}

/** Belegte Plätze nach Regel SEATS_COUNT; mit $slug nur die Anmeldungen dieser Edition. */
function x25_seats_taken(array $all, ?string $slug = null): int
{
    $rule = x25_conf()['seats_rule'];
    $n = 0;
    foreach ($all as $r) {
        if ($slug !== null && (((string)($r['edition_slug'] ?? '') ?: x25_default_slug()) !== $slug)) { continue; }
        if (($r['status'] ?? '') !== 'zugelassen') { continue; }
        if ($rule === 'bezahlt' && ($r['payment_status'] ?? '') !== 'bezahlt') { continue; }
        $n++;
    }
    return $n;
}
