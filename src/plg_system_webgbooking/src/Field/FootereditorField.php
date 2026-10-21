<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The footer text of the emails and of the direct page, in the visual editor. A text saved before 0.36.0 is plain text with line
 * breaks: it is shown to the editor as paragraphs and line breaks (its web addresses as links), so nothing is flattened when the
 * editor opens it. The database is not touched until the options are saved.
 */

namespace WebG\Plugin\System\Webgbooking\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\EditorField;
use WebG\Plugin\System\Webgbooking\Booking\FooterText;

class FootereditorField extends EditorField
{
    public $type = 'Footereditor';

    protected function getInput()
    {
        $this->value = FooterText::toEditorHtml((string) $this->value);

        return parent::getInput();
    }
}
