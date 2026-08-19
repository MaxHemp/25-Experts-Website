<?php
/**
 * 25 EXPERTS – Rechnungsseite (druckbar, „Als PDF speichern"): rechnung.php?t=TOKEN
 * Pflichtangaben nach § 14 UStG: Aussteller mit Anschrift, Steuernummer/USt-IdNr., Rechnungsnummer, Rechnungsdatum,
 * Leistungsdatum, Leistungsbeschreibung, Netto, Steuersatz, USt-Betrag, Brutto; Empfänger (Name, Unternehmen).
 * Aussteller- und Bankdaten aus config.php (INVOICE_ISSUER_*, BANK_*).
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

$C = x25_conf(); $A = x25_amounts();
$t = (string)($_GET['t'] ?? '');
$rec = preg_match('/^[a-f0-9]{32}$/', $t) ? x25_store()->findByToken($t) : null;
if ($rec === null || empty($rec['invoice_no'])) {
    x25_out(x25_page('Rechnung nicht gefunden', '<h1>Rechnung nicht gefunden.</h1><p>Der Link ist ungültig oder es wurde noch keine Rechnung erstellt.</p>'), 404);
}
$issuer = [
    'name' => (string)x25_cfg('INVOICE_ISSUER_NAME', '25 Experts Cologne UG (haftungsbeschränkt) i. G.'),
    'addr' => (string)x25_cfg('INVOICE_ISSUER_ADDRESS', 'Moitzfeld 17 · 51429 Bergisch Gladbach'),
    'tax'  => (string)x25_cfg('INVOICE_TAX_ID', 'Steuernummer: [TBD]'),
    'vat'  => (string)x25_cfg('INVOICE_VAT_ID', 'USt-IdNr.: [TBD]'),
    'mail' => (string)x25_cfg('MAIL_FROM', 'info@25-experts.de'),
];
$paid = $rec['payment_status'] === 'bezahlt';
$rows = x25_invoice_rows($rec);
$body = '<div class="noprint" style="margin-bottom:16px;"><button class="btn" type="button" data-print>Drucken / als PDF speichern</button> '
    . ($rec['status'] === 'zugelassen' && !$paid ? '<a class="btn sec" href="' . x25_e(x25_pay_url($rec)) . '">Zur Zahlungsseite</a>' : '') . '</div>'
    . '<p class="kicker">Rechnung</p>'
    . '<div style="display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap;margin-bottom:24px;">'
    . '<div><p class="meta" style="margin:0 0 4px">Aussteller</p><strong style="color:var(--ink)">' . x25_e($issuer['name']) . '</strong><br>' . x25_e($issuer['addr']) . '<br>' . x25_e($issuer['mail']) . '<br>' . x25_e($issuer['tax']) . '<br>' . x25_e($issuer['vat']) . '</div>'
    . '<div><p class="meta" style="margin:0 0 4px">Rechnungsempfänger</p><strong style="color:var(--ink)">' . x25_e(x25_invoice_recipient($rec)[0]) . '</strong><br>' . nl2br(x25_e(x25_invoice_recipient($rec)[1] !== '' ? x25_invoice_recipient($rec)[1] : $rec['name'])) . (x25_invoice_recipient($rec)[1] === '' ? '<br><span class="meta">Anschrift laut Deiner Angabe [TBD: Rechnungsanschrift bei Bedarf per E-Mail nachreichen]</span>' : '') . '</div>'
    . '</div>'
    . '<h1>Rechnung ' . x25_e($rec['invoice_no']) . ($paid ? ' <span class="badge bezahlt">bezahlt</span>' : '') . '</h1>'
    . '<div class="card"><table class="rows">'
    . '<tr><td>Rechnungsdatum</td><td>' . x25_e(x25_date($rec['invoice_date'], 'd.m.Y')) . '</td></tr>'
    . (($rec['order_no'] ?? '') !== '' ? '<tr><td>Bestellnummer</td><td>' . x25_e($rec['order_no']) . '</td></tr>' : '')
    . '<tr><td>Leistungsdatum</td><td>' . x25_e($C['leistungsdatum']) . ' (Veranstaltungstage)</td></tr>'
    . '<tr><td>Zahlungsziel</td><td>' . x25_e(x25_date($rec['invoice_due'], 'd.m.Y')) . ' (' . (int)$C['payment_days'] . ' Tage ab Rechnungsdatum)</td></tr>'
    . '</table></div>'
    . '<table class="list"><thead><tr><th>Pos.</th><th>Leistung</th><th>Menge</th><th style="text-align:right">Netto</th></tr></thead><tbody>'
    . '<tr><td>1</td><td>Teilnahme ' . x25_e($C['edition_name']) . ', ' . x25_e($C['leistungsdatum']) . ', ' . x25_e($C['edition_ort']) . '<br><span class="meta">Teilnehmer: ' . x25_e($rec['name']) . ' · Teilnahmegebühr inkl. Verpflegung an beiden Tagen und Abendveranstaltung</span></td><td>1</td><td style="text-align:right">' . x25_e(x25_money($A['net'])) . '</td></tr>'
    . '</tbody><tfoot>'
    . '<tr><td colspan="3" style="text-align:right">Nettobetrag</td><td style="text-align:right">' . x25_e(x25_money($A['net'])) . '</td></tr>'
    . '<tr><td colspan="3" style="text-align:right">zzgl. ' . (int)round($A['rate'] * 100) . ' % USt.</td><td style="text-align:right">' . x25_e(x25_money($A['vat'])) . '</td></tr>'
    . '<tr><td colspan="3" style="text-align:right"><strong>Rechnungsbetrag (brutto)</strong></td><td style="text-align:right" class="amount">' . x25_e(x25_money($A['gross'])) . '</td></tr>'
    . '</tfoot></table>'
    . '<h2>Zahlung</h2><div class="card"><table class="rows">';
foreach ($rows as [$k, $v]) {
    if (in_array($k, ['Empfänger', 'IBAN', 'BIC', 'Bank', 'Verwendungszweck'], true)) { $body .= '<tr><td>' . x25_e($k) . '</td><td>' . x25_e((string)$v) . '</td></tr>'; }
}
$body .= '</table><p class="meta" style="margin-bottom:0">Bitte überweise den Rechnungsbetrag bis zum ' . x25_e(x25_date($rec['invoice_due'], 'd.m.Y')) . ' unter Angabe des Verwendungszwecks. Mit dem Zahlungseingang ist Dein Platz verbindlich; Du erhältst dann Dein Ticket. [TBD: Hinweis auf Teilnahmebedingungen/Storno]</p></div>'
    . '<p class="meta">Vielen Dank für Deine Anmeldung. Bei Fragen zur Rechnung (Bestellnummer, abweichende Rechnungsanschrift) schreib an <a href="mailto:' . x25_e($issuer['mail']) . '">' . x25_e($issuer['mail']) . '</a>.</p>';
x25_out(x25_page('Rechnung ' . $rec['invoice_no'], $body, '<script src="seite.js" defer></script>'));
