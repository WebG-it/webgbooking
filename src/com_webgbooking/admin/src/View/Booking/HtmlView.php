<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Booking;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $item;

    public function display($tpl = null)
    {
        $id = (int) Factory::getApplication()->getInput()->getInt('id', 0);

        /** @var \WebG\Component\Webgbooking\Administrator\Model\BookingModel $model */
        $model       = $this->getModel();
        $this->item  = $model->getItem($id);

        if (!$this->item) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_WEBGBOOKING_BOOKING_NOT_FOUND'), 'error');
            Factory::getApplication()->redirect('index.php?option=com_webgbooking&view=bookings');

            return;
        }

        ToolbarHelper::title(Text::_('COM_WEBGBOOKING_BOOKING_DETAIL'), 'calendar');
        ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_webgbooking&view=bookings');

        parent::display($tpl);
    }
}
