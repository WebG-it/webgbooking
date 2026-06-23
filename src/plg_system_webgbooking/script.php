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
                . 'PRIMARY KEY (' . $db->quoteName('id') . '),'
                . 'KEY ' . $db->quoteName('idx_date') . ' (' . $db->quoteName('booking_date') . ',' . $db->quoteName('booking_time') . ')'
                . ') DEFAULT CHARSET=utf8mb4'
            )->execute();

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
            // Non-fatal.
        }

        return true;
    }
};
