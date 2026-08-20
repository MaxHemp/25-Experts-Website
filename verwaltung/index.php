<?php
/**
 * 25 EXPERTS – Verwaltung: Übersicht der Event-Editionen.
 * Neue Edition anlegen (auf Basis einer bestehenden), duplizieren, Status schalten
 * (Entwurf → Online usw.), Anmeldung öffnen/schließen, löschen (nur ohne Anmeldungen).
 * Bearbeiten der Inhalte: bearbeiten.php?slug=…
 */
declare(strict_types=1);

require __DIR__ . '/auth.php';

$e = static fn(?string $s): string => x25ed_e($s);
$csrf = xv_csrf();

// ------------------------------------------------------------------ Aktionen (POST + CSRF)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!xv_csrf_ok((string)($_POST['csrf'] ?? ''))) {
        xv_page('Abgelehnt', '<div class="v-card v-card--warn"><h1>Sitzung abgelaufen.</h1><p>Bitte zurück und die Aktion erneut ausführen.</p><p><a class="v-btn" href="index.php">Zur Übersicht</a></p></div>', 403);
    }
    $do = (string)($_POST['do'] ?? '');
    $slug = (string)($_POST['slug'] ?? '');
    $msg = '';
    try {
        if ($do === 'neu' || $do === 'duplizieren') {
            $name = trim((string)($_POST['name'] ?? ''));
            $thema = trim((string)($_POST['thema'] ?? ''));
            if ($do === 'neu' && $thema === '') { throw new RuntimeException('Bitte das Thema der Edition angeben (z. B. „Underwriting“).'); }
            $vorlageSlug = (string)($_POST['vorlage'] ?? $slug);
            $vorlage = x25ed_get($vorlageSlug);
            if ($vorlage === null) { throw new RuntimeException('Vorlage nicht gefunden.'); }
            if ($do === 'duplizieren') {
                $thema = $thema !== '' ? $thema : (string)($vorlage['thema'] ?? '');
                $name = $name !== '' ? $name : ('Kopie von ' . $vorlage['name']);
            } else {
                $name = $name !== '' ? $name : ('25 ' . mb_strtoupper($thema) . ' EXPERTS');
            }
            $neuSlug = x25ed_slugify((string)($_POST['neuer_slug'] ?? '') !== '' ? (string)$_POST['neuer_slug'] : ($thema !== '' ? $thema : $name));
            if (!x25ed_slug_ok($neuSlug)) { throw new RuntimeException('Aus dem Namen ließ sich keine gültige Adresse ableiten; bitte im Feld „Adresse“ selbst eine angeben (Kleinbuchstaben und Bindestriche).'); }
            if ($do === 'duplizieren') {   // Kopie bekommt automatisch eine freie Adresse
                $basis = $neuSlug; $i = 1;
                while (x25ed_get($neuSlug) !== null) { $i++; $neuSlug = $basis . '-kopie' . ($i > 2 ? '-' . $i : ''); }
            }
            if (x25ed_get($neuSlug) !== null) { throw new RuntimeException('Es gibt bereits eine Edition mit der Adresse /' . $neuSlug . '/. Bitte eine andere Adresse wählen.'); }
            $neu = $vorlage;
            $neu['slug'] = $neuSlug;
            $neu['name'] = $name;
            $neu['thema'] = $thema;
            $neu['name_html'] = '';                    // wird aus dem Thema abgeleitet
            $neu['status'] = 'entwurf';
            $neu['anmeldung_offen'] = false;
            $neu['created_at'] = gmdate('c');
            if ($do === 'neu') {
                foreach (['datum_text', 'datum_kurz', 'datum_start', 'datum_ende', 'anmeldung_ab', 'leistungsdatum'] as $k) { $neu[$k] = ''; }
                $neu['ticket_prefix'] = '25X-' . mb_strtoupper(mb_substr(x25ed_slugify($thema), 0, 4)) . '-';
                // Name und Thema der Vorlage in den übernommenen Texten durch die neuen ersetzen
                $ersetzungen = array_filter([
                    (string)$vorlage['name'] => $name,
                    mb_strtoupper((string)($vorlage['thema'] ?? '')) => mb_strtoupper($thema),
                    (string)($vorlage['thema'] ?? '') => $thema,
                ], static fn($v, $k) => $k !== '' && $k !== $v, ARRAY_FILTER_USE_BOTH);
                if ($ersetzungen) {
                    $tausche = static fn(string $s): string => strtr($s, $ersetzungen);
                    foreach ($neu['texte'] ?? [] as $bereich => $paare) {
                        foreach ($paare as $k => $v) { $neu['texte'][$bereich][$k] = $tausche((string)$v); }
                    }
                    $neu['kurz'] = $tausche((string)($neu['kurz'] ?? ''));
                    foreach (['kicker', 'fakten', 'meta'] as $k) {
                        if (isset($neu['karte'][$k]) && is_string($neu['karte'][$k])) { $neu['karte'][$k] = $tausche($neu['karte'][$k]); }
                    }
                    foreach (['punkte', 'aside'] as $k) {
                        if (isset($neu['karte'][$k]) && is_array($neu['karte'][$k])) { $neu['karte'][$k] = array_map($tausche, $neu['karte'][$k]); }
                    }
                }
            }
            x25ed_save($neu);
            $hinweis = $do === 'neu'
                ? 'Edition „' . $name . '“ ist angelegt (Status: Entwurf). Wichtig: Termin und Ort eintragen und die Texte prüfen – Datumsangaben in den übernommenen Texten (z. B. Kopfbereich, Seitentitel) stammen noch aus der Vorlage.'
                : 'Edition „' . $name . '“ ist als Kopie angelegt (Status: Entwurf).';
            header('Location: ' . xv_flash_url('bearbeiten.php', $hinweis, ['slug' => $neuSlug]), true, 303);
            exit;
        }
        $ed = x25ed_get($slug);
        if ($ed === null) { throw new RuntimeException('Edition nicht gefunden.'); }
        switch ($do) {
            case 'status':
                $neuStatus = (string)($_POST['status'] ?? '');
                if (!isset(X25ED_STATUS[$neuStatus])) { throw new RuntimeException('Unbekannter Status.'); }
                $ed['status'] = $neuStatus;
                if ($neuStatus !== 'online') { $ed['anmeldung_offen'] = false; }
                x25ed_save($ed);
                $msg = '„' . $ed['name'] . '“ ist jetzt: ' . X25ED_STATUS[$neuStatus] . '.';
                break;
            case 'anmeldung':
                $ed['anmeldung_offen'] = (($_POST['offen'] ?? '') === '1');
                x25ed_save($ed);
                $msg = 'Anmeldung für „' . $ed['name'] . '“ ist jetzt ' . ($ed['anmeldung_offen'] ? 'geöffnet' : 'geschlossen') . '.';
                break;
            case 'loeschen':
                if (($_POST['bestaetigung'] ?? '') !== 'LÖSCHEN') { throw new RuntimeException('Zum Löschen bitte „LÖSCHEN“ in das Bestätigungsfeld schreiben.'); }
                $anz = x25ed_anmeldungen_je_edition()[$slug] ?? 0;
                if ($anz > 0) { throw new RuntimeException('Diese Edition hat ' . $anz . ' Anmeldung(en) und kann nicht gelöscht werden. Bitte stattdessen archivieren.'); }
                x25ed_delete($slug);
                $msg = 'Edition „' . $ed['name'] . '“ ist gelöscht.';
                break;
            default:
                throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $ex) {
        $msg = 'Fehler: ' . $ex->getMessage();
    }
    header('Location: ' . xv_flash_url('index.php', $msg), true, 303);
    exit;
}

// ------------------------------------------------------------------ Übersicht
$flash = (string)($_GET['m'] ?? '');
$alle = x25ed_all();
$anmeldungen = x25ed_anmeldungen_je_edition();
$statusReihenfolge = ['online' => 0, 'angekuendigt' => 1, 'entwurf' => 2, 'archiviert' => 3];
usort($alle, static fn($a, $b) => ($statusReihenfolge[$a['status']] ?? 9) <=> ($statusReihenfolge[$b['status']] ?? 9)
    ?: strcmp((string)($a['datum_start'] ?: '9999'), (string)($b['datum_start'] ?: '9999')));

$karten = '';
foreach ($alle as $ed) {
    $slug = (string)$ed['slug'];
    $anz = (int)($anmeldungen[$slug] ?? 0);
    $plaetze = x25ed_seats($slug);
    $plaetzeTxt = $plaetze === null ? '' : $plaetze . ' / ' . (int)($ed['max_plaetze'] ?? 25) . ' Plätze belegt · ';
    $offen = !empty($ed['anmeldung_offen']);
    $statusBtns = '';
    foreach (X25ED_STATUS as $sKey => $sLabel) {
        if ($sKey === ($ed['status'] ?? '')) { continue; }
        $wirkt = match ($sKey) {
            'online' => 'Landingpage und Anmeldung werden öffentlich sichtbar.',
            'angekuendigt' => 'Nur die Teaser-Karte erscheint auf Startseite und Übersicht.',
            'entwurf' => 'Die Edition verschwindet von der Website (Vorschau-Link bleibt).',
            'archiviert' => 'Die Edition verschwindet von der Website; Anmeldedaten bleiben erhalten.',
        };
        $statusBtns .= '<form method="post" action="index.php" data-confirm="„' . $e($ed['name']) . '“ auf „' . $e($sLabel) . '“ stellen? ' . $e($wirkt) . '">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="do" value="status"><input type="hidden" name="slug" value="' . $e($slug) . '"><input type="hidden" name="status" value="' . $e($sKey) . '">'
            . '<button class="v-btn v-btn--klein v-btn--leise" type="submit">' . $e($sLabel) . '</button></form>';
    }
    $anmBtn = ($ed['status'] ?? '') === 'online'
        ? '<form method="post" action="index.php"><input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="do" value="anmeldung"><input type="hidden" name="slug" value="' . $e($slug) . '"><input type="hidden" name="offen" value="' . ($offen ? '0' : '1') . '">'
          . '<button class="v-btn v-btn--klein ' . ($offen ? 'v-btn--leise' : '') . '" type="submit">' . ($offen ? 'Anmeldung schließen' : 'Anmeldung öffnen') . '</button></form>'
        : '';
    $loeschen = $anz === 0
        ? '<details class="v-loeschen"><summary>Löschen …</summary><form method="post" action="index.php" class="v-loeschen__form">'
          . '<input type="hidden" name="csrf" value="' . $e($csrf) . '"><input type="hidden" name="do" value="loeschen"><input type="hidden" name="slug" value="' . $e($slug) . '">'
          . '<label>Zur Bestätigung „LÖSCHEN“ eintippen: <input type="text" name="bestaetigung" placeholder="LÖSCHEN" size="10"></label>'
          . '<button class="v-btn v-btn--klein v-btn--rot" type="submit">Endgültig löschen</button></form></details>'
        : '<span class="v-meta">Löschen nicht möglich (' . $anz . ' Anmeldungen) – stattdessen archivieren.</span>';
    $datumOrt = trim(implode(' · ', array_filter([(string)($ed['datum_text'] ?? ''), (string)($ed['ort'] ?? '')])));
    $anmeldStatus = ($ed['status'] ?? '') === 'online' ? ($offen ? '· Anmeldung geöffnet' : '· Anmeldung geschlossen') : '';
    $badgeHtml = xv_badge($ed);
    $karten .= <<<HTML

    <article class="v-card v-edition">
      <div class="v-edition__kopf">
        <div>
          <h2 class="v-edition__name">{$e((string)$ed['name'])}</h2>
          <p class="v-meta">{$e($datumOrt)} &nbsp;·&nbsp; Adresse: /editionen/{$e($slug)}/ {$e($anmeldStatus)}</p>
        </div>
        {$badgeHtml}
      </div>
      <p class="v-meta">{$plaetzeTxt}{$anz} Anmeldung(en) insgesamt</p>
      <div class="v-edition__aktionen">
        <a class="v-btn" href="bearbeiten.php?slug={$e($slug)}">Bearbeiten</a>
        <a class="v-btn v-btn--leise" href="{$e(xv_ansehen_url($ed))}" target="_blank" rel="noopener">Ansehen ↗</a>
        {$anmBtn}
        <form method="post" action="index.php"><input type="hidden" name="csrf" value="{$e($csrf)}"><input type="hidden" name="do" value="duplizieren"><input type="hidden" name="slug" value="{$e($slug)}"><input type="hidden" name="vorlage" value="{$e($slug)}"><button class="v-btn v-btn--leise" type="submit">Duplizieren</button></form>
        <span class="v-edition__status">Status ändern: {$statusBtns}</span>
      </div>
      <div class="v-edition__fuss">{$loeschen}</div>
    </article>
HTML;
}

$vorlagen = '';
foreach ($alle as $ed) {
    $vorlagen .= '<option value="' . $e((string)$ed['slug']) . '">' . $e((string)$ed['name']) . '</option>';
}
$flashHtml = $flash !== '' ? '<div class="v-card ' . (str_starts_with($flash, 'Fehler') ? 'v-card--warn' : 'v-card--ok') . '"><strong>' . $e($flash) . '</strong></div>' : '';

$body = <<<HTML

    <div class="v-kopfzeile">
      <div>
        <p class="v-kicker">Verwaltung</p>
        <h1>Event-Editionen</h1>
        <p class="v-meta v-maxw">Hier pflegt Ihr die Editionen der 25 EXPERTS: anlegen, Texte und Termine bearbeiten, online stellen, Anmeldung öffnen.
        Jede Edition hat ihre eigene Landingpage unter <strong>25-experts.de/editionen/…</strong> und erscheint automatisch auf Startseite und Editionen-Übersicht, sobald sie online ist.</p>
      </div>
    </div>
    {$flashHtml}
    {$karten}

    <details class="v-card v-neu" id="neu">
      <summary class="v-neu__summary">+ Neue Edition anlegen</summary>
      <form method="post" action="index.php" class="v-form">
        <input type="hidden" name="csrf" value="{$e($csrf)}">
        <input type="hidden" name="do" value="neu">
        <div class="v-form__grid">
          <label class="v-feld">
            <span>Thema <em>Pflicht</em></span>
            <input type="text" name="thema" placeholder="z. B. Underwriting" required>
            <small>Daraus entstehen Name („25 UNDERWRITING EXPERTS“) und Web-Adresse.</small>
          </label>
          <label class="v-feld">
            <span>Name (optional)</span>
            <input type="text" name="name" placeholder="wird sonst aus dem Thema gebildet">
          </label>
          <label class="v-feld">
            <span>Web-Adresse (optional)</span>
            <input type="text" name="neuer_slug" placeholder="z. B. underwriting">
            <small>Kleinbuchstaben und Bindestriche; ergibt 25-experts.de/editionen/…/</small>
          </label>
          <label class="v-feld">
            <span>Texte übernehmen von</span>
            <select name="vorlage">{$vorlagen}</select>
            <small>Alle Texte der gewählten Edition werden als Startpunkt kopiert.</small>
          </label>
        </div>
        <p class="v-meta">Die neue Edition startet als <strong>Entwurf</strong> (nicht öffentlich). Termin, Ort, Preis und Texte trägst Du danach im Bearbeiten-Formular ein.</p>
        <button class="v-btn v-btn--gross" type="submit">Edition anlegen</button>
      </form>
    </details>
HTML;

xv_page('Editionen', $body);
