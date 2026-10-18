<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Like SecretField, for several lines (webhook header profiles: "Authorization: Bearer ..." one per line).
 * The stored value is NEVER sent back to the browser: the box is empty and only says "set" when something is stored.
 * Empty on save = keep the stored value, "-" = clear it. The plugin encrypts a new value before it is written.
 */

namespace WebG\Plugin\System\Webgbooking\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

class SecretareaField extends FormField
{
    protected $type = 'Secretarea';

    protected function getInput()
    {
        $set  = trim((string) $this->value) !== '';
        $id   = htmlspecialchars((string) $this->id, ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) $this->name, ENT_QUOTES, 'UTF-8');
        $ph   = htmlspecialchars(Text::_($set ? 'PLG_SYSTEM_WEBGBOOKING_SECRET_KEEP' : 'PLG_SYSTEM_WEBGBOOKING_WHP_HEADERS_EMPTY'), ENT_QUOTES, 'UTF-8');
        $badge = $set
            ? '<span class="badge bg-success ms-2">' . htmlspecialchars(Text::_('PLG_SYSTEM_WEBGBOOKING_SECRET_SET'), ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span class="badge bg-secondary ms-2">' . htmlspecialchars(Text::_('PLG_SYSTEM_WEBGBOOKING_SECRET_NOTSET'), ENT_QUOTES, 'UTF-8') . '</span>';

        return '<textarea class="form-control d-inline-block w-auto" style="min-width:26em" rows="3" id="' . $id . '" name="' . $name . '" autocomplete="off" spellcheck="false" placeholder="' . $ph . '"></textarea>' . $badge;
    }
}
