<?php

/**
 * @package     pkg_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Package install script. Runs in postflight AFTER the children AND the package update site are
 * registered, so it can rebuild exactly ONE clean, enabled, correctly-mapped update site — the
 * fix for "Find Updates shows nothing" caused by duplicate/orphaned sites from manual reinstalls.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Factory;
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

            $pkgId = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('extension_id'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('package'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('pkg_webgbooking'))
            )->loadResult();

            if (!$pkgId) {
                return true;
            }

            // Drop every WebG Booking update site (plugin/component/package + duplicates) and its maps.
            // Match the exact repo path segment, not a bare substring, to never touch another extension.
            $siteIds = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('update_site_id'))
                    ->from($db->quoteName('#__update_sites'))
                    ->where($db->quoteName('location') . ' LIKE ' . $db->quote('%/webgbooking/%'))
            )->loadColumn();

            foreach ($siteIds as $sid) {
                $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__update_sites'))->where($db->quoteName('update_site_id') . ' = ' . (int) $sid))->execute();
                $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__update_sites_extensions'))->where($db->quoteName('update_site_id') . ' = ' . (int) $sid))->execute();
            }

            // Insert one clean package update site, enabled, cache reset, mapped to the package.
            $site = (object) [
                'name'                 => 'WebG Booking',
                'type'                 => 'extension',
                'location'             => 'https://raw.githubusercontent.com/WebG-it/webgbooking/main/updates-pkg.xml',
                'enabled'              => 1,
                'last_check_timestamp' => 0,
                'extra_query'          => '',
            ];
            $db->insertObject('#__update_sites', $site, 'update_site_id');

            $db->insertObject('#__update_sites_extensions', (object) [
                'update_site_id' => (int) $site->update_site_id,
                'extension_id'   => $pkgId,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        $this->ensureReminderTask();

        return true;
    }

    /**
     * Reminders: make sure Joomla's Task Scheduler has ONE "WebG Booking reminders" task (every 5 minutes) and
     * that the task plugin is enabled. Done only when no task of this type exists yet, so a task the administrator
     * disabled or retimed (and the plugin state with it) is never touched again by an update.
     */
    private function ensureReminderTask(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            // Scheduler component present (Joomla 4.1+)?
            $tables = $db->getTableList();
            if (!\in_array($db->replacePrefix('#__scheduler_tasks'), $tables, true)) {
                return;
            }

            $exists = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__scheduler_tasks'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plg_task_webgbooking_reminders'))
            )->loadResult();

            if ($exists > 0) {
                return;
            }

            $now  = Factory::getDate()->toSql();
            $task = (object) [
                'asset_id'        => 0,
                'title'           => 'WebG Booking: send appointment reminders',
                'type'            => 'plg_task_webgbooking_reminders',
                'execution_rules' => json_encode(['rule-type' => 'interval-minutes', 'interval-minutes' => '5']),
                'cron_rules'      => json_encode(['type' => 'interval', 'exp' => 'PT5M']),
                'state'           => 1,
                'last_exit_code'  => 0,
                'next_execution'  => $now,
                'times_executed'  => 0,
                'times_failed'    => 0,
                'priority'        => 0,
                'ordering'        => 0,
                'cli_exclusive'   => 0,
                'params'          => json_encode([
                    'individual_log' => false,
                    'log_file'       => '',
                    'notifications'  => ['success_mail' => '0', 'failure_mail' => '0', 'fatal_failure_mail' => '0', 'orphan_mail' => '0'],
                ]),
                'created'         => $now,
                'created_by'      => 0,
            ];
            $db->insertObject('#__scheduler_tasks', $task);

            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('enabled') . ' = 1')
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('webgbookingreminders'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
            )->execute();
        } catch (\Throwable $e) {
            // Non-fatal: the task can still be created by hand in System - Scheduled Tasks.
        }
    }
};
