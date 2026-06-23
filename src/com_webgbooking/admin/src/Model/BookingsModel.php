<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

class BookingsModel extends ListModel
{
    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__webgbooking_bookings'))
            ->order($db->quoteName('booking_date') . ' DESC')
            ->order($db->quoteName('booking_time') . ' DESC');

        return $query;
    }
}
