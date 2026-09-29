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
