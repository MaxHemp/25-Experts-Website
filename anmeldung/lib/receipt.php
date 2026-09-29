<?php
/** Kurzlebiger, serverseitiger Eingangsbeleg; keine Personendaten in URLs. */
function x25_receipt_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('x25_receipt');
    session_start(['use_strict_mode'=>1,'cookie_lifetime'=>600,'gc_maxlifetime'=>600,'cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
}
function x25_receipt_save(array $rec): void {
    x25_receipt_session();
    $_SESSION['receipt']=['edition'=>(string)$rec['edition_slug'],'email'=>(string)$rec['email'],'status'=>(string)$rec['status'],'time'=>time()];
    session_write_close();
}
function x25_receipt_read(string $slug): ?array {
    if (empty($_COOKIE['x25_receipt'])) { return null; }
    x25_receipt_session();
    $r=$_SESSION['receipt']??null;
    if (!is_array($r) || ($r['edition']??'')!==$slug || (int)($r['time']??0)<time()-600) {$r=null;unset($_SESSION['receipt']);}
    session_write_close();
    return $r;
}
