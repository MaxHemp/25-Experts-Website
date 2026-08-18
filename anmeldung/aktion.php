<?php
/**
 * 25 EXPERTS – Gastgeber-Aktion per signiertem Link aus der Benachrichtigungsmail.
 *   aktion.php?a=zulassen|absagen|bezahlt&id=ID&n=NONCE&s=SIGNATUR
 * Signatur = HMAC-SHA256(APP_SECRET, "aktion|id|nonce"); die Nonce steht im Datensatz und wird nach jeder
 * Aktion erneuert, damit jeder Link nur einmal wirkt (Zulassen und Absagen derselben Mail schließen sich aus).
 * GET zeigt eine Bestätigungsseite (Mail-Scanner, die Links vorab öffnen, lösen nichts aus); erst der POST führt aus.
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

$a  = (string)($_REQUEST['a'] ?? '');
$id = (int)($_REQUEST['id'] ?? 0);
$n  = (string)($_REQUEST['n'] ?? '');
$s  = (string)($_REQUEST['s'] ?? '');
$LABELS = ['zulassen' => 'Zulassen', 'absagen' => 'Absagen', 'bezahlt' => 'Zahlung eingegangen'];

if (!isset($LABELS[$a]) || $id <= 0 || !x25_verify_sig($a . '|' . $id . '|' . $n, $s)) {
    x25_out(x25_page('Link ungültig', '<h1>Dieser Link ist ungültig.</h1><p>Die Signatur passt nicht. Bitte nutzen Sie den Link aus der aktuellen Benachrichtigungsmail oder den <a href="admin.php">Admin-Bereich</a>.</p>'), 403);
}
$rec = x25_store()->get($id);
if ($rec === null) {
    x25_out(x25_page('Anmeldung nicht gefunden', '<h1>Anmeldung nicht gefunden.</h1><p>Der Datensatz existiert nicht mehr.</p>'), 404);
}
if (($rec['action_nonce'] ?? '') !== $n || $n === '') {
    x25_out(x25_page('Link bereits verwendet', '<h1>Dieser Link wurde bereits verwendet.</h1><p>Aktueller Stand: <span class="badge ' . x25_e($rec['status']) . '">' . x25_e(X25_STATUS[$rec['status']] ?? $rec['status']) . '</span>, Zahlung <span class="badge ' . x25_e($rec['payment_status']) . '">' . x25_e($rec['payment_status']) . '</span>. Änderungen im <a href="admin.php">Admin-Bereich</a>.</p>'), 410);
}

$rows = x25_rows_person($rec, true);
$summary = '<div class="card"><table class="rows">' . implode('', array_map(static fn($r) => '<tr><td>' . x25_e($r[0]) . '</td><td>' . x25_e((string)$r[1]) . '</td></tr>', $rows)) . '</table>'
    . '<p class="meta">Ihre eine offene Frage:</p><p style="color:var(--ink);font-family:Georgia,serif;font-size:17px;">' . nl2br(x25_e($rec['question'])) . '</p></div>';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Bestätigungsseite
    $all = x25_store()->all();
    $taken = x25_seats_taken($all); $max = x25_conf()['max_seats'];
    $seat = '<p class="meta">Belegte Plätze: ' . $taken . ' von ' . $max . ($taken >= $max && $a === 'zulassen' ? ' · <strong style="color:var(--orange)">Alle Plätze sind belegt; die Zulassung überschreitet das Kontingent.</strong>' : '') . '</p>';
    $extra = '';
    if ($a === 'absagen') {
        $extra = '<p><label><input type="radio" name="reason" value="zielgruppe" checked> Nicht Zielgruppe (kein Versicherer, Maklerpool oder Versicherungsvertrieb)</label><br>'
            . '<label><input type="radio" name="reason" value="ebene"> Ebene passt nicht (Absage mit Hinweis auf die Führungsebene)</label><br>'
            . '<label><input type="radio" name="reason" value="voll"> Alle Plätze vergeben</label></p>';
    }
    $hint = match ($a) {
        'zulassen' => 'Die Person erhält sofort die Zusage mit der Zahlungsaufforderung (PayPal oder Rechnung).',
        'absagen' => 'Die Person erhält eine freundliche Absage (Wortlaut E-Mail 03).',
        default => 'Der Zahlungseingang wird verbucht, die Ticketnummer vergeben und das Ticket mit QR-Code an die Person gesendet.',
    };
    $body = '<p class="kicker">Gastgeber-Aktion</p><h1>' . x25_e($LABELS[$a]) . ': ' . x25_e($rec['name']) . ', ' . x25_e($rec['company']) . '</h1>'
        . '<p>Aktueller Stand: <span class="badge ' . x25_e($rec['status']) . '">' . x25_e(X25_STATUS[$rec['status']] ?? $rec['status']) . '</span> · Zahlung <span class="badge ' . x25_e($rec['payment_status']) . '">' . x25_e($rec['payment_status']) . '</span>' . (!empty($rec['invoice_no']) ? ' · Rechnung ' . x25_e($rec['invoice_no']) : '') . '</p>'
        . $seat . $summary
        . '<form method="post" action="aktion.php"><input type="hidden" name="a" value="' . x25_e($a) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="n" value="' . x25_e($n) . '"><input type="hidden" name="s" value="' . x25_e($s) . '">'
        . '<p>' . x25_e($hint) . '</p>' . $extra
        . '<button class="btn' . ($a === 'absagen' ? ' danger' : '') . '" type="submit">' . x25_e($LABELS[$a]) . ' bestätigen</button> <a class="btn sec" href="admin.php">Zum Admin</a></form>';
    x25_out(x25_page($LABELS[$a], $body));
}

// POST: ausführen
try {
    $msg = '';
    switch ($a) {
        case 'zulassen':
            if ($rec['status'] === 'zugelassen') { $msg = 'Die Anmeldung war bereits zugelassen.'; break; }
            $rec = x25_admit($rec, 'gastgeber-link');
            $msg = 'Zugelassen. Zusage und Zahlungsaufforderung sind an ' . $rec['email'] . ' unterwegs.';
            break;
        case 'absagen':
            $reason = (string)($_POST['reason'] ?? 'zielgruppe');
            if (!in_array($reason, ['zielgruppe', 'ebene', 'voll'], true)) { $reason = 'zielgruppe'; }
            $rec = x25_reject($rec, 'gastgeber-link', $reason);
            $msg = 'Abgesagt. Die Absage ist an ' . $rec['email'] . ' unterwegs.';
            break;
        case 'bezahlt':
            if ($rec['status'] !== 'zugelassen') { x25_out(x25_page('Nicht möglich', '<h1>Zahlung kann nicht verbucht werden.</h1><p>Die Anmeldung ist nicht zugelassen (Status: ' . x25_e($rec['status']) . ').</p>'), 409); }
            if ($rec['payment_status'] === 'bezahlt') { $msg = 'Die Zahlung war bereits verbucht (Ticket ' . $rec['ticket_no'] . ').'; break; }
            $rec = x25_mark_paid($rec, ($rec['payment_method'] ?? '') !== '' ? $rec['payment_method'] : 'manuell', 'gastgeber-link');
            $msg = 'Zahlung verbucht. Ticket ' . $rec['ticket_no'] . ' ist an ' . $rec['email'] . ' unterwegs.';
            break;
    }
} catch (Throwable $e) {
    x25_log('aktion ' . $a . ' fehlgeschlagen: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 150));
    x25_out(x25_page('Fehler', '<h1>Die Aktion konnte nicht ausgeführt werden.</h1><p>Der Mailversand ist fehlgeschlagen oder die Datenablage ist nicht erreichbar. Bitte im <a href="admin.php">Admin</a> erneut versuchen.</p>'), 500);
}
x25_out(x25_page('Erledigt', '<p class="kicker">Gastgeber-Aktion</p><h1>' . x25_e($msg) . '</h1>' . $summary . '<p><a class="btn sec" href="admin.php">Zum Admin</a></p>'));
