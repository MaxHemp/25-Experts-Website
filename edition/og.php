<?php
/**
 * 25 EXPERTS – Share-Bild einer Edition (Open Graph, 1200x630): /editionen/{slug}/og.jpg  →  edition/og.php?slug=…
 * Zeichnet die Karte im Stil von 04-website/build_og.py (GD + TTF aus assets/fonts/ttf/) und
 * cacht das JPEG in DATA_DIR/og-cache/ (wird beim Speichern der Edition verworfen).
 * Ohne GD oder Fonts: Weiterleitung auf das allgemeine Share-Bild.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$slug = (string)($_GET['slug'] ?? '');
$ed = x25ed_get($slug);
$fallback = '/assets/img/og/25experts-og.jpg';
if ($ed === null) { header('Location: ' . $fallback, true, 302); exit; }

$fontsDir = X25ED_ROOT . '/assets/fonts/ttf';
$fontXB = $fontsDir . '/PlusJakartaSans-ExtraBold.ttf';
$fontMono = $fontsDir . '/IBMPlexMono-Medium.ttf';
if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext') || !is_file($fontXB) || !is_file($fontMono)) {
    header('Location: ' . $fallback, true, 302);
    exit;
}

// Cache je Editionsstand
$stamp = substr(hash('sha256', json_encode([$ed['name'], $ed['thema'] ?? '', $ed['datum_kurz'] ?? '', $ed['ort'] ?? '', $ed['status'], $ed['anmeldung_offen'] ?? false, $ed['max_plaetze'] ?? 25])), 0, 12);
$cache = x25ed_og_cache_dir() . '/' . $slug . '-' . $stamp . '.jpg';
if (!is_file($cache)) {
    $img = x25ed_og_zeichnen($ed, $fontXB, $fontMono);
    if ($img === null) { header('Location: ' . $fallback, true, 302); exit; }
    imagejpeg($img, $cache, 90);
    imagedestroy($img);
}
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . (string)filesize($cache));
readfile($cache);
exit;

function x25ed_og_zeichnen(array $ed, string $fontXB, string $fontMono)
{
    $W = 1200; $H = 630;
    $img = imagecreatetruecolor($W, $H);
    $rgb = static fn(string $hex) => [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $col = static function ($img, string $hex) use ($rgb) { [$r, $g, $b] = $rgb($hex); return imagecolorallocate($img, $r, $g, $b); };
    $ink = $col($img, '#0B1F26'); $petrol = $col($img, '#0B6470'); $signal = $col($img, '#C2410C');
    $paper = $col($img, '#FBFAF6'); $neutral = $col($img, '#B8C4C6');
    imagefilledrectangle($img, 0, 0, $W, $H, $ink);
    imageantialias($img, true);

    // 5x5-Punktraster mit Signal-Quadrat (Feld Zeile 3, Spalte 4)
    $gx = 92; $gy = 92; $gap = 22; $s = 8;
    for ($r = 0; $r < 5; $r++) {
        for ($c = 0; $c < 5; $c++) {
            $cx = $gx + $c * $gap; $cy = $gy + $r * $gap;
            if ($r === 2 && $c === 3) {
                imagefilledrectangle($img, $cx - $s, $cy - $s, $cx + $s, $cy + $s, $signal);
            } else {
                imagefilledellipse($img, $cx, $cy, (int)($s * 1.1), (int)($s * 1.1), $paper);
            }
        }
    }
    // Wortmarke, Trennlinie (imagettftext: y = Grundlinie)
    imagettftext($img, 43, 0, 215, 63 + 55, $paper, $fontXB, '25 EXPERTS');
    imagefilledrectangle($img, 80, 551, $W - 80, 553, $petrol);

    // Kopfzeile
    $offen = !empty($ed['anmeldung_offen']) && ($ed['status'] ?? '') === 'online';
    $kopf = $offen ? 'NÄCHSTES EVENT · ANMELDUNG GEÖFFNET' : mb_strtoupper((string)(X25ED_STATUS[$ed['status'] ?? ''] ?? 'EDITION'));
    imagettftext($img, 16, 0, 80, 206 + 21, $neutral, $fontMono, $kopf);

    // Editionsname: „25 <THEMA> EXPERTS", Thema in Signalfarbe, umbrochen
    $thema = mb_strtoupper(trim((string)($ed['thema'] ?? '')));
    if ($thema === '') {
        $thema = trim(preg_replace('/^25\s+|\s+EXPERTS$/u', '', mb_strtoupper((string)($ed['name'] ?? ''))) ?? '');
    }
    // Zeilen: „25 WORT1", weitere Themen-Wörter je eigene Zeile, dann „EXPERTS";
    // Schriftgröße so wählen, dass die breiteste Zeile in die Karte passt
    $worte = preg_split('/\s+/', $thema) ?: [];
    $size = count($worte) > 1 ? 66 : 70;
    $breiteste = '25 ' . ($worte[0] ?? 'EXPERTS');
    foreach ($worte as $wi => $wo) { if ($wi > 0 && mb_strlen($wo) > mb_strlen($breiteste)) { $breiteste = $wo; } }
    while ($size > 24) {
        $box = imagettfbbox($size, 0, $fontXB, $breiteste);
        $zeilenZahl = count($worte) + 1;
        if (abs($box[2] - $box[0]) <= 1040 && $zeilenZahl * $size * 1.45 <= 300) { break; }
        $size -= 4;
    }
    $y = 252 + (int)($size * 1.15);
    $x0 = 76;
    // Zeile 1: 25 + erstes Themenwort
    $bb = imagettftext($img, $size, 0, $x0, $y, $paper, $fontXB, '25 ');
    imagettftext($img, $size, 0, $bb[2], $y, $signal, $fontXB, $worte[0] ?? 'EXPERTS');
    $zeilenAbstand = (int)($size * 1.45);
    for ($i = 1; $i < count($worte); $i++) {
        $y += $zeilenAbstand;
        imagettftext($img, $size, 0, $x0, $y, $signal, $fontXB, $worte[$i]);
    }
    $y += $zeilenAbstand;
    imagettftext($img, $size, 0, $x0, $y, $paper, $fontXB, 'EXPERTS');

    // Fußzeile
    $fuss = implode(' · ', array_filter([
        (string)($ed['datum_kurz'] ?? ''), (string)($ed['ort'] ?? ''),
        (string)($ed['max_plaetze'] ?? 25) . ' Plätze', '25-experts.de',
    ]));
    imagettftext($img, 16, 0, 80, 572 + 21, $neutral, $fontMono, $fuss);
    return $img;
}
