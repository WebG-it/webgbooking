<?php
// elcheck.php <lang-tag> | ini : (1) the YOOtheme element configuration read in a given language: every label comes from the
// language files (no unresolved key, no English text left in Italian); (2) every language file of the package parses with
// Joomla's own parser, the same keys in both languages. Test kit only.
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
$mode = $argv[1] ?? 'en-GB';
$out = [];
if ($mode === 'ini') {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/var/www/html', FilesystemIterator::SKIP_DOTS)) as $f) {
        $p = str_replace(chr(92), '/', (string) $f);
        if (preg_match('#/language/(en-GB|it-IT)/[^/]*(webgbooking|WEBGBOOKING)[^/]*\.ini$#i', $p, $m)) {
            $files[basename($p)][$m[1]] = $p;
        }
    }
    foreach ($files as $name => $per) {
        $row = ['langs' => array_keys($per)];
        foreach ($per as $lang => $p) {
            $k = \Joomla\CMS\Language\LanguageHelper::parseIniFile($p);
            $row[$lang] = count($k);
            $row[$lang . '_keys'] = array_keys($k);
            if ($lang === 'en-GB' && isset($k['PLG_SYSTEM_WEBGBOOKING_WHP_DESC'])) {
                $row['quote_ok'] = strpos($k['PLG_SYSTEM_WEBGBOOKING_WHP_DESC'], '"Header: value"') !== false || strpos($k['PLG_SYSTEM_WEBGBOOKING_WHP_DESC'], '"') !== false;
            }
        }
        $out[$name] = $row;
    }
    echo json_encode($out) . "\n";
    exit;
}
if ($mode !== 'en-GB') {                      // another language: the language object Joomla's Text reads
    $lang = $c->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage($mode, false);
    $lang->load('plg_system_webgbooking', JPATH_PLUGINS . '/system/webgbooking');
    Factory::$language = $lang;
}
// en-GB: nothing is preloaded, so element.php's own language loading is what makes the labels resolve
$cfg = include '/var/www/html/plugins/system/webgbooking/yootheme/elements/booking/element.php';
$leaves = [];
array_walk_recursive($cfg, function ($v, $k) use (&$leaves) { if (is_string($v)) { $leaves[] = $v; } });
// option labels are array KEYS
$walk = function ($a) use (&$walk, &$leaves) { foreach ($a as $k => $v) { if (is_string($k)) { $leaves[] = $k; } if (is_array($v)) { $walk($v); } } };
$walk($cfg);
$out['unresolved'] = array_values(array_unique(array_filter($leaves, fn($x) => strpos($x, 'PLG_SYSTEM_WEBGBOOKING_') !== false)));
$out['duration_label'] = $cfg['fields']['event_duration']['label'] ?? '';
$out['price_label'] = $cfg['fields']['price_amount']['label'] ?? '';
$out['meet_option'] = array_keys($cfg['fields']['event_location']['options'] ?? []);
$out['body_default'] = $cfg['fields']['email_body']['default'] ?? null;
echo json_encode($out) . "\n";
