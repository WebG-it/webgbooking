<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/** The public address of a saved event type, as the plugin answers it (single copy of the rules: EventTypes::publicUrl in the plugin). */
final class LinkHelper
{
    /** Short address (https://site/prenota/<slug>), or the long one (index.php?option=com_webgbooking&view=book&event=<slug>). */
    public static function url(string $slug, bool $long = false): string
    {
        $class = '\\WebG\\Plugin\\System\\Webgbooking\\Booking\\EventTypes';
        if (class_exists($class)) {
            $plugin = PluginHelper::getPlugin('system', 'webgbooking');
            $path   = $plugin ? (string) (new Registry($plugin->params ?? ''))->get('direct_path', '') : '';

            return $class::publicUrl($slug, $path, $long);
        }

        return Uri::root() . 'index.php?option=com_webgbooking&view=book' . ($slug !== '' ? '&event=' . rawurlencode($slug) : '');
    }
}
