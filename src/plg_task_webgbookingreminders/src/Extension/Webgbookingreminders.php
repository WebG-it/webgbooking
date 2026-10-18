<?php

/**
 * @package     plg_task_webgbookingreminders
 * @author      Marco Galassi (WebG)
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Task Scheduler routine that sends the WebG Booking appointment reminders. The logic itself lives in the
 * booking system plugin (runReminders); this plugin only gives it a slot in Joomla's Scheduled Tasks, which
 * run from the lazy scheduler (site traffic) or from a system cron, whichever the site uses.
 */

namespace WebG\Plugin\Task\Webgbookingreminders\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

final class Webgbookingreminders extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /** Routine id: it is the `type` of the scheduled task row. */
    public const ROUTINE = 'plg_task_webgbooking_reminders';

    /** @var string[] */
    protected const TASKS_MAP = [
        self::ROUTINE => [
            'langConstPrefix' => 'PLG_TASK_WEBGBOOKINGREMINDERS_TASK',
            'method'          => 'sendReminders',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask'     => 'standardRoutineHandler',
        ];
    }

    /** Send the due reminders through the booking system plugin. */
    protected function sendReminders(ExecuteTaskEvent $event): int
    {
        try {
            if (!PluginHelper::isEnabled('system', 'webgbooking')) {
                $this->logTask('WebG Booking: the system plugin is disabled, no reminders sent', 'warning');

                return TaskStatus::OK;
            }

            $app    = Factory::getApplication();
            $system = method_exists($app, 'bootPlugin') ? $app->bootPlugin('webgbooking', 'system') : null;

            if (!$system || !method_exists($system, 'runReminders')) {
                $this->logTask('WebG Booking: reminder engine not found (is the package up to date?)', 'error');

                return TaskStatus::NO_RUN;
            }

            $r = $system->runReminders();
            // Paid events: unpaid holds past their time free the slot (0.32.0).
            $expired = method_exists($system, 'expireHolds') ? (int) $system->expireHolds() : 0;
            $this->logTask(sprintf('WebG Booking reminders: %d checked, %d sent, %d failed; %d unpaid holds expired', $r['checked'] ?? 0, $r['sent'] ?? 0, $r['failed'] ?? 0, $expired));

            return TaskStatus::OK;
        } catch (\Throwable $e) {
            $this->logTask('WebG Booking reminders failed: ' . $e->getMessage(), 'error');

            return TaskStatus::KNOCKOUT;
        }
    }
}
