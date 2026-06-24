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
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'created', 'booking_date', 'booking_time',
                'customer_name', 'customer_email', 'customer_phone', 'status',
            ];
        }

        parent::__construct($config);
    }

    protected function populateState($ordering = 'booking_date', $direction = 'DESC')
    {
        $search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string');
        $this->setState('filter.search', $search);

        $status = $this->getUserStateFromRequest($this->context . '.filter.status', 'filter_status', '', 'string');
        $this->setState('filter.status', $status);

        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.status');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__webgbooking_bookings'));

        $search = (string) $this->getState('filter.search');
        if ($search !== '') {
            $like = $db->quote('%' . $db->escape($search, true) . '%', false);
            $query->where(
                '(' . $db->quoteName('customer_name') . ' LIKE ' . $like
                . ' OR ' . $db->quoteName('customer_email') . ' LIKE ' . $like
                . ' OR ' . $db->quoteName('customer_phone') . ' LIKE ' . $like . ')'
            );
        }

        $status = (string) $this->getState('filter.status');
        if ($status !== '') {
            $query->where($db->quoteName('status') . ' = ' . $db->quote($status));
        }

        $allowed  = ['id', 'created', 'booking_date', 'booking_time', 'customer_name', 'customer_email', 'customer_phone', 'status'];
        $ordering = $this->getState('list.ordering', 'booking_date');
        if (!\in_array($ordering, $allowed, true)) {
            $ordering = 'booking_date';
        }
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $query->order($db->quoteName($ordering) . ' ' . $direction);
        if ($ordering === 'booking_date') {
            $query->order($db->quoteName('booking_time') . ' ' . $direction);
        }

        return $query;
    }
}
