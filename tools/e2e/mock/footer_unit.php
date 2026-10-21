<?php
// Runs the footer filter of the plugin over a list of texts (JSON file given as the first argument) and prints what each function returns.
const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;
use WebG\Plugin\System\Webgbooking\Booking\FooterText;

$c = Factory::getContainer();
$c->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $c->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();

$in  = json_decode((string) file_get_contents($argv[1]), true);
$out = [];
foreach ($in as $i => $s) {
    $out[$i] = [
        'rich'     => FooterText::isRich($s),
        'clean'    => FooterText::sanitize($s),
        'again'    => FooterText::sanitize(FooterText::sanitize($s)),
        'text'     => FooterText::toText($s),
        'inline'   => FooterText::inline($s, 'class="f-link" style="color:#fff;text-decoration:underline;"', static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        'privacy'  => FooterText::privacyHref($s),
        'editor'   => FooterText::toEditorHtml($s),
    ];
}
echo "\n@@" . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "@@\n";
