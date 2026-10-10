<?php
declare(strict_types=1);
/** Editorial identity: facts, dates, prices and booking rules stay in edition data. */
function x25ed_identity(string $slug): array
{
    $themes = [
        'change-management' => ['01', 'KI verändert Arbeit. Du gestaltest den Wandel.', 'Wie wird aus neuen Werkzeugen eine Zusammenarbeit, die trägt?'],
        'security' => ['02', 'Sicherheit braucht Verantwortung.', 'Wer entscheidet zwischen Schutz, Tempo und Verantwortung?'],
        'vertrieb' => ['03', 'Kundennähe beginnt mit einer Entscheidung.', 'Was braucht Vertrieb, wenn sich Erwartungen und Zugänge verändern?'],
        'operations' => ['04', 'Fachbereich und IT. Gemeinsam wirksam.', 'Wie werden aus Übergaben gemeinsame Entscheidungen?'],
        'female' => ['05', 'Verantwortung übernehmen. Sichtbar gestalten.', 'Wie stärken Frauen ihren Einfluss auf die Entscheidungen der Branche?'],
        'data' => ['06', 'Daten sind da. Worauf verlassen wir uns?', 'Welche Daten tragen Entscheidungen – und wer steht dafür ein?'],
        'sustainability' => ['07', 'Wirkung braucht Entschei­dungen.', 'Wie wird aus Nachhaltigkeitszielen veränderte Praxis?'],
    ];
    return $themes[$slug] ?? ['25', 'Bring Deine Perspektive mit.', 'Welche Entscheidung möchtest Du voranbringen?'];
}
function x25ed_motif(): string
{
    return '<div class="nx-perspectives" aria-hidden="true">' . str_repeat('<i></i>', 25) . '</div>';
}
/** Header-Video (KI-generiertes Symbolbild, Dateien in assets/video/, Manifest tools/fotos.json).
 *  Gleiches Markup wie auf der Startseite (index.html); Styles in identity.css (.nx-hero--video …), Steuerung in site.js. */
function x25ed_hero_video(): string
{
    $v = '/assets/video/hero-teilnehmer-v1-';
    return '<div class="nx-hero__media" aria-hidden="true">'
        . '<video class="nx-hero__video" autoplay muted loop playsinline preload="auto" disablepictureinpicture disableremoteplayback tabindex="-1">'
        . '<source src="' . $v . 'mobil.webm" type="video/webm" media="(max-width: 700px)">'
        . '<source src="' . $v . 'mobil.mp4" type="video/mp4" media="(max-width: 700px)">'
        . '<source src="' . $v . '720.webm" type="video/webm" media="(max-width: 900px)">'
        . '<source src="' . $v . '720.mp4" type="video/mp4" media="(max-width: 900px)">'
        . '<source src="' . $v . '1080.webm" type="video/webm">'
        . '<source src="' . $v . '1080.mp4" type="video/mp4">'
        . '</video></div>'
        . '<div class="nx-hero__meta"><button class="nx-hero__toggle" type="button" aria-pressed="false" hidden>Video anhalten</button></div>';
}
