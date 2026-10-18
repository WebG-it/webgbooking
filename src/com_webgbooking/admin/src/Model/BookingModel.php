<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Single-booking operations for the admin: read one, change status, delete.
 */
class BookingModel extends BaseDatabaseModel
{
    /** Allowed booking statuses (shared vocabulary with the plugin). */
    public const STATUSES = ['pending', 'confirmed', 'cancelled', 'completed', 'no-show'];

    /** Load one booking by id. */
    public function getItem(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->getDatabase();

        return $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__webgbooking_bookings'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER)
        )->loadObject() ?: null;
    }

    /**
     * Set the status on one or more bookings. Returns the number of affected rows.
     */
    public function setStatus(array $ids, string $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));

        if (!$ids || !\in_array($status, self::STATUSES, true)) {
            return 0;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__webgbooking_bookings'))
            ->set($db->quoteName('status') . ' = ' . $db->quote($status))
            ->set($db->quoteName('updated') . ' = ' . $db->quote(Factory::getDate()->toSql()))
            ->whereIn($db->quoteName('id'), $ids);

        $db->setQuery($query)->execute();

        return (int) $db->getAffectedRows();
    }

    /**
     * Permanently delete one or more bookings. Returns the number of deleted rows.
     */
    public function deleteItems(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));

        if (!$ids) {
            return 0;
        }

        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__webgbooking_bookings'))
                ->whereIn($db->quoteName('id'), $ids)
        )->execute();

        return (int) $db->getAffectedRows();
    }
}
