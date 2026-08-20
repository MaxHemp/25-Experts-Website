<?php
/**
 * 25 EXPERTS – Landingpage einer Edition (dynamisch): /editionen/{slug}/  →  edition/seite.php?slug=…
 * PHP-Port von 05-landingpage/build_landing.landing(); Inhalte aus der Editions-Datei (verwaltung/)
 * mit Fallback auf die Standardtexte (edition/texte.json).
 * Entwürfe sind nur mit Vorschau-Link sichtbar; archivierte Editionen leiten auf die Übersicht um.
 */
declare(strict_types=1);

require_once __DIR__ . '/shell.php';

$slug = (string)($_GET['slug'] ?? '');
$ed = x25ed_get($slug);
if ($ed === null) { x25ed_404('Diese Edition gibt es nicht.'); }
if (($ed['status'] ?? '') === 'archiviert' && !x25ed_can_view($ed)) {
    header('Location: /editionen', true, 302);
    exit;
}
if (!x25ed_can_view($ed)) {
    if (($ed['status'] ?? '') === 'angekuendigt') { header('Location: /editionen', true, 302); exit; }
    x25ed_404('Diese Edition gibt es nicht.');
}
$vorschau = ($ed['status'] ?? '') !== 'online';

$t = static fn(string $k): string => x25ed_txt($ed, 'landing', $k);
$g = static fn(string $k): string => x25ed_g($k, $ed);
$e = static fn(?string $s): string => x25ed_e($s);
$canon = x25ed_abs_url($ed);
$anm = x25ed_url($ed) . 'anmeldung';
$nameHtml = x25ed_name_html($ed);
$kern = trim($t('kern') . ' ' . (x25ed_texte()['vars']['am_tisch'] ?? ''));
$domain = (string)(x25ed_texte()['domain'] ?? 'https://25-experts.de/');

// ------------------------------------------------------------------ JSON-LD (Event + FAQ)
$ld = '';
if (!$vorschau) {
    $venueLd = (array)($ed['venue_ld'] ?? []);
    $event = [
        '@context' => 'https://schema.org', '@type' => 'Event',
        'name' => (string)$ed['name'],
        'description' => strip_tags($t('eventld.beschreibung')),
        'startDate' => (string)($ed['datum_start'] ?? ''), 'endDate' => (string)($ed['datum_ende'] ?? ''),
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'inLanguage' => 'de', 'maximumAttendeeCapacity' => (int)($ed['max_plaetze'] ?? 25),
        'location' => [
            '@type' => 'Place', 'name' => (string)($venueLd['name'] ?? $ed['venue'] ?? ''),
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => (string)($venueLd['strasse'] ?? ''), 'postalCode' => (string)($venueLd['plz'] ?? ''), 'addressLocality' => (string)($venueLd['stadt'] ?? $ed['ort'] ?? ''), 'addressCountry' => 'DE'],
        ],
        'organizer' => ['@type' => 'Organization', 'name' => '25 Experts Cologne UG (haftungsbeschränkt)', 'url' => $domain],
        'offers' => [
            '@type' => 'Offer', 'name' => strip_tags($t('eventld.angebot.name')),
            'price' => (string)x25ed_preis($ed), 'priceCurrency' => 'EUR',
            'description' => strip_tags($t('eventld.angebot.beschreibung')),
            'availability' => 'https://schema.org/LimitedAvailability',
            'url' => rtrim($canon, '/') . '/anmeldung',
        ],
        'url' => $canon,
    ];
    if (($ed['anmeldung_ab'] ?? '') !== '') { $event['offers']['validFrom'] = (string)$ed['anmeldung_ab']; }
    $ld .= '  <script type="application/ld+json">' . "\n  " . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n  </script>\n";
    $faqEntities = [];
    foreach (x25ed_tuples($ed, 'landing', 'faq', 'frage', 'antwort') as [$f, $a]) {
        $faqEntities[] = ['@type' => 'Question', 'name' => strip_tags($f), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags($a)]];
    }
    if ($faqEntities) {
        $ld .= '  <script type="application/ld+json">' . "\n  " . json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqEntities], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n  </script>\n";
    }
}

// ------------------------------------------------------------------ Tagesplan
function x25ed_schedule(array $ed, string $day, string $meta, string $prefix): string
{
    $lis = '';
    foreach (x25ed_items($ed, 'landing', $prefix) as $entry) {
        $parts = array_map('trim', explode('|', $entry));
        $t = $parts[0] ?? ''; $txt = $parts[1] ?? '';
        $desc = $parts[2] ?? '';
        $markers = isset($parts[3]) ? preg_split('/\s+/', $parts[3]) : [];
        $cls = [];
        if (in_array('signatur', $markers, true)) { $cls[] = 'is-signature'; }
        if (in_array($txt, ['Lunch', 'Kaffeepause', 'Frühstück', 'Ankommen mit kleinem Frühstück'], true)) { $cls[] = 'is-break'; }
        $c = $cls ? ' class="' . implode(' ', $cls) . '"' : '';
        $d = $desc !== '' ? '<p class="x-timeline__desc">' . $desc . '</p>' : '';
        $chip = '';
        foreach ($markers as $m) {
            if (str_starts_with((string)$m, 'foto:')) { $chip = x25ed_foto(substr((string)$m, 5), 'x-timeline__chip'); }
        }
        $lis .= '<li' . $c . '><time class="x-timeline__time" datetime="' . x25ed_e($t) . '">' . $t . '</time><span class="x-timeline__dot" aria-hidden="true"></span><div class="x-timeline__body"><p class="x-timeline__title">' . $txt . '</p>' . $d . $chip . '</div></li>' . "\n              ";
    }
    return <<<HTML

          <div data-reveal>
            <h3 class="x-timeline__day">{$day}<span class="x-meta">{$meta}</span></h3>
            <ol class="x-timeline">
              {$lis}
            </ol>
          </div>
HTML;
}

// ------------------------------------------------------------------ Sektionen
$fw = '';
foreach (x25ed_items($ed, 'landing', 'fuerwen') as $x) { $fw .= "\n              <li>{$x}</li>"; }
$beispiele = '';
foreach (x25ed_items($ed, 'landing', 'frage.beispiel') as $x) { $beispiele .= "\n              <li>{$x}</li>"; }
$leitfragen = '';
foreach (x25ed_tuples($ed, 'landing', 'leitfrage', 'titel', 'text') as [$lt, $lx]) { $leitfragen .= "\n            <li><div><strong>{$lt}</strong> {$lx}</div></li>"; }
$impulse = '';
foreach (x25ed_tuples($ed, 'landing', 'impuls', 'kicker', 'titel', 'text') as [$ik, $it, $ix]) {
    $impulse .= <<<HTML
<li>
            <p class="x-kicker">{$ik}</p>
            <h3 class="x-h4">{$it}</h3>
            <p>{$ix}</p>
          </li>

HTML;
}
$dp = '';
foreach (x25ed_items($ed, 'landing', 'dp.punkt') as $x) { $dp .= "\n            <li>{$x}</li>"; }
$enthalten = '';
foreach (x25ed_lines($ed, 'landing', 'preis.enthalten') as $x) { $enthalten .= "\n              <li>{$x}</li>"; }
$nicht = '';
foreach (x25ed_lines($ed, 'landing', 'preis.nicht') as $x) { $nicht .= "\n              <li>{$x}</li>"; }
$hotels = '';
foreach (x25ed_items($ed, 'landing', 'hotel') as $i => $x) {
    $n = $i + 1;
    $hotels .= "\n            <li class=\"x-card x-card--muted x-card--flat\"><span class=\"x-kicker\">{$t('hotels.kicker')} {$n}</span><p>{$x}</p></li>";
}
$faq = '';
foreach (x25ed_tuples($ed, 'landing', 'faq', 'frage', 'antwort') as [$ff, $fa]) {
    $faq .= <<<HTML
<details>
            <summary>{$ff}</summary>
            <div class="x-accordion__body"><p>{$fa}</p></div>
          </details>

HTML;
}
$ta = static fn(string $k): string => x25ed_txt($ed, 'anmeldung', $k);
$paketFakten = '';
foreach (x25ed_lines($ed, 'anmeldung', 'paket.fakten') as $x) { $paketFakten .= "<li>{$x}</li>"; }
$dayHtml1 = x25ed_schedule($ed, $t('ablauf.tag1.titel'), $t('ablauf.tag1.meta'), 'ablauf.tag1');
$dayHtml2 = x25ed_schedule($ed, $t('ablauf.tag2.titel'), $t('ablauf.tag2.meta'), 'ablauf.tag2');
$preisBetrag = x25ed_preis_text($ed);
$heroFoto = x25ed_foto('location-panorama', '', 'eager');
$dokFoto = x25ed_foto('dokument');
$hochFoto = x25ed_foto('location-hoch');
$panoFoto = x25ed_foto('location-panorama');
$story = x25ed_story_steps($ed);
$signatur = x25ed_signature($ed);
$wert = x25ed_wert_section($ed, $anm, $t('wert.lead'));
$kodex = x25ed_kodex_teaser($ed, $t('kodex.link'));
$hinweis = $vorschau ? '<div class="x-notice" role="note" style="margin:0"><p class="x-kicker">Vorschau</p><p>Diese Edition ist noch nicht veröffentlicht (Status: ' . x25ed_e(X25ED_STATUS[$ed['status']] ?? $ed['status']) . '). Diese Ansicht ist nur über den Vorschau-Link erreichbar.</p></div>' : '';

$body = <<<HTML

    {$hinweis}
    <section class="x-hero x-hero--photo x-hero--edition x-hero--kenburns x-dark" aria-labelledby="hero-title">
      <div class="x-hero__media">{$heroFoto}</div>
      <div class="x-hero__scrim x-hero__scrim--top"></div>
      <div class="x-container x-hero__inner">
        <div class="x-hero__copy">
          <p class="x-hero__kicker" data-reveal>{$t('hero.kicker')}</p>
          <h1 class="x-hero__title x-hero__title--edition" id="hero-title" data-reveal style="--x-reveal-delay:80ms">{$nameHtml}</h1>
          <p class="x-hero__meta" data-reveal style="--x-reveal-delay:160ms">{$t('hero.meta')}</p>
          <div class="x-hero__claimpair" data-reveal style="--x-reveal-delay:240ms">
            <p class="x-hero__claim x-hero__claim--lg">{$g('claim.kurz')}<span class="x-dot">.</span></p>
            <p class="x-hero__claim">{$g('claim.satz')}</p>
          </div>
          <p class="x-lead x-hero__lead x-hero__lead--kern" data-reveal style="--x-reveal-delay:300ms">{$kern}</p>
          <div class="x-hero__actions" data-reveal style="--x-reveal-delay:360ms">
            <a class="x-btn x-btn--on-dark x-btn--lg" href="{$anm}">{$g('cta.anmelden')}</a>
            <a class="x-btn x-btn--on-dark-outline x-btn--lg" href="#ablauf">{$t('hero.button2')}</a>
          </div>
        </div>
        <p class="x-hero__side x-hero__note" data-reveal style="--x-reveal-delay:420ms">{$t('hero.note')}</p>
      </div>
      <p class="x-hero__symbol" aria-hidden="true">{$g('symbolbild')}</p>
    </section>

    <section class="x-section" aria-labelledby="fw-h">
      <p class="x-side-label">{$t('fuerwen.label')}</p>
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('fuerwen.label')}</p>
          <h2 id="fw-h" class="x-h2">{$t('fuerwen.titel')}</h2>
        </div>
        <div class="x-forwhom" data-reveal-group>
          <div class="x-forwhom__yes">
            <ul class="x-list x-list--check x-list--loose x-lead">{$fw}
            </ul>
            <p class="x-lead x-mt-8">{$t('fuerwen.schluss')}</p>
          </div>
          <div class="x-forwhom__no x-forwhom__frage">
            <p class="x-kicker">{$t('frage.kicker')}</p>
            <h3 class="x-h3">{$t('frage.titel')}</h3>
            <p>{$t('frage.text')}</p>
            <p class="x-meta x-mt-6">{$t('frage.beispiel-label')}</p>
            <ul class="x-list x-list--loose x-serif x-forwhom__beispiele">{$beispiele}
            </ul>
            <p class="x-meta x-mt-4">{$t('frage.meta')}</p>
            <p class="x-mt-4"><a class="x-link x-link--arrow" href="{$anm}">{$t('frage.link')}</a></p>
          </div>
        </div>
      </div>
    </section>

    {$wert}

    <section class="x-section" aria-labelledby="story-h">
      <p class="x-side-label">{$t('story.label')}</p>
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('story.label')}</p>
          <h2 id="story-h" class="x-h2">{$t('story.titel')}</h2>
          <p class="x-lead">{$t('story.lead')}</p>
        </div>
        {$story}
      </div>
    </section>

    <section class="x-section x-section--muted" aria-labelledby="sig-h">
      <p class="x-side-label">{$t('signatur.label')}</p>
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('signatur.label')}</p>
          <h2 id="sig-h" class="x-h2">{$t('signatur.titel')}</h2>
          <p class="x-lead">{$t('signatur.lead')}</p>
        </div>
        {$signatur}
      </div>
    </section>

    <section class="x-section" id="leitfrage" aria-labelledby="lf-h">
      <p class="x-side-label">{$t('leitfrage.kicker')}</p>
      <div class="x-container x-split">
        <div class="x-split__lead is-sticky" data-reveal>
          <p class="x-kicker">{$t('leitfrage.kicker')}</p>
          <h2 id="lf-h" class="x-h2">{$t('leitfrage.titel')}</h2>
          <p>{$t('leitfrage.text')}</p>
        </div>
        <div class="x-split__body" data-reveal>
          <p class="x-serif x-serif--lg">{$t('leitfrage.serif')}</p>
          <ol class="x-leitfrage__list">{$leitfragen}
          </ol>
          <p class="x-meta x-mt-8">{$t('leitfrage.meta')}</p>
        </div>
      </div>
    </section>

    <section class="x-section x-section--muted" id="ablauf" aria-labelledby="ablauf-h">
      <p class="x-side-label">{$t('ablauf.label')}</p>
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('ablauf.kicker')}</p>
          <h2 id="ablauf-h" class="x-h2">{$t('ablauf.titel')}</h2>
          <p class="x-lead">{$t('ablauf.lead')}</p>
        </div>
        <div class="x-schedule">
          {$dayHtml1}
          {$dayHtml2}
        </div>
      </div>
    </section>

    <section class="x-section" id="impulse" aria-labelledby="imp-h">
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('impulse.kicker')}</p>
          <h2 id="imp-h" class="x-h2">{$t('impulse.titel')}</h2>
          <p class="x-lead">{$t('impulse.lead')}</p>
        </div>
        <ol class="x-impulse" data-reveal-group>
          {$impulse}
        </ol>
      </div>
    </section>

    <section class="x-section x-section--muted" id="dissenspapier" aria-labelledby="dp-h">
      <div class="x-container x-grid x-grid--center">
        <figure class="x-figure x-figure--4x5 x-col-5" data-reveal>{$dokFoto}<figcaption>{$t('dp.bildunterschrift')}</figcaption></figure>
        <div class="x-col-6 x-offset-1" data-reveal>
          <p class="x-kicker">{$t('dp.kicker')}</p>
          <h2 id="dp-h" class="x-h2">{$t('dp.titel')}</h2>
          <p class="x-lead">{$t('dp.lead')}</p>
          <ul class="x-list x-list--loose">{$dp}
          </ul>
        </div>
      </div>
    </section>

    <section class="x-section" id="preis" aria-labelledby="preis-h">
      <div class="x-container x-price">
        <div class="x-price__main" data-reveal>
          <p class="x-kicker">{$t('preis.kicker')}</p>
          <h2 id="preis-h" class="x-visually-hidden">{$t('preis.titel')}</h2>
          <p class="x-price__amount">{$preisBetrag}<small>{$t('preis.einheit')}</small></p>
          <p><a class="x-btn x-btn--primary x-btn--lg" href="{$anm}">{$g('cta.anmelden')}</a></p>
          <p class="x-meta x-mt-4">{$t('preis.meta')}</p>
        </div>
        <div class="x-price__lists" data-reveal-group>
          <div>
            <h3 class="x-h5">{$t('preis.enthalten.titel')}</h3>
            <ul class="x-list x-list--check">{$enthalten}
            </ul>
          </div>
          <div>
            <h3 class="x-h5">{$t('preis.nicht.titel')}</h3>
            <ul class="x-list x-list--no">{$nicht}
            </ul>
            <p class="x-meta x-mt-4">{$t('preis.storno')}</p>
          </div>
        </div>
      </div>
    </section>

    <section class="x-section x-section--wood" id="anmeldung" aria-labelledby="anm-h">
      <p class="x-side-label">{$t('anmeldung.kicker')}</p>
      <div class="x-container x-anmeldung">
        <div class="x-anmeldung__intro" data-reveal>
          <p class="x-kicker">{$t('anmeldung.kicker')}</p>
          <h2 id="anm-h" class="x-h2">{$t('anmeldung.titel')}</h2>
          <p class="x-lead">{$t('anmeldung.lead')}</p>
          <p>{$t('anmeldung.absatz1')}</p>
          <p>{$t('anmeldung.absatz2')}</p>
          <p>{$t('anmeldung.absatz3')}</p>
        </div>
        <div class="x-anmeldung__form" data-reveal>
          <div class="x-card x-card--lg x-anmeldung__cta">
            <p class="x-kicker">{$ta('paket.kicker')}</p>
            <h3 class="x-h3">{$ta('paket.titel')}</h3>
            <p class="x-price__amount">{$preisBetrag}<small>{$ta('paket.preis.zusatz')}</small></p>
            <ul class="x-facts">{$paketFakten}</ul>
            <div class="x-actions x-mt-8"><a class="x-btn x-btn--primary x-btn--lg" href="{$anm}">{$t('anmeldung.button')}</a></div>
            <p class="x-meta x-mt-4">{$ta('bestaetigung.hinweis')}</p>
          </div>
        </div>
      </div>
    </section>

    <section class="x-section x-section--ink x-dark" id="kodex" aria-labelledby="kodex-h">
      {$kodex}
    </section>

    <section class="x-section" id="anreise" aria-labelledby="ort-h">
      <p class="x-side-label">{$t('anreise.label')}</p>
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('anreise.kicker')}</p>
          <h2 id="ort-h" class="x-h2">{$t('anreise.titel')}</h2>
        </div>
        <div class="x-location" data-reveal-group>
          <div class="x-location__hoch">{$hochFoto}<p class="x-symbol x-mt-2">{$t('anreise.bild.hoch')}</p></div>
          <div class="x-location__cards">
            <div class="x-card x-card--lg">
              <p class="x-kicker">{$t('warumkoeln.kicker')}</p>
              <p>{$t('warumkoeln.absatz1')}</p>
              <p>{$t('warumkoeln.absatz2')}</p>
            </div>
            <div class="x-card">
              <p class="x-kicker">{$t('tagungsort.kicker')}</p>
              <h3 class="x-h4">{$g('location.name')}</h3>
              <address>{$g('location.adresse')}</address>
              <p>{$t('tagungsort.text')}</p>
            </div>
            <div class="x-card">
              <p class="x-kicker">{$t('abend.kicker')}</p>
              <p>{$t('abend.tbd')}</p>
              <p>{$t('abend.text')}</p>
            </div>
            <div class="x-card">
              <p class="x-kicker">{$t('anfahrt.kicker')}</p>
              <p>{$t('anfahrt.text1')}</p>
              <p>{$t('anfahrt.text2')}</p>
            </div>
          </div>
          <div class="x-location__pano">{$panoFoto}</div>
        </div>
        <div class="x-mt-16" data-reveal>
          <h3 class="x-h3">{$t('hotels.titel')}</h3>
          <p class="x-maxw">{$t('hotels.text')}</p>
          <ul class="x-hotels x-mt-6">{$hotels}
          </ul>
        </div>
      </div>
    </section>

    <section class="x-section x-section--muted" id="faq" aria-labelledby="faq-h">
      <div class="x-container x-split">
        <div class="x-split__lead is-sticky" data-reveal>
          <p class="x-kicker">{$t('faq.kicker')}</p>
          <h2 id="faq-h" class="x-h2">{$t('faq.titel')}</h2>
          <p>{$t('faq.kontakt')}</p>
          <p><a class="x-btn x-btn--primary" href="{$anm}">{$g('cta.anmelden')}</a></p>
        </div>
        <div class="x-split__body x-accordion" data-reveal>
          {$faq}
        </div>
      </div>
    </section>

    <section class="x-section x-section--ink x-dark x-cta" aria-labelledby="cta2-h">
      <div class="x-container x-cta__inner">
        <div data-reveal>
          <p class="x-kicker">{$t('cta.kicker')}</p>
          <h2 id="cta2-h" class="x-h2">{$t('cta.titel')}</h2>
          <p class="x-lead">{$t('cta.lead')}</p>
        </div>
        <div class="x-actions" data-reveal>
          <a class="x-btn x-btn--on-dark x-btn--lg" href="{$anm}">{$g('cta.anmelden')}</a>
          <a class="x-btn x-btn--on-dark-outline x-btn--lg" href="/neutralitaetskodex">{$t('cta.button2')}</a>
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
    'extra_head' => $ld,
    'cta_href' => $anm,
    'overlay' => true,
    'noindex' => $vorschau,
    'og_image' => rtrim($canon, '/') . '/og.jpg',
    'og_image_alt' => x25ed_label($ed) . ' · 25-experts.de',
]), 200, $vorschau ? 0 : 600);
