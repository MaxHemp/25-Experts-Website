<?php
/**
 * 25 EXPERTS – Sitemap (dynamisch): /sitemap.xml  →  edition/sitemap.php
 * Statische Seiten plus die Online-Editionen aus der Verwaltung (Landingpage + Anmeldeseite).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$domain = rtrim((string)(x25ed_texte()['domain'] ?? 'https://25-experts.de/'), '/') . '/';
// lastmod: echtes Änderungsdatum (Datei bzw. Edition), nicht pauschal „heute“ – sonst ignorieren Suchmaschinen es.
$root = dirname(__DIR__);
$datei = static fn(string $f): string => is_file($root . '/' . $f) ? date('Y-m-d', (int)filemtime($root . '/' . $f)) : date('Y-m-d');
$urls = [
    [$domain, $datei('index.html')],
    [$domain . 'format', $datei('format.html')],
    [$domain . 'editionen', null],
    [$domain . 'neutralitaetskodex', $datei('neutralitaetskodex.html')],
    [$domain . 'ueber-uns', $datei('ueber-uns.html')],
    [$domain . 'kontakt', $datei('kontakt.html')],
];
$neuste = '';
foreach (x25ed_all() as $ed) {
    if (($ed['status'] ?? '') !== 'online') { continue; }
    $stand = substr((string)($ed['updated_at'] ?? ''), 0, 10) ?: date('Y-m-d');
    $neuste = max($neuste, $stand);
    $urls[] = [rtrim($domain, '/') . x25ed_url($ed), $stand];
    if (!empty($ed['anmeldung_offen'])) { $urls[] = [rtrim($domain, '/') . x25ed_url($ed) . 'anmeldung', $stand]; }
}
$urls[2][1] = $neuste ?: date('Y-m-d');

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$u, $stand]) {
    echo '  <url><loc>' . htmlspecialchars($u, ENT_XML1) . '</loc><lastmod>' . $stand . '</lastmod></url>' . "\n";
}
echo '</urlset>' . "\n";
