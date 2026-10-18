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
$reqh = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$log = ['t' => date('c'), 'host' => $host, 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $json ?? $body, 'headers' => $reqh];
file_put_contents("$dir/log.jsonl", json_encode($log) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$gfail = file_exists("$dir/google_fail") ? trim(file_get_contents("$dir/google_fail")) : '';
if ($host === 'oauth2.googleapis.com' && $gfail === 'token') { http_response_code(400); echo json_encode(['error' => 'invalid_grant']); exit; }
if ($host === 'www.googleapis.com' && $gfail === 'freebusy' && $path === '/calendar/v3/freeBusy') { http_response_code(500); echo json_encode(['error' => ['message' => 'backend error']]); exit; }
if ($host === 'www.googleapis.com' && $gfail === 'events' && strpos($path, '/events') !== false && $method !== 'DELETE') { http_response_code(500); echo json_encode(['error' => ['message' => 'backend error']]); exit; }

if ($host === 'oauth2.googleapis.com') {
    parse_str($body, $tf);
    if (($tf['grant_type'] ?? '') === 'authorization_code') {            // consent finished: hand out a refresh token
        if (($tf['code'] ?? '') !== 'goodcode') { http_response_code(400); echo json_encode(['error' => 'invalid_grant']); exit; }
        $jwt = 'e30.' . rtrim(strtr(base64_encode(json_encode(['email' => 'reconnected@wgbtest.invalid'])), '+/', '-_'), '=') . '.sig';
        echo json_encode(['access_token' => 'mock-access-token', 'refresh_token' => 'mock-refresh-token', 'id_token' => $jwt, 'expires_in' => 3600]);
        exit;
    }
    if (($tf['refresh_token'] ?? '') === 'mock-revoked') { http_response_code(400); echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']); exit; }
    echo json_encode(['access_token' => 'mock-access-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    exit;
}
if ($host === 'sheets.googleapis.com') {
    if ($method === 'GET') { echo json_encode(['sheets' => [['properties' => ['title' => 'Prenotazioni']], ['properties' => ['title' => 'Archivio']]]]); exit; }
    echo json_encode(['updates' => ['updatedRows' => 1]]);
    exit;
}
if ($host === 'www.googleapis.com' && strpos($path, '/drive/') === 0) {
    http_response_code(403); echo json_encode(['error' => ['message' => 'Drive API has not been used in project 100455972354 before or it is disabled.']]); exit;
}
if ($host === 'www.googleapis.com' && $path === '/calendar/v3/users/me/calendarList') {
    if ($gfail === 'calendarlist') { http_response_code(403); echo json_encode(['error' => ['message' => 'Google Calendar API has not been used in project 100455972354 before or it is disabled.']]); exit; }
    echo json_encode(['items' => [['id' => 'primary', 'summary' => 'Marco', 'primary' => true], ['id' => 'altro@example.invalid', 'summary' => 'Altro']]]);
    exit;
}
if ($host === 'www.googleapis.com' && $path === '/hook') {                // a customer's webhook receiver (test)
    $hf = file_exists("$dir/hook_fail") ? (int) trim(file_get_contents("$dir/hook_fail")) : 0;
    if ($hf > 0) { http_response_code($hf); echo json_encode(['error' => 'mock hook failing']); exit; }
    echo json_encode(['ok' => true]); exit;
}
if ($host === 'api.brevo.com') {                                              // Brevo API (contacts, double opt-in, account)
    $bf = "$dir/brevo.json";
    $bs = file_exists($bf) ? json_decode(file_get_contents($bf), true) : [];
    if (!is_array($bs)) { $bs = []; }
    $bs += ['contacts' => [], 'doi' => []];
    $bfail = file_exists("$dir/brevo_fail") ? trim(file_get_contents("$dir/brevo_fail")) : '';
    $bwant = file_exists("$dir/brevo_key") ? trim(file_get_contents("$dir/brevo_key")) : '';
    $bout = function ($code, $arr) { http_response_code($code); echo json_encode($arr); exit; };
    if ($path === '/__mock/state') { $bout(200, $bs); }
    if ($bwant === '' || ($reqh['api-key'] ?? '') !== $bwant) { $bout(401, ['message' => 'Key not found', 'code' => 'unauthorized']); }
    if ($bfail === '500') { $bout(500, ['message' => 'internal error', 'code' => 'server_error']); }
    if ($bfail === '429') { $bout(429, ['message' => 'Too many requests', 'code' => 'too_many_requests']); }
    $allowed = file_exists("$dir/brevo_attrs") ? array_filter(array_map('trim', explode(',', file_get_contents("$dir/brevo_attrs")))) : ['NOME', 'COGNOME', 'SMS', 'CITTA', 'PROGETTO'];
    $lists = [7, 12];
    $chk = function ($attrs, $ids) use ($allowed, $lists, $bout) {
        foreach ((array) $attrs as $k => $v) { if (!in_array($k, $allowed, true)) { $bout(400, ['code' => 'invalid_parameter', 'message' => 'Invalid attributes: ' . $k . ' does not exist']); } }
        foreach ((array) $ids as $i) { if (!in_array((int) $i, $lists, true)) { $bout(400, ['code' => 'invalid_parameter', 'message' => 'Invalid list id ' . (int) $i]); } }
    };
    if ($path === '/v3/account' && $method === 'GET') { $bout(200, ['email' => 'owner@brevo.test', 'companyName' => 'WebG Mock']); }
    if ($path === '/v3/contacts' && $method === 'POST') {
        $em = strtolower(trim((string) ($json['email'] ?? '')));
        if ($em === '') { $bout(400, ['code' => 'missing_parameter', 'message' => 'email is required']); }
        $chk($json['attributes'] ?? [], $json['listIds'] ?? []);
        $exists = isset($bs['contacts'][$em]);
        if ($exists && empty($json['updateEnabled'])) { $bout(400, ['code' => 'duplicate_parameter', 'message' => 'Contact already exist']); }
        $c = $bs['contacts'][$em] ?? ['attributes' => [], 'listIds' => []];
        $c['attributes'] = array_merge($c['attributes'], (array) ($json['attributes'] ?? []));
        $c['listIds'] = array_values(array_unique(array_merge($c['listIds'], array_map('intval', (array) ($json['listIds'] ?? [])))));
        $c['updates'] = ($c['updates'] ?? 0) + 1;
        $bs['contacts'][$em] = $c;
        file_put_contents($bf, json_encode($bs));
        if ($exists) { http_response_code(204); exit; }
        $bout(201, ['id' => count($bs['contacts'])]);
    }
    if ($path === '/v3/contacts/doubleOptinConfirmation' && $method === 'POST') {
        $em = strtolower(trim((string) ($json['email'] ?? '')));
        if ($em === '' || empty($json['templateId']) || empty($json['redirectionUrl']) || empty($json['includeListIds'])) { $bout(400, ['code' => 'missing_parameter', 'message' => 'email, templateId, redirectionUrl and includeListIds are required']); }
        $chk($json['attributes'] ?? [], $json['includeListIds']);
        $bs['doi'][] = ['email' => $em, 'attributes' => (array) ($json['attributes'] ?? []), 'lists' => $json['includeListIds'], 'templateId' => $json['templateId'], 'redirectionUrl' => $json['redirectionUrl']];
        file_put_contents($bf, json_encode($bs));
        http_response_code(201); echo '{}'; exit;
    }
    $bout(404, ['message' => 'mock brevo: not handled', 'code' => 'document_not_found']);
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
            if (isset($q['live'])) { $sess[$id]['livemode'] = true; }
            if (isset($q['nolive'])) { unset($sess[$id]['livemode']); }
            $save(); echo json_encode($sess[$id]); exit;
        }
        $err(404, 'mock control: unknown');
    }
    $keyfile = "$dir/stripe_key";
    $want = file_exists($keyfile) ? trim(file_get_contents($keyfile)) : '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($hdrs['Authorization'] ?? '');
    if ($want === '' || $auth !== 'Bearer ' . $want) { $err(401, 'Invalid API Key provided'); }
    if ($path === '/v1/account' && $method === 'GET') {
        if (file_exists("$dir/stripe_restricted")) { $err(403, 'This API call cannot be made with a restricted key (permission).'); }
        echo json_encode(['id' => 'acct_mockaccount']); exit;
    }
    if ($path === '/v1/checkout/sessions' && $method === 'GET') {
        if (file_exists("$dir/stripe_nolist")) { $err(403, 'The provided key does not have the required permissions.'); }
        echo json_encode(['object' => 'list', 'data' => array_slice(array_values($sess), 0, 1)]); exit;
    }
    if ($path === '/v1/checkout/sessions' && $method === 'POST') {
        parse_str($body, $f);
        $li = $f['line_items'][0]['price_data'] ?? [];
        if (($f['mode'] ?? '') !== 'payment' || empty($li['unit_amount']) || empty($li['currency']) || empty($f['success_url'])) { $err(400, 'Missing required param'); }
        $exp = (int) ($f['expires_at'] ?? 0);
        if ($exp < time() + 30 * 60 || $exp > time() + 86400) { $err(400, 'The `expires_at` timestamp must be between 30 minutes and 24 hours from now.'); }
        $id = 'cs_test_mock' . bin2hex(random_bytes(6));          // random: ids are never reused between runs
        $sess[$id] = ['id' => $id, 'object' => 'checkout.session', 'amount_total' => (int) $li['unit_amount'], 'currency' => $li['currency'],
            'payment_status' => 'unpaid', 'status' => 'open', 'livemode' => false, 'url' => 'https://checkout.stripe.test/c/pay/' . $id,
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
    if ($method === 'GET' && $id !== '') {
        if (!isset($events[$id])) { http_response_code(404); echo '{"error":"notFound"}'; exit; }
        echo json_encode($events[$id]);
        exit;
    }
    if ($method === 'POST') {
        $ev = (array) $json;
        if (!empty($ev['id'])) {                                   // client-chosen id: insert is idempotent
            if (!preg_match('/^[a-v0-9]{5,1024}$/', (string) $ev['id'])) { http_response_code(400); echo '{"error":"invalid id"}'; exit; }
            if (isset($events[$ev['id']])) { http_response_code(409); echo '{"error":{"code":409,"message":"The requested identifier already exists."}}'; exit; }
            $id = (string) $ev['id'];
        } else {
            $id = 'mockevt' . bin2hex(random_bytes(5));
        }
        $ev['id'] = $id;
        $resp = ['id' => $id];
        if (!empty($json['conferenceData']['createRequest'])) {
            $resp['hangoutLink'] = 'https://meet.google.com/mock-' . $id;
            $ev['hangoutLink'] = $resp['hangoutLink'];
        }
        $events[$id] = $ev;
        file_put_contents("$dir/events.json", json_encode($events));
        if ($gfail === 'events_lost') { http_response_code(504); exit; }   // created, but the answer never reaches the caller
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
