<?php
/** iCalendar attachment, without attendee data or private access links. */
declare(strict_types=1);

function x25_calendar_text(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", strip_tags($value));
    return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $value);
}

/** RFC 5545: 75 octets, without splitting UTF-8 characters. */
function x25_calendar_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $part = mb_strcut($line, 0, 75, 'UTF-8');
        $out .= $part . "\r\n";
        $line = ' ' . substr($line, strlen($part));
    }
    return $out . $line;
}

function x25_calendar_attachment(array $ed): array
{
    $dates = [];
    foreach (['datum_start', 'datum_ende'] as $key) {
        $raw = (string)($ed[$key] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $raw)) {
            throw new RuntimeException('Für den Kalenderanhang fehlen gültige Eventzeiten. Bitte die Edition prüfen.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new RuntimeException('Ungültiges Eventdatum für den Kalenderanhang.');
        }
        $dates[] = $date;
    }
    if ($dates[1] <= $dates[0]) { throw new RuntimeException('Das Eventende muss nach dem Beginn liegen.'); }
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($ed['slug'] ?? 'event'))) ?: 'event';
    $description = $ed['name'] . "\n\nTermin: " . $ed['datum']
        . "\nZeiten (Ortszeit Deutschland): " . $ed['zeiten']
        . "\nOrt: " . $ed['venue'] . "\nHotel: " . $ed['hotel']
        . "\nKontakt: " . $ed['kontakt'] . "\n\nDetails und Ablauf: " . $ed['landing']
        . "\n\nBitte halte Dein Ticket aus der Bestätigungsmail am Empfang bereit."
        . "\nDer Kalendereintrag umfasst das gesamte Event einschließlich Abendprogramm und Übernachtungspause. Die einzelnen Tageszeiten stehen oben.";
    $utc = new DateTimeZone('UTC');
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//25 EXPERTS//Event Calendar//DE',
        'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
        'UID:' . hash('sha256', (string)$ed['landing']) . '@25-experts.de',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . $dates[0]->setTimezone($utc)->format('Ymd\THis\Z'),
        'DTEND:' . $dates[1]->setTimezone($utc)->format('Ymd\THis\Z'),
        'SUMMARY:' . x25_calendar_text($ed['name']),
        'LOCATION:' . x25_calendar_text($ed['venue']),
        'DESCRIPTION:' . x25_calendar_text($description),
        'URL:' . str_replace(["\r", "\n"], '', $ed['landing']),
        'STATUS:CONFIRMED', 'TRANSP:OPAQUE', 'END:VEVENT', 'END:VCALENDAR'];
    return [implode("\r\n", array_map('x25_calendar_fold', $lines)) . "\r\n",
        '25-experts-' . $slug . '.ics', 'text/calendar; charset=UTF-8; method=PUBLISH'];
}
