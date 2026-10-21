<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;

/** The system plugin that holds the engine of the emails (texts, preview, test). The component never reads or writes the texts itself. */
final class EmailEngine
{
    public static function get(): ?object
    {
        try {
            if (PluginHelper::isEnabled('system', 'webgbooking')) {
                $p = Factory::getApplication()->bootPlugin('webgbooking', 'system');
                if (method_exists($p, 'emailEditorData')) {
                    return $p;
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }
}
