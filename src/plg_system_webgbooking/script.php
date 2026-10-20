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
                . $db->quoteName('customer_tz') . ' VARCHAR(64) NULL,'
                . $db->quoteName('staff_to') . ' VARCHAR(500) NULL,'
                . $db->quoteName('hook_cfg') . ' TEXT NULL,'
                . $db->quoteName('cust_cfg') . ' MEDIUMTEXT NULL,'
                . $db->quoteName('review_why') . ' VARCHAR(16) NULL,'
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
                // 0.35.2: the time zone the customer chose on the booking page (names the time in their emails, reminders and .ics)
                'customer_tz'           => 'VARCHAR(64) NULL',
                // 0.33.2: the element's staff recipient(s), so reschedule / cancel / alerts reach the same people as the booking
                'staff_to'              => 'VARCHAR(500) NULL',
                // 0.34.0: where the element's webhook points (name of the header profile, no secret), for the later events
                'hook_cfg'              => 'TEXT NULL',
                // 0.34.1: the element's customer-email settings, for the admin "confirm and tell the customer"
                'cust_cfg'              => 'MEDIUMTEXT NULL',
                // 0.34.3: why a paid booking needs review (slot / past / unknown / amount): the customer pages and emails say it
                'review_why'            => 'VARCHAR(16) NULL',
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

            // 0.35.0: saved event types (the direct booking address /prenota/<slug> and the element's "saved event type" choice).
            // Edited in the component, read by the plugin. Text fields keep the element's own format (price "49" or "29,00",
            // questions one per line) so the same code reads both.
            $db->setQuery(
                'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__webgbooking_eventtypes') . ' ('
                . $db->quoteName('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . $db->quoteName('slug') . ' VARCHAR(80) NOT NULL,'
                . $db->quoteName('title') . ' VARCHAR(190) NOT NULL,'
                . $db->quoteName('description') . ' TEXT NULL,'
                . $db->quoteName('state') . ' TINYINT NOT NULL DEFAULT 1,'
                . $db->quoteName('listed') . ' TINYINT NOT NULL DEFAULT 1,'
                . $db->quoteName('duration') . ' VARCHAR(6) NULL,'
                . $db->quoteName('buffer_before') . ' VARCHAR(6) NULL,'
                . $db->quoteName('buffer_after') . ' VARCHAR(6) NULL,'
                . $db->quoteName('location') . " VARCHAR(10) NOT NULL DEFAULT 'meet',"
                . $db->quoteName('avail_start') . ' VARCHAR(5) NULL,'
                . $db->quoteName('avail_end') . ' VARCHAR(5) NULL,'
                . $db->quoteName('avail_days') . ' VARCHAR(40) NULL,'
                . $db->quoteName('avail_notice') . ' VARCHAR(6) NULL,'
                . $db->quoteName('avail_window') . ' VARCHAR(6) NULL,'
                . $db->quoteName('price_amount') . ' VARCHAR(30) NULL,'
                . $db->quoteName('price_currency') . ' VARCHAR(3) NULL,'
                . $db->quoteName('price_label') . ' VARCHAR(120) NULL,'
                . $db->quoteName('custom_fields') . ' TEXT NULL,'
                . $db->quoteName('staff_to') . ' VARCHAR(500) NULL,'
                . $db->quoteName('redirect_url') . ' VARCHAR(500) NULL,'
                . $db->quoteName('confirm_manual') . ' TINYINT NOT NULL DEFAULT 0,'
                . $db->quoteName('allow_guest') . ' TINYINT NOT NULL DEFAULT 1,'
                . $db->quoteName('show_newsletter') . ' TINYINT NOT NULL DEFAULT 0,'
                . $db->quoteName('ordering') . ' INT NOT NULL DEFAULT 0,'
                . $db->quoteName('created') . ' DATETIME NULL,'
                . $db->quoteName('modified') . ' DATETIME NULL,'
                . $db->quoteName('created_by') . ' INT UNSIGNED NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (' . $db->quoteName('id') . '),'
                . 'UNIQUE KEY ' . $db->quoteName('uq_slug') . ' (' . $db->quoteName('slug') . '),'
                . 'KEY ' . $db->quoteName('idx_state') . ' (' . $db->quoteName('state') . ',' . $db->quoteName('listed') . ',' . $db->quoteName('ordering') . ')'
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
                // The plugin language files are copied before this runs: load them so the text is translated.
                Factory::getApplication()->getLanguage()->load('plg_system_webgbooking', JPATH_PLUGINS . '/system/webgbooking');
                \Joomla\CMS\Log\Log::add(\Joomla\CMS\Language\Text::sprintf('PLG_SYSTEM_WEBGBOOKING_INSTALL_DB_LOG', implode(' | ', $problems)), \Joomla\CMS\Log\Log::ERROR, 'webgbooking');
                Factory::getApplication()->enqueueMessage(\Joomla\CMS\Language\Text::sprintf('PLG_SYSTEM_WEBGBOOKING_INSTALL_DB_MESSAGE', \count($problems), implode('; ', array_slice($problems, 0, 3))), 'error');
            } catch (\Throwable $e) {
            }
        }

        return true;
    }
};
