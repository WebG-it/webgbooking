<?php
// Mock of the Google endpoints WebG Booking uses (oauth2 token, Calendar freeBusy/events, Sheets append).
// State in /tmp/gmock: events.json (current events), log.jsonl (every request), busy.json (freeBusy answer).
$dir = '/tmp/gmock';
@mkdir($dir, 0777, true);
$host   = strtolower($_SERVER['HTTP_HOST'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'];
$path   = parse_url($uri, PHP_URL_PATH);
$query  = (string) parse_url($uri, PHP_URL_QUERY);
$body   = file_get_contents('php://input');
$json   = json_decode($body, true);
$events = file_exists("$dir/events.json") ? json_decode(file_get_contents("$dir/events.json"), true) : [];
if (!is_array($events)) { $events = []; }
$seqf = "$dir/seq";
$log = ['t' => date('c'), 'host' => $host, 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $json ?? $body];
file_put_contents("$dir/log.jsonl", json_encode($log) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if ($host === 'oauth2.googleapis.com') {
    echo json_encode(['access_token' => 'mock-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    exit;
}
if ($host === 'sheets.googleapis.com') {
    echo json_encode(['updates' => ['updatedRows' => 1]]);
    exit;
}
if ($host === 'api.stripe.com') {
    $sf = "$dir/stripe.json";
    $sess = file_exists($sf) ? json_decode(file_get_contents($sf), true) : [];
    if (!is_array($sess)) { $sess = []; }
    $save = function () use (&$sess, $sf) { file_put_contents($sf, json_encode($sess)); };
    $err = function ($code, $msg) { http_response_code($code); echo json_encode(['error' => ['message' => $msg, 'type' => 'invalid_request_error']]); exit; };
    if (strpos($path, '/__mock/') === 0) {            // test control: simulate the customer paying
        parse_str($query, $q);
        $id = $q['id'] ?? '';
        if ($path === '/__mock/pay' && isset($sess[$id])) {
            $sess[$id]['payment_status'] = 'paid'; $sess[$id]['status'] = 'complete';
            $sess[$id]['payment_intent'] = 'pi_mock_' . substr($id, -6);
            if (isset($q['amount'])) { $sess[$id]['amount_total'] = (int) $q['amount']; }
            $save(); echo json_encode($sess[$id]); exit;
        }
        $err(404, 'mock control: unknown');
    }
    $keyfile = "$dir/stripe_key";
    $want = file_exists($keyfile) ? trim(file_get_contents($keyfile)) : '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($hdrs['Authorization'] ?? '');
    if ($want === '' || $auth !== 'Bearer ' . $want) { $err(401, 'Invalid API Key provided'); }
    if ($path === '/v1/account' && $method === 'GET') { echo json_encode(['id' => 'acct_mockaccount']); exit; }
    if ($path === '/v1/checkout/sessions' && $method === 'POST') {
        parse_str($body, $f);
        $li = $f['line_items'][0]['price_data'] ?? [];
        if (($f['mode'] ?? '') !== 'payment' || empty($li['unit_amount']) || empty($li['currency']) || empty($f['success_url'])) { $err(400, 'Missing required param'); }
        $exp = (int) ($f['expires_at'] ?? 0);
        if ($exp < time() + 30 * 60 || $exp > time() + 86400) { $err(400, 'The `expires_at` timestamp must be between 30 minutes and 24 hours from now.'); }
        $n = count($sess) + 1;
        $id = 'cs_test_mock' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        $sess[$id] = ['id' => $id, 'object' => 'checkout.session', 'amount_total' => (int) $li['unit_amount'], 'currency' => $li['currency'],
            'payment_status' => 'unpaid', 'status' => 'open', 'url' => 'https://checkout.stripe.test/c/pay/' . $id,
            'client_reference_id' => $f['client_reference_id'] ?? null, 'customer_email' => $f['customer_email'] ?? null,
            'metadata' => $f['metadata'] ?? [], 'payment_intent' => null, 'expires_at' => $exp, 'success_url' => $f['success_url'], 'cancel_url' => $f['cancel_url'] ?? '',
            'receipt_email' => $f['payment_intent_data']['receipt_email'] ?? null, 'product_name' => $li['product_data']['name'] ?? ''];
        $save(); echo json_encode($sess[$id]); exit;
    }
    if (preg_match('#^/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)(/expire)?$#', $path, $m)) {
        $id = $m[1];
        if (!isset($sess[$id])) { $err(404, 'No such checkout.session'); }
        if (!empty($m[2])) {
            if ($sess[$id]['status'] !== 'open') { $err(400, 'Only Checkout Sessions with a status in open can be expired.'); }
            $sess[$id]['status'] = 'expired'; $save(); echo json_encode($sess[$id]); exit;
        }
        echo json_encode($sess[$id]); exit;
    }
    $err(404, 'mock stripe: not handled');
}
// calendar
if ($path === '/calendar/v3/freeBusy') {
    $busy = file_exists("$dir/busy.json") ? json_decode(file_get_contents("$dir/busy.json"), true) : [];
    $items = [];
    foreach ((array) $busy as $b) { $items[] = ['start' => $b[0], 'end' => $b[1]]; }
    echo json_encode(['calendars' => ['primary' => ['busy' => $items]]]);
    exit;
}
if (preg_match('#^/calendar/v3/calendars/([^/]+)/events(?:/([^/]+))?$#', $path, $m)) {
    $id = $m[2] ?? '';
    if ($method === 'POST') {
        $n = (int) @file_get_contents($seqf) + 1;
        file_put_contents($seqf, (string) $n);
        $id = 'mockevt' . $n;
        $ev = (array) $json;
        $ev['id'] = $id;
        $resp = ['id' => $id];
        if (!empty($json['conferenceData']['createRequest'])) {
            $resp['hangoutLink'] = 'https://meet.google.com/mock-' . $id;
            $ev['hangoutLink'] = $resp['hangoutLink'];
        }
        $events[$id] = $ev;
        file_put_contents("$dir/events.json", json_encode($events));
        echo json_encode($resp);
        exit;
    }
    if ($method === 'PATCH' && $id !== '') {
        if (!isset($events[$id])) { http_response_code(404); echo '{"error":"notFound"}'; exit; }
        $events[$id] = array_merge($events[$id], (array) $json);
        file_put_contents("$dir/events.json", json_encode($events));
        echo json_encode($events[$id]);
        exit;
    }
    if ($method === 'DELETE' && $id !== '') {
        if (!isset($events[$id])) { http_response_code(410); echo '{"error":"gone"}'; exit; }
        unset($events[$id]);
        file_put_contents("$dir/events.json", json_encode($events));
        http_response_code(204);
        exit;
    }
}
http_response_code(404);
echo json_encode(['error' => 'mock: not handled', 'path' => $path]);
