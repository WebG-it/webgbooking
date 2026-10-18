<?php
/**
 * jed_harness.php <cartella_estensione_estratta> <cartella_src_jedchecker> : esegue le regole di JED Checker
 * (ramo develop, quello moderno con i namespace) dentro un Joomla usa-e-getta e stampa JSON fra JSONSTART e JSONEND.
 * JED Checker non ha una riga di comando: e' un componente da admin. Qui se ne usano le STESSE regole
 * (RuleDiscovery + AbstractRule::check) senza l'interfaccia.
 */
const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias('JSession', 'session.cli')->alias(\Joomla\CMS\Session\Session::class, 'session.cli')->alias(\Joomla\Session\Session::class, 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\Console\Application::class);
\Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap();

$folder = rtrim($argv[1], '/');
$src = rtrim($argv[2], '/');

spl_autoload_register(function ($c) use ($src) {
    $p = 'Joomla\\Component\\Jedchecker\\Administrator\\';
    if (strpos($c, $p) === 0) {
        $f = $src . '/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

$out = ['regole' => [], 'errori' => 0, 'avvisi' => 0, 'compat' => 0, 'voci' => []];

foreach (\Joomla\Component\Jedchecker\Administrator\Rule\RuleDiscovery::getRules() as $fqcn) {
    try {
        $r = new $fqcn($folder);
        $r->check();
        $d = $r->getReport()->getData();
        $out['regole'][] = $r->getId();

        foreach ($d['issues'] as $i) {
            if ($i['type'] === 'error') {
                $out['errori']++;
            } elseif ($i['type'] === 'warning') {
                $out['avvisi']++;
            } elseif ($i['type'] === 'compatibility') {
                $out['compat']++;
            } else {
                continue;
            }

            if (true) {
                $out['voci'][] = $i['type'] . ' [' . $r->getId() . '] ' . $i['location'] . ($i['line'] ? ':' . $i['line'] : '') . ' ' . substr(strip_tags((string) $i['text']), 0, 400);
            }
        }
    } catch (\Throwable $e) {
        $out['voci'][] = 'ECCEZIONE ' . $fqcn . ': ' . substr($e->getMessage(), 0, 120);
        $out['eccezioni'] = ($out['eccezioni'] ?? 0) + 1;
    }
}

echo 'JSONSTART' . json_encode($out) . "JSONEND\n";
