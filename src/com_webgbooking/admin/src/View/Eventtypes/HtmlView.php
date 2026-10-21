<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Eventtypes;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $items;
    protected $pagination;
    protected $state;
    public $filterForm;
    public $activeFilters;
    public $canCreate = false;
    public $canEdit = false;
    public $canEditState = false;
    public $canDelete = false;

    public function display($tpl = null)
    {
        $this->items         = $this->get('Items');
        $this->pagination    = $this->get('Pagination');
        $this->state         = $this->get('State');
        $this->filterForm    = $this->get('FilterForm');
        $this->activeFilters = $this->get('ActiveFilters');

        $user               = Factory::getApplication()->getIdentity();
        $this->canCreate    = $user->authorise('core.create', 'com_webgbooking');
        $this->canEdit      = $user->authorise('core.edit', 'com_webgbooking');
        $this->canEditState = $user->authorise('core.edit.state', 'com_webgbooking');
        $this->canDelete    = $user->authorise('core.delete', 'com_webgbooking');

        ToolbarHelper::title(Text::_('COM_WEBGBOOKING_ET_TITLE'), 'calendar');
        if ($this->canCreate) {
            ToolbarHelper::addNew('eventtype.add');
        }
        if ($this->canEdit) {
            ToolbarHelper::editList('eventtype.edit');
        }
        if ($this->canEditState) {
            ToolbarHelper::publish('eventtypes.publish', 'JTOOLBAR_PUBLISH', true);
            ToolbarHelper::unpublish('eventtypes.unpublish', 'JTOOLBAR_UNPUBLISH', true);
        }
        if ($this->canDelete) {
            ToolbarHelper::deleteList(Text::_('COM_WEBGBOOKING_ET_CONFIRM_DELETE'), 'eventtypes.delete', 'JTOOLBAR_DELETE');
        }
        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_webgbooking')) {
            ToolbarHelper::preferences('com_webgbooking');
        }

        parent::display($tpl);
    }
}
