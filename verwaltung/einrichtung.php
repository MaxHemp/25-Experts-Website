<?php
/**
 * 25 EXPERTS – Verwaltung: Zugang einrichten und Passwort ändern (ohne Terminal/Dateimanager).
 *
 * Erst-Einrichtung (noch kein Zugang gesetzt):
 *   1. „Code anfordern“ → ein Bestätigungscode geht per E-Mail an die Gastgeber-Adresse (MAIL_TO,
 *      info@25-experts.de). Damit kann nur das Team den Zugang anlegen.
 *   2. Code + Benutzername + Passwort eintragen → Zugang wird in anmeldung/data/verwaltung-zugang.json
 *      gespeichert (gilt für /verwaltung/ UND /anmeldung/admin.php).
 * Passwort ändern (eingerichtet): normale Anmeldung nötig, dann neues Passwort setzen.
 * Schutz: Code gehasht + 15 Minuten gültig + höchstens 5 Versuche; Anforderungen ratenbegrenzt.
 */
declare(strict_types=1);

define('XV_OHNE_GATE', 1);
require __DIR__ . '/auth.php';

$e = static fn(?string $s): string => x25ed_e($s);

if (!is_file(X25ED_ROOT . '/anmeldung/config.php')) {
    xv_page('Einrichtung', '<div class="v-card v-card--warn"><h1>Der Server ist noch nicht konfiguriert.</h1>'
        . '<p>Auf dem Webspace fehlt die Datei <code>anmeldung/config.php</code> (sie enthält u. a. den Mail-Versand und wird auch für die Anmeldestrecke gebraucht). '
        . 'Bitte zuerst nach der Anleitung in <code>anmeldung/config.example.php</code> anlegen, dann diese Seite neu laden.</p></div>', 503);
}

$zugang = x25ed_zugang();
$eingerichtet = $zugang['hash'] !== '';
$flash = '';
$modus = $eingerichtet ? 'aendern' : (is_setup_code_offen() ? 'code' : 'start');

// ------------------------------------------------------------------ Aktionen
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    try {
        if (!$eingerichtet && $do === 'code') {
            require_once X25ED_ROOT . '/anmeldung/lib/x25.php';
            if (!x25_rate_ok(3, 3600, (string)x25ed_cfg('RATE_SALT', 'x25'), 'vcode')) {
                throw new RuntimeException('Zu viele Code-Anforderungen. Bitte in einer Stunde erneut versuchen.');
            }
            $code = (string)random_int(10000000, 99999999);
            x25ed_zugang_merge(['setup_code' => password_hash($code, PASSWORD_DEFAULT), 'setup_bis' => time() + 900, 'setup_versuche' => 0]);
            $mailTo = x25_conf()['mail_to'];
            $txt = "Einrichtungscode für die 25-EXPERTS-Verwaltung (gültig 15 Minuten):\n\n    " . $code . "\n\n"
                 . "Diesen Code auf https://25-experts.de/verwaltung/einrichtung.php eintragen und dort Benutzername und Passwort festlegen.\n"
                 . "Wenn Ihr diese Einrichtung nicht angestoßen habt, könnt Ihr die Mail ignorieren.\n";
            $html = x25_html_shell('Einrichtungscode Verwaltung',
                x25_h_kicker('Verwaltung einrichten') . x25_h_h1('Euer Einrichtungscode')
                . x25_h_box('<p style="margin:0;font-family:' . X25_MONO . ';font-size:32px;letter-spacing:6px;font-weight:700;color:' . X25_INK . ';">' . $e($code) . '</p>')
                . x25_h_p('Gültig 15 Minuten. Auf <a href="https://25-experts.de/verwaltung/einrichtung.php" style="color:' . X25_PETROL . ';">25-experts.de/verwaltung/einrichtung.php</a> eintragen und Benutzername und Passwort festlegen.')
                . x25_h_p('<span style="font-size:13px;color:' . X25_META . ';">Wenn Ihr diese Einrichtung nicht angestoßen habt, könnt Ihr die Mail ignorieren.</span>'), false);
            x25_send_hosts('Einrichtungscode Verwaltung · 25 EXPERTS', $html, $txt, 'verwaltung-code');
            $modus = 'code';
            $flash = 'Der Code ist unterwegs an ' . $mailTo . ' (gültig 15 Minuten).';
        } elseif (!$eingerichtet && $do === 'setzen') {
            $d = is_file(x25ed_zugang_datei()) ? (json_decode((string)file_get_contents(x25ed_zugang_datei()), true) ?: []) : [];
            $codeHash = (string)($d['setup_code'] ?? '');
            if ($codeHash === '' || time() > (int)($d['setup_bis'] ?? 0)) { throw new RuntimeException('Kein gültiger Code (abgelaufen?). Bitte einen neuen Code anfordern.'); }
            if ((int)($d['setup_versuche'] ?? 0) >= 5) {
                x25ed_zugang_merge(['setup_code' => '', 'setup_bis' => 0]);
                throw new RuntimeException('Zu viele Fehlversuche. Bitte einen neuen Code anfordern.');
            }
            $code = preg_replace('/\s+/', '', (string)($_POST['code'] ?? '')) ?? '';
            if (!password_verify($code, $codeHash)) {
                x25ed_zugang_merge(['setup_versuche' => (int)($d['setup_versuche'] ?? 0) + 1]);
                $modus = 'code';
                throw new RuntimeException('Der Code stimmt nicht. Bitte prüfen (8 Ziffern aus der E-Mail).');
            }
            [$user, $hash] = xv_neues_passwort();
            x25ed_zugang_merge(['user' => $user, 'hash' => $hash, 'setup_code' => '', 'setup_bis' => 0, 'setup_versuche' => 0, 'eingerichtet_am' => gmdate('c')]);
            xv_page('Zugang eingerichtet', '<div class="v-card v-card--ok"><h1>Der Zugang ist eingerichtet.</h1>'
                . '<p>Benutzername: <strong>' . $e($user) . '</strong> · Dein Passwort gilt ab sofort für die <a href="index.php">Verwaltung</a> und die <a href="/anmeldung/admin.php">Anmeldungs-Übersicht</a>.</p>'
                . '<p><a class="v-btn v-btn--gross" href="index.php">Zur Verwaltung</a></p></div>');
        } elseif ($do === 'aendern') {
            xv_gate();   // nur mit gültiger Anmeldung
            if (!xv_csrf_ok((string)($_POST['csrf'] ?? ''))) { throw new RuntimeException('Sitzung abgelaufen. Bitte Seite neu laden und erneut versuchen.'); }
            [$user, $hash] = xv_neues_passwort();
            x25ed_zugang_merge(['user' => $user, 'hash' => $hash, 'geaendert_am' => gmdate('c')]);
            xv_page('Passwort geändert', '<div class="v-card v-card--ok"><h1>Zugang aktualisiert.</h1>'
                . '<p>Benutzername: <strong>' . $e($user) . '</strong>. Beim nächsten Aufruf fragt der Browser die neuen Zugangsdaten ab (ggf. Browser-Fenster einmal schließen).</p>'
                . '<p><a class="v-btn" href="index.php">Zur Verwaltung</a></p></div>');
        }
    } catch (Throwable $ex) {
        $flash = 'Fehler: ' . $ex->getMessage();
    }
}

/** Benutzername/Passwort aus dem Formular prüfen → [user, hash]. */
function xv_neues_passwort(): array
{
    $user = trim((string)($_POST['benutzer'] ?? ''));
    $pw1 = (string)($_POST['passwort'] ?? '');
    $pw2 = (string)($_POST['passwort2'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $user)) { throw new RuntimeException('Bitte einen Benutzernamen aus 3–40 Buchstaben/Ziffern angeben (ohne Leerzeichen).'); }
    if (mb_strlen($pw1) < 10) { throw new RuntimeException('Das Passwort braucht mindestens 10 Zeichen.'); }
    if ($pw1 !== $pw2) { throw new RuntimeException('Die beiden Passwörter stimmen nicht überein.'); }
    return [$user, password_hash($pw1, PASSWORD_DEFAULT)];
}

function is_setup_code_offen(): bool
{
    $f = x25ed_zugang_datei();
    if (!is_file($f)) { return false; }
    $d = json_decode((string)file_get_contents($f), true) ?: [];
    return ($d['setup_code'] ?? '') !== '' && time() <= (int)($d['setup_bis'] ?? 0);
}

// ------------------------------------------------------------------ Seite
$flashHtml = $flash !== '' ? '<div class="v-card ' . (str_starts_with($flash, 'Fehler') ? 'v-card--warn' : 'v-card--ok') . '"><strong>' . $e($flash) . '</strong></div>' : '';

if ($eingerichtet) {
    xv_gate();
    $csrf = xv_csrf();
    $body = '<p class="v-kicker"><a href="index.php">Verwaltung</a> → Passwort</p><div class="v-kopfzeile"><div><h1>Passwort ändern</h1>'
        . '<p class="v-meta v-maxw">Die Zugangsdaten gelten für die Verwaltung und die Anmeldungs-Übersicht. Sie liegen nur auf dem Server (anmeldung/data/), nie im Git.</p></div></div>'
        . $flashHtml
        . '<form method="post" action="einrichtung.php" class="v-card v-form"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="do" value="aendern">'
        . '<div class="v-form__grid">'
        . '<label class="v-feld"><span>Benutzername</span><input type="text" name="benutzer" value="' . $e($zugang['user']) . '" autocomplete="username"></label>'
        . '<label class="v-feld"><span>Neues Passwort <em>mind. 10 Zeichen</em></span><input type="password" name="passwort" autocomplete="new-password"></label>'
        . '<label class="v-feld"><span>Neues Passwort (Wiederholung)</span><input type="password" name="passwort2" autocomplete="new-password"></label>'
        . '</div><p class="v-mt"><button class="v-btn v-btn--gross" type="submit">Speichern</button></p></form>';
    xv_page('Passwort ändern', $body);
}

// Erst-Einrichtung
$schrittCode = <<<HTML
    <form method="post" action="einrichtung.php" class="v-card v-form">
      <input type="hidden" name="do" value="setzen">
      <h2>Schritt 2: Code eintragen und Zugang festlegen</h2>
      <div class="v-form__grid">
        <label class="v-feld"><span>Bestätigungscode <em>aus der E-Mail</em></span><input type="text" name="code" inputmode="numeric" placeholder="8 Ziffern" autocomplete="one-time-code"></label>
        <label class="v-feld"><span>Benutzername</span><input type="text" name="benutzer" value="gastgeber" autocomplete="username"></label>
        <label class="v-feld"><span>Passwort <em>mind. 10 Zeichen</em></span><input type="password" name="passwort" autocomplete="new-password"></label>
        <label class="v-feld"><span>Passwort (Wiederholung)</span><input type="password" name="passwort2" autocomplete="new-password"></label>
      </div>
      <p class="v-mt"><button class="v-btn v-btn--gross" type="submit">Zugang einrichten</button></p>
      <p class="v-meta">Kein Code angekommen? Spam-Ordner prüfen oder unten einen neuen anfordern.</p>
    </form>
HTML;
$schrittStart = <<<HTML
    <form method="post" action="einrichtung.php" class="v-card v-form">
      <input type="hidden" name="do" value="code">
      <h2>Schritt 1: Bestätigungscode anfordern</h2>
      <p class="v-meta v-maxw">Zum Schutz vor Fremden schickt der Server einen Code an die Gastgeber-Adresse (info@25-experts.de). Nur wer dieses Postfach lesen kann, kann den Zugang einrichten.</p>
      <p class="v-mt"><button class="v-btn v-btn--gross" type="submit">Code per E-Mail anfordern</button></p>
    </form>
HTML;

$body = '<p class="v-kicker">Verwaltung</p><div class="v-kopfzeile"><div><h1>Verwaltung einrichten</h1>'
    . '<p class="v-meta v-maxw">Einmalige Einrichtung des Team-Zugangs für die Editions-Verwaltung und die Anmeldungs-Übersicht. Dauert eine Minute.</p></div></div>'
    . $flashHtml
    . ($modus === 'code' ? $schrittCode . $schrittStart : $schrittStart);
xv_page('Einrichtung', $body);
