<?php
/**
 * 25 EXPERTS – Verwaltung: Zugangsschutz und Seitenhülle des Backends.
 * Zugang wie beim Anmeldungs-Admin: HTTP-Basic-Auth mit ADMIN_USER / ADMIN_PASS_HASH aus
 * anmeldung/config.php; CSRF-Token (HMAC über APP_SECRET, stündlich wechselnd) für alle POSTs.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/edition/lib.php';

function xv_page(string $titel, string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Cache-Control: no-store', true);
    $e = static fn(?string $s): string => x25ed_e($s);
    echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{$e($titel)} · Verwaltung · 25 EXPERTS</title>
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="verwaltung.css">
</head>
<body>
  <header class="v-top">
    <div class="v-wrap v-top__inner">
      <a class="v-top__logo" href="index.php"><img src="/assets/img/25experts-logo-horizontal.svg" alt="25 EXPERTS" width="180" height="40"></a>
      <nav class="v-top__nav">
        <a href="index.php">Editionen</a>
        <a href="/anmeldung/admin.php">Anmeldungen</a>
        <a href="/" target="_blank" rel="noopener">Website ansehen ↗</a>
      </nav>
    </div>
  </header>
  <main class="v-wrap v-main">
{$body}
  </main>
  <footer class="v-wrap v-foot">Verwaltung · nur für das 25-EXPERTS-Team · Änderungen sind sofort auf der Website sichtbar (Browser-Cache: bis zu 10 Minuten).</footer>
  <script src="verwaltung.js" defer></script>
</body>
</html>
HTML;
    exit;
}

// ------------------------------------------------------------------ Zugang
$XV_USER = (string)x25ed_cfg('ADMIN_USER', '');
$XV_HASH = (string)x25ed_cfg('ADMIN_PASS_HASH', '');
if ($XV_USER === '' || $XV_HASH === '') {
    xv_page('Nicht eingerichtet', '<div class="v-card v-card--warn"><h1>Verwaltung ist noch nicht eingerichtet.</h1>'
        . '<p>Bitte in <code>anmeldung/config.php</code> auf dem Server <code>ADMIN_USER</code> und <code>ADMIN_PASS_HASH</code> setzen '
        . '(Passwort-Hash erzeugen: <code>php -r \'echo password_hash("DeinPasswort", PASSWORD_DEFAULT);\'</code>).</p></div>', 503);
}
$xvCred = ['', ''];
if (isset($_SERVER['PHP_AUTH_USER'])) {
    $xvCred = [(string)$_SERVER['PHP_AUTH_USER'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')];
} else {
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($hdr, 'basic ') === 0) {
        $dec = base64_decode(trim(substr($hdr, 6)), true);
        if ($dec !== false && str_contains($dec, ':')) { $xvCred = explode(':', $dec, 2); }
    }
}
if ($xvCred[0] !== $XV_USER || !password_verify($xvCred[1], $XV_HASH)) {
    header('WWW-Authenticate: Basic realm="25 EXPERTS Verwaltung", charset="UTF-8"');
    xv_page('Anmeldung erforderlich', '<div class="v-card"><h1>Anmeldung erforderlich.</h1><p>Bitte mit dem Team-Benutzer und Passwort anmelden (dieselben Zugangsdaten wie für die Anmeldungs-Übersicht).</p></div>', 401);
}

function xv_csrf(int $shift = 0): string
{
    $secret = (string)x25ed_cfg('APP_SECRET', '');
    return hash_hmac('sha256', 'verwaltung|' . (intdiv(time(), 3600) - $shift), $secret);
}
function xv_csrf_ok(string $tok): bool
{
    return $tok !== '' && (hash_equals(xv_csrf(), $tok) || hash_equals(xv_csrf(1), $tok));
}
function xv_flash_url(string $seite, string $msg, array $extra = []): string
{
    return $seite . '?' . http_build_query($extra + ['m' => mb_substr($msg, 0, 300)]);
}
function xv_badge(array $ed): string
{
    $s = (string)($ed['status'] ?? 'entwurf');
    return '<span class="v-badge v-badge--' . x25ed_e($s) . '">' . x25ed_e(X25ED_STATUS[$s] ?? $s) . '</span>';
}
/** Link auf die öffentliche Seite; Entwürfe mit Vorschau-Signatur. */
function xv_ansehen_url(array $ed): string
{
    $url = x25ed_url($ed);
    if (($ed['status'] ?? '') !== 'online') {
        $sig = x25ed_preview_sig((string)$ed['slug']);
        if ($sig !== '') { $url .= '?vorschau=' . $sig; }
    }
    return $url;
}
