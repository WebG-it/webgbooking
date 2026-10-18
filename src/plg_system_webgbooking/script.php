<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Install script: creates the bookings table (install + update, idempotent) and
 * enables the plugin on first install only (updates leave the state untouched).
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

return new class () implements InstallerScriptInterface {
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'install' && $type !== 'update') {
            return true;
        }

        $problems = [];

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__webgbooking_bookings') . ' ('
                . $db->quoteName('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . $db->quoteName('created') . ' DATETIME NOT NULL,'
                . $db->quoteName('booking_date') . ' DATE NOT NULL,'
                . $db->quoteName('booking_time') . ' VARCHAR(5) NOT NULL,'
                . $db->quoteName('customer_name') . ' VARCHAR(190) NOT NULL,'
                . $db->quoteName('customer_email') . ' VARCHAR(190) NOT NULL,'
                . $db->quoteName('customer_phone') . ' VARCHAR(60) NULL,'
                . $db->quoteName('notes') . ' TEXT NULL,'
                . $db->quoteName('status') . " VARCHAR(20) NOT NULL DEFAULT 'pending',"
                . $db->quoteName('source_url') . ' VARCHAR(255) NULL,'
                . $db->quoteName('meeting_url') . ' VARCHAR(255) NULL,'
                . $db->quoteName('guest_email') . ' VARCHAR(190) NULL,'
                . $db->quoteName('manage_token') . ' VARCHAR(64) NULL,'
                . $db->quoteName('google_event_id') . ' VARCHAR(255) NULL,'
                . $db->quoteName('updated') . ' DATETIME NULL,'
                . $db->quoteName('seq') . ' INT NOT NULL DEFAULT 0,'
                . $db->quoteName('custom_data') . ' TEXT NULL,'
                . $db->quoteName('duration_min') . ' INT NULL,'
                . $db->quoteName('buffer_before') . ' INT NULL,'
                . $db->quoteName('buffer_after') . ' INT NULL,'
                . $db->quoteName('location') . ' VARCHAR(20) NULL,'
                . $db->quoteName('event_name') . ' VARCHAR(190) NULL,'
                . $db->quoteName('reminder_cfg') . ' TEXT NULL,'
                . $db->quoteName('reminders_sent') . ' TEXT NULL,'
                . $db->quoteName('payment_status') . ' VARCHAR(20) NULL,'
                . $db->quoteName('amount_cents') . ' INT NULL,'
                . $db->quoteName('currency') . ' VARCHAR(3) NULL,'
                . $db->quoteName('stripe_session_id') . ' VARCHAR(255) NULL,'
                . $db->quoteName('stripe_payment_intent') . ' VARCHAR(255) NULL,'
                . $db->quoteName('hold_until') . ' DATETIME NULL,'
                . $db->quoteName('paid_at') . ' DATETIME NULL,'
                . $db->quoteName('pending_ctx') . ' MEDIUMTEXT NULL,'
                . $db->quoteName('fx_state') . ' VARCHAR(10) NULL,'
                . $db->quoteName('fx_steps') . ' TEXT NULL,'
                . $db->quoteName('fx_tries') . ' INT NULL,'
                . $db->quoteName('fx_last') . ' DATETIME NULL,'
                . $db->quoteName('avail_cfg') . ' TEXT NULL,'
                . $db->quoteName('ip_hash') . ' VARCHAR(32) NULL,'
                . $db->quoteName('staff_to') . ' VARCHAR(500) NULL,'
                . 'PRIMARY KEY (' . $db->quoteName('id') . '),'
                . 'KEY ' . $db->quoteName('idx_date') . ' (' . $db->quoteName('booking_date') . ',' . $db->quoteName('booking_time') . '),'
                . 'KEY ' . $db->quoteName('idx_token') . ' (' . $db->quoteName('manage_token') . ')'
                . ') DEFAULT CHARSET=utf8mb4'
            )->execute();

            // Upgrade path: add any columns missing from a pre-existing table.
            $newCols = [
                'meeting_url'     => 'VARCHAR(255) NULL',
                'guest_email'     => 'VARCHAR(190) NULL',
                'manage_token'    => 'VARCHAR(64) NULL',
                'google_event_id' => 'VARCHAR(255) NULL',
                'updated'         => 'DATETIME NULL',
                'seq'             => 'INT NOT NULL DEFAULT 0',
                'custom_data'     => 'TEXT NULL',
                // 0.31.0: event type per element, where the meeting happens, reminders (idempotent ALTER)
                'duration_min'    => 'INT NULL',
                'buffer_before'   => 'INT NULL',
                'buffer_after'    => 'INT NULL',
                'location'        => 'VARCHAR(20) NULL',
                'event_name'      => 'VARCHAR(190) NULL',
                'reminder_cfg'    => 'TEXT NULL',
                'reminders_sent'  => 'TEXT NULL',
                // 0.32.0: paid events (Stripe Checkout)
                'payment_status'        => 'VARCHAR(20) NULL',
                'amount_cents'          => 'INT NULL',
                'currency'              => 'VARCHAR(3) NULL',
                'stripe_session_id'     => 'VARCHAR(255) NULL',
                'stripe_payment_intent' => 'VARCHAR(255) NULL',
                'hold_until'            => 'DATETIME NULL',
                'paid_at'               => 'DATETIME NULL',
                'pending_ctx'           => 'MEDIUMTEXT NULL',
                // 0.33.0: resumable effects, per-element availability, anti-abuse
                'fx_state'              => 'VARCHAR(10) NULL',
                'fx_steps'              => 'TEXT NULL',
                'fx_tries'              => 'INT NULL',
                'fx_last'               => 'DATETIME NULL',
                'avail_cfg'             => 'TEXT NULL',
                'ip_hash'               => 'VARCHAR(32) NULL',
                // 0.33.2: the element's staff recipient(s), so reschedule / cancel / alerts reach the same people as the booking
                'staff_to'              => 'VARCHAR(500) NULL',
            ];
            foreach ($newCols as $name => $def) {
                try {
                    $has = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName('#__webgbooking_bookings') . ' LIKE ' . $db->quote($name))->loadResult();
                    if (!$has) {
                        $db->setQuery('ALTER TABLE ' . $db->quoteName('#__webgbooking_bookings') . ' ADD COLUMN ' . $db->quoteName($name) . ' ' . $def)->execute();
                    }
                } catch (\Throwable $e) {
                    $problems[] = 'column ' . $name . ': ' . $e->getMessage();
                }
            }

            // Index to find a booking from a Stripe session id (webhook / return page) and the unpaid holds.
            foreach (['idx_stripe_session' => ['stripe_session_id(100)'], 'idx_status_hold' => ['status', 'hold_until'], 'idx_fx' => ['fx_state', 'fx_last'], 'idx_ip' => ['ip_hash', 'created']] as $ix => $cols) {
                try {
                    $has = $db->setQuery('SHOW INDEX FROM ' . $db->quoteName('#__webgbooking_bookings') . ' WHERE Key_name = ' . $db->quote($ix))->loadResult();
                    if (!$has) {
                        $db->setQuery('ALTER TABLE ' . $db->quoteName('#__webgbooking_bookings') . ' ADD INDEX ' . $db->quoteName($ix) . ' (' . implode(',', array_map(fn($c) => strpos($c, '(') === false ? $db->quoteName($c) : $c, $cols)) . ')')->execute();
                    }
                } catch (\Throwable $e) {
                    $problems[] = 'index ' . $ix . ': ' . $e->getMessage();
                }
            }

            // Processed Stripe webhook events (idempotency: the same event delivered twice has one effect).
            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__webgbooking_stripe_events') . ' ('
                . $db->quoteName('event_id') . ' VARCHAR(100) NOT NULL,'
                . $db->quoteName('type') . ' VARCHAR(80) NULL,'
                . $db->quoteName('created') . ' DATETIME NOT NULL,'
                . $db->quoteName('result') . ' VARCHAR(40) NULL,'
                . 'PRIMARY KEY (' . $db->quoteName('event_id') . ')'
                . ') DEFAULT CHARSET=utf8mb4'
            )->execute();

            // Key/value store: alert throttles, freeBusy cache, one-time outcome tokens.
            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__webgbooking_kv') . ' ('
                . $db->quoteName('k') . ' VARCHAR(120) NOT NULL,'
                . $db->quoteName('v') . ' MEDIUMTEXT NULL,'
                . $db->quoteName('updated') . ' DATETIME NOT NULL,'
                . 'PRIMARY KEY (' . $db->quoteName('k') . ')'
                . ') DEFAULT CHARSET=utf8mb4'
            )->execute();

            // Google connection store (single row): encrypted refresh token + account.
            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__webgbooking_google') . ' ('
                . $db->quoteName('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . $db->quoteName('refresh_token') . ' TEXT NULL,'
                . $db->quoteName('account_email') . ' VARCHAR(190) NULL,'
                . $db->quoteName('calendar_id') . ' VARCHAR(190) NULL,'
                . $db->quoteName('read_calendars') . ' TEXT NULL,'
                . $db->quoteName('sheet_id') . ' VARCHAR(190) NULL,'
                . $db->quoteName('sheet_tab') . ' VARCHAR(190) NULL,'
                . $db->quoteName('oauth_state') . ' VARCHAR(64) NULL,'
                . $db->quoteName('created') . ' DATETIME NULL,'
                . $db->quoteName('updated') . ' DATETIME NULL,'
                . 'PRIMARY KEY (' . $db->quoteName('id') . ')'
                . ') DEFAULT CHARSET=utf8mb4'
            )->execute();

            // Upgrade path for the Google table (older installs predate these columns).
            foreach (['read_calendars' => 'TEXT', 'sheet_id' => 'VARCHAR(190)', 'sheet_tab' => 'VARCHAR(190)'] as $name => $colType) {
                try {
                    $has = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName('#__webgbooking_google') . ' LIKE ' . $db->quote($name))->loadResult();
                    if (!$has) {
                        $db->setQuery('ALTER TABLE ' . $db->quoteName('#__webgbooking_google') . ' ADD COLUMN ' . $db->quoteName($name) . ' ' . $colType . ' NULL')->execute();
                    }
                } catch (\Throwable $e) {
                    $problems[] = 'google column ' . $name . ': ' . $e->getMessage();
                }
            }

            if ($type === 'install') {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName('#__extensions'))
                        ->set($db->quoteName('enabled') . ' = 1')
                        ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('webgbooking'))
                        ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                )->execute();
            }

        } catch (\Throwable $e) {
            $problems[] = 'tables: ' . $e->getMessage();
        }

        // Not silent any more: a failed ALTER means the plugin would run against a table that lacks columns.
        if ($problems) {
            try {
                \Joomla\CMS\Log\Log::addLogger(['text_file' => 'webgbooking.php'], \Joomla\CMS\Log\Log::ALL, ['webgbooking']);
                \Joomla\CMS\Log\Log::add('WebG Booking database update problems: ' . implode(' | ', $problems), \Joomla\CMS\Log\Log::ERROR, 'webgbooking');
                Factory::getApplication()->enqueueMessage('WebG Booking: the database update did not complete (' . \count($problems) . ' problem(s): ' . implode('; ', array_slice($problems, 0, 3)) . '). Bookings may fail until this is fixed: check the Joomla database user rights (ALTER, CREATE) and reinstall the package.', 'error');
            } catch (\Throwable $e) {
            }
        }

        return true;
    }
};
