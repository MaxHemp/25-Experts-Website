<?php
/**
 * 25 EXPERTS – Ticketseite (druckbar) mit QR-Code: ticket.php?t=TOKEN
 *   &qr=svg  → QR-Code als SVG   |   &qr=png → QR-Code als PNG (GD)   (beide zeigen auf diese Ticket-URL)
 * Nur nach Zahlungseingang (payment_status = bezahlt). Am Empfang: QR scannen → diese Seite zeigt Ticketnummer,
 * Name, Unternehmen und Status; damit ist ein Ticket ohne Datenbankzugriff prüfbar.
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

$C = x25_conf();
$t = (string)($_GET['t'] ?? '');
$rec = preg_match('/^[a-f0-9]{32}$/', $t) ? x25_store()->findByToken($t) : null;
if ($rec === null || $rec['payment_status'] !== 'bezahlt' || empty($rec['ticket_no'])) {
    x25_out(x25_page('Ticket nicht gefunden', '<h1>Kein gültiges Ticket.</h1><p>Der Link ist ungültig oder die Zahlung ist noch nicht eingegangen. Bei Fragen: <a href="mailto:' . x25_e($C['mail_to']) . '">' . x25_e($C['mail_to']) . '</a>.</p>'), 404);
}
$url = x25_ticket_url($rec);
$qr = (string)($_GET['qr'] ?? '');
if ($qr === 'svg') {
    header('Content-Type: image/svg+xml; charset=utf-8'); header('Cache-Control: private, max-age=86400');
    echo x25_qr_svg($url); exit;
}
if ($qr === 'png') {
    $png = x25_qr_png($url);
    if ($png === '') { header('Location: ticket.php?t=' . rawurlencode($t) . '&qr=svg', true, 302); exit; }
    header('Content-Type: image/png'); header('Cache-Control: private, max-age=86400');
    echo $png; exit;
}
$info = [['Termin', $C['edition_datum']], ['Zeiten', $C['edition_zeiten']], ['Ort', $C['edition_venue']], ['Hotel', $C['edition_hotel']], ['Kontakt', $C['edition_kontakt']]];
$body = '<div class="noprint" style="margin-bottom:16px;"><button class="btn" type="button" data-print>Drucken / als PDF speichern</button></div>'
    . '<p class="kicker">Ticket</p><h1>' . x25_e($C['edition_name']) . '</h1>'
    . '<div class="card" style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">'
    . '<img class="qr" src="ticket.php?t=' . x25_e($t) . '&amp;qr=svg" width="220" height="220" alt="QR-Code: ' . x25_e($url) . '">'
    . '<div><p class="mono" style="margin:0 0 4px">Ticketnummer</p><p style="font-family:\'Courier New\',monospace;font-size:28px;font-weight:700;color:var(--ink);margin:0 0 16px">' . x25_e($rec['ticket_no']) . '</p>'
    . '<table class="rows"><tr><td>Name</td><td>' . x25_e($rec['name']) . '</td></tr><tr><td>Unternehmen</td><td>' . x25_e($rec['company']) . '</td></tr><tr><td>Rolle</td><td>' . x25_e($rec['role']) . '</td></tr>'
    . '<tr><td>Status</td><td><span class="badge bezahlt">bezahlt</span> ' . x25_e(x25_date($rec['paid_at'] ?? null, 'd.m.Y')) . '</td></tr></table></div></div>'
    . '<h2>Alle weiteren Informationen</h2><div class="card"><table class="rows">';
foreach ($info as [$k, $v]) { $body .= '<tr><td>' . x25_e($k) . '</td><td>' . x25_e($v) . '</td></tr>'; }
$body .= '</table></div>'
    . '<p>Vorbereitung ist nicht nötig. Bringen Sie Ihre eine offene Frage mit, so wie Sie sie im Formular gestellt haben. Zwei Wochen vor dem Termin senden wir Ihnen alle Details zu Ablauf, Anreise und Abend.</p>'
    . '<p class="meta">Bitte zeigen Sie dieses Ticket am Empfang vor (Ausdruck oder Smartphone). Der QR-Code führt auf diese Seite.</p>';
x25_out(x25_page('Ticket ' . $rec['ticket_no'], $body, '<script src="seite.js" defer></script>'));
