<?php
/**
 * 25 EXPERTS – Rechnungsseite (druckbar, „Als PDF speichern"): rechnung.php?t=TOKEN
 * Geschäftsrechnung im Corporate Design, angelehnt an DIN 5008: Briefkopf mit Logo, Absenderzeile,
 * Anschriftfeld, Informationsblock, Positionstabelle mit Einzel-/Gesamtpreis, Summenblock, Zahlungsblock,
 * dreispaltiger Fuß (Gesellschaft · Bankverbindung · Kontakt/Steuern).
 * Pflichtangaben nach § 14 UStG: Aussteller mit Anschrift, Steuernummer/USt-IdNr., fortlaufende Rechnungs-
 * nummer, Rechnungsdatum, Leistungsdatum, Menge und Art der Leistung, Netto, Steuersatz, USt-Betrag, Brutto,
 * Empfänger mit Anschrift. Aussteller- und Bankdaten aus config.php (INVOICE_ISSUER_*, BANK_*).
 * Druck: @page ohne Rand (unterdrückt die Browser-Kopf-/Fußzeilen), Innenabstand auf dem Blatt; das Layout
 * ist bewusst kompakt, damit die Rechnung auf eine A4-Seite passt.
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

$C = x25_conf();
$t = (string)($_GET['t'] ?? '');
$rec = preg_match('/^[a-f0-9]{32}$/', $t) ? x25_store()->findByToken($t) : null;
$A = x25_amounts($rec); $ED = $rec !== null ? x25_edition_for($rec) : null;
if ($rec === null || empty($rec['invoice_no'])) {
    x25_out(x25_page('Rechnung nicht gefunden', '<h1>Rechnung nicht gefunden.</h1><p>Der Link ist ungültig oder es wurde noch keine Rechnung erstellt.</p>'), 404);
}
$e = static fn(?string $s): string => x25_e((string)$s);
$issuer = [
    'name' => (string)x25_cfg('INVOICE_ISSUER_NAME', '25 EXPERTS UG (haftungsbeschränkt) i. G.'),
    'addr' => (string)x25_cfg('INVOICE_ISSUER_ADDRESS', 'Moitzfeld 17 · 51429 Bergisch Gladbach'),
    'tax'  => (string)x25_cfg('INVOICE_TAX_ID', ''),
    'vat'  => (string)x25_cfg('INVOICE_VAT_ID', ''),
    'mail' => (string)x25_cfg('MAIL_FROM', 'info@25-experts.de'),
];
$paid = $rec['payment_status'] === 'bezahlt';
[$invCompany, $invAddr] = x25_invoice_recipient($rec);
$bankIban = (string)x25_cfg('BANK_IBAN', X25_BANK_IBAN);
$bank = [
    'holder' => (string)x25_cfg('BANK_HOLDER', X25_BANK_HOLDER),
    'iban' => $bankIban,
    'bic' => (string)x25_cfg('BANK_BIC', X25_BANK_BIC),
    'name' => (string)x25_cfg('BANK_NAME', X25_BANK_NAME),
];
$zweck = $rec['invoice_no'] . ' · ' . $rec['name'];
$logo = '/assets/img/25experts-logo-horizontal.svg';   // gleicher Host, funktioniert auch im lokalen Test
$webHost = preg_replace('~^https?://|/$~', '', $C['site']);

// ------------------------------------------------------------------ Bausteine
$infoRows = [['Rechnungsnummer', $rec['invoice_no']], ['Rechnungsdatum', x25_date($rec['invoice_date'], 'd.m.Y')], ['Leistungsdatum', $ED['leistungsdatum']]];
if (($rec['order_no'] ?? '') !== '') { $infoRows[] = ['Bestellnummer', $rec['order_no']]; }
$infoRows[] = ['Teilnehmer', $rec['name']];
$infoRows[] = ['Zahlungsziel', x25_date($rec['invoice_due'], 'd.m.Y')];
$infoHtml = '';
foreach ($infoRows as [$k, $v]) { $infoHtml .= '<tr><th scope="row">' . $e($k) . '</th><td>' . $e($v) . '</td></tr>'; }

$empfaenger = '<strong>' . $e($invCompany) . '</strong><br>'
    . ($invAddr !== '' ? nl2br($e($invAddr)) : $e($rec['name']));
$anschriftHinweis = $invAddr === ''
    ? '<p class="meta noprint" style="margin:6px 0 0">Eine abweichende Rechnungsanschrift kannst Du uns per E-Mail nachreichen; wir stellen die Rechnung dann neu aus.</p>' : '';

$zahlText = $paid
    ? 'Der Rechnungsbetrag ist bereits beglichen' . (($rec['paid_at'] ?? '') !== '' ? ' (Zahlungseingang ' . $e(x25_date($rec['paid_at'], 'd.m.Y')) . ')' : '') . '. Diese Rechnung dient als Beleg; eine Zahlung ist nicht mehr erforderlich.'
    : 'Zahlbar ohne Abzug bis zum ' . $e(x25_date($rec['invoice_due'], 'd.m.Y')) . ' (' . (int)$C['payment_days'] . ' Tage ab Rechnungsdatum) unter Angabe des Verwendungszwecks.';
$bankHtml = $bank['iban'] !== ''
    ? '<table class="bank"><tr><th scope="row">Empfänger</th><td>' . $e($bank['holder']) . '</td><th scope="row">Bank</th><td>' . $e($bank['name']) . '</td></tr>'
      . '<tr><th scope="row">IBAN</th><td class="mono-wert">' . $e($bank['iban']) . '</td><th scope="row">BIC</th><td class="mono-wert">' . $e($bank['bic']) . '</td></tr>'
      . '<tr><th scope="row">Verwendungszweck</th><td colspan="3" class="mono-wert"><strong>' . $e($zweck) . '</strong></td></tr></table>'
    : '<p>Die Bankverbindung senden wir gesondert per E-Mail; bitte erst danach überweisen. Verwendungszweck: <strong class="mono-wert">' . $e($zweck) . '</strong></p>';

// Vorberechnete, bereits maskierte Werte für das Template
$extra_btn = ($rec['status'] === 'zugelassen' && !$paid) ? '<a class="btn sec" href="' . $e(x25_pay_url($rec)) . '">Zur Zahlungsseite</a>' : '';
$paidBadge = $paid ? '<span class="badge-bezahlt">Bezahlt</span>' : '';
$rate = (int)round($A['rate'] * 100);
$vLogo = $e($logo); $vEdLabel = $e($ED['label']); $vIssuerName = $e($issuer['name']); $vIssuerAddr = $e($issuer['addr']);
$vNr = $e($rec['invoice_no']); $vEdName = $e($C['edition_name']); $vLeistung = $e($ED['leistungsdatum']); $vOrt = $e($ED['ort']);
$vName = $e($rec['name']); $vNet = $e(x25_money($A['net'])); $vVat = $e(x25_money($A['vat'])); $vGross = $e(x25_money($A['gross']));
$vMail = $e($issuer['mail']); $vWeb = $e($webHost); $vFooter = $e($C['footer']); $vZahlText = $zahlText;
$fussBank = $bank['iban'] !== ''
    ? $e($bank['holder']) . '<br>IBAN ' . $e($bank['iban']) . '<br>BIC ' . $e($bank['bic']) . ' · ' . $e($bank['name'])
    : 'Bankverbindung folgt gesondert per E-Mail';
$fussSteuer = ($issuer['tax'] !== '' ? '<br>' . $e($issuer['tax']) : '') . ($issuer['vat'] !== '' ? '<br>' . $e($issuer['vat']) : '');

$body = <<<HTML
<div class="noprint aktionen">
  <button class="btn" type="button" data-print>Drucken / als PDF speichern</button>
  {$extra_btn}
  <span class="meta">Tipp für ein sauberes PDF: Im Druckdialog „Kopf- und Fußzeilen" abwählen, falls angeboten.</span>
</div>
<div class="blatt">
  <header class="kopf">
    <img class="kopf__logo" src="{$vLogo}" alt="25 EXPERTS" width="220" height="49">
    <div class="kopf__rechts">
      <p class="kicker">Rechnung</p>
      <p class="kopf__edition">{$vEdLabel}</p>
    </div>
  </header>

  <div class="adressen">
    <div class="anschrift">
      <p class="absenderzeile">{$vIssuerName} · {$vIssuerAddr}</p>
      <p class="anschrift__feld">{$empfaenger}</p>
      {$anschriftHinweis}
    </div>
    <table class="infoblock">{$infoHtml}</table>
  </div>

  <h1>Rechnung {$vNr} {$paidBadge}</h1>

  <table class="positionen">
    <thead><tr><th class="c-pos">Pos.</th><th>Leistung</th><th class="c-menge">Menge</th><th class="c-preis">Einzelpreis</th><th class="c-preis">Gesamt netto</th></tr></thead>
    <tbody><tr>
      <td class="c-pos">1</td>
      <td><strong>Teilnahme {$vEdName}</strong><br>
        <span class="pos-detail">{$vLeistung} (Veranstaltungstage) · {$vOrt} · Teilnehmer: {$vName}<br>
        Teilnahmegebühr inkl. Verpflegung an beiden Tagen und Abendveranstaltung</span></td>
      <td class="c-menge">1</td>
      <td class="c-preis">{$vNet}</td>
      <td class="c-preis">{$vNet}</td>
    </tr></tbody>
  </table>

  <div class="summen-zeile">
    <table class="summen">
      <tr><th scope="row">Zwischensumme (netto)</th><td>{$vNet}</td></tr>
      <tr><th scope="row">zzgl. {$rate} % USt.</th><td>{$vVat}</td></tr>
      <tr class="summen__brutto"><th scope="row">Rechnungsbetrag (brutto)</th><td>{$vGross}</td></tr>
    </table>
  </div>

  <section class="zahlung">
    <h2>Zahlung</h2>
    <p>{$zahlText}</p>
    {$bankHtml}
    <p class="meta">Nach Zahlungseingang ist die Teilnahme verbindlich; das Ticket geht dem Teilnehmer per E-Mail zu.
    Es gelten die Teilnahmebedingungen: {$vWeb}/teilnahmebedingungen. Fragen zur Rechnung (Bestellnummer,
    abweichende Rechnungsanschrift): {$vMail}.</p>
  </section>

  <footer class="fuss">
    <div><p class="fuss__titel">Gesellschaft</p>{$vFooter}</div>
    <div><p class="fuss__titel">Bankverbindung</p>{$fussBank}</div>
    <div><p class="fuss__titel">Kontakt &amp; Steuern</p>{$vMail}<br>{$vWeb}{$fussSteuer}</div>
  </footer>
</div>
<script src="seite.js" defer></script>
HTML;

x25_out(x25_rechnung_seite('Rechnung ' . $rec['invoice_no'], $body));

/** Eigenständige Seitenhülle der Rechnung: Markenfarben und -schriften, A4-Druckbild. */
function x25_rechnung_seite(string $title, string $body): string
{
    $ink = X25_INK; $petrol = X25_PETROL; $paper = X25_PAPER; $bodyCol = X25_BODY; $meta = X25_META; $line = X25_LINE; $orange = X25_ORANGE;
    return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>' . x25_e($title) . ' · 25 EXPERTS</title>'
        . <<<CSS
<style>
@font-face{font-family:"Plus Jakarta Sans";src:url("/assets/fonts/PlusJakartaSans-Medium.woff2") format("woff2");font-weight:500;font-display:swap}
@font-face{font-family:"Plus Jakarta Sans";src:url("/assets/fonts/PlusJakartaSans-SemiBold.woff2") format("woff2");font-weight:600;font-display:swap}
@font-face{font-family:"Plus Jakarta Sans";src:url("/assets/fonts/PlusJakartaSans-ExtraBold.woff2") format("woff2");font-weight:800;font-display:swap}
@font-face{font-family:"IBM Plex Mono";src:url("/assets/fonts/IBMPlexMono-Regular.woff2") format("woff2");font-weight:400;font-display:swap}
@font-face{font-family:"IBM Plex Mono";src:url("/assets/fonts/IBMPlexMono-Medium.woff2") format("woff2");font-weight:500;font-display:swap}
:root{--ink:{$ink};--petrol:{$petrol};--paper:{$paper};--body:{$bodyCol};--meta:{$meta};--line:{$line};--orange:{$orange}}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--body);font:500 15px/1.5 "Plus Jakarta Sans",Arial,Helvetica,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
strong{font-weight:600;color:var(--ink)}
.mono-wert,.absenderzeile,.infoblock th,.positionen thead th,.fuss__titel,.kicker{font-family:"IBM Plex Mono","Courier New",monospace}
.kicker{margin:0;font-size:12px;font-weight:500;letter-spacing:2px;text-transform:uppercase;color:var(--petrol)}
.meta{color:var(--meta);font-size:12.5px}

.aktionen{max-width:210mm;margin:16px auto 12px;padding:0 8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.btn{display:inline-block;background:var(--petrol);color:#fff;border:0;border-radius:4px;padding:11px 18px;font:600 14px "Plus Jakarta Sans",Arial,sans-serif;text-decoration:none;cursor:pointer}
.btn.sec{background:#fff;color:var(--ink);border:1px solid var(--ink)}

.blatt{max-width:210mm;min-height:280mm;margin:0 auto 32px;background:#fff;padding:16mm 18mm 12mm;box-shadow:0 2px 18px rgba(11,31,38,.12);display:flex;flex-direction:column}

.kopf{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;padding-bottom:14px;border-bottom:3px solid var(--ink);margin-bottom:26px}
.kopf__logo{display:block;width:220px;height:auto}
.kopf__rechts{text-align:right}
.kopf__edition{margin:4px 0 0;font-size:12.5px;color:var(--meta)}

.adressen{display:flex;justify-content:space-between;gap:28px;margin-bottom:26px}
.absenderzeile{margin:0 0 8px;font-size:10.5px;letter-spacing:.3px;color:var(--meta);border-bottom:1px solid var(--line);padding-bottom:5px;max-width:85mm}
.anschrift__feld{margin:0;font-size:14.5px;line-height:1.55;color:var(--ink)}
.infoblock{border-collapse:collapse;align-self:flex-start}
.infoblock th{padding:2.5px 14px 2.5px 0;text-align:left;font-size:11px;font-weight:500;letter-spacing:.5px;text-transform:uppercase;color:var(--meta);white-space:nowrap;vertical-align:top}
.infoblock td{padding:2.5px 0;font-size:13.5px;color:var(--ink);vertical-align:top}

h1{margin:0 0 14px;font-size:23px;font-weight:800;color:var(--ink);letter-spacing:-.2px}
h2{margin:0 0 6px;font-size:15px;font-weight:800;color:var(--ink)}
.badge-bezahlt{display:inline-block;vertical-align:3px;margin-left:10px;padding:3px 10px;border-radius:3px;font:600 11.5px "Plus Jakarta Sans",Arial,sans-serif;letter-spacing:.5px;text-transform:uppercase;background:#DDF1E1;color:#1E5A2A}

.positionen{width:100%;border-collapse:collapse;font-size:13.5px}
.positionen thead th{padding:7px 8px;font-size:10.5px;font-weight:500;letter-spacing:.8px;text-transform:uppercase;color:#fff;background:var(--ink);text-align:left}
.positionen tbody td{padding:11px 8px;border-bottom:1px solid var(--line);vertical-align:top;color:var(--ink)}
.pos-detail{color:var(--body);font-size:12.5px;line-height:1.55}
.c-pos{width:34px}.c-menge{width:52px;text-align:right}.c-preis{width:98px;text-align:right}
.positionen thead .c-menge,.positionen thead .c-preis{text-align:right}
.positionen td.c-menge,.positionen td.c-preis{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}

.summen-zeile{display:flex;justify-content:flex-end;margin:2px 0 26px}
.summen{border-collapse:collapse;min-width:88mm}
.summen th{padding:6px 20px 6px 8px;text-align:right;font-weight:500;color:var(--body);font-size:13.5px}
.summen td{padding:6px 8px;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;color:var(--ink);font-size:13.5px;min-width:98px}
.summen tr{border-bottom:1px solid var(--line)}
.summen__brutto th{font-weight:800;color:var(--ink);font-size:15px;padding-top:9px}
.summen__brutto td{font-weight:800;font-size:17px;padding-top:9px}
.summen__brutto{border-top:2px solid var(--ink);border-bottom:3px double var(--ink)}

.zahlung{border:1px solid var(--line);border-left:4px solid var(--petrol);background:#FDFCF9;padding:14px 18px;break-inside:avoid;page-break-inside:avoid}
.zahlung p{margin:0 0 10px;font-size:13.5px}
.zahlung p.meta{margin:10px 0 0;font-size:11.5px;line-height:1.55}
.bank{border-collapse:collapse;width:100%}
.bank th{padding:3px 12px 3px 0;text-align:left;font-family:"IBM Plex Mono","Courier New",monospace;font-size:10.5px;font-weight:500;letter-spacing:.5px;text-transform:uppercase;color:var(--meta);white-space:nowrap}
.bank td{padding:3px 24px 3px 0;font-size:13.5px;color:var(--ink)}
.bank .mono-wert{font-size:13px;letter-spacing:.3px}

.fuss{margin-top:auto;padding-top:12px;border-top:2px solid var(--ink);display:flex;gap:24px;font-size:10px;line-height:1.65;color:var(--meta);break-inside:avoid;page-break-inside:avoid}
.fuss>div{flex:1 1 0}
.fuss__titel{margin:0 0 3px;font-size:9.5px;font-weight:500;letter-spacing:1px;text-transform:uppercase;color:var(--ink)}

@media screen and (max-width:760px){
  .blatt{padding:20px 16px;min-height:0}
  .adressen,.kopf,.fuss{flex-direction:column;align-items:flex-start;gap:14px}
  .kopf__rechts{text-align:left}
  .summen-zeile{justify-content:flex-start}
  .summen{width:100%}
}
@page{size:A4;margin:0}
@media print{
  .noprint{display:none!important}
  body{background:#fff}
  .blatt{max-width:none;width:210mm;min-height:296mm;margin:0;box-shadow:none;padding:14mm 18mm 10mm}
}
</style>
CSS
        . '</head><body>' . $body . '</body></html>';
}
