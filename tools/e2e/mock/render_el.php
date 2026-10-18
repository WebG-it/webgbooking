<?php
// Renders the REAL element template (template.php) for a set of test configs and writes static pages
// (served by the test Joomla, same origin as com_ajax) that load the real wgb.js / wgb.css / UIkit.
const _JEXEC = 1;
$_SERVER['HTTP_HOST'] = 'localhost:8086'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SERVER_PORT'] = '8086';
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\Console\Application::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();
// the plugin language, in the site default language
$lang = $app->getLanguage();
$lang->load('plg_system_webgbooking', JPATH_ADMINISTRATOR);
$lang->load('plg_system_webgbooking', JPATH_PLUGINS . '/system/webgbooking');

class StubBuilder
{
    public function el($tag, $attrs = [])
    {
        return function ($props = [], $a = []) use ($tag, $attrs) {
            $cls = [];
            foreach ((array) ($attrs['class'] ?? []) as $c) { if ($c) { $cls[] = $c; } }
            return '<' . $tag . ' class="' . htmlspecialchars(implode(' ', $cls)) . '">';
        };
    }
}

function render_element(array $props): string
{
    $stub = new StubBuilder();
    $fn = function () use ($props) {
        $attrs = []; $children = []; $builder = null;
        ob_start();
        include '/var/www/html/plugins/system/webgbooking/yootheme/elements/booking/templates/template.php';
        return ob_get_clean();
    };
    return \Closure::bind($fn, $stub, StubBuilder::class)();
}

$configs = json_decode(file_get_contents($argv[1] ?? '/tmp/mock/configs.json'), true) ?: [];
@mkdir('/var/www/html/wgbtest', 0777, true);
foreach ($configs as $name => $props) {
    $GLOBALS['__wgbAssets'] = false;
    if (isset($props['_multi'])) {
        $html = '';
        foreach ($props['_multi'] as $one) { $html .= '<div class="uk-margin-bottom">' . render_element($one) . '</div>'; }
    } else {
        $html = render_element($props);
    }
    $assets = '/plugins/system/webgbooking/yootheme/elements/booking/assets';
    $page = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>wgbtest ' . $name . '</title>'
        . '<link rel="stylesheet" href="' . $assets . '/uikit/css/uikit.min.css"><script src="' . $assets . '/uikit/js/uikit.min.js"></script><script src="' . $assets . '/uikit/js/uikit-icons.min.js"></script>'
        . '</head><body><div class="uk-container uk-margin-top" style="max-width:520px">' . $html . '</div></body></html>';
    file_put_contents('/var/www/html/wgbtest/' . $name . '.html', $page);
    echo "wrote $name\n";
}
