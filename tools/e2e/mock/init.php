<?php
// Test-container init: plugin options + fake Google connection (encrypted refresh token like the plugin does).
const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$db = $container->get(DatabaseInterface::class);

$opts = [
    'work_start' => '09:00', 'work_end' => '18:00', 'work_days' => ['0', '1', '2', '3', '4', '5', '6'],
    'slot_interval' => '30', 'buffer_before' => '0', 'buffer_after' => '0', 'min_notice' => '0', 'window_days' => '30',
    'notify_email' => 'staff@wgbtest.invalid', 'slot_duration' => '30',
    'google_client_id' => 'mock-client-id', 'google_client_secret' => 'mock-client-secret', 'google_calendar_id' => 'primary',
    'reminders_enabled' => '1', 'reminder_times' => '24h, 1h',
    'webhook_allow_private' => '1',     // test kit only: the mock receiver lives on 127.0.0.1 (the SSRF tests switch it off)
    'limit_ip_hour' => '1000', 'max_holds_ip' => '50', 'max_open_holds' => '500', 'config_epoch' => '1', 'config_ttl_days' => '30',
];
foreach ($argv as $i => $a) {
    if ($i > 0 && strpos($a, '=') !== false) {
        [$k, $v] = explode('=', $a, 2);
        $opts[$k] = $v;
    }
}
$encKey = hash('sha256', (string) $app->get('secret'), true);
foreach (['whp1_headers', 'whp2_headers', 'whp3_headers', 'whp4_headers'] as $k) {
    if (isset($opts[$k])) { $opts[$k] = str_replace('\\n', "\n", $opts[$k]); }      // "A: 1\nB: 2" on the command line
}
foreach (['stripe_sk', 'stripe_whsec', 'brevo_key', 'whp1_headers', 'whp2_headers', 'whp3_headers', 'whp4_headers'] as $k) {
    if (!empty($opts[$k]) && strncmp($opts[$k], 'g:', 2) !== 0) {
        $iv0 = random_bytes(12); $tag0 = '';
        $ct0 = openssl_encrypt($opts[$k], 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv0, $tag0);
        $opts[$k] = 'g:' . base64_encode($iv0 . $tag0 . $ct0);
    }
}
$refreshPlain = (string) ($opts['google_refresh'] ?? 'mock-refresh-token');
unset($opts['google_refresh']);
// 0.36.0: the settings are the Options of the component. The plugin row is kept EMPTY on purpose, so a test passes only if the
// code reads the component. 'row=plugin' (an old release: the options were the plugin's) writes the plugin row instead.
$toPlugin = ($opts['row'] ?? '') === 'plugin';
unset($opts['row']);
$setRow = function (string $where, array $p) use ($db) {
    $db->setQuery($db->getQuery(true)->update('#__extensions')->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($p ?: new stdClass())))->where($where))->execute();
};
if ($toPlugin) {
    $setRow("type='plugin' AND element='webgbooking' AND folder='system'", $opts);
} else {
    $setRow("type='component' AND element='com_webgbooking'", $opts);
    $setRow("type='plugin' AND element='webgbooking' AND folder='system'", []);
}

$secret = (string) $app->get('secret');
$key = hash('sha256', $secret, true);
$iv = random_bytes(12);
$tag = '';
$ct = openssl_encrypt($refreshPlain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
$enc = 'g:' . base64_encode($iv . $tag . $ct);

$db->setQuery('DELETE FROM ' . $db->quoteName('#__webgbooking_google'))->execute();
$g = (object) ['refresh_token' => $enc, 'account_email' => 'owner@wgbtest.invalid', 'calendar_id' => 'primary', 'read_calendars' => '', 'sheet_id' => '', 'sheet_tab' => '', 'oauth_state' => '', 'created' => date('Y-m-d H:i:s'), 'updated' => date('Y-m-d H:i:s')];
$db->insertObject('#__webgbooking_google', $g);
echo "init ok\n";
