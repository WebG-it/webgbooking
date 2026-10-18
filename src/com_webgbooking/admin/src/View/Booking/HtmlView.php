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

        // Manual-confirmation flow: a pending booking is confirmed AND the customer is told, in one click.
        if ($this->item->status === 'pending' && Factory::getApplication()->getIdentity()->authorise('core.edit.state', 'com_webgbooking')) {
            ToolbarHelper::custom('booking.confirmnotify', 'publish', '', 'COM_WEBGBOOKING_TB_CONFIRMNOTIFY', false);
        }

        // Cancel from the detail page too (same action as the list: Google event + customer email).
        if ($this->item->status !== 'cancelled' && Factory::getApplication()->getIdentity()->authorise('core.edit.state', 'com_webgbooking')) {
            ToolbarHelper::custom('booking.cancel', 'cancel', '', 'COM_WEBGBOOKING_TB_CANCEL', false);
        }
        ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_webgbooking&view=bookings');

        parent::display($tpl);
    }
}
