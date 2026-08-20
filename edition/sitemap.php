<?php
/**
 * 25 EXPERTS – Sitemap (dynamisch): /sitemap.xml  →  edition/sitemap.php
 * Statische Seiten plus die Online-Editionen aus der Verwaltung (Landingpage + Anmeldeseite).
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$domain = rtrim((string)(x25ed_texte()['domain'] ?? 'https://25-experts.de/'), '/') . '/';
$heute = date('Y-m-d');
$urls = [
    $domain,
    $domain . 'format',
    $domain . 'editionen',
    $domain . 'neutralitaetskodex',
    $domain . 'ueber-uns',
    $domain . 'kontakt',
    $domain . 'impressum',
    $domain . 'datenschutz',
];
foreach (x25ed_all() as $ed) {
    if (($ed['status'] ?? '') !== 'online') { continue; }
    $urls[] = rtrim($domain, '/') . x25ed_url($ed);
    if (!empty($ed['anmeldung_offen'])) { $urls[] = rtrim($domain, '/') . x25ed_url($ed) . 'anmeldung'; }
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . htmlspecialchars($u, ENT_XML1) . '</loc><lastmod>' . $heute . '</lastmod></url>' . "\n";
}
echo '</urlset>' . "\n";
