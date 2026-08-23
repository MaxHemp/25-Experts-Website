<?php
/**
 * 25 EXPERTS – Verwaltung: eine Edition bearbeiten.
 *
 * Zwei Ansichten:
 *   bearbeiten.php?slug=…                 übersichtliches Formular (Grunddaten, Termin & Ort,
 *                                         Preis & Plätze, Karten, Landingpage, Anmeldeseite)
 *   bearbeiten.php?slug=…&ansicht=texte   Expertenansicht: ALLE Texte der Seiten als Felder
 *
 * Gespeichert wird je Edition als JSON (edition/lib.php); Änderungen sind sofort live
 * (Landingpage/Übersicht werden bis zu 10 Minuten vom Browser zwischengespeichert).
 */
declare(strict_types=1);

require __DIR__ . '/auth.php';

$e = static fn(?string $s): string => x25ed_e($s);
$csrf = xv_csrf();
$slug = (string)($_GET['slug'] ?? $_POST['slug'] ?? '');
$ed = x25ed_get($slug);
if ($ed === null) {
    xv_page('Nicht gefunden', '<div class="v-card v-card--warn"><h1>Edition nicht gefunden.</h1><p><a class="v-btn" href="index.php">Zur Übersicht</a></p></div>', 404);
}
$ansicht = (string)($_GET['ansicht'] ?? $_POST['ansicht'] ?? '');

// ------------------------------------------------------------------ Feld-Register
// Skalarfelder der Edition: [feld, label, typ, hinweis]
$GRUND = [
    'Grunddaten' => [
        ['name', 'Name der Edition', 'text', 'Klartext für Mails, Rechnung und Ticket, z. B. „25 UNDERWRITING EXPERTS“.'],
        ['thema', 'Thema', 'text', 'Nur das Thema, z. B. „Underwriting“. Daraus entsteht die farbige Überschrift auf der Website.'],
        ['kurz', 'Kurzbeschreibung', 'textarea', 'Erscheint auf der Karte der Startseite und der Editionen-Übersicht (2–3 Sätze).'],
    ],
    'Termin & Ort' => [
        ['datum_text', 'Termin ausgeschrieben', 'text', 'z. B. „3. und 4. Dezember 2026“ (Mails, Landingpage).'],
        ['datum_kurz', 'Termin kurz', 'text', 'z. B. „03./04.12.2026“ (Karten, Kopfzeilen, Share-Bild).'],
        ['datum_start', 'Beginn (technisch)', 'text', 'Format JJJJ-MM-TTTHH:MM:SS+01:00, z. B. „2026-12-03T09:45:00+01:00“ – für Google/Suchmaschinen und die Sortierung.'],
        ['datum_ende', 'Ende (technisch)', 'text', 'gleiches Format wie Beginn.'],
        ['anmeldung_ab', 'Anmeldung möglich ab', 'text', 'Datum JJJJ-MM-TT (nur für Suchmaschinen; das Öffnen/Schließen der Anmeldung schaltest Du in der Übersicht).'],
        ['ort', 'Stadt', 'text', 'z. B. „Köln“.'],
        ['venue', 'Veranstaltungsort (komplett)', 'textarea', 'Name und Anschrift, erscheint in Zusage- und Ticket-Mail.'],
        ['zeiten', 'Zeiten', 'textarea', 'z. B. „Tag 1: 09:45 bis 17:15 Uhr … · Tag 2: …“ (Ticket-Mail).'],
        ['hotel', 'Hotel-Hinweis', 'textarea', 'Erscheint in der Ticket-Mail.'],
        ['kontakt_zeile', 'Kontaktzeile', 'text', 'z. B. „info@25-experts.de · 0221 …“ (Ticket-Mail).'],
        ['leistungsdatum', 'Leistungsdatum (Rechnung)', 'text', 'z. B. „03.–04.12.2026“ – Pflichtangabe auf der Rechnung.'],
    ],
    'Preis & Plätze' => [
        ['preis_net', 'Teilnahmebeitrag netto (€)', 'zahl', 'Nettobetrag in Euro, z. B. 450. Die Umsatzsteuer wird automatisch ergänzt.'],
        ['max_plaetze', 'Anzahl Plätze', 'zahl', 'Standard 25. Ist alles belegt, landen weitere Anmeldungen auf der Warteliste.'],
        ['ticket_prefix', 'Ticketnummern-Anfang', 'text', 'z. B. „25X-UW-“ ergibt Tickets 25X-UW-001, 25X-UW-002 …'],
    ],
];
// venue_ld für Suchmaschinen
$VENUE_LD = [
    ['name', 'Name des Orts', 'z. B. „SESSEL HUB Rheinauhafen, Kranhaus Nord“'],
    ['strasse', 'Straße und Hausnummer', ''],
    ['plz', 'Postleitzahl', ''],
    ['stadt', 'Stadt', ''],
];
// Karte (Startseite/Übersicht)
$KARTE = [
    ['kicker', 'Zeile über dem Namen', 'text', 'z. B. „Dezember 2026 · Anmeldung geöffnet · in Köln“.'],
    ['fakten', 'Fakten (eine je Zeile)', 'zeilen', 'Kurze Fakten wie „03./04.12.2026“, „25 Plätze“, „Rheinauhafen Köln“.'],
    ['meta', 'Kleingedrucktes', 'text', 'optionale Zusatzzeile unter dem Text.'],
    ['punkte', 'Detailpunkte auf der Editionen-Übersicht (einer je Zeile)', 'karte-liste', 'z. B. „<strong>Impulse:</strong> …“ – erscheinen nur auf der Übersichtsseite.'],
    ['aside', 'Randspalte (nur bei Status „Angekündigt“, ein Absatz je Zeile)', 'karte-liste', ''],
];
// Kuratierte Einzeltexte: [bereich, key, label, typ, hinweis]
$TEXT_LANDING = [
    ['landing', 'meta.titel', 'Seitentitel (Browser/Google)', 'text', ''],
    ['landing', 'meta.beschreibung', 'Beschreibung (Google/Teilen)', 'textarea', '1–2 Sätze; erscheint in Suchergebnissen und beim Teilen des Links.'],
    ['landing', 'hero.kicker', 'Kopfbereich: Zeile über der Überschrift', 'text', ''],
    ['landing', 'hero.meta', 'Kopfbereich: Termin-Zeile', 'text', 'z. B. „03./04. Dezember 2026 · Köln, Rheinauhafen · 25 Plätze“.'],
    ['landing', 'kern', 'Kern-Erklärung', 'textarea', 'Der zentrale Absatz im Kopfbereich: Was ist diese Edition?'],
    ['landing', 'hero.note', 'Kopfbereich: Randnotiz', 'textarea', ''],
    ['landing', 'fuerwen.titel', '„Für wen“: Überschrift', 'textarea', ''],
    ['landing', 'fuerwen.schluss', '„Für wen“: Schlusssatz', 'textarea', ''],
    ['landing', 'preis.meta', 'Preis: Kleingedrucktes', 'textarea', ''],
    ['landing', 'preis.storno', 'Storno-Hinweis', 'textarea', ''],
    ['landing', 'abend.ort', 'Abendlocation (Name und Adresse)', 'textarea', ''],
    ['landing', 'eventld.beschreibung', 'Beschreibung für Suchmaschinen (Event)', 'textarea', ''],
];
$TEXT_ANMELDUNG = [
    ['anmeldung', 'meta.titel', 'Seitentitel (Browser/Google)', 'text', ''],
    ['anmeldung', 'kopf.titel', 'Überschrift', 'text', ''],
    ['anmeldung', 'kopf.lead', 'Einleitung', 'textarea', ''],
    ['anmeldung', 'paket.titel', 'Paket-Kasten: Titel', 'text', ''],
    ['anmeldung', 'paket.fakten', 'Paket-Kasten: Fakten (eine je Zeile)', 'zeilen', ''],
    ['anmeldung', 'paket.enthalten', 'Paket-Kasten: Leistungen (eine je Zeile)', 'zeilen', ''],
    ['anmeldung', 'bestaetigung.hinweis', 'Hinweis unter dem Formular', 'textarea', ''],
];
// Wiederholgruppen: [id, bereich, prefix, label, felder([key,label,typ]; key '' = Einzelwert), hinweis]
$REPEATS = [
    ['fuerwen', 'landing', 'fuerwen', '„Für wen“: die Punkte', [['', 'Punkt', 'textarea']], 'Wer gehört an den Tisch? Ein Satz je Punkt.'],
    ['beispiele', 'landing', 'frage.beispiel', 'Beispiele für offene Fragen', [['', 'Beispiel', 'textarea']], 'Erscheinen kursiv im Kasten „Deine offene Frage“.'],
    ['tag1', 'landing', 'ablauf.tag1', 'Ablauf Tag 1', [['zeit', 'Zeit', 'zeit'], ['titel', 'Programmpunkt', 'text'], ['text', 'Beschreibung (optional)', 'textarea'], ['marker', 'Marker (optional)', 'marker']], 'Marker: „signatur“ hebt den Punkt hervor, „foto:leinwand“ zeigt ein kleines Foto (Schlüssel aus der Fotoliste).'],
    ['tag2', 'landing', 'ablauf.tag2', 'Ablauf Tag 2', [['zeit', 'Zeit', 'zeit'], ['titel', 'Programmpunkt', 'text'], ['text', 'Beschreibung (optional)', 'textarea'], ['marker', 'Marker (optional)', 'marker']], ''],
    ['impulse', 'landing', 'impuls', 'Impulse', [['kicker', 'Kicker', 'text'], ['titel', 'Titel', 'text'], ['text', 'Text', 'textarea']], 'Die nummerierten Impulse auf der Landingpage.'],
    ['leitfragen', 'landing', 'leitfrage', 'Leitfragen', [['titel', 'Titel', 'text'], ['text', 'Text', 'textarea']], ''],
    ['faq', 'landing', 'faq', 'Häufige Fragen (FAQ)', [['frage', 'Frage', 'text'], ['antwort', 'Antwort', 'textarea']], 'Erscheinen auf der Landingpage und in den Google-Suchergebnissen.'],
];
// Zeilenlisten außerhalb der Karten
$ZEILEN_EXTRA = [
    ['landing', 'preis.enthalten', 'Im Preis enthalten (eine Leistung je Zeile)'],
    ['landing', 'preis.nicht', 'Nicht enthalten (eine je Zeile)'],
];

// Sonderfall Ablauf: Einträge sind „Zeit | Titel | Text | Marker“ in einem Schlüssel
$ABLAUF_IDS = ['tag1', 'tag2'];

// ------------------------------------------------------------------ Speichern
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!xv_csrf_ok((string)($_POST['csrf'] ?? ''))) {
        xv_page('Abgelehnt', '<div class="v-card v-card--warn"><h1>Sitzung abgelaufen.</h1><p>Bitte zurück, Seite neu laden und erneut speichern.</p></div>', 403);
    }
    try {
        // Skalarfelder
        foreach ($GRUND as $felder) {
            foreach ($felder as [$feld, , $typ]) {
                if (!array_key_exists($feld, $_POST)) { continue; }
                $v = trim((string)$_POST[$feld]);
                if ($typ === 'zahl') {
                    $v = str_replace(',', '.', $v);
                    $ed[$feld] = $feld === 'max_plaetze' ? max(1, (int)$v) : round((float)$v, 2);
                } else {
                    $ed[$feld] = $v;
                }
            }
        }
        if ($ed['name'] === '') { throw new RuntimeException('Der Name der Edition darf nicht leer sein.'); }
        $ed['name_html'] = '';   // Anzeige-Überschrift wird aus dem Thema abgeleitet
        if (isset($_POST['venue_ld']) && is_array($_POST['venue_ld'])) {
            $ld = [];
            foreach ($VENUE_LD as [$k]) { $ld[$k] = trim((string)($_POST['venue_ld'][$k] ?? '')); }
            $ed['venue_ld'] = $ld;
        }
        if (isset($_POST['karte']) && is_array($_POST['karte'])) {
            $karte = (array)($ed['karte'] ?? []);
            foreach ($KARTE as [$k, , $typ]) {
                if (!array_key_exists($k, $_POST['karte'])) { continue; }
                $wert = trim(str_replace("\r\n", "\n", (string)$_POST['karte'][$k]));
                $karte[$k] = $typ === 'karte-liste'
                    ? array_values(array_filter(array_map('trim', explode("\n", $wert)), static fn($l) => $l !== ''))
                    : $wert;
            }
            $ed['karte'] = $karte;
        }
        // Einzeltexte (kuratiert und Expertenansicht): texte[bereich][key]
        if (isset($_POST['texte']) && is_array($_POST['texte'])) {
            foreach ($_POST['texte'] as $bereich => $paare) {
                if (!in_array($bereich, ['landing', 'anmeldung', 'danke'], true) || !is_array($paare)) { continue; }
                foreach ($paare as $key => $wert) {
                    $key = (string)$key;
                    if (!preg_match('/^[a-z0-9.\-]{1,80}$/i', $key)) { continue; }
                    $ed['texte'][$bereich][$key] = trim(str_replace("\r\n", "\n", (string)$wert));
                }
            }
        }
        // Wiederholgruppen: rep[id][index][feld]
        if (isset($_POST['rep']) && is_array($_POST['rep'])) {
            foreach ($REPEATS as [$id, $bereich, $prefix, , $felder]) {
                if (!isset($_POST['rep'][$id]) || !is_array($_POST['rep'][$id])) { continue; }
                // alte nummerierte Schlüssel der Gruppe entfernen
                foreach (array_keys($ed['texte'][$bereich] ?? []) as $k) {
                    if (preg_match('/^' . preg_quote($prefix, '/') . '\.\d+(\.|$)/', (string)$k)) { unset($ed['texte'][$bereich][$k]); }
                }
                $n = 0;
                foreach (array_values($_POST['rep'][$id]) as $row) {
                    if (!is_array($row)) { continue; }
                    $row = array_map(static fn($v) => trim(str_replace("\r\n", "\n", (string)$v)), $row);
                    if (in_array($id, $ABLAUF_IDS, true)) {
                        if (($row['zeit'] ?? '') === '' && ($row['titel'] ?? '') === '') { continue; }
                        $n++;
                        $teile = [$row['zeit'] ?? '', $row['titel'] ?? ''];
                        if (($row['text'] ?? '') !== '' || ($row['marker'] ?? '') !== '') { $teile[] = $row['text'] ?? ''; }
                        if (($row['marker'] ?? '') !== '') { $teile[] = $row['marker']; }
                        $ed['texte'][$bereich]["$prefix.$n"] = implode(' | ', $teile);
                    } elseif (count($felder) === 1 && $felder[0][0] === '') {
                        if (($row['wert'] ?? '') === '') { continue; }
                        $n++;
                        $ed['texte'][$bereich]["$prefix.$n"] = $row['wert'];
                    } else {
                        $leer = true;
                        foreach ($felder as [$fk]) { if (($row[$fk] ?? '') !== '') { $leer = false; } }
                        if ($leer) { continue; }
                        $n++;
                        foreach ($felder as [$fk]) { $ed['texte'][$bereich]["$prefix.$n.$fk"] = $row[$fk] ?? ''; }
                    }
                }
            }
        }
        x25ed_save($ed);
        $ziel = 'bearbeiten.php?' . http_build_query(array_filter(['slug' => $slug, 'ansicht' => $ansicht ?: null, 'm' => 'Gespeichert. Die Änderungen sind sofort online (Browser-Cache: bis zu 10 Minuten).']));
        header('Location: ' . $ziel, true, 303);
        exit;
    } catch (Throwable $ex) {
        $flash = 'Fehler: ' . $ex->getMessage();
    }
} else {
    $flash = (string)($_GET['m'] ?? '');
}

// ------------------------------------------------------------------ Formular-Bausteine
function xv_input(string $name, string $label, string $typ, ?string $wert, string $hinweis = ''): string
{
    $e = static fn(?string $s): string => x25ed_e($s);
    $hint = $hinweis !== '' ? '<small>' . $hinweis . '</small>' : '';
    $feld = match ($typ) {
        'textarea', 'zeilen' => '<textarea name="' . $e($name) . '" rows="' . (max(2, min(8, substr_count((string)$wert, "\n") + 2))) . '">' . $e($wert) . '</textarea>',
        'zahl' => '<input type="text" inputmode="decimal" name="' . $e($name) . '" value="' . $e($wert) . '" class="v-input--kurz">',
        'zeit' => '<input type="text" name="' . $e($name) . '" value="' . $e($wert) . '" class="v-input--zeit" placeholder="09:45">',
        default => '<input type="text" name="' . $e($name) . '" value="' . $e($wert) . '">',
    };
    return '<label class="v-feld"><span>' . $e($label) . '</span>' . $feld . $hint . '</label>';
}

$body = '<p class="v-kicker"><a href="index.php">Verwaltung</a> → Edition bearbeiten</p>'
    . '<div class="v-kopfzeile"><div><h1>' . $e((string)$ed['name']) . ' ' . xv_badge($ed) . '</h1>'
    . '<p class="v-meta">Adresse: /editionen/' . $e($slug) . '/ · <a href="' . $e(xv_ansehen_url($ed)) . '" target="_blank" rel="noopener">Seite ansehen ↗</a>'
    . ' · Ansicht: ' . ($ansicht === 'texte'
        ? '<a href="bearbeiten.php?slug=' . $e($slug) . '">Übersichtlich</a> | <strong>Alle Texte</strong>'
        : '<strong>Übersichtlich</strong> | <a href="bearbeiten.php?slug=' . $e($slug) . '&amp;ansicht=texte">Alle Texte (Expertenansicht)</a>') . '</p></div></div>'
    . (($flash ?? '') !== '' ? '<div class="v-card ' . (str_starts_with((string)$flash, 'Fehler') ? 'v-card--warn' : 'v-card--ok') . '"><strong>' . $e((string)$flash) . '</strong></div>' : '');

$body .= '<form method="post" action="bearbeiten.php" class="v-form" id="bearbeiten">'
    . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="slug" value="' . $e($slug) . '">'
    . ($ansicht !== '' ? '<input type="hidden" name="ansicht" value="' . $e($ansicht) . '">' : '');

if ($ansicht === 'texte') {
    // ---------------- Expertenansicht: alle Texte
    $body .= '<div class="v-card"><p class="v-meta">Alle Texte der drei Seiten dieser Edition. In den Texten funktionieren Platzhalter wie {mail}, {anmeldung} oder {landing} sowie einfache HTML-Auszeichnung (&lt;strong&gt;, &lt;a&gt;). „[TBD: …]“ erscheint als sichtbarer oranger Platzhalter.</p></div>';
    $texteAlle = x25ed_texte();
    foreach ([['landing', 'Landingpage'], ['anmeldung', 'Anmeldeseite'], ['danke', 'Danke-Seite']] as [$bereich, $titel]) {
        $keys = array_unique(array_merge(array_keys($texteAlle[$bereich . '_default'] ?? []), array_keys($ed['texte'][$bereich] ?? [])));
        sort($keys);
        $felder = '';
        foreach ($keys as $key) {
            $wert = $ed['texte'][$bereich][$key] ?? $texteAlle[$bereich . '_default'][$key] ?? '';
            $felder .= xv_input('texte[' . $bereich . '][' . $key . ']', $key, 'textarea', (string)$wert);
        }
        $body .= '<details class="v-card v-gruppe"><summary><h2>' . $e($titel) . '</h2><span class="v-meta">' . count($keys) . ' Texte</span></summary><div class="v-form__stapel">' . $felder . '</div></details>';
    }
} else {
    // ---------------- Übersichtliche Ansicht
    foreach ($GRUND as $gruppe => $felder) {
        $inner = '';
        foreach ($felder as [$feld, $label, $typ, $hinweis]) {
            $wert = (string)($ed[$feld] ?? '');
            $inner .= xv_input($feld, $label, $typ, $wert, $hinweis);
        }
        if ($gruppe === 'Termin & Ort') {
            $ld = (array)($ed['venue_ld'] ?? []);
            $ldFelder = '';
            foreach ($VENUE_LD as [$k, $label, $hinweis]) {
                $ldFelder .= xv_input('venue_ld[' . $k . ']', $label, 'text', (string)($ld[$k] ?? ''), $hinweis);
            }
            $inner .= '<details class="v-untergruppe"><summary>Adresse für Suchmaschinen (optional)</summary><div class="v-form__grid">' . $ldFelder . '</div></details>';
        }
        $body .= '<details class="v-card v-gruppe" open><summary><h2>' . $e($gruppe) . '</h2></summary><div class="v-form__grid">' . $inner . '</div></details>';
    }

    // Karte
    $karte = (array)($ed['karte'] ?? []);
    $inner = '';
    foreach ($KARTE as [$k, $label, $typ, $hinweis]) {
        $wert = $karte[$k] ?? '';
        if (is_array($wert)) { $wert = implode("\n", $wert); }
        $inner .= xv_input('karte[' . $k . ']', $label, $typ === 'karte-liste' ? 'zeilen' : $typ, (string)$wert, $hinweis);
    }
    $inner .= xv_input('kurz', 'Kurzbeschreibung', 'textarea', (string)($ed['kurz'] ?? ''), 'Der Text auf der Karte (identisch mit der Kurzbeschreibung in den Grunddaten).');
    $body .= '<details class="v-card v-gruppe" open><summary><h2>Karte auf Startseite &amp; Übersicht</h2></summary><div class="v-form__grid">' . $inner . '</div></details>';

    // Kuratierte Landingpage-Texte
    $inner = '';
    foreach ($TEXT_LANDING as [$bereich, $key, $label, $typ, $hinweis]) {
        $inner .= xv_input('texte[' . $bereich . '][' . $key . ']', $label, $typ, x25ed_raw($ed, $bereich, $key) ?? '', $hinweis);
    }
    foreach ($ZEILEN_EXTRA as [$bereich, $key, $label]) {
        $inner .= xv_input('texte[' . $bereich . '][' . $key . ']', $label, 'zeilen', x25ed_raw($ed, $bereich, $key) ?? '');
    }
    $body .= '<details class="v-card v-gruppe"><summary><h2>Landingpage: zentrale Texte</h2><span class="v-meta">Überschriften, Kern-Erklärung, Preis</span></summary><div class="v-form__stapel">' . $inner . '</div></details>';

    // Wiederholgruppen
    foreach ($REPEATS as [$id, $bereich, $prefix, $label, $felder, $hinweis]) {
        $rows = [];
        if (in_array($id, $ABLAUF_IDS, true)) {
            foreach (x25ed_items($ed, $bereich, $prefix) as $entry) {
                $teile = array_map('trim', explode('|', $entry));
                $rows[] = ['zeit' => $teile[0] ?? '', 'titel' => $teile[1] ?? '', 'text' => $teile[2] ?? '', 'marker' => $teile[3] ?? ''];
            }
        } elseif (count($felder) === 1 && $felder[0][0] === '') {
            foreach (x25ed_rohliste($ed, $bereich, $prefix) as $w) { $rows[] = ['wert' => $w]; }
        } else {
            $keys = array_map(static fn($f) => $f[0], $felder);
            foreach (x25ed_rohtuples($ed, $bereich, $prefix, $keys) as $tupel) { $rows[] = $tupel; }
        }
        $rowsHtml = '';
        foreach ($rows as $i => $row) {
            $rowsHtml .= xv_repeat_row($id, $i, $felder, $row);
        }
        $vorlageRow = xv_repeat_row($id, '__NEU__', $felder, []);
        $titelExtra = $hinweis !== '' ? '<span class="v-meta">' . $hinweis . '</span>' : '';
        $body .= '<details class="v-card v-gruppe"><summary><h2>' . $e($label) . '</h2>' . $titelExtra . '</summary>'
            . '<div class="v-repeat" data-repeat="' . $e($id) . '">' . $rowsHtml . '</div>'
            . '<template data-repeat-vorlage="' . $e($id) . '">' . $vorlageRow . '</template>'
            . '<p><button type="button" class="v-btn v-btn--klein v-btn--leise" data-repeat-add="' . $e($id) . '">+ Eintrag hinzufügen</button></p></details>';
    }

    // Anmeldeseite
    $inner = '';
    foreach ($TEXT_ANMELDUNG as [$bereich, $key, $label, $typ, $hinweis]) {
        $inner .= xv_input('texte[' . $bereich . '][' . $key . ']', $label, $typ, x25ed_raw($ed, $bereich, $key) ?? '', $hinweis);
    }
    $body .= '<details class="v-card v-gruppe"><summary><h2>Anmeldeseite</h2><span class="v-meta">Überschrift, Paket-Kasten</span></summary><div class="v-form__stapel">' . $inner . '</div></details>';

    $body .= '<div class="v-card"><p class="v-meta">Noch mehr ändern? In der <a href="bearbeiten.php?slug=' . $e($slug) . '&amp;ansicht=texte">Expertenansicht „Alle Texte“</a> ist jeder einzelne Text der drei Seiten bearbeitbar.</p></div>';
}

$body .= '<div class="v-speichern"><button class="v-btn v-btn--gross" type="submit">Speichern</button> '
    . '<a class="v-btn v-btn--leise" href="' . $e(xv_ansehen_url($ed)) . '" target="_blank" rel="noopener">Seite ansehen ↗</a>'
    . '<span class="v-meta">Änderungen sind nach dem Speichern sofort online.</span></div></form>';

xv_page('Bearbeiten: ' . (string)$ed['name'], $body);

// ------------------------------------------------------------------ Hilfen
/** Eine Zeile einer Wiederholgruppe. */
function xv_repeat_row(string $id, $index, array $felder, array $row): string
{
    global $ABLAUF_IDS;
    $e = static fn(?string $s): string => x25ed_e($s);
    $inner = '';
    if (in_array($id, $ABLAUF_IDS, true)) {
        $inner .= xv_input("rep[$id][$index][zeit]", 'Zeit', 'zeit', (string)($row['zeit'] ?? ''));
        $inner .= xv_input("rep[$id][$index][titel]", 'Programmpunkt', 'text', (string)($row['titel'] ?? ''));
        $inner .= xv_input("rep[$id][$index][text]", 'Beschreibung (optional)', 'textarea', (string)($row['text'] ?? ''));
        $inner .= xv_input("rep[$id][$index][marker]", 'Marker (optional)', 'text', (string)($row['marker'] ?? ''));
    } elseif (count($felder) === 1 && $felder[0][0] === '') {
        $inner .= xv_input("rep[$id][$index][wert]", $felder[0][1], $felder[0][2], (string)($row['wert'] ?? ''));
    } else {
        foreach ($felder as [$fk, $label, $typ]) {
            $inner .= xv_input("rep[$id][$index][$fk]", $label, $typ, (string)($row[$fk] ?? ''));
        }
    }
    return '<fieldset class="v-repeat__row"><div class="v-repeat__felder">' . $inner . '</div>'
        . '<div class="v-repeat__werkzeuge"><button type="button" class="v-btn v-btn--klein v-btn--leise" data-repeat-hoch title="nach oben">↑</button>'
        . '<button type="button" class="v-btn v-btn--klein v-btn--leise" data-repeat-runter title="nach unten">↓</button>'
        . '<button type="button" class="v-btn v-btn--klein v-btn--rot" data-repeat-weg title="Eintrag entfernen">✕</button></div></fieldset>';
}

/** Rohwerte einer Einzelwert-Familie (für das Formular, ohne Platzhalter-Ersetzung). */
function x25ed_rohliste(array $ed, string $bereich, string $prefix): array
{
    $eigen = isset($ed['texte'][$bereich]["$prefix.1"]);
    $out = [];
    for ($i = 1; $i <= 200; $i++) {
        $raw = $eigen ? ($ed['texte'][$bereich]["$prefix.$i"] ?? null) : x25ed_raw(null, $bereich, "$prefix.$i");
        if ($raw === null) { break; }
        $out[] = (string)($ed['texte'][$bereich]["$prefix.$i"] ?? $raw);
    }
    return $out;
}

/** Rohwerte einer Tupel-Familie. */
function x25ed_rohtuples(array $ed, string $bereich, string $prefix, array $keys): array
{
    $erste = $keys[0];
    $eigen = isset($ed['texte'][$bereich]["$prefix.1.$erste"]);
    $out = [];
    for ($i = 1; $i <= 200; $i++) {
        $test = $eigen ? ($ed['texte'][$bereich]["$prefix.$i.$erste"] ?? null) : x25ed_raw(null, $bereich, "$prefix.$i.$erste");
        if ($test === null) { break; }
        $row = [];
        foreach ($keys as $k) {
            $row[$k] = (string)($ed['texte'][$bereich]["$prefix.$i.$k"] ?? x25ed_raw(null, $bereich, "$prefix.$i.$k") ?? '');
        }
        $out[] = $row;
    }
    return $out;
}
