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

        // Whole-month availability: the single source for selectable days + their slots.
        if ($action === 'month') {
            $this->monthSlots($input->getCmd('from', ''), $input->getCmd('to', ''));
        }

        // Single-day slots (widget fallback), computed server-side in the site timezone.
        if ($action === 'slots') {
            $this->availableSlots($input->getCmd('date', ''));
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

        // Honeypot: bots fill the hidden field. Pretend success and drop the submission.
        if (trim((string) $input->post->getString('website', '')) !== '') {
            $this->respond(['ok' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_OK')]);
        }

        if (
            $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $privacy !== 1
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)
        ) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            // Basic rate limit: at most 3 bookings per email per hour.
            $recent = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('customer_email') . ' = ' . $db->quote($email))
                    ->where($db->quoteName('created') . ' > ' . $db->quote(Factory::getDate('-1 hour')->toSql()))
            )->loadResult();

            if ($recent >= 3) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
            }

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

            // Auto Google Meet (overrides the fixed link if Google is connected).
            $meet = $this->createGoogleEvent($row);
            if ($meet !== '') {
                $row->meeting_url = $meet;
                $upd = (object) ['id' => $row->id, 'meeting_url' => $meet];
                $db->updateObject('#__webgbooking_bookings', $upd, 'id');
            }

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

            $upd = (object) [
                'id'            => $row->id,
                'refresh_token' => $this->enc($refresh),
                'account_email' => $email,
                'oauth_state'   => '',
                'updated'       => Factory::getDate()->toSql(),
            ];
            $db->updateObject('#__webgbooking_google', $upd, 'id');

            $app->redirect($admin . '&wgbconnected=1');
        } catch (\Throwable $e) {
            $app->redirect($admin . '&wgberror=' . rawurlencode('exchange: ' . $e->getMessage()));
        }
    }

    private function encKey(): string
    {
        return hash('sha256', (string) $this->getApplication()->get('secret'), true);
    }

    /** Authenticated encryption (AES-256-GCM), prefixed 'g:'. */
    private function enc(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $this->encKey(), OPENSSL_RAW_DATA, $iv, $tag);

        return 'g:' . base64_encode($iv . $tag . $ct);
    }

    /** Decrypts GCM ('g:' prefix) or legacy CBC values. */
    private function dec(string $stored): string
    {
        $key = $this->encKey();

        if (strncmp($stored, 'g:', 2) === 0) {
            $raw = base64_decode(substr($stored, 2));

            return (string) openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        }

        $raw = base64_decode($stored);

        return (string) openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }

    /** Exchange the (encrypted) refresh token for a Google access token. */
    private function googleAccessToken(string $refreshEnc): string
    {
        $resp = (new HttpFactory())->getHttp()->post(
            'https://oauth2.googleapis.com/token',
            [
                'client_id'     => trim((string) $this->params->get('google_client_id', '')),
                'client_secret' => trim((string) $this->params->get('google_client_secret', '')),
                'refresh_token' => $this->dec($refreshEnc),
                'grant_type'    => 'refresh_token',
            ],
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        return json_decode((string) $resp->body, true)['access_token'] ?? '';
    }

    /**
     * Create a Google Calendar event with an auto-generated Google Meet link.
     * Returns the Meet URL, or '' if Google is not connected / on any failure.
     */
    private function createGoogleEvent(object $row): string
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return '';
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);

            if ($token === '') {
                return '';
            }

            $cal   = $g->calendar_id ?: 'primary';
            $tz    = $this->siteTz();
            $dur   = (int) $this->params->get('slot_duration', 30);
            $start = $row->booking_date . 'T' . $row->booking_time . ':00';
            $end   = (new \DateTime($start))->modify('+' . $dur . ' minutes')->format('Y-m-d\TH:i:s');

            $payload = [
                'summary'        => 'WebG Booking - ' . $row->customer_name,
                'description'    => (string) $row->notes,
                'start'          => ['dateTime' => $start, 'timeZone' => $tz],
                'end'            => ['dateTime' => $end, 'timeZone' => $tz],
                'attendees'      => [['email' => $row->customer_email]],
                'conferenceData' => ['createRequest' => [
                    'requestId'             => 'wgb-' . bin2hex(random_bytes(6)),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ]],
            ];

            $resp = (new HttpFactory())->getHttp()->post(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events?conferenceDataVersion=1',
                json_encode($payload),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']
            );

            $data = json_decode((string) $resp->body, true) ?: [];

            if (!empty($data['hangoutLink'])) {
                return $data['hangoutLink'];
            }

            foreach (($data['conferenceData']['entryPoints'] ?? []) as $ep) {
                if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) {
                    return $ep['uri'];
                }
            }

            return '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** A valid timezone identifier for the site (authoritative for slot times). */
    private function siteTz(): string
    {
        $tz = (string) ($this->getApplication()->get('offset') ?: date_default_timezone_get());

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            $tz = 'UTC';
        }

        return $tz;
    }

    /**
     * Authoritative slot computation for a day, in the SITE timezone:
     * working hours / interval / duration, minus min-notice and Google busy times.
     */
    /** Availability rules read once from the plugin params. */
    private function slotConfig(): array
    {
        $ws = explode(':', (string) $this->params->get('work_start', '09:00'));
        $we = explode(':', (string) $this->params->get('work_end', '18:00'));
        $wd = $this->params->get('work_days', [1, 2, 3, 4, 5]);
        $wd = array_values(array_map('intval', \is_array($wd) ? $wd : explode(',', (string) $wd)));

        return [
            'start'  => ((int) ($ws[0] ?? 9)) * 60 + (int) ($ws[1] ?? 0),
            'end'    => ((int) ($we[0] ?? 18)) * 60 + (int) ($we[1] ?? 0),
            'iv'     => max(5, (int) $this->params->get('slot_interval', 30)),
            'dur'    => max(5, (int) $this->params->get('slot_duration', 30)),
            'notice' => (int) $this->params->get('min_notice', 2) * 3600,
            'window' => max(1, (int) $this->params->get('window_days', 30)),
            'days'   => $wd ?: [1, 2, 3, 4, 5],
        ];
    }

    /** Available "HH:MM" slots for one day, given pre-fetched busy intervals. */
    private function computeDay(string $dayStr, \DateTimeZone $tz, array $busy, int $now, array $c): array
    {
        $slots = [];

        for ($m = $c['start']; $m + $c['dur'] <= $c['end']; $m += $c['iv']) {
            $hh = intdiv($m, 60);
            $mm = $m % 60;
            $slotStart = (new \DateTime(sprintf('%s %02d:%02d:00', $dayStr, $hh, $mm), $tz))->getTimestamp();
            $slotEnd   = $slotStart + $c['dur'] * 60;

            if ($slotStart < $now + $c['notice']) {
                continue;
            }

            $conflict = false;
            foreach ($busy as $b) {
                if ($slotStart < $b[1] && $slotEnd > $b[0]) {
                    $conflict = true;
                    break;
                }
            }

            if (!$conflict) {
                $slots[] = sprintf('%02d:%02d', $hh, $mm);
            }
        }

        return $slots;
    }

    /** Slots for a single day (used as a fallback by the widget). */
    private function availableSlots(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->respond(['slots' => []]);
        }

        try {
            $tz   = new \DateTimeZone($this->siteTz());
            $now  = (new \DateTime('now', $tz))->getTimestamp();
            $next = (new \DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
            $busy = $this->googleBusy($date, $next, $tz);

            $this->respond(['slots' => $this->computeDay($date, $tz, $busy, $now, $this->slotConfig())]);
        } catch (\Throwable $e) {
            $this->respond(['slots' => []]);
        }
    }

    /**
     * Authoritative month availability: the SINGLE source the calendar uses to
     * decide which days are selectable AND which slots each day offers.
     * Returns { days: { "Y-m-d": ["HH:MM", ...] } } only for days with >=1 slot.
     */
    private function monthSlots(string $from, string $to): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $this->respond(['days' => (object) []]);
        }

        try {
            $tz    = new \DateTimeZone($this->siteTz());
            $now   = (new \DateTime('now', $tz))->getTimestamp();
            $c     = $this->slotConfig();
            $maxTs = (new \DateTime('today', $tz))->modify('+' . $c['window'] . ' days')->getTimestamp();

            $cur  = new \DateTime($from . ' 00:00:00', $tz);
            $last = new \DateTime($to . ' 00:00:00', $tz);

            // Cap the scanned range to a sane bound regardless of the requested window.
            if ($last->getTimestamp() - $cur->getTimestamp() > 86400 * 62) {
                $last = (clone $cur)->modify('+62 days');
            }

            $busyTo = (clone $last)->modify('+1 day')->format('Y-m-d');
            $busy   = $this->googleBusy($cur->format('Y-m-d'), $busyTo, $tz);

            $days = [];

            while ($cur <= $last) {
                $dts    = $cur->getTimestamp();
                $dayStr = $cur->format('Y-m-d');

                if ($dts + 86400 > $now && $dts <= $maxTs && \in_array((int) $cur->format('w'), $c['days'], true)) {
                    $slots = $this->computeDay($dayStr, $tz, $busy, $now, $c);
                    if ($slots) {
                        $days[$dayStr] = $slots;
                    }
                }

                $cur->modify('+1 day');
            }

            $this->respond(['days' => $days ?: (object) []]);
        } catch (\Throwable $e) {
            $this->respond(['days' => (object) []]);
        }
    }

    /** Google busy intervals over [from, toExclusive) as [startTs, endTs] pairs (empty if not connected). */
    private function googleBusy(string $from, string $toExclusive, \DateTimeZone $tz): array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return [];
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);

            if ($token === '') {
                return [];
            }

            $reads = json_decode((string) ($g->read_calendars ?? ''), true);
            $reads = \is_array($reads) && $reads ? $reads : [$g->calendar_id ?: 'primary'];
            $min   = (new \DateTime($from . ' 00:00:00', $tz))->format(\DateTime::RFC3339);
            $max   = (new \DateTime($toExclusive . ' 00:00:00', $tz))->format(\DateTime::RFC3339);

            $resp = (new HttpFactory())->getHttp()->post(
                'https://www.googleapis.com/calendar/v3/freeBusy',
                json_encode([
                    'timeMin' => $min,
                    'timeMax' => $max,
                    'items'   => array_map(fn($id) => ['id' => $id], $reads),
                ]),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']
            );

            $data = (array) json_decode((string) $resp->body, true);
            $busy = [];

            foreach (($data['calendars'] ?? []) as $c) {
                foreach (($c['busy'] ?? []) as $b) {
                    if (isset($b['start'], $b['end'])) {
                        $busy[] = [strtotime($b['start']), strtotime($b['end'])];
                    }
                }
            }

            return $busy;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function respond(array $data): void
    {
        echo json_encode($data);
        $this->getApplication()->close();
    }
}
