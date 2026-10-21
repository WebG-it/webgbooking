<?php
// The preview of one email as the plugin makes it (HTML and the plain-text version), with sample data: php preview.php <key> <lang>
const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();
$plugin = $app->bootPlugin('webgbooking', 'system');
$p = $plugin->previewEmail($argv[1] ?? 'customer_confirmed', $argv[2] ?? 'en-GB', []);
echo "\n@@" . json_encode($p, JSON_UNESCAPED_UNICODE) . "@@\n";
