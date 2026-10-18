<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Read-only box with the URL to register as the webhook endpoint in the Stripe dashboard.
 */

namespace WebG\Plugin\System\Webgbooking\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Uri\Uri;

class WebhookurlField extends FormField
{
    protected $type = 'Webhookurl';

    protected function getInput()
    {
        $url = Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=json&action=stripewebhook';
        $esc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return '<input type="text" class="form-control" id="' . htmlspecialchars((string) $this->id, ENT_QUOTES, 'UTF-8') . '" value="' . $esc . '" readonly onclick="this.select()" size="90">';
    }

    protected function getLabel()
    {
        return parent::getLabel();
    }
}
