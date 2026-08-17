<?php
/**
 * 25 EXPERTS – Anmeldeformular-Handler
 * Nimmt die Anmeldung der Landingpage (editionen/change-management/#anmeldung) entgegen:
 *   - als JSON (fetch aus assets/js/site.js, Content-Type application/json)  → Antwort JSON {ok:true} / {ok:false,error}
 *   - als klassisches Formular-POST (ohne JavaScript)                        → Redirect 303 auf danke.html bzw. zurück mit ?fehler=1
 * Prüft Pflichtfelder, Honeypot, Rate-Limit, Header-Injection; verschickt per SMTP (PHPMailer)
 *   (a) die Benachrichtigung mit allen Feldern an MAIL_TO (Reply-To = Anmelder),
 *   (b) die Eingangsbestätigung an den Anmelder (Wortlaut E-Mail 01, Sie-Form).
 * Es werden keine personenbezogenen Daten gespeichert oder protokolliert (nur ein IP-Hash für das Rate-Limit, RATE_WINDOW lang).
 * Konfiguration: config.php (aus config.example.php), PHP >= 8.1, PHPMailer in lib/.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store', true);

$here = __DIR__;
if (!is_file($here . '/config.php')) {
    x25_fail_early('Konfiguration fehlt (anmeldung/config.php).');
}
require $here . '/config.php';
require $here . '/lib/Exception.php';
require $here . '/lib/PHPMailer.php';
require $here . '/lib/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

// Farben/Schriften der HTML-Mails (wie 06-assets/emails/build_emails.py)
const X25_FONT = 'Arial, Helvetica, sans-serif';
const X25_MONO = "'Courier New', Courier, monospace";
const X25_INK = '#0B1F26'; const X25_PETROL = '#0B6470'; const X25_PAPER = '#FBFAF6';
const X25_BODY = '#3D4442'; const X25_META = '#6B6E6A'; const X25_LINE = '#DDDAD1';

// ------------------------------------------------------------------ Konstanten mit Fallback
$cfg = static function (string $name, $default) {
    return defined($name) ? constant($name) : $default;
};
$SITE_URL      = rtrim((string)$cfg('SITE_URL', 'https://25-experts.de/'), '/') . '/';
$LANDING_PATH  = trim((string)$cfg('LANDING_PATH', 'editionen/change-management/'), '/') . '/';
$LANDING_URL   = $SITE_URL . $LANDING_PATH;
$THANKS_URL    = $LANDING_URL . 'danke.html';
$EDITION       = (string)$cfg('EDITION', '25 CHANGE MANAGEMENT EXPERTS · 03./04.12.2026 · Köln');
$EDITION_NAME  = (string)$cfg('EDITION_NAME', '25 CHANGE MANAGEMENT EXPERTS');
$EDITION_DATUM = (string)$cfg('EDITION_DATUM', '3. und 4. Dezember 2026');
$EDITION_ORT   = (string)$cfg('EDITION_ORT', 'Köln');
$EDITION_BETRAG= (string)$cfg('EDITION_BETRAG', '450 €');
$LOGO_URL      = (string)$cfg('LOGO_URL', '');
$MAIL_FOOTER   = (string)$cfg('MAIL_FOOTER', '25 Experts Cologne UG (haftungsbeschränkt) · Sitz Köln');
$RATE_LIMIT    = (int)$cfg('RATE_LIMIT', 5);
$RATE_WINDOW   = (int)$cfg('RATE_WINDOW', 3600);
$RATE_SALT     = (string)$cfg('RATE_SALT', 'x25');
$CHECK_ORIGIN  = (bool)$cfg('CHECK_ORIGIN', true);
$TRANSPORT     = (string)$cfg('MAIL_TRANSPORT', 'smtp');

// ------------------------------------------------------------------ Anfrage einordnen
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctype  = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$isJson = str_contains($ctype, 'application/json');
$wantsJson = $isJson || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));

if ($method !== 'POST') {
    // Direktaufruf: zur Anmeldung leiten
    header('Location: ' . $LANDING_URL . '#anmeldung', true, 302);
    exit;
}

// Origin-Prüfung (Browser senden Origin bei POST; wenn vorhanden, muss er zur eigenen Domain passen)
if ($CHECK_ORIGIN && !empty($_SERVER['HTTP_ORIGIN'])) {
    $originHost = strtolower((string)parse_url((string)$_SERVER['HTTP_ORIGIN'], PHP_URL_HOST));
    $siteHost   = strtolower((string)parse_url($SITE_URL, PHP_URL_HOST));
    $allowed = [$siteHost, 'www.' . $siteHost, preg_replace('/^www\./', '', $siteHost)];
    if ($originHost === '' || !in_array($originHost, $allowed, true)) {
        x25_respond(false, 'Anfrage abgelehnt.', 403, 'versand');
    }
}

// Daten lesen
$in = [];
if ($isJson) {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 65536) {
        x25_respond(false, 'Ungültige Anfrage.', 400, 'pflicht');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        x25_respond(false, 'Ungültige Anfrage.', 400, 'pflicht');
    }
    $in = $decoded;
} else {
    $in = $_POST;
}

// Honeypot: Bots füllen „website"; still als Erfolg beantworten, nichts senden
if (isset($in['website']) && trim((string)$in['website']) !== '') {
    x25_respond(true, null, 200, null);
}

// ------------------------------------------------------------------ Rate-Limit (Datei, IP-Hash, kein Klartext)
// Zwei Zähler je IP-Hash: alle Versuche (großzügig, gegen Hämmern) und tatsächlich versandte Anmeldungen (RATE_LIMIT).
$RATE_MSG = 'Zu viele Versuche in kurzer Zeit. Bitte versuchen Sie es in einer Stunde erneut.';
if ($RATE_LIMIT > 0 && !x25_rate_ok($RATE_LIMIT * 6, $RATE_WINDOW, $RATE_SALT, 'v')) {
    x25_respond(false, $RATE_MSG, 429, 'limit');
}

// ------------------------------------------------------------------ Validierung
$LEVELS = [
    'teamleitung'       => 'Teamleitung',
    'abteilungsleitung' => 'Abteilungsleitung',
    'bereichsleitung'   => 'Bereichsleitung',
    'vorstandsassistenz'=> 'Vorstandsassistenz',
    'dienstleister'     => 'Dienstleister',
    'sonstiges'         => 'Sonstiges',
];
$CATEGORIES = ['versicherer' => 'Versicherer', 'dienstleister' => 'Dienstleister'];

$errors = [];
$d = [];
$d['name']     = x25_line($in['name'] ?? '', 200);
$d['company']  = x25_line($in['company'] ?? '', 200);
$d['role']     = x25_line($in['role'] ?? '', 200);
$d['level']    = strtolower(x25_line($in['level'] ?? '', 40));
$d['email']    = strtolower(x25_line($in['email'] ?? '', 254));
$d['linkedin'] = x25_line($in['linkedin'] ?? '', 300);
$d['question'] = x25_multiline($in['question'] ?? '', 5000);
$d['category'] = strtolower(x25_line($in['category'] ?? '', 40));
$d['consent']  = x25_truthy($in['consent'] ?? null);
$d['source']   = x25_line($in['source'] ?? '', 500);
$edition_in    = x25_line($in['edition'] ?? '', 200);
$d['edition']  = $edition_in !== '' ? $edition_in : $EDITION;

foreach (['name' => 'Name', 'company' => 'Unternehmen', 'role' => 'Rolle', 'question' => 'Ihre eine offene Frage'] as $k => $label) {
    if ($d[$k] === '') { $errors[$k] = $label . ' fehlt.'; }
}
if ($d['level'] === '' || !isset($LEVELS[$d['level']])) { $errors['level'] = 'Ebene fehlt oder ist ungültig.'; }
if ($d['category'] === '' || !isset($CATEGORIES[$d['category']])) { $errors['category'] = 'Kategorie fehlt oder ist ungültig.'; }
if (!$d['consent']) { $errors['consent'] = 'Bitte bestätigen Sie den Hinweis zum Datenschutz.'; }
$emailOk = $d['email'] !== ''
    && filter_var($d['email'], FILTER_VALIDATE_EMAIL) !== false
    && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $d['email']) === 1
    && PHPMailer::validateAddress($d['email']);
if (!$emailOk) { $errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.'; }
if ($d['linkedin'] !== '') {
    if (!preg_match('~^https?://~i', $d['linkedin']) || filter_var($d['linkedin'], FILTER_VALIDATE_URL) === false) {
        $errors['linkedin'] = 'Bitte geben Sie eine vollständige LinkedIn-Adresse mit https:// an.';
    }
}
if ($d['source'] !== '' && !str_starts_with($d['source'], $SITE_URL) && !str_starts_with($d['source'], 'http://localhost')) {
    $d['source'] = '';   // fremde/unerwartete Herkunft nicht übernehmen
}

if ($errors) {
    $reason = (count($errors) === 1 && isset($errors['email'])) ? 'email' : 'pflicht';
    $msg = 'Bitte prüfen Sie Ihre Angaben: ' . implode(' ', array_values($errors));
    x25_respond(false, $msg, 422, $reason, $errors);
}
if ($RATE_LIMIT > 0 && !x25_rate_ok($RATE_LIMIT, $RATE_WINDOW, $RATE_SALT, 'm')) {
    x25_respond(false, $RATE_MSG, 429, 'limit');
}

// ------------------------------------------------------------------ Mails aufbauen
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
$stamp = $now->format('d.m.Y H:i') . ' Uhr';
$levelLabel = $LEVELS[$d['level']];
$catLabel = $CATEGORIES[$d['category']];

$rows = [
    ['Name', $d['name']],
    ['Unternehmen', $d['company']],
    ['Rolle', $d['role']],
    ['Ebene', $levelLabel],
    ['E-Mail', $d['email']],
    ['LinkedIn', $d['linkedin'] !== '' ? $d['linkedin'] : '–'],
    ['Kategorie', $catLabel],
    ['Datenschutzhinweis', 'gelesen (Art. 6 Abs. 1 lit. b DSGVO)'],
    ['Edition', $d['edition']],
    ['Eingegangen', $stamp],
    ['Quelle', $d['source'] !== '' ? $d['source'] : $LANDING_URL],
];

// (a) Benachrichtigung an die Gastgeber
$subjNotify = 'Neue Anmeldung: ' . $d['name'] . ', ' . $d['company'] . ' · ' . $EDITION_NAME;
$txtNotify = "Neue Anmeldung über das Formular auf " . $LANDING_URL . "\n\n";
foreach ($rows as [$k, $v]) { $txtNotify .= str_pad($k . ':', 20) . $v . "\n"; }
$txtNotify .= "\nIhre eine offene Frage:\n" . x25_wrap($d['question']) . "\n\n"
    . "Antworten Sie direkt auf diese E-Mail, um die Person zu erreichen (Reply-To ist gesetzt).\n"
    . "Status-Vorschlag für die Anmeldeliste: eingegangen → zugesagt/abgesagt → bezahlt.\n";
$htmlNotify = x25_html_shell(
    $subjNotify,
    x25_h_kicker('Neue Anmeldung') . x25_h_h1(x25_e($d['name']) . '<br><span style="font-weight:400;color:#3D4442;">' . x25_e($d['company']) . '</span>')
    . x25_h_rows($rows)
    . x25_h_sub('Ihre eine offene Frage')
    . x25_h_box(x25_h_p(nl2br(x25_e($d['question'])), 'margin:0;font-family:Georgia,\'Times New Roman\',serif;font-size:17px;line-height:26px;color:#0B1F26;'), '#C2410C')
    . x25_h_p('Antworten Sie direkt auf diese E-Mail, um die Person zu erreichen (Reply-To ist gesetzt).', 'font-size:14px;line-height:20px;color:#6B6E6A;'),
    $EDITION, $LOGO_URL, $MAIL_FOOTER, false
);

// (b) Eingangsbestätigung an den Anmelder (Wortlaut E-Mail 01)
$subjConfirm = 'Ihre Anmeldung ist eingegangen · ' . $EDITION_NAME;
$preheader = 'Wir melden uns nach der Sichtung. Zahlung erst nach Zusage.';
$vorname = $d['name'];
$absatz1 = 'vielen Dank für Ihre Anmeldung zu ' . $EDITION_NAME . ' am ' . $EDITION_DATUM . ' in ' . $EDITION_ORT . '.';
$absatz2 = 'So geht es weiter: Wir sichten jede Anmeldung persönlich, auch Ihre eine offene Frage. Der Raum hat genau 25 Plätze, und wir achten auf die fachliche Zuordnung und die Ebene der Teilnehmer: Der Tisch ist für die Verantwortlichen der Funktion gedacht, Teamleitung, Abteilungsleitung, Bereichsleitung und Vorstandsassistenz aus Versicherungshäusern. Deshalb kann es einige Tage dauern, bis Sie von uns hören.';
$absatz3 = 'Bis dahin entsteht keine Zahlungspflicht. Die Rechnung über ' . $EDITION_BETRAG . ' netto erhalten Sie erst nach unserer Zusage.';
$absatz4 = 'Sollten Sie Ihre Anmeldung zurückziehen wollen, genügt eine kurze Antwort auf diese E-Mail.';
$txtConfirm = "Guten Tag " . $vorname . ",\n\n"
    . x25_wrap($absatz1) . "\n\n"
    . x25_wrap($absatz2) . "\n\n"
    . x25_wrap($absatz3) . "\n\n"
    . x25_wrap($absatz4) . "\n\n"
    . "Zur Kontrolle Ihre Angaben:\n"
    . "Name: " . $d['name'] . "\nUnternehmen: " . $d['company'] . "\nRolle: " . $d['role'] . "\nEbene: " . $levelLabel
    . "\nE-Mail: " . $d['email'] . "\nLinkedIn: " . ($d['linkedin'] !== '' ? $d['linkedin'] : '–') . "\nSie sind: " . $catLabel
    . "\nIhre eine offene Frage:\n" . x25_wrap($d['question']) . "\n\n"
    . "Mit freundlichen Grüßen\nMaximilian Hempel und Simon Moser\nGastgeber · 25 EXPERTS\n\n"
    . "--\n25 EXPERTS\n" . $MAIL_FOOTER . "\n"
    . "Datenschutz: " . $SITE_URL . "datenschutz · Impressum: " . $SITE_URL . "impressum\n";
$htmlConfirm = x25_html_shell(
    $subjConfirm,
    x25_h_kicker('Anmeldung eingegangen') . x25_h_h1('Guten Tag ' . x25_e($vorname) . ',')
    . x25_h_p(x25_e($absatz1))
    . x25_h_p(x25_e($absatz2))
    . x25_h_box(x25_h_p(x25_e($absatz3), 'margin:0;'))
    . x25_h_p(x25_e($absatz4))
    . x25_h_sub('Zur Kontrolle Ihre Angaben')
    . x25_h_rows([
        ['Name', $d['name']], ['Unternehmen', $d['company']], ['Rolle', $d['role']], ['Ebene', $levelLabel],
        ['E-Mail', $d['email']], ['LinkedIn', $d['linkedin'] !== '' ? $d['linkedin'] : '–'], ['Sie sind', $catLabel],
    ])
    . x25_h_p('<span style="font-family:\'Courier New\',Courier,monospace;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#0B6470;">Ihre eine offene Frage</span><br>' . nl2br(x25_e($d['question'])), 'font-family:Georgia,\'Times New Roman\',serif;font-size:17px;line-height:26px;color:#0B1F26;')
    . x25_h_p('Mit freundlichen Grüßen<br>Maximilian Hempel und Simon Moser<br><span style="color:#6B6E6A;">Gastgeber · 25 EXPERTS</span>'),
    $EDITION, $LOGO_URL, $MAIL_FOOTER, true, $preheader, $SITE_URL
);

// ------------------------------------------------------------------ Versand
$replyToConfirm = trim((string)$cfg('MAIL_CONFIRM_REPLY_TO', ''));
if ($replyToConfirm === '') { $replyToConfirm = (string)$cfg('MAIL_TO', ''); }
$replyToConfirm = trim(explode(',', $replyToConfirm)[0]);

try {
    // (a) an die Gastgeber
    $m = x25_mailer($cfg);
    foreach (explode(',', (string)$cfg('MAIL_TO', '')) as $to) {
        $to = trim($to);
        if ($to !== '') { $m->addAddress($to, (string)$cfg('MAIL_TO_NAME', '')); }
    }
    $m->addReplyTo($d['email'], $d['name']);
    $m->Subject = $subjNotify;
    $m->isHTML(true);
    $m->Body = $htmlNotify;
    $m->AltBody = $txtNotify;
    x25_dispatch($m, $TRANSPORT, (string)$cfg('MAIL_DUMP_DIR', sys_get_temp_dir() . '/25x-mails'), 'benachrichtigung');

    // (b) an den Anmelder
    $c = x25_mailer($cfg);
    $c->addAddress($d['email'], $d['name']);
    if ($replyToConfirm !== '' && PHPMailer::validateAddress($replyToConfirm)) { $c->addReplyTo($replyToConfirm, '25 EXPERTS'); }
    $c->Subject = $subjConfirm;
    $c->isHTML(true);
    $c->Body = $htmlConfirm;
    $c->AltBody = $txtConfirm;
    $c->addCustomHeader('Auto-Submitted', 'auto-replied');
    x25_dispatch($c, $TRANSPORT, (string)$cfg('MAIL_DUMP_DIR', sys_get_temp_dir() . '/25x-mails'), 'bestaetigung');
} catch (MailException $e) {
    error_log('25x anmeldung: Versand fehlgeschlagen (' . substr($e->getMessage(), 0, 200) . ')');   // keine personenbezogenen Daten
    x25_respond(false, 'Die Anmeldung konnte nicht übertragen werden. Bitte versuchen Sie es erneut oder schreiben Sie uns per E-Mail.', 502, 'versand');
} catch (Throwable $e) {
    error_log('25x anmeldung: Fehler ' . get_class($e));
    x25_respond(false, 'Die Anmeldung konnte nicht übertragen werden. Bitte versuchen Sie es erneut oder schreiben Sie uns per E-Mail.', 500, 'versand');
}

x25_respond(true, null, 200, null);

// ================================================================== Funktionen

/** Konfigurierter PHPMailer (SMTP). */
function x25_mailer(callable $cfg): PHPMailer
{
    $m = new PHPMailer(true);
    $m->CharSet = PHPMailer::CHARSET_UTF8;
    $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
    $m->XMailer = '25 EXPERTS Anmeldung';
    $m->isSMTP();
    $m->Host = (string)$cfg('SMTP_HOST', 'smtp.hostinger.com');
    $m->Port = (int)$cfg('SMTP_PORT', 465);
    $secure = (string)$cfg('SMTP_SECURE', 'ssl');
    $m->SMTPSecure = $secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $m->SMTPAuth = true;
    $m->Username = (string)$cfg('SMTP_USER', '');
    $m->Password = (string)$cfg('SMTP_PASS', '');
    $m->Timeout = 20;
    $m->setFrom((string)$cfg('MAIL_FROM', $m->Username), (string)$cfg('MAIL_FROM_NAME', '25 EXPERTS'));
    return $m;
}

/** Versand oder (Testmodus 'file') Ablage als .eml. */
function x25_dispatch(PHPMailer $m, string $transport, string $dumpDir, string $tag): void
{
    if ($transport === 'file') {
        if (!is_dir($dumpDir)) { @mkdir($dumpDir, 0700, true); }
        if (!$m->preSend()) { throw new MailException('preSend fehlgeschlagen'); }
        $file = rtrim($dumpDir, '/') . '/' . date('Ymd-His') . '-' . $tag . '-' . bin2hex(random_bytes(3)) . '.eml';
        file_put_contents($file, $m->getSentMIMEMessage());
        return;
    }
    $m->send();
}

/** Antwort: JSON (fetch) oder Redirect (klassisches Formular). Beendet das Skript. */
function x25_respond(bool $ok, ?string $error, int $status, ?string $reason, array $fields = []): void
{
    global $wantsJson, $THANKS_URL, $LANDING_URL;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $out = ['ok' => $ok];
        if (!$ok) { $out['error'] = $error ?? 'Fehler'; if ($fields) { $out['fields'] = array_keys($fields); } }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($ok) {
        header('Location: ' . $THANKS_URL, true, 303);
        exit;
    }
    header('Location: ' . $LANDING_URL . '?fehler=1&grund=' . rawurlencode($reason ?? 'versand') . '#anmeldung', true, 303);
    exit;
}

/** Notausgang vor der Konfiguration (kein config.php): schlichte Antwort. */
function x25_fail_early(string $msg): void
{
    http_response_code(500);
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ctype, 'application/json') || str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
}

/** Rate-Limit: höchstens $limit Einträge je IP-Hash und Zähler ($bucket) in $window Sekunden; Dateien in sys_get_temp_dir()/25x-anmeldung/. */
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
    // gelegentlich aufräumen: alte Sperrdateien löschen
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (@filemtime($f) < $now - 2 * $window) { @unlink($f); }
        }
    }
    return $ok;
}

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
    $s = trim($s);
    return mb_substr($s, 0, $max);
}

function x25_truthy($v): bool
{
    if (is_bool($v)) { return $v; }
    if (is_array($v)) { return false; }
    $s = strtolower(trim((string)$v));
    return in_array($s, ['ja', 'true', '1', 'on', 'yes'], true);
}

function x25_wrap(string $s): string
{
    return wordwrap($s, 76, "\n", false);
}

function x25_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
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
function x25_h_rows(array $rows): string
{
    $tr = '';
    foreach ($rows as [$k, $v]) {
        $val = x25_e($v);
        if (preg_match('~^https?://~i', $v)) { $val = '<a href="' . $val . '" style="color:' . X25_PETROL . ';">' . $val . '</a>'; }
        $tr .= '<tr><td valign="top" style="padding:6px 12px 6px 0;font-family:' . X25_MONO . ';font-size:13px;line-height:20px;color:' . X25_META . ';white-space:nowrap;">' . x25_e($k) . '</td>'
            . '<td valign="top" style="padding:6px 0;font-family:' . X25_FONT . ';font-size:15px;line-height:20px;color:' . X25_INK . ';word-break:break-word;">' . $val . '</td></tr>';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px 0;">' . $tr . '</table>';
}
function x25_html_shell(string $subject, string $body, string $edition, string $logoUrl, string $footer, bool $withLinks, string $preheader = '', string $siteUrl = ''): string
{
    $logo = $logoUrl !== ''
        ? '<img src="' . x25_e($logoUrl) . '" width="180" height="40" alt="25 EXPERTS" style="display:block;border:0;outline:none;width:180px;height:auto;font-family:' . X25_FONT . ';font-size:14px;font-weight:700;color:' . X25_INK . ';">'
        : '<span style="font-family:' . X25_FONT . ';font-size:16px;font-weight:700;letter-spacing:1px;color:' . X25_INK . ';">25 EXPERTS</span>';
    $pre = $preheader !== ''
        ? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:' . X25_PAPER . ';">' . x25_e($preheader) . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>'
        : '';
    $links = $withLinks && $siteUrl !== ''
        ? '<p style="margin:0;font-family:' . X25_FONT . ';font-size:12px;line-height:18px;color:' . X25_META . ';">'
          . '<a href="' . x25_e($siteUrl) . 'datenschutz" style="color:' . X25_PETROL . ';text-decoration:underline;">Datenschutz</a> · '
          . '<a href="' . x25_e($siteUrl) . 'impressum" style="color:' . X25_PETROL . ';text-decoration:underline;">Impressum</a></p>'
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
        . '<td align="right" valign="middle" style="font-family:' . X25_MONO . ';font-size:12px;line-height:16px;letter-spacing:1px;text-transform:uppercase;color:' . X25_META . ';">' . x25_e($edition) . '</td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="border-top:2px solid ' . X25_INK . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:32px 32px 16px 32px;border-left:1px solid ' . X25_LINE . ';border-right:1px solid ' . X25_LINE . ';border-bottom:1px solid ' . X25_LINE . ';">'
        . $body
        . '</td></tr>'
        . '<tr><td style="padding:24px 8px 8px 8px;">'
        . '<p style="margin:0 0 8px 0;font-family:' . X25_MONO . ';font-size:12px;line-height:18px;letter-spacing:1px;text-transform:uppercase;color:' . X25_INK . ';">25 EXPERTS</p>'
        . '<p style="margin:0 0 8px 0;font-family:' . X25_FONT . ';font-size:12px;line-height:18px;color:' . X25_META . ';">' . x25_e($footer) . '</p>'
        . $links
        . '</td></tr></table></td></tr></table></body></html>';
}
