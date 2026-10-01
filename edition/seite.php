<?php
/**
 * 25 EXPERTS – Landingpage einer Edition (dynamisch): /editionen/{slug}/  →  edition/seite.php?slug=…
 * PHP-Port von 05-landingpage/build_landing.landing(); Inhalte aus der Editions-Datei (verwaltung/)
 * mit Fallback auf die Standardtexte (edition/texte.json).
 * Entwürfe sind nur mit Vorschau-Link sichtbar; archivierte Editionen leiten auf die Übersicht um.
 */
declare(strict_types=1);

require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/identity.php';

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
$kern = trim($t('kern') . ' ' . (x25ed_vars($ed)['am_tisch'] ?? ''));
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
        'organizer' => ['@type' => 'Organization', 'name' => '25 EXPERTS UG (haftungsbeschränkt)', 'url' => $domain],
        'offers' => [
            '@type' => 'Offer', 'name' => strip_tags($t('eventld.angebot.name')),
            'price' => (string)x25ed_preis($ed), 'priceCurrency' => 'EUR',
            'description' => strip_tags($t('eventld.angebot.beschreibung')),
            'availability' => 'https://schema.org/LimitedAvailability',
            'url' => rtrim($canon, '/') . '/anmeldung',
        ],
        'url' => $canon,
    ];
    if (empty($ed['anmeldung_offen'])) { unset($event['offers']); }
    if (!empty($ed['anmeldung_offen']) && ($ed['anmeldung_ab'] ?? '') !== '') { $event['offers']['validFrom'] = (string)$ed['anmeldung_ab']; }
    // Vorläufige Termine nicht als bestätigte Events an Suchmaschinen melden.
    if (empty($ed['termin_vorlaeufig'])) {
        $ld .= '  <script type="application/ld+json">' . "\n  " . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n  </script>\n";
    }
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
            if (str_starts_with((string)$m, 'foto:')) { $chip = ''; }
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
$kodex = x25ed_kodex_teaser($ed, $t('kodex.link'));
$hinweis = $vorschau ? '<div class="x-notice" role="note" style="margin:0"><p class="x-kicker">Vorschau</p><p>Diese Edition ist noch nicht veröffentlicht (Status: ' . x25ed_e(X25ED_STATUS[$ed['status']] ?? $ed['status']) . '). Diese Ansicht ist nur über den Vorschau-Link erreichbar.</p></div>' : '';

$benefits = [
 'change-management'=>'Veränderung gestalten, Menschen mitnehmen und aus den Erfahrungen anderer lernen.',
 'security'=>'Sicherheit wirksam verankern – zwischen Verantwortung, Fachbereich und Technologie.',
 'vertrieb'=>'Vertrieb weiterentwickeln: Kundennähe, Zusammenarbeit und Wirkung gemeinsam durchdenken.',
 'female'=>'Erfahrungen teilen, Sichtbarkeit stärken und den eigenen Weg in der Versicherungsbranche gestalten.',
 'operations'=>'Fachbereich und IT verbinden: Übergaben klären, Prozesse verbessern und gemeinsam ins Handeln kommen.',
 'data'=>'Aus Daten tragfähige Entscheidungen machen – mit klarer Verantwortung und praktischer Wirkung.',
 'sustainability'=>'Nachhaltigkeit in wirksame Entscheidungen und gelebte Praxis übersetzen.',
];
$benefit = $e($benefits[$slug] ?? strip_tags($t('kern')));
$date = $e((string)$ed['datum_text']);
$provisional = !empty($ed['termin_vorlaeufig']) ? ' · Termin vorläufig' : '';
$gross = number_format(x25ed_preis($ed)*1.19, 2, ',', '.');
$identity = x25ed_identity($slug);
$statement = $e($identity[1]);
$motif = x25ed_motif();
$themeClass = 'nx-event nx-theme-' . preg_replace('/[^a-z-]/', '', $slug);
$body = <<<HTML
    {$hinweis}
    <section class="nx-event-hero" aria-labelledby="hero-title"><div class="x-container">
      <div class="nx-event-hero__top"><h1 id="hero-title">{$nameHtml}</h1><p>{$date}{$provisional}<br>Köln · Rheinauhafen</p></div>
      <div class="nx-event-hero__grid"><div><p class="nx-event-hero__statement">{$statement}</p><p class="x-lead">{$benefit}</p></div>{$motif}</div>
      <div class="nx-event-hero__bottom"><p class="nx-event-hero__facts">SESSEL HUB · Kranhaus Nord<br>25 Teilnehmer · 1½ Tage mit Dinner<br>{$preisBetrag} netto · {$gross} € inkl. 19 % USt.</p><div><a class="nx-button" href="{$anm}">Jetzt anmelden <span aria-hidden="true">↗</span></a><p class="x-meta">Rückmeldung in zwei Werktagen. Noch keine Buchung.</p></div></div>
    </div></section>
    <nav class="ux-section-nav x-container" aria-label="Auf dieser Editionsseite"><a href="#leitfrage">Dein Thema</a><a href="#ablauf">Ablauf</a><a href="#impulse">Mitwirkende</a><a href="#preis">Leistungen &amp; Preis</a><a href="#anreise">Ort &amp; Anreise</a><a href="#faq">Fragen</a></nav>

    <section class="x-promise" aria-label="Persönliche Betreuung">
      <div class="x-container x-promise__grid">
        <p><span>Das Event</span><strong>25 Teilnehmer</strong>Erfahrungen, die sich ergänzen. Höchstens zwei pro Unternehmen.</p>
        <p><span>Vor dem Treffen</span><strong>Deine Fragen vorbereitet</strong>Dossier mit Spannungsfeldern und Praxisfällen.</p>
        <p><span>In Köln</span><strong>Zeit für Begegnungen</strong>Begleitete Gespräche, Aperitif und Dinner.</p>
        
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
        <ol class="ux-stages"><li><strong>Ankommen und kennenlernen</strong></li><li><strong>Praxis und Austausch</strong></li><li><strong>Gemeinsamer Abend</strong></li><li><strong>Nächste Schritte</strong></li></ol>
        <details class="ux-agenda"><summary>Den detaillierten Zeitplan öffnen</summary><div class="x-schedule">
          {$dayHtml1}
          {$dayHtml2}
        </div></details>
      </div>
    </section>

    <section class="x-section" id="impulse" aria-labelledby="imp-h">
      <div class="x-container">
        <div class="x-section__head" data-reveal>
          <p class="x-kicker">{$t('impulse.kicker')}</p>
          <h2 id="imp-h" class="x-h2">{$t('impulse.titel')}</h2>
          <p class="x-lead">{$t('impulse.lead')}</p>
        </div>
        <p class="ux-note">Die folgenden Impulse beschreiben die geplanten Beiträge. Bestätigte externe Mitwirkende werden hier mit Name, Rolle und Beitrag ergänzt, sobald ihre Zusage vorliegt.</p><ol class="x-impulse" data-reveal-group>
          {$impulse}
        </ol>
      </div>
    </section>

    <section class="x-section x-section--muted" id="dissenspapier" aria-labelledby="dp-h"><div class="x-container"><h2 class="x-h2" id="dp-h">Was Du mitnimmst.</h2><div class="ux-stages ux-stages--three"><div><h3>Der 26. Experte</h3><p>KI liefert eine zusätzliche Gegenperspektive auf Deine eigene Entscheidung. Erst formuliert Ihr Euer Urteil, dann prüfen wir gemeinsam die Gegenargumente.</p></div><div><h3>Das Kuvert</h3><p>Du hältst Deine Einschätzung fest. Bei der nächsten Edition zum selben Thema kannst Du prüfen, was sich verändert hat.</p></div><div><h3>Das Dissenspapier</h3><p>Gemeinsame Erkenntnisse, begründete Unterschiede und nächste Schritte – als Arbeitsgrundlage für Deinen Alltag. Öffentlich wird nur geteilt, was freigegeben ist.</p></div></div></div></section>

    <section class="x-section" id="preis" aria-labelledby="preis-h">
      <div class="x-container x-price">
        <div class="x-price__main" data-reveal>
          <p class="x-kicker">{$t('preis.kicker')}</p>
          <h2 id="preis-h" class="x-visually-hidden">{$t('preis.titel')}</h2>
          <p class="x-price__amount">{$preisBetrag}<small>netto · {$gross} € inkl. 19 % USt.</small></p>
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

    <section class="x-section x-section--wood" id="anmeldung" aria-labelledby="anm-h"><div class="x-container"><h2 class="x-h2" id="anm-h">Bring Deine Perspektive mit.</h2><p>Du verantwortest dieses Thema fachlich oder führst ein Team? Wir möchten erfahren, welche Frage Dich gerade beschäftigt. Ein Führungstitel ist keine Voraussetzung.</p><ol class="ux-stages ux-stages--three"><li><strong>Jetzt anmelden</strong><p>Kontaktdaten und ein bis drei Sätze zu Deinem Anliegen reichen.</p></li><li><strong>Persönliche Rückmeldung</strong><p>Innerhalb von zwei Werktagen. Wir achten auf Erfahrungen und Fragen, die sich ergänzen.</p></li><li><strong>Nach Zusage selbst entscheiden</strong><p>Erst Deine ausdrückliche verbindliche Buchung begründet die Zahlungspflicht.</p></li></ol><a class="x-btn x-btn--primary" href="{$anm}">Jetzt anmelden</a></div></section>

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
        <div class="ux-venues"><div class="x-card"><p class="x-kicker">Unser Tagungsort</p><h3>SESSEL HUB · Kranhaus Nord</h3><address>Im Zollhafen 12<br>50678 Köln</address><p>Hier nehmen wir uns Zeit für Deine Fragen und den gemeinsamen Austausch.</p><a href="https://www.sesselkampagne.koeln/meetings" target="_blank" rel="noopener">Die Location kennenlernen (externe Website)</a></div><div class="x-card"><p class="x-kicker">Unser gemeinsamer Abend</p><h3>Gilden im Zims</h3><address>Heumarkt 77<br>50667 Köln</address><p>Beim gemeinsamen Dinner setzen wir die Gespräche in entspannter Atmosphäre fort.</p><a href="https://www.zims.de/" target="_blank" rel="noopener">Die Abendlocation kennenlernen (externe Website)</a></div></div><p class="x-meta">Anreise und Übernachtung sind nicht enthalten. Plane Deine Anreise passend zum Beginn der Agenda; die Details erhältst Du mit Deiner Teilnahmebestätigung.</p>
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
HTML;

x25ed_out(x25ed_shell([
    'ed' => $ed,
    'body_class' => $themeClass,
    'title' => $t('meta.titel'),
    'description' => strip_tags($t('meta.beschreibung')),
    'body' => $body,
    'canonical' => $canon,
    'extra_head' => $ld,
    'cta_href' => $anm,
    'overlay' => false,
    'noindex' => $vorschau,
    'og_image' => rtrim($canon, '/') . '/og.jpg',
    'og_image_alt' => x25ed_label($ed) . ' · 25-experts.de',
]), 200, $vorschau ? 0 : 600);
