<?php
declare(strict_types=1);
require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/../anmeldung/lib/receipt.php';
$ed = x25ed_get((string)($_GET['slug'] ?? ''));
if ($ed === null) { x25ed_404('Diese Edition gibt es nicht.'); }
$receipt = x25_receipt_read((string)$ed['slug']);
$name = x25ed_e((string)$ed['name']);
$date = x25ed_e((string)$ed['datum_text']);
$url = x25ed_url($ed);
$title = $receipt ? 'Danke – Deine Anfrage ist eingegangen.' : 'So geht es nach Deiner Anfrage weiter.';
$email = $receipt ? '<p>Wir melden uns bei <strong class="x-review__email">' . x25ed_e($receipt['email']) . '</strong>.</p>' : '<p>Wenn Du Deine Anfrage bereits gesendet hast, findest Du die Eingangsbestätigung in Deinem E-Mail-Postfach. Auf diesem Gerät liegt keine aktuelle Eingangsbestätigung vor.</p>';
$body = <<<HTML
<section class="x-section"><div class="x-container ux-confirmation"><p class="x-kicker">{$name} · {$date}</p><h1 class="x-h1">{$title}</h1>{$email}<p>Du erhältst innerhalb von <strong>zwei Werktagen</strong> eine persönliche Rückmeldung. Deine Anfrage ist kostenfrei und noch keine verbindliche Buchung.</p><p>Nach unserer Zusage entscheidest Du selbst, ob Du verbindlich buchen möchtest. Erst diese ausdrückliche Buchung begründet die Zahlungspflicht.</p><div class="ux-note"><h2 class="x-h4">Noch eine Frage oder ein Tippfehler?</h2><p>Schreib uns an <a href="mailto:info@25-experts.de">info@25-experts.de</a> und nenne Deine Edition. Bitte sende keine zweite Anfrage.</p></div><p><a class="x-btn x-btn--secondary" href="{$url}">Zurück zu Deiner Edition</a></p></div></section>
HTML;
x25ed_out(x25ed_shell(['ed'=>$ed,'title'=>$title.' · '.$name,'description'=>'Nächste Schritte nach Deiner kostenfreien Teilnahme-Anfrage.','body'=>$body,'canonical'=>rtrim(x25ed_abs_url($ed),'/').'/danke','noindex'=>true,'cta_href'=>$url,'cta_label'=>'Zur Edition']),200,0);
