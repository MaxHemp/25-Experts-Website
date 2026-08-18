<?php
/**
 * 25 EXPERTS – Anmeldeformular-Handler (Schritt 1 der Anmeldestrecke)
 * Nimmt die verbindliche Anmeldung der Landingpage (editionen/change-management/#anmeldung) entgegen:
 *   - als JSON (fetch aus assets/js/site.js)  → Antwort JSON {ok:true,status:"zugelassen"|"pruefung"|"warteliste"} / {ok:false,error,fields}
 *   - als klassisches Formular-POST (ohne JS)  → Redirect 303 auf danke.html?status=… bzw. zurück mit ?fehler=1&grund=…
 * Prüft Pflichtfelder (Feldliste v5), Honeypot, Rate-Limit, Origin, Header-Injection; speichert die Anmeldung
 * (lib/store.php, data/) und entscheidet die Zulassung automatisch:
 *   E-Mail-Domain auf data/domains-zugelassen.txt  → zugelassen: Zusage + Zahlungsaufforderung sofort (Warteliste, wenn MAX_SEATS belegt)
 *   Freemail-Domain oder unbekannte Firmendomain   → prüfung: Eingangsbestätigung; Gastgeber erhalten Prüf-Mail mit Zulassen-/Absagen-Link
 * Konfiguration: config.php (aus config.example.php), PHP >= 8.1, PHPMailer in lib/.
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store', true);

$C = x25_conf();
$RATE_LIMIT   = (int)x25_cfg('RATE_LIMIT', 5);
$RATE_WINDOW  = (int)x25_cfg('RATE_WINDOW', 3600);
$RATE_SALT    = (string)x25_cfg('RATE_SALT', 'x25');
$CHECK_ORIGIN = (bool)x25_cfg('CHECK_ORIGIN', true);

// ------------------------------------------------------------------ Anfrage einordnen
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctype  = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$isJson = str_contains($ctype, 'application/json');
$wantsJson = $isJson || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));

if ($method !== 'POST') {
    header('Location: ' . $C['landing'] . '#anmeldung', true, 302);   // Direktaufruf: zur Anmeldung leiten
    exit;
}

// Origin-Prüfung (Browser senden Origin bei POST; wenn vorhanden, muss er zur eigenen Domain passen)
if ($CHECK_ORIGIN && !empty($_SERVER['HTTP_ORIGIN'])) {
    $originHost = strtolower((string)parse_url((string)$_SERVER['HTTP_ORIGIN'], PHP_URL_HOST));
    $siteHost   = strtolower((string)parse_url($C['site'], PHP_URL_HOST));
    $allowed = [$siteHost, 'www.' . $siteHost, preg_replace('/^www\./', '', $siteHost)];
    if ($originHost === '' || !in_array($originHost, $allowed, true)) {
        x25_respond(false, 'Anfrage abgelehnt.', 403, 'versand');
    }
}

// Daten lesen
if ($isJson) {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 65536) { x25_respond(false, 'Ungültige Anfrage.', 400, 'pflicht'); }
    $in = json_decode($raw, true);
    if (!is_array($in)) { x25_respond(false, 'Ungültige Anfrage.', 400, 'pflicht'); }
} else {
    $in = $_POST;
}

// Honeypot: Bots füllen „website"; still als Erfolg beantworten, nichts senden, nichts speichern
if (isset($in['website']) && trim((string)$in['website']) !== '') {
    x25_respond(true, null, 200, null, [], 'pruefung');
}

// ------------------------------------------------------------------ Rate-Limit (Datei, IP-Hash, kein Klartext)
// Zwei Zähler je IP-Hash: alle Versuche (großzügig, gegen Hämmern) und tatsächlich angenommene Anmeldungen (RATE_LIMIT).
$RATE_MSG = 'Zu viele Versuche in kurzer Zeit. Bitte versuchen Sie es in einer Stunde erneut.';
if ($RATE_LIMIT > 0 && !x25_rate_ok($RATE_LIMIT * 6, $RATE_WINDOW, $RATE_SALT, 'v')) {
    x25_respond(false, $RATE_MSG, 429, 'limit');
}

// ------------------------------------------------------------------ Validierung (Feldliste v5)
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
$privacy       = x25_truthy($in['privacy'] ?? ($in['consent'] ?? null));   // v5: privacy (consent = ältere Seitenversion)
$d['source']   = x25_line($in['source'] ?? '', 500);
$edition_in    = x25_line($in['edition'] ?? '', 200);
$d['edition']  = $edition_in !== '' ? $edition_in : $C['edition'];

foreach (['name' => 'Name', 'company' => 'Unternehmen', 'role' => 'Rolle', 'question' => 'Ihre eine offene Frage'] as $k => $label) {
    if ($d[$k] === '') { $errors[$k] = $label . ' fehlt.'; }
}
if ($d['level'] === '' || !isset(X25_LEVELS[$d['level']])) { $errors['level'] = 'Ebene fehlt oder ist ungültig.'; }
if ($d['category'] === '' || !isset(X25_CATEGORIES[$d['category']])) { $errors['category'] = 'Unternehmenstyp fehlt oder ist ungültig.'; }
if (!$privacy) { $errors['privacy'] = 'Bitte bestätigen Sie den Hinweis zum Datenschutz.'; }
$emailOk = $d['email'] !== ''
    && filter_var($d['email'], FILTER_VALIDATE_EMAIL) !== false
    && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $d['email']) === 1
    && PHPMailer\PHPMailer\PHPMailer::validateAddress($d['email']);
if (!$emailOk) { $errors['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an.'; }
if ($d['linkedin'] !== '' && (!preg_match('~^https?://~i', $d['linkedin']) || filter_var($d['linkedin'], FILTER_VALIDATE_URL) === false)) {
    $errors['linkedin'] = 'Bitte geben Sie eine vollständige LinkedIn-Adresse mit https:// an.';
}
if ($d['source'] !== '' && !str_starts_with($d['source'], $C['site']) && !str_starts_with($d['source'], 'http://localhost')) {
    $d['source'] = '';   // fremde/unerwartete Herkunft nicht übernehmen
}
if ($errors) {
    $reason = (count($errors) === 1 && isset($errors['email'])) ? 'email' : 'pflicht';
    x25_respond(false, 'Bitte prüfen Sie Ihre Angaben: ' . implode(' ', array_values($errors)), 422, $reason, $errors);
}
if ($RATE_LIMIT > 0 && !x25_rate_ok($RATE_LIMIT, $RATE_WINDOW, $RATE_SALT, 'm')) {
    x25_respond(false, $RATE_MSG, 429, 'limit');
}

// ------------------------------------------------------------------ Zulassung entscheiden und speichern
$domainResult = x25_domain_check($d['email']);   // zugelassen | freemail | unbekannt
$rec = $d + [
    'token' => x25_token(16), 'action_nonce' => x25_token(12), 'created_at' => gmdate('c'),
    'status' => 'pruefung', 'payment_method' => '', 'payment_status' => 'offen',
    'admission_note' => match ($domainResult) {
        'zugelassen' => 'automatisch: Domain auf Allowlist',
        'freemail' => 'Freemail-Adresse, manuelle Prüfung',
        default => 'unbekannte Domain, manuelle Prüfung',
    },
];
$id = 0;
try {
    $store = x25_store();
    // Platzvergabe unter Sperre: Allowlist-Treffer werden zugelassen, solange Plätze frei sind, sonst Warteliste
    $store->transaction(function (X25Store $s) use (&$rec, &$id, $domainResult) {
        if ($domainResult === 'zugelassen') {
            $rec['status'] = x25_seats_taken($s->all()) < x25_conf()['max_seats'] ? 'zugelassen' : 'warteliste';
            $rec['decided_at'] = gmdate('c'); $rec['decided_by'] = 'automatisch';
        }
        $id = $s->insert($rec);
    });
    $rec = $store->get($id);

    // Mails: (a) an den Anmelder je nach Status, (b) an die Gastgeber (Info bzw. Prüfauftrag mit Links)
    match ($rec['status']) {
        'zugelassen' => x25_mail_zusage($rec),
        'warteliste' => x25_mail_warteliste($rec),
        default => x25_mail_pruefung($rec, $domainResult === 'freemail'),
    };
    x25_mail_hosts_neu($rec);
} catch (PHPMailer\PHPMailer\Exception $e) {
    x25_log('Versand fehlgeschlagen (' . substr($e->getMessage(), 0, 200) . ')');   // keine personenbezogenen Daten
    if ($id > 0) { try { x25_store()->deleteWhere(static fn($r) => (int)$r['id'] === $id); } catch (Throwable) {} }   // kein Datensatz ohne Bestätigung
    x25_respond(false, 'Die Anmeldung konnte nicht übertragen werden. Bitte versuchen Sie es erneut oder schreiben Sie uns per E-Mail.', 502, 'versand');
} catch (Throwable $e) {
    x25_log('Fehler ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 120));
    if ($id > 0) { try { x25_store()->deleteWhere(static fn($r) => (int)$r['id'] === $id); } catch (Throwable) {} }
    x25_respond(false, 'Die Anmeldung konnte nicht übertragen werden. Bitte versuchen Sie es erneut oder schreiben Sie uns per E-Mail.', 500, 'versand');
}

x25_respond(true, null, 200, null, [], $rec['status']);

// ================================================================== Antwort
/** JSON (fetch) oder Redirect (klassisches Formular). Beendet das Skript. */
function x25_respond(bool $ok, ?string $error, int $status, ?string $reason, array $fields = [], string $state = ''): void
{
    global $wantsJson, $C;
    if ($wantsJson) {
        $out = ['ok' => $ok];
        if ($ok) { $out['status'] = $state; }
        else { $out['error'] = $error ?? 'Fehler'; if ($fields) { $out['fields'] = array_keys($fields); } }
        x25_json($out, $status);
    }
    if ($ok) {
        header('Location: ' . $C['thanks'] . ($state !== '' ? '?status=' . rawurlencode($state) : ''), true, 303);
        exit;
    }
    header('Location: ' . $C['landing'] . '?fehler=1&grund=' . rawurlencode($reason ?? 'versand') . '#anmeldung', true, 303);
    exit;
}
