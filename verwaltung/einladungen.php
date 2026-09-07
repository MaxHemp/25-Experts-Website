<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_once dirname(__DIR__).'/anmeldung/lib/flow.php';
$h=static fn($s):string=>x25ed_e((string)$s);
$message='';$result='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!xv_csrf_ok((string)($_POST['csrf']??''))) { xv_page('Sitzung abgelaufen','<h1>Bitte die Seite neu laden.</h1>',403); }
    try {
        $slug=(string)($_POST['edition']??''); $ed=x25ed_get($slug);
        if (!$ed || $ed['status']!=='online' || empty($ed['anmeldung_offen'])) { throw new RuntimeException('Bitte eine geöffnete Edition wählen.'); }
        if (($_POST['geprueft']??'')!=='ja') { throw new RuntimeException('Bitte die fachliche Prüfung bestätigen.'); }
        $i=x25_invite_create($slug,(string)($_POST['email']??''),(string)($_POST['reason']??''));
        $url=x25ed_abs_url($ed).'anmeldung?einladung='.$i['token'];
        $draft="Hallo,\n\n".$i['reason']."\n\nIch lade Dich zu ".$ed['name'].' am '.$ed['datum_text']." im SESSEL HUB Rheinauhafen ein. Deine fachliche Passung haben wir bereits geprüft. Über Deinen persönlichen Link kannst Du Deine Angaben vervollständigen und direkt buchen, sofern ein Platz frei ist.\n\n".$url."\n\nDie Teilnahme kostet 450 € netto zzgl. USt. Enthalten sind beide Tage, Vorbereitung und Dossier, Aperitif und Dinner, das interne Dissenspapier und unser Online-Wiedersehen nach sechs Wochen. Der Link ist 30 Tage gültig und an Deine E-Mail-Adresse gebunden; er reserviert noch keinen Platz.\n\nHerzliche Grüße\nMaximilian Hempel · Gastgeber";
        $result='<section class="v-card"><h2>Persönliche Einladung vorbereitet</h2><p>Für '.$h($i['email']).'. Es wurde keine E-Mail versandt. Kopiere den Text in Deine persönliche Nachricht. Prüfe insbesondere Anrede und Begründung.</p><p><a href="'.$h($url).'">Einladungsseite öffnen</a></p><textarea rows="18" readonly style="width:100%">'.$h($draft).'</textarea></section>';
    } catch(Throwable $e) { $message='<p role="alert">'.$h($e->getMessage()).'</p>'; }
}
$options='';foreach(x25ed_all() as $ed) { if($ed['status']==='online'&&!empty($ed['anmeldung_offen'])) { $options.='<option value="'.$h($ed['slug']).'">'.$h($ed['name']).'</option>'; } }
xv_page('Persönliche Einladungen','<h1>Persönliche Einladungen</h1><p>Für bereits geprüfte Personen. Die Einladung gilt für genau eine E-Mail-Adresse und eine Edition. Die Grenze von 25 Teilnehmern und höchstens zwei pro Unternehmen bleibt bestehen.</p>'.$message.$result.'<form method="post" class="v-card"><input type="hidden" name="csrf" value="'.$h(xv_csrf()).'"><p><label>Edition<br><select name="edition" required>'.$options.'</select></label></p><p><label>Geschäftliche E-Mail-Adresse<br><input type="email" name="email" required maxlength="254"></label></p><p><label>Warum diese Person zur Runde passt<br><textarea name="reason" rows="4" maxlength="1200" required style="width:100%" placeholder="Deine Erfahrung mit … passt zu der Frage, an der wir in dieser Edition arbeiten."></textarea></label></p><p><label><input type="checkbox" name="geprueft" value="ja" required> Verantwortung, Erfahrung und Passung sind persönlich geprüft.</label></p><button type="submit">Persönlichen Einladungslink erstellen</button></form>');
