<?php

/**
 * @package     plg_system_webgbooking
 * @author      Marco Galassi (WebG)
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use WebG\Plugin\System\Webgbooking\Extension\Webgbooking;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Webgbooking(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'webgbooking')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
