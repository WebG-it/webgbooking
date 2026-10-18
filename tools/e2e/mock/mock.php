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
