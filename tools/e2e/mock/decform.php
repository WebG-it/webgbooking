<?php
// decform.php <form-token> : prints the content of a form token (sealed v2 or old readable one) as JSON, after checking
// its signature with the site secret (the secret itself is never printed). Test kit only.
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
echo json_encode(\WebG\Plugin\System\Webgbooking\Extension\Webgbooking::openFormToken((string) $argv[1], (string) $app->get('secret'))) . "\n";
