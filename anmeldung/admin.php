<?php
/**
 * 25 EXPERTS – Admin der Anmeldestrecke: admin.php (HTTP-Basic-Auth: ADMIN_USER / ADMIN_PASS_HASH aus config.php)
 * Liste aller Anmeldungen (Status, Unternehmenstyp, Ebene, Zahlungsweg, Zahlungsstatus, Rechnung, Ticket), Platzzähler,
 * Aktionen je Anmeldung: Zulassen · Absagen · Zahlung eingegangen · Ticket erneut senden · Rechnung/Ticket ansehen,
 * CSV-Export (?export=csv), Löschroutine („Daten der Edition löschen": alle Anmeldungen vor einem Datum).
 * Schutz: Basic-Auth (Passwort-Hash), CSRF-Token (HMAC, stündlich wechselnd) für alle POST-Aktionen, keine Aktionen per GET.
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

// ------------------------------------------------------------------ Basic-Auth
// Zugangsdaten: über /verwaltung/einrichtung.php gesetzt (anmeldung/data/verwaltung-zugang.json)
// oder ADMIN_USER/ADMIN_PASS_HASH aus config.php.
require_once dirname(__DIR__) . '/edition/lib.php';
['user' => $user, 'hash' => $hash] = x25ed_zugang();
if ($hash === '') {
    x25_out(x25_page('Admin nicht eingerichtet', '<h1>Admin ist nicht eingerichtet.</h1><p>Bitte einmalig den Team-Zugang anlegen: <a href="../verwaltung/einrichtung.php">Verwaltung einrichten</a>.</p>'), 503);
}
[$u, $p] = x25_basic_credentials();
if ($u !== $user || !password_verify($p, $hash)) {
    header('WWW-Authenticate: Basic realm="25 EXPERTS Anmeldungen", charset="UTF-8"');
    x25_out(x25_page('Anmeldung erforderlich', '<h1>Anmeldung erforderlich.</h1><p>Bitte mit Admin-Benutzer und Passwort anmelden (dieselben Zugangsdaten wie für die Verwaltung).</p><p class="meta">Zugangsdaten vergessen? <a href="../verwaltung/einrichtung.php">Zugang zurücksetzen</a> – ein Bestätigungscode geht an das Gastgeber-Postfach (info@25-experts.de), damit legst Du Benutzername und Passwort neu fest.</p>'), 401);
}
$csrf = x25_csrf_token($user);
$store = x25_store(); $C = x25_conf();

// ------------------------------------------------------------------ Aktionen (POST + CSRF)
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!x25_csrf_ok($user, (string)($_POST['csrf'] ?? ''))) { x25_out(x25_page('Abgelehnt', '<h1>Sitzung abgelaufen.</h1><p>Bitte zurück und erneut ausführen.</p>'), 403); }
    $do = (string)($_POST['do'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    try {
        if ($do === 'loeschen') {
            $before = (string)($_POST['vor'] ?? '');
            if (($_POST['bestaetigung'] ?? '') !== 'LÖSCHEN') { throw new RuntimeException('Bitte „LÖSCHEN" in das Bestätigungsfeld schreiben.'); }
            $limit = $before !== '' ? (new DateTimeImmutable($before . ' 23:59:59'))->getTimestamp() : time();
            $n = $store->deleteWhere(static fn($r) => (int)strtotime((string)$r['created_at']) <= $limit);
            $flash = $n . ' Anmeldung(en) gelöscht (eingegangen bis ' . ($before !== '' ? $before : 'heute') . ').';
        } elseif ($do === 'testmail') {
            $an = trim((string)($_POST['an'] ?? ''));
            if ($an === '') { $an = trim(explode(',', $C['mail_to'])[0]); }
            if (!PHPMailer\PHPMailer\PHPMailer::validateAddress($an)) { throw new RuntimeException('Bitte eine gültige Empfängeradresse angeben.'); }
            $m = x25_mailer();
            $m->addAddress($an);
            $m->Subject = '25 EXPERTS – Test-Mail der Anmeldestrecke';
            $m->isHTML(false);
            $m->Body = 'Diese Test-Mail wurde über admin.php ausgelöst (' . date('d.m.Y H:i:s') . ").\n"
                . 'Transport: ' . $C['transport'] . ' · SMTP-Host: ' . (string)x25_cfg('SMTP_HOST', '') . ' · Absender: ' . (string)x25_cfg('MAIL_FROM', '') . "\n"
                . 'Kommt diese Mail an, funktioniert der Versand. Prüfe sonst das Mail-Protokoll unten auf der Admin-Seite.';
            try {
                x25_dispatch($m, 'testmail');
                $flash = $C['transport'] === 'smtp'
                    ? 'Test-Mail an ' . $an . ' übergeben. Bitte Posteingang UND Spam-Ordner prüfen.'
                    : 'ACHTUNG Testmodus (MAIL_TRANSPORT ' . $C['transport'] . '): Test-Mail wurde NICHT verschickt, nur als Datei abgelegt.';
            } catch (PHPMailer\PHPMailer\Exception $e) {
                throw new RuntimeException('SMTP-Fehler: ' . mb_substr($m->ErrorInfo !== '' ? $m->ErrorInfo : $e->getMessage(), 0, 220));
            }
        } else {
            $rec = $store->get($id);
            if ($rec === null) { throw new RuntimeException('Datensatz nicht gefunden.'); }
            switch ($do) {
                case 'zulassen':
                    $rec = x25_admit($rec, 'admin'); $flash = 'Zugelassen: ' . $rec['name'] . ' (Zusage + Zahlungsaufforderung versandt).'; break;
                case 'absagen':
                    $reason = in_array($_POST['reason'] ?? '', ['zielgruppe', 'ebene', 'voll'], true) ? (string)$_POST['reason'] : 'zielgruppe';
                    $rec = x25_reject($rec, 'admin', $reason); $flash = 'Abgesagt: ' . $rec['name'] . ' (Absage versandt).'; break;
                case 'bezahlt':
                    if ($rec['status'] !== 'zugelassen') { throw new RuntimeException('Nur zugelassene Anmeldungen können als bezahlt markiert werden.'); }
                    if ($rec['payment_status'] === 'bezahlt') { throw new RuntimeException('War bereits bezahlt.'); }
                    $rec = x25_mark_paid($rec, ($rec['payment_method'] ?? '') !== '' ? $rec['payment_method'] : 'manuell', 'admin');
                    $flash = 'Zahlung verbucht: ' . $rec['name'] . ', Ticket ' . $rec['ticket_no'] . ' versandt.'; break;
                case 'ticket':
                    if ($rec['payment_status'] !== 'bezahlt') { throw new RuntimeException('Kein Ticket vorhanden (noch nicht bezahlt).'); }
                    x25_send_ticket($rec); $flash = 'Ticket ' . $rec['ticket_no'] . ' erneut an ' . $rec['email'] . ' gesandt.'; break;
                case 'rechnung':
                    if ($rec['status'] !== 'zugelassen') { throw new RuntimeException('Nur für zugelassene Anmeldungen.'); }
                    $rec = x25_choose_invoice($rec); $flash = 'Rechnung ' . $rec['invoice_no'] . ' an ' . $rec['email'] . ' gesandt.'; break;
                case 'warteliste':
                    $rec = x25_waitlist($rec, 'admin'); $flash = 'Auf die Warteliste gesetzt: ' . $rec['name'] . '.'; break;
                default: throw new RuntimeException('Unbekannte Aktion.');
            }
        }
    } catch (Throwable $e) {
        $flash = 'Fehler: ' . $e->getMessage();
        x25_log('admin ' . ($do ?? '') . ': ' . get_class($e));
    }
    header('Location: admin.php?m=' . rawurlencode(mb_substr($flash, 0, 300)), true, 303);
    exit;
}
$flash = (string)($_GET['m'] ?? '');

// ------------------------------------------------------------------ Daten
$all = $store->all();
// v8: mehrere Editionen – Filter über ?edition=slug (Altdatensätze zählen zur Standard-Edition)
$edFilter = (string)($_GET['edition'] ?? '');
$slugOf = static fn(array $r): string => (string)($r['edition_slug'] ?? '') !== '' ? (string)$r['edition_slug'] : x25_default_slug();
$slugs = [];
foreach ($all as $r) { $slugs[$slugOf($r)] = true; }
try { require_once dirname(__DIR__) . '/edition/lib.php'; foreach (x25ed_all() as $edRow) { $slugs[(string)$edRow['slug']] = true; } } catch (Throwable) {}
$slugs = array_keys($slugs); sort($slugs);
if ($edFilter !== '' && preg_match('/^[a-z0-9-]{1,60}$/', $edFilter)) {
    $all = array_values(array_filter($all, static fn($r) => $slugOf($r) === $edFilter));
} else { $edFilter = ''; }
if ($edFilter !== '') {
    $edInfo = function_exists('x25ed_get') ? x25ed_get($edFilter) : null;
    $taken = x25_seats_taken($store->all(), $edFilter);
    $max = $edInfo !== null ? (int)($edInfo['max_plaetze'] ?? 25) : $C['max_seats'];
} else {
    $taken = x25_seats_taken($all); $max = $C['max_seats'];
}
$count = ['pruefung' => 0, 'zugelassen' => 0, 'abgesagt' => 0, 'warteliste' => 0, 'bezahlt' => 0];
foreach ($all as $r) { $count[$r['status']] = ($count[$r['status']] ?? 0) + 1; if ($r['payment_status'] === 'bezahlt') { $count['bezahlt']++; } }

// CSV-Export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="25experts-anmeldungen-' . date('Ymd') . '.csv"'); header('Cache-Control: no-store');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    $cols = ['id', 'created_at', 'name', 'company', 'role', 'level', 'category', 'email', 'linkedin', 'question', 'status', 'admission_note', 'decided_at', 'decided_by', 'payment_method', 'payment_status', 'paid_at', 'invoice_no', 'invoice_date', 'invoice_due', 'ticket_no', 'ticket_sent_at', 'paypal_order_id', 'paypal_capture_id', 'edition', 'edition_slug', 'source'];
    fputcsv($out, $cols, ';');
    foreach (array_reverse($all) as $r) { fputcsv($out, array_map(static fn($c) => (string)($r[$c] ?? ''), $cols), ';'); }
    exit;
}

// ------------------------------------------------------------------ Seite
$h = static fn($s) => x25_e((string)$s);
$btn = static function (int $id, string $do, string $label, string $cls = 'btn small', string $extra = '') use ($csrf) {
    return '<form method="post" action="admin.php" style="display:inline"' . ($do === 'absagen' ? ' data-confirm="Absage wirklich senden?"' : '') . '><input type="hidden" name="csrf" value="' . x25_e($csrf) . '"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="id" value="' . $id . '">' . $extra . '<button class="' . $cls . '" type="submit">' . x25_e($label) . '</button></form>';
};
$rowsHtml = '';
foreach ($all as $r) {
    $acts = '';
    if (in_array($r['status'], ['pruefung', 'warteliste', 'abgesagt'], true)) { $acts .= $btn((int)$r['id'], 'zulassen', 'Zulassen'); }
    if (in_array($r['status'], ['pruefung', 'warteliste', 'zugelassen'], true) && $r['payment_status'] !== 'bezahlt') {
        $acts .= $btn((int)$r['id'], 'absagen', 'Absagen', 'btn small danger', '<select name="reason" style="padding:4px;font-size:12px"><option value="zielgruppe">nicht Zielgruppe</option><option value="ebene">Ebene</option><option value="voll">voll</option></select> ');
    }
    if ($r['status'] === 'pruefung') { $acts .= $btn((int)$r['id'], 'warteliste', 'Warteliste', 'btn small sec'); }
    if ($r['status'] === 'zugelassen' && $r['payment_status'] !== 'bezahlt') {
        $acts .= $btn((int)$r['id'], 'bezahlt', 'Zahlung eingegangen');
        if (empty($r['invoice_no'])) { $acts .= $btn((int)$r['id'], 'rechnung', 'Rechnung senden', 'btn small sec'); }
    }
    if ($r['payment_status'] === 'bezahlt') { $acts .= $btn((int)$r['id'], 'ticket', 'Ticket erneut senden', 'btn small sec'); }
    $links = '';
    if ($r['status'] === 'zugelassen') { $links .= '<a href="' . $h(x25_pay_url($r)) . '">Zahlung</a> '; }
    if (!empty($r['invoice_no'])) { $links .= '<a href="' . $h(x25_invoice_url($r)) . '">' . $h($r['invoice_no']) . '</a> '; }
    if (!empty($r['ticket_no'])) { $links .= '<a href="' . $h(x25_ticket_url($r)) . '">' . $h($r['ticket_no']) . '</a>'; }
    $rowsHtml .= '<tr><td>' . (int)$r['id'] . '<br><span class="meta">' . $h(x25_date($r['created_at'], 'd.m.y H:i')) . '</span></td>'
        . '<td><strong>' . $h($r['name']) . '</strong><br>' . $h($r['company']) . '<br><span class="meta">' . $h($r['role']) . '</span><br><span class="meta">' . $h($slugOf($r)) . '</span>'
        . '<details><summary class="meta" style="cursor:pointer">Frage / Details</summary><div style="max-width:420px;white-space:pre-wrap;font-family:Georgia,serif;color:var(--ink)">' . $h($r['question']) . '</div><p class="meta" style="margin:6px 0 0">' . $h($r['admission_note'] ?? '') . ($r['decided_by'] ?? '' ? ' · entschieden: ' . $h($r['decided_by']) . ' ' . $h(x25_date($r['decided_at'] ?? null, 'd.m.y H:i')) : '') . (!empty($r['linkedin']) ? ' · <a href="' . $h($r['linkedin']) . '" rel="noopener">LinkedIn</a>' : '') . '</p></details></td>'
        . '<td>' . $h(X25_CATEGORIES[$r['category']] ?? $r['category']) . '<br><span class="meta">' . $h(X25_LEVELS[$r['level']] ?? $r['level']) . '</span></td>'
        . '<td><a href="mailto:' . $h($r['email']) . '">' . $h($r['email']) . '</a></td>'
        . '<td><span class="badge ' . $h($r['status']) . '">' . $h(X25_STATUS[$r['status']] ?? $r['status']) . '</span></td>'
        . '<td>' . $h($r['payment_method'] ?: '–') . '<br><span class="badge ' . $h($r['payment_status']) . '">' . $h($r['payment_status']) . '</span>' . (!empty($r['paid_at']) ? '<br><span class="meta">' . $h(x25_date($r['paid_at'], 'd.m.y')) . '</span>' : '') . '</td>'
        . '<td>' . $links . '</td><td>' . $acts . '</td></tr>';
}
$edLinks = '<a class="btn small' . ($edFilter === '' ? '' : ' sec') . '" href="admin.php">Alle Editionen</a> ';
foreach ($slugs as $sl) { $edLinks .= '<a class="btn small' . ($edFilter === $sl ? '' : ' sec') . '" href="admin.php?edition=' . rawurlencode($sl) . '">' . $h($sl) . '</a> '; }
$body = '<p class="kicker">Admin · <a href="../verwaltung/index.php">Editionen verwalten</a></p><h1>Anmeldungen' . ($edFilter !== '' ? ' · ' . $h($edFilter) : '') . '</h1>'
    . '<p>' . $edLinks . '</p>'
    . ($flash !== '' ? '<div class="card ' . (str_starts_with($flash, 'Fehler') ? 'warn' : 'ok') . '"><strong>' . $h($flash) . '</strong></div>' : '')
    . '<div class="card"><strong style="color:var(--ink);font-size:20px">' . $taken . ' / ' . $max . ' Plätze belegt</strong> <span class="meta">(Regel: ' . ($C['seats_rule'] === 'bezahlt' ? 'nur bezahlte' : 'zugelassene inkl. bezahlte') . ' zählen)</span><br>'
    . '<span class="meta">gesamt ' . count($all) . ' · in Prüfung ' . $count['pruefung'] . ' · zugelassen ' . $count['zugelassen'] . ' · davon bezahlt ' . $count['bezahlt'] . ' · Warteliste ' . $count['warteliste'] . ' · abgesagt ' . $count['abgesagt'] . ' · Ablage: ' . $h($store->backend) . ' · PayPal: ' . $h($C['paypal_env']) . '</span><br>'
    . '<a class="btn small sec" href="admin.php?export=csv">CSV-Export</a> <a class="btn small sec" href="admin.php">Aktualisieren</a></div>'
    . x25_admin_mail_card($csrf, $C, $h)
    . '<table class="list"><thead><tr><th>#</th><th>Person</th><th>Typ / Ebene</th><th>E-Mail</th><th>Status</th><th>Zahlung</th><th>Links</th><th>Aktionen</th></tr></thead><tbody>' . ($rowsHtml ?: '<tr><td colspan="8" class="meta">Noch keine Anmeldungen.</td></tr>') . '</tbody></table>'
    . '<h2>Daten der Edition löschen</h2><div class="card warn"><p class="meta">Löscht alle Anmeldungen, die bis zum gewählten Datum eingegangen sind (Datensparsamkeit; nach der Edition bzw. nach Ablauf der Aufbewahrungspflichten für Rechnungen [TBD: Rechnungsdaten 10 Jahre aufbewahren, vorher CSV-Export sichern]). Rechnungs- und Ticketzähler laufen weiter.</p>'
    . '<form method="post" action="admin.php" data-confirm="Anmeldungen unwiderruflich löschen?"><input type="hidden" name="csrf" value="' . $h($csrf) . '"><input type="hidden" name="do" value="loeschen">'
    . 'Eingegangen bis <input type="date" name="vor" value="' . date('Y-m-d') . '"> · Bestätigung: <input type="text" name="bestaetigung" placeholder="LÖSCHEN" size="10"> <button class="btn small danger" type="submit">Löschen</button></form></div>';
x25_out(x25_page('Admin', $body, '<script src="seite.js" defer></script>', true));

// ================================================================== Hilfsfunktionen
/** Mailversand-Karte: Konfigurations-Check, Test-Mail, Mail-Protokoll (Fehlersuche „keine E-Mails"). */
function x25_admin_mail_card(string $csrf, array $C, callable $h): string
{
    $checks = [];
    $smtpUser = (string)x25_cfg('SMTP_USER', '');
    $smtpPass = (string)x25_cfg('SMTP_PASS', '');
    if ($C['transport'] !== 'smtp') {
        $checks[] = 'MAIL_TRANSPORT steht auf „' . $C['transport'] . '“ – es werden KEINE E-Mails verschickt (Testmodus: Ablage als Datei). In config.php auf \'smtp\' stellen.';
    }
    if ($smtpUser === '') { $checks[] = 'SMTP_USER ist leer – in config.php die vollständige Postfach-Adresse eintragen (z. B. info@25-experts.de).'; }
    if ($smtpPass === '' || str_starts_with($smtpPass, 'HIER-DAS-POSTFACH')) { $checks[] = 'SMTP_PASS ist nicht gesetzt (leer oder Platzhalter aus config.example.php) – jeder Versand schlägt fehl.'; }
    if ($smtpUser !== '' && (string)x25_cfg('MAIL_FROM', $smtpUser) !== $smtpUser) { $checks[] = 'MAIL_FROM weicht vom SMTP-Postfach (SMTP_USER) ab – Hostinger lehnt fremde Absenderadressen ab.'; }
    if ($C['mail_to'] === '') { $checks[] = 'MAIL_TO ist leer – die Gastgeber erhalten keine Benachrichtigungen über neue Anmeldungen.'; }
    $checkHtml = $checks
        ? '<div class="card warn"><strong>Konfigurations-Check:</strong><ul style="margin:6px 0 0 18px">' . implode('', array_map(static fn($c) => '<li>' . $h($c) . '</li>', $checks)) . '</ul></div>'
        : '<p class="meta">Konfigurations-Check: keine Auffälligkeiten (Transport smtp, Postfach und Absender gesetzt).</p>';
    $log = x25_mail_protokoll_lesen(25);
    $logHtml = $log
        ? '<div style="font-family:ui-monospace,monospace;font-size:12px;line-height:1.7;overflow-x:auto;white-space:nowrap">' . implode('<br>', array_map(static fn($l) => $h($l), $log)) . '</div>'
        : '<p class="meta">Noch keine Einträge. Das Protokoll füllt sich mit jedem Versandversuch (auch Test-Mails); vor diesem Update gab es noch kein Protokoll.</p>';
    return '<h2>Mailversand</h2><div class="card">' . $checkHtml
        . '<form method="post" action="admin.php" style="margin:10px 0"><input type="hidden" name="csrf" value="' . $h($csrf) . '"><input type="hidden" name="do" value="testmail">'
        . 'Test-Mail an <input type="email" name="an" placeholder="' . $h(trim(explode(',', $C['mail_to'])[0])) . '" size="28"> <button class="btn small" type="submit">Test-Mail senden</button>'
        . ' <span class="meta">Leer = Gastgeber-Postfach. Zum Test der Zustellung auch mal eine externe Adresse (GMX/Web.de/Gmail) eintragen und den Spam-Ordner prüfen.</span></form>'
        . '<details><summary style="cursor:pointer"><strong>Mail-Protokoll</strong> <span class="meta">(letzte ' . count($log) . ' Versandversuche, neueste zuerst)</span></summary>' . $logHtml . '</details></div>';
}

/** Basic-Auth-Daten; auch hinter FastCGI/LiteSpeed (HTTP_AUTHORIZATION per .htaccess durchgereicht). */
function x25_basic_credentials(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'])) { return [(string)$_SERVER['PHP_AUTH_USER'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')]; }
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($hdr, 'basic ') === 0) {
        $dec = base64_decode(trim(substr($hdr, 6)), true);
        if ($dec !== false && str_contains($dec, ':')) { return explode(':', $dec, 2); }
    }
    return ['', ''];
}
/** CSRF-Token ohne Sitzung: HMAC über Benutzer und Stunde; gültig für die aktuelle und die vorige Stunde. */
function x25_csrf_token(string $user, int $shift = 0): string
{
    return x25_sign('csrf|' . $user . '|' . (intdiv(time(), 3600) - $shift));
}
function x25_csrf_ok(string $user, string $tok): bool
{
    return $tok !== '' && (hash_equals(x25_csrf_token($user), $tok) || hash_equals(x25_csrf_token($user, 1), $tok));
}
