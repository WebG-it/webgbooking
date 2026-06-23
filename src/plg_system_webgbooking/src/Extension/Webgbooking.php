<?php

/**
 * @package     plg_system_webgbooking
 * @author      Marco Galassi (WebG)
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Registers the WebG Booking element into the YOOtheme Pro builder, without a child theme.
 * YOOtheme's Application is a singleton (Application::getInstance()); we load our module
 * bootstrap into it after YOOtheme has booted (lower event priority). Verified against
 * YOOtheme Pro 5.0.35.
 */

namespace WebG\Plugin\System\Webgbooking\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;

final class Webgbooking extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        // LOW priority so YOOtheme's onAfterInitialise (HIGH) has created its Application first.
        return ['onAfterInitialise' => ['onAfterInitialise', Priority::LOW]];
    }

    public function onAfterInitialise(): void
    {
        // YOOtheme Pro not installed/active → nothing to do.
        if (!class_exists(\YOOtheme\Application::class)) {
            return;
        }

        $app = $this->getApplication();

        // Only run where YOOtheme itself boots its app (frontend, or the builder/customizer
        // editing contexts in admin). Mirrors YOOtheme's own template_bootstrap guard so we
        // never spawn a stray Application instance in unrelated requests.
        $option = $app->getInput()->getCmd('option', '');
        $editing = $app->isClient('administrator')
            && \in_array($option, ['com_ajax', 'com_templates', 'com_modules', 'com_content'], true);

        if (!$app->isClient('site') && !$editing) {
            return;
        }

        try {
            \YOOtheme\Application::getInstance()->load(__DIR__ . '/../../yootheme/bootstrap.php');
        } catch (\Throwable $e) {
            // Best-effort: never break the site if YOOtheme internals change.
        }
    }
}
