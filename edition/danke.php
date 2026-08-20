<?php
/**
 * 25 EXPERTS – Danke-Seite einer Edition (dynamisch): /editionen/{slug}/danke
 * PHP-Port von 05-landingpage/build_landing.danke(); Status-Varianten (?status=zugelassen|warteliste)
 * schaltet assets/js/site.js clientseitig um.
 */
declare(strict_types=1);

require_once __DIR__ . '/shell.php';

$slug = (string)($_GET['slug'] ?? '');
$ed = x25ed_get($slug);
if ($ed === null) { x25ed_404('Diese Edition gibt es nicht.'); }

$t = static fn(string $k): string => x25ed_txt($ed, 'danke', $k);
$e = static fn(?string $s): string => x25ed_e($s);
$canon = rtrim(x25ed_abs_url($ed), '/') . '/danke';
$landing = x25ed_url($ed);
$foto = x25ed_foto('kuverts', '', 'eager');

$body = <<<HTML

    <section class="x-pagehead x-thanks">
      <div class="x-container x-pagehead__inner">
        <div class="x-pagehead__title" data-reveal>
          <p class="x-kicker">{$t('kopf.kicker')}</p>
          <h1 class="x-h1" data-status-zugelassen="{$e(strip_tags($t('titel.zugelassen')))}" data-status-warteliste="{$e(strip_tags($t('titel.warteliste')))}">{$t('titel.standard')}</h1>
        </div>
        <div class="x-pagehead__lead" data-reveal><p class="x-lead" data-status-zugelassen="{$e(strip_tags($t('lead.zugelassen')))}" data-status-warteliste="{$e(strip_tags($t('lead.warteliste')))}">{$t('lead.standard')}</p></div>
        <div class="x-pagehead__photo" data-reveal>{$foto}</div>
      </div>
    </section>
    <section class="x-section x-section--flush-top">
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('schritte.kicker')}</p>
          <h2 class="x-h2">{$t('schritte.titel')}</h2>
        </div>
        <ol class="x-steps x-steps--row" data-reveal-group>
          <li><h3 class="x-h4">{$t('schritt.1.titel')}</h3><p>{$t('schritt.1.text')}</p></li>
          <li><h3 class="x-h4">{$t('schritt.2.titel')}</h3><p>{$t('schritt.2.text')}</p></li>
          <li><h3 class="x-h4">{$t('schritt.3.titel')}</h3><p>{$t('schritt.3.text')}</p></li>
        </ol>
        <div class="x-actions x-mt-16" data-reveal>
          <a class="x-btn x-btn--secondary" href="{$landing}">{$t('button.zurueck')}</a>
          <a class="x-btn x-btn--secondary" href="/format">{$t('button.ablauf')}</a>
        </div>
      </div>
    </section>
HTML;

x25ed_out(x25ed_shell([
    'ed' => $ed,
    'title' => $t('meta.titel'),
    'description' => strip_tags($t('meta.beschreibung')),
    'body' => $body,
    'canonical' => $canon,
    'noindex' => true,
    'cta_href' => $landing . 'anmeldung',
]), 200, 0);
