<?php
/** Teilnehmerauswahl, persönliche Einladungen und Begleitung. Keine externen Aufrufe. */
declare(strict_types=1);

function x25_curation_dir(): string
{
    $dir = defined('DATA_DIR') ? (string)DATA_DIR : dirname(__DIR__) . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) { throw new RuntimeException('Datenablage nicht erreichbar.'); }
    return rtrim($dir, '/');
}
function x25_curation_slug(string $slug): bool { return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,58}[a-z0-9]$/', $slug); }
function x25_private_json(string $file, array $data): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new RuntimeException('Speichern fehlgeschlagen.'); }
    chmod($tmp, 0600);
    if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Speichern fehlgeschlagen.'); }
}
function x25_invite_create(string $slug, string $email, string $reason): array
{
    $email = strtolower(trim($email));
    if (!x25_curation_slug($slug) || !filter_var($email, FILTER_VALIDATE_EMAIL) || trim($reason) === '') { throw new RuntimeException('Edition, geschäftliche E-Mail und persönliche Begründung angeben.'); }
    $token = bin2hex(random_bytes(24));
    $invite = ['slug'=>$slug, 'email'=>$email, 'reason'=>mb_substr(trim($reason),0,1200), 'created_at'=>gmdate('c'), 'expires_at'=>time()+30*86400];
    x25_private_json(x25_curation_dir().'/.einladung-'.hash('sha256',$token).'.json', $invite);
    return ['token'=>$token] + $invite;
}
function x25_invite_read(string $token, string $slug): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token) || !x25_curation_slug($slug)) { return null; }
    $file = x25_curation_dir().'/.einladung-'.hash('sha256',$token).'.json';
    if (!is_file($file)) { return null; }
    $data = json_decode((string)file_get_contents($file),true);
    if (!is_array($data) || ($data['slug']??'') !== $slug || ($data['expires_at']??0)<time() || !empty($data['revoked'])) { return null; }
    return $data;
}
function x25_review_due(?string $created = null): string
{
    $d = new DateTimeImmutable($created ?? 'now', new DateTimeZone('Europe/Berlin'));
    for ($n=0; $n<2;) { $d=$d->modify('+1 day'); if ((int)$d->format('N')<6) { $n++; } }
    return $d->setTime(18,0)->format(DATE_ATOM);
}
function x25_company_key(string $company): string { return mb_strtolower(preg_replace('/\s+/u',' ',trim($company)) ?? trim($company)); }
function x25_admission_limit(array $all, array $rec, int $max): string
{
    $slug=(string)($rec['edition_slug']??x25_default_slug()); $count=0; $company=0;
    foreach ($all as $row) {
        if ((int)($row['id']??0)===(int)($rec['id']??-1) || ($row['edition_slug']??x25_default_slug())!==$slug || ($row['status']??'')!=='zugelassen') { continue; }
        $count++;
        if (x25_company_key((string)($row['company']??''))===x25_company_key((string)($rec['company']??''))) { $company++; }
    }
    if ($count >= $max) { return 'Alle Teilnehmerplätze sind vergeben.'; }
    if ($company >= 2) { return 'Für dieses Unternehmen sind bereits zwei Teilnehmer zugelassen. Bitte die Unternehmenszuordnung prüfen.'; }
    return '';
}
/** Aufruf ausschließlich innerhalb der Store-Transaktion: Einladung kann nicht parallel doppelt genutzt werden. */
function x25_new_admission(array $all, array $rec, string $invitation, int $max): array
{
    $slug=(string)$rec['edition_slug'];
    foreach ($all as $row) {
        if (($row['edition_slug']??x25_default_slug())===$slug && strtolower((string)($row['email']??''))===$rec['email'] && ($row['status']??'')!=='abgesagt') {
            throw new InvalidArgumentException('Zu dieser E-Mail besteht bereits eine Anfrage für diese Edition. Bitte nutze Deine Bestätigung oder kontaktiere das Organisationsteam.');
        }
    }
    $rec['admission_mode']='persoenlich'; $rec['review_due_at']=x25_review_due();
    $rec['status']='pruefung'; $rec['admission_note']='Fachliche Passung und Zusammensetzung persönlich prüfen.';
    if ($invitation !== '') {
        $invite=x25_invite_read($invitation,$slug);
        if (!$invite || !hash_equals($invite['email'],$rec['email'])) { throw new InvalidArgumentException('Der Einladungslink ist abgelaufen oder gehört zu einer anderen E-Mail-Adresse. Bitte kontaktiere das Organisationsteam.'); }
        $hash=hash('sha256',$invitation);
        foreach($all as $row) { if (($row['invitation_hash']??'')===$hash) { throw new InvalidArgumentException('Diese Einladung wurde bereits verwendet. Bitte nutze Deine Bestätigung.'); } }
        $rec['invitation_hash']=$hash;
        $limit=x25_admission_limit($all,$rec,$max);
        $rec['status']=$limit===''?'zugelassen':'warteliste';
        $rec['admission_note']=$limit===''?'Persönliche Einladung; fachliche Prüfung durch den Veranstalter erfolgt.':$limit;
        $rec['decided_at']=gmdate('c'); $rec['decided_by']='persoenliche-einladung';
    }
    return $rec;
}
function x25_booking_required(array $rec): bool { return ($rec['admission_mode']??'')==='persoenlich' && empty($rec['booking_confirmed_at']); }
function x25_prepare_url(array $rec): string { return x25_conf()['base'].'vorbereitung.php?t='.rawurlencode((string)$rec['token']); }
function x25_curation_load(string $slug): array
{
    if (!x25_curation_slug($slug)) { return []; }
    $f=x25_curation_dir().'/.begleitung-'.$slug.'.json';
    $d=is_file($f)?json_decode((string)file_get_contents($f),true):[];
    return is_array($d)?$d:[];
}
