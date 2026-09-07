<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
require_once dirname(__DIR__).'/anmeldung/lib/flow.php';
$h=static fn($s):string=>x25ed_e((string)$s);
$slug=(string)($_REQUEST['edition']??'change-management');$ed=x25ed_get($slug);
if(!$ed) { xv_page('Edition fehlt','<h1>Bitte eine vorhandene Edition auswählen.</h1>',404); }
$settings=x25_curation_load($slug);$notice='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if(!xv_csrf_ok((string)($_POST['csrf']??''))) { xv_page('Sitzung abgelaufen','<h1>Bitte die Seite neu laden.</h1>',403); }
    try {
        if(($_POST['do']??'')==='person') {
            $rec=x25_store()->get((int)($_POST['id']??0));
            if(!$rec||($rec['edition_slug']??x25_default_slug())!==$slug) { throw new RuntimeException('Teilnehmer gehört nicht zu dieser Edition.'); }
            $care=['table'=>max(0,min(3,(int)($_POST['table']??0))), 'welcome'=>x25_multiline($_POST['welcome']??'',600), 'introductions'=>x25_multiline($_POST['introductions']??'',1600), 'introductions_approved'=>(($_POST['introductions_approved']??'')==='ja'), 'notes'=>x25_multiline($_POST['notes']??'',2400)];
            x25_store()->update((int)$rec['id'],['care'=>$care]);
        } else {
            $before=$settings;
            foreach(['themes','cases','agenda','paper','public_paper'] as $k) { $settings[$k]=x25_multiline($_POST[$k]??'',20000); }
            $settings['meeting_date']=x25_line($_POST['meeting_date']??'',200);
            $url=x25_line($_POST['meeting_url']??'',1000);
            if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!str_starts_with($url,'https://'))) { throw new RuntimeException('Bitte einen gültigen HTTPS-Link für das Wiedersehen eingeben.'); }
            $settings['meeting_url']=$url;
            foreach(['dossier_ready','paper_ready','public_paper_approved'] as $k) { $settings[$k]=(($_POST[$k]??'')==='ja'); }
            if (($before['public_paper']??'')!==$settings['public_paper']) { $settings['public_paper_approved']=false; }
            $settings['tasks']=array_values(array_intersect((array)($_POST['tasks']??[]),['gruppe','beitraege','dossier','service','fotografie','kontakte','papier','wiedersehen']));
            $settings['updated_at']=gmdate('c');
            x25_private_json(x25_curation_dir().'/.begleitung-'.$slug.'.json',$settings);
        }
        header('Location: begleitung.php?edition='.rawurlencode($slug).'&gespeichert=1',true,303);exit;
    }catch(Throwable $e) { $notice='<p role="alert">'.$h($e->getMessage()).'</p>'; }
}
if(isset($_GET['gespeichert'])) { $notice='<p role="status">Gespeichert.</p>'; }
$options='';foreach(x25ed_all() as $row) { $options.='<option value="'.$h($row['slug']).'"'.($row['slug']===$slug?' selected':'').'>'.$h($row['name']).'</option>'; }
$start=new DateTimeImmutable((string)$ed['datum_start']);$end=new DateTimeImmutable((string)$ed['datum_ende']);
$tasks=['gruppe'=>'Gruppe fachlich prüfen: höchstens zwei pro Unternehmen; Unternehmensnamen abgleichen.', 'beitraege'=>'Beitragende briefen: eine Entscheidung, ein Lernpunkt, Zeit für Rückfragen; Partnerrolle benennen.', 'dossier'=>'Drei Spannungsfelder und zwei vorbereitete Fälle festlegen; Dossier freigeben.', 'service'=>'Begrüßung, persönliche Platzkarten, Akustik, bequeme Plätze, Dinner und Sitzordnung prüfen.', 'fotografie'=>'Fotografen briefen: angekündigte Fotomomente beim Empfang und Abend; keine Arbeitsphasen.', 'kontakte'=>'Für jeden Teilnehmer zwei passende Gespräche vorbereiten; Zustimmung vor Kontaktweitergabe klären.', 'papier'=>'Interne Ausgabe fertigstellen; öffentliche Kurzfassung ausdrücklich freigeben lassen.', 'wiedersehen'=>'Online-Termin sechs Wochen später festlegen und persönlich mitteilen.'];
$checks='';foreach($tasks as $key=>$label) { $checks.='<p><label><input type="checkbox" name="tasks[]" value="'.$key.'"'.(in_array($key,$settings['tasks']??[],true)?' checked':'').'> '.$h($label).'</label></p>'; }
$fields='';foreach(['themes'=>'Drei Spannungsfelder','cases'=>'Zwei vorbereitete Praxisfälle','agenda'=>'Arbeitsagenda für das Dossier','paper'=>'Interne Ausgabe des Dissenspapiers','public_paper'=>'Freigegebene öffentliche Kurzfassung (Export)'] as $key=>$label) {
    $fields.='<p><label>'.$label.'<br><textarea name="'.$key.'" rows="7" maxlength="20000" style="width:100%">'.$h($settings[$key]??'').'</textarea></label></p>';
}
$release='';foreach(['dossier_ready'=>'Dossier für zugelassene Teilnehmer freigeben','paper_ready'=>'Interne Ausgabe für zugelassene Teilnehmer freigeben','public_paper_approved'=>'Öffentliche Kurzfassung wurde ausdrücklich inhaltlich freigegeben'] as $key=>$label) {
    $release.='<p><label><input type="checkbox" name="'.$key.'" value="ja"'.(!empty($settings[$key])?' checked':'').'> '.$label.'</label></p>';
}
$people='';$totals=[1=>0,2=>0,3=>0];
foreach(array_reverse(x25_store()->all()) as $rec) {
    if(($rec['edition_slug']??x25_default_slug())!==$slug||$rec['status']!=='zugelassen') { continue; }
    $prep=(array)($rec['preparation']??[]);$care=(array)($rec['care']??[]);$table=(int)($care['table']??0);
    if(isset($totals[$table])) { $totals[$table]++; }
    $select='<option value="0">Noch offen</option>';for($n=1;$n<=3;$n++) { $select.='<option value="'.$n.'"'.($table===$n?' selected':'').'>Tisch '.$n.'</option>'; }
    $people.='<details class="v-card"><summary><strong>'.$h($rec['name']).'</strong> · '.$h($rec['company']).' · '.(empty($prep)?'Vorbereitung offen':'Antworten vorhanden').'</summary><p>'.$h($rec['role']).'</p>';
    foreach(['decision'=>'Aktuelle Entscheidung','experience'=>'Eigene Erfahrung','learn'=>'Von anderen verstehen','followup'=>'Rückmeldung nach der Edition'] as $k=>$label) { $people.='<p><strong>'.$label.'</strong><br><span style="white-space:pre-wrap">'.$h($prep[$k]??($k==='decision'?$rec['question']:'' )).'</span></p>'; }
    $people.='<p><a href="'.$h(x25_prepare_url($rec)).'" rel="noreferrer">Persönliche Vorbereitungsseite</a> · <a href="mailto:'.$h($rec['email']).'">Persönlich schreiben</a></p><form method="post"><input type="hidden" name="csrf" value="'.$h(xv_csrf()).'"><input type="hidden" name="edition" value="'.$h($slug).'"><input type="hidden" name="do" value="person"><input type="hidden" name="id" value="'.(int)$rec['id'].'"><p><label>Arbeitstisch <select name="table">'.$select.'</select></label></p><p><label>Persönliches Willkommen für die Platzkarte<br><textarea name="welcome" rows="2" maxlength="600" style="width:100%">'.$h($care['welcome']??'').'</textarea></label></p><p><label>Zwei passende Kontakte / Gespräche vorbereiten<br><textarea name="introductions" rows="3" maxlength="1600" style="width:100%">'.$h($care['introductions']??'').'</textarea></label></p><p><label><input type="checkbox" name="introductions_approved" value="ja"'.(!empty($care['introductions_approved'])?' checked':'').'> Die betroffenen Personen haben dieser Kontaktvermittlung zugestimmt; auf der persönlichen Teilnehmerseite anzeigen.</label></p><p><label>Interne Notizen (z. B. direkte Berichtslinien bei der Tischplanung berücksichtigen)<br><textarea name="notes" rows="3" maxlength="2400" style="width:100%">'.$h($care['notes']??'').'</textarea></label></p><button type="submit">Betreuung speichern</button></form></details>';
}
$links='';foreach(['briefing'=>'Moderationsbriefing','platzkarten'=>'Platzkarten','kuvert'=>'Kuvert-Einleger','dossier'=>'Dossier','papier'=>'Internes Dissenspapier','oeffentlich'=>'Öffentliche Kurzfassung'] as $key=>$label) { $links.='<a href="material.php?edition='.$h($slug).'&typ='.$key.'">'.$label.'</a> · '; }
xv_page('Begleitung','<h1>Vorbereitung und Begleitung</h1><form method="get"><label>Edition <select name="edition">'.$options.'</select></label> <button type="submit">Öffnen</button></form>'.$notice.'<p><strong>'.$h($ed['name']).'</strong> · '.$h($ed['datum_text']).'</p><p>Vorbereitungsantworten bis '.$start->modify('-14 days')->format('d.m.Y').'. Ziel für das Online-Wiedersehen: '.$end->modify('+42 days')->format('d.m.Y').'. Der genaue Termin wird unten eingetragen und persönlich mitgeteilt.</p><p>'.$links.'</p><form method="post" class="v-card"><input type="hidden" name="csrf" value="'.$h(xv_csrf()).'"><input type="hidden" name="edition" value="'.$h($slug).'"><h2>Durchführung vorbereiten</h2>'.$checks.'<h2>Dossier und Ergebnisse</h2><p>Keine vertraulichen Rohdaten übernehmen. Freigaben gelten für die untenstehenden Inhalte und müssen nach einer inhaltlichen Änderung erneut geprüft werden.</p>'.$fields.$release.'<h2>Online-Wiedersehen</h2><p><label>Bestätigter Termin mit Uhrzeit und Zeitzone<br><input name="meeting_date" maxlength="200" value="'.$h($settings['meeting_date']??'').'" placeholder="Datum · Uhrzeit · Europe/Berlin"></label></p><p><label>Zugangslink (HTTPS)<br><input type="url" name="meeting_url" maxlength="1000" value="'.$h($settings['meeting_url']??'').'" style="width:100%"></label></p><button type="submit">Begleitung speichern</button></form><h2>Teilnehmer persönlich begleiten</h2><p>Tisch 1: '.$totals[1].' · Tisch 2: '.$totals[2].' · Tisch 3: '.$totals[3].'. Ziel: acht bis neun Personen je Tisch. Direkte Berichtslinien möglichst trennen.</p>'.$people);
