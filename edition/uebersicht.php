<?php
/**
 * 25 EXPERTS – Editions-Übersicht (dynamisch): /editionen  →  edition/uebersicht.php
 * PHP-Port von build_site.page_editionen(): Karten aller sichtbaren Editionen (aus der
 * Verwaltung) plus Themen-Pipeline und Kuvert-Sektion (Texte aus content/website/editionen.txt
 * über edition/texte.json).
 */
declare(strict_types=1);

require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/karten.php';

$w = static fn(string $k): string => x25ed_txt(null, 'website_editionen', $k);
$e = static fn(?string $s): string => x25ed_e($s);
$domain = (string)(x25ed_texte()['domain'] ?? 'https://25-experts.de/');
$canon = rtrim($domain, '/') . '/editionen';

$kopf = x25ed_pagehead($w('kopf.titel'), $w('kopf.lead'), $w('kopf.kicker'));

// Karten wie auf der Startseite, hier mit den Detail-Punkten der Editionen
$cards = '';
foreach (x25ed_sichtbar() as $ed) {
    if ($ed['status'] === 'online') {
        $card = x25ed_karte_online($ed);
        $punkte = '';
        foreach ((array)($ed['karte']['punkte'] ?? []) as $p) {
            $punkte .= "\n              <li>" . x25ed_render((string)$p, x25ed_vars($ed)) . '</li>';
        }
        if ($punkte !== '') {
            $liste = '<ul class="x-list" style="font-size:var(--x-fs-sm)">' . $punkte . "\n            </ul>\n              ";
            $card = str_replace('<div class="x-actions">', $liste . '<div class="x-actions">', $card);
        }
        $cards .= $card;
    } else {
        $cards .= x25ed_karte_teaser($ed);
    }
}
if ($cards === '') {
    $cards = '<div class="x-card x-card--lg"><p class="x-kicker">Editionen</p><p>Gerade ist keine Edition ausgeschrieben. Schreib uns an <a href="mailto:' . x25ed_g('kontakt.mail') . '">' . x25ed_g('kontakt.mail') . '</a>, wenn Du zur nächsten Edition eingeladen werden möchtest.</p></div>';
}

$pipe = '';
foreach (x25ed_lines(null, 'website_editionen', 'pipeline.themen') as $p) {
    $pipe .= "\n          <li><span class=\"x-tag\">{$p}</span></li>";
}

$body = <<<HTML

    {$kopf}

    <section class="x-section x-section--flush-top" aria-label="{$e(strip_tags($w('kopf.titel')))}">
      <div class="x-container x-editions">
        {$cards}
      </div>
    </section>

    <section class="x-section x-section--muted" aria-labelledby="pipe-h">
      <div class="x-container x-split">
        <div class="x-split__lead" data-reveal>
          <p class="x-kicker">{$w('pipeline.kicker')}</p>
          <h2 id="pipe-h" class="x-h2">{$w('pipeline.titel')}</h2>
          <p>{$w('pipeline.text')}</p>
        </div>
        <div class="x-split__body" data-reveal>
          <ul class="x-tags" aria-label="{$e(strip_tags($w('pipeline.aria')))}">{$pipe}
          </ul>
          <p class="x-mt-8">{$w('pipeline.kontakt')}</p>
        </div>
      </div>
    </section>

    <section class="x-section" aria-labelledby="kuvert-h">
      <div class="x-container x-grid x-grid--center">
        <div class="x-col-6" data-reveal>
          <p class="x-kicker">{$w('kuvert.kicker')}</p>
          <h2 id="kuvert-h" class="x-h2">{$w('kuvert.titel')}</h2>
          <div class="x-lead">
            <p>{$w('kuvert.absatz1')}</p>
            <p>{$w('kuvert.absatz2')}</p>
            <p>{$w('kuvert.absatz3')}</p>
          </div>
        </div>
        <div class="x-col-5 x-offset-1" data-reveal>
          <div class="x-envelope"><span class="x-envelope__seal" aria-hidden="true">25</span><p class="x-envelope__kicker">{$w('kuvert.visual.kicker')}</p><p class="x-envelope__text">{$w('kuvert.visual.text')}</p><div class="x-envelope__foot"><span>{$w('kuvert.visual.fuss.links')}</span><span>{$w('kuvert.visual.fuss.rechts')}</span></div></div>
        </div>
      </div>
    </section>
HTML;

x25ed_out(x25ed_shell([
    'title' => $w('meta.titel'),
    'description' => strip_tags($w('meta.beschreibung')),
    'body' => $body,
    'canonical' => $canon,
    'current' => '/editionen',
]));
