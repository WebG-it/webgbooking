<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Emails;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use WebG\Component\Webgbooking\Administrator\Helper\EmailEngine;

/** The list of the emails: one line per email, what it is for, whether this site changed its text. */
class HtmlView extends BaseHtmlView
{
    public $items = [];
    public $lang = '';
    public $languages = [];
    public $engine = false;
    public $canEdit = false;

    public function display($tpl = null)
    {
        $app             = Factory::getApplication();
        $engine          = EmailEngine::get();
        $this->engine    = $engine !== null;
        $this->canEdit   = $app->getIdentity()->authorise('core.edit', 'com_webgbooking');
        $this->languages = $engine ? $engine->emailLanguages() : [];
        $want            = preg_replace('/[^A-Za-z-]/', '', (string) $app->input->getString('lang', ''));
        $this->lang      = \in_array($want, $this->languages, true) ? $want : (string) $app->getLanguage()->getTag();
        if (!\in_array($this->lang, $this->languages, true)) {
            $this->lang = $this->languages[0] ?? 'en-GB';
        }
        $this->items = $engine ? $engine->emailList($this->lang) : [];

        ToolbarHelper::title(Text::_('COM_WEBGBOOKING_EM_TITLE'), 'envelope');
        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_webgbooking')) {
            ToolbarHelper::preferences('com_webgbooking');
        }
        parent::display($tpl);
    }
}
