<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;

/** The list of saved event types ("Tipi di evento"). */
class EventtypesModel extends ListModel
{
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'title', 'slug', 'state', 'listed', 'duration', 'ordering'];
        }

        parent::__construct($config);
    }

    protected function populateState($ordering = 'ordering', $direction = 'ASC')
    {
        $this->setState('filter.search', $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string'));
        $this->setState('filter.state', $this->getUserStateFromRequest($this->context . '.filter.state', 'filter_state', '', 'string'));
        $this->setState('filter.listed', $this->getUserStateFromRequest($this->context . '.filter.listed', 'filter_listed', '', 'string'));

        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search') . ':' . $this->getState('filter.state') . ':' . $this->getState('filter.listed');

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_eventtypes'));

        $search = trim((string) $this->getState('filter.search'));
        if ($search !== '') {
            $like = $db->quote('%' . $db->escape($search, true) . '%', false);
            $query->where('(' . $db->quoteName('title') . ' LIKE ' . $like . ' OR ' . $db->quoteName('slug') . ' LIKE ' . $like . ')');
        }
        $state = (string) $this->getState('filter.state');
        if ($state === '0' || $state === '1') {
            $query->where($db->quoteName('state') . ' = ' . (int) $state);
        }
        $listed = (string) $this->getState('filter.listed');
        if ($listed === '0' || $listed === '1') {
            $query->where($db->quoteName('listed') . ' = ' . (int) $listed);
        }

        $allowed  = ['id', 'title', 'slug', 'state', 'listed', 'ordering'];
        $ordering = (string) $this->getState('list.ordering', 'ordering');
        if (!\in_array($ordering, $allowed, true)) {
            $ordering = 'ordering';
        }
        $direction = strtoupper((string) $this->getState('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $query->order($db->quoteName($ordering) . ' ' . $direction);
        if ($ordering === 'ordering') {
            $query->order($db->quoteName('id') . ' ASC');
        }

        return $query;
    }
}
