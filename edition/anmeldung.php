<?php
/**
 * 25 EXPERTS – Anmeldeseite einer Edition (Wizard, dynamisch): /editionen/{slug}/anmeldung
 * PHP-Port von 05-landingpage/build_landing.anmeldung_seite(); sendet an /anmeldung/send.php
 * (JSON per assets/js/anmeldung.js, ohne JavaScript als klassisches POST).
 * Ist die Anmeldung geschlossen (anmeldung_offen=false), erscheint statt des Formulars ein Hinweis.
 */
declare(strict_types=1);

require_once __DIR__ . '/shell.php';

$slug = (string)($_GET['slug'] ?? '');
$ed = x25ed_get($slug);
if ($ed === null) { x25ed_404('Diese Edition gibt es nicht.'); }
if (!x25ed_can_view($ed) || ($ed['status'] ?? '') === 'angekuendigt') {
    header('Location: /editionen', true, 302);
    exit;
}
$vorschau = ($ed['status'] ?? '') !== 'online';
$offen = !empty($ed['anmeldung_offen']);

$t = static fn(string $k): string => x25ed_txt($ed, 'anmeldung', $k);
$g = static fn(string $k): string => x25ed_g($k, $ed);
$e = static fn(?string $s): string => x25ed_e($s);
$canon = rtrim(x25ed_abs_url($ed), '/') . '/anmeldung';
$landing = x25ed_url($ed);
$label = x25ed_label($ed);
$endpoint = '/anmeldung/send.php';
require_once dirname(__DIR__) . '/anmeldung/lib/curation.php';
$invitation=(string)($_GET['einladung']??'');
$invite=$invitation!==''?x25_invite_read($invitation,(string)$ed['slug']):null;
$invEmail=$invite!==null?(string)$invite['email']:'';
$invNotice='';
if($invitation!=='') {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    $invNotice=$invite!==null
        ? '<div class="x-invitation"><p class="x-kicker">Deine persönliche Einladung</p><p>Deine fachliche Passung ist bereits geprüft. Vervollständige Deine Angaben; anschließend kannst Du verbindlich buchen, sofern ein Platz frei ist.</p></div>'
        : '<div class="x-invitation" role="alert"><p>Dieser Einladungslink ist nicht mehr gültig. Bitte kontaktiere das Organisationsteam oder <a href="'.$e($landing.'anmeldung').'">stelle eine neue Teilnahme-Anfrage</a>.</p></div>';
}

$preisBetrag = x25ed_preis_text($ed);

$requestTitle = $invite !== null ? 'Deine Einladung annehmen' : 'Jetzt anmelden';
$requestInfo = $invite !== null ? 'Deine Einladung liegt vor. Nach Deinen Angaben kannst Du verbindlich buchen, sofern ein Platz frei ist.' : 'In drei Schritten zu Deiner Anfrage. Wir melden uns innerhalb von zwei Werktagen. Noch keine Buchung, keine Zahlungspflicht.';
$requestButton = $invite !== null ? 'Weiter zur verbindlichen Buchung' : 'Jetzt anmelden';
$requestConsent = $invite !== null ? 'Ich möchte meine Einladung nutzen und anschließend die Buchungsangaben prüfen. Mit diesem Schritt buche ich noch nicht verbindlich.' : 'Ich möchte mich für dieses Event anmelden. Eine verbindliche Buchung erfolgt erst in einem eigenen Schritt nach der Zusage.';
$gross = number_format(x25ed_preis($ed) * 1.19, 2, ',', '.') . ' €';
$eventDate = $e((string)$ed['datum_text']);
$example = [
 'change-management'=>'Wir führen einen KI-Assistenten ein. Wie nehme ich skeptische Kolleginnen und Kollegen mit und erkenne, ob unsere Begleitung wirkt?',
 'security'=>'Wie können wir KI-Werkzeuge freigeben, ohne vertrauliche Daten ungeschützt weiterzugeben?',
 'vertrieb'=>'Wie nutzen wir KI in der Beratung, ohne das persönliche Vertrauen unserer Kunden zu verlieren?',
 'operations'=>'Fachbereich und IT priorisieren unterschiedlich. Wie kommen wir zu gemeinsamen Entscheidungen über Automatisierung?',
 'female'=>'Ich übernehme fachliche Verantwortung für KI-Projekte. Wie mache ich meinen Beitrag sichtbar und gewinne Unterstützung?',
 'data'=>'Unsere Datenqualität schwankt. Welche Verbesserungen bringen für unsere KI-Anwendung zuerst einen messbaren Nutzen?',
 'sustainability'=>'Wie vergleichen wir die Wirkung verschiedener Maßnahmen, wenn die Daten noch unvollständig sind?'
][$slug] ?? 'Welche Entscheidung beschäftigt Dich gerade, und welche Erfahrungen anderer Häuser würden Dir helfen?';
$kopf = '<section class="x-request-head"><div class="x-container"><p class="x-kicker">'.$e($ed['name']).' · '.$eventDate.'</p><h1 class="x-h2">'.$requestTitle.'</h1><p>'.$requestInfo.'</p><a class="x-link" href="#leistungen">Preis und Leistungen ansehen</a></div></section>';

$ebeneOptionen = '';
$ebeneWerte = ['', 'teamleitung', 'abteilungsleitung', 'bereichsleitung', 'vorstand', 'vorstandsstab', 'sonstiges'];
foreach (x25ed_lines($ed, 'anmeldung', 'feld.ebene.optionen') as $i => $l) {
    $v = $ebeneWerte[$i] ?? '';
    $ebeneOptionen .= '<option value="' . $e($v) . '">' . $l . "</option>\n                  ";
}
$stepNames = ['Deine Kontaktdaten', 'Dein Anliegen', 'Prüfen und senden'];
$tabs = '';
for ($i = 1; $i <= 3; $i++) {
    $act = $i === 1 ? ' is-active' : '';
    $tabs .= "\n          <li class=\"x-wizard__tab{$act}\" data-step-tab=\"{$i}\"><button type=\"button\"><span class=\"x-wizard__num\">{$i}</span><span>" . $stepNames[$i - 1] . '</span></button></li>';
}
$paket = '';
foreach (x25ed_lines($ed, 'anmeldung', 'paket.enthalten') as $x) { $paket .= "\n            <li>{$x}</li>"; }
$fakten = '';
foreach (x25ed_lines($ed, 'anmeldung', 'paket.fakten') as $x) { $fakten .= "<li>{$x}</li>"; }

if (!$offen) {
    $formTeil = <<<HTML

        <div class="x-card x-card--lg" data-reveal>
          <p class="x-kicker">Anmeldung</p>
          <h2 class="x-h3">Die Anmeldung ist derzeit geschlossen.</h2>
          <p>Für {$e($ed['name'])} nehmen wir gerade keine Anmeldungen entgegen. Schreib uns gern an <a href="mailto:{$g('kontakt.mail')}">{$g('kontakt.mail')}</a>; wir melden uns, sobald die Anmeldung wieder geöffnet ist.</p>
          <p class="x-mt-6"><a class="x-link x-link--arrow" href="{$landing}">Zurück zur Edition</a></p>
        </div>
HTML;
} else {
    $formTeil = <<<HTML

        <div class="x-card x-card--lg x-wizard" id="anfrage-formular">
          <form class="x-form" method="post" action="{$endpoint}" data-wizard data-endpoint="{$endpoint}" data-thanks="{$landing}danke" data-edition="{$e($label)}" data-msg-fehler="{$t('nav.fehler')}" novalidate>
            <input type="hidden" name="edition_slug" value="{$e($ed['slug'])}">
            <input type="hidden" name="invitation" value="{$e($invitation)}">
            {$invNotice}
            <p class="x-wizard__progress x-wizard__jsonly" aria-live="polite" aria-atomic="true">Schritt 1 von 3 · Deine Kontaktdaten</p>
            <ol class="x-wizard__tabs x-wizard__jsonly">{$tabs}
            </ol>

            <div class="x-wizard__step is-active" data-step="1">
              <p class="x-kicker">Deine Kontaktdaten</p>
              <div class="x-form__grid">
                <div class="x-field">
                  <label for="a-vorname">{$t('feld.vorname')} <span class="x-req" aria-hidden="true">*</span></label>
                  <input type="text" id="a-vorname" name="vorname" autocomplete="given-name" required aria-required="true">
                  <span class="x-error" role="alert">{$t('feld.vorname.error')}</span>
                </div>
                <div class="x-field">
                  <label for="a-nachname">{$t('feld.nachname')} <span class="x-req" aria-hidden="true">*</span></label>
                  <input type="text" id="a-nachname" name="nachname" autocomplete="family-name" required aria-required="true">
                  <span class="x-error" role="alert">{$t('feld.nachname.error')}</span>
                </div>
                <div class="x-field">
                  <label for="a-company">{$t('feld.unternehmen')} <span class="x-req" aria-hidden="true">*</span></label>
                  <input type="text" id="a-company" name="company" autocomplete="organization" required aria-required="true">
                  <span class="x-error" role="alert">{$t('feld.unternehmen.error')}</span>
                </div>
                <div class="x-field">
                  <label for="a-role">{$t('feld.rolle')} <span class="x-req" aria-hidden="true">*</span></label>
                  <input type="text" id="a-role" name="role" autocomplete="organization-title" required aria-required="true" aria-describedby="a-role-hint">
                  <span class="x-hint" id="a-role-hint">Fachliche Verantwortung zählt – auch ohne Führungsposition.</span>
                  <span class="x-error" role="alert">{$t('feld.rolle.error')}</span>
                </div>
                <div class="x-field">
                  <label for="a-email">{$t('feld.email')} <span class="x-req" aria-hidden="true">*</span></label>
                  <input type="email" id="a-email" name="email" value="{$e($invEmail)}" autocomplete="email" required aria-required="true" inputmode="email" aria-describedby="a-email-hint">
                  <span class="x-hint" id="a-email-hint">{$t('feld.email.hint')}</span>
                  <span class="x-error" role="alert">{$t('feld.email.error')}</span>
                </div>
                <div class="x-field">
                  <label for="a-phone">{$t('feld.telefon')}</label>
                  <input type="tel" id="a-phone" name="phone" autocomplete="tel" inputmode="tel" aria-describedby="a-phone-hint">
                  <span class="x-hint" id="a-phone-hint">{$t('feld.telefon.hint')}</span>
                </div>
              </div>
            </div>

            <div class="x-wizard__step" data-step="2">
              <p class="x-kicker">Deine Verantwortung und Frage</p>
              <div class="x-form__grid">
                <fieldset class="x-field x-field--full x-fieldset">
                  <legend>{$t('feld.typ.legend')} <span class="x-req" aria-hidden="true">*</span></legend>
                  <div class="x-choices x-choices--row">
                    <label class="x-choice" for="a-cat-v"><input type="radio" id="a-cat-v" name="category" value="versicherer" required aria-required="true"><span><span class="x-choice__label">{$t('feld.typ.versicherer.label')}</span><span class="x-choice__hint">{$t('feld.typ.versicherer.hint')}</span></span></label>
                    <label class="x-choice" for="a-cat-m"><input type="radio" id="a-cat-m" name="category" value="maklerpool"><span><span class="x-choice__label">{$t('feld.typ.maklerpool.label')}</span><span class="x-choice__hint">{$t('feld.typ.maklerpool.hint')}</span></span></label>
                    <label class="x-choice" for="a-cat-t"><input type="radio" id="a-cat-t" name="category" value="vertrieb"><span><span class="x-choice__label">{$t('feld.typ.vertrieb.label')}</span><span class="x-choice__hint">{$t('feld.typ.vertrieb.hint')}</span></span></label>
                    <label class="x-choice" for="a-cat-s"><input type="radio" id="a-cat-s" name="category" value="sonstiges"><span><span class="x-choice__label">{$t('feld.typ.sonstiges.label')}</span><span class="x-choice__hint">{$t('feld.typ.sonstiges.hint')}</span></span></label>
                  </div>
                  <span class="x-hint">{$t('feld.typ.hint')}</span>
                  <span class="x-error" role="alert">{$t('feld.typ.error')}</span>
                </fieldset>
                <div class="x-field">
                  <label for="a-linkedin">{$t('feld.linkedin')}</label>
                  <input type="url" id="a-linkedin" name="linkedin" inputmode="url" placeholder="https://www.linkedin.com/in/…" aria-describedby="a-linkedin-hint">
                  <span class="x-hint" id="a-linkedin-hint">{$t('feld.linkedin.hint')}</span>
                  <span class="x-error" role="alert">{$t('feld.linkedin.error')}</span>
                </div>
                <div class="x-field x-field--full x-field--frage">
                  <label for="a-question">{$t('feld.frage')} <span class="x-req" aria-hidden="true">*</span></label>
                  <textarea id="a-question" name="question" rows="3" maxlength="5000" required aria-required="true" aria-describedby="a-question-hint"></textarea>
                  <span class="x-hint" id="a-question-hint">Ein bis drei Sätze reichen. Beispiel: {$e($example)}<br>Bitte keine vertraulichen Unternehmens- oder Kundendaten eingeben.</span>
                  <span class="x-error" role="alert">{$t('feld.frage.error')}</span>
                </div>
              </div>
            </div>

            <div class="x-wizard__step" data-step="3">
              <p class="x-kicker">Prüfen und senden</p>
              <div class="x-review-event"><strong>{$e($ed['name'])}</strong><p>{$eventDate} · {$e((string)$ed['venue'])}</p><p>Bei späterer Buchung: {$preisBetrag} netto · {$gross} inkl. 19 % USt.</p><p>Die Anfrage selbst kostet nichts.</p></div>
              <div class="x-review x-wizard__jsonly" data-review></div>
              <div class="x-form__grid">
                <div class="x-field x-field--full">
                  <label class="x-choice x-choice--bare" for="a-binding"><input type="checkbox" id="a-binding" name="binding" value="ja" required aria-required="true"> <span>{$requestConsent} <span class="x-req" aria-hidden="true">*</span></span></label>
                  <span class="x-error" role="alert">{$t('bestaetigung.anmeldung.error')}</span>
                </div>
                <div class="x-field x-field--full">
                  <label class="x-choice x-choice--bare" for="a-privacy"><input type="checkbox" id="a-privacy" name="privacy" value="ja" required aria-required="true"> <span>{$t('bestaetigung.datenschutz')} <span class="x-req" aria-hidden="true">*</span></span></label>
                  <span class="x-error" role="alert">{$t('bestaetigung.datenschutz.error')}</span>
                </div>
                <p class="x-meta">Erst mit Deiner verbindlichen Buchung entsteht eine Zahlungspflicht.</p>
              </div>
            </div>

            <div class="x-hp" aria-hidden="true">
              <label for="a-website">{$t('honeypot.label')}</label>
              <input type="text" id="a-website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <p class="x-form__status" tabindex="-1" hidden></p>
            <div class="x-form__foot x-wizard__nav">
              <button type="button" class="x-wizard__back x-wizard__jsonly" hidden>&larr; {$t('nav.zurueck')}</button>
              <span class="x-wizard__spacer"></span>
              <button type="button" class="x-btn x-btn--primary x-btn--lg x-wizard__next x-wizard__jsonly">{$t('nav.weiter')}</button>
              <button type="submit" class="x-btn x-btn--primary x-btn--lg x-wizard__submit">{$requestButton}</button>
            </div>
            <p class="x-meta x-mt-6">{$t('nav.pflicht')}</p>
          </form>
        </div>
HTML;
}

$hinweis = $vorschau ? '<div class="x-notice" role="note" style="margin:0"><p class="x-kicker">Vorschau</p><p>Diese Edition ist noch nicht veröffentlicht.</p></div>' : '';
$anmJs = x25ed_asset('js/anmeldung.js');

$body = <<<HTML

    {$hinweis}{$kopf}
    <section class="x-section x-section--flush-top" aria-label="{$e(strip_tags($t('kopf.titel')))}">
      <div class="x-container x-anmeldeseite">
        {$formTeil}

        <aside class="x-card x-card--ink x-paket" id="leistungen">
          <p class="x-kicker">{$t('paket.kicker')}</p>
          <h2 class="x-h3">{$t('paket.titel')}</h2>
          <p class="x-paket__preis">{$preisBetrag}<small>netto bei verbindlicher Buchung<br>{$gross} inkl. 19 % USt.</small></p>
          <ul class="x-facts">{$fakten}</ul>
          <p class="x-paket__sub">{$t('paket.enthalten.titel')}</p>
          <ul class="x-list x-list--check x-list--loose">{$paket}
          </ul>
          <p class="x-meta">Anreise und Übernachtung sind nicht enthalten.</p>
        </aside>
      </div>
    </section>
HTML;

x25ed_out(x25ed_shell([
    'ed' => $ed,
    'title' => $t('meta.titel'),
    'description' => strip_tags($t('meta.beschreibung')),
    'body' => $body,
    'canonical' => $canon,
    'cta_href' => '#anfrage-formular',
    'cta_label' => $invite !== null ? 'Zur Einladung' : 'Jetzt anmelden',
    'noindex' => $vorschau || $invitation !== '',
    'og_image' => rtrim(x25ed_abs_url($ed), '/') . '/og.jpg',
    'og_image_alt' => 'Anmeldung: ' . x25ed_label($ed),
    'extra_head' => '  <script src="' . $anmJs . '" defer></script>' . "\n",
 ]), 200, ($vorschau || $invitation !== '') ? 0 : 600);

