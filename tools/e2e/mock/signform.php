<?php
// signform.php <payload-json> : prints a form token (payload.base64 + HMAC) signed with the site secret,
// without ever printing the secret. Used to craft an OLD token (iat in the past) for the lifetime test.
const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;

$c = Factory::getContainer();
$c->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $c->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$payload = base64_encode((string) $argv[1]);
echo $payload . '.' . hash_hmac('sha256', $payload, (string) $app->get('secret')) . "\n";
