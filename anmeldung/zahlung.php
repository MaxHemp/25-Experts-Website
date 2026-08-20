<?php
/**
 * 25 EXPERTS – Zahlungsseite (Schritt 3): zahlung.php?t=TOKEN
 * Zeigt Betrag (PRICE_NET + VAT_RATE) und zwei Wege:
 *   (a) PayPal Smart Buttons (JS-SDK; Order anlegen/capturen serverseitig über paypal.php)
 *   (b) „Per Rechnung zahlen" (POST) → Rechnungsnummer, Rechnungsmail, Gastgeber-Mail mit „Zahlung eingegangen"-Link
 * Nach Zahlungseingang wird auf das Ticket verwiesen; seit v6 landet jede gültige Anmeldung direkt hier.
 * Die Content-Security-Policy für das PayPal-SDK setzt anmeldung/.htaccess (Files zahlung.php); das Seitenskript liegt in zahlung.js.
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

$C = x25_conf();
$t = (string)($_REQUEST['t'] ?? '');
$rec = preg_match('/^[a-f0-9]{32}$/', $t) ? x25_store()->findByToken($t) : null;
$A = x25_amounts($rec); $ED = $rec !== null ? x25_edition_for($rec) : null;
if ($rec === null) {
    x25_out(x25_page('Link ungültig', '<h1>Dieser Zahlungslink ist ungültig.</h1><p>Bitte nutze den Link aus Deiner Bestätigungs-Mail oder schreib an <a href="mailto:' . x25_e($C['mail_to']) . '">' . x25_e($C['mail_to']) . '</a>.</p>'), 404);
}
if ($rec['status'] !== 'zugelassen') {
    x25_out(x25_page('Keine Zahlung offen', '<h1>Für diese Anmeldung ist keine Zahlung offen.</h1><p>Stand: ' . x25_e(X25_STATUS[$rec['status']] ?? $rec['status']) . '. Bei Fragen schreib an <a href="mailto:' . x25_e($C['mail_to']) . '">' . x25_e($C['mail_to']) . '</a>.</p>'), 409);
}
$intro = '<p class="kicker">Zahlung</p><h1>Teilnahmebeitrag ' . x25_e($ED['name']) . '</h1>'
    . '<div class="card"><table class="rows"><tr><td>Teilnehmer</td><td>' . x25_e($rec['name']) . ', ' . x25_e($rec['company']) . '</td></tr>'
    . '<tr><td>Termin</td><td>' . x25_e($ED['datum']) . ' · ' . x25_e($ED['ort']) . '</td></tr>'
    . '<tr><td>Netto</td><td>' . x25_e(x25_money($A['net'])) . '</td></tr><tr><td>USt. ' . (int)round($A['rate'] * 100) . ' %</td><td>' . x25_e(x25_money($A['vat'])) . '</td></tr>'
    . '<tr><td>Brutto</td><td class="amount">' . x25_e(x25_money($A['gross'])) . '</td></tr></table></div>';

if ($rec['payment_status'] === 'bezahlt') {
    x25_out(x25_page('Bezahlt', $intro . '<div class="card ok"><h2 style="margin-top:0">Vielen Dank, Deine Zahlung ist eingegangen.</h2><p>Dein Ticket ' . x25_e($rec['ticket_no'] ?? '') . ' wurde per E-Mail versandt.</p><a class="btn" href="' . x25_e(x25_ticket_url($rec)) . '">Ticket öffnen</a></div>', '', false, $ED['label']));
}

// (b) Rechnung gewählt
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['weg'] ?? '') === 'rechnung') {
    try {
        $rec = x25_choose_invoice($rec);
    } catch (Throwable $e) {
        x25_log('rechnung fehlgeschlagen: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 150));
        x25_out(x25_page('Fehler', $intro . '<div class="card warn"><p>Die Rechnung konnte gerade nicht erstellt oder versandt werden. Bitte versuche es in einigen Minuten erneut oder schreib an <a href="mailto:' . x25_e($C['mail_to']) . '">' . x25_e($C['mail_to']) . '</a>.</p></div>'), 500);
    }
    header('Location: zahlung.php?t=' . rawurlencode($t) . '&r=1', true, 303);
    exit;
}

$paypalOn = in_array($C['paypal_env'], ['sandbox', 'live'], true) && $C['paypal_client'] !== '';
$body = $intro;
if (($rec['payment_method'] ?? '') === 'rechnung' && !empty($rec['invoice_no'])) {
    $body .= '<div class="card ok"><h2 style="margin-top:0">Rechnung ' . x25_e($rec['invoice_no']) . ' ist unterwegs.</h2>'
        . '<p>Wir haben Dir die Rechnung per E-Mail gesandt (Zahlungsziel ' . x25_e(x25_date($rec['invoice_due'], 'd.m.Y')) . '). Nach Zahlungseingang erhältst Du Dein Ticket und alle weiteren Informationen.</p>'
        . '<a class="btn" href="' . x25_e(x25_invoice_url($rec)) . '">Rechnung ansehen / als PDF speichern</a></div>';
    if ($paypalOn) { $body .= '<p class="meta">Du möchtest doch lieber sofort per PayPal zahlen? Dann nutze die Schaltflächen unten; die Rechnung gilt dann als erledigt.</p>'; }
} else {
    $body .= '<p>Bitte wähle Deinen Zahlungsweg. Mit dem Zahlungseingang ist Dein Platz verbindlich; Du erhältst dann Dein Ticket und alle weiteren Informationen.</p>';
}
$body .= '<div class="card"><h2 style="margin-top:0">PayPal</h2>';
if ($paypalOn) {
    $body .= '<p class="meta">Zahlung mit PayPal-Konto, Kreditkarte oder Lastschrift über PayPal. Der Betrag von ' . x25_e(x25_money($A['gross'])) . ' wird sofort verbucht.</p>'
        . '<div id="paypal-buttons" data-token="' . x25_e($t) . '" data-api="paypal.php" data-ticket="' . x25_e(x25_ticket_url($rec)) . '"></div>'
        . '<p id="paypal-status" class="meta" aria-live="polite"></p>';
} else {
    $body .= '<p class="meta">PayPal ist derzeit nicht verfügbar [TBD: PayPal-Zugang in config.php eintragen]. Bitte zahle per Rechnung.</p>';
}
$body .= '</div>';
if (($rec['payment_method'] ?? '') !== 'rechnung') {
    $body .= '<div class="card"><h2 style="margin-top:0">Rechnung</h2><p class="meta">Du erhältst die Rechnung per E-Mail (Zahlungsziel ' . (int)$C['payment_days'] . ' Tage, Überweisung). Das Ticket kommt nach Zahlungseingang.</p>'
        . '<form method="post" action="zahlung.php"><input type="hidden" name="t" value="' . x25_e($t) . '"><input type="hidden" name="weg" value="rechnung"><button class="btn sec" type="submit">Per Rechnung zahlen</button></form></div>';
}
$body .= '<p class="meta">Fragen zur Zahlung: <a href="mailto:' . x25_e($C['mail_to']) . '">' . x25_e($C['mail_to']) . '</a></p>';

$head = '';
if ($paypalOn) {
    $sdk = 'https://www.paypal.com/sdk/js?client-id=' . rawurlencode($C['paypal_client']) . '&currency=' . rawurlencode($A['currency']) . '&intent=capture&locale=de_DE&disable-funding=credit';
    $head = '<script src="' . x25_e($sdk) . '"></script><script src="zahlung.js" defer></script>';
}
x25_out(x25_page('Zahlung', $body, $head, false, $ED['label']));
