<?php
// nocurl.php : run with `php -d disable_functions=curl_init nocurl.php`. Shows what the plugin does when curl is not usable:
// the pinned HTTP client is NOT built (no fall back to the stream transport). Test kit only.
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
$plugin = $app->bootPlugin('webgbooking', 'system');
$m = new ReflectionMethod($plugin, 'pinnedHttp');
$m->setAccessible(true);
$r = $m->invoke($plugin, ['host' => 'www.example.com', 'port' => 443, 'ip' => '93.184.216.34']);
echo json_encode(['curl_usable' => function_exists('curl_init'), 'client' => $r === null ? null : get_class($r)]) . "\n";
