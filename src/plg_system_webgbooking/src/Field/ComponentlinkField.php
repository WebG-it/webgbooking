<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The plugin has no options of its own since 0.36.0: this field shows where they are now, with a link to the Options of the component.
 */

namespace WebG\Plugin\System\Webgbooking\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use WebG\Plugin\System\Webgbooking\Booking\Settings;

class ComponentlinkField extends FormField
{
    protected $type = 'Componentlink';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        $href = Route::_(Settings::OPTIONS_LINK, false);

        return '<div class="alert alert-info" role="status"><p class="mb-2">' . htmlspecialchars(Text::_('PLG_SYSTEM_WEBGBOOKING_MOVED_TEXT'), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a class="btn btn-primary" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(Text::_('PLG_SYSTEM_WEBGBOOKING_MOVED_BUTTON'), ENT_QUOTES, 'UTF-8') . '</a></div>';
    }
}
