<?php

/**
 * @package     plg_system_webgbooking
 * @author      Marco Galassi (WebG)
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * - Registers the WebG Booking element into the YOOtheme Pro builder (singleton hook).
 * - Handles the booking submission via com_ajax (group=system, plugin=webgbooking):
 *   action=token returns a fresh CSRF token (cache-safe), action=book validates, stores
 *   and notifies. Verified against YOOtheme Pro 5.0.35 / Joomla 6.
 */

namespace WebG\Plugin\System\Webgbooking\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;

final class Webgbooking extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => ['onAfterInitialise', Priority::LOW],
            'onAjaxWebgbooking' => 'onAjax',
        ];
    }

    public function onAfterInitialise(): void
    {
        if (!class_exists(\YOOtheme\Application::class)) {
            return;
        }

        $app = $this->getApplication();
        $option = $app->getInput()->getCmd('option', '');
        $editing = $app->isClient('administrator')
            && \in_array($option, ['com_ajax', 'com_templates', 'com_modules', 'com_content'], true);

        if (!$app->isClient('site') && !$editing) {
            return;
        }

        try {
            \YOOtheme\Application::getInstance()->load(__DIR__ . '/../../yootheme/bootstrap.php');
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * com_ajax endpoint. Outputs JSON and stops, so we control the response exactly.
     */
    public function onAjax(Event $event): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();

        // Cache-safe CSRF: the widget fetches a fresh token right before submitting.
        if ($input->getCmd('action', 'book') === 'token') {
            $this->respond(['token' => Session::getFormToken()]);
        }

        if (!Session::checkToken('request')) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_INVALID_TOKEN')]);
        }

        $date    = $input->post->getString('date', '');
        $time    = $input->post->getString('time', '');
        $name    = trim($input->post->getString('name', ''));
        $email   = trim($input->post->getString('email', ''));
        $phone   = trim($input->post->getString('phone', ''));
        $notes   = trim((string) $input->post->get('notes', '', 'STRING'));
        $privacy = (int) $input->post->getInt('privacy', 0);

        if (
            $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $privacy !== 1
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)
        ) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }

        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = (object) [
                'created'        => Factory::getDate()->toSql(),
                'booking_date'   => $date,
                'booking_time'   => $time,
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'notes'          => $notes,
                'status'         => 'pending',
                'source_url'     => substr((string) $input->server->getString('HTTP_REFERER', ''), 0, 255),
                'meeting_url'    => trim((string) $this->params->get('meeting_url', '')),
            ];
            $db->insertObject('#__webgbooking_bookings', $row);

            $this->notify($row);

            $this->respond(['ok' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_OK')]);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }
    }

    private function notify(object $row): void
    {
        try {
            $app    = $this->getApplication();
            $config = $app->getConfig();
            $admin  = trim((string) $this->params->get('notify_email', '')) ?: (string) $config->get('mailfrom');
            $mf     = Factory::getContainer()->get(MailerFactoryInterface::class);

            $meetLine = $row->meeting_url !== ''
                ? ' ' . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_MEET_LINE', $row->meeting_url)
                : '';

            // Admin notification
            if ($admin !== '') {
                $m = $mf->createMailer();
                $m->addRecipient($admin);
                $m->setSubject(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_ADMIN_SUBJECT', $row->booking_date, $row->booking_time));
                $m->setBody(Text::sprintf(
                    'PLG_SYSTEM_WEBGBOOKING_EMAIL_ADMIN_BODY',
                    $row->booking_date,
                    $row->booking_time,
                    $row->customer_name,
                    $row->customer_email,
                    $row->customer_phone ?: '-',
                    $row->notes ?: '-'
                ) . $meetLine);
                $m->Send();
            }

            // Customer confirmation
            $c = $mf->createMailer();
            $c->addRecipient($row->customer_email);
            $c->setSubject(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_CUSTOMER_SUBJECT'));
            $c->setBody(Text::sprintf(
                'PLG_SYSTEM_WEBGBOOKING_EMAIL_CUSTOMER_BODY',
                $row->customer_name,
                $row->booking_date,
                $row->booking_time
            ) . $meetLine);
            $c->Send();
        } catch (\Throwable $e) {
            // Email is best-effort: the booking is already stored.
        }
    }

    private function respond(array $data): void
    {
        echo json_encode($data);
        $this->getApplication()->close();
    }
}
