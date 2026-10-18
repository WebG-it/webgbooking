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
} elseif (($argv[1] ?? '') === 'cidr') {
    $out = $cls::ipInCidr((string) $argv[2], (string) $argv[3]);
}
echo json_encode($out) . "\n";
