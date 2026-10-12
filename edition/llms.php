<?php
/**
 * 25 EXPERTS – /llms.txt (dynamisch) für KI-Suche und Assistenten (ChatGPT, Claude, Perplexity, Gemini …).
 * Kurzer, faktischer Überblick in Markdown nach llms.txt-Konvention; Editionen, Termine und Leitfragen
 * kommen live aus den Editionsdaten der Verwaltung, damit KI-Antworten nicht auf veralteten Daten beruhen.
 * Stammdaten: edition/seo.json.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/identity.php';

$site = rtrim((string)(x25ed_texte()['domain'] ?? 'https://25-experts.de/'), '/');
$seo = json_decode((string)@file_get_contents(__DIR__ . '/seo.json'), true) ?: [];
$plain = static fn(string $s): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')) ?? '');

$editionen = array_values(array_filter(x25ed_all(), static fn(array $ed): bool => ($ed['status'] ?? '') === 'online'));
usort($editionen, static fn(array $a, array $b): int => strcmp((string)($a['datum_start'] ?? ''), (string)($b['datum_start'] ?? '')));

$z = [];
$z[] = '# 25 EXPERTS';
$z[] = '';
$z[] = '> Expertentreffen für die Versicherungsbranche: Je Edition 25 Fach- und Führungskräfte aus Versicherern, Maklerpools und Versicherungsvertrieben, anderthalb Tage im Kölner Rheinauhafen. Persönlich zusammengestellt, vorbereitet, moderiert – mit KI als „26. Experte“.';
$z[] = '';
$z[] = 'Diese Datei fasst die wichtigsten Fakten für KI-Assistenten und Suchsysteme zusammen. Sie wird automatisch aus den aktuellen Editionsdaten erzeugt (Stand: ' . date('d.m.Y') . '). Maßgeblich sind die verlinkten Seiten.';
$z[] = '';
$z[] = '## Fakten';
$z[] = '';
$z[] = '- Was: Geschlossenes Expertentreffen (kein Kongress, keine Bühne, kein Publikum). Jede Edition hat ein Thema und genau 25 Teilnehmerplätze; höchstens zwei Personen pro Unternehmen.';
$z[] = '- Für wen: Team-, Abteilungs- und Bereichsleitungen sowie fachlich Verantwortliche bei Versicherern, Maklerpools und Versicherungsvertrieben. Dienstleister und Berater nehmen nur als Expertin/Experte oder Impulsgeber nach gesonderter Absprache teil. Die Female Edition richtet sich an Frauen in der Versicherungsbranche.';
$z[] = '- Ablauf: Anderthalb Tage: Tag 1 ab 09:45 Uhr mit gemeinsamem Abend (Aperitif und Dinner), Tag 2 bis 13:30 Uhr. Vier Impulse à 45 Minuten (Forschung, zwei Praxisfälle, Vorstandsgespräch), zwei moderierte Arbeitsblöcke.';
$z[] = '- Besonderheiten: „Der 26. Experte“ – die Gruppe entscheidet zuerst selbst und vergleicht dann mit einer KI anhand derselben Informationen. „Das Kuvert“ – eine versiegelte, überprüfbare Vorhersage jeder Person. „Das Dissenspapier“ – gemeinsame Positionen und begründete Unterschiede als Ergebnis.';
$z[] = '- Vertraulichkeit: Für die fachlichen Gespräche gilt die Chatham House Rule; während der Arbeitsphasen wird nicht fotografiert oder gefilmt.';
$z[] = '- Ort: SESSEL HUB Rheinauhafen, Kranhaus Nord, Im Zollhafen 12, 50678 Köln.';
$z[] = '- Preis: 450 € netto zzgl. 19 % USt. je Platz (535,50 € brutto), inklusive Vorbereitung und Dossier, Verpflegung, Aperitif und Dinner. Anreise und Übernachtung sind nicht enthalten.';
$z[] = '- Teilnahme: Kostenfreie Anfrage auf der jeweiligen Editionsseite, persönliche Rückmeldung innerhalb von zwei Werktagen; erst nach Zusage wird verbindlich gebucht (PayPal oder Rechnung).';
$z[] = '- Gastgeber und Geschäftsführer: Maximilian Hempel (Herausgeber des VersicherungsTech Magazins). Moderator: Simon Moser.';
$z[] = '- Veranstalter: ' . (string)($seo['organisation']['legalName'] ?? '25 EXPERTS UG (haftungsbeschränkt)') . ' · Kontakt: info@25-experts.de · LinkedIn: https://www.linkedin.com/company/141373969/';
$z[] = '';
$z[] = '## Editionen und Termine';
$z[] = '';
foreach ($editionen as $ed) {
    $vars = x25ed_vars($ed);
    $frage = x25ed_identity((string)$ed['slug'])[2] ?? '';
    $leit = $plain(x25ed_txt($ed, 'landing', 'leitfrage.serif'));
    $status = (!empty($ed['termin_vorlaeufig']) && mb_stripos((string)($ed['datum_text'] ?? ''), 'vorläufig') === false) ? ' (Termin vorläufig)' : '';
    $z[] = '### ' . (string)$ed['name'];
    $z[] = '';
    $z[] = '- Termin: ' . $plain((string)($ed['datum_text'] ?? '')) . $status . ', Köln, Rheinauhafen';
    if ($frage !== '') { $z[] = '- Leitfrage: ' . $plain($frage); }
    if ($leit !== '' && $leit !== $plain($frage)) { $z[] = '- Worum es geht: ' . $leit; }
    if (!empty($vars['zielgruppe'])) { $z[] = '- Zielgruppe: ' . $plain((string)$vars['zielgruppe']); }
    $z[] = '- Anmeldung: ' . (!empty($ed['anmeldung_offen']) ? 'geöffnet' : 'noch nicht geöffnet') . ' · Preis: ' . x25ed_preis($ed) . ' € netto';
    $z[] = '- Seite: ' . $site . x25ed_url($ed);
    $z[] = '';
}
$z[] = '## Seiten';
$z[] = '';
$z[] = '- [Startseite](' . $site . '/): Überblick, nächstes Event, häufige Fragen';
$z[] = '- [Das Format](' . $site . '/format): Ablauf, Vorbereitung, 26. Experte, Kuvert, Dissenspapier';
$z[] = '- [Editionen](' . $site . '/editionen): alle Themen und Termine';
$z[] = '- [Unser Anspruch](' . $site . '/neutralitaetskodex): Auswahl der Teilnehmenden, Umgang mit Partnern, Vertraulichkeit';
$z[] = '- [Über uns](' . $site . '/ueber-uns): Gastgeber und Moderator';
$z[] = '- [Kontakt](' . $site . '/kontakt)';
$z[] = '';
$z[] = '## Optional';
$z[] = '';
$z[] = '- [Teilnahmebedingungen](' . $site . '/teilnahmebedingungen)';
$z[] = '- [Impressum](' . $site . '/impressum)';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
echo implode("\n", $z) . "\n";
