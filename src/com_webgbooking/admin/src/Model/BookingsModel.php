<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
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

        $from = $this->getUserStateFromRequest($this->context . '.filter.date_from', 'filter_date_from', '', 'string');
        $this->setState('filter.date_from', $from);

        $to = $this->getUserStateFromRequest($this->context . '.filter.date_to', 'filter_date_to', '', 'string');
        $this->setState('filter.date_to', $to);

        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.status');
        $id .= ':' . $this->getState('filter.date_from');
        $id .= ':' . $this->getState('filter.date_to');

        return parent::getStoreId($id);
    }

    /** Only accept a clean YYYY-MM-DD date for the range filters. */
    private function cleanDate($value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /** At-a-glance counters for the list header. */
    public function getStats(): object
    {
        $db    = $this->getDatabase();
        $today = Factory::getDate()->format('Y-m-d');

        $count = function ($where = null) use ($db) {
            $q = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_bookings'));
            if ($where) {
                $q->where($where);
            }

            return (int) $db->setQuery($q)->loadResult();
        };

        return (object) [
            'total'     => $count(),
            'pending'   => $count($db->quoteName('status') . ' = ' . $db->quote('pending')),
            'confirmed' => $count($db->quoteName('status') . ' = ' . $db->quote('confirmed')),
            'today'     => $count($db->quoteName('booking_date') . ' = ' . $db->quote($today) . ' AND ' . $db->quoteName('status') . ' NOT IN (' . $db->quote('cancelled') . ',' . $db->quote('expired') . ',' . $db->quote('awaiting_payment') . ')'),
            'upcoming'  => $count($db->quoteName('booking_date') . ' >= ' . $db->quote($today) . ' AND ' . $db->quoteName('status') . ' NOT IN (' . $db->quote('cancelled') . ',' . $db->quote('completed') . ',' . $db->quote('no-show') . ',' . $db->quote('expired') . ',' . $db->quote('awaiting_payment') . ')'),
        ];
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

        $from = $this->cleanDate($this->getState('filter.date_from'));
        if ($from !== '') {
            $query->where($db->quoteName('booking_date') . ' >= ' . $db->quote($from));
        }

        $to = $this->cleanDate($this->getState('filter.date_to'));
        if ($to !== '') {
            $query->where($db->quoteName('booking_date') . ' <= ' . $db->quote($to));
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
