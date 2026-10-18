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
use Joomla\CMS\Plugin\PluginHelper;

/**
 * Single-booking operations for the admin: read one, change status, delete.
 */
class BookingModel extends BaseDatabaseModel
{
    /** Allowed booking statuses (shared vocabulary with the plugin). */
    public const STATUSES = ['pending', 'confirmed', 'cancelled', 'completed', 'no-show', 'awaiting_payment', 'expired', 'needs_review'];

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

    /** Outcome of the last deleteItems() call (Google events removed with the rows). */
    public array $deleteReport = ['google_ok' => 0, 'google_fail' => 0, 'engine' => true];

    /** Outcome of the last cancelBookings() call, for the admin message. */
    public array $cancelReport = ['done' => 0, 'already' => 0, 'google_ok' => 0, 'google_fail' => 0, 'mail_ok' => 0, 'mail_fail' => 0, 'refund' => 0, 'engine' => true];

    /**
     * Cancel bookings the way the customer's own "cancel" does: the status changes, the Google Calendar event is
     * deleted and the customer gets the cancellation email. The logic lives in the system plugin (single copy);
     * if the plugin is not available the status is still set, and the report says nothing else was done.
     * Returns the number of bookings cancelled now.
     */
    public function cancelBookings(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        $this->cancelReport = ['done' => 0, 'already' => 0, 'google_ok' => 0, 'google_fail' => 0, 'mail_ok' => 0, 'mail_fail' => 0, 'refund' => 0, 'engine' => true];

        if (!$ids) {
            return 0;
        }

        $engine = null;
        try {
            if (PluginHelper::isEnabled('system', 'webgbooking')) {
                $candidate = Factory::getApplication()->bootPlugin('webgbooking', 'system');
                if (method_exists($candidate, 'adminCancelBooking')) {
                    $engine = $candidate;
                }
            }
        } catch (\Throwable $e) {
            $engine = null;
        }

        if ($engine === null) {
            $this->cancelReport['engine'] = false;

            return $this->writeStatus($ids, 'cancelled');
        }

        foreach ($ids as $id) {
            $r = $engine->adminCancelBooking($id);

            if (($r['status'] ?? '') === 'already') {
                $this->cancelReport['already']++;
                continue;
            }
            if (($r['status'] ?? '') !== 'ok') {
                continue;
            }

            $this->cancelReport['done']++;
            if (!empty($r['refund'])) {
                $this->cancelReport['refund']++;
            }
            // A booking that was never confirmed (unpaid hold) has no email and no Google event: nothing to report.
            if (($r['google'] ?? null) === null && empty($r['mail']) && empty($r['refund']) && ($r['status'] ?? '') === 'ok') {
                continue;
            }
            if (($r['google'] ?? null) === true) {
                $this->cancelReport['google_ok']++;
            } elseif (($r['google'] ?? null) === false) {
                $this->cancelReport['google_fail']++;
            }
            $this->cancelReport[!empty($r['mail']) ? 'mail_ok' : 'mail_fail']++;
        }

        return $this->cancelReport['done'];
    }

    /**
     * Set the status on one or more bookings. Returns the number of affected rows.
     * "cancelled" goes through cancelBookings() (Google event + customer email).
     */
    public function setStatus(array $ids, string $status): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));

        if (!$ids || !\in_array($status, self::STATUSES, true)) {
            return 0;
        }

        if ($status === 'cancelled') {
            return $this->cancelBookings($ids);
        }

        return $this->writeStatus($ids, $status);
    }

    /** Plain status update (no side effects). */
    private function writeStatus(array $ids, string $status): int
    {

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
        $this->deleteReport = ['google_ok' => 0, 'google_fail' => 0, 'engine' => true];

        if (!$ids) {
            return 0;
        }

        // Take the Google event away (and close an open Stripe session) BEFORE the rows disappear.
        $engine = null;
        try {
            if (PluginHelper::isEnabled('system', 'webgbooking')) {
                $candidate = Factory::getApplication()->bootPlugin('webgbooking', 'system');
                if (method_exists($candidate, 'adminBeforeDelete')) {
                    $engine = $candidate;
                }
            }
        } catch (\Throwable $e) {
            $engine = null;
        }
        if ($engine === null) {
            $this->deleteReport['engine'] = false;
        } else {
            foreach ($ids as $id) {
                $r = $engine->adminBeforeDelete($id);
                if (($r['google'] ?? null) === true) {
                    $this->deleteReport['google_ok']++;
                } elseif (($r['google'] ?? null) === false) {
                    $this->deleteReport['google_fail']++;
                }
            }
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
