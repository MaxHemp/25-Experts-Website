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
    $v = x25ed_texte()['vars'] ?? [];
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
    return x25ed_render((string)(x25ed_texte()['gemeinsam'][$key] ?? ''), x25ed_vars($ed));
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

/** Kopfzeile „NAME · 03./04.12.2026 · Köln" (Formulare, Mails, Datensätze). */
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
