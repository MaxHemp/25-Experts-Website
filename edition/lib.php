<?php
/**
 * 25 EXPERTS – Editionen: Datenablage und Textbasis der dynamischen Seiten.
 *
 * Editionen liegen als JSON-Dateien in DATA_DIR/editionen/ (Standard: anmeldung/data/editionen/),
 * werden über /verwaltung/ gepflegt und hier gelesen/geschrieben. Beim ersten Zugriff werden die
 * Seed-Dateien aus edition/seed/ importiert (nur fehlende Slugs; danach ist das Backend die Quelle).
 * Die gemeinsamen Texte (Seitenhülle, Bausteine, Standardtexte) kommen aus edition/texte.json
 * (erzeugt von 04-website/build_edition_export.py aus content/).
 *
 * Diese Datei funktioniert bewusst auch OHNE anmeldung/config.php (öffentliche Seiten bleiben
 * erreichbar); config.php wird eingebunden, wenn vorhanden (DATA_DIR, APP_SECRET, Admin-Zugang).
 *
 * Statusmodell je Edition:
 *   entwurf       nur mit Vorschau-Link/Backend sichtbar
 *   angekuendigt  Teaser-Karte auf Startseite/Übersicht, noch keine Landingpage
 *   online        Landingpage + Anmeldung öffentlich (Anmeldung zusätzlich über anmeldung_offen schaltbar)
 *   archiviert    nicht mehr gelistet; Landingpage leitet auf die Übersicht um
 */
declare(strict_types=1);

if (defined('X25ED_LIB')) { return; }
define('X25ED_LIB', 1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Berlin');

define('X25ED_DIR', __DIR__);
define('X25ED_ROOT', dirname(__DIR__));

// config.php der Anmeldestrecke mitbenutzen (falls x25.php sie nicht schon geladen hat)
if (!defined('SMTP_HOST') && is_file(X25ED_ROOT . '/anmeldung/config.php')) {
    require X25ED_ROOT . '/anmeldung/config.php';
}

const X25ED_STATUS = ['entwurf' => 'Entwurf', 'angekuendigt' => 'Angekündigt', 'online' => 'Online', 'archiviert' => 'Archiviert'];

function x25ed_cfg(string $name, $default = null)
{
    return defined($name) ? constant($name) : $default;
}

function x25ed_e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function x25ed_site(): string
{
    return rtrim((string)x25ed_cfg('SITE_URL', 'https://25-experts.de/'), '/') . '/';
}

/** Verzeichnis der Editions-Dateien (neben der Anmeldungs-Datenablage, per .htaccess geschützt). */
function x25ed_dir(): string
{
    $dir = rtrim((string)x25ed_cfg('DATA_DIR', X25ED_ROOT . '/anmeldung/data'), '/') . '/editionen';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    return $dir;
}

function x25ed_slug_ok(string $slug): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,58}[a-z0-9]$/', $slug);
}

/** Slug aus einem Namen ableiten (Umlaute, Sonderzeichen). */
function x25ed_slugify(string $name): string
{
    $s = mb_strtolower(trim($name));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = preg_replace('/^25\s+/', '', $s) ?? $s;
    $s = preg_replace('/\s+experts?$/', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

// ------------------------------------------------------------------ Lesen/Schreiben
/** Seed-Import: fehlende Editionen aus edition/seed/*.json anlegen (einmalig je Slug). */
function x25ed_seed(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    foreach (glob(X25ED_DIR . '/seed/*.json') ?: [] as $f) {
        $slug = basename($f, '.json');
        if (!x25ed_slug_ok($slug) || is_file(x25ed_dir() . '/' . $slug . '.json')) { continue; }
        $ed = json_decode((string)file_get_contents($f), true);
        if (is_array($ed) && ($ed['slug'] ?? '') === $slug) { x25ed_save($ed); }
    }
    x25ed_tbd_migration();
    x25ed_reframe_migration();
    x25ed_wording_migration();
    x25ed_editionen_2027_migration();
    x25ed_anmeldung_2027_migration();
    x25ed_phrasen_sweep();
}

/** Vom Gastgeber freigegebener Buchungsstart: bestehende Editionen gezielt aktualisieren.
 * Fachinhalte und spätere Änderungen im Backend werden nicht erneut importiert. */
function x25ed_anmeldung_2027_migration(): void
{
    $revision = 'anmeldung-2027-v1';
    $textKeys = [
        'gemeinsam' => ['cta.anmelden'],
        'landing' => ['hero.note', 'anmeldung.kicker', 'anmeldung.titel', 'anmeldung.lead',
            'anmeldung.absatz1', 'anmeldung.absatz3', 'anmeldung.button', 'preis.kicker',
            'preis.meta', 'preis.storno', 'eventld.angebot.name', 'eventld.angebot.beschreibung',
            'faq.3.antwort', 'faq.4.antwort'],
        'anmeldung' => ['kopf.kicker', 'kopf.lead', 'meta.titel', 'meta.beschreibung',
            'bestaetigung.hinweis', 'bestaetigung.anmeldung', 'paket.preis.zusatz'],
    ];
    foreach (['vertrieb', 'female', 'operations', 'data'] as $slug) {
        $marker = x25ed_dir() . '/.migration-' . $revision . '-' . $slug;
        if (is_file($marker)) { continue; }
        $seedFile = X25ED_DIR . '/seed/' . $slug . '.json';
        $file = x25ed_dir() . '/' . $slug . '.json';
        if (!is_file($seedFile) || !is_file($file)) { continue; }
        $seed = json_decode((string)file_get_contents($seedFile), true);
        $ed = json_decode((string)file_get_contents($file), true);
        if (!is_array($seed) || !is_array($ed) || ($seed['registration_revision'] ?? '') !== $revision) { continue; }
        $backup = x25ed_dir() . '/.' . $slug . '-before-' . $revision . '.json';
        if (!is_file($backup) && !copy($file, $backup)) { throw new RuntimeException('Editionssicherung fehlgeschlagen.'); }
        @chmod($backup, 0640);
        foreach (['status', 'anmeldung_offen', 'anmeldung_ab', 'leistungsdatum', 'registration_revision'] as $key) {
            $ed[$key] = $seed[$key];
        }
        foreach (['kicker', 'fakten'] as $key) { $ed['karte'][$key] = $seed['karte'][$key]; }
        foreach ($textKeys as $section => $keys) {
            foreach ($keys as $key) { $ed['texte'][$section][$key] = $seed['texte'][$section][$key]; }
        }
        x25ed_save($ed);
        if (file_put_contents($marker, gmdate('c'), LOCK_EX) === false) {
            throw new RuntimeException('Buchungsstart konnte nicht gespeichert werden.');
        }
    }
}

/** Einmaliger Import der freigegebenen Editionen einschließlich Security, auch über vorhandene Entwürfe.
 * Bestehende Editionsdaten werden gesichert; spätere Backend-Änderungen bleiben bestehen. */
function x25ed_editionen_2027_migration(): void
{
    $revision = 'editionen-2027-v1';
    foreach (['security', 'vertrieb', 'female', 'operations', 'data'] as $slug) {
        $marker = x25ed_dir() . '/.migration-' . $revision . '-' . $slug;
        if (is_file($marker)) { continue; }
        $seedFile = X25ED_DIR . '/seed/' . $slug . '.json';
        if (!is_file($seedFile)) { continue; }
        $seed = json_decode((string)file_get_contents($seedFile), true);
        if (!is_array($seed) || ($seed['content_revision'] ?? '') !== $revision || ($seed['slug'] ?? '') !== $slug) { continue; }
        $file = x25ed_dir() . '/' . $slug . '.json';
        $old = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        if (!is_array($old)) { throw new RuntimeException('Vorhandene Edition konnte nicht gelesen werden.'); }
        if (($old['content_revision'] ?? '') !== $revision) {
            $backup = x25ed_dir() . '/.' . $slug . '-before-' . $revision . '.json';
            if (is_file($file) && !is_file($backup) && !copy($file, $backup)) {
                throw new RuntimeException('Sicherung der bisherigen Edition fehlgeschlagen.');
            }
            if (is_file($backup)) { @chmod($backup, 0640); }
            if (isset($old['created_at'])) { $seed['created_at'] = $old['created_at']; }
            x25ed_save(array_replace($old, $seed));
        }
        if (file_put_contents($marker, gmdate('c'), LOCK_EX) === false) {
            throw new RuntimeException('Editionsimport konnte nicht abgeschlossen werden.');
        }
    }
}

/** Überholte Formulierungen, die in gespeicherten Editionen nicht mehr vorkommen dürfen.
 *  Wird beim Ändern der Standardtexte erweitert. */
function x25ed_alte_phrasen(): array
{
    return ['genau einer offenen Frage', 'genau eine offene Frage', 'niemand beantworten kann',
        'noch niemand beantwortet hat', 'Du sollst Dein Haus', 'Du sollst Dein Unternehmen',
        'sitzt mit am Tisch', 'Claude am Tisch', 'Claude live am Tisch', 'Alles Weitere ist Programm',
        'Signaturelemente', 'auch nicht gegen Geld', 'Der Eintritt sind Deine Fragen', 'Vorstandsstab',
        'live mit auf dem Prüfstand', 'ab 21:00 offener Ausklang', 'liegen zu beidem günstig',
        'unverändert wieder gestellt', 'Beträge werden dann erstattet',
        // Alter Termin der CM-Edition (bis 24.08.2026: Do./Fr. 03./04.12.2026, jetzt Mi./Do. 02./03.12.2026).
        // Achtung: „Donnerstag, 3. Dezember 2026" ist seither der GÜLTIGE Tag 2 und darf hier nicht stehen;
        // nur Fragmente aufnehmen, die im neuen Stand nicht mehr vorkommen können.
        '03./04.', '3./4. Dezember', '3. und 4. Dezember 2026', '03.–04.12',
        'Freitag, 4. Dezember', 'Tag 1 · Donnerstag', 'am 3. Dezember um 10:15', 'am 3. Dezember um 10:55',
        'Abendessen am 3. Dezember mit Aperitif', 'Donnerstag früh bis Freitagmittag',
        'Freitagnachmittag gehört Dir', '2026-12-03T09:45', '2026-12-04T',
        // Agenda-Wortlaut vor dem 24.08.2026 (Arbeitsblöcke seither mit erklärendem Satz)
        'Arbeitsblock 1, drei Tische', 'Formulierung der Thesen inklusive',
        // Story-Reframing 28.08.2026: Netzwerk/Community vor Dissenspapier, kurze Header.
        // Fragmente der ALTEN Fassungen (kern, meta, eventld, dp.*); im neuen Stand nicht mehr vorhanden.
        'jede und jeder mit eigenen Fragen und Themen aus der Praxis', 'Gängige KI-Werkzeuge live geprüft',
        'Was Du am Montag danach in der Hand hast', 'geprüft gegen die Gegenargumente der KI',
        'kleinste gemeinsame Aussage', 'unverändert erneut gestellt', 'was Du bewusst liegen lässt',
        'Thesen und Begründungen, keine Urheber', 'bevor es jemand anderes gelesen hat',
        // Klartext-Lektorat 28.08.2026: Rätselsätze und „der Raum"-Metaphern durch konkrete Sätze ersetzt.
        'Fragen des Raums', 'worin sich der Raum einig', 'was wird aus der Funktion',
        'Was wird aus Phasenplänen', 'wie das System in sechs Monaten arbeitet',
        'schneller verschieben als Curricula', 'Veränderung ohne Enddatum',
        'Management im Kontext der Arbeit mit KI', 'Woran der Raum arbeitet',
        'formuliert der Raum seine Thesen', 'Der Raum challengt fachlich',
        'Preis dafür, dass die anderen 24', 'Der Tisch steht nicht zufällig hier',
        'Eingeladen ist die Funktion, nicht', 'Plätze am Tisch gehören der Funktion',
        'Zusammenstellung des Raums', 'nennst sie dem Raum', 'Bis dahin stehen die Rollen',
        'unter Bedingungen fremdbestimmten Tempos'];
}

/** Dauerhafte Selbstheilung (ohne Marker, daher idempotent und nicht durch einen früheren
 *  Migrationslauf blockiert): Felder gespeicherter Editionen, die eine überholte Formulierung
 *  enthalten, werden aus dem aktuellen Seed nachgezogen bzw. entfernt, damit der Standardtext
 *  greift. Geschrieben wird nur, wenn tatsächlich etwas gefunden wurde. */
function x25ed_phrasen_sweep(): void
{
    $alt = x25ed_alte_phrasen();
    $veraltet = static function ($wert) use ($alt): bool {
        if (!is_string($wert)) { return false; }
        foreach ($alt as $needle) { if (strpos($wert, $needle) !== false) { return true; } }
        return false;
    };
    foreach (glob(x25ed_dir() . '/*.json') ?: [] as $f) {
        $ed = json_decode((string)file_get_contents($f), true);
        if (!is_array($ed) || !x25ed_slug_ok((string)($ed['slug'] ?? ''))) { continue; }
        if ($ed['slug'] !== 'change-management') { continue; }
        $seedFile = X25ED_DIR . '/seed/' . $ed['slug'] . '.json';
        $seed = is_file($seedFile) ? json_decode((string)file_get_contents($seedFile), true) : null;
        $dirty = false;
        foreach (['landing', 'anmeldung', 'danke'] as $bereich) {
            foreach ((array)($ed['texte'][$bereich] ?? []) as $k => $v) {
                if ($veraltet($v)) { unset($ed['texte'][$bereich][$k]); $dirty = true; }
            }
        }
        // Lücken in nummerierten Listen (prefix.1, prefix.2 …) aus dem Seed schließen: Entfernt eine
        // Migration einen Zwischeneintrag, bricht x25ed_items()/x25ed_tuples() sonst an der Lücke ab
        // (z. B. Agenda ohne Einträge). Geheilt wird nur, wenn die Edition NACH der Lücke noch einen
        // Geschwister-Eintrag derselben Liste führt; bewusst gekürzte Listen (Enden gelöscht) bleiben kurz.
        foreach (['landing', 'anmeldung', 'danke'] as $bereich) {
            if (!is_array($seed)) { break; }
            $eigen = (array)($ed['texte'][$bereich] ?? []);
            foreach ((array)($seed['texte'][$bereich] ?? []) as $k => $v) {
                if (isset($eigen[$k]) || !is_string($v) || $veraltet($v)) { continue; }
                if (!preg_match('/^(.+)\.(\d+)(\..+)?$/', $k, $m)) { continue; }
                for ($j = (int)$m[2] + 1; $j <= 200; $j++) {
                    if (isset($eigen[$m[1] . '.' . $j . ($m[3] ?? '')])) {
                        $ed['texte'][$bereich][$k] = $v; $eigen[$k] = $v; $dirty = true;
                        break;
                    }
                }
            }
        }
        if ($veraltet($ed['kurz'] ?? null) && is_array($seed) && !$veraltet($seed['kurz'] ?? null)) {
            $ed['kurz'] = (string)$seed['kurz']; $dirty = true;
        }
        // Terminfelder der Edition (Karten, JSON-LD, Ticket-Mail, Rechnung) ebenfalls nachziehen,
        // wenn sie eine überholte Angabe enthalten – z. B. nach einer Terminverschiebung im Seed.
        foreach (['datum_text', 'datum_kurz', 'datum_start', 'datum_ende', 'leistungsdatum', 'zeiten'] as $feld) {
            if ($veraltet($ed[$feld] ?? null) && is_array($seed) && isset($seed[$feld]) && !$veraltet($seed[$feld])) {
                $ed[$feld] = (string)$seed[$feld]; $dirty = true;
            }
        }
        foreach (['kicker', 'fakten', 'meta'] as $feld) {
            if ($veraltet($ed['karte'][$feld] ?? null) && is_array($seed) && !$veraltet($seed['karte'][$feld] ?? null)) {
                $ed['karte'][$feld] = (string)$seed['karte'][$feld]; $dirty = true;
            }
        }
        foreach (['punkte', 'aside'] as $feld) {
            foreach ((array)($ed['karte'][$feld] ?? []) as $i => $v) {
                if ($veraltet($v) && is_array($seed) && !$veraltet($seed['karte'][$feld][$i] ?? null)) {
                    $ed['karte'][$feld][$i] = (string)$seed['karte'][$feld][$i]; $dirty = true;
                }
            }
        }
        if ($dirty) { x25ed_save($ed); }
    }
}

/** Einmalige Bereinigung (23.08.2026): negative/bevormundende Formulierungen und die sachlich
 *  ungenaue „KI sitzt mit am Tisch"-Rahmung ersetzt durch positivere, korrektere Standardtexte
 *  (die KI wird in gezielten Sessions fachlich geprüft, nicht dauerhaft „am Tisch"). Die
 *  aufgeführten Schlüssel fallen auf die neuen Standardtexte zurück; Backend-Änderungen ohne
 *  diese Formulierungen bleiben unangetastet. */
function x25ed_wording_migration(): void
{
    // Marker mit Version: Wird die Schlüsselliste erweitert, muss die Version steigen, damit der
    // Lauf auf Servern erneut greift, die den vorherigen Marker schon geschrieben haben.
    $marker = x25ed_dir() . '/.migration-wording-2026-08-v2';
    if (is_file($marker)) { return; }
    // Textschlüssel, die auf die neuen Standardtexte zurückfallen sollen (Formulierungen, die sich
    // geändert haben, ohne dass eine erkennbare Altphrase darin steht)
    $reset = [
        'landing' => ['eventld.beschreibung', 'fuerwen.1', 'kern', 'leitfrage.serif', 'meta.beschreibung',
            'signatur.label', 'signatur.lead', 'signatur.titel', 'story.2.text', 'story.3.text', 'story.4.text',
            'story.5.titel', 'impulse.lead', 'fuerwen.3', 'fuerwen.4', 'leitfrage.1.text'],
    ];
    foreach (glob(x25ed_dir() . '/*.json') ?: [] as $f) {
        $ed = json_decode((string)file_get_contents($f), true);
        if (!is_array($ed) || !x25ed_slug_ok((string)($ed['slug'] ?? ''))) { continue; }
        // Diese historischen Korrekturen gelten nur für die ursprünglichen Editionen.
        if (!in_array($ed['slug'], ['change-management'], true)) { continue; }
        $dirty = false;
        foreach ($reset as $bereich => $keys) {
            foreach ($keys as $k) {
                if (isset($ed['texte'][$bereich][$k])) { unset($ed['texte'][$bereich][$k]); $dirty = true; }
            }
        }
        if ($dirty) { x25ed_save($ed); }
    }
    @file_put_contents($marker, gmdate('c'));
}

/** Einmalige Bereinigung (22.08.2026): Konzept-Reframing („offene Fragen" statt „genau eine offene
 *  Frage", Dienstleister-Regelung, Formulartexte). Die aufgeführten Schlüssel werden aus den
 *  gespeicherten Editionstexten entfernt und fallen damit auf die neuen Standardtexte zurück;
 *  alle übrigen im Backend gepflegten Texte bleiben unangetastet. */
function x25ed_reframe_migration(): void
{
    $marker = x25ed_dir() . '/.migration-reframe-2026-08';
    if (is_file($marker)) { return; }
    $reset = [
        'landing' => ['ablauf.tag1.2', 'ablauf.tag1.3', 'anmeldung.absatz2', 'anmeldung.absatz3', 'eventld.beschreibung',
            'faq.1.antwort', 'faq.1.frage', 'faq.2.antwort', 'faq.3.antwort', 'faq.9.antwort', 'frage.link', 'frage.meta',
            'frage.text', 'frage.titel', 'fuerwen.1', 'fuerwen.schluss', 'kern', 'leitfrage.meta', 'leitfrage.text',
            'meta.beschreibung', 'story.1.text', 'story.2.text', 'story.2.titel', 'story.titel'],
        'anmeldung' => ['feld.ebene.optionen', 'feld.frage', 'feld.frage.hint', 'feld.typ.hint'],
        'danke' => ['schritt.3.text'],
    ];
    foreach (glob(x25ed_dir() . '/*.json') ?: [] as $f) {
        $ed = json_decode((string)file_get_contents($f), true);
        if (!is_array($ed) || !x25ed_slug_ok((string)($ed['slug'] ?? ''))) { continue; }
        // Diese historischen Korrekturen gelten nur für die ursprünglichen Editionen.
        if (!in_array($ed['slug'], ['change-management'], true)) { continue; }
        $dirty = false;
        foreach ($reset as $bereich => $keys) {
            foreach ($keys as $k) {
                if (isset($ed['texte'][$bereich][$k])) { unset($ed['texte'][$bereich][$k]); $dirty = true; }
            }
        }
        if ($dirty) { x25ed_save($ed); }
    }
    @file_put_contents($marker, gmdate('c'));
}

/** Einmalige Bereinigung (08/2026): gespeicherte Platzhalterwerte mit „[TBD" entfernen bzw. durch die
 *  Werte des aktuellen Seeds ersetzen. Entfernte Textschlüssel fallen auf die Standardtexte
 *  (texte.json) zurück; im Backend geänderte Texte ohne „[TBD" bleiben unangetastet. */
function x25ed_tbd_migration(): void
{
    $marker = x25ed_dir() . '/.migration-tbd-2026-08';
    if (is_file($marker)) { return; }
    // Schlüssel, deren alte Standardtexte auf die entfernte Hotel-Sektion verwiesen: zurücksetzen.
    $reset = ['landing' => ['faq.12.antwort', 'hotels.titel', 'hotels.text', 'hotels.kicker']];
    foreach (glob(x25ed_dir() . '/*.json') ?: [] as $f) {
        $ed = json_decode((string)file_get_contents($f), true);
        if (!is_array($ed) || !x25ed_slug_ok((string)($ed['slug'] ?? ''))) { continue; }
        // Diese historischen Korrekturen gelten nur für die ursprünglichen Editionen.
        if (!in_array($ed['slug'], ['change-management'], true)) { continue; }
        $seedFile = X25ED_DIR . '/seed/' . $ed['slug'] . '.json';
        $seed = is_file($seedFile) ? json_decode((string)file_get_contents($seedFile), true) : null;
        $dirty = false;
        foreach (['landing', 'anmeldung', 'danke'] as $bereich) {
            foreach ((array)($ed['texte'][$bereich] ?? []) as $k => $v) {
                $weg = (is_string($v) && strpos($v, '[TBD') !== false) || in_array($k, $reset[$bereich] ?? [], true);
                if ($weg) { unset($ed['texte'][$bereich][$k]); $dirty = true; }
            }
        }
        foreach (['hotel', 'kontakt_zeile'] as $feld) {
            if (is_string($ed[$feld] ?? null) && strpos($ed[$feld], '[TBD') !== false) {
                $neu = (is_array($seed) && is_string($seed[$feld] ?? null) && strpos($seed[$feld], '[TBD') === false) ? $seed[$feld] : '';
                $ed[$feld] = $neu; $dirty = true;
            }
        }
        foreach (['kicker', 'fakten', 'meta'] as $feld) {
            if (is_string($ed['karte'][$feld] ?? null) && strpos($ed['karte'][$feld], '[TBD') !== false
                && is_string($seed['karte'][$feld] ?? null) && strpos($seed['karte'][$feld], '[TBD') === false) {
                $ed['karte'][$feld] = $seed['karte'][$feld]; $dirty = true;
            }
        }
        foreach (['punkte', 'aside'] as $feld) {
            foreach ((array)($ed['karte'][$feld] ?? []) as $i => $v) {
                if (is_string($v) && strpos($v, '[TBD') !== false
                    && is_string($seed['karte'][$feld][$i] ?? null) && strpos($seed['karte'][$feld][$i], '[TBD') === false) {
                    $ed['karte'][$feld][$i] = $seed['karte'][$feld][$i]; $dirty = true;
                }
            }
        }
        if ($dirty) { x25ed_save($ed); }
    }
    @file_put_contents($marker, gmdate('c'));
}

function x25ed_get(string $slug): ?array
{
    if (!x25ed_slug_ok($slug)) { return null; }
    x25ed_seed();
    $f = x25ed_dir() . '/' . $slug . '.json';
    if (!is_file($f)) { return null; }
    $ed = json_decode((string)file_get_contents($f), true);
    return is_array($ed) ? $ed : null;
}

/** Alle Editionen, sortiert nach Startdatum (leere Daten zuletzt). */
function x25ed_all(): array
{
    x25ed_seed();
    $out = [];
    foreach (glob(x25ed_dir() . '/*.json') ?: [] as $f) {
        $ed = json_decode((string)file_get_contents($f), true);
        if (is_array($ed) && x25ed_slug_ok((string)($ed['slug'] ?? ''))) { $out[] = $ed; }
    }
    usort($out, static fn($a, $b) => strcmp((string)($a['datum_start'] ?: '9999') . $a['slug'], (string)($b['datum_start'] ?: '9999') . $b['slug']));
    return $out;
}

/** Öffentlich sichtbare Editionen (online + angekündigt). */
function x25ed_sichtbar(): array
{
    return array_values(array_filter(x25ed_all(), static fn($e) => in_array($e['status'] ?? '', ['online', 'angekuendigt'], true)));
}

function x25ed_save(array $ed): void
{
    $slug = (string)($ed['slug'] ?? '');
    if (!x25ed_slug_ok($slug)) { throw new RuntimeException('Ungültiger Slug.'); }
    $ed['updated_at'] = gmdate('c');
    $ed['created_at'] = $ed['created_at'] ?? gmdate('c');
    $f = x25ed_dir() . '/' . $slug . '.json';
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, json_encode($ed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException('Edition konnte nicht gespeichert werden (Schreibrechte im Datenverzeichnis?).');
    }
    @chmod($tmp, 0640);
    rename($tmp, $f);
    // OG-Cache der Edition verwerfen
    foreach (glob(x25ed_og_cache_dir() . '/' . $slug . '-*.jpg') ?: [] as $c) { @unlink($c); }
}

function x25ed_delete(string $slug): void
{
    if (!x25ed_slug_ok($slug)) { return; }
    @unlink(x25ed_dir() . '/' . $slug . '.json');
    foreach (glob(x25ed_og_cache_dir() . '/' . $slug . '-*.jpg') ?: [] as $c) { @unlink($c); }
}

function x25ed_og_cache_dir(): string
{
    $dir = rtrim((string)x25ed_cfg('DATA_DIR', X25ED_ROOT . '/anmeldung/data'), '/') . '/og-cache';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    return $dir;
}

// ------------------------------------------------------------------ Texte
/** Gesamte Textbasis aus edition/texte.json (gecacht). */
function x25ed_texte(): array
{
    static $t = null;
    if ($t === null) {
        $t = json_decode((string)file_get_contents(X25ED_DIR . '/texte.json'), true);
        if (!is_array($t)) { $t = ['vars' => [], 'gemeinsam' => [], 'fotos' => [], 'assets' => []]; }
    }
    return $t;
}

/** Platzhalter-Werte; Editions-Kontext ergänzt site/landing/anmeldung. */
function x25ed_vars(?array $ed = null): array
{
    $v = array_replace(x25ed_texte()['vars'] ?? [], (array)($ed['vars'] ?? []));
    $v['site'] = '/';
    $v['landing'] = $ed ? x25ed_url($ed) : '/editionen';
    $v['anmeldung'] = $ed ? x25ed_url($ed) . 'anmeldung' : '/editionen';
    return $v;
}

/** Rohtext rendern: [TBD: …] → sichtbarer Platzhalter, {platzhalter} ersetzen, interne Links ohne .html. */
function x25ed_render(string $text, array $vars): string
{
    $text = preg_replace('/\[TBD: ([^\]]+)\]/', '<span class="x-tbd">[TBD: $1]</span>', $text) ?? $text;
    if (str_contains($text, '{')) {
        $text = preg_replace_callback('/\{([a-z0-9_.-]+)\}/i', static fn($m) => array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0], $text) ?? $text;
    }
    // Interne Links in Texten sauber machen (wie build_deploy.py beim statischen Bau):
    // href="kontakt.html" / href="/kontakt.html" → href="/kontakt", href="index.html" → href="/"
    if (str_contains($text, '.html')) {
        foreach (['format', 'editionen', 'neutralitaetskodex', 'ueber-uns', 'kontakt', 'impressum', 'datenschutz'] as $n) {
            $text = str_replace(['href="' . $n . '.html', 'href="/' . $n . '.html'], 'href="/' . $n, $text);
        }
        $text = str_replace(['href="index.html"', 'href="/index.html"'], 'href="/"', $text);
    }
    return $text;
}

/**
 * Text einer Edition: zuerst die editions-eigenen Texte ($ed['texte'][$bereich]),
 * dann die Standardtexte ({$bereich}_default aus texte.json), dann gemeinsam.
 * $bereich: landing | anmeldung | danke | gemeinsam | website_index | website_editionen
 */
function x25ed_txt(?array $ed, string $bereich, string $key, ?string $fallback = null): string
{
    $t = x25ed_texte();
    $raw = $ed['texte'][$bereich][$key]
        ?? $t[$bereich . '_default'][$key]
        ?? $t[$bereich][$key]
        ?? $t['gemeinsam'][$key]
        ?? $fallback;
    if ($raw === null) { return ''; }
    return x25ed_render((string)$raw, x25ed_vars($ed));
}

/** Gemeinsamer Text (Seitenhülle, Bausteine). */
function x25ed_g(string $key, ?array $ed = null): string
{
    return x25ed_txt($ed, 'gemeinsam', $key, '');
}

/** Rohtext (ohne Platzhalter-Ersetzung, für die Verwaltung); null, wenn der Schlüssel nirgends existiert. */
function x25ed_raw(?array $ed, string $bereich, string $key): ?string
{
    $t = x25ed_texte();
    $raw = $ed['texte'][$bereich][$key]
        ?? $t[$bereich . '_default'][$key]
        ?? $t[$bereich][$key]
        ?? $t['gemeinsam'][$key]
        ?? null;
    return $raw === null ? null : (string)$raw;
}

/**
 * Nummerierte Schlüssel prefix.1, prefix.2 … als Liste. Definiert die Edition selbst
 * mindestens den ersten Eintrag, zählt NUR ihre eigene Liste (sonst würden gekürzte
 * Listen in die Standardtexte „durchfallen").
 */
function x25ed_items(?array $ed, string $bereich, string $prefix): array
{
    $eigen = isset($ed['texte'][$bereich]["$prefix.1"]);
    $out = [];
    for ($i = 1; $i <= 200; $i++) {
        if ($eigen ? !isset($ed['texte'][$bereich]["$prefix.$i"]) : !x25ed_has(null, $bereich, "$prefix.$i")) { break; }
        $out[] = x25ed_txt($ed, $bereich, "$prefix.$i");
    }
    return $out;
}

/** prefix.1.feld1/prefix.1.feld2 … als Tupel-Liste (gleiche Regel wie x25ed_items). */
function x25ed_tuples(?array $ed, string $bereich, string $prefix, string ...$felder): array
{
    $eigen = isset($ed['texte'][$bereich]["$prefix.1.$felder[0]"]);
    $out = [];
    for ($i = 1; $i <= 200; $i++) {
        if ($eigen ? !isset($ed['texte'][$bereich]["$prefix.$i.$felder[0]"]) : !x25ed_has(null, $bereich, "$prefix.$i.$felder[0]")) { break; }
        $row = [];
        foreach ($felder as $f) { $row[] = x25ed_txt($ed, $bereich, "$prefix.$i.$f"); }
        $out[] = $row;
    }
    return $out;
}

function x25ed_has(?array $ed, string $bereich, string $key): bool
{
    $t = x25ed_texte();
    return isset($ed['texte'][$bereich][$key]) || isset($t[$bereich . '_default'][$key]) || isset($t[$bereich][$key]) || isset($t['gemeinsam'][$key]);
}

/** Zeilen eines Schlüssels als Liste. */
function x25ed_lines(?array $ed, string $bereich, string $key): array
{
    return array_values(array_filter(array_map('trim', explode("\n", x25ed_txt($ed, $bereich, $key))), static fn($l) => $l !== ''));
}

// ------------------------------------------------------------------ Abgeleitete Editionswerte
function x25ed_url(array $ed): string
{
    return '/editionen/' . $ed['slug'] . '/';
}

function x25ed_abs_url(array $ed): string
{
    return rtrim(x25ed_site(), '/') . x25ed_url($ed);
}

/** Anzeigename mit Auszeichnung (x-fn); Fallback: aus Thema bzw. Klartextname gebaut. */
function x25ed_name_html(array $ed): string
{
    if (($ed['name_html'] ?? '') !== '') { return (string)$ed['name_html']; }
    $thema = trim((string)($ed['thema'] ?? ''));
    if ($thema !== '') { return '25 <span class="x-fn">' . x25ed_e($thema) . '</span> Experts'; }
    return x25ed_e((string)($ed['name'] ?? ''));
}

/** Kopfzeile „NAME · 02./03.12.2026 · Köln" (Formulare, Mails, Datensätze). */
function x25ed_label(array $ed): string
{
    return trim(implode(' · ', array_filter([(string)($ed['name'] ?? ''), (string)($ed['datum_kurz'] ?? ''), (string)($ed['ort'] ?? '')])));
}

function x25ed_preis(array $ed): float
{
    return round((float)($ed['preis_net'] ?? x25ed_cfg('PRICE_NET', 450.0)), 2);
}

function x25ed_preis_text(array $ed): string
{
    $n = x25ed_preis($ed);
    $s = number_format($n, ($n == floor($n) ? 0 : 2), ',', '.');
    return $s . ' €';
}

/** Snapshot der Editionswerte für den Anmeldedatensatz (Fallback, falls die Edition später gelöscht wird). */
function x25ed_snapshot(array $ed): array
{
    return [
        'slug' => $ed['slug'], 'name' => (string)($ed['name'] ?? ''),
        'datum_text' => (string)($ed['datum_text'] ?? ''), 'datum_kurz' => (string)($ed['datum_kurz'] ?? ''),
        'ort' => (string)($ed['ort'] ?? ''), 'venue' => (string)($ed['venue'] ?? ''),
        'zeiten' => (string)($ed['zeiten'] ?? ''), 'hotel' => (string)($ed['hotel'] ?? ''),
        'kontakt_zeile' => (string)($ed['kontakt_zeile'] ?? ''), 'leistungsdatum' => (string)($ed['leistungsdatum'] ?? ''),
        'preis_net' => x25ed_preis($ed), 'max_plaetze' => (int)($ed['max_plaetze'] ?? 25),
        'ticket_prefix' => (string)($ed['ticket_prefix'] ?? ''),
    ];
}

// ------------------------------------------------------------------ Plätze (liest die Anmeldungs-Ablage, nur lesend)
/** Standard-Slug für Altdatensätze ohne edition_slug (aus LANDING_PATH). */
function x25ed_default_slug(): string
{
    return basename(trim((string)x25ed_cfg('LANDING_PATH', 'editionen/change-management/'), '/'));
}

/** Belegte Plätze einer Edition; null, wenn die Ablage nicht lesbar ist (z. B. config fehlt). */
function x25ed_seats(string $slug): ?int
{
    try {
        require_once X25ED_ROOT . '/anmeldung/lib/store.php';
        $store = X25Store::open((string)x25ed_cfg('DATA_DIR', X25ED_ROOT . '/anmeldung/data'), (string)x25ed_cfg('STORE_BACKEND', 'auto'));
        $rule = (string)x25ed_cfg('SEATS_COUNT', 'zugelassen');
        $n = 0;
        foreach ($store->all() as $r) {
            $rs = (string)($r['edition_slug'] ?? '') ?: x25ed_default_slug();
            if ($rs !== $slug || ($r['status'] ?? '') !== 'zugelassen') { continue; }
            if ($rule === 'bezahlt' && ($r['payment_status'] ?? '') !== 'bezahlt') { continue; }
            $n++;
        }
        return $n;
    } catch (Throwable) {
        return null;
    }
}

/** Anzahl der Anmeldungen je Edition (für die Verwaltung); [] bei nicht lesbarer Ablage. */
function x25ed_anmeldungen_je_edition(): array
{
    try {
        require_once X25ED_ROOT . '/anmeldung/lib/store.php';
        $store = X25Store::open((string)x25ed_cfg('DATA_DIR', X25ED_ROOT . '/anmeldung/data'), (string)x25ed_cfg('STORE_BACKEND', 'auto'));
        $out = [];
        foreach ($store->all() as $r) {
            $rs = (string)($r['edition_slug'] ?? '') ?: x25ed_default_slug();
            $out[$rs] = ($out[$rs] ?? 0) + 1;
        }
        return $out;
    } catch (Throwable) {
        return [];
    }
}

// ------------------------------------------------------------------ Team-Zugang (Verwaltung + Anmeldungs-Admin)
/** Zugangsdatei: über die Verwaltung gesetzte Zugangsdaten (überstimmen ADMIN_* aus config.php). */
function x25ed_zugang_datei(): string
{
    return rtrim((string)x25ed_cfg('DATA_DIR', X25ED_ROOT . '/anmeldung/data'), '/') . '/verwaltung-zugang.json';
}

/** Aktueller Team-Zugang: ['user' => …, 'hash' => …]; Datei zuerst, sonst config.php; leer = nicht eingerichtet. */
function x25ed_zugang(): array
{
    $f = x25ed_zugang_datei();
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && ($d['hash'] ?? '') !== '') {
            return ['user' => (string)($d['user'] ?? 'gastgeber'), 'hash' => (string)$d['hash']];
        }
    }
    return ['user' => (string)x25ed_cfg('ADMIN_USER', 'gastgeber'), 'hash' => (string)x25ed_cfg('ADMIN_PASS_HASH', '')];
}

/** Felder der Zugangsdatei setzen (Merge); legt die Datei bei Bedarf an (chmod 600). */
function x25ed_zugang_merge(array $felder): void
{
    $f = x25ed_zugang_datei();
    $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $d = array_merge($d, $felder);
    if (file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('Zugangsdatei nicht beschreibbar (Datenverzeichnis prüfen).');
    }
    @chmod($f, 0600);
}

// ------------------------------------------------------------------ Vorschau (Entwürfe)
function x25ed_preview_sig(string $slug): string
{
    $secret = (string)x25ed_cfg('APP_SECRET', '');
    return $secret !== '' ? hash_hmac('sha256', 'vorschau|' . $slug, $secret) : '';
}

function x25ed_can_view(array $ed): bool
{
    if (in_array($ed['status'] ?? '', ['online'], true)) { return true; }
    $sig = (string)($_GET['vorschau'] ?? '');
    $want = x25ed_preview_sig((string)$ed['slug']);
    return $sig !== '' && $want !== '' && hash_equals($want, $sig);
}

// ------------------------------------------------------------------ HTML-Bausteine der öffentlichen Seiten
/** <img> aus dem Foto-Manifest (lokale Dateien unter /assets/img/fotos/). */
function x25ed_foto(string $key, string $cls = '', string $loading = 'lazy', ?string $alt = null): string
{
    $f = x25ed_texte()['fotos'][$key] ?? null;
    if (!$f) { return ''; }
    $alt = $alt ?? ('Symbolbild: ' . (string)$f['motiv']);
    $ld = $loading === 'eager' ? ' fetchpriority="high"' : ' loading="' . $loading . '" decoding="async"';
    $c = $cls !== '' ? ' class="' . $cls . '"' : '';
    return '<img' . $c . ' src="/assets/img/fotos/' . $f['file'] . '" width="' . (int)$f['w'] . '" height="' . (int)$f['h'] . '" alt="' . x25ed_e($alt) . '"' . $ld . '>';
}

function x25ed_asset(string $rel): string
{
    $v = x25ed_texte()['assets'][$rel] ?? '';
    return '/assets/' . $rel . ($v !== '' ? '?v=' . $v : '');
}

function x25ed_404(string $msg = 'Diese Seite gibt es nicht.'): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $ziel = '/editionen';
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Nicht gefunden · 25 EXPERTS</title></head>'
        . '<body style="margin:0;background:#FBFAF6;color:#0B1F26;font:18px/1.5 system-ui,sans-serif"><div style="max-width:640px;margin:12vh auto;padding:0 20px">'
        . '<p style="font-family:monospace;letter-spacing:1px;color:#0B6470">25 EXPERTS</p><h1>' . x25ed_e($msg) . '</h1>'
        . '<p><a href="' . $ziel . '" style="color:#0B6470">Zu den Editionen</a> · <a href="/" style="color:#0B6470">Zur Startseite</a></p></div></body></html>';
    exit;
}

function x25ed_out(string $html, int $status = 200, int $cacheSeconds = 600): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header($cacheSeconds > 0 ? 'Cache-Control: public, max-age=' . $cacheSeconds . ', must-revalidate' : 'Cache-Control: no-store');
    echo $html;
    exit;
}
