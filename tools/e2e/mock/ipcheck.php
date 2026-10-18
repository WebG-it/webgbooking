<?php
// ipcheck.php public <ip>... | cidr <ip> <cidr> : runs the plugin's own address checks (test kit only).
const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;

$c = Factory::getContainer();
$c->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $c->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();
$cls = '\\WebG\\Plugin\\System\\Webgbooking\\Extension\\Webgbooking';
$out = [];
if (($argv[1] ?? '') === 'public') {
    foreach (array_slice($argv, 2) as $ip) { $out[$ip] = $cls::ipIsPublic($ip); }
} elseif (($argv[1] ?? '') === 'url') {
    foreach (array_slice($argv, 2) as $u) { $out[$u] = $cls::httpUrlOk($u); }
} elseif (($argv[1] ?? '') === 'diag') {          // diag <remote> <cf-header|-> ...
    $plugin = $app->bootPlugin('webgbooking', 'system');
    $a = array_slice($argv, 2);
    for ($i = 0; $i + 1 < count($a); $i += 2) { $out[$a[$i] . '|' . $a[$i + 1]] = $plugin->ipDiagnosis($a[$i], $a[$i + 1] === '-' ? '' : $a[$i + 1]); }
} elseif (($argv[1] ?? '') === 'norm') {
    foreach (array_slice($argv, 2) as $ip) { $out[$ip] = $cls::normalizeIp($ip); }
} elseif (($argv[1] ?? '') === 'cidr') {
    $out = $cls::ipInCidr((string) $argv[2], (string) $argv[3]);
}
echo json_encode($out) . "\n";
