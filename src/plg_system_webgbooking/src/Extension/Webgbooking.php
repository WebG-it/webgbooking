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

        // Self-service management page + cancellation (HTML, authorised by the per-booking token).
        if ($action === 'manage') {
            $this->managePage($input->getString('token', ''));
            return;
        }
        if ($action === 'cancel') {
            $this->cancelBooking($input->getString('token', ''));
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

        // Self-service reschedule: authorised by the booking's manage token (not the Joomla CSRF).
        if ($action === 'reschedule') {
            $this->rescheduleBooking($input);
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
        $guest   = trim($input->post->getString('guest', ''));
        $guest   = ($guest !== '' && filter_var($guest, FILTER_VALIDATE_EMAIL)) ? $guest : '';

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

            // Re-validate the slot server-side: working rules + Google busy + not already taken
            // (anti double-booking — the client availability could be stale).
            $vtz   = new \DateTimeZone($this->siteTz());
            $vnow  = (new \DateTime('now', $vtz))->getTimestamp();
            $vnext = (new \DateTime($date . ' 00:00:00', $vtz))->modify('+1 day')->format('Y-m-d');
            $vslots = $this->computeDay($date, $vtz, $this->googleBusy($date, $vnext, $vtz), $vnow, $this->slotConfig());
            $taken = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('booking_date') . ' = ' . $db->quote($date))
                    ->where($db->quoteName('booking_time') . ' = ' . $db->quote($time))
                    ->where($db->quoteName('status') . ' != ' . $db->quote('cancelled'))
            )->loadResult();

            if (!\in_array($time, $vslots, true) || $taken > 0) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_SLOT_TAKEN')]);
            }

            $row = (object) [
                'created'        => Factory::getDate()->toSql(),
                'booking_date'   => $date,
                'booking_time'   => $time,
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
                'notes'          => $notes,
                'status'          => 'pending',
                'source_url'      => substr((string) $input->server->getString('HTTP_REFERER', ''), 0, 255),
                'meeting_url'     => trim((string) $this->params->get('meeting_url', '')),
                'guest_email'     => $guest,
                'manage_token'    => bin2hex(random_bytes(16)),
                'google_event_id' => '',
            ];
            $db->insertObject('#__webgbooking_bookings', $row, 'id');

            // Auto Google Meet + calendar event (sets $row->google_event_id; overrides a fixed link).
            $meet = $this->createGoogleEvent($row);
            if ($meet !== '') {
                $row->meeting_url = $meet;
            }
            $upd = (object) [
                'id'              => $row->id,
                'meeting_url'     => $row->meeting_url,
                'google_event_id' => (string) ($row->google_event_id ?? ''),
            ];
            $db->updateObject('#__webgbooking_bookings', $upd, 'id');

            // Per-element ACTIONS (HMAC-verified, signed by the server at render time).
            $actions = $this->verifyForm($input->post->get('form', '', 'RAW'));
            $cust    = $actions['customer'] ?? [];
            $staff   = $actions['staff'] ?? [];
            $row->_custOn         = !isset($cust['on']) || (bool) $cust['on'];
            $row->emailSubjectTpl = (string) ($cust['subject'] ?? '');
            $row->emailBodyTpl    = (string) ($cust['body'] ?? '');
            $row->_staffOn        = !isset($staff['on']) || (bool) $staff['on'];
            $row->_staffTo        = (string) ($staff['to'] ?? '');
            $row->_staffSubject   = (string) ($staff['subject'] ?? '');
            $row->_staffBody      = (string) ($staff['body'] ?? '');

            $this->notify($row);

            // Newsletter opt-in: forward to the configured webhook (MailUp / Zapier / Make) on consent.
            if ($input->post->getInt('newsletter', 0) === 1) {
                $this->newsletterSignup($row);
            }

            // Webhook action: per-element override → plugin global fallback (Zapier / Make / Sheets).
            $wh    = $actions['webhook'] ?? [];
            $whUrl = (!empty($wh['on']) && !empty($wh['url'])) ? (string) $wh['url'] : '';
            $this->postBookingWebhook($row, $whUrl);

            $this->respond(['ok' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_OK')]);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }
    }

    /** Verify the HMAC-signed per-element actions config from the form token. Returns the array or []. */
    private function verifyForm(string $token): array
    {
        try {
            if ($token === '' || strpos($token, '.') === false) {
                return [];
            }
            [$payload, $sig] = explode('.', $token, 2);
            $calc = hash_hmac('sha256', $payload, (string) $this->getApplication()->get('secret'));
            if (!hash_equals($calc, $sig)) {
                return [];
            }
            $cfg = json_decode((string) base64_decode($payload), true);

            return \is_array($cfg) ? $cfg : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Forward the full booking to a webhook (per-element override → plugin global). */
    private function postBookingWebhook(object $row, string $overrideUrl = ''): void
    {
        try {
            $url = $overrideUrl !== '' ? $overrideUrl : trim((string) $this->params->get('booking_webhook', ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                return;
            }

            (new HttpFactory())->getHttp()->post(
                $url,
                json_encode([
                    'event'       => 'booking.created',
                    'id'          => (int) $row->id,
                    'date'        => $row->booking_date,
                    'time'        => $row->booking_time,
                    'name'        => $row->customer_name,
                    'email'       => $row->customer_email,
                    'phone'       => $row->customer_phone,
                    'guest'       => $row->guest_email,
                    'notes'       => $row->notes,
                    'meeting_url' => $row->meeting_url,
                    'status'      => $row->status,
                ]),
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
        }
    }

    /** Forward a consented subscriber to the configured newsletter webhook (admin-set URL). */
    private function newsletterSignup(object $row): void
    {
        try {
            $url = trim((string) $this->params->get('newsletter_webhook', ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                return;
            }

            (new HttpFactory())->getHttp()->post(
                $url,
                json_encode([
                    'email'  => $row->customer_email,
                    'name'   => $row->customer_name,
                    'phone'  => $row->customer_phone,
                    'source' => 'webgbooking',
                    'date'   => $row->booking_date,
                ]),
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
        }
    }

    private function notify(object $row, bool $reschedule = false): void
    {
        try {
            $app    = $this->getApplication();
            $config = $app->getConfig();
            $admin  = trim((string) $this->params->get('notify_email', '')) ?: (string) $config->get('mailfrom');
            $mf     = Factory::getContainer()->get(MailerFactoryInterface::class);
            $manageUrl = $this->manageUrl($row);
            $ics    = $this->buildIcs($row, $reschedule ? 'REQUEST' : 'PUBLISH');

            $dateFmt = $row->booking_date;
            try {
                $dateFmt = (new \DateTime($row->booking_date))->format('d/m/Y');
            } catch (\Throwable $e) {
            }

            // ---- Customer email action (and invited guest). Skipped if the action is off. ----
            if ($reschedule || ($row->_custOn ?? true)) {
                if ($reschedule) {
                    $subject = strtr(Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_EMAIL_SUBJECT'), ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]);
                    $bodyTpl = $this->bodyTemplate('PLG_SYSTEM_WEBGBOOKING_EMAIL_RESCHEDULED');
                } else {
                    $elSub      = (string) ($row->emailSubjectTpl ?? '');
                    $elBody     = (string) ($row->emailBodyTpl ?? '');
                    $subjectTpl = $elSub !== '' ? $elSub : (trim((string) $this->params->get('email_subject', '')) ?: Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_CUSTOMER_SUBJECT'));
                    $subject    = strtr($subjectTpl, ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]);
                    $bodyTpl    = $elBody !== '' ? $elBody : (trim((string) $this->params->get('email_body', '')) ?: $this->defaultEmailBody());
                }
                $this->sendHtmlMail($mf, $row->customer_email, $subject, $this->wrapEmail($this->renderTpl($bodyTpl, $row, $manageUrl, $dateFmt)), $ics);

                // Guest invite: distinct email WITHOUT the cancel link/token.
                if (!empty($row->guest_email)) {
                    $gSubject = strtr(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_GUEST_SUBJECT'), ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]);
                    $this->sendHtmlMail($mf, $row->guest_email, $gSubject, $this->wrapEmail($this->guestEmailBody($row, $dateFmt)), $ics);
                }
            }

            // ---- Staff email action: per-element HTML template, or the plain default. ----
            if ($row->_staffOn ?? true) {
                $tos = array_values(array_filter(array_map('trim', explode(',', (string) ($row->_staffTo ?? '')))));
                if (!$tos && $admin !== '') {
                    $tos = [$admin];
                }
                if ($tos) {
                    $sSubTpl = (string) ($row->_staffSubject ?? '');
                    $sSub    = $sSubTpl !== '' ? strtr($sSubTpl, ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]) : Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_ADMIN_SUBJECT', $row->booking_date, $row->booking_time);
                    $sBody   = (string) ($row->_staffBody ?? '');
                    if ($sBody !== '') {
                        $this->sendHtmlMail($mf, $tos, $sSub, $this->wrapEmail($this->renderTpl($sBody, $row, $manageUrl, $dateFmt)), '');
                    } else {
                        $meetLine  = !empty($row->meeting_url) ? ' ' . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_MEET_LINE', $row->meeting_url) : '';
                        $guestLine = !empty($row->guest_email) ? "\n" . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_GUEST_LINE', $row->guest_email) : '';
                        $m = $mf->createMailer();
                        $m->addRecipient($tos);
                        $m->setSubject($sSub);
                        $m->setBody(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_ADMIN_BODY', $row->booking_date, $row->booking_time, $row->customer_name, $row->customer_email, $row->customer_phone ?: '-', $row->notes ?: '-') . $meetLine . $guestLine);
                        $m->Send();
                    }
                }
            }
        } catch (\Throwable $e) {
            // Email is best-effort: the booking is already stored.
        }
    }

    /** Self-service management URL (cancel / future reschedule), authorised by the per-booking token. */
    private function manageUrl(object $row): string
    {
        return Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' . rawurlencode((string) $row->manage_token);
    }

    /** Replace placeholders in a (default or admin-edited) HTML email template. */
    private function renderTpl(string $tpl, object $row, string $manageUrl, string $dateFmt): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $meetBlock = '';
        if (!empty($row->meeting_url)) {
            $meetBlock = '<p style="margin:20px 0;"><a href="' . $e($row->meeting_url) . '" style="display:inline-block;background:#1f2937;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;">'
                . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_BTN')) . '</a></p>';
        }
        $cancelBlock = '<p style="margin:18px 0 0;font-size:13px;color:#6a6a6a;"><a href="' . $e($manageUrl) . '" style="color:#1f2937;">'
            . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MANAGE_LINK')) . '</a></p>';

        return strtr($tpl, [
            '{name}'         => $e($row->customer_name),
            '{email}'        => $e($row->customer_email),
            '{date}'         => $e($dateFmt),
            '{time}'         => $e($row->booking_time),
            '{phone}'        => $e($row->customer_phone ?: ''),
            '{notes}'        => $e($row->notes ?: ''),
            '{meet_url}'     => $e($row->meeting_url),
            '{cancel_url}'   => $e($manageUrl),
            '{meet_block}'   => $meetBlock,
            '{cancel_block}' => $cancelBlock,
        ]);
    }

    /** Default, nicely-designed email body (translatable; uses {placeholders}). */
    private function defaultEmailBody(): string
    {
        return $this->bodyTemplate('PLG_SYSTEM_WEBGBOOKING_EMAIL_CONFIRMED');
    }

    /** Shared email body template with a configurable intro line. */
    private function bodyTemplate(string $introKey): string
    {
        $t   = fn($k) => htmlspecialchars(Text::_($k), ENT_QUOTES, 'UTF-8');
        $cell = 'padding:6px 0;font-size:14px;';

        return '<p style="margin:0 0 14px;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_HELLO') . ' {name},</p>'
            . '<p style="margin:0 0 18px;">' . $t($introKey) . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e6e9ee;border-radius:8px;">'
            . '<tr><td style="' . $cell . 'padding-left:14px;color:#6a6a6a;width:90px;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_DATE') . '</td><td style="' . $cell . 'font-weight:600;">{date}</td></tr>'
            . '<tr><td style="' . $cell . 'padding-left:14px;color:#6a6a6a;border-top:1px solid #eef1f4;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_TIME') . '</td><td style="' . $cell . 'font-weight:600;border-top:1px solid #eef1f4;">{time}</td></tr>'
            . '</table>'
            . '{meet_block}'
            . '<p style="margin:18px 0 0;font-size:13px;color:#6a6a6a;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_ICS_HINT') . '</p>'
            . '{cancel_block}'
            . '<p style="margin:22px 0 0;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_SIGNOFF') . '</p>';
    }

    /** Wrap the email body in a responsive, email-client-safe shell. */
    private function wrapEmail(string $inner): string
    {
        $brand = htmlspecialchars((string) $this->getApplication()->get('sitename', 'WebG Booking'), ENT_QUOTES, 'UTF-8');
        $foot  = htmlspecialchars(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_FOOTER'), ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f2f4f7;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e6e9ee;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">'
            . '<tr><td style="background:#1f2937;padding:18px 28px;color:#ffffff;font-size:16px;font-weight:600;">' . $brand . '</td></tr>'
            . '<tr><td style="padding:26px 28px;font-size:15px;line-height:1.55;">' . $inner . '</td></tr>'
            . '<tr><td style="padding:16px 28px;background:#f6f8fa;color:#8a929b;font-size:12px;line-height:1.5;border-top:1px solid #eef1f4;">' . $foot . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Send one HTML email with the optional .ics attachment. $to may be a string or an array. */
    private function sendHtmlMail($mf, $to, string $subject, string $html, string $ics): void
    {
        $m = $mf->createMailer();
        $m->isHtml(true);
        $m->addRecipient($to);
        $m->setSubject($subject);
        $m->setBody($html);
        if ($ics !== '') {
            $m->addStringAttachment($ics, 'appuntamento.ics', 'base64', 'text/calendar; charset=utf-8; method=PUBLISH');
        }
        $m->Send();
    }

    /** Guest-facing email body: an invitation, without any management/cancel link. */
    private function guestEmailBody(object $row, string $dateFmt): string
    {
        $t    = fn($k) => htmlspecialchars(Text::_($k), ENT_QUOTES, 'UTF-8');
        $e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $cell = 'padding:6px 0;font-size:14px;';

        $meetBlock = '';
        if (!empty($row->meeting_url)) {
            $meetBlock = '<p style="margin:20px 0;"><a href="' . $e($row->meeting_url) . '" style="display:inline-block;background:#1f2937;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;">'
                . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_BTN') . '</a></p>';
        }

        return '<p style="margin:0 0 14px;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_HELLO') . ',</p>'
            . '<p style="margin:0 0 18px;">' . htmlspecialchars(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_GUEST_INTRO', $row->customer_name), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e6e9ee;border-radius:8px;">'
            . '<tr><td style="' . $cell . 'padding-left:14px;color:#6a6a6a;width:90px;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_DATE') . '</td><td style="' . $cell . 'font-weight:600;">' . $e($dateFmt) . '</td></tr>'
            . '<tr><td style="' . $cell . 'padding-left:14px;color:#6a6a6a;border-top:1px solid #eef1f4;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_TIME') . '</td><td style="' . $cell . 'font-weight:600;border-top:1px solid #eef1f4;">' . $e($row->booking_time) . '</td></tr>'
            . '</table>'
            . $meetBlock
            . '<p style="margin:18px 0 0;font-size:13px;color:#6a6a6a;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_ICS_HINT') . '</p>';
    }

    /** Build an .ics (iCalendar) attachment for the appointment. $method PUBLISH (new) or REQUEST (update). */
    private function buildIcs(object $row, string $method = 'PUBLISH'): string
    {
        try {
            $tz    = new \DateTimeZone($this->siteTz());
            $utc   = new \DateTimeZone('UTC');
            $dur   = (int) $this->params->get('slot_duration', 30);
            $start = new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz);
            $end   = (clone $start)->modify('+' . $dur . ' minutes');
            $fmt   = fn(\DateTime $d) => (clone $d)->setTimezone($utc)->format('Ymd\THis\Z');
            $host  = parse_url(Uri::root(), PHP_URL_HOST) ?: 'webg';
            $esc   = fn($s) => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], (string) $s);

            $lines = [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//WebG Booking//IT', 'CALSCALE:GREGORIAN', 'METHOD:' . $method,
                'BEGIN:VEVENT',
                'UID:wgb-' . $row->id . '-' . $row->manage_token . '@' . $host,
                'DTSTAMP:' . $fmt(new \DateTime('now', $utc)),
                'DTSTART:' . $fmt($start),
                'DTEND:' . $fmt($end),
                'SUMMARY:' . $esc(Text::_('PLG_SYSTEM_WEBGBOOKING_ICS_SUMMARY')),
            ];
            if (!empty($row->meeting_url)) {
                $lines[] = 'DESCRIPTION:' . $esc($row->meeting_url);
                $lines[] = 'LOCATION:' . $esc($row->meeting_url);
                $lines[] = 'URL:' . $esc($row->meeting_url);
            }
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'SEQUENCE:' . (int) ($row->seq ?? 0);
            $lines[] = 'END:VEVENT';
            $lines[] = 'END:VCALENDAR';

            return implode("\r\n", $lines) . "\r\n";
        } catch (\Throwable $e) {
            return '';
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

            $attendees = [['email' => $row->customer_email]];
            if (!empty($row->guest_email)) {
                $attendees[] = ['email' => $row->guest_email];
            }

            $payload = [
                'summary'        => 'WebG Booking - ' . $row->customer_name,
                'description'    => (string) $row->notes,
                'start'          => ['dateTime' => $start, 'timeZone' => $tz],
                'end'            => ['dateTime' => $end, 'timeZone' => $tz],
                'attendees'      => $attendees,
                'conferenceData' => ['createRequest' => [
                    'requestId'             => 'wgb-' . bin2hex(random_bytes(6)),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ]],
            ];

            // sendUpdates=all => Google emails the calendar invite to the customer and the guest.
            $resp = (new HttpFactory())->getHttp()->post(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events?conferenceDataVersion=1&sendUpdates=all',
                json_encode($payload),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']
            );

            $data = json_decode((string) $resp->body, true) ?: [];

            if (!empty($data['id'])) {
                $row->google_event_id = (string) $data['id'];
            }

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
            'bufBefore' => max(0, (int) $this->params->get('buffer_before', 0)) * 60,
            'bufAfter'  => max(0, (int) $this->params->get('buffer_after', 0)) * 60,
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

            // Buffer padding around the slot keeps a gap before/after other events.
            $winStart = $slotStart - $c['bufBefore'];
            $winEnd   = $slotEnd + $c['bufAfter'];
            $conflict = false;
            foreach ($busy as $b) {
                if ($winStart < $b[1] && $winEnd > $b[0]) {
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

    /** Load a non-cancelled booking by its management token. */
    private function bookingByToken(string $token): ?object
    {
        if (strlen($token) < 16) {
            return null;
        }

        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('manage_token') . ' = ' . $db->quote($token))
                    ->where($db->quoteName('status') . ' != ' . $db->quote('cancelled'))
            )->loadObject();

            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Self-service page: shows the appointment and a cancel button (POST). */
    private function managePage(string $token): void
    {
        $row = $this->bookingByToken($token);
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        if (!$row) {
            $this->htmlPage(
                Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND_TITLE'),
                '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND_TITLE')) . '</h1>'
                . '<p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND')) . '</p>'
            );
        }

        $dateFmt = $row->booking_date;
        try {
            $dateFmt = (new \DateTime($row->booking_date))->format('d/m/Y');
        } catch (\Throwable $ex) {
        }

        $meet = $row->meeting_url
            ? '<tr><td>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_BTN')) . '</td><td><a href="' . $e($row->meeting_url) . '">' . $e($row->meeting_url) . '</a></td></tr>'
            : '';

        // Reschedule widget: the booking calendar in calendar-only mode, authorised by the token.
        $asset = Uri::root(true) . '/plugins/system/webgbooking/yootheme/elements/booking/assets';
        $ver   = '0.20.0';
        // The manage page is standalone (outside the YOOtheme template), so UIkit isn't present;
        // load it so the reschedule widget (icons, spinner, buttons) renders correctly.
        $uikit = 'https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist';
        $head  = '<link rel="stylesheet" href="' . $uikit . '/css/uikit.min.css">'
            . '<link rel="stylesheet" href="' . $e($asset . '/wgb.css?' . $ver) . '">'
            . '<script src="' . $uikit . '/js/uikit.min.js"></script>'
            . '<script src="' . $uikit . '/js/uikit-icons.min.js"></script>'
            . '<script src="' . $e($asset . '/wgb.js?' . $ver) . '" defer></script>';

        $cfg = [
            'locale'  => $this->getApplication()->getLanguage()->getTag(),
            'cols'    => 3,
            'mode'    => 'reschedule',
            'token'   => $row->manage_token,
            'ajaxUrl' => Uri::root(true) . '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=json',
            'labels'  => [
                'stepTime'  => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_TIME'),
                'noSlots'   => Text::_('PLG_SYSTEM_WEBGBOOKING_NO_SLOTS'),
                'back'      => Text::_('PLG_SYSTEM_WEBGBOOKING_BACK'),
                'loading'   => Text::_('PLG_SYSTEM_WEBGBOOKING_LOADING'),
                'prevMonth' => Text::_('PLG_SYSTEM_WEBGBOOKING_PREV_MONTH'),
                'nextMonth' => Text::_('PLG_SYSTEM_WEBGBOOKING_NEXT_MONTH'),
                'rsIntro'   => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_INTRO'),
                'rsConfirm' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_CONFIRM'),
                'rsOk'      => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_OK'),
                'bookErr'   => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR'),
            ],
        ];
        $cfgAttr = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        $cancelAction = Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=cancel';

        $body = '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_TITLE')) . '</h1>'
            . '<p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_INTRO')) . '</p>'
            . '<table>'
            . '<tr><td>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_DATE')) . '</td><td>' . $e($dateFmt) . '</td></tr>'
            . '<tr><td>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_TIME')) . '</td><td>' . $e($row->booking_time) . '</td></tr>'
            . '<tr><td>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_NAME')) . '</td><td>' . $e($row->customer_name) . '</td></tr>'
            . $meet
            . '</table>'
            . '<h2>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_RESCHEDULE_TITLE')) . '</h2>'
            . '<div class="wgb-booking" data-wgb="' . $cfgAttr . '"></div>'
            . '<hr>'
            . '<form method="post" action="' . $e($cancelAction) . '">'
            . '<input type="hidden" name="token" value="' . $e($row->manage_token) . '">'
            . '<button type="submit" class="btn btn-danger">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCEL_BTN')) . '</button>'
            . '</form>';

        $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_TITLE'), $body, $head);
    }

    /** Cancel a booking (POST only): delete the calendar event, notify, confirm. */
    private function cancelBooking(string $token): void
    {
        if (strtoupper((string) $this->getApplication()->getInput()->getMethod()) !== 'POST') {
            $this->managePage($token); // A GET just shows the page (prevents prefetch cancellation).
            return;
        }

        $row = $this->bookingByToken($token);
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        if (!$row) {
            $this->htmlPage(
                Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND_TITLE'),
                '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND_TITLE')) . '</h1>'
                . '<p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND')) . '</p>'
            );
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->updateObject('#__webgbooking_bookings', (object) ['id' => $row->id, 'status' => 'cancelled'], 'id');

            if (!empty($row->google_event_id)) {
                $this->deleteGoogleEvent((string) $row->google_event_id);
            }

            $this->notifyCancellation($row);
        } catch (\Throwable $ex) {
        }

        $this->htmlPage(
            Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_TITLE'),
            '<h1 class="ok">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_TITLE')) . '</h1>'
            . '<p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_MSG')) . '</p>'
        );
    }

    /** Delete the Google Calendar event (and notify its attendees) on cancellation. */
    private function deleteGoogleEvent(string $eventId): void
    {
        try {
            if ($eventId === '') {
                return;
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return;
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);
            if ($token === '') {
                return;
            }

            $cal = $g->calendar_id ?: 'primary';
            (new HttpFactory())->getHttp()->delete(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all',
                ['Authorization' => 'Bearer ' . $token]
            );
        } catch (\Throwable $e) {
        }
    }

    /** Self-service reschedule (JSON): validate the new slot, move the booking + the Google event. */
    private function rescheduleBooking($input): void
    {
        $token = $input->post->getString('token', '');
        $date  = $input->post->getString('date', '');
        $time  = $input->post->getString('time', '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }

        $row = $this->bookingByToken($token);
        if (!$row) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND')]);
        }

        if ($row->booking_date === $date && $row->booking_time === $time) {
            $this->respond(['ok' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_OK')]);
        }

        // Debounce repeated reschedules (email-bomb / Google-quota protection).
        if (!empty($row->updated) && Factory::getDate($row->updated)->toUnix() > (Factory::getDate()->toUnix() - 60)) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_TOO_SOON')]);
        }

        try {
            $tz   = new \DateTimeZone($this->siteTz());
            $now  = (new \DateTime('now', $tz))->getTimestamp();
            $next = (new \DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
            $busy = $this->googleBusy($date, $next, $tz);

            // The booking's own existing event must not block its new slot.
            if (!empty($row->google_event_id)) {
                $dur      = (int) $this->params->get('slot_duration', 30);
                $oldStart = (new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz))->getTimestamp();
                $oldEnd   = $oldStart + $dur * 60;
                $busy     = array_values(array_filter($busy, fn($b) => !($b[0] === $oldStart && $b[1] === $oldEnd)));
            }

            $slots = $this->computeDay($date, $tz, $busy, $now, $this->slotConfig());
            if (!\in_array($time, $slots, true)) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_TAKEN')]);
            }

            $seq = (int) ($row->seq ?? 0) + 1;
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $db->updateObject('#__webgbooking_bookings', (object) ['id' => $row->id, 'booking_date' => $date, 'booking_time' => $time, 'updated' => Factory::getDate()->toSql(), 'seq' => $seq], 'id');
            $row->booking_date = $date;
            $row->booking_time = $time;
            $row->seq          = $seq;

            if (!empty($row->google_event_id)) {
                $this->patchGoogleEvent($row);
            }

            $this->notify($row, true);

            $this->respond(['ok' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_OK')]);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }
    }

    /** Move an existing Google Calendar event to the booking's new start/end. */
    private function patchGoogleEvent(object $row): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return;
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);
            if ($token === '') {
                return;
            }

            $cal   = $g->calendar_id ?: 'primary';
            $tz    = $this->siteTz();
            $dur   = (int) $this->params->get('slot_duration', 30);
            $start = $row->booking_date . 'T' . $row->booking_time . ':00';
            $end   = (new \DateTime($start))->modify('+' . $dur . ' minutes')->format('Y-m-d\TH:i:s');

            (new HttpFactory())->getHttp()->patch(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode((string) $row->google_event_id) . '?sendUpdates=all',
                json_encode(['start' => ['dateTime' => $start, 'timeZone' => $tz], 'end' => ['dateTime' => $end, 'timeZone' => $tz]]),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
        }
    }

    /** Notify admin (and the customer) that a booking was cancelled. */
    private function notifyCancellation(object $row): void
    {
        try {
            $mf    = Factory::getContainer()->get(MailerFactoryInterface::class);
            $admin = trim((string) $this->params->get('notify_email', '')) ?: (string) $this->getApplication()->getConfig()->get('mailfrom');

            if ($admin !== '') {
                $m = $mf->createMailer();
                $m->addRecipient($admin);
                $m->setSubject(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_ADMIN_SUBJECT', $row->booking_date, $row->booking_time));
                $m->setBody(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_ADMIN_BODY', $row->booking_date, $row->booking_time, $row->customer_name, $row->customer_email));
                $m->Send();
            }

            $c = $mf->createMailer();
            $c->addRecipient($row->customer_email);
            $c->setSubject(Text::_('PLG_SYSTEM_WEBGBOOKING_CANCEL_CUSTOMER_SUBJECT'));
            $c->setBody(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_CUSTOMER_BODY', $row->customer_name, $row->booking_date, $row->booking_time));
            $c->Send();
        } catch (\Throwable $e) {
        }
    }

    /** Output a small, self-contained styled HTML page and stop. $head adds extra <head> tags. */
    private function htmlPage(string $title, string $body, string $head = ''): void
    {
        $css = 'body{margin:0;background:#f2f4f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937}'
            . '.wrap{max-width:560px;margin:48px auto;padding:0 16px}'
            . '.card{background:#fff;border:1px solid #e6e9ee;border-radius:12px;padding:26px 28px}'
            . 'h1{font-size:20px;margin:0 0 10px}h2{font-size:15px;margin:26px 0 6px}.muted{color:#6a6a6a;font-size:14px;line-height:1.5}'
            . 'table{width:100%;border-collapse:collapse;margin:18px 0}td{padding:9px 0;font-size:14px;border-top:1px solid #eef1f4}'
            . 'tr:first-child td{border-top:0}td:first-child{color:#6a6a6a;width:130px}'
            . '.btn{display:inline-block;border:0;border-radius:8px;padding:11px 22px;font-weight:600;font-size:14px;cursor:pointer;text-decoration:none}'
            . '.btn-danger{background:#c0392b;color:#fff}.btn-ghost{background:#eef1f4;color:#1f2937}.ok{color:#1d6b3f}a{color:#1f2937}'
            . 'hr{border:0;border-top:1px solid #eef1f4;margin:22px 0}';

        echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>' . $css . '</style>' . $head . '</head>'
            . '<body><div class="wrap"><div class="card">' . $body . '</div></div></body></html>';

        $this->getApplication()->close();
    }

    private function respond(array $data): void
    {
        echo json_encode($data);
        $this->getApplication()->close();
    }
}
