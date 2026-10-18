<?php
// amounts.php <json list of inputs> : prints the strict amount parser's answer for each (cents, 0 = free, null = refused).
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
$out = [];
foreach (json_decode((string) file_get_contents($argv[1]), true) as $in) {
    $out[$in] = \WebG\Plugin\System\Webgbooking\Extension\Webgbooking::parseAmountStrict((string) $in);
}
echo json_encode($out) . "\n";
