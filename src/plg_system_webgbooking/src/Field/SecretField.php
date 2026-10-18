<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * A secret (password-like) option that is NEVER sent back to the browser: the form shows an empty box and,
 * when a value is stored, only the word "set". Empty on save = keep the stored value, "-" = clear it.
 * The plugin encrypts the new value before it is written (see Webgbooking::onExtensionBeforeSave).
 */

namespace WebG\Plugin\System\Webgbooking\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

class SecretField extends FormField
{
    protected $type = 'Secret';

    protected function getInput()
    {
        $set  = trim((string) $this->value) !== '';
        $id   = htmlspecialchars((string) $this->id, ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) $this->name, ENT_QUOTES, 'UTF-8');
        $ph   = htmlspecialchars(Text::_($set ? 'PLG_SYSTEM_WEBGBOOKING_SECRET_KEEP' : 'PLG_SYSTEM_WEBGBOOKING_SECRET_EMPTY'), ENT_QUOTES, 'UTF-8');
        $badge = $set
            ? '<span class="badge bg-success ms-2">' . htmlspecialchars(Text::_('PLG_SYSTEM_WEBGBOOKING_SECRET_SET'), ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span class="badge bg-secondary ms-2">' . htmlspecialchars(Text::_('PLG_SYSTEM_WEBGBOOKING_SECRET_NOTSET'), ENT_QUOTES, 'UTF-8') . '</span>';

        return '<input type="password" class="form-control d-inline-block w-auto" style="min-width:20em" id="' . $id . '" name="' . $name . '" value="" size="40" autocomplete="new-password" placeholder="' . $ph . '">' . $badge;
    }
}
