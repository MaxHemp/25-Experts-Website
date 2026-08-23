<?php
/**
 * 25 EXPERTS – Seitenhülle der dynamischen Editions-Seiten.
 * PHP-Port von build_site.shell() (04-website): Kopf mit Navigation, Fuß, Meta/OG.
 * Pfade sind absolut (Auslieferung unter /editionen/…); Texte aus edition/texte.json.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/**
 * $o: slug, title, description, body, canonical, extra_head, og_type, cta_href, cta_label,
 *     overlay(bool), og_image, og_image_w/h, og_image_alt, ed (Edition für {landing}/{anmeldung}), noindex(bool)
 */
function x25ed_shell(array $o): string
{
    $ed = $o['ed'] ?? null;
    $g = static fn(string $k): string => x25ed_g($k, $ed);
    $e = static fn(?string $s): string => x25ed_e($s);
    $navItems = '';
    foreach ([['/format', 'nav.format'], ['/editionen', 'nav.editionen'], ['/neutralitaetskodex', 'nav.kodex'], ['/ueber-uns', 'nav.ueber-uns'], ['/kontakt', 'nav.kontakt']] as [$href, $key]) {
        $cur = ($o['current'] ?? '') === $href ? ' aria-current="page"' : '';
        $navItems .= '<li><a href="' . $href . '"' . $cur . '>' . $g($key) . '</a></li>' . "\n          ";
    }
    $cta = $o['cta_href'] ?? ($ed ? x25ed_url($ed) . 'anmeldung' : '/editionen');
    $ctaLabel = $o['cta_label'] ?? $g('cta.anmelden');
    $domain = (string)(x25ed_texte()['domain'] ?? 'https://25-experts.de/');
    $ogImage = $o['og_image'] ?? ($domain . 'assets/img/og/25experts-og.jpg');
    $ogW = (int)($o['og_image_w'] ?? 1200);
    $ogH = (int)($o['og_image_h'] ?? 630);
    $ogAlt = $o['og_image_alt'] ?? ($g('claim.kurz') . '. 25-experts.de');
    $hcls = !empty($o['overlay']) ? 'x-header x-header--overlay' : 'x-header';
    $robots = !empty($o['noindex']) ? '  <meta name="robots" content="noindex, nofollow">' . "\n" : '';
    $landingFooter = $ed && ($ed['status'] ?? '') === 'online' ? x25ed_url($ed) : '/editionen';
    $mail = $g('kontakt.mail');
    $css1 = x25ed_asset('css/tokens.css'); $css2 = x25ed_asset('css/components.css'); $css3 = x25ed_asset('css/site.css');
    $js = x25ed_asset('js/site.js');
    $extra = $o['extra_head'] ?? '';
    $bodyHtml = $o['body'];

    return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$e($o['title'])}</title>
  <meta name="description" content="{$e($o['description'])}">
  <link rel="canonical" href="{$e($o['canonical'])}">
{$robots}  <meta property="og:type" content="{$e($o['og_type'] ?? 'website')}">
  <meta property="og:site_name" content="25 EXPERTS">
  <meta property="og:locale" content="de_DE">
  <meta property="og:title" content="{$e($o['title'])}">
  <meta property="og:description" content="{$e($o['description'])}">
  <meta property="og:url" content="{$e($o['canonical'])}">
  <meta property="og:image" content="{$e($ogImage)}">
  <meta property="og:image:width" content="{$ogW}">
  <meta property="og:image:height" content="{$ogH}">
  <meta property="og:image:alt" content="{$e($ogAlt)}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="theme-color" content="#0B1F26">
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="preload" href="/assets/fonts/PlusJakartaSans-ExtraBold.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/assets/fonts/PlusJakartaSans-Medium.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/assets/fonts/IBMPlexMono-Medium.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="{$css1}">
  <link rel="stylesheet" href="{$css2}">
  <link rel="stylesheet" href="{$css3}">
  <script>document.documentElement.classList.add('js');</script>
{$extra}</head>
<body>
  <a class="x-skip" href="#inhalt">{$g('shell.skip')}</a>
  <header class="{$hcls}" id="x-header">
    <div class="x-container x-header__inner">
      <a class="x-header__logo" href="/" aria-label="25 EXPERTS, zur Startseite">
        <img class="x-logo--light" src="/assets/img/25experts-logo-horizontal.svg" alt="25 EXPERTS" width="360" height="80">
        <img class="x-logo--dark" src="/assets/img/25experts-logo-horizontal-white.svg" alt="" aria-hidden="true" width="360" height="80">
      </a>
      <button class="x-burger" type="button" aria-expanded="false" aria-controls="x-nav" aria-label="{$g('shell.menue')}"><span class="x-burger__lines"><span></span></span></button>
      <nav class="x-nav" id="x-nav" aria-label="Hauptnavigation">
        <ul>
          {$navItems}
        </ul>
        <a class="x-btn x-btn--primary x-btn--sm" href="{$e($cta)}">{$ctaLabel}</a>
      </nav>
    </div>
  </header>

  <main id="inhalt" tabindex="-1">
{$bodyHtml}
  </main>

  <footer class="x-footer" role="contentinfo">
    <div class="x-container">
      <div class="x-footer__grid">
        <div class="x-footer__brand">
          <a href="/" aria-label="25 EXPERTS, zur Startseite"><img src="/assets/img/25experts-logo-horizontal-mono-white.svg" alt="25 EXPERTS" width="360" height="80"></a>
          <p class="x-footer__claim">{$g('footer.claim')}</p>
          <p>{$g('footer.text')}</p>
        </div>
        <div>
          <p class="x-footer__title">{$g('footer.titel.format')}</p>
          <ul>
            <li><a href="/format">{$g('nav.format')}</a></li>
            <li><a href="/editionen">{$g('nav.editionen')}</a></li>
            <li><a href="{$landingFooter}">{$g('nav.landing')}</a></li>
            <li><a href="/neutralitaetskodex">{$g('nav.kodex')}</a></li>
          </ul>
        </div>
        <div>
          <p class="x-footer__title">{$g('footer.titel.gesellschaft')}</p>
          <ul>
            <li><a href="/ueber-uns">{$g('nav.ueber-uns')}</a></li>
            <li><a href="/kontakt">{$g('nav.kontakt')}</a></li>
            <li><a href="/impressum">{$g('nav.impressum')}</a></li>
          </ul>
        </div>
        <div>
          <p class="x-footer__title">{$g('footer.titel.kontakt')}</p>
          <ul>
            <li><a href="mailto:{$e($mail)}">{$e($mail)}</a></li>
            <li><a href="https://www.linkedin.com/company/141373969/" rel="noopener">LinkedIn</a></li>
            <li>{$g('location.name')}, Köln</li>
          </ul>
        </div>
      </div>
      <p class="x-footer__fine">{$g('partner-hinweis')}</p>
      <div class="x-footer__bottom">
        <span>{$g('footer.copyright')}</span>
        <ul>
          <li><a href="/impressum">{$g('nav.impressum')}</a></li>
          <li><a href="/datenschutz">{$g('nav.datenschutz')}</a></li>
          <li><a href="/teilnahmebedingungen">{$g('nav.teilnahmebedingungen')}</a></li>
          <li><a href="/neutralitaetskodex">{$g('nav.kodex')}</a></li>
        </ul>
      </div>
    </div>
  </footer>
  <script src="{$js}" defer></script>
</body>
</html>
HTML;
}

// ------------------------------------------------------------------ Bausteine (Ports aus build_site.py)
function x25ed_pagehead(string $title, string $lead, string $kicker = ''): string
{
    $k = $kicker !== '' ? '<p class="x-kicker">' . $kicker . '</p>' : '';
    return <<<HTML

    <section class="x-pagehead">
      <div class="x-container x-pagehead__inner">
        <div class="x-pagehead__title" data-reveal>
          {$k}
          <h1 class="x-h1">{$title}</h1>
        </div>
        <div class="x-pagehead__lead" data-reveal><p class="x-lead">{$lead}</p></div>
      </div>
    </section>
HTML;
}

/** Die drei Signaturelemente als Foto-Karten (Bausteine signatur.N.*). */
function x25ed_signature(?array $ed): string
{
    $fotos = ['leinwand', 'kuverts', 'dokument'];
    $items = '';
    foreach (x25ed_tuples($ed, 'gemeinsam', 'signatur', 'titel', 'text') as $i => [$title, $text]) {
        $nr = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
        $img = x25ed_foto($fotos[$i] ?? 'leinwand');
        $mehr = x25ed_g('signatur.mehr', $ed);
        $items .= <<<HTML

        <li class="x-sig">
          <div class="x-sig__img"><span class="x-sig__nr">{$nr}</span>{$img}</div>
          <h3 class="x-sig__title" id="sigcard-{$nr}">{$title}</h3>
          <p class="x-sig__text">{$text}</p>
          <a class="x-link x-link--arrow x-sig__more" href="/format#sig-{$nr}">{$mehr}</a>
        </li>
HTML;
    }
    return '<ul class="x-signature" data-reveal-group>' . $items . "\n      </ul>";
}

/** Wertversprechen-Sektion (Bausteine wert.N / diff.N). */
function x25ed_wert_section(?array $ed, string $ctaHref, string $lead): string
{
    $g = static fn(string $k): string => x25ed_g($k, $ed);
    $w = '';
    foreach (x25ed_tuples($ed, 'gemeinsam', 'wert', 'titel', 'text') as [$t, $d]) {
        $w .= "\n          <li><h3 class=\"x-h4\">{$t}</h3><p>{$d}</p></li>";
    }
    $d = '';
    foreach (x25ed_tuples($ed, 'gemeinsam', 'diff', 'titel', 'text') as [$t, $x]) {
        $d .= "\n            <li><strong>{$t}</strong> {$x}</li>";
    }
    return <<<HTML

    <section class="x-section x-section--muted" aria-labelledby="wert-h">
      <p class="x-side-label">{$g('wert.kicker')}</p>
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$g('wert.kicker')}</p>
          <h2 id="wert-h" class="x-h2">{$g('wert.titel')}</h2>
          <p class="x-lead">{$lead}</p>
        </div>
        <ol class="x-steps x-steps--row x-steps--five" data-reveal-group>{$w}
        </ol>
        <div class="x-split x-mt-16">
          <div class="x-split__lead" data-reveal>
            <p class="x-kicker">{$g('diff.kicker')}</p>
            <h3 class="x-h3">{$g('diff.titel')}</h3>
          </div>
          <div class="x-split__body" data-reveal>
            <ul class="x-list x-list--check x-list--loose" style="max-width:64ch">{$d}
            </ul>
          </div>
        </div>
        <p class="x-mt-10" data-reveal><a class="x-btn x-btn--primary" href="{$ctaHref}">{$g('cta.anmelden')}</a></p>
      </div>
    </section>
HTML;
}

/** Kodex-Teaser (Bausteine kodex.teaser.N). */
function x25ed_kodex_teaser(?array $ed, string $linkLabel): string
{
    $g = static fn(string $k): string => x25ed_g($k, $ed);
    $pts = '';
    foreach (x25ed_tuples($ed, 'gemeinsam', 'kodex.teaser', 'titel', 'text') as [$t, $d]) {
        $pts .= "\n            <li><strong>{$t}</strong> {$d}</li>";
    }
    return <<<HTML

      <div class="x-container x-split x-split--wide">
        <div class="x-split__lead" data-reveal>
          <p class="x-kicker">{$g('kodex.teaser.kicker')}</p>
          <h2 id="kodex-h" class="x-h2">{$g('kodex.teaser.titel')}</h2>
          <p><a class="x-btn x-btn--on-dark-outline" href="/neutralitaetskodex">{$linkLabel}</a></p>
        </div>
        <div class="x-split__body" data-reveal>
          <blockquote class="x-quote x-quote--sm"><p>{$g('kodex.frage')}</p></blockquote>
          <ul class="x-list x-list--check x-list--loose x-mt-8" style="max-width:64ch">{$pts}
          </ul>
        </div>
      </div>
HTML;
}
