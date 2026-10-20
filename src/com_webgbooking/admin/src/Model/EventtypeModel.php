<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;

/** One saved event type: the edit form, save, publish and delete (rights checked against the component). */
class EventtypeModel extends AdminModel
{
    public function getTable($name = 'Eventtype', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_webgbooking.eventtype', 'eventtype', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_webgbooking.edit.eventtype.data', []);
        if (empty($data)) {
            $data = $this->getItem();
            if (!(int) ($data->id ?? 0)) {
                // a new one starts with the common choices already made
                $data->state = 1;
                $data->listed = 1;
                $data->location = 'meet';
                $data->allow_guest = 1;
                $data->price_currency = 'EUR';
            }
        }

        return $data;
    }

    // These tables have no asset and "state" is 0/1: the component's own rights decide (the base class would only delete trashed items with a "published" column).
    protected function canDelete($record)
    {
        return !empty($record->id) && $this->getCurrentUser()->authorise('core.delete', 'com_webgbooking');
    }

    protected function canEditState($record)
    {
        return $this->getCurrentUser()->authorise('core.edit.state', 'com_webgbooking');
    }

    public function save($data)
    {
        // a new one goes to the end of the list unless the administrator chose a position
        if (empty($data['id']) && (int) ($data['ordering'] ?? 0) === 0) {
            $db = $this->getDatabase();
            $data['ordering'] = 1 + (int) $db->setQuery($db->getQuery(true)->select('MAX(' . $db->quoteName('ordering') . ')')->from($db->quoteName('#__webgbooking_eventtypes')))->loadResult();
        }

        return parent::save($data);
    }
}
