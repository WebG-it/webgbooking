<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Email;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use WebG\Component\Webgbooking\Administrator\Helper\EmailEngine;

/** One email: its texts in the visual editor, "Insert placeholder", Preview, Send me a test, Restore the default text. */
class HtmlView extends BaseHtmlView
{
    public $data = [];
    public $form;
    public $canEdit = false;
    public $languages = [];

    public function display($tpl = null)
    {
        $app    = Factory::getApplication();
        $key    = preg_replace('/[^a-z_]/', '', (string) $app->input->getString('key', ''));
        $tag    = preg_replace('/[^A-Za-z-]/', '', (string) $app->input->getString('lang', ''));
        $engine = EmailEngine::get();
        $this->languages = $engine ? $engine->emailLanguages() : [];
        if (!\in_array($tag, $this->languages, true)) {
            $tag = $this->languages[0] ?? 'en-GB';
        }
        $this->data    = $engine ? $engine->emailEditorData($key, $tag) : [];
        $this->canEdit = $app->getIdentity()->authorise('core.edit', 'com_webgbooking');

        if ($this->data) {
            $this->form = $this->buildForm($this->data);
        }

        ToolbarHelper::title(Text::_('COM_WEBGBOOKING_EM_TITLE') . ($this->data ? ': ' . $this->data['name'] : ''), 'envelope');
        if ($this->canEdit && $this->data) {
            ToolbarHelper::apply('email.save', 'JTOOLBAR_APPLY');
            ToolbarHelper::custom('email.restore', 'undo', '', 'COM_WEBGBOOKING_EM_RESTORE', false);
        }
        ToolbarHelper::cancel('email.cancel', 'JTOOLBAR_CLOSE');

        parent::display($tpl);
    }

    /** The fields of the email: one line for the subject, the title, the words of the buttons and of the closing; the editor for the body. */
    private function buildForm(array $d): Form
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><form><fieldset name="main">';
        foreach ($d['fields'] as $f) {
            $label = 'COM_WEBGBOOKING_EMF_' . strtoupper($f);
            if ($f === 'text') {
                $xml .= '<field name="text" type="editor" label="' . $label . '" buttons="false" filter="raw" height="340px" />';
            } else {
                $xml .= '<field name="' . $f . '" type="text" label="' . $label . '" class="form-control" maxlength="300" />';
            }
        }
        $xml .= '</fieldset></form>';
        $form = Form::getInstance('com_webgbooking.email.' . $d['key'] . '.' . $d['lang'], $xml, ['control' => 'jform']);
        $form->bind($d['values']);

        return $form;
    }
}
