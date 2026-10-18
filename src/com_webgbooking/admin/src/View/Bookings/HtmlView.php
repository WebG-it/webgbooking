<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Bookings;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $items;
    protected $pagination;
    public $filterForm;
    public $activeFilters;
    protected $state;
    public $stats;
    public $canEditState = false;
    public $canDelete = false;

    public function display($tpl = null)
    {
        $this->items         = $this->get('Items');
        $this->pagination    = $this->get('Pagination');
        $this->state         = $this->get('State');
        $this->filterForm    = $this->get('FilterForm');
        $this->activeFilters = $this->get('ActiveFilters');
        $this->stats         = $this->getModel()->getStats();

        $user               = Factory::getApplication()->getIdentity();
        $this->canEditState = $user->authorise('core.edit.state', 'com_webgbooking');
        $this->canDelete    = $user->authorise('core.delete', 'com_webgbooking');

        $this->addToolbar();

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_WEBGBOOKING'), 'calendar');

        if ($this->canEditState) {
            ToolbarHelper::custom('booking.confirm', 'check', '', 'COM_WEBGBOOKING_TB_CONFIRM', true);
            ToolbarHelper::custom('booking.complete', 'checkin', '', 'COM_WEBGBOOKING_TB_COMPLETE', true);
            ToolbarHelper::custom('booking.noshow', 'minus', '', 'COM_WEBGBOOKING_TB_NOSHOW', true);
            ToolbarHelper::custom('booking.cancel', 'cancel', '', 'COM_WEBGBOOKING_TB_CANCEL', true);
            ToolbarHelper::divider();
        }

        if ($this->canDelete) {
            ToolbarHelper::deleteList(Text::_('COM_WEBGBOOKING_CONFIRM_DELETE'), 'booking.delete', 'JTOOLBAR_DELETE');
            ToolbarHelper::divider();
        }
    }
}
