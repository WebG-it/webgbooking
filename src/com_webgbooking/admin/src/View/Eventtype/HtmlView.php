<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Eventtype;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $form;
    protected $item;

    public function display($tpl = null)
    {
        $this->form = $this->get('Form');
        $this->item = $this->get('Item');

        Factory::getApplication()->getInput()->set('hidemainmenu', true);
        ToolbarHelper::title(Text::_((int) ($this->item->id ?? 0) > 0 ? 'COM_WEBGBOOKING_ET_EDIT' : 'COM_WEBGBOOKING_ET_NEW'), 'calendar');
        ToolbarHelper::apply('eventtype.apply');
        ToolbarHelper::save('eventtype.save');
        ToolbarHelper::cancel('eventtype.cancel', (int) ($this->item->id ?? 0) > 0 ? 'JTOOLBAR_CLOSE' : 'JTOOLBAR_CANCEL');

        parent::display($tpl);
    }
}
