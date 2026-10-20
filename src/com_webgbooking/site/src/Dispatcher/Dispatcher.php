<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Site\Dispatcher;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;

/**
 * Site side of the component: the long address of the direct booking page,
 * index.php?option=com_webgbooking&view=book&event=<slug>, the one that works even when the site has no friendly URLs.
 * The page itself (event definition, widget, brand) is the system plugin's: this only hands the request over.
 */
class Dispatcher extends ComponentDispatcher
{
    public function dispatch()
    {
        if (PluginHelper::isEnabled('system', 'webgbooking')) {
            $engine = $this->app->bootPlugin('webgbooking', 'system');
            if (method_exists($engine, 'renderDirectPage')) {
                // answers and ends the request
                $engine->renderDirectPage((string) $this->input->getString('event', ''));

                return;
            }
        }

        throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }
}
