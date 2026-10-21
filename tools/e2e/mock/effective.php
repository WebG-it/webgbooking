<?php
// Prints the settings as the code reads them: from Settings::all() and from the running plugin ($this->params), for the keys given.
const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;
use WebG\Plugin\System\Webgbooking\Booking\Settings;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();
$plugin = $app->bootPlugin('webgbooking', 'system');
$ref = new ReflectionProperty($plugin, 'params');
$ref->setAccessible(true);
$pp = $ref->getValue($plugin);
$res = ['all' => [], 'plugin' => []];
foreach (array_slice($argv, 1) as $k) {
    $res['all'][$k]    = Settings::all()->get($k, null);
    $res['plugin'][$k] = $pp->get($k, null);
}
echo "\n@@" . json_encode($res, JSON_UNESCAPED_UNICODE) . "@@\n";
