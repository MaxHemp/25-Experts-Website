<?php
declare(strict_types=1);
require __DIR__.'/lib/flow.php';
header('Referrer-Policy: no-referrer');
$token=(string)($_REQUEST['t']??'');
$rec=preg_match('/^[a-f0-9]{32}$/',$token)?x25_store()->findByToken($token):null;
if (!$rec || $rec['status']!=='zugelassen') { x25_out(x25_page('Link nicht verfügbar','<h1>Dieser persönliche Link ist nicht verfügbar.</h1><p>Bitte nutze den Vorbereitungslink aus Deiner Zusage oder wende Dich an die Gastgeber.</p>'),404); }
$h=static fn($s):string=>x25_e((string)$s);$ed=x25_edition_for($rec);$csrf=x25_sign('vorbereitung|'.$token);$flash='';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!hash_equals($csrf,(string)($_POST['csrf']??''))) { x25_out(x25_page('Anfrage abgelehnt','<h1>Bitte lade Deine Vorbereitungsseite erneut.</h1>'),403); }
    $values=[];
    foreach(['decision','experience','learn','followup'] as $key) { $values[$key]=x25_multiline($_POST[$key]??'',3000); }
    if (trim($values['decision'])==='' || trim($values['experience'])==='' || trim($values['learn'])==='') { $flash='Bitte beantworte die drei Vorbereitungsfragen kurz.'; }
    else {
        $values['updated_at']=gmdate('c');
        x25_store()->update((int)$rec['id'],['preparation'=>$values]);
        header('Location: vorbereitung.php?t='.rawurlencode($token).'&gespeichert=1',true,303);exit;
    }
}
$v=(array)($rec['preparation']??[]);
if (isset($_GET['gespeichert'])) { $flash='Danke. Deine Antworten sind gespeichert. Du kannst sie über diesen Link jederzeit ergänzen.'; }
$fields='';
foreach(['decision'=>'Welche Entscheidung beschäftigt Dich gerade?','experience'=>'Welche eigene Erfahrung kannst Du beitragen?','learn'=>'Was möchtest Du von Menschen aus anderen Unternehmen verstehen?','followup'=>'Nach der Edition: Was hast Du umgesetzt, und wo brauchst Du weiteren Austausch?'] as $key=>$label) {
    $required=$key==='followup'?'':' required';
    $val=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?($_POST[$key]??''):($v[$key]??($key==='decision'?$rec['question']:''));
    $fields.='<p><label for="'.$key.'"><strong>'.$h($label).'</strong></label><br><textarea id="'.$key.'" name="'.$key.'" rows="4" maxlength="3000"'.$required.' style="width:100%">'.$h($val).'</textarea></p>';
}
$settings=x25_curation_load((string)($rec['edition_slug']??x25_default_slug()));$dossier='';
if (!empty($settings['dossier_ready'])) {
    foreach(['themes'=>'Drei Spannungsfelder','cases'=>'Zwei vorbereitete Fälle','agenda'=>'Unsere Arbeitsagenda'] as $k=>$title) {
        if(trim((string)($settings[$k]??''))!=='') { $dossier.='<h3>'.$title.'</h3><p style="white-space:pre-wrap">'.$h($settings[$k]).'</p>'; }
    }
}
if ($dossier==='') { $dossier='<p>Die Gastgeber bereiten aus Euren Fragen ein kurzes Dossier vor. Sobald es freigegeben ist, findest Du es hier.</p>'; }
$meeting='<p>Unser moderiertes Online-Wiedersehen ist sechs Wochen nach der Edition vorgesehen. Den genauen Termin und Zugangslink erhältst Du von den Gastgebern; nach der Terminierung findest Du beides auch hier.</p>';
if (!empty($settings['meeting_date'])) { $meeting.='<p><strong>'.$h($settings['meeting_date']).'</strong></p>'; }
if (!empty($settings['meeting_url']) && filter_var($settings['meeting_url'],FILTER_VALIDATE_URL) && str_starts_with($settings['meeting_url'],'https://')) { $meeting.='<p><a class="btn" href="'.$h($settings['meeting_url']).'" rel="noreferrer noopener">Zum Online-Wiedersehen</a></p>'; }
$care=(array)($rec['care']??[]);
$introductions=!empty($care['introductions_approved'])&&trim((string)($care['introductions']??''))!=='' ? '<section class="card"><h2>Persönlich für Dich vermittelt</h2><p style="white-space:pre-wrap">'.$h($care['introductions']).'</p></section>' : '';
$paper=trim((string)($settings['paper']??''));
$paperHtml=(!empty($settings['paper_ready'])&&$paper!=='')?'<section class="card"><h2>Die interne Ausgabe des Dissenspapiers</h2><p>Für den Teilnehmerkreis. Bitte nicht weitergeben.</p><div style="white-space:pre-wrap">'.$h($paper).'</div></section>':'';
x25_out(x25_page('Deine Vorbereitung','<p class="kicker">'.$h($ed['name']).'</p><h1>Deine Fragen bereiten die Runde vor.</h1><p>Hallo '.$h($rec['vorname']??$rec['name']).', drei kurze Antworten helfen uns, die Gespräche auf Eure Anliegen auszurichten. Bitte bis zwei Wochen vor der Edition ergänzen. Vertrauliche Unternehmens- oder Kundendaten gehören nicht in dieses Formular.</p>'.($flash!==''?'<div class="card" role="status">'.$h($flash).'</div>':'').'<form method="post" class="card"><input type="hidden" name="t" value="'.$h($token).'"><input type="hidden" name="csrf" value="'.$h($csrf).'">'.$fields.'<button class="btn" type="submit">Antworten speichern</button></form><section class="card"><h2>Dein Dossier</h2>'.$dossier.'</section>'.$paperHtml.$introductions.'<section class="card"><h2>Nach sechs Wochen sehen wir uns wieder.</h2>'.$meeting.'</section><p class="meta">Dieser Link ist persönlich. Teile ihn nicht mit anderen.</p>','',false,$ed['label']));
