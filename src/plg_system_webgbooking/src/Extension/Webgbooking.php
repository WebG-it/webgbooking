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
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
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
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $action = $input->getCmd('action', 'book');

        // OAuth callback from Google (redirects to admin, not JSON).
        if ($action === 'oauth') {
            $this->oauthCallback($input->get('code', '', 'RAW'), $input->get('state', '', 'RAW'));
            return;
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();

        // Cache-safe CSRF: the widget fetches a fresh token right before submitting.
        if ($action === 'token') {
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

    /**
     * Google OAuth callback: exchange the code for tokens, store the encrypted
     * refresh token, then redirect back to the component admin.
     */
    private function oauthCallback(string $code, string $state): void
    {
        $app   = $this->getApplication();
        $admin = Uri::root() . 'administrator/index.php?option=com_webgbooking&view=google';

        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$row || $state === '' || !hash_equals((string) $row->oauth_state, $state)) {
                $app->redirect($admin . '&wgberror=state');
                return;
            }

            $cid      = trim((string) $this->params->get('google_client_id', ''));
            $csec     = trim((string) $this->params->get('google_client_secret', ''));
            $redirect = Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=raw&action=oauth';

            $resp = (new HttpFactory())->getHttp()->post(
                'https://oauth2.googleapis.com/token',
                [
                    'code'          => $code,
                    'client_id'     => $cid,
                    'client_secret' => $csec,
                    'redirect_uri'  => $redirect,
                    'grant_type'    => 'authorization_code',
                ],
                ['Content-Type' => 'application/x-www-form-urlencoded']
            );

            $data    = json_decode((string) $resp->body, true) ?: [];
            $refresh = $data['refresh_token'] ?? '';
            $email   = '';

            if (!empty($data['id_token'])) {
                $parts = explode('.', $data['id_token']);
                if (isset($parts[1])) {
                    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true) ?: [];
                    $email   = $payload['email'] ?? '';
                }
            }

            if ($refresh === '') {
                $app->redirect($admin . '&wgberror=token');
                return;
            }

            $db->updateObject('#__webgbooking_google', (object) [
                'id'            => $row->id,
                'refresh_token' => $this->enc($refresh),
                'account_email' => $email,
                'oauth_state'   => '',
                'updated'       => Factory::getDate()->toSql(),
            ], 'id');

            $app->redirect($admin . '&wgbconnected=1');
        } catch (\Throwable $e) {
            $app->redirect($admin . '&wgberror=exchange');
        }
    }

    private function enc(string $plain): string
    {
        $key = hash('sha256', (string) $this->getApplication()->get('secret'), true);
        $iv  = random_bytes(16);

        return base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
    }

    private function dec(string $b64): string
    {
        $raw = base64_decode($b64);
        $key = hash('sha256', (string) $this->getApplication()->get('secret'), true);

        return (string) openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }

    private function respond(array $data): void
    {
        echo json_encode($data);
        $this->getApplication()->close();
    }
}
