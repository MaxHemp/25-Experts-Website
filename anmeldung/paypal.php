<?php
/**
 * 25 EXPERTS – PayPal-Server-Endpunkt (PayPal REST API v2, Orders).
 * POST JSON von zahlung.js:
 *   {action:"create",  t:TOKEN}                → legt die Order serverseitig an (Betrag aus config, nie aus dem Browser) → {ok,orderID}
 *   {action:"capture", t:TOKEN, orderID:"…"}   → captured die Order, prüft Status COMPLETED, Betrag, Währung und
 *                                                 dass die Order-ID zu dieser Anmeldung gehört → verbucht Zahlung, Ticket-Mail → {ok,ticket}
 * Zugangsdaten: PAYPAL_ENV (sandbox|live), PAYPAL_CLIENT_ID, PAYPAL_SECRET (config.php). PAYPAL_API_BASE nur für Tests (Mock).
 * Es werden keine Kartendaten verarbeitet; PayPal übernimmt die Zahlung, hier laufen nur Order-IDs.
 */
declare(strict_types=1);

require __DIR__ . '/lib/flow.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { x25_json(['ok' => false, 'error' => 'Nur POST.'], 405); }
$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
if (!is_array($in)) { x25_json(['ok' => false, 'error' => 'Ungültige Anfrage.'], 400); }

$C = x25_conf();
$env = $C['paypal_env'];
if (!in_array($env, ['sandbox', 'live'], true) || $C['paypal_client'] === '' || (string)x25_cfg('PAYPAL_SECRET', '') === '') {
    x25_json(['ok' => false, 'error' => 'PayPal ist nicht konfiguriert.'], 503);
}
$t = (string)($in['t'] ?? '');
$rec = preg_match('/^[a-f0-9]{32}$/', $t) ? x25_store()->findByToken($t) : null;
if ($rec === null || $rec['status'] !== 'zugelassen') { x25_json(['ok' => false, 'error' => 'Für diese Anmeldung ist keine Zahlung offen.'], 404); }
$A = x25_amounts($rec); $ED = x25_edition_for($rec);
if ($rec['payment_status'] === 'bezahlt') { x25_json(['ok' => true, 'ticket' => $rec['ticket_no'] ?? '', 'already' => true]); }

$action = (string)($in['action'] ?? '');
try {
    if ($action === 'create') {
        $reqId = 'x25-' . $rec['id'] . '-' . x25_token(6);
        $order = x25_paypal_call('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'anmeldung-' . $rec['id'],
                'custom_id' => (string)$rec['id'],
                'description' => mb_substr('Teilnahme ' . $ED['name'] . ', ' . $ED['leistungsdatum'] . ', ' . $ED['ort'], 0, 127),
                'amount' => ['currency_code' => $A['currency'], 'value' => x25_money_api($A['gross']),
                    'breakdown' => ['item_total' => ['currency_code' => $A['currency'], 'value' => x25_money_api($A['net'])], 'tax_total' => ['currency_code' => $A['currency'], 'value' => x25_money_api($A['vat'])]]],
            ]],
            'application_context' => ['brand_name' => '25 EXPERTS', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW', 'locale' => 'de-DE'],
        ], ['PayPal-Request-Id: ' . $reqId]);
        $orderId = (string)($order['id'] ?? '');
        if ($orderId === '') { throw new RuntimeException('Order ohne ID'); }
        x25_store()->update((int)$rec['id'], ['paypal_order_id' => $orderId, 'paypal_order_at' => gmdate('c')]);
        x25_json(['ok' => true, 'orderID' => $orderId]);
    }
    if ($action === 'capture') {
        $orderId = (string)($in['orderID'] ?? '');
        // Die Order muss von uns für genau diese Anmeldung angelegt worden sein (kein Fremd-Capture)
        if ($orderId === '' || !preg_match('/^[A-Za-z0-9_-]{5,64}$/', $orderId) || $orderId !== (string)($rec['paypal_order_id'] ?? '')) {
            x25_json(['ok' => false, 'error' => 'Die PayPal-Order passt nicht zu dieser Anmeldung.'], 409);
        }
        $cap = x25_paypal_call('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', new stdClass(), ['PayPal-Request-Id: cap-' . $orderId]);
        $capture = $cap['purchase_units'][0]['payments']['captures'][0] ?? null;
        $status = (string)($cap['status'] ?? '');
        $cStatus = (string)($capture['status'] ?? '');
        $val = (string)($capture['amount']['value'] ?? '');
        $cur = (string)($capture['amount']['currency_code'] ?? '');
        $okAmount = $val !== '' && abs((float)$val - $A['gross']) < 0.005 && $cur === $A['currency'];
        if (($cap['id'] ?? '') !== $orderId || $status !== 'COMPLETED' || $cStatus !== 'COMPLETED' || !$okAmount) {
            x25_log('paypal capture abgelehnt: status=' . $status . '/' . $cStatus . ' betrag=' . $val . ' ' . $cur);
            x25_json(['ok' => false, 'error' => 'Die Zahlung wurde von PayPal nicht bestätigt (Status ' . ($cStatus ?: $status ?: 'unbekannt') . '). Bitte prüfen Sie Ihr PayPal-Konto oder zahlen Sie per Rechnung.'], 402);
        }
        $rec = x25_mark_paid($rec, 'paypal', 'paypal', ['paypal_capture_id' => (string)($capture['id'] ?? ''), 'paypal_amount' => $val . ' ' . $cur]);
        x25_json(['ok' => true, 'ticket' => $rec['ticket_no']]);
    }
    x25_json(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (Throwable $e) {
    x25_log('paypal ' . $action . ' Fehler: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 200));
    x25_json(['ok' => false, 'error' => 'PayPal ist gerade nicht erreichbar. Bitte versuchen Sie es erneut oder zahlen Sie per Rechnung.'], 502);
}

// ================================================================== PayPal REST
function x25_paypal_base(): string
{
    $override = (string)x25_cfg('PAYPAL_API_BASE', '');
    if ($override !== '') { return rtrim($override, '/'); }
    return x25_conf()['paypal_env'] === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}
/** OAuth2-Token (client_credentials); kurz gecacht in sys_get_temp_dir(), ohne Secret im Dateinamen/Inhalt außer dem Token selbst. */
function x25_paypal_token(): string
{
    $client = (string)x25_cfg('PAYPAL_CLIENT_ID', ''); $secret = (string)x25_cfg('PAYPAL_SECRET', '');
    $cache = rtrim(sys_get_temp_dir(), '/') . '/25x-pp-' . hash('sha256', $client . '|' . x25_paypal_base()) . '.json';
    if (is_file($cache)) {
        $j = json_decode((string)file_get_contents($cache), true);
        if (is_array($j) && ($j['exp'] ?? 0) > time() + 60 && !empty($j['tok'])) { return (string)$j['tok']; }
    }
    $r = x25_http('POST', x25_paypal_base() . '/v1/oauth2/token', 'grant_type=client_credentials',
        ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic ' . base64_encode($client . ':' . $secret)]);
    $tok = (string)($r['access_token'] ?? '');
    if ($tok === '') { throw new RuntimeException('kein access_token'); }
    @file_put_contents($cache, json_encode(['tok' => $tok, 'exp' => time() + (int)($r['expires_in'] ?? 300)]));
    @chmod($cache, 0600);
    return $tok;
}
function x25_paypal_call(string $method, string $path, $body, array $headers = []): array
{
    $tok = x25_paypal_token();
    return x25_http($method, x25_paypal_base() . $path, json_encode($body, JSON_UNESCAPED_SLASHES), array_merge(['Content-Type: application/json', 'Authorization: Bearer ' . $tok, 'Prefer: return=representation'], $headers));
}
/** HTTP-Aufruf per cURL; wirft bei Netz-/HTTP-Fehler (Fehlertext ohne Zugangsdaten). */
function x25_http(string $method, string $url, string $body, array $headers): array
{
    if (!function_exists('curl_init')) { throw new RuntimeException('cURL fehlt'); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) { throw new RuntimeException('cURL: ' . $err); }
    $j = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ' ' . substr(is_array($j) ? (string)($j['name'] ?? $j['error'] ?? '') : '', 0, 60));
    }
    return is_array($j) ? $j : [];
}
