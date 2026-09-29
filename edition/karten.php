<?php
/**
 * 25 EXPERTS – Editions-Karten als HTML-Fragment (für Startseite und Übersicht).
 * /edition/karten.php            Karten der sichtbaren Editionen (online + angekündigt)
 * Die Startseite (statisch gebaut) tauscht ihre eingebaute Editions-Sektion per JavaScript
 * gegen dieses Fragment aus (assets/js/site.js, [data-editionen-karten]); ohne JavaScript
 * bleibt der eingebaute Stand sichtbar.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/identity.php';

// Direktaufruf als Endpoint; als Bibliothek (uebersicht.php) nur die Funktionen bereitstellen
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300, must-revalidate');
    echo x25ed_karten_html();
}

/** Karten aller sichtbaren Editionen; ohne data-reveal (wird nachträglich ins DOM gesetzt). */
function x25ed_karten_html(): string
{
    $out = '';
    foreach (x25ed_sichtbar() as $ed) {
        $out .= ($ed['status'] === 'online') ? x25ed_karte_online($ed) : x25ed_karte_teaser($ed);
    }
    return $out;
}

function x25ed_karte_online(array $ed): string
{
    $name = x25ed_e((string)$ed['name']);
    $date = x25ed_e((string)$ed['datum_text']);
    $status = !empty($ed['termin_vorlaeufig']) ? 'Termin vorläufig' : 'Termin bestätigt';
    $url = x25ed_e(x25ed_url($ed));
    $featured = ($ed['slug'] ?? '') === 'change-management';
    $cls = $featured ? ' ux-edition--featured' : '';
    $tag = $featured ? '<p class="x-kicker">Das nächste Event</p>' : '';
    $identity = x25ed_identity((string)($ed['slug'] ?? ''));
    $number = x25ed_e($identity[0]);
    $question = x25ed_e($identity[2]);
    return '<article class="ux-edition' . $cls . '" aria-label="' . $name . '"><span class="nx-edition__number" aria-hidden="true">' . $number . '</span><div>' . $tag . '<h3><a href="' . $url . '">' . $name . '</a></h3><p class="nx-edition__question">' . $question . '</p></div><p class="ux-edition__date">' . $date . '<span>' . $status . '</span></p><a class="x-link" href="' . $url . '">Edition entdecken <span aria-hidden="true">↗</span></a></article>';
}

function x25ed_karte_teaser(array $ed): string
{
    $e = static fn(?string $s): string => x25ed_e($s);
    $karte = (array)($ed['karte'] ?? []);
    $vars = x25ed_vars(null);
    $kicker = x25ed_render((string)($karte['kicker'] ?? 'In Vorbereitung'), $vars);
    $fakten = '';
    foreach (array_filter(array_map('trim', explode("\n", (string)($karte['fakten'] ?? '')))) as $f) {
        $fakten .= '<li>' . x25ed_render($f, $vars) . '</li>';
    }
    $text = x25ed_render((string)($ed['kurz'] ?? ''), $vars);
    $nameHtml = x25ed_name_html($ed);
    $aside = '';
    foreach ((array)($karte['aside'] ?? []) as $a) { $aside .= '<p>' . x25ed_render((string)$a, $vars) . '</p>'; }
    if ($aside === '') { $aside = '<p>' . x25ed_render((string)($karte['meta'] ?? ''), $vars) . '</p>'; }
    return <<<HTML

          <article class="x-edition x-edition--next" aria-label="{$e((string)$ed['name'])}">
            <div class="x-edition__main">
              <p class="x-kicker">{$kicker}</p>
              <h3 class="x-edition__name">{$nameHtml}</h3>
              <ul class="x-facts">{$fakten}</ul>
              <p>{$text}</p>
            </div>
            <div class="x-edition__aside">
              {$aside}
            </div>
          </article>
HTML;
}
