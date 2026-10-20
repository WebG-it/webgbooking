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

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use WebG\Plugin\System\Webgbooking\Mail\EmailLayout;

final class Webgbooking extends CMSPlugin implements SubscriberInterface
{
    /** Cache-buster for the bundled front-end assets (wgb.css/js, UIkit). Bump when those change. */
    public const ASSET_VER = '0.34.5';

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => ['onAfterInitialise', Priority::LOW],
            'onAjaxWebgbooking' => 'onAjax',
            'onExtensionBeforeSave' => 'onExtensionBeforeSave',
            'onBeforeCompileHead' => 'onBeforeCompileHead',
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

        // Stripe Checkout: return and cancel pages (HTML). The return page re-reads the session from Stripe.
        if ($action === 'payreturn') {
            $this->payReturn($input->getString('token', ''), $input->getString('session_id', ''));
            return;
        }
        if ($action === 'paycancel') {
            $this->payCancel($input->getString('token', ''));
            return;
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->privacyHeaders($app);
        $app->sendHeaders();

        // Stripe webhook: signed by Stripe, no Joomla session/CSRF.
        if ($action === 'stripewebhook') {
            $this->stripeWebhook();
        }

        // Cache-safe CSRF: the widget fetches a fresh token right before submitting.
        if ($action === 'token') {
            $this->respond(['token' => Session::getFormToken()]);
        }

        // Event config of the element (duration / buffers), signed at render time. Missing or tampered
        // tokens (old cached pages) fall back to the plugin-wide defaults.
        $ev = $this->evFromToken((string) $input->get('ev', '', 'RAW'));

        // Whole-month availability: the single source for selectable days + their slots.
        if ($action === 'month') {
            $this->monthSlots($input->getCmd('from', ''), $input->getCmd('to', ''), $ev);
        }

        // Single-day slots (widget fallback), computed server-side in the site timezone.
        if ($action === 'slots') {
            $this->availableSlots($input->getCmd('date', ''), $ev);
        }

        // Self-service reschedule: authorised by the booking's manage token (not the Joomla CSRF).
        if ($action === 'reschedule') {
            $this->rescheduleBooking($input);
        }

        if (!Session::checkToken('request')) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_INVALID_TOKEN')]);
        }

        $this->handleBook($input);
    }

    /** Create a booking (POST, CSRF-protected). Always answers with JSON and stops. */
    private function handleBook($input): void
    {
        $date    = $input->post->getString('date', '');
        $time    = $input->post->getString('time', '');
        $name    = mb_substr(trim($input->post->getString('name', '')), 0, 190);
        $email   = mb_substr(trim($input->post->getString('email', '')), 0, 190);
        $phone   = mb_substr(trim($input->post->getString('phone', '')), 0, 60);
        $notes   = mb_substr(trim((string) $input->post->get('notes', '', 'STRING')), 0, 2000);
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

        // Per-element ACTIONS + custom-field DEFINITIONS + EVENT TYPE (HMAC-verified: signed by the server
        // at render time, so the client cannot inject fields, turn "required" off or pick another duration).
        $actions = $this->verifyForm($input->post->get('form', '', 'RAW'));
        if (!$actions) {
            // No valid signed config = not our widget (or a forged price/duration): refuse. Without this a
            // hand-made request could book a paid event as a free default one.
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_INVALID_TOKEN')]);
        }
        $ev      = $this->resolveEvent((array) ($actions['event'] ?? []));
        // The newsletter tick counts only when the element really shows the box (signed in the config): the visitor
        // controls the POST field, never the consent rule. Tokens from before 0.34.1 carry no flag: tick ignored.
        $newsletter = !empty($actions['showNewsletter']) && $input->post->getInt('newsletter', 0) === 1;

        // Where the meeting happens: fixed by the element, or chosen by the customer.
        $loc = $this->resolveLocation($ev['loc'], $input->post->getCmd('location', ''));
        if ($loc === '') {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }
        if ($loc === 'phone' && !self::phoneOk($phone)) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PHONE_REQUIRED')]);
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $nowSql = Factory::getDate()->toSql();
            $hourAgo = Factory::getDate('-1 hour')->toSql();
            $ipHash = $this->clientIpHash($input);
            // Rows that never counted: unpaid holds that lapsed and expired ones (a visitor who abandoned Stripe
            // twice must not be locked out of booking).
            $live = '(' . $db->quoteName('status') . ' != ' . $db->quote('expired')
                . ' AND NOT (' . $db->quoteName('status') . ' = ' . $db->quote('awaiting_payment') . ' AND ' . $db->quoteName('hold_until') . ' < ' . $db->quote($nowSql) . '))';

            // Rate limit per email: at most 3 live bookings per hour.
            $recent = (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('customer_email') . ' = ' . $db->quote($email))
                    ->where($db->quoteName('created') . ' > ' . $db->quote($hourAgo))
                    ->where($live)
            )->loadResult();
            if ($recent >= 3) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
            }

            // Per IP (hashed with the site secret, the address itself is not stored): bookings per hour and open holds.
            if ($ipHash !== '') {
                $perIp = (int) $db->setQuery(
                    $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_bookings'))
                        ->where($db->quoteName('ip_hash') . ' = ' . $db->quote($ipHash))
                        ->where($db->quoteName('created') . ' > ' . $db->quote($hourAgo))
                        ->where($live)
                )->loadResult();
                if ($perIp >= max(1, (int) $this->params->get('limit_ip_hour', 15))) {
                    $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_TOO_MANY')]);
                }
            }

            $customData = $this->collectCustomFields($actions['fields'] ?? [], $input);
            if ($customData === false) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
            }

            $vtz   = new \DateTimeZone($this->siteTz());
            $vnow  = (new \DateTime('now', $vtz))->getTimestamp();
            $vnext = (new \DateTime($date . ' 00:00:00', $vtz))->modify('+1 day')->format('Y-m-d');
            $cfg   = $this->slotConfig($ev);
            $busy  = $this->googleBusy($date, $vnext, $vtz, false); // fresh network call BEFORE taking the lock
            if ($busy === null) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_CAL_UNAVAILABLE')]);
            }

            $price  = (array) ($actions['price'] ?? []);
            if (!empty($price['invalid'])) {
                // The price field of this element could not be read (ambiguous amount, unsupported currency):
                // refuse instead of selling it for free.
                $this->alertStaff('price-invalid', Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_PRICE_SUBJECT'), Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_PRICE_BODY'), 24);
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_UNAVAILABLE')]);
            }
            $priced = (int) ($price['amt'] ?? 0) > 0 && \in_array(strtoupper((string) ($price['cur'] ?? 'EUR')), self::CURRENCIES, true);
            $sk     = '';
            if ($priced) {
                $sk = $this->stripeSecretOpt('stripe_sk');
                if ($sk === '') {
                    // Paid event but Stripe is not configured: never take a booking we cannot charge for.
                    $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_UNAVAILABLE')]);
                }
                // The key must belong to the configured mode (a live key with "test" selected, or the opposite, is a misconfiguration).
                $keyMode = preg_match('/^(sk|rk)_live_/', $sk) ? 'live' : (preg_match('/^(sk|rk)_test_/', $sk) ? 'test' : '');
                if ($keyMode === '' || $keyMode !== ($this->stripeLive() ? 'live' : 'test')) {
                    $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_PAY_KEYMODE', $keyMode, ($this->stripeLive() ? 'live' : 'test')));
                    $this->alertStaff('key-mode', Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_KEYMODE_SUBJECT'), Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_KEYMODE_BODY'), 12);
                    $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_UNAVAILABLE')]);
                }

                // Open holds are a scarce resource: per IP and globally.
                if ($ipHash !== '') {
                    $ipHolds = (int) $db->setQuery(
                        $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_bookings'))
                            ->where($db->quoteName('ip_hash') . ' = ' . $db->quote($ipHash))
                            ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
                            ->where($db->quoteName('hold_until') . ' >= ' . $db->quote($nowSql))
                    )->loadResult();
                    if ($ipHolds >= max(1, (int) $this->params->get('max_holds_ip', 2))) {
                        $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_TOO_MANY')]);
                    }
                }
                $allHolds = (int) $db->setQuery(
                    $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_bookings'))
                        ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
                        ->where($db->quoteName('hold_until') . ' >= ' . $db->quote($nowSql))
                )->loadResult();
                if ($allHolds >= max(1, (int) $this->params->get('max_open_holds', 60))) {
                    $this->alertStaff('holds-cap', Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_HOLDS_SUBJECT'), Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_HOLDS_BODY', $allHolds), 6);
                    $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_TOO_MANY')]);
                }
            }

            $rem         = $this->reminderPlan((array) ($actions['reminder'] ?? []));
            // The scheduler can run from a CLI cron where Joomla does not know the public URL: keep the site
            // root with the plan so the "manage / cancel" link in a reminder is always the real one.
            $rem['root'] = Uri::root();
            $startTs = (new \DateTime($date . ' ' . $time . ':00', $vtz))->getTimestamp();

            // Re-validate the slot server-side and insert it under a per-day lock: working rules + Google busy
            // + every existing booking (with ITS duration and buffers) + insert are one atomic step, so two
            // visitors can never get the same (or an overlapping) slot (TOCTOU / double booking).
            $lockName = $this->lockName($db, $date);
            if (!$this->acquireLock($db, $lockName)) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_SLOT_TAKEN')]);
            }

            $row   = null;
            $taken = false;
            try {
                $vslots = $this->computeDay($date, $vtz, $busy, $vnow, $cfg, $this->localBookings($date, $vnext, $vtz));

                if (!\in_array($time, $vslots, true)) {
                    $taken = true;
                } else {
                    $row = (object) [
                        'created'         => Factory::getDate()->toSql(),
                        'booking_date'    => $date,
                        'booking_time'    => $time,
                        'customer_name'   => $name,
                        'customer_email'  => $email,
                        'customer_phone'  => $phone,
                        'notes'           => $notes,
                        // Free bookings are confirmed at once; the element can keep the old "I confirm each one" flow.
                        'status'          => !empty($actions['manual']) ? 'pending' : 'confirmed',
                        'staff_to'        => $this->cleanRecipients((string) (($actions['staff'] ?? [])['to'] ?? '')),
                        // Where the element's webhook points (no secrets: the header profile is only a NAME), for the later events.
                        'hook_cfg'        => $this->hookCfgJson($actions),
                        // The element's customer-email settings, for what happens after creation (admin "confirm and tell the customer").
                        'cust_cfg'        => (string) json_encode([
                            'on'      => !isset(($actions['customer'] ?? [])['on']) || (bool) $actions['customer']['on'],
                            'subject' => (string) (($actions['customer'] ?? [])['subject'] ?? ''),
                            'body'    => (string) (($actions['customer'] ?? [])['body'] ?? ''),
                        ]),
                        'source_url'      => substr((string) $input->server->getString('HTTP_REFERER', ''), 0, 255),
                        'meeting_url'     => $loc === 'phone' ? '' : trim((string) $this->params->get('meeting_url', '')),
                        'guest_email'     => $guest,
                        'manage_token'    => bin2hex(random_bytes(16)),
                        'google_event_id' => '',
                        'custom_data'     => $customData,
                        'duration_min'    => $ev['dur'],
                        'buffer_before'   => $ev['bb'],
                        'buffer_after'    => $ev['ba'],
                        'location'        => $loc,
                        'event_name'      => $ev['name'],
                        'reminder_cfg'    => (string) json_encode($rem),
                        'reminders_sent'  => (string) json_encode($this->pastOffsets($rem, $startTs, $vnow)),
                        'ip_hash'         => $ipHash,
                        'avail_cfg'       => (string) json_encode(array_filter(['ws' => $ev['ws'], 'we' => $ev['we'], 'wd' => $ev['wd'], 'nt' => $ev['nt'], 'wn' => $ev['wn']], static fn($v) => $v !== null)),
                        // Kept until the effects are done (or the hold dies): lets a retry resume. Cleared after.
                        'pending_ctx'     => (string) json_encode([
                            'actions'    => $actions,
                            'newsletter' => $newsletter ? 1 : 0,
                            'redirect'   => (string) ($actions['redirect'] ?? ''),
                            'analytics'  => (string) ($actions['analytics'] ?? ''),
                        ]),
                    ];
                    if (!$priced) {
                        $row->fx_state = 'pending';
                        $row->fx_steps = '{}';
                        $row->fx_tries = 0;
                    }
                    if ($priced) {
                        // Slot is HELD (not confirmed) until Stripe says paid: nothing is sent or created yet.
                        $row->status         = 'awaiting_payment';
                        $row->payment_status = 'pending';
                        $row->amount_cents   = (int) $price['amt'];
                        $row->currency       = strtoupper(substr((string) ($price['cur'] ?? 'EUR'), 0, 3));
                        $row->hold_until     = Factory::getDate('+' . $this->holdMinutes() . ' minutes')->toSql();
                    }
                    $db->insertObject('#__webgbooking_bookings', $row, 'id');
                }
            } finally {
                $this->releaseLock($db, $lockName);
            }

            if ($taken || $row === null) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_SLOT_TAKEN')]);
            }

            if ($priced) {
                $session = $this->stripeCreateSession($row, $price, $sk);
                if ($session === null) {
                    // Could not open a payment: release the held slot at once.
                    $db->setQuery(
                        $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                            ->set($db->quoteName('pending_ctx') . ' = NULL')
                            ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                            ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                            ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                    )->execute();
                    $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_UNAVAILABLE')]);
                }
                // The slot stays held for exactly as long as Stripe accepts the payment (never a window where Stripe takes money for a freed slot).
                $upd = (object) ['id' => $row->id, 'stripe_session_id' => (string) $session['id']];
                if (!empty($session['expires_at']) && (int) $session['expires_at'] > time()) {
                    $upd->hold_until = Factory::getDate('@' . (int) $session['expires_at'])->toSql();
                }
                $db->updateObject('#__webgbooking_bookings', $upd, 'id');
                // What the return page needs after the stored config has been cleared (no personal data).
                $this->kvSet('ret:' . $session['id'], (string) json_encode(['redirect' => (string) ($actions['redirect'] ?? ''), 'analytics' => (string) ($actions['analytics'] ?? '')]));

                $this->respond(['ok' => true, 'redirect' => (string) $session['url'], 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REDIRECTING')]);
            }

            // Free event: Google event (+ Meet link unless phone), emails, newsletter, webhook, Sheet.
            try {
                $this->completeBooking($row, $actions, $newsletter);
            } catch (\Throwable $e) {
                // Stored and flagged: the scheduled task retries the missing steps.
                $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_FX_THREW', (int) $row->id));
                $db->setQuery($db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))->set($db->quoteName('fx_state') . ' = ' . $db->quote('retry'))->where($db->quoteName('id') . ' = ' . (int) $row->id)->where($db->quoteName('fx_state') . ' != ' . $db->quote('done')))->execute();
            }

            $this->respond([
                'ok'      => true,
                'message' => Text::_(
                    !empty($actions['manual'])
                        ? ($loc === 'phone' ? 'PLG_SYSTEM_WEBGBOOKING_BOOK_OK_PHONE' : 'PLG_SYSTEM_WEBGBOOKING_BOOK_OK')
                        : ($loc === 'phone' ? 'PLG_SYSTEM_WEBGBOOKING_BOOK_OK_PHONE_CONFIRMED' : 'PLG_SYSTEM_WEBGBOOKING_BOOK_OK_CONFIRMED')
                ),
            ]);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }
    }

    // ------------------------------------------------------------------------------------------------
    // Event type (duration / buffers / location), per element
    // ------------------------------------------------------------------------------------------------

    /** Sign the (small) event config so GET endpoints (month/slots) can trust it without the full form token. */
    public static function signEv(array $ev, string $secret, int $epoch = 1): string
    {
        $ev['v']   = $epoch;
        $ev['iat'] = time();
        $payload   = self::b64e((string) json_encode($ev));

        return $payload . '.' . hash_hmac('sha256', 'ev.' . $payload, $secret);
    }

    /** Verify a token made by signEv(). Returns the resolved event config (plugin defaults when invalid/missing). */
    private function evFromToken(string $token): array
    {
        try {
            if ($token !== '' && preg_match('/^[A-Za-z0-9+\/=]+\.[a-f0-9]{64}$/', $token)) {
                [$payload, $sig] = explode('.', $token, 2);
                $calc = hash_hmac('sha256', 'ev.' . $payload, (string) $this->getApplication()->get('secret'));
                if (hash_equals($calc, $sig)) {
                    $cfg = json_decode((string) self::b64d($payload), true);
                    if (\is_array($cfg) && $this->tokenFresh($cfg)) {
                        return $this->resolveEvent($cfg);
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return $this->resolveEvent([]);
    }

    /**
     * Normalise an event config: element values win, empty/missing values fall back to the plugin options.
     * Returns ['dur' => minutes, 'bb' => buffer before, 'ba' => buffer after, 'loc' => meet|phone|choice, 'name' => string].
     */
    private function resolveEvent(array $e): array
    {
        $has = static fn($k) => isset($e[$k]) && $e[$k] !== '' && $e[$k] !== null && is_numeric($e[$k]);

        $dur = $has('dur') && (int) $e['dur'] > 0 ? (int) $e['dur'] : (int) $this->params->get('slot_duration', 30);
        $bb  = $has('bb') ? (int) $e['bb'] : (int) $this->params->get('buffer_before', 0);
        $ba  = $has('ba') ? (int) $e['ba'] : (int) $this->params->get('buffer_after', 0);
        $loc = (string) ($e['loc'] ?? 'meet');

        // Availability overrides for this element (empty = the plugin-wide values)
        $ws = isset($e['ws']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $e['ws']) ? (string) $e['ws'] : null;
        $we = isset($e['we']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $e['we']) ? (string) $e['we'] : null;
        if ($ws !== null && $we !== null) {
            $m1 = array_map('intval', explode(':', $ws));
            $m2 = array_map('intval', explode(':', $we));
            if ($m1[0] * 60 + $m1[1] >= $m2[0] * 60 + $m2[1] || $m1[0] > 23 || $m2[0] > 24) {
                $ws = $we = null;
            }
        } else {
            $ws = $we = null;
        }
        $wd = null;
        if (isset($e['wd']) && \is_array($e['wd'])) {
            $wd = array_values(array_unique(array_filter(array_map('intval', $e['wd']), static fn($d) => $d >= 0 && $d <= 6)));
            $wd = $wd ?: null;
        }
        $nt = isset($e['nt']) && is_numeric($e['nt']) ? max(0, min(720, (int) $e['nt'])) : null;
        $wn = isset($e['wn']) && is_numeric($e['wn']) && (int) $e['wn'] > 0 ? max(1, min(365, (int) $e['wn'])) : null;

        return [
            'dur'  => max(5, min(480, $dur)),
            'bb'   => max(0, min(240, $bb)),
            'ba'   => max(0, min(240, $ba)),
            'loc'  => \in_array($loc, ['meet', 'phone', 'choice'], true) ? $loc : 'meet',
            'name' => mb_substr(trim(strip_tags((string) ($e['name'] ?? ''))), 0, 120),
            'ws'   => $ws,
            'we'   => $we,
            'wd'   => $wd,
            'nt'   => $nt,
            'wn'   => $wn,
        ];
    }

    /** The concrete location of a booking: 'meet' | 'phone' ('' when the customer choice is invalid). */
    private function resolveLocation(string $cfgLoc, string $posted): string
    {
        if ($cfgLoc === 'phone') {
            return 'phone';
        }
        if ($cfgLoc === 'choice') {
            $posted = strtolower($posted);

            return \in_array($posted, ['meet', 'phone'], true) ? $posted : 'meet';
        }

        return 'meet';
    }

    /** A usable phone number to be called: only phone characters, at least 6 digits. */
    public static function phoneOk(string $phone): bool
    {
        return (bool) preg_match('/^[+()\d\s.\-\/]{6,60}$/', $phone) && preg_match_all('/\d/', $phone) >= 6;
    }

    /**
     * Parse "24h, 1h, 30m, 2d" (bare number = hours) into a sorted list of minutes, largest first.
     * At most 6 entries, each between 5 minutes and 14 days.
     */
    public static function parseTimes(string $text): array
    {
        $out = [];
        foreach (preg_split('/[,;\s]+/', strtolower(trim($text))) ?: [] as $tok) {
            if (!preg_match('/^(\d{1,4})\s*(d|h|m|min|g|o)?$/', $tok, $m)) {
                continue;
            }
            $n    = (int) $m[1];
            $unit = $m[2] ?? 'h';
            $min  = $n * ($unit === 'd' || $unit === 'g' ? 1440 : ($unit === 'm' || $unit === 'min' ? 1 : 60));
            if ($min >= 5 && $min <= 20160) {
                $out[$min] = $min;
            }
        }
        rsort($out);

        return \array_slice(array_values($out), 0, 6);
    }

    /**
     * Reminder plan stored with the booking (element override > plugin options > defaults).
     * ['on' => bool, 'times' => [minutes...], 'subject' => string, 'body' => string]
     */
    private function reminderPlan(array $r): array
    {
        // The plugin master switch is checked when the reminders are SENT, so re-enabling it later also covers
        // bookings made while it was off; here only the element's own on/off is stored.
        $on     = !isset($r['on']) || (bool) $r['on'];
        $times  = [];
        foreach ((array) ($r['times'] ?? []) as $x) {
            if (is_numeric($x) && (int) $x >= 5 && (int) $x <= 20160) {
                $times[] = (int) $x;
            }
        }
        if (!$times) {
            $times = self::parseTimes((string) $this->params->get('reminder_times', '24h, 1h')) ?: [1440, 60];
        }
        $times = array_values(array_unique($times));
        rsort($times);

        return [
            'on'      => $on,
            'times'   => \array_slice(array_values($times), 0, 6),
            'subject' => (string) ($r['subject'] ?? ''),
            'body'    => (string) ($r['body'] ?? ''),
        ];
    }

    /** Reminder offsets (minutes) whose send time is already behind us when the booking is made or moved. */
    private function pastOffsets(array $plan, int $startTs, int $now): array
    {
        $out = [];
        foreach ((array) ($plan['times'] ?? []) as $m) {
            if ($startTs - ((int) $m) * 60 <= $now) {
                $out[] = (int) $m;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------
    // Per-day booking lock (closes the check-then-insert race) + existing-booking availability
    // ------------------------------------------------------------------------------------------------

    private function lockName($db, string $date): string
    {
        return 'wgb:' . substr(md5((string) $this->getApplication()->get('db') . '|' . $db->getPrefix() . '|' . $date), 0, 24);
    }

    /** Named lock (MySQL/MariaDB GET_LOCK). On other drivers there is no lock and we proceed (best effort). */
    private function acquireLock($db, string $name): bool
    {
        try {
            if (stripos((string) $db->getServerType(), 'mysql') === false) {
                return true;
            }

            return (int) $db->setQuery('SELECT GET_LOCK(' . $db->quote($name) . ', 8)')->loadResult() === 1;
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function releaseLock($db, string $name): void
    {
        try {
            if (stripos((string) $db->getServerType(), 'mysql') !== false) {
                $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($name) . ')')->loadResult();
            }
        } catch (\Throwable $e) {
        }
    }

    /** Cloudflare proxy ranges (https://www.cloudflare.com/ips/). The option `cf_ranges_extra` adds to them without a new release. */
    private const CF_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * How the visitor address of a request is detected, for the admin diagnostics line: which rule applied, the format of
     * REMOTE_ADDR and only the LAST THREE characters of the detected address (never the address, never its hash).
     * mode: cloudflare (header accepted: the connection comes from a Cloudflare range) | restored (REMOTE_ADDR already equals the
     *       header: the server restored the real IP) | direct (no Cloudflare header, real IP) | unrecognized (header present, REMOTE_ADDR
     *       is another address outside the ranges: looks like a proxy not in the list) | noheader (REMOTE_ADDR in a Cloudflare
     *       range but the header is missing: every visitor shows the edge address).
     */
    public function ipDiagnosis(string $remote, string $cfHeader): array
    {
        $remote = trim($remote);
        $cf     = trim($cfHeader);
        if (stripos($remote, '::ffff:') === 0 && filter_var(substr($remote, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $fmt = 'v4mapped';
        } elseif (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $fmt = 'v4';
        } elseif (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $fmt = 'v6';
        } else {
            $fmt = 'unknown';
        }
        $norm     = self::normalizeIp($remote);
        $edge     = $fmt !== 'unknown' && $this->fromCloudflare($norm);
        $cfValid  = $cf !== '' && filter_var($cf, FILTER_VALIDATE_IP);
        $detected = $fmt === 'unknown' ? '' : $norm;
        if ($cfValid && $edge) {
            $mode     = 'cloudflare';
            $detected = $cf;
        } elseif ($cfValid && self::normalizeIp($cf) === $norm) {
            $mode = 'restored';          // the server already put the visitor address in REMOTE_ADDR (e.g. mod_remoteip): the real IP
        } elseif ($cfValid) {
            $mode = 'unrecognized';      // header present, REMOTE_ADDR is some other address that is not a listed Cloudflare range: looks like a proxy not in the list
        } elseif ($edge) {
            $mode = 'noheader';
        } else {
            $mode = 'direct';
        }

        return ['mode' => $mode, 'fmt' => $fmt, 'tail' => $detected === '' ? '' : substr($detected, -3)];
    }

    /** An IPv4 address written inside IPv6 (::ffff:a.b.c.d) is the same address as a.b.c.d: compare and hash it as IPv4. */
    public static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($ip, 7);
        }

        return $ip;
    }

    private function fromCloudflare(string $ip): bool
    {
        $ip    = self::normalizeIp($ip);
        $extra = preg_split('/[\s,;]+/', (string) $this->params->get('cf_ranges_extra', '')) ?: [];
        foreach (array_merge(self::CF_RANGES, array_filter($extra)) as $cidr) {
            if (self::ipInCidr($ip, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Is $ip inside $cidr (IPv4 or IPv6)? A malformed range never matches. */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || \strlen($a) !== \strlen($b) || !ctype_digit($bits) || (int) $bits > \strlen($a) * 8) {
            return false;
        }
        $bits  = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($a[$bytes]) & $mask) === (\ord($b[$bytes]) & $mask);
    }

    /** Hash of the visitor's address (site-secret HMAC): enough to rate-limit, nothing personal stored. */
    private function clientIpHash($input): string
    {
        // CF-Connecting-IP is a plain request header anyone can send: it counts only when the connection itself
        // comes from a Cloudflare address (built-in list + the "extra ranges" option for a changed list).
        $ip     = self::normalizeIp((string) $input->server->getString('REMOTE_ADDR', ''));
        $cfHead = trim((string) $input->server->getString('HTTP_CF_CONNECTING_IP', ''));
        if ($cfHead !== '' && filter_var($cfHead, FILTER_VALIDATE_IP) && filter_var($ip, FILTER_VALIDATE_IP) && $this->fromCloudflare($ip)) {
            $ip = $cfHead;
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? substr(hash_hmac('sha256', 'ip|' . $ip, (string) $this->getApplication()->get('secret')), 0, 24) : '';
    }

    /** Duration (minutes) of a stored booking; older rows without the column use the plugin default. */
    private function rowDuration(object $row): int
    {
        $d = (int) ($row->duration_min ?? 0);

        return $d > 0 ? $d : max(5, (int) $this->params->get('slot_duration', 30));
    }

    /** 'meet' | 'phone' for a stored booking (older rows are Meet). */
    private function rowLocation(object $row): string
    {
        return (($row->location ?? '') === 'phone') ? 'phone' : 'meet';
    }

    /** Event config (resolved) rebuilt from a stored booking, used for the reschedule widget. */
    private function eventFromRow(object $row): array
    {
        return $this->resolveEvent([
            'dur'  => $this->rowDuration($row),
            'bb'   => (int) ($row->buffer_before ?? 0),
            'ba'   => (int) ($row->buffer_after ?? 0),
            'loc'  => $this->rowLocation($row),
            'name' => (string) ($row->event_name ?? ''),
        ] + (array) json_decode((string) ($row->avail_cfg ?? ''), true));
    }

    /**
     * Existing, non-cancelled bookings around [from, toExclusive) as [startTs, endTs, bufferBeforeSec, bufferAfterSec].
     * Each one blocks overlapping slots using ITS OWN duration and buffers, so events of different length coexist.
     */
    private function localBookings(string $from, string $toExclusive, \DateTimeZone $tz, int $excludeId = 0): array
    {
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $lo   = (new \DateTime($from . ' 00:00:00', $tz))->modify('-1 day')->format('Y-m-d');
            $hi   = (new \DateTime($toExclusive . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('status') . ' NOT IN (' . $db->quote('cancelled') . ',' . $db->quote('expired') . ',' . $db->quote('needs_review') . ')')
                    // an unpaid hold blocks the slot only while it has not lapsed
                    ->where('(' . $db->quoteName('status') . ' != ' . $db->quote('awaiting_payment') . ' OR ' . $db->quoteName('hold_until') . ' >= ' . $db->quote(Factory::getDate()->toSql()) . ')')
                    ->where($db->quoteName('booking_date') . ' >= ' . $db->quote($lo))
                    ->where($db->quoteName('booking_date') . ' <= ' . $db->quote($hi))
            )->loadObjectList();

            $out = [];
            foreach ($rows ?: [] as $r) {
                if ($excludeId > 0 && (int) $r->id === $excludeId) {
                    continue;
                }
                $s = (new \DateTime($r->booking_date . ' ' . $r->booking_time . ':00', $tz))->getTimestamp();
                $out[] = [$s, $s + $this->rowDuration($r) * 60, max(0, (int) ($r->buffer_before ?? 0)) * 60, max(0, (int) ($r->buffer_after ?? 0)) * 60];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------------------------------------
    // Paid events (Stripe Checkout, REST over Joomla HTTP, no SDK)
    // ------------------------------------------------------------------------------------------------

    // ------------------------------------------------------------------------------------------------
    // Amounts and currencies
    // ------------------------------------------------------------------------------------------------

    /** Currencies the plugin charges in. All have 2 decimals (zero/three-decimal currencies are NOT supported on purpose). */
    public const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF'];

    /**
     * Strict amount parser: "49", "49,00", "49.5", "1.234,50", "1,234.50" -> cents.
     * Returns 0 for empty/zero (free event) and NULL for anything ambiguous or malformed ("49.999", "1,234", "4,9,9",
     * "49abc", above 999999.99): an unreadable price must never silently turn a paid event into a free one.
     */
    public static function parseAmountStrict(string $text): ?int
    {
        $t = trim($text);
        if ($t === '') {
            return 0;
        }
        if (!preg_match('/^[\s€$£]*([\d.,\s]+?)[\s€$£]*$/u', $t, $m) && !preg_match('/^(?:EUR|USD|GBP|CHF)?\s*([\d.,]+)\s*(?:EUR|USD|GBP|CHF)?$/i', $t, $m)) {
            return null;
        }
        $n = preg_replace('/\s+/', '', $m[1]);
        if ($n === '' || !preg_match('/\d/', $n)) {
            return null;
        }
        $hasC = strpos($n, ',') !== false;
        $hasD = strpos($n, '.') !== false;

        if (!$hasC && !$hasD) {
            $int = $n;
            $dec = '';
        } elseif ($hasC && $hasD) {
            $dpos = max(strrpos($n, ','), strrpos($n, '.'));
            $dsep = $n[$dpos];
            $tsep = $dsep === ',' ? '.' : ',';
            $dec  = substr($n, $dpos + 1);
            $ip   = substr($n, 0, $dpos);
            if (!preg_match('/^\d{1,2}$/', $dec) || !preg_match('/^\d{1,3}(?:' . preg_quote($tsep, '/') . '\d{3})+$/', $ip)) {
                return null;
            }
            $int = str_replace($tsep, '', $ip);
        } else {
            $sep = $hasC ? ',' : '.';
            $parts = explode($sep, $n);
            if (\count($parts) === 2) {
                if (!preg_match('/^\d+$/', $parts[0]) || !preg_match('/^\d{1,2}$/', $parts[1])) {
                    return null;     // 3 digits after a single separator ("49.999", "1,234") is ambiguous: refuse
                }
                $int = $parts[0];
                $dec = $parts[1];
            } else {
                // repeated separator: only valid as thousands grouping "1.234.567"
                if (!preg_match('/^\d{1,3}(?:' . preg_quote($sep, '/') . '\d{3})+$/', $n)) {
                    return null;
                }
                $int = str_replace($sep, '', $n);
                $dec = '';
            }
        }
        if (\strlen(ltrim($int, '0')) > 6) {
            return null;
        }
        $cents = ((int) $int) * 100 + (int) str_pad($dec, 2, '0');

        return $cents <= 99999999 ? $cents : null;
    }

    /** Lenient wrapper kept for callers that only need "cents or 0". */
    public static function parseAmount(string $text): int
    {
        return (int) self::parseAmountStrict($text);
    }

    /** Human amount, e.g. "49,00 €" (it/de/fr/es style) or "€49.00". */
    public static function formatAmount(int $cents, string $cur, string $langTag = 'en-GB'): string
    {
        $sym   = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF'][strtoupper($cur)] ?? strtoupper($cur);
        $comma = \in_array(strtolower(substr($langTag, 0, 2)), ['it', 'de', 'fr', 'es', 'pt', 'nl'], true);
        $num   = number_format($cents / 100, 2, $comma ? ',' : '.', $comma ? '.' : ',');

        return $comma ? $num . ' ' . $sym : (mb_strlen($sym) > 1 ? $sym . ' ' : $sym) . $num;
    }

    private function langTag(): string
    {
        try {
            return (string) $this->getApplication()->getLanguage()->getTag();
        } catch (\Throwable $e) {
            return 'en-GB';
        }
    }

    /** Names of Stripe secrets stored encrypted that could NOT be decrypted (Joomla secret rotated?). */
    private array $secretFail = [];

    /**
     * Stripe secret of the plugin options, decrypted, or ''.
     * - Found in clear (set by hand in the database / imported options): it is encrypted and written back on this
     *   first read, and keeps working.
     * - Stored encrypted but undecryptable (the Joomla "secret" changed): '' + a log line WITHOUT values + the admin
     *   screens show a warning ("re-enter the key"); this is not the same as "not configured".
     */
    private function stripeSecretOpt(string $name): string
    {
        $v = trim((string) $this->params->get($name, ''));
        if ($v === '') {
            return '';
        }
        if (strncmp($v, 'g:', 2) === 0) {
            $plain = $this->dec($v);
            if ($plain === '') {
                $this->secretFail[$name] = true;
                $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_SECRET_UNREADABLE', $name));
            }

            return $plain;
        }

        // in clear: encrypt now
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $raw = (string) $db->setQuery(
                $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('webgbooking'))->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            )->loadResult();
            $p = json_decode($raw, true);
            if (\is_array($p) && isset($p[$name]) && strncmp((string) $p[$name], 'g:', 2) !== 0) {
                $p[$name] = $this->enc($v);
                $db->setQuery(
                    $db->getQuery(true)->update($db->quoteName('#__extensions'))->set($db->quoteName('params') . ' = ' . $db->quote((string) json_encode($p)))
                        ->where($db->quoteName('element') . ' = ' . $db->quote('webgbooking'))->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                )->execute();
                $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_SECRET_ENCRYPTED', $name));
            }
        } catch (\Throwable $e) {
        }

        return $v;
    }

    private function stripeLive(): bool
    {
        return (string) $this->params->get('stripe_mode', 'test') === 'live';
    }

    private function holdMinutes(): int
    {
        return max(30, min(1440, (int) $this->params->get('stripe_hold_minutes', 30)));
    }

    /** Flatten nested arrays to Stripe's form encoding keys (a[b][0][c]). */
    private static function stripeFlatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
            if (\is_array($v)) {
                $out += self::stripeFlatten($v, $key);
            } elseif ($v !== null && $v !== '') {
                $out[$key] = \is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
        }

        return $out;
    }

    /** One Stripe REST call. Returns [httpCode, decodedBody]. The secret key only travels to api.stripe.com. */
    private function stripeCall(string $method, string $path, array $form, string $sk): array
    {
        try {
            $http    = (new HttpFactory())->getHttp();
            $url     = 'https://api.stripe.com' . $path;
            $headers = ['Authorization' => 'Bearer ' . $sk];

            if ($method === 'GET') {
                $resp = $http->get($url, $headers, 20);
            } else {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                $resp = $http->post($url, http_build_query(self::stripeFlatten($form)), $headers, 20);
            }

            return [self::httpCode($resp), (array) json_decode(self::httpBody($resp), true)];
        } catch (\Throwable $e) {
            return [0, []];
        }
    }

    /** Create the Checkout Session for an awaiting_payment booking. Returns the session array or null. */
    private function stripeCreateSession(object $row, array $price, string $sk): ?array
    {
        $root    = Uri::root();
        $base    = $root . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&token=' . rawurlencode((string) $row->manage_token);
        $label   = trim((string) ($price['label'] ?? ''));
        $name    = $label !== '' ? $label : (trim((string) ($row->event_name ?? '')) ?: 'WebG Booking');
        $when    = (new \DateTime($row->booking_date))->format('d/m/Y') . ' ' . $row->booking_time;
        $holdEnd = (new \DateTime((string) $row->hold_until, new \DateTimeZone('UTC')))->getTimestamp();

        // The session ends exactly when the hold ends. Stripe only accepts 30 minutes to 24 hours from now, so a 30 minute
        // hold gets a few seconds more: the hold is then moved to the session's real end (see handleBook), never the other way.
        $expires = max($holdEnd, time() + 30 * 60 + 30);
        $deadline = (new \DateTime('@' . $expires))->setTimezone(new \DateTimeZone($this->siteTz()))->format('d/m/Y H:i');

        [$code, $s] = $this->stripeCall('POST', '/v1/checkout/sessions', [
            'mode'                => 'payment',
            'client_reference_id' => (string) $row->id,
            'customer_email'      => (string) $row->customer_email,
            'success_url'         => $base . '&action=payreturn&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'          => $base . '&action=paycancel',
            'expires_at'          => $expires,
            // Said on the payment page itself, so a customer who leaves the tab open knows what happens after the deadline.
            'custom_text'         => ['submit' => ['message' => Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_STRIPE_DEADLINE', $when, $deadline)]],
            'line_items'          => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower((string) $row->currency),
                    'unit_amount'  => (int) $row->amount_cents,
                    'product_data' => ['name' => $name, 'description' => $when . ' - ' . (int) $this->rowDuration($row) . ' min'],
                ],
            ]],
            'payment_intent_data' => ['receipt_email' => (string) $row->customer_email, 'description' => $name . ' ' . $when],
            'metadata'            => ['booking_id' => (string) $row->id, 'site' => $this->siteKey()],
        ], $sk);

        return ($code >= 200 && $code < 300 && !empty($s['id']) && !empty($s['url'])) ? $s : null;
    }

    private function stripeRetrieveSession(string $id, string $sk): ?array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $id)) {
            return null;
        }
        [$code, $s] = $this->stripeCall('GET', '/v1/checkout/sessions/' . $id, [], $sk);

        return ($code === 200 && ($s['id'] ?? '') === $id) ? $s : null;
    }

    /** Ask Stripe to close an open session (best effort). True when it is closed/gone. */
    private function stripeExpireSession(string $id, string $sk): bool
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $id) || $sk === '') {
            return false;
        }
        [$code] = $this->stripeCall('POST', '/v1/checkout/sessions/' . $id . '/expire', [], $sk);

        return $code >= 200 && $code < 300;
    }

    /**
     * Check a key against Stripe. A normal secret key can read the account. A restricted key (rk_) often cannot
     * (403): then a Checkout Sessions list is tried, which is exactly the permission the plugin needs. If that is
     * refused too the key is "unverifiable" (not rejected). 401 = Stripe does not know the key.
     *
     * @return array{ok:bool, account:string, code:int, restricted?:bool, unverifiable?:bool}
     */
    public function checkStripeKey(string $sk): array
    {
        [$code, $s] = $this->stripeCall('GET', '/v1/account', [], $sk);
        if ($code === 200) {
            return ['ok' => true, 'account' => (string) ($s['id'] ?? ''), 'code' => 200];
        }
        if ($code === 403) {
            [$c2] = $this->stripeCall('GET', '/v1/checkout/sessions?limit=1', [], $sk);
            if ($c2 === 200) {
                return ['ok' => true, 'account' => '', 'code' => 200, 'restricted' => true];
            }
            if ($c2 === 403) {
                return ['ok' => false, 'account' => '', 'code' => 403, 'unverifiable' => true];
            }

            return ['ok' => false, 'account' => '', 'code' => $c2];
        }

        return ['ok' => false, 'account' => '', 'code' => $code];
    }

    /**
     * Verify a Stripe-Signature header: "t=timestamp,v1=hex[,v1=hex...]", HMAC-SHA256 of "t.payload" with the
     * endpoint signing secret, constant-time comparison, timestamp within the tolerance (replay protection).
     */
    public static function verifyStripeSignature(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $t    = 0;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (\count($kv) === 2) {
                if ($kv[0] === 't') {
                    $t = (int) $kv[1];
                } elseif ($kv[0] === 'v1') {
                    $sigs[] = $kv[1];
                }
            }
        }
        if ($t <= 0 || !$sigs || abs(($now ?? time()) - $t) > $tolerance) {
            return false;
        }
        $calc = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($calc, $s)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every side effect of a confirmed booking, STEP BY STEP and resumable: Google event/Meet, customer email, staff
     * email, newsletter, webhook, Sheet. What is done is recorded in `fx_steps`, so a failure in one step never blocks
     * the others and a retry (task scheduler) redoes only what is missing, never an email that already went out.
     * "Paid" and "effects done" are separate facts: a paid booking whose effects failed stays flagged (`fx_state`)
     * and the staff is told.
     *
     * Meet bookings with Google connected but the event not created yet: the customer gets the confirmation at once
     * with "I will send you the video link shortly" (no empty link), and a "here is your link" email as soon as a
     * retry creates the event.
     */
    private function completeBooking(object $row, array $actions, bool $newsletter): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $steps = json_decode((string) ($row->fx_steps ?? ''), true);
        $steps = \is_array($steps) ? $steps : [];
        $tries = (int) ($row->fx_tries ?? 0) + 1;
        $failed = [];

        $cust  = $actions['customer'] ?? [];
        $staff = $actions['staff'] ?? [];
        $row->_custOn         = !isset($cust['on']) || (bool) $cust['on'];
        $row->emailSubjectTpl = (string) ($cust['subject'] ?? '');
        $row->emailBodyTpl    = (string) ($cust['body'] ?? '');
        $row->_staffOn        = !isset($staff['on']) || (bool) $staff['on'];
        $row->_staffTo        = (string) ($staff['to'] ?? '');
        $row->_staffSubject   = (string) ($staff['subject'] ?? '');
        $row->_staffBody      = (string) ($staff['body'] ?? '');

        $connected = $this->googleConnected();

        // 1. Google event (+ Meet link unless it is a phone call)
        if (empty($steps['google'])) {
            try {
                if (!$connected) {
                    $steps['google'] = true;
                } else {
                    $meet = $this->createGoogleEvent($row);
                    if (!empty($row->google_event_id)) {
                        $steps['google'] = true;
                        if ($meet !== '') {
                            $row->meeting_url = $meet;
                        }
                        $upd = (object) ['id' => $row->id, 'meeting_url' => (string) $row->meeting_url, 'google_event_id' => (string) $row->google_event_id];
                        $db->updateObject('#__webgbooking_bookings', $upd, 'id');
                        $this->purgeBusyCache();
                    } else {
                        $failed[] = 'google';
                    }
                }
            } catch (\Throwable $e) {
                $failed[] = 'google';
            }
        }

        $row->_meetPending = $connected && $this->rowLocation($row) === 'meet' && empty($steps['google']);

        // 2. customer (+ guest) email
        if (empty($steps['mail_customer'])) {
            if ($this->notify($row, false, 'customer')) {
                $steps['mail_customer'] = true;
                if ($row->_meetPending) {
                    $steps['linkmail'] = 'needed';
                }
            } else {
                $failed[] = 'mail_customer';
            }
        }

        // 2b. invited guest (its own step: a refused guest address never re-sends the customer's mail)
        if (!empty($row->guest_email) && empty($steps['mail_guest'])) {
            if ($this->notify($row, false, 'guest')) {
                $steps['mail_guest'] = true;
            } else {
                $failed[] = 'mail_guest';
            }
        }

        // 3. staff email
        if (empty($steps['mail_staff'])) {
            if ($this->notify($row, false, 'staff')) {
                $steps['mail_staff'] = true;
            } else {
                $failed[] = 'mail_staff';
            }
        }

        // 4. newsletter opt-in
        if ($newsletter && empty($steps['newsletter'])) {
            if ($this->newsletterSignup($row)) {
                $steps['newsletter'] = true;
            } else {
                $failed[] = 'newsletter';
            }
        }

        // 5. webhook
        if (empty($steps['webhook'])) {
            // The same rule as for the later events (see hookCfgJson): a paid booking is announced as booking.paid.
            $cfg   = json_decode($this->hookCfgJson($actions), true);
            $event = ($row->payment_status ?? '') === 'paid' ? 'booking.paid' : 'booking.created';
            $res = $this->hookSend($row, $event, (array) $cfg);
            if ($res === true) {
                $steps['webhook'] = true;
            } elseif ($res === 'refused') {
                $steps['webhook'] = 'error';      // a configuration problem: logged, staff told, no retry loop
            } else {
                $failed[] = 'webhook';
            }
        }

        // 5b. Brevo contact (own step: a Brevo outage never re-sends emails or re-creates events)
        if (empty($steps['brevo']) && !empty(($actions['brevo'] ?? [])['on'])) {
            $r = $this->brevoSync($row, (array) $actions['brevo'], $newsletter);
            if ($r === 'retry') {
                $failed[] = 'brevo';
            } else {
                $steps['brevo'] = $r;   // ok | skip | error (an error that a retry cannot fix: logged and mailed to the staff once)
            }
        }

        // 6. Google Sheet row
        if (empty($steps['sheet'])) {
            if ($this->appendToSheet($row)) {
                $steps['sheet'] = true;
            } else {
                $failed[] = 'sheet';
            }
        }

        // 7. the promised video link, once the event exists
        if (($steps['linkmail'] ?? '') === 'needed' && !empty($steps['google'])) {
            if ($this->sendLinkMail($row)) {
                $steps['linkmail'] = 'sent';
            } else {
                $failed[] = 'linkmail';
            }
        }

        $done  = !$failed && ($steps['linkmail'] ?? '') !== 'needed';
        $state = $done ? 'done' : ($tries >= 10 ? 'failed' : 'retry');
        $set   = [
            $db->quoteName('fx_state') . ' = ' . $db->quote($state),
            $db->quoteName('fx_steps') . ' = ' . $db->quote((string) json_encode($steps)),
            $db->quoteName('fx_tries') . ' = ' . (int) $tries,
            $db->quoteName('fx_last') . ' = ' . $db->quote(Factory::getDate()->toSql()),
        ];
        if ($done) {
            $set[] = $db->quoteName('pending_ctx') . ' = NULL';   // nothing left to resume: drop the stored config
        }
        try {
            $db->setQuery($db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))->set($set)->where($db->quoteName('id') . ' = ' . (int) $row->id))->execute();
        } catch (\Throwable $e) {
        }
        $row->fx_state = $state;

        if ($failed) {
            $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_FX_INCOMPLETE', (int) $row->id, implode(',', $failed), $tries));
            $this->alertStaff(
                'fx' . (int) $row->id,
                Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_FX_SUBJECT', $this->shortDate((string) $row->booking_date), $row->booking_time),
                Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_FX_BODY', (int) $row->id, $row->customer_name, $row->customer_email, implode(', ', $failed), $state === 'failed' ? Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_FX_GAVEUP') : Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_FX_WILLRETRY')),
                12,
                $row
            );
        }
    }

    /** "Here is your video link" email, sent once a delayed Google event finally exists. */
    private function sendLinkMail(object $row): bool
    {
        try {
            if (empty($row->meeting_url)) {
                return true; // nothing to send (Google gave no Meet link)
            }
            $this->useSiteLanguage();
            $mf      = Factory::getContainer()->get(MailerFactoryInterface::class);
            $dateFmt = (new \DateTime($row->booking_date))->format('d/m/Y');
            $this->sendLayout($mf, $row->customer_email, Text::_('PLG_SYSTEM_WEBGBOOKING_LINKMAIL_SUBJECT'), $this->customerMail($row, 'link', $this->manageUrl($row), $dateFmt));

            return true;
        } catch (\Throwable $ex) {
            return false;
        }
    }

    /**
     * Retry the effects of confirmed bookings that did not complete (Google down, SMTP hiccup, an exception).
     * Resumes from `fx_steps`; claims the row with a compare-and-set on `fx_last` so two runs never overlap.
     * Returns how many bookings were retried.
     */
    public function retryEffects(int $limit = 30): int
    {
        $n = 0;
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $cut  = Factory::getDate('-2 minutes')->toSql();
            $cut5 = Factory::getDate('-5 minutes')->toSql();
            // 'retry' = a step failed and was recorded. 'pending' older than a few minutes = the PHP process died (or
            // was killed) between the confirmation and the final UPDATE: nobody will ever finish it unless we do.
            // Young 'pending' rows are left alone: their own request may still be running.
            $rows = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_bookings'))
                    ->where('(('
                        . $db->quoteName('fx_state') . ' = ' . $db->quote('retry') . ' AND (' . $db->quoteName('fx_last') . ' IS NULL OR ' . $db->quoteName('fx_last') . ' < ' . $db->quote($cut) . '))'
                        . ' OR ('
                        . $db->quoteName('fx_state') . ' = ' . $db->quote('pending') . ' AND COALESCE(' . $db->quoteName('fx_last') . ', ' . $db->quoteName('paid_at') . ', ' . $db->quoteName('created') . ') < ' . $db->quote($cut5) . '))')
                    ->where($db->quoteName('status') . ' IN (' . $db->quote('pending') . ',' . $db->quote('confirmed') . ')')
                    ->order($db->quoteName('id') . ' ASC'),
                0,
                $limit
            )->loadObjectList();

            $n += $this->retryHooks();

            foreach ($rows ?: [] as $row) {
                $old = (string) ($row->fx_last ?? '');
                $now = Factory::getDate()->toSql();
                $db->setQuery(
                    $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                        ->set($db->quoteName('fx_last') . ' = ' . $db->quote($now))
                        ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                        ->where($db->quoteName('fx_state') . ' IN (' . $db->quote('retry') . ',' . $db->quote('pending') . ')')
                        ->where('COALESCE(' . $db->quoteName('fx_last') . ", '') = " . $db->quote($old))
                )->execute();
                if ((int) $db->getAffectedRows() !== 1) {
                    continue;
                }
                $ctx = json_decode((string) ($row->pending_ctx ?? ''), true);
                $ctx = \is_array($ctx) ? $ctx : [];
                $this->useSiteLanguage();
                try {
                    $this->completeBooking($row, (array) ($ctx['actions'] ?? []), !empty($ctx['newsletter']));
                } catch (\Throwable $e) {
                    $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_FX_RETRY_THREW', (int) $row->id));
                }
                $n++;
            }
        } catch (\Throwable $e) {
        }

        return $n;
    }

    private function bookingBySession(string $sessionId): ?object
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            return $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('stripe_session_id') . ' = ' . $db->quote($sessionId))
            )->loadObject() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function bookingByTokenAny(string $token): ?object
    {
        if (strlen($token) < 16) {
            return null;
        }
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            return $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('manage_token') . ' = ' . $db->quote($token))
            )->loadObject() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Short stable id of THIS site, put in Stripe metadata so a Stripe account shared by several sites is not mixed up. */
    private function siteKey(): string
    {
        $host = (string) (parse_url(Uri::root(), PHP_URL_HOST) ?: 'site');

        return substr(md5(strtolower($host)), 0, 10);
    }

    /**
     * A paid Checkout Session reached us (webhook or return page). Idempotent: only the transition from
     * awaiting_payment/expired to paid has effects, a second call returns 'already'.
     * - session.livemode must match the plugin mode, else nothing is confirmed ('mismatch' + staff alert).
     * - If the hold had lapsed the slot is re-checked: still free -> confirmed anyway, taken -> needs_review + alert.
     * - A session paid for a booking that is no longer waiting (admin cancelled the hold meanwhile) is recorded as
     *   an orphan payment and the staff is told to refund ('orphan').
     * - 'busy' = the day lock could not be taken: nothing was changed, the caller must let Stripe retry.
     *
     * @return string confirmed | review | already | mismatch | orphan | busy
     */
    private function finalizePaid(object $row, array $session): string
    {
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $pi   = (string) ($session['payment_intent'] ?? '');
        $now  = Factory::getDate()->toSql();
        $tz   = new \DateTimeZone($this->siteTz());
        $nowT = (new \DateTime('now', $tz))->getTimestamp();

        // Test money must not confirm a live booking (and the opposite).
        // A session that does not even say which mode it belongs to is treated as a mismatch, not skipped.
        if (!isset($session['livemode']) || (bool) $session['livemode'] !== $this->stripeLive()) {
            $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_PAY_LIVEMODE', (int) $row->id));
            $this->notifyPaymentReview($row, 'mode');

            return 'mismatch';
        }

        if (($row->payment_status ?? '') === 'paid') {
            return 'already';
        }

        if (!\in_array($row->status, ['awaiting_payment', 'expired'], true)) {
            return $this->recordOrphanPayment($row, $session);
        }

        $amountOk = (int) ($session['amount_total'] ?? -1) === (int) $row->amount_cents
            && strtolower((string) ($session['currency'] ?? '')) === strtolower((string) $row->currency);

        $startTs    = (new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz))->getTimestamp();
        $holdLapsed = $row->status === 'expired' || (!empty($row->hold_until) && Factory::getDate($row->hold_until)->toUnix() < time());
        $outcome    = 'confirmed';
        $why        = '';

        $lockName = $this->lockName($db, $row->booking_date);
        if (!$this->acquireLock($db, $lockName)) {
            return 'busy';
        }
        try {
            if (!$amountOk || $startTs <= $nowT) {
                $outcome = 'review';
                $why     = !$amountOk ? 'amount' : 'past';
            } elseif ($holdLapsed) {
                $next = (new \DateTime($row->booking_date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
                $cfg  = $this->slotConfig($this->eventFromRow($row));
                $cfg['notice'] = 0; // only conflicts matter here, the customer already paid for this slot
                $cfg['window'] = 3650;
                $cfg['days']   = [0, 1, 2, 3, 4, 5, 6];
                $busy = $this->googleBusy($row->booking_date, $next, $tz, false);
                if ($busy === null) {
                    $outcome = 'review'; // cannot prove the slot is free: a person decides
                    $why     = 'unknown';
                } else {
                    $slots = $this->computeDay($row->booking_date, $tz, $busy, 0, $cfg, $this->localBookings($row->booking_date, $next, $tz, (int) $row->id));
                    if (!\in_array($row->booking_time, $slots, true)) {
                        $outcome = 'review';
                        $why     = 'slot';
                    }
                }
            }

            $plan = json_decode((string) ($row->reminder_cfg ?? ''), true);
            $set  = [
                $db->quoteName('status') . ' = ' . $db->quote($outcome === 'confirmed' ? 'confirmed' : 'needs_review'),
                $db->quoteName('payment_status') . ' = ' . $db->quote('paid'),
                $db->quoteName('paid_at') . ' = ' . $db->quote($now),
                $db->quoteName('updated') . ' = ' . $db->quote($now),
                $db->quoteName('stripe_payment_intent') . ' = ' . $db->quote($pi),
            ];
            if ($outcome === 'confirmed') {
                $set[] = $db->quoteName('fx_state') . ' = ' . $db->quote('pending');
                $set[] = $db->quoteName('fx_steps') . ' = ' . $db->quote('{}');
                $set[] = $db->quoteName('fx_tries') . ' = 0';
            } else {
                $set[] = $db->quoteName('pending_ctx') . ' = NULL';
                $set[] = $db->quoteName('review_why') . ' = ' . $db->quote($why);
            }
            if (\is_array($plan)) {
                $set[] = $db->quoteName('reminders_sent') . ' = ' . $db->quote((string) json_encode($this->pastOffsets($plan, $startTs, $nowT)));
            }
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__webgbooking_bookings'))
                    ->set($set)
                    ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                    ->where($db->quoteName('status') . ' IN (' . $db->quote('awaiting_payment') . ',' . $db->quote('expired') . ')')
                    ->where('COALESCE(' . $db->quoteName('payment_status') . ", '') != " . $db->quote('paid'))
            )->execute();
            $claimed = (int) $db->getAffectedRows() === 1;
        } finally {
            $this->releaseLock($db, $lockName);
        }

        if (!$claimed) {
            return 'already';
        }

        $row->status         = $outcome === 'confirmed' ? 'confirmed' : 'needs_review';
        $row->payment_status = 'paid';
        $row->paid_at        = $now;
        $row->stripe_payment_intent = $pi;

        if ($outcome === 'review') {
            $row->review_why = $why;
            $this->notifyPaymentReview($row, $amountOk ? 'slot' : 'amount');
            $this->notifyLatePayment($row, $why);        // the customer hears it at once, not only the staff

            return $amountOk ? 'review' : 'mismatch';
        }

        // The money is in and recorded above. The effects run now; if anything fails they stay flagged for retry.
        $ctx = json_decode((string) ($row->pending_ctx ?? ''), true);
        $ctx = \is_array($ctx) ? $ctx : [];
        $this->useSiteLanguage();
        $row->fx_state = 'pending';
        try {
            $this->completeBooking($row, (array) ($ctx['actions'] ?? []), !empty($ctx['newsletter']));
        } catch (\Throwable $e) {
            $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_FX_PAID_THREW', (int) $row->id));
            try {
                $db->setQuery($db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))->set($db->quoteName('fx_state') . ' = ' . $db->quote('retry'))->where($db->quoteName('id') . ' = ' . (int) $row->id)->where($db->quoteName('fx_state') . ' != ' . $db->quote('done')))->execute();
            } catch (\Throwable $e2) {
            }
        }

        return 'confirmed';
    }

    /** Money arrived for a booking that is no longer waiting (cancelled or otherwise closed): record it, tell the staff. */
    private function recordOrphanPayment(object $row, array $session): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $db->setQuery(
            $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                ->set($db->quoteName('payment_status') . ' = ' . $db->quote('paid'))
                ->set($db->quoteName('paid_at') . ' = ' . $db->quote(Factory::getDate()->toSql()))
                ->set($db->quoteName('stripe_payment_intent') . ' = ' . $db->quote((string) ($session['payment_intent'] ?? '')))
                ->set($db->quoteName('pending_ctx') . ' = NULL')
                ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                ->where('COALESCE(' . $db->quoteName('payment_status') . ", '') != " . $db->quote('paid'))
        )->execute();

        if ((int) $db->getAffectedRows() === 1) {
            $row->payment_status = 'paid';
            $row->stripe_payment_intent = (string) ($session['payment_intent'] ?? '');
            $this->notifyPaymentReview($row, 'orphan');
        }

        return 'orphan';
    }

    /** A button back to the page the customer booked from (or the site home). */
    private function backButton(object $row, string $labelKey): string
    {
        $url = !empty($row->source_url) && preg_match('#^https?://#i', (string) $row->source_url) ? (string) $row->source_url : '';

        return $url !== '' ? '<p><a class="btn btn-ghost" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(Text::_($labelKey), ENT_QUOTES, 'UTF-8') . '</a></p>' : '';
    }

    /**
     * Money arrived for a booking that cannot be confirmed (the slot was taken after the hold ended, or it cannot be
     * checked): the CUSTOMER is told at once what happened and what to do, besides the staff alert.
     * $why: slot | past (the time is no longer available) | unknown | amount (we are checking).
     */
    private function notifyLatePayment(object $row, string $why): void
    {
        try {
            $this->useSiteLanguage();
            $P    = 'PLG_SYSTEM_WEBGBOOKING_';
            $mf   = Factory::getContainer()->get(MailerFactoryInterface::class);
            $gone = \in_array($why, ['slot', 'past'], true);
            $date = $this->mailDay((string) $row->booking_date);
            // the day name opens a sentence in the box, but sits inside one here: Italian writes it in lower case
            $inText = strncmp($this->langTag(), 'it', 2) === 0 ? mb_strtolower($date) : $date;
            $amount = self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag());
            $back   = !empty($row->source_url) && preg_match('#^https?://#i', (string) $row->source_url) ? (string) $row->source_url : $this->siteRoot();
            $m = [
                'title'       => Text::_($gone ? $P . 'PAY_LATE_TITLE' : $P . 'PAY_LATE_SUBJECT_CHECK'),
                'preheader'   => Text::sprintf($P . 'MAIL_PRE_APPT', $date, $row->booking_time),
                'lead'        => [Text::_($P . 'EMAIL_HELLO') . ' ' . $row->customer_name . ',', Text::sprintf($gone ? $P . 'PAY_LATE_BODY_GONE' : $P . 'PAY_LATE_BODY_CHECK', $inText, $row->booking_time, $amount)],
                'notices'     => [['tone' => 'warn', 'text' => Text::sprintf($P . 'EMAIL_PAID', $amount)]],
                'summary'     => ['rows' => $this->mailRows($row, 'customer')],
                'buttons'     => $gone ? [['label' => Text::_($P . 'PAY_LATE_CTA'), 'url' => $back, 'primary' => true]] : [],
                'footer_note' => Text::_($P . 'EMAIL_FOOTER'),
            ];
            $this->sendLayout($mf, $row->customer_email, Text::_($gone ? $P . 'PAY_LATE_SUBJECT_GONE' : $P . 'PAY_LATE_SUBJECT_CHECK'), $m);
        } catch (\Throwable $x) {
            $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_MAIL_FAILED', 'late payment', (int) $row->id));
        }
    }

    /** Staff alert: money received but the booking cannot stand (slot taken / amount differs / mode / orphan). Refund by hand. */
    private function notifyPaymentReview(object $row, string $why): void
    {
        $keys = ['amount' => 'PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_BODY_AMOUNT', 'mode' => 'PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_BODY_MODE', 'orphan' => 'PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_BODY_ORPHAN'];
        $this->alertStaff(
            'pay-' . $why . '-' . (int) $row->id,
            Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_SUBJECT', $this->shortDate((string) $row->booking_date), $row->booking_time),
            Text::sprintf(
                $keys[$why] ?? 'PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_BODY_SLOT',
                $this->shortDate((string) $row->booking_date),
                $row->booking_time,
                $row->customer_name,
                $row->customer_email,
                self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()),
                (string) ($row->stripe_payment_intent ?: $row->stripe_session_id),
                (int) $row->id
            ),
            24,
            $row
        );
    }

    /** Drop one-time tokens and cache rows nobody will read again. */
    private function purgeKv(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                $db->getQuery(true)->delete($db->quoteName('#__webgbooking_kv'))
                    ->where('(' . $db->quoteName('k') . ' LIKE ' . $db->quote('ret:%') . ' AND ' . $db->quoteName('updated') . ' < ' . $db->quote(Factory::getDate('-3 days')->toSql()) . ')'
                        . ' OR (' . $db->quoteName('k') . ' LIKE ' . $db->quote('done:%') . ' AND ' . $db->quoteName('updated') . ' < ' . $db->quote(Factory::getDate('-2 days')->toSql()) . ')'
                        . ' OR (' . $db->quoteName('k') . ' LIKE ' . $db->quote('fb:%') . ' AND ' . $db->quoteName('updated') . ' < ' . $db->quote(Factory::getDate('-1 hour')->toSql()) . ')'
                        . ' OR (' . $db->quoteName('k') . ' LIKE ' . $db->quote('alert:%') . ' AND ' . $db->quoteName('updated') . ' < ' . $db->quote(Factory::getDate('-30 days')->toSql()) . ')')
            )->execute();
        } catch (\Throwable $e) {
        }
    }

    /** Hold lapsed without payment: free the slot and close the Stripe session. Returns how many were expired. */
    public function expireHolds(int $limit = 200): int
    {
        $n = 0;
        $this->purgeKv();
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $now  = Factory::getDate()->toSql();
            $rows = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
                    ->where($db->quoteName('hold_until') . ' < ' . $db->quote($now))
                    ->order($db->quoteName('id') . ' ASC'),
                0,
                $limit
            )->loadObjectList();
            $sk = $this->stripeSecretOpt('stripe_sk');

            foreach ($rows ?: [] as $row) {
                // The customer may have paid in the last seconds: ask Stripe before giving the slot up.
                $sid = (string) ($row->stripe_session_id ?? '');
                if ($sid !== '' && $sk !== '') {
                    $s = $this->stripeRetrieveSession($sid, $sk);
                    if ($s && ($s['payment_status'] ?? '') === 'paid') {
                        $this->finalizePaid($row, $s);
                        continue;
                    }
                    $this->stripeExpireSession($sid, $sk);
                }
                $db->setQuery(
                    $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                        ->set($db->quoteName('pending_ctx') . ' = NULL')
                        ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                        ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                        ->set($db->quoteName('updated') . ' = ' . $db->quote($now))
                        ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                        ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
                )->execute();
                if ((int) $db->getAffectedRows() === 1) {
                    $row->status = 'expired';
                    $row->payment_status = 'expired';
                    $this->fireHook($row, 'booking.expired');
                }
                $n += (int) $db->getAffectedRows();
            }
        } catch (\Throwable $e) {
        }

        return $n;
    }

    /** Stripe webhook (POST, signed). Always answers JSON: 200 handled/ignored, 400 bad signature, 500 retry. */
    private function stripeWebhook(): void
    {
        $app     = $this->getApplication();
        $payload = (string) file_get_contents('php://input');
        $header  = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
        $secret  = $this->stripeSecretOpt('stripe_whsec');

        if (strtoupper((string) $app->getInput()->getMethod()) !== 'POST') {
            http_response_code(405);
            $this->respond(['error' => 'method']);
        }
        if (!self::verifyStripeSignature($payload, $header, $secret)) {
            http_response_code(400);
            $this->respond(['error' => 'signature']);
        }

        $ev = json_decode($payload, true);
        if (!\is_array($ev) || empty($ev['id']) || empty($ev['type'])) {
            http_response_code(400);
            $this->respond(['error' => 'payload']);
        }

        // A test-mode event sent to a live endpoint (or the opposite) is acknowledged and ignored.
        if (!isset($ev['livemode']) || (bool) $ev['livemode'] !== $this->stripeLive()) {
            $this->respond(['received' => true, 'ignored' => 'mode']);
        }

        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $eid = (string) $ev['id'];

            $seen = (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_stripe_events'))
                    ->where($db->quoteName('event_id') . ' = ' . $db->quote($eid))
            )->loadResult();
            if ($seen > 0) {
                $this->respond(['received' => true, 'duplicate' => true]);
            }

            $obj    = (array) ($ev['data']['object'] ?? []);
            $result = 'ignored';

            if (\in_array($ev['type'], ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                $row = !empty($obj['id']) ? $this->bookingBySession((string) $obj['id']) : null;
                if ($row && ($obj['payment_status'] ?? '') === 'paid') {
                    $this->useSiteLanguage();
                    $result = $this->finalizePaid($row, $obj);
                    if ($result === 'busy') {
                        http_response_code(503); // nothing changed: Stripe will deliver it again
                        $this->respond(['error' => 'busy']);
                    }
                } elseif ($row) {
                    $result = 'unpaid';
                } elseif (!empty($obj['id']) && ($obj['payment_status'] ?? '') === 'paid' && (string) ($obj['metadata']['site'] ?? '') === $this->siteKey()) {
                    // Paid session of THIS site with no booking row at all (hold deleted from the admin while the customer paid).
                    $this->alertStaff(
                        'orphan-' . $obj['id'],
                        Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_ORPHAN_SUBJECT'),
                        Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_ORPHAN_BODY', (string) ($obj['id']), (string) ($obj['payment_intent'] ?? ''), self::formatAmount((int) ($obj['amount_total'] ?? 0), (string) ($obj['currency'] ?? 'eur'), $this->langTag()), (string) ($obj['customer_email'] ?? $obj['customer_details']['email'] ?? '')),
                        24
                    );
                    $result = 'orphan';
                }
            } elseif (\in_array($ev['type'], ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
                $row = !empty($obj['id']) ? $this->bookingBySession((string) $obj['id']) : null;
                if ($row && $row->status === 'awaiting_payment') {
                    $now = Factory::getDate()->toSql();
                    $db->setQuery(
                        $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                            ->set($db->quoteName('pending_ctx') . ' = NULL')
                            ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                            ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                            ->set($db->quoteName('updated') . ' = ' . $db->quote($now))
                            ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                            ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
                    )->execute();
                    if ((int) $db->getAffectedRows() === 1) {
                        $row->status = 'expired';
                        $row->payment_status = 'expired';
                        $this->fireHook($row, 'booking.expired');
                    }
                    $result = 'expired';
                }
            }

            // Recorded only after the handling went through: a crash above means Stripe retries.
            $rec = (object) ['event_id' => $eid, 'type' => mb_substr((string) $ev['type'], 0, 80), 'created' => Factory::getDate()->toSql(), 'result' => mb_substr($result, 0, 40)];
            try {
                $db->insertObject('#__webgbooking_stripe_events', $rec);
            } catch (\Throwable $e) {
                // a parallel delivery of the same event recorded it first: fine
            }

            $this->respond(['received' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            http_response_code(500);
            $this->respond(['error' => 'internal']);
        }
    }

    /** Success URL: never trust the URL, re-read the session from Stripe, confirm if paid, then redirect/thank. */
    private function payReturn(string $token, string $sessionId): void
    {
        $row = $this->bookingByTokenAny($token);
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $sk  = $this->stripeSecretOpt('stripe_sk');

        if (!$row || $sessionId === '' || ($row->stripe_session_id ?? '') !== $sessionId || $sk === '') {
            $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_NOT_FOUND')) . '</p>');
        }

        $s = $this->stripeRetrieveSession($sessionId, $sk);
        if ($s && ($s['payment_status'] ?? '') === 'paid' && (string) ($s['metadata']['booking_id'] ?? '') === (string) $row->id) {
            $this->useSiteLanguage();
            $this->finalizePaid($row, $s);
            $row = $this->bookingByTokenAny($token) ?: $row;
        }

        $ctx = json_decode((string) $this->kvGet('ret:' . $sessionId), true);
        $ctx = \is_array($ctx) ? $ctx : (array) json_decode((string) ($row->pending_ctx ?? ''), true);
        if (($row->payment_status ?? '') === 'paid' && !\in_array($row->status, ['needs_review', 'cancelled', 'expired', 'awaiting_payment'], true)) {
            $url = (string) ($ctx['redirect'] ?? '');
            $ga  = $this->gaPayload($row, $ctx);
            if ($url !== '' && preg_match('#^(https?://[^\s\\\\]+|/(?![/\\\\])[^\s\\\\]*)$#i', $url)) {
                if ($ga !== null) {
                    $tok = bin2hex(random_bytes(12));
                    $this->kvSet('done:' . $tok, (string) json_encode($ga));
                    $frag = '';
                    if (($h = strpos($url, '#')) !== false) {
                        $frag = substr($url, $h);
                        $url  = substr($url, 0, $h);
                    }
                    $url .= (strpos($url, '?') === false ? '?' : '&') . 'wgb_done=' . $tok . $frag;
                }
                $this->getApplication()->redirect($url);
                return;
            }
            $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_OK_TITLE'), '<h1 class="ok">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_OK_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_OK_MSG')) . '</p>', $ga !== null ? $this->gaHead($ga) : '');
        }
        if (($row->payment_status ?? '') === 'paid') {
            if (\in_array((string) ($row->review_why ?? ''), ['slot', 'past'], true)) {
                // Paid after the time to pay, and the slot is gone: say so plainly, with a way to choose again.
                $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_LATE_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_LATE_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_LATE_MSG')) . '</p>' . $this->backButton($row, 'PLG_SYSTEM_WEBGBOOKING_PAY_LATE_CTA'));
            }
            $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_MSG')) . '</p>');
        }
        if ($row->status === 'expired') {
            $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_EXPIRED_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_EXPIRED_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_EXPIRED_MSG')) . '</p>' . $this->backButton($row, 'PLG_SYSTEM_WEBGBOOKING_PAY_CHOOSE_AGAIN'));
        }

        $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_PENDING_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_PENDING_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_PENDING_MSG')) . '</p>');
    }

    /** Cancel URL: the customer backed out of Stripe. Frees the slot (unless Stripe says it was paid meanwhile). */
    private function payCancel(string $token): void
    {
        $row = $this->bookingByTokenAny($token);
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $sk  = $this->stripeSecretOpt('stripe_sk');
        // Already past its time to pay (not the customer pressing "back" just now): the page says the time ran out.
        $wasExpired = $row && $row->status === 'expired' && ($row->payment_status ?? '') !== 'paid';

        if ($row && $row->status === 'awaiting_payment' && $sk !== '') {
            $sid = (string) ($row->stripe_session_id ?? '');
            $s   = $sid !== '' ? $this->stripeRetrieveSession($sid, $sk) : null;
            if ($s && ($s['payment_status'] ?? '') === 'paid') {
                $this->useSiteLanguage();
                $this->finalizePaid($row, $s);
                $this->payReturn($token, $sid);
                return;
            }
            if ($sid !== '') {
                $this->stripeExpireSession($sid, $sk);
            }
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $now = Factory::getDate()->toSql();
            $db->setQuery(
                $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                    ->set($db->quoteName('pending_ctx') . ' = NULL')
                    ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                    ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                    ->set($db->quoteName('updated') . ' = ' . $db->quote($now))
                    ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                    ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
            )->execute();
        }

        if ($wasExpired) {
            $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_EXPIRED_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_EXPIRED_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_EXPIRED_MSG')) . '</p>' . $this->backButton($row, 'PLG_SYSTEM_WEBGBOOKING_PAY_CHOOSE_AGAIN'));
        }
        $back = $row && !empty($row->source_url) && preg_match('#^https?://#i', (string) $row->source_url)
            ? '<p><a class="btn btn-ghost" href="' . $e($row->source_url) . '">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_BACK')) . '</a></p>' : '';
        $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_CANCELLED_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_CANCELLED_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_CANCELLED_MSG')) . '</p>' . $back);
    }

    /** True when a PAID booking is inside the no-cancel window (customer must contact us instead). */
    private function paidLocked(object $row): bool
    {
        if (($row->payment_status ?? '') !== 'paid') {
            return false;
        }
        try {
            $hours = max(0, (int) $this->params->get('paid_cancel_hours', 24));
            $start = (new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', new \DateTimeZone($this->siteTz())))->getTimestamp();

            return $start - time() < $hours * 3600;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Admin is deleting a booking: take its Google event away (and close an open Stripe session) first.
     *
     * @return array{google:?bool, stripe:?bool}
     */
    public function adminBeforeDelete(int $id): array
    {
        $r = ['google' => null, 'stripe' => null];
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_bookings'))->where($db->quoteName('id') . ' = ' . (int) $id)
            )->loadObject();
            if (!$row) {
                return $r;
            }
            if (!empty($row->google_event_id) && $row->status !== 'cancelled') {
                $r['google'] = $this->deleteGoogleEvent((string) $row->google_event_id);
            }
            if ($row->status === 'awaiting_payment' && !empty($row->stripe_session_id)) {
                $r['stripe'] = $this->stripeExpireSession((string) $row->stripe_session_id, $this->stripeSecretOpt('stripe_sk'));
            }
        } catch (\Throwable $e) {
        }

        return $r;
    }

    /**
     * Plugin options save.
     * - Stripe secrets are encrypted at rest (same AES-256-GCM scheme as the Google token). Empty box = keep the
     *   stored value, "-" = clear it.
     * - A key that does not belong to the chosen mode (live key + "test", or the opposite) is an ERROR: the key and
     *   the mode are not saved (the previous ones stay) and the admin is told.
     * - A new secret key is checked with Stripe. Restricted keys (rk_) that cannot read the account are checked with
     *   a Checkout Sessions read; if even that is refused the key is reported as "not verifiable", not rejected.
     * - "Invalidate cached configurations" raises the config epoch (see tokenFresh()).
     */
    public function onExtensionBeforeSave(Event $event): void
    {
        try {
            $a       = $event->getArguments();
            $context = (string) ($a['context'] ?? $a[0] ?? '');
            $table   = $a['subject'] ?? $a[1] ?? null;

            if ($context !== 'com_plugins.plugin' || !\is_object($table) || ($table->element ?? '') !== 'webgbooking' || ($table->folder ?? '') !== 'system') {
                return;
            }

            $new = json_decode((string) $table->params, true);
            if (!\is_array($new)) {
                return;
            }

            $app = $this->getApplication();
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $old = (array) json_decode((string) $db->setQuery(
                $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($db->quoteName('extension_id') . ' = ' . (int) ($table->extension_id ?? 0))
            )->loadResult(), true);

            // config epoch: bump on request, never lower
            $epoch = max(1, (int) ($old['config_epoch'] ?? 1));
            if (!empty($new['config_bump']) && (string) $new['config_bump'] === '1') {
                $epoch++;
                $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EPOCH_BUMPED', $epoch), 'message');
            }
            $new['config_epoch'] = $epoch;
            $new['config_bump']  = '0';

            $freshSk = '';
            $fresh   = [];
            foreach (['stripe_sk', 'stripe_whsec', 'brevo_key', 'whp1_headers', 'whp2_headers', 'whp3_headers', 'whp4_headers'] as $k) {
                $v = trim((string) ($new[$k] ?? ''));
                if ($v === '') {
                    $new[$k] = (string) ($old[$k] ?? '');
                } elseif ($v === '-') {
                    $new[$k] = '';
                } elseif (strncmp($v, 'g:', 2) !== 0) {
                    $new[$k] = $this->enc($v);
                    $fresh[$k] = $v;
                    if ($k === 'stripe_sk') {
                        $freshSk = $v;
                    }
                }
            }

            // Key vs mode (the key actually in force after this save)
            $mode = (string) ($new['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
            $eff  = $freshSk !== '' ? $freshSk : (strncmp((string) ($new['stripe_sk'] ?? ''), 'g:', 2) === 0 ? $this->dec((string) $new['stripe_sk']) : '');
            $keyMode = preg_match('/^(sk|rk)_live_/', $eff) ? 'live' : (preg_match('/^(sk|rk)_test_/', $eff) ? 'test' : '');
            if ($freshSk !== '' && $keyMode === '') {
                $app->enqueueMessage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_PREFIX_ERROR'), 'error');
                $new['stripe_sk'] = (string) ($old['stripe_sk'] ?? '');
                $freshSk = '';
            } elseif ($keyMode !== '' && $keyMode !== $mode) {
                $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_MODE_ERROR', $keyMode, $mode), 'error');
                $new['stripe_sk']   = (string) ($old['stripe_sk'] ?? '');
                $new['stripe_mode'] = (string) ($old['stripe_mode'] ?? 'test');
                $freshSk = '';
            }
            $table->params = (string) json_encode($new);

            if ($freshSk !== '') {
                $chk = $this->checkStripeKey($freshSk);
                if ($chk['ok'] && !empty($chk['restricted'])) {
                    $app->enqueueMessage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_OK_RESTRICTED'), 'message');
                } elseif ($chk['ok']) {
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_OK', $chk['account']), 'message');
                } elseif (!empty($chk['unverifiable'])) {
                    $app->enqueueMessage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_UNVERIFIABLE'), 'warning');
                } else {
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_BAD', (int) $chk['code']), 'warning');
                }
            }

            // Brevo: a newly entered key is checked at once (GET /v3/account). Header profiles: only counts are shown.
            if (isset($fresh['brevo_key'])) {
                $chk = $this->checkBrevoKey($fresh['brevo_key']);
                if ($chk['ok']) {
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_BREVO_KEY_OK', $chk['account']), 'message');
                } elseif (!empty($chk['unverifiable'])) {
                    $app->enqueueMessage(Text::_('PLG_SYSTEM_WEBGBOOKING_BREVO_KEY_UNVERIFIABLE'), 'warning');
                } else {
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_BREVO_KEY_BAD', (int) $chk['code']), 'warning');
                }
            }
            foreach ([1, 2, 3, 4] as $i) {
                if (isset($fresh['whp' . $i . '_headers'])) {
                    $u = '';
                    $hs = [];
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_WHP_SAVED', (string) ($new['whp' . $i . '_name'] ?? ('#' . $i)), \count(self::parseHeaderLines($fresh['whp' . $i . '_headers'], $u, $hs))), 'message');
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Thank-you page of a PAID booking (the element's redirect URL carries ?wgb_done=<token>): the token is a
     * random, single-use, 24 h handle to {event, value, currency, transaction id}, never personal data. On the first
     * view the booking-completed event is pushed to dataLayer (+ gtag when the site defines it), then the token is gone.
     */
    public function onBeforeCompileHead(): void
    {
        try {
            $app = $this->getApplication();
            if (!$app->isClient('site')) {
                return;
            }
            $t = (string) $app->getInput()->getCmd('wgb_done', '');
            if (!preg_match('/^[a-f0-9]{24}$/', $t)) {
                return;
            }
            $raw = $this->kvGet('done:' . $t);
            if ($raw === null) {
                return;
            }
            $this->kvDelete('done:' . $t);
            $d = json_decode($raw, true);
            if (!\is_array($d) || time() - (int) ($d['t'] ?? 0) > 86400) {
                return;
            }
            $doc = $app->getDocument();
            if ($doc && method_exists($doc, 'addScriptDeclaration')) {
                $doc->addScriptDeclaration($this->gaScript($d));
            }
        } catch (\Throwable $e) {
        }
    }

    /** Head tags for the standalone return page: the site's GTM container or GA4 tag (if set in the options) + the event. */
    private function gaHead(array $ga): string
    {
        $gtm = trim((string) $this->params->get('gtm_id', ''));
        $g4  = trim((string) $this->params->get('ga4_id', ''));
        $h   = '';
        if (preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm)) {
            $h .= "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . $gtm . "');</script>";
        } elseif (preg_match('/^G-[A-Z0-9]{6,14}$/', $g4)) {
            $h .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $g4 . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'js\',new Date());gtag(\'config\',\'' . $g4 . '\');</script>';
        }

        return $h . '<script>' . $this->gaScript($ga) . '</script>';
    }

    /** The JS that reports a completed paid booking. */
    private function gaScript(array $d): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $name  = json_encode((string) $d['ev'], $flags);
        $p     = json_encode(['value' => (float) $d['value'], 'currency' => (string) $d['cur'], 'transaction_id' => (string) $d['tid']], $flags);

        return 'window.dataLayer=window.dataLayer||[];(function(){var n=' . $name . ',p=' . $p . ';var o={event:n};for(var k in p){o[k]=p[k];}window.dataLayer.push(o);if(typeof window.gtag===\'function\'){window.gtag(\'event\',n,p);}})();';
    }

    /** Event data for a confirmed paid booking, or null when it was already reported (fires once per booking). */
    private function gaPayload(object $row, array $ctx): ?array
    {
        if ($this->kvGet('ga:' . (int) $row->id) !== null) {
            return null;
        }
        $this->kvSet('ga:' . (int) $row->id, '1');
        $name = (string) ($ctx['analytics'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $name)) {
            $name = 'wgb_booking_completed';
        }

        return ['ev' => $name, 'value' => round(((int) $row->amount_cents) / 100, 2), 'cur' => strtoupper((string) $row->currency), 'tid' => (string) $row->stripe_session_id, 't' => time()];
    }

    /** What the admin screens need to know about the Stripe setup (no secret ever leaves this class). */
    public function stripeStatus(): array
    {
        $sk    = $this->stripeSecretOpt('stripe_sk');
        $whsec = $this->stripeSecretOpt('stripe_whsec');

        return [
            'configured' => $sk !== '',
            'live'       => $this->stripeLive(),
            'secret_ok'  => empty($this->secretFail['stripe_sk']),
            'whsec_ok'   => empty($this->secretFail['stripe_whsec']),
            'whsec_set'  => trim((string) $this->params->get('stripe_whsec', '')) !== '',
        ];
    }

    /**
     * A signed config is honoured only for the CURRENT epoch and while younger than the plugin's lifetime.
     * Epoch: bump it (plugin options, "Invalidate cached configurations") and every page rendered before - including
     * copies cached by Cloudflare - stops booking until it is re-rendered; use it whenever an element changes price.
     * Lifetime (default 30 days, 0 = unlimited) is the backstop: long enough to survive even a monthly Cloudflare
     * "cache everything" rule, short enough that a stale paid/free configuration cannot live forever.
     * Tokens made before 0.33.0 carry no epoch and count as epoch 1.
     */
    private function tokenFresh(array $cfg): bool
    {
        if ((int) ($cfg['v'] ?? 1) !== $this->configEpoch()) {
            return false;
        }
        $ttl = (int) $this->params->get('config_ttl_days', 30);
        if ($ttl > 0 && isset($cfg['iat']) && time() - (int) $cfg['iat'] > $ttl * 86400) {
            return false;
        }

        return true;
    }

    public function configEpoch(): int
    {
        return max(1, (int) $this->params->get('config_epoch', 1));
    }

    /**
     * The per-element config travels in the page as a form token. Since 0.34.1 it is SEALED: AES-256-GCM (key derived
     * from the site secret for this purpose only) plus an HMAC over the sealed text, format `v2.<base64>.<hmac>`.
     * The page source therefore no longer shows the webhook address, the staff recipients or the Brevo settings.
     * Cost: 28 bytes of GCM overhead on top of the same base64 growth (a typical token stays under 3 KB).
     * Tokens made before 0.34.1 (`<base64 json>.<hmac>`, readable) are still accepted, with the same epoch and
     * lifetime rules, so pages cached before the update keep booking until they expire or the epoch is bumped.
     */
    public static function sealFormToken(array $cfg, string $secret): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string) json_encode($cfg), 'aes-256-gcm', hash_hmac('sha256', 'webgbooking/form-token/enc/v2', $secret, true), OPENSSL_RAW_DATA, $iv, $tag);
        $b   = self::b64e($iv . $tag . $ct);

        return 'v2.' . $b . '.' . hash_hmac('sha256', 'v2.' . $b, hash_hmac('sha256', 'webgbooking/form-token/sig/v2', $secret, true));
    }

    /** Check the signature of a form token (sealed or old) and return its content, or [] when it is not ours / was altered. No freshness rule here. */
    public static function openFormToken(string $token, string $secret): array
    {
        try {
            if (strncmp($token, 'v2.', 3) === 0) {
                $parts = explode('.', substr($token, 3), 2);
                if (\count($parts) !== 2) {
                    return [];
                }
                [$b, $sig] = $parts;
                if (!hash_equals(hash_hmac('sha256', 'v2.' . $b, hash_hmac('sha256', 'webgbooking/form-token/sig/v2', $secret, true)), $sig)) {
                    return [];
                }
                $raw = self::b64d($b, true);
                if ($raw === false || \strlen($raw) < 29) {
                    return [];
                }
                $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash_hmac('sha256', 'webgbooking/form-token/enc/v2', $secret, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
                $cfg   = $plain === false ? null : json_decode($plain, true);

                return \is_array($cfg) ? $cfg : [];
            }
            if ($token === '' || strpos($token, '.') === false) {
                return [];
            }
            [$payload, $sig] = explode('.', $token, 2);
            if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
                return [];
            }
            $cfg = json_decode((string) self::b64d($payload), true);

            return \is_array($cfg) ? $cfg : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Base64 helpers: the only two places of this file that encode or decode (signed tokens, encrypted secrets, Google id token). */
    private static function b64e(string $raw): string
    {
        return base64_encode($raw);
    }

    private static function b64d(string $text, bool $strict = false)
    {
        return base64_decode($text, $strict);
    }

    /** One-pass {placeholder} replacement: a value that itself contains "{cancel_url}" is NOT expanded again. */
    private static function fillTpl(string $tpl, array $map): string
    {
        return (string) preg_replace_callback('/\{[a-z_]+\}/', static fn(array $m): string => (string) ($map[$m[0]] ?? $m[0]), $tpl);
    }

    /** Verify the signed per-element actions config from the form token. Returns the array or []. */
    private function verifyForm(string $token): array
    {
        $cfg = self::openFormToken($token, (string) $this->getApplication()->get('secret'));

        return ($cfg && $this->tokenFresh($cfg)) ? $cfg : [];
    }

    /**
     * Validate and collect custom-field answers against the HMAC-signed definitions.
     * Returns a JSON string of [['l'=>label,'v'=>value], …] (or '' when there are none),
     * or boolean false if a required answer is missing/invalid (so the booking is rejected).
     *
     * @return string|false
     */
    private function collectCustomFields(array $defs, $input)
    {
        if (!$defs) {
            return '';
        }

        $raw = (array) $input->post->get('custom', [], 'array');
        $out = [];

        foreach ($defs as $def) {
            if (!\is_array($def) || empty($def['name'])) {
                continue;
            }
            $name  = (string) $def['name'];
            $label = (string) ($def['label'] ?? $name);
            $type  = (string) ($def['type'] ?? 'text');
            $req   = !empty($def['required']);
            $val   = isset($raw[$name]) ? trim(strip_tags((string) $raw[$name])) : '';
            if (mb_strlen($val) > 2000) {
                $val = mb_substr($val, 0, 2000);
            }

            if ($type === 'checkbox') {
                $checked = \in_array(strtolower($val), ['1', 'on', 'true', 'yes'], true);
                if ($req && !$checked) {
                    return false;
                }
                if ($checked) {
                    $out[] = ['l' => $label, 'v' => Text::_('JYES')];
                }
                continue;
            }

            // For a select, only accept a value that is in the signed option list.
            if ($type === 'select' && !empty($def['options']) && \is_array($def['options']) && $val !== '' && !\in_array($val, $def['options'], true)) {
                $val = '';
            }

            if ($req && $val === '') {
                return false;
            }
            if ($val !== '') {
                $out[] = ['l' => $label, 'v' => $val];
            }
        }

        return $out ? (string) json_encode($out) : '';
    }

    /** http(s) URLs only (FILTER_VALIDATE_URL alone also lets ftp:, file:, gopher: through). */
    public static function httpUrlOk(string $url): bool
    {
        // No backslash, "@" or "#": PHP and curl read them differently, which is how an address gets made to point elsewhere.
        return strpbrk($url, chr(92) . '@#') === false && (bool) preg_match('#^https?://[^\s]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** Where the element's webhook points, kept on the booking for the later events. No secrets: the profile is a name. */
    private function hookCfgJson(array $actions): string
    {
        $wh = (array) ($actions['webhook'] ?? []);
        $on = !empty($wh['on']);

        return (string) json_encode([
            'url'     => ($on && !empty($wh['url'])) ? (string) $wh['url'] : '',
            'method'  => $on ? (string) ($wh['method'] ?? '') : '',
            'profile' => $on ? (string) ($wh['profile'] ?? '') : '',
        ]);
    }

    /**
     * A header profile saved in the plugin options (Integrations tab): a NAME the element refers to, and behind it
     * the headers with their secrets (encrypted, never sent back to any form, never put in the page markup).
     * One "Header: value" per line. Optional lines: "URL: https://..." (the only address the headers go to) and
     * "HOSTS: a.example.com, b.example.com" (further hosts an element may point at while using this profile).
     * Returns ['url', 'headers', 'hosts', 'found', 'ok']: `ok` is false when the name is unknown or the stored
     * secret cannot be decrypted (a rotated Joomla secret): the caller must then send NOTHING.
     */
    private function webhookProfile(string $name): array
    {
        $out  = ['url' => '', 'headers' => [], 'hosts' => [], 'found' => false, 'ok' => false];
        $name = trim($name);
        if ($name === '') {
            return $out;
        }
        for ($i = 1; $i <= 4; $i++) {
            if (strcasecmp(trim((string) $this->params->get('whp' . $i . '_name', '')), $name) !== 0) {
                continue;
            }
            $out['found']   = true;
            $raw            = $this->stripeSecretOpt('whp' . $i . '_headers');
            $out['ok']      = empty($this->secretFail['whp' . $i . '_headers']);
            $out['headers'] = self::parseHeaderLines($raw, $out['url'], $out['hosts']);
            break;
        }

        return $out;
    }

    /** Parse the profile lines. Invalid or forbidden header lines are skipped. $url and $hosts receive the URL: and HOSTS: lines. */
    public static function parseHeaderLines(string $raw, string &$url = '', array &$hosts = []): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$h, $v] = array_map('trim', explode(':', $line, 2));
            if (strcasecmp($h, 'url') === 0) {
                if (self::httpUrlOk($v)) {
                    $url = $v;
                }
                continue;
            }
            if (strcasecmp($h, 'hosts') === 0) {
                foreach (preg_split('/[\s,;]+/', strtolower($v)) ?: [] as $host) {
                    if ($host !== '' && preg_match('/^[a-z0-9.-]{1,253}$/', $host)) {
                        $hosts[] = $host;
                    }
                }
                continue;
            }
            if (
                !preg_match('/^[A-Za-z0-9-]{1,60}$/', $h) || $v === '' || \strlen($v) > 1000 || preg_match('/[[:cntrl:]]/', $v)
                || \in_array(strtolower($h), ['host', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'accept-encoding'], true)
            ) {
                continue;
            }
            if (\count($out) >= 6) {
                break;
            }
            $out[$h] = $v;
        }

        return $out;
    }

    /** The JSON a webhook receives (the same keys for every event; `event` says which one it is). */
    private function hookPayload(object $row, string $event, array $extra = []): array
    {
        return $extra + [
            'event'       => $event,
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
            'location'    => $this->rowLocation($row),
            'duration'    => $this->rowDuration($row),
            'event_name'  => (string) ($row->event_name ?? ''),
            'payment_status' => (string) ($row->payment_status ?? ''),
            'amount'      => isset($row->amount_cents) && $row->amount_cents !== null ? round(((int) $row->amount_cents) / 100, 2) : null,
            'currency'    => (string) ($row->currency ?? ''),
            'custom'      => $this->customMap($row),
        ];
    }

    /** Is this IP a public, routable address? Loopback, private, link-local, CGNAT, documentation and multicast ranges are not. */
    public static function ipIsPublic(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (stripos($ip, '::ffff:') === 0) {                       // IPv4 written inside IPv6
            $v4 = substr($ip, 7);
            if (!filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }
            $ip = $v4;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        foreach (['0.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3',
            '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8'] as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where a customer-chosen URL really points, checked at the moment of the call: the host is resolved HERE, every
     * address must be public, and the caller connects to that very address (so a DNS answer that changes between
     * the check and the connection, "DNS rebinding", gets nothing). Credentials in the URL are refused.
     * Returns ['host', 'port', 'ip'] or null when the target is not allowed.
     * The hidden option `webhook_allow_private` = 1 exists only for the test kit (its mock receiver lives on 127.0.0.1).
     */
    private function safeTarget(string $url): ?array
    {
        $u = parse_url($url);
        if (!\is_array($u) || empty($u['host']) || isset($u['user']) || isset($u['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($u['scheme'] ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower(trim((string) $u['host'], '[]'));
        $port = (int) ($u['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips  = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            // IPv4 through the system resolver (the same one curl uses, so /etc/hosts and local DNS rules count), IPv6 through DNS.
            $ips = (array) @gethostbynamel($host);
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
                $ips[] = (string) ($r['ipv6'] ?? '');
            }
            $ips = array_values(array_unique(array_filter($ips)));
        }
        if (!$ips) {
            return null;
        }
        if ((string) $this->params->get('webhook_allow_private', '') !== '1') {
            foreach ($ips as $ip) {
                if (!self::ipIsPublic($ip)) {
                    return null;
                }
            }
        }
        usort($ips, static fn($x, $y) => (strpos($x, ':') !== false) <=> (strpos($y, ':') !== false));   // IPv4 first

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** An HTTP client that connects to the address safeTarget() approved, and never follows a redirect. */
    private function pinnedHttp(array $pin)
    {
        // The address pin (CURLOPT_RESOLVE) only exists in the curl transport: never fall back to the stream one.
        if (!function_exists('curl_init')) {
            return null;
        }
        $ip = strpos($pin['ip'], ':') !== false ? '[' . $pin['ip'] . ']' : $pin['ip'];

        return (new HttpFactory())->getHttp([
            'follow_location' => false,
            'transport.curl'  => [
                CURLOPT_RESOLVE        => [$pin['host'] . ':' . $pin['port'] . ':' . $ip],
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ],
        ], ['curl']);
    }

    /** A webhook that must not be sent: say why in the log (Italian, no values), tell the staff once, and answer 'refused' (not worth a retry). */
    private function hookRefuse(object $row, string $event, string $why): string
    {
        $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_HOOK_REFUSED', $event, (int) $row->id, $why));
        $this->alertStaff(
            'hookcfg' . (int) $row->id,
            Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_HOOKCFG_SUBJECT'),
            Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_HOOKCFG_BODY', (int) $row->id, $event, $why),
            24,
            $row
        );

        return 'refused';
    }

    /**
     * Deliver one webhook call. $cfg = ['url', 'method', 'profile'] as stored on the booking (url may be empty).
     * The header-profile rule (a profile's secrets never go to an address its owner did not name):
     *  - the headers of a profile go ONLY to the profile's own address (its "URL:" line), to a host listed in its
     *    "HOSTS:" line, or, for the plugin-wide profile, to the plugin-wide webhook address;
     *  - an element with its own address and a profile that does not allow it sends nothing (refused, staff told);
     *  - a profile that is named but missing or undecryptable sends nothing (no fall back to another address);
     *  - with headers the address must be https.
     * Every address must also pass safeTarget() (no internal networks) and redirects are never followed.
     * Returns true (delivered, or nothing to deliver), false (transport error, 429 or 5xx: worth a retry) or
     * 'refused' (a configuration problem a retry cannot fix, already logged and mailed to the staff).
     */
    private function hookSend(object $row, string $event, array $cfg, array $extra = [])
    {
        try {
            $profName = trim((string) ($cfg['profile'] ?? ''));
            $elUrl    = trim((string) ($cfg['url'] ?? ''));
            $gUrl     = trim((string) $this->params->get('booking_webhook', ''));
            $gName    = trim((string) $this->params->get('booking_webhook_profile', ''));
            $headers  = [];
            $url      = '';

            if ($profName !== '') {
                $prof = $this->webhookProfile($profName);
                if (!$prof['ok']) {
                    return $this->hookRefuse($row, $event, Text::sprintf('PLG_SYSTEM_WEBGBOOKING_WHY_PROFILE_MISSING', mb_substr($profName, 0, 40)));
                }
                $isGlobal = $gName !== '' && strcasecmp($profName, $gName) === 0;
                $url      = $elUrl !== '' ? $elUrl : ($prof['url'] !== '' ? $prof['url'] : ($isGlobal ? $gUrl : ''));
                if ($url === '') {
                    return $this->hookRefuse($row, $event, Text::sprintf('PLG_SYSTEM_WEBGBOOKING_WHY_PROFILE_NOURL', mb_substr($profName, 0, 40)));
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (!($url === $prof['url'] || ($host !== '' && \in_array($host, $prof['hosts'], true)) || ($isGlobal && $url === $gUrl))) {
                    return $this->hookRefuse($row, $event, Text::sprintf('PLG_SYSTEM_WEBGBOOKING_WHY_PROFILE_OTHER', mb_substr($profName, 0, 40)));
                }
                $headers = $prof['headers'];
            } elseif ($elUrl !== '') {
                $url = $elUrl;                                   // the element's own address: no profile, no secret headers
            } else {
                $url = $gUrl;                                    // the plugin-wide webhook and its profile
                if ($gName !== '') {
                    $g = $this->webhookProfile($gName);
                    if (!$g['ok']) {
                        return $this->hookRefuse($row, $event, Text::sprintf('PLG_SYSTEM_WEBGBOOKING_WHY_GLOBAL_MISSING', mb_substr($gName, 0, 40)));
                    }
                    if ($url === '') {
                        $url = $g['url'];
                    }
                    $headers = $g['headers'];
                }
            }
            if ($url === '') {
                return true;                                     // nothing configured: nothing to deliver
            }
            if (!self::httpUrlOk($url)) {
                return $this->hookRefuse($row, $event, Text::_('PLG_SYSTEM_WEBGBOOKING_WHY_BADURL'));
            }
            if ($headers && stripos($url, 'https://') !== 0) {
                return $this->hookRefuse($row, $event, Text::_('PLG_SYSTEM_WEBGBOOKING_WHY_HTTPS'));
            }
            $pin = $this->safeTarget($url);
            if ($pin === null) {
                return $this->hookRefuse($row, $event, Text::_('PLG_SYSTEM_WEBGBOOKING_WHY_INTERNAL'));
            }

            $method = strtoupper(trim((string) ($cfg['method'] ?? '')) !== '' ? (string) $cfg['method'] : (string) $this->params->get('booking_webhook_method', 'POST'));
            $data   = $this->hookPayload($row, $event, $extra);
            $http   = $this->pinnedHttp($pin);
            if ($http === null) {
                return $this->hookRefuse($row, $event, Text::_('PLG_SYSTEM_WEBGBOOKING_WHY_NOCURL'));
            }
            if ($method === 'GET') {
                $sep  = strpos($url, '?') === false ? '?' : '&';
                $resp = $http->get($url . $sep . http_build_query($data), $headers, 8);
            } else {
                $resp = $http->post($url, json_encode($data), $headers + ['Content-Type' => 'application/json'], 8);
            }
            $code = self::httpCode($resp);
            if ($code >= 300 && $code < 400) {
                $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_HOOK_REDIRECT', $code, $event, (int) $row->id));
            } elseif ($code >= 400 && $code < 500 && $code !== 429) {
                $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_HOOK_4XX', $code, $event, (int) $row->id));
            }

            return $code < 500 && $code !== 429;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Tell the webhook about something that happened to a booking AFTER it was created (moved, cancelled,
     * confirmed, expired). A failed delivery goes to a small outbox (kv `hq:`, at most 200 entries) that the scheduled
     * task retries.
     */
    private function fireHook(object $row, string $event, array $extra = []): void
    {
        try {
            $cfg = json_decode((string) ($row->hook_cfg ?? ''), true);
            if ($this->hookSend($row, $event, \is_array($cfg) ? $cfg : [], $extra) === false) {
                $db = Factory::getContainer()->get(DatabaseInterface::class);
                if ((int) $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_kv'))->where($db->quoteName('k') . ' LIKE ' . $db->quote('hq:%')))->loadResult() >= 200) {
                    $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_HOOK_QUEUE_FULL', $event, (int) $row->id));

                    return;
                }
                $this->kvSet('hq:' . (int) $row->id . ':' . preg_replace('/[^a-z.]/', '', $event) . ':' . bin2hex(random_bytes(3)), (string) json_encode(['id' => (int) $row->id, 'event' => $event, 'extra' => $extra, 'tries' => 1, 'at' => time()]));
            }
        } catch (\Throwable $e) {
        }
    }

    /** Redeliver the webhook calls that failed (backoff 2, 4, 6 ... minutes, at most 8 tries, at most 40 s of work per run). Returns how many were sent. */
    private function retryHooks(int $limit = 20): int
    {
        $n  = 0;
        $t0 = microtime(true);
        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $rows = $db->setQuery(
                $db->getQuery(true)->select($db->quoteName(['k', 'v']))->from($db->quoteName('#__webgbooking_kv'))
                    ->where($db->quoteName('k') . ' LIKE ' . $db->quote('hq:%'))->order($db->quoteName('updated') . ' ASC'),
                0,
                $limit
            )->loadObjectList();

            foreach ($rows ?: [] as $r) {
                if (microtime(true) - $t0 > 40) {
                    break;                                   // the rest waits for the next run: the task never hangs on a slow receiver
                }
                $q = json_decode((string) $r->v, true);
                if (!\is_array($q) || empty($q['id'])) {
                    $this->kvDelete((string) $r->k);
                    continue;
                }
                $tries = (int) ($q['tries'] ?? 1);
                if (time() - (int) ($q['at'] ?? 0) < $tries * 120) {
                    continue;
                }
                $row = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_bookings'))->where($db->quoteName('id') . ' = ' . (int) $q['id']))->loadObject();
                if (!$row) {
                    $this->kvDelete((string) $r->k);
                    continue;
                }
                $cfg = json_decode((string) ($row->hook_cfg ?? ''), true);
                $res = $this->hookSend($row, (string) $q['event'], \is_array($cfg) ? $cfg : [], (array) ($q['extra'] ?? []));
                if ($res === true) {
                    $this->kvDelete((string) $r->k);
                    $n++;
                } elseif ($res === 'refused') {
                    $this->kvDelete((string) $r->k);         // a configuration problem: already logged and mailed
                } elseif ($tries >= 8) {
                    $this->kvDelete((string) $r->k);
                    $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_HOOK_GAVEUP', (string) $q['event'], (int) $q['id']));
                    $this->alertStaff('hookfail' . (int) $q['id'] . $q['event'], Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_HOOK_SUBJECT'), Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_HOOK_BODY', (int) $q['id'], (string) $q['event']), 24, $row);
                } else {
                    $q['tries'] = $tries + 1;
                    $q['at']    = time();
                    $this->kvSet((string) $r->k, (string) json_encode($q));
                }
            }
        } catch (\Throwable $e) {
        }

        return $n;
    }

    // ------------------------------------------------------------------------------------------------
    // Brevo (native): the booker as a contact, optionally on a list, with double opt-in when they ticked the newsletter
    // ------------------------------------------------------------------------------------------------

    /** A phone number in E.164 (+39...), or '' when it cannot be made into one with confidence. */
    public static function e164(string $phone, string $defaultCc = '39'): string
    {
        $d = preg_replace('/[^\d+]/', '', trim($phone));
        if ($d === '' || strpos((string) $d, '+', 1) !== false) {
            return '';
        }
        if (strncmp($d, '00', 2) === 0) {
            $d = '+' . substr($d, 2);
        }
        if ($d[0] !== '+') {
            $cc = preg_replace('/\D/', '', $defaultCc);
            if ($cc === '') {
                return '';
            }
            // Italian numbers keep their leading 0 (landlines); elsewhere a single trunk 0 is dropped.
            $d = '+' . $cc . ($cc === '39' ? $d : preg_replace('/^0/', '', $d));
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $d) ? $d : '';
    }

    /** Brevo attributes for a booking: first name / surname / SMS + "ATTRIBUTE=Question label" mappings. Visitor input is cleaned first. */
    private function brevoAttributes(object $row, string $mapping): array
    {
        $attr = static fn(string $v, string $def): string => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', trim($v) !== '' ? $v : $def));
        $aFirst = $attr((string) $this->params->get('brevo_attr_first', ''), 'NOME');
        $aLast  = $attr((string) $this->params->get('brevo_attr_last', ''), 'COGNOME');
        $aSms   = $attr((string) $this->params->get('brevo_attr_sms', ''), 'SMS');

        // What the visitor typed is stored by Brevo and may reach mailing templates: no markup, a short length, and no
        // links or addresses in the name fields (the usual way of smuggling a message into someone else's inbox).
        $clean = static fn(string $v, int $max): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($v))), 0, $max);
        $nameOk = static fn(string $v): bool => $v !== '' && !preg_match('/@|:\/\/|www\.|\b[a-z0-9-]+\.(com|net|org|it|io|ru|xyz|info|biz|me|co|eu|dev|app|top|site|online|link|click|cn|tk)\b/iu', $v);

        $out   = [];
        $parts = explode(' ', $clean((string) $row->customer_name, 190), 2);
        $first = $clean($parts[0], 60);
        $last  = $clean((string) ($parts[1] ?? ''), 60);
        if ($aFirst !== '' && $nameOk($first)) {
            $out[$aFirst] = $first;
        }
        if ($aLast !== '' && $nameOk($last)) {
            $out[$aLast] = $last;     // the first word is the name, the rest the surname (De Luca, Dalla Chiesa ...)
        }
        $sms = self::e164((string) $row->customer_phone, (string) $this->params->get('brevo_default_cc', '39'));
        if ($aSms !== '' && $sms !== '') {
            $out[$aSms] = $sms;
        }

        $answers = [];
        foreach ($this->customMap($row) as $label => $value) {
            $answers[mb_strtolower(trim((string) $label))] = (string) $value;
        }
        foreach (preg_split('/\r\n|\r|\n/', $mapping) ?: [] as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            [$name, $label] = array_map('trim', explode('=', $line, 2));
            $name = strtoupper($name);
            $v    = $clean($answers[mb_strtolower($label)] ?? '', 200);
            if (preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $name) && $v !== '') {
                $out[$name] = $v;
            }
        }

        return $out;
    }

    /** One Brevo API call. Returns [http code, decoded body]. Throws on a transport error (the caller retries). */
    private function brevoCall(string $method, string $path, ?array $body, string $key): array
    {
        $http = (new HttpFactory())->getHttp();
        $h    = ['api-key' => $key, 'accept' => 'application/json', 'Content-Type' => 'application/json'];
        $url  = 'https://api.brevo.com' . $path;
        $resp = $method === 'GET' ? $http->get($url, $h, 15) : $http->post($url, (string) json_encode($body), $h, 15);
        $data = json_decode(self::httpBody($resp), true);

        return [self::httpCode($resp), \is_array($data) ? $data : []];
    }

    /** Check a Brevo API key (GET /v3/account). ['ok' => bool, 'account' => string, 'code' => int, 'unverifiable' => bool]. */
    public function checkBrevoKey(string $key): array
    {
        try {
            [$code, $data] = $this->brevoCall('GET', '/v3/account', null, $key);
            if ($code === 200) {
                return ['ok' => true, 'account' => mb_substr((string) ($data['companyName'] ?? $data['email'] ?? ''), 0, 80), 'code' => 200];
            }

            return ['ok' => false, 'code' => $code, 'unverifiable' => $code >= 500 || $code === 429];
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 0, 'unverifiable' => true];
        }
    }

    private function brevoFail(object $row, string $why): void
    {
        $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_BREVO_FAIL', (int) $row->id, $why));
        $this->alertStaff(
            'brevo' . (int) $row->id,
            Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_BREVO_SUBJECT'),
            Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_BREVO_BODY', (int) $row->id, $row->customer_name, $row->customer_email, $why),
            12,
            $row
        );
    }

    /**
     * The booker in Brevo, under these rules (the visitor chose nothing but the tick, and the tick only counts when the
     * element really shows the newsletter box):
     *  - ticked AND the element has lists: subscribe THROUGH Brevo's double opt-in (doubleOptinConfirmation). Without the
     *    DOI template there is no subscription at all: error in the log and an email to the staff;
     *  - otherwise, when the element says "every booking" (or the box was ticked but there are no lists): the contact is
     *    created WITHOUT any list and an existing contact is left untouched (no overwrite: anyone can type anyone's email);
     *  - otherwise nothing.
     * Returns ok | skip | error (permanent, already logged and mailed to the staff) | retry (Brevo or the network is down).
     */
    private function brevoSync(object $row, array $bv, bool $newsletter): string
    {
        try {
            $lists     = array_values(array_unique(array_filter(array_map('intval', (array) ($bv['lists'] ?? [])), static fn($i) => $i > 0)));
            $subscribe = $newsletter && $lists;
            if (!$subscribe && !(($bv['when'] ?? 'newsletter') === 'always' || $newsletter)) {
                return 'skip';
            }
            $key = $this->stripeSecretOpt('brevo_key');
            if ($key === '') {
                $this->brevoFail($row, Text::_('PLG_SYSTEM_WEBGBOOKING_WHY_BREVO_NOKEY'));

                return 'error';
            }
            $attrs = $this->brevoAttributes($row, (string) ($bv['attrs'] ?? ''));

            if ($subscribe) {
                $doi = (array) ($bv['doi'] ?? []);
                if ((int) ($doi['template'] ?? 0) < 1) {
                    $this->brevoFail($row, Text::_('PLG_SYSTEM_WEBGBOOKING_WHY_BREVO_NODOI'));

                    return 'error';
                }
                [$code, $data] = $this->brevoCall('POST', '/v3/contacts/doubleOptinConfirmation', [
                    'email'          => $row->customer_email,
                    'attributes'     => (object) $attrs,
                    'includeListIds' => $lists,
                    'templateId'     => (int) $doi['template'],
                    'redirectionUrl' => (string) (($doi['redirect'] ?? '') !== '' ? $doi['redirect'] : $this->siteRoot()),
                ], $key);
            } else {
                [$code, $data] = $this->brevoCall('POST', '/v3/contacts', ['email' => $row->customer_email, 'attributes' => (object) $attrs], $key);
                if ($code === 400 && ($data['code'] ?? '') === 'duplicate_parameter') {
                    return 'ok';                           // already a contact: left exactly as it is
                }
            }

            if ($code >= 200 && $code < 300) {
                return 'ok';
            }
            if ($code === 429 || $code >= 500) {
                $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_BREVO_RETRY', $code, (int) $row->id));

                return 'retry';
            }
            $msg = mb_substr((string) preg_replace('/[^\p{L}\p{N} .,:;()\/\'_-]/u', '', (string) ($data['message'] ?? '')), 0, 200);
            if ($code === 401 || $code === 403) {
                $this->brevoFail($row, Text::sprintf('PLG_SYSTEM_WEBGBOOKING_WHY_BREVO_KEYREFUSED', $code));
            } else {
                $this->brevoFail($row, Text::sprintf('PLG_SYSTEM_WEBGBOOKING_WHY_BREVO_HTTP', $code, ($msg !== '' ? ' (' . $msg . ')' : '')));
            }

            return 'error';
        } catch (\Throwable $e) {
            $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_BREVO_DOWN', (int) $row->id));

            return 'retry';
        }
    }

    /** Append the booking as a row to a configured Google Sheet. True when written or when no Sheet is configured. */
    private function appendToSheet(object $row): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return true;
            }

            // Sheet chosen in the component (Google page) → fallback to the plugin param.
            $sheetId = trim((string) ($g->sheet_id ?? '')) ?: trim((string) $this->params->get('google_sheet_id', ''));
            if ($sheetId === '') {
                return true;
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);
            if ($token === '') {
                return false;
            }

            $tab   = trim((string) ($g->sheet_tab ?? ''));
            $range = $tab !== '' ? rawurlencode("'" . str_replace("'", "''", $tab) . "'!A1") : 'A1';

            $customCol = [];
            foreach ($this->customAnswers($row) as $it) {
                $customCol[] = $it['l'] . ': ' . $it['v'];
            }

            // Neutralise spreadsheet formula injection: any visitor value starting with = + - @ (or a
            // control char) is treated by Sheets as a live formula. Prefix such cells with an apostrophe.
            $safe = static function ($v): string {
                $v = (string) $v;
                return ($v !== '' && \in_array($v[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) ? "'" . $v : $v;
            };

            $values = [array_map($safe, [
                $row->created, $row->booking_date, $row->booking_time,
                $row->customer_name, $row->customer_email, (string) $row->customer_phone,
                (string) $row->guest_email, (string) $row->notes, $row->status, (string) $row->meeting_url,
                implode(' | ', $customCol),
                $this->rowLocation($row), (string) $this->rowDuration($row), (string) ($row->event_name ?? ''),
            ])];

            $resp = (new HttpFactory())->getHttp()->post(
                'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId) . '/values/' . $range . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
                json_encode(['values' => $values]),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                8
            );

            return self::httpCode($resp) < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Forward a consented subscriber to the configured newsletter webhook (admin-set URL). True when sent or not configured. */
    private function newsletterSignup(object $row): bool
    {
        try {
            $url = trim((string) $this->params->get('newsletter_webhook', ''));
            if ($url === '' || !self::httpUrlOk($url)) {
                return true;
            }

            $pin = $this->safeTarget($url);
            if ($pin === null) {
                $this->wlog(Text::_('PLG_SYSTEM_WEBGBOOKING_LOG_NL_INTERNAL'));

                return true;
            }
            $http = $this->pinnedHttp($pin);
            if ($http === null) {
                $this->wlog(Text::_('PLG_SYSTEM_WEBGBOOKING_LOG_NL_NOCURL'));

                return true;
            }
            $resp = $http->post(
                $url,
                json_encode([
                    'email'  => $row->customer_email,
                    'name'   => $row->customer_name,
                    'phone'  => $row->customer_phone,
                    'source' => 'webgbooking',
                    'date'   => $row->booking_date,
                ]),
                ['Content-Type' => 'application/json'],
                8
            );

            return self::httpCode($resp) < 500;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Confirmation emails. $parts: all | customer | staff. Each part is independent so a retry can redo only the
     * one that failed. Returns true when every requested part went out (or had nothing to send).
     */
    private function notify(object $row, bool $reschedule = false, string $parts = 'all'): bool
    {
        $ok = true;

        try {
            $config = $this->getApplication()->getConfig();
            $admin  = trim((string) $this->params->get('notify_email', '')) ?: (string) $config->get('mailfrom');
            $mf     = Factory::getContainer()->get(MailerFactoryInterface::class);
            $this->rootFrom($row);
            $manageUrl = $this->manageUrl($row);

            $dateFmt = $row->booking_date;
            try {
                $dateFmt = (new \DateTime($row->booking_date))->format('d/m/Y');
            } catch (\Throwable $e) {
            }

            if ($parts === 'all' || $parts === 'customer') {
                try {
                    $this->notifyCustomer($row, $reschedule, $mf, $manageUrl, $dateFmt);
                } catch (\Throwable $e) {
                    $ok = false;
                    $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_MAIL_FAILED', 'customer', (int) $row->id));
                }
            }
            if ($parts === 'all' || $parts === 'guest') {
                try {
                    $this->notifyGuest($row, $reschedule, $mf, $dateFmt);
                } catch (\Throwable $e) {
                    $ok = false;
                    $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_MAIL_FAILED', 'guest', (int) $row->id));
                }
            }
            if ($parts === 'all' || $parts === 'staff') {
                try {
                    $this->notifyStaff($row, $mf, $manageUrl, $dateFmt, $admin, $reschedule);
                } catch (\Throwable $e) {
                    $ok = false;
                    $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_MAIL_FAILED', 'staff', (int) $row->id));
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return $ok;
    }

    /**
     * Until 0.34.1 the builder showed an Italian email text as the default of the customer and staff body fields, and saved it
     * when the element was stored. That text is the OLD default: it is fixed Italian, says "confirmed" even for a booking waiting
     * for confirmation and has no payment line. A body that is exactly that text (whitespace, quote style and entities forgiven)
     * is treated as empty, so the element takes the current translated default. Any edited text is left alone.
     */
    private static function isLegacyDefaultBody(string $body): bool
    {
        if (trim($body) === '') {
            return false;
        }
        $b = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $b = str_replace('"', "'", $b);
        $b = (string) preg_replace('/<br\s*\/?>/i', '<br>', $b);
        $b = (string) preg_replace('/\s+/u', ' ', $b);
        $b = (string) preg_replace('/>\s+</', '><', $b);

        return \in_array(md5(trim($b)), ['dcc4ea0bdf679aff87a7dea35287626d', 'f406d3e589bc3a2f0123a0a21097ad3e'], true);
    }

    /** Customer email. */
    private function notifyCustomer(object $row, bool $reschedule, $mf, string $manageUrl, string $dateFmt): void
    {
        $ics      = $this->buildIcs($row, $reschedule ? 'REQUEST' : 'PUBLISH');

        // ---- Customer email action (and invited guest). Skipped if the action is off. ----
        if ($reschedule || ($row->_custOn ?? true)) {
            $isRequest = false;
            if ($reschedule) {
                $subject = self::fillTpl(Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_EMAIL_SUBJECT'), ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]);
                $bodyTpl = '';
            } else {
                $elSub      = (string) ($row->emailSubjectTpl ?? '');
                $elBody     = (string) ($row->emailBodyTpl ?? '');
                if (self::isLegacyDefaultBody($elBody)) {
                    $elBody = '';
                }
                $isRequest  = (string) ($row->status ?? '') === 'pending';
                $subjectTpl = $elSub !== '' ? $elSub : (trim((string) $this->params->get('email_subject', '')) ?: Text::_($isRequest ? 'PLG_SYSTEM_WEBGBOOKING_EMAIL_CUSTOMER_SUBJECT_REQUEST' : 'PLG_SYSTEM_WEBGBOOKING_EMAIL_CUSTOMER_SUBJECT'));
                $subject    = self::fillTpl($subjectTpl, ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]);
                $bodyTpl    = $elBody !== '' ? $elBody : trim((string) $this->params->get('email_body', ''));
            }
            $this->sendLayout($mf, $row->customer_email, $subject, $this->customerMail($row, $reschedule ? 'rescheduled' : ($isRequest ? 'request' : 'confirmed'), $manageUrl, $dateFmt, $bodyTpl), $ics);

        }
    }

    /** Invited guest: a distinct email WITHOUT the cancel link/token and without the customer's phone. */
    private function notifyGuest(object $row, bool $reschedule, $mf, string $dateFmt): void
    {
        if (empty($row->guest_email) || !($reschedule || ($row->_custOn ?? true))) {
            return;
        }
        $icsGuest = $this->buildIcs($row, $reschedule ? 'REQUEST' : 'PUBLISH', true);
        $gSubject = self::fillTpl(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_GUEST_SUBJECT'), ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]);
        $this->sendLayout($mf, $row->guest_email, $gSubject, $this->customerMail($row, 'guest', '', $dateFmt), $icsGuest);
    }

    /** Keep only valid addresses of a comma separated list (max 500 chars). */
    private function cleanRecipients(string $list): string
    {
        $ok = [];
        foreach (explode(',', $list) as $a) {
            $a = trim($a);
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
                $ok[] = $a;
            }
        }

        return mb_substr(implode(', ', $ok), 0, 500);
    }

    /**
     * Who the staff emails of this booking go to: the recipient(s) of the element it was made from (stored on the
     * booking, so reschedule, cancellation and alerts reach the same people as the "new booking" email), else the
     * plugin Notify e-mail, else the site sender.
     */
    private function staffRecipients(?object $row = null): array
    {
        $own = $this->cleanRecipients((string) ($row->staff_to ?? ($row->_staffTo ?? '')));
        if ($own !== '') {
            return array_map('trim', explode(',', $own));
        }
        $admin = trim((string) $this->params->get('notify_email', '')) ?: (string) $this->getApplication()->getConfig()->get('mailfrom');

        return $admin !== '' ? [$admin] : [];
    }

    /** Staff email: per-element HTML template, or the plain default. */
    private function notifyStaff(object $row, $mf, string $manageUrl, string $dateFmt, string $admin, bool $moved = false): void
    {
        if (!($row->_staffOn ?? true)) {
            return;
        }
        $tos = $this->staffRecipients($row);
        if (!$tos) {
            return;
        }
        $sSubTpl = (string) ($row->_staffSubject ?? '');
        $sSub    = $moved
            ? Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_ADMIN_SUBJECT_MOVED', $this->shortDate((string) $row->booking_date), $row->booking_time)
            : ($sSubTpl !== '' ? self::fillTpl($sSubTpl, ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time]) : Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_ADMIN_SUBJECT', $this->shortDate((string) $row->booking_date), $row->booking_time));
        $sBody = $moved ? '' : (string) ($row->_staffBody ?? '');
        if (self::isLegacyDefaultBody($sBody)) {
            $sBody = '';
        }
        $this->sendLayout($mf, $tos, $sSub, $this->staffMail($row, $moved ? 'moved' : 'new', $manageUrl, $dateFmt, $sBody));
    }

    /** Self-service management URL (cancel / future reschedule), authorised by the per-booking token. */
    private function manageUrl(object $row, string $root = ''): string
    {
        if ($root === '') {
            $root = $this->siteRoot();
        }

        return $root . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' . rawurlencode((string) $row->manage_token);
    }

    // ------------------------------------------------------------------------------------------------
    // Emails: ONE layout for all of them (Mail/EmailLayout.php). The functions below only say WHAT goes in.
    // ------------------------------------------------------------------------------------------------

    /** Address of the site; from the scheduler or the command line there is no web request, so the address stored with the booking is used. */
    private string $rootHint = '';

    private function siteRoot(): string
    {
        try {
            return Uri::root();
        } catch (\Throwable $e) {
            return $this->rootHint;
        }
    }

    /** Remember the site address saved on a booking (reminder_cfg.root) for emails sent outside a web request. */
    private function rootFrom(object $row): void
    {
        $cfg = json_decode((string) ($row->reminder_cfg ?? ''), true);
        if (\is_array($cfg) && !empty($cfg['root'])) {
            $this->rootHint = (string) $cfg['root'];
        }
    }

    /** Branding from the plugin options. Neutral by default: no logo, blue accent, the site name. */
    private function mailBrand(): array
    {
        $accent = EmailLayout::hex((string) $this->params->get('mail_color', ''), '#2b59c3');

        return [
            'name'      => trim((string) $this->params->get('mail_business_name', '')) ?: (trim((string) $this->getApplication()->get('sitename', '')) ?: 'WebG Booking'),
            'logo'      => $this->mediaUrl((string) $this->params->get('mail_logo', '')),
            'logo_dark' => $this->mediaUrl((string) $this->params->get('mail_logo_dark', '')),
            'color'     => $accent,
            'button'    => EmailLayout::hex((string) $this->params->get('mail_button_color', ''), $accent),
            'footer'    => trim((string) $this->params->get('mail_footer', '')),
            'logo_pos'     => $this->params->get('mail_logo_pos', 'left') === 'center' ? 'center' : 'left',
            'header_style' => $this->params->get('mail_header_style', 'band') === 'rule' ? 'rule' : 'band',
            'footer_out'   => (int) $this->params->get('mail_footer_out', 0) === 1,
            'line'         => EmailLayout::hex((string) $this->params->get('mail_line_color', ''), ''),
            'link'         => EmailLayout::hex((string) $this->params->get('mail_link_color', ''), ''),
            'text'         => EmailLayout::hex((string) $this->params->get('mail_text_color', ''), ''),
            'footer_color' => EmailLayout::hex((string) $this->params->get('mail_footer_color', ''), ''),
            'site_url'  => $this->siteRoot(),
        ];
    }

    /** Address of an image chosen with the media field ("images/logo.png#joomlaImage://..."): https/http as is, a path under the site root. */
    private function mediaUrl(string $v): string
    {
        $v = trim(explode('#', $v, 2)[0]);
        if ($v === '') {
            return '';
        }
        if (preg_match('#^https?://[^\s"<>]+$#i', $v)) {
            return $v;
        }
        if (preg_match('#^[A-Za-z0-9_./ %()-]+$#', $v) && strpos($v, '..') === false) {
            return $this->siteRoot() . str_replace(' ', '%20', ltrim($v, '/'));
        }

        return '';
    }

    /** Date for subjects and alerts in the order of the site language (28/10/2026; 10/28/2026 only for US English), instead of the stored 2026-10-28. */
    private function shortDate(string $date): string
    {
        try {
            return (new \DateTime($date))->format($this->langTag() === 'en-US' ? 'm/d/Y' : 'd/m/Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    /** "Wednesday 21/10/2026" in the language of the site (weekday names are plugin strings: no dependency on a core language pack). */
    private function mailDay(string $date): string
    {
        try {
            $dt = new \DateTime($date);

            return Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_DAY_' . $dt->format('w')) . ' ' . $dt->format('d/m/Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    /** "15:00 (CEST, Europe/Rome)": the time with its zone. */
    private function mailTime(object $row): string
    {
        try {
            $tz = new \DateTimeZone($this->siteTz());
            $dt = new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz);

            return $row->booking_time . ' (' . $dt->format('T') . ', ' . $tz->getName() . ')';
        } catch (\Throwable $e) {
            return (string) $row->booking_time;
        }
    }

    /** Rows of the appointment box. $who: customer | guest | staff. A value may be [text, url]. */
    private function mailRows(object $row, string $who = 'customer'): array
    {
        $rows = [];
        $svc  = trim((string) ($row->event_name ?? ''));
        if ($svc !== '') {
            $rows[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_SERVICE'), $svc];
        }
        $rows[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_DATE'), $this->mailDay((string) $row->booking_date)];
        $rows[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_TIME'), $this->mailTime($row)];
        $rows[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_DURATION'), Text::sprintf('PLG_SYSTEM_WEBGBOOKING_MAIL_DURATION_VALUE', $this->rowDuration($row))];
        if ($this->rowLocation($row) === 'phone') {
            $rows[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_WHERE'), $who === 'guest' ? Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_WHERE_PHONE') : Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOC_PHONE_AT', (string) $row->customer_phone)];
        } else {
            $url    = (string) ($row->meeting_url ?? '');
            $rows[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_WHERE'), $url !== '' ? [Text::_('PLG_SYSTEM_WEBGBOOKING_LOC_MEET'), $url] : Text::_('PLG_SYSTEM_WEBGBOOKING_LOC_MEET')];
        }
        if ($who !== 'guest' && ($row->payment_status ?? '') === 'paid' && (int) ($row->amount_cents ?? 0) > 0) {
            $amt    = self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag());
            $rows[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_PAYMENT'), $who === 'staff' ? Text::sprintf('PLG_SYSTEM_WEBGBOOKING_STAFF_PAID_LINE', $amt, (string) ($row->stripe_payment_intent ?? '')) : $amt];
        }

        return $rows;
    }

    /** "Add to Google Calendar" link built from the booking (no secret in it). */
    private function mailCalendarUrl(object $row): string
    {
        try {
            $tz = new \DateTimeZone($this->siteTz());
            $s  = (new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz))->setTimezone(new \DateTimeZone('UTC'));
            $e  = (clone $s)->modify('+' . $this->rowDuration($row) . ' minutes');

            return 'https://calendar.google.com/calendar/render?' . http_build_query([
                'action'  => 'TEMPLATE',
                'text'    => trim((string) ($row->event_name ?? '')) ?: (string) $this->getApplication()->get('sitename', 'WebG Booking'),
                'dates'   => $s->format('Ymd\THis\Z') . '/' . $e->format('Ymd\THis\Z'),
                'details' => $this->rowLocation($row) === 'phone' ? '' : (string) ($row->meeting_url ?? ''),
            ]);
        } catch (\Throwable $x) {
            return '';
        }
    }

    private function mailBackendUrl(object $row): string
    {
        return $this->siteRoot() . 'administrator/index.php?option=com_webgbooking&view=booking&id=' . (int) $row->id;
    }

    /** The data of the customer and the answers of the form, as sections of a staff email. */
    private function mailStaffSections(object $row): array
    {
        $cust = [[Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_NAME'), (string) $row->customer_name], [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_EMAIL'), (string) $row->customer_email]];
        if (trim((string) $row->customer_phone) !== '') {
            $cust[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_PHONE'), (string) $row->customer_phone];
        }
        if (!empty($row->guest_email)) {
            $cust[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_GUEST'), (string) $row->guest_email];
        }
        if (trim((string) $row->notes) !== '') {
            $cust[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_NOTES'), (string) $row->notes];
        }
        $cust[] = [Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_L_BOOKING'), '#' . (int) $row->id];
        $secs = [['title' => Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_S_CUSTOMER'), 'rows' => $cust]];
        $ans  = [];
        foreach ($this->customAnswers($row) as $it) {
            $ans[] = [$it['l'], $it['v']];
        }
        if ($ans) {
            $secs[] = ['title' => Text::_('PLG_SYSTEM_WEBGBOOKING_MAIL_S_ANSWERS'), 'rows' => $ans];
        }

        return $secs;
    }

    private static function hasPlaceholder(string $tpl, array $names): bool
    {
        foreach ($names as $n) {
            if (strpos($tpl, $n) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * What goes into a customer or guest email. $kind: confirmed | request | rescheduled | reminder | link | guest | guest_reminder.
     * $bodyTpl is the element's (or the plugin's) own text: it goes INTO the body of the layout, it does not replace the layout
     * (header, title, appointment box, buttons and footer stay).
     */
    private function customerMail(object $row, string $kind, string $manageUrl, string $dateFmt, string $bodyTpl = ''): array
    {
        $P      = 'PLG_SYSTEM_WEBGBOOKING_';
        $guest  = \in_array($kind, ['guest', 'guest_reminder'], true);
        $custom = trim($bodyTpl) !== '';
        $hello  = Text::_($P . 'EMAIL_HELLO') . ($guest ? ',' : ' ' . $row->customer_name . ',');
        $titles = ['confirmed' => 'MAIL_TITLE_CONFIRMED', 'request' => 'MAIL_TITLE_REQUEST', 'rescheduled' => 'MAIL_TITLE_RESCHEDULED', 'reminder' => 'MAIL_TITLE_REMINDER', 'link' => 'MAIL_TITLE_LINK', 'guest' => 'MAIL_TITLE_GUEST', 'guest_reminder' => 'MAIL_TITLE_GUEST_REMINDER'];
        $lead   = [];
        if (!$custom) {
            switch ($kind) {
                case 'request':
                    $lead[] = $hello . ' ' . Text::_($P . 'EMAIL_REQUEST');
                    break;
                case 'rescheduled':
                    $lead[] = $hello . ' ' . Text::_($P . 'EMAIL_RESCHEDULED');
                    break;
                case 'reminder':
                    $lead[] = $hello . ' ' . Text::_($P . 'EMAIL_REMINDER');
                    break;
                case 'link':
                    $lead[] = $hello . ' ' . Text::sprintf($P . 'LINKMAIL_INTRO', $dateFmt, $row->booking_time);
                    break;
                case 'guest':
                    $lead[] = $hello;
                    $lead[] = Text::sprintf($P . 'EMAIL_GUEST_INTRO', $row->customer_name);
                    break;
                case 'guest_reminder':
                    $lead[] = $hello;
                    $lead[] = Text::sprintf($P . 'EMAIL_GUEST_REMINDER_INTRO', $row->customer_name);
                    break;
                default:
                    $lead[] = $hello . ' ' . Text::_($P . 'EMAIL_CONFIRMED');
            }
        }
        if (\in_array($kind, ['confirmed', 'request', 'rescheduled', 'guest'], true)) {
            $lead[] = Text::_($P . 'EMAIL_ICS_HINT');
        }

        $loc      = $this->rowLocation($row);
        $notices  = [];
        if (!$guest && \in_array($kind, ['confirmed', 'rescheduled'], true) && ($row->payment_status ?? '') === 'paid' && (int) ($row->amount_cents ?? 0) > 0) {
            $notices[] = ['tone' => 'ok', 'text' => Text::sprintf($P . 'EMAIL_PAID', self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()))];
        }
        if (!$guest && $loc !== 'phone' && empty($row->meeting_url) && !empty($row->_meetPending) && !self::hasPlaceholder($bodyTpl, ['{meet_block}', '{location_block}'])) {
            $notices[] = ['tone' => 'info', 'text' => Text::_($P . 'EMAIL_MEET_PENDING')];
        }
        if (!$guest && $loc === 'phone' && \in_array($kind, ['confirmed', 'request', 'rescheduled', 'reminder'], true)) {
            $notices[] = ['tone' => 'info', 'text' => Text::sprintf($P . 'EMAIL_PHONE_CALL', (string) $row->customer_phone)];
        }

        $buttons = [];
        if ($loc !== 'phone' && !empty($row->meeting_url) && !self::hasPlaceholder($bodyTpl, ['{meet_block}', '{location_block}', '{meet_url}'])) {
            $buttons[] = ['label' => Text::_($P . 'EMAIL_MEET_BTN'), 'url' => (string) $row->meeting_url, 'primary' => true];
        }
        if (\in_array($kind, ['confirmed', 'request', 'rescheduled', 'guest'], true)) {
            $buttons[] = ['label' => Text::_($P . 'MAIL_BTN_CALENDAR'), 'url' => $this->mailCalendarUrl($row)];
        }
        if (!$guest && $kind !== 'link' && !self::hasPlaceholder($bodyTpl, ['{cancel_block}', '{cancel_url}'])) {
            $buttons[] = ['label' => Text::_($P . 'EMAIL_MANAGE_LINK'), 'url' => $manageUrl];
        }

        return [
            'title'       => Text::_($P . $titles[$kind]),
            'preheader'   => Text::sprintf($P . 'MAIL_PRE_APPT', $this->mailDay((string) $row->booking_date), $row->booking_time),
            'lead'        => $lead,
            'notices'     => $notices,
            'summary'     => ['rows' => $this->mailRows($row, $guest ? 'guest' : 'customer')],
            'body_html'   => $custom ? $this->renderTpl($bodyTpl, $row, $manageUrl, $dateFmt) : '',
            'buttons'     => $buttons,
            'signoff'     => Text::_($P . 'EMAIL_SIGNOFF'),
            'footer_note' => Text::_($P . 'EMAIL_FOOTER'),
        ];
    }

    /** What goes into a staff email about a booking. $kind: new | moved | cancelled. */
    private function staffMail(object $row, string $kind, string $manageUrl, string $dateFmt, string $bodyTpl = ''): array
    {
        $P       = 'PLG_SYSTEM_WEBGBOOKING_';
        $paid    = ($row->payment_status ?? '') === 'paid' && (int) ($row->amount_cents ?? 0) > 0;
        $notices = [];
        if ($kind === 'moved') {
            $title = Text::_($P . 'MAIL_TITLE_STAFF_MOVED');
            $lead  = [Text::sprintf($P . 'EMAIL_ADMIN_MOVED_INTRO', $this->shortDate((string) ($row->_prevDate ?? '')), (string) ($row->_prevTime ?? ''))];
        } elseif ($kind === 'cancelled') {
            $title = Text::_($P . 'MAIL_TITLE_STAFF_CANCELLED');
            $lead  = [Text::sprintf($P . 'CANCEL_ADMIN_BODY', $this->shortDate((string) $row->booking_date), $row->booking_time, $row->customer_name, $row->customer_email)];
            if ($paid) {
                $notices[] = ['tone' => 'warn', 'text' => Text::sprintf($P . 'CANCEL_REFUND_STAFF', self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()), (string) ($row->stripe_payment_intent ?? ''))];
            }
        } else {
            $title = Text::_($P . 'MAIL_TITLE_STAFF_NEW');
            $lead  = [Text::_($P . ($paid ? 'MAIL_STAFF_LEAD_NEW_PAID' : 'MAIL_STAFF_LEAD_NEW'))];
        }

        return [
            'title'       => $title,
            'preheader'   => Text::sprintf($P . 'MAIL_PRE_STAFF', $row->customer_name, $this->mailDay((string) $row->booking_date), $row->booking_time),
            'lead'        => $lead,
            'notices'     => $notices,
            'summary'     => ['rows' => $this->mailRows($row, 'staff')],
            'body_html'   => trim($bodyTpl) !== '' ? $this->renderTpl($bodyTpl, $row, $manageUrl, $dateFmt) : '',
            'sections'    => $this->mailStaffSections($row),
            'buttons'     => [['label' => Text::_($P . 'MAIL_BTN_BACKEND'), 'url' => $this->mailBackendUrl($row), 'primary' => true]],
            'footer_note' => Text::sprintf($P . 'MAIL_STAFF_FOOTER', (string) ($this->mailBrand()['name'])),
        ];
    }

    /** Render with the layout and send (HTML + plain text alternative). */
    private function sendLayout($mf, $to, string $subject, array $m, string $ics = '', string $icsMethod = 'PUBLISH'): void
    {
        $r = EmailLayout::render($m + ['lang' => $this->langTag(), 'brand' => $this->mailBrand()]);
        $this->sendHtmlMail($mf, $to, $subject, $r['html'], $ics, $icsMethod, $r['text']);
    }

    /** A button for the blocks inside a custom text ({meet_block}), in the brand colours. */
    private function inlineButton(string $label, string $url): string
    {
        $b   = $this->mailBrand();
        $bg  = (string) $b['button'];
        $txt = EmailLayout::contrast('#ffffff', $bg) >= 4.5 ? '#ffffff' : '#111827';

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;"><tr><td class="btn-td" bgcolor="' . $bg . '" style="background-color:' . $bg . ';border-radius:8px;"><a class="btn-a" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:13px 26px;font-family:Roboto,\'Segoe UI\',Helvetica,Arial,sans-serif;font-size:16px;line-height:20px;font-weight:700;color:' . $txt . ';text-decoration:none;border-radius:8px;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></td></tr></table>';
    }

    /** Replace placeholders in a (default or admin-edited) HTML email template. */
    private function renderTpl(string $tpl, object $row, string $manageUrl, string $dateFmt): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        // The "Payment received" line is part of the layout for every paid booking, so {payment_block} is empty here;
        // {amount} gives the amount for a text that wants to write it itself.
        $amount   = '';
        $payBlock = '';
        if (($row->payment_status ?? '') === 'paid' && (int) ($row->amount_cents ?? 0) > 0) {
            $amount = self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag());
        }

        $isPhone   = $this->rowLocation($row) === 'phone';
        $meetBlock = '';
        if (!$isPhone && empty($row->meeting_url) && !empty($row->_meetPending)) {
            // The Google event is still being created: promise the link instead of showing an empty one.
            $meetBlock = '<p class="note-box t-head" style="margin:16px 0;padding:12px 16px;background-color:#f4f6fb;border:1px solid #d9deea;border-radius:8px;font-weight:600;">'
                . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_PENDING')) . '</p>';
        } elseif ($isPhone) {
            // Phone appointment: no link, we say we will call the customer at the number they gave.
            $meetBlock = '<p class="note-box t-head" style="margin:16px 0;padding:12px 16px;background-color:#f4f6fb;border:1px solid #d9deea;border-radius:8px;font-weight:600;">'
                . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_PHONE_CALL', (string) $row->customer_phone)) . '</p>';
        } elseif (!empty($row->meeting_url)) {
            $meetBlock = $this->inlineButton(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_BTN'), (string) $row->meeting_url);
        }
        $locLabel = $isPhone
            ? Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOC_PHONE_AT', (string) $row->customer_phone)
            : Text::_('PLG_SYSTEM_WEBGBOOKING_LOC_MEET');
        $cancelBlock = '<p class="t-muted" style="margin:18px 0 0;font-size:14px;color:#5b6074;"><a class="t-link" href="' . $e($manageUrl) . '" style="color:#1f3f9a;text-decoration:underline;">'
            . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MANAGE_LINK')) . '</a></p>';

        // Custom questions block (answers to the per-element custom form fields).
        $customBlock = '';
        $rows = '';
        foreach ($this->customAnswers($row) as $it) {
            $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#6a6a6a;">' . $e($it['l']) . '</td><td style="padding:4px 0;font-weight:600;">' . $e($it['v']) . '</td></tr>';
        }
        if ($rows !== '') {
            $customBlock = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:14px;">' . $rows . '</table>';
        }

        $out = self::fillTpl($tpl, [
            '{name}'         => $e($row->customer_name),
            '{email}'        => $e($row->customer_email),
            '{date}'         => $e($dateFmt),
            '{time}'         => $e($row->booking_time),
            '{phone}'        => $e($row->customer_phone ?: ''),
            '{notes}'        => $e($row->notes ?: ''),
            '{meet_url}'     => $e($row->meeting_url),
            '{cancel_url}'   => $e($manageUrl),
            '{meet_block}'   => $meetBlock,
            '{location_block}' => $meetBlock,
            '{cancel_block}' => $cancelBlock,
            '{custom}'       => $customBlock,
            '{location}'     => $e($locLabel),
            '{duration}'     => $e((string) $this->rowDuration($row)),
            '{event}'        => $e((string) ($row->event_name ?? '')),
            '{amount}'       => $e($amount),
            '{payment_block}' => $payBlock,
        ]);

        return $out;
    }

    /** Decode the stored custom-field answers as a list of ['l' => label, 'v' => value]. */
    private function customAnswers(object $row): array
    {
        $data = json_decode((string) ($row->custom_data ?? ''), true);
        if (!\is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $it) {
            if (\is_array($it) && isset($it['l'])) {
                $out[] = ['l' => (string) $it['l'], 'v' => (string) ($it['v'] ?? '')];
            }
        }

        return $out;
    }

    /** Custom answers as a flat label => value map (for webhook / sheet payloads). */
    private function customMap(object $row): array
    {
        $map = [];
        foreach ($this->customAnswers($row) as $it) {
            $map[$it['l']] = $it['v'];
        }

        return $map;
    }

    /** Send one HTML email with the optional .ics attachment. $to may be a string or an array. */
    private function sendHtmlMail($mf, $to, string $subject, string $html, string $ics, string $icsMethod = 'PUBLISH', ?string $text = null): void
    {
        $m = $mf->createMailer();
        $m->isHtml(true);
        $m->addRecipient($to);
        $m->setSubject($subject);
        $m->setBody($html);
        if ($text !== null) {
            $m->AltBody = $text;                       // the plain-text alternative of the same email
        }
        $fromName = trim((string) $this->params->get('mail_from_name', ''));
        $fromAddr = (string) $this->getApplication()->get('mailfrom', '');
        if ($fromName !== '' && $fromAddr !== '') {
            $m->setSender([$fromAddr, $fromName]);
        }
        if ($ics !== '') {
            $m->addStringAttachment($ics, 'appuntamento.ics', 'base64', 'text/calendar; charset=utf-8; method=' . $icsMethod);
        }
        if ($m->Send() !== true) {
            throw new \RuntimeException('mail not sent');
        }
    }

    /**
     * Calendar UID of the appointment. 0.33.0+: an HMAC of the booking id (the .ics travels by email and into other
     * people's calendars: it must not contain the manage_token, which is a credential). Bookings made before 0.33.0
     * (no fx_state) keep their old UID so their moved/cancelled updates still match the entry already in the calendar.
     */
    private function icsUid(object $row, string $host): string
    {
        if (($row->fx_state ?? null) === null && ($row->payment_status ?? null) === null) {
            return 'wgb-' . $row->id . '-' . $row->manage_token . '@' . $host;
        }

        return 'wgb-' . $row->id . '-' . substr(hash_hmac('sha256', 'uid|' . $row->id, (string) $this->getApplication()->get('secret')), 0, 20) . '@' . $host;
    }

    /**
     * Build an .ics (iCalendar) for the appointment. $method: PUBLISH (new), REQUEST (moved), CANCEL (cancelled).
     * Uses the booking's own duration; a phone appointment says "we call you at X" instead of a link.
     */
    private function buildIcs(object $row, string $method = 'PUBLISH', bool $forGuest = false): string
    {
        try {
            $tz     = new \DateTimeZone($this->siteTz());
            $utc    = new \DateTimeZone('UTC');
            $dur    = $this->rowDuration($row);
            $start  = new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz);
            $end    = (clone $start)->modify('+' . $dur . ' minutes');
            $fmt    = fn(\DateTime $d) => (clone $d)->setTimezone($utc)->format('Ymd\THis\Z');
            $host   = parse_url(Uri::root(), PHP_URL_HOST) ?: 'webg';
            $esc    = fn($s) => str_replace(["\\", "\r", "\n", ',', ';'], ['\\\\', '', '\\n', '\\,', '\\;'], (string) $s);
            $evName = trim((string) ($row->event_name ?? ''));

            $lines = [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//WebG Booking//IT', 'CALSCALE:GREGORIAN', 'METHOD:' . $method,
                'BEGIN:VEVENT',
                'UID:' . $this->icsUid($row, (string) $host),
                'DTSTAMP:' . $fmt(new \DateTime('now', $utc)),
                'DTSTART:' . $fmt($start),
                'DTEND:' . $fmt($end),
                'SUMMARY:' . $esc($evName !== '' ? $evName : Text::_('PLG_SYSTEM_WEBGBOOKING_ICS_SUMMARY')),
            ];

            if ($this->rowLocation($row) === 'phone') {
                $call = $forGuest ? Text::_('PLG_SYSTEM_WEBGBOOKING_ICS_PHONE_GUEST') : Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ICS_PHONE', (string) ($row->customer_phone ?? ''));
                $lines[] = 'DESCRIPTION:' . $esc($call);
                $lines[] = 'LOCATION:' . $esc($call);
            } elseif (!empty($row->meeting_url)) {
                $lines[] = 'DESCRIPTION:' . $esc($row->meeting_url);
                $lines[] = 'LOCATION:' . $esc($row->meeting_url);
                $lines[] = 'URL:' . $esc($row->meeting_url);
            }
            $lines[] = 'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED');
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

            $data    = json_decode(self::httpBody($resp), true) ?: [];
            $refresh = $data['refresh_token'] ?? '';
            $email   = '';

            if (!empty($data['id_token'])) {
                $parts = explode('.', $data['id_token']);
                if (isset($parts[1])) {
                    $payload = json_decode(self::b64d(str_replace(['-', '_'], ['+', '/'], $parts[1])) ?: '', true) ?: [];
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
            $this->wlog(Text::_('PLG_SYSTEM_WEBGBOOKING_LOG_GOOGLE_OAUTH_FAILED'));
            $app->redirect($admin . '&wgberror=exchange');
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

        return 'g:' . self::b64e($iv . $tag . $ct);
    }

    /** Decrypts GCM ('g:' prefix) or legacy CBC values. */
    private function dec(string $stored): string
    {
        $key = $this->encKey();

        if (strncmp($stored, 'g:', 2) === 0) {
            $raw = self::b64d(substr($stored, 2));

            return (string) openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        }

        $raw = self::b64d($stored);

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
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            15
        );

        return (json_decode(self::httpBody($resp), true) ?: [])['access_token'] ?? '';
    }

    /**
     * Create a Google Calendar event. For a Meet appointment it carries an auto-generated Google Meet link;
     * for a phone appointment it has NO conference, the number is in the title and the description.
     * Returns the Meet URL, or '' if Google is not connected / phone / on any failure.
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
            $dur   = $this->rowDuration($row);
            $phone = $this->rowLocation($row) === 'phone';
            $start = $row->booking_date . 'T' . $row->booking_time . ':00';
            $end   = (new \DateTime($start))->modify('+' . $dur . ' minutes')->format('Y-m-d\TH:i:s');

            $attendees = [['email' => $row->customer_email]];
            if (!empty($row->guest_email)) {
                $attendees[] = ['email' => $row->guest_email];
            }

            $evName  = trim((string) ($row->event_name ?? ''));
            $summary = ($evName !== '' ? $evName : 'WebG Booking') . ' - ' . $row->customer_name;
            $desc    = (string) $row->notes;

            if ($phone) {
                $summary .= ' (' . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_GCAL_PHONE_TITLE', $row->customer_phone) . ')';
                $desc     = Text::sprintf('PLG_SYSTEM_WEBGBOOKING_GCAL_PHONE_DESC', $row->customer_name, $row->customer_phone)
                    . ($desc !== '' ? "\n\n" . $desc : '');
            }

            $payload = [
                'summary'     => $summary,
                'description' => $desc,
                'start'       => ['dateTime' => $start, 'timeZone' => $tz],
                'end'         => ['dateTime' => $end, 'timeZone' => $tz],
                'attendees'   => $attendees,
            ];

            if ($phone) {
                $payload['location'] = Text::sprintf('PLG_SYSTEM_WEBGBOOKING_GCAL_PHONE_TITLE', $row->customer_phone);
            } else {
                $payload['conferenceData'] = ['createRequest' => [
                    'requestId'             => 'wgb-' . substr(hash_hmac('sha256', 'meet|' . $row->id, (string) $this->getApplication()->get('secret')), 0, 16),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ]];
            }

            // The event id is chosen by us (base32hex: only 0-9 and a-v, so no "w"; derived from the booking id): inserting it twice answers 409
            // instead of creating a second event, so a retry after a lost answer finds the event that already exists.
            $gid = 'bk' . substr(hash_hmac('sha256', 'gevt|' . $row->id, (string) $this->getApplication()->get('secret')), 0, 40);
            $payload['id'] = $gid;
            $hdr  = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
            $http = (new HttpFactory())->getHttp();
            $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events';

            // sendUpdates=all => Google emails the calendar invite to the customer and the guest (only on the real insert).
            $resp = $http->post($base . '?' . ($phone ? '' : 'conferenceDataVersion=1&') . 'sendUpdates=all', json_encode($payload), $hdr, 15);
            $code = self::httpCode($resp);

            if ($code === 409) {
                // already created by an earlier attempt whose answer we never saw: fetch it
                $resp = $http->get($base . '/' . rawurlencode($gid), ['Authorization' => 'Bearer ' . $token], 15);
                $code = self::httpCode($resp);
            }

            $data = json_decode(self::httpBody($resp), true) ?: [];

            if ($code >= 200 && $code < 300 && !empty($data['id']) && ($data['status'] ?? '') !== 'cancelled') {
                $row->google_event_id = (string) $data['id'];
            } else {
                return '';
            }

            if ($phone) {
                return '';
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
    private function slotConfig(array $ev = []): array
    {
        $ws = explode(':', (string) $this->params->get('work_start', '09:00'));
        $we = explode(':', (string) $this->params->get('work_end', '18:00'));
        $wd = $this->params->get('work_days', [1, 2, 3, 4, 5]);
        $wd = array_values(array_map('intval', \is_array($wd) ? $wd : explode(',', (string) $wd)));

        return [
            'start'  => !empty($ev['ws']) ? ((int) explode(':', $ev['ws'])[0]) * 60 + (int) explode(':', $ev['ws'])[1] : ((int) ($ws[0] ?? 9)) * 60 + (int) ($ws[1] ?? 0),
            'end'    => !empty($ev['we']) ? ((int) explode(':', $ev['we'])[0]) * 60 + (int) explode(':', $ev['we'])[1] : ((int) ($we[0] ?? 18)) * 60 + (int) ($we[1] ?? 0),
            'iv'     => max(5, (int) $this->params->get('slot_interval', 30)),
            'dur'    => isset($ev['dur']) ? max(5, (int) $ev['dur']) : max(5, (int) $this->params->get('slot_duration', 30)),
            'notice' => (isset($ev['nt']) && $ev['nt'] !== null ? (int) $ev['nt'] : (int) $this->params->get('min_notice', 2)) * 3600,
            'window' => isset($ev['wn']) && $ev['wn'] !== null ? (int) $ev['wn'] : max(1, (int) $this->params->get('window_days', 30)),
            'days'   => !empty($ev['wd']) ? array_values($ev['wd']) : ($wd ?: [1, 2, 3, 4, 5]),
            'bufBefore' => (isset($ev['bb']) ? max(0, (int) $ev['bb']) : max(0, (int) $this->params->get('buffer_before', 0))) * 60,
            'bufAfter'  => (isset($ev['ba']) ? max(0, (int) $ev['ba']) : max(0, (int) $this->params->get('buffer_after', 0))) * 60,
        ];
    }

    /** Available "HH:MM" slots for one day, given pre-fetched busy intervals. */
    private function computeDay(string $dayStr, \DateTimeZone $tz, array $busy, int $now, array $c, array $local = []): array
    {
        $slots = [];

        // Working day and booking window are enforced HERE too, so they bind book/reschedule/slots and not only the
        // month view (a hand-made request for a Sunday or for next year used to pass).
        $dayStart = (new \DateTime($dayStr . ' 00:00:00', $tz))->getTimestamp();
        $maxTs    = (new \DateTime('today', $tz))->modify('+' . (int) $c['window'] . ' days')->getTimestamp();
        if ($dayStart > $maxTs || !\in_array((int) (new \DateTime($dayStr . ' 00:00:00', $tz))->format('w'), $c['days'], true)) {
            return [];
        }

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

            // Existing bookings: the padded new slot must clear their raw time, and their own buffers must
            // clear the raw new slot (the gap is the larger of the two buffers, not their sum).
            if (!$conflict) {
                foreach ($local as $b) {
                    if (($winStart < $b[1] && $winEnd > $b[0]) || ($slotStart < $b[1] + $b[3] && $slotEnd > $b[0] - $b[2])) {
                        $conflict = true;
                        break;
                    }
                }
            }

            if (!$conflict) {
                $slots[] = sprintf('%02d:%02d', $hh, $mm);
            }
        }

        return $slots;
    }

    /** Slots for a single day (used as a fallback by the widget). */
    private function availableSlots(string $date, array $ev = []): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->respond(['slots' => []]);
        }

        try {
            $tz   = new \DateTimeZone($this->siteTz());
            $now  = (new \DateTime('now', $tz))->getTimestamp();
            $next = (new \DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
            $busy = $this->googleBusy($date, $next, $tz);
            if ($busy === null) {
                $this->respond(['slots' => [], 'unavailable' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_CAL_UNAVAILABLE')]);
            }

            $this->respond(['slots' => $this->computeDay($date, $tz, $busy, $now, $this->slotConfig($ev), $this->localBookings($date, $next, $tz))]);
        } catch (\Throwable $e) {
            $this->respond(['slots' => []]);
        }
    }

    /**
     * Authoritative month availability: the SINGLE source the calendar uses to
     * decide which days are selectable AND which slots each day offers.
     * Returns { days: { "Y-m-d": ["HH:MM", ...] } } only for days with >=1 slot.
     */
    private function monthSlots(string $from, string $to, array $ev = []): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $this->respond(['days' => (object) []]);
        }

        try {
            $tz    = new \DateTimeZone($this->siteTz());
            $now   = (new \DateTime('now', $tz))->getTimestamp();
            $c     = $this->slotConfig($ev);
            $maxTs = (new \DateTime('today', $tz))->modify('+' . $c['window'] . ' days')->getTimestamp();

            $cur  = new \DateTime($from . ' 00:00:00', $tz);
            $last = new \DateTime($to . ' 00:00:00', $tz);

            // Cap the scanned range to a sane bound regardless of the requested window.
            if ($last->getTimestamp() - $cur->getTimestamp() > 86400 * 62) {
                $last = (clone $cur)->modify('+62 days');
            }

            $busyTo = (clone $last)->modify('+1 day')->format('Y-m-d');
            $busy   = $this->googleBusy($cur->format('Y-m-d'), $busyTo, $tz);
            if ($busy === null) {
                $this->respond(['days' => (object) [], 'unavailable' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_CAL_UNAVAILABLE')]);
            }
            $local  = $this->localBookings($cur->format('Y-m-d'), $busyTo, $tz);

            $days = [];

            while ($cur <= $last) {
                $dts    = $cur->getTimestamp();
                $dayStr = $cur->format('Y-m-d');

                if ($dts + 86400 > $now && $dts <= $maxTs && \in_array((int) $cur->format('w'), $c['days'], true)) {
                    $slots = $this->computeDay($dayStr, $tz, $busy, $now, $c, $local);
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

    // ------------------------------------------------------------------------------------------------
    // Small key/value store (throttles, freeBusy cache, one-time outcome tokens)
    // ------------------------------------------------------------------------------------------------

    private function kvGet(string $k): ?string
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $v  = $db->setQuery(
                $db->getQuery(true)->select($db->quoteName('v'))->from($db->quoteName('#__webgbooking_kv'))->where($db->quoteName('k') . ' = ' . $db->quote($k))
            )->loadResult();

            return $v === null ? null : (string) $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function kvSet(string $k, string $v): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('#__webgbooking_kv') . ' (' . $db->quoteName('k') . ',' . $db->quoteName('v') . ',' . $db->quoteName('updated') . ') VALUES ('
                . $db->quote($k) . ',' . $db->quote($v) . ',' . $db->quote(Factory::getDate()->toSql()) . ') ON DUPLICATE KEY UPDATE '
                . $db->quoteName('v') . ' = VALUES(' . $db->quoteName('v') . '), ' . $db->quoteName('updated') . ' = VALUES(' . $db->quoteName('updated') . ')'
            )->execute();
        } catch (\Throwable $e) {
        }
    }

    private function kvDelete(string $k, bool $prefix = false): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                $db->getQuery(true)->delete($db->quoteName('#__webgbooking_kv'))
                    ->where($prefix ? $db->quoteName('k') . ' LIKE ' . $db->quote($db->escape($k, true) . '%', false) : $db->quoteName('k') . ' = ' . $db->quote($k))
            )->execute();
        } catch (\Throwable $e) {
        }
    }

    /** Log without values: category webgbooking (administrator/logs when Joomla logging is on). */
    private function wlog(string $msg): void
    {
        static $ready = false;
        try {
            if (!$ready) {
                \Joomla\CMS\Log\Log::addLogger(['text_file' => 'webgbooking.php'], \Joomla\CMS\Log\Log::ALL, ['webgbooking']);
                $ready = true;
            }
            \Joomla\CMS\Log\Log::add($msg, \Joomla\CMS\Log\Log::WARNING, 'webgbooking');
        } catch (\Throwable $e) {
        }
    }

    /** Plain email to the staff, at most once every $hours for the same $key (so a failing calendar is one mail, not one per visitor). */
    private function alertStaff(string $key, string $subject, string $body, int $hours = 6, ?object $row = null): void
    {
        try {
            $last = (int) $this->kvGet('alert:' . $key);
            if ($last > 0 && time() - $last < $hours * 3600) {
                return;
            }
            $this->kvSet('alert:' . $key, (string) time());
            $tos = $this->staffRecipients($row);
            if (!$tos) {
                return;
            }
            $P   = 'PLG_SYSTEM_WEBGBOOKING_';
            $mf  = Factory::getContainer()->get(MailerFactoryInterface::class);
            if ($row !== null) {
                $this->rootFrom($row);
            }
            $lay = [
                'title'       => Text::_($P . 'MAIL_TITLE_ALERT'),
                'preheader'   => mb_substr($subject, 0, 120),
                'lead'        => array_values(array_filter(array_map('trim', preg_split('/\n+/', $body) ?: []), static fn($l) => $l !== '')),
                'footer_note' => Text::sprintf($P . 'MAIL_STAFF_FOOTER', (string) ($this->mailBrand()['name'])),
            ];
            if ($row !== null && isset($row->booking_date, $row->booking_time)) {
                $lay['summary']  = ['rows' => $this->mailRows($row, 'staff')];
                $lay['sections'] = $this->mailStaffSections($row);
                $lay['buttons']  = [['label' => Text::_($P . 'MAIL_BTN_BACKEND'), 'url' => $this->mailBackendUrl($row), 'primary' => true]];
            }
            $this->sendLayout($mf, $tos, $subject, $lay);
        } catch (\Throwable $e) {
        }
    }

    private function googleConnected(): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google')))->loadObject();

            return $g && !empty($g->refresh_token);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Google busy intervals over [from, toExclusive) as [startTs, endTs] pairs.
     * [] when Google is not connected. NULL when it IS connected but the answer cannot be trusted (revoked token,
     * timeout, API error): callers must then fail CLOSED (offer nothing) instead of treating the calendar as empty.
     * A short cache (90 s per range) spares the Google quota; $cache=false forces a fresh read (used to confirm a booking).
     */
    private function googleBusy(string $from, string $toExclusive, \DateTimeZone $tz, bool $cache = true): ?array
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return [];
            }

            $reads = json_decode((string) ($g->read_calendars ?? ''), true);
            $reads = \is_array($reads) && $reads ? $reads : [$g->calendar_id ?: 'primary'];
            $ckey  = 'fb:' . md5($from . '|' . $toExclusive . '|' . $tz->getName() . '|' . implode(',', $reads));

            if ($cache) {
                $hit = json_decode((string) $this->kvGet($ckey), true);
                if (\is_array($hit) && isset($hit['t'], $hit['busy']) && time() - (int) $hit['t'] < 90) {
                    return (array) $hit['busy'];
                }
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);
            if ($token === '') {
                return $this->googleBusyFailed('token');
            }

            $min = (new \DateTime($from . ' 00:00:00', $tz))->format(\DateTime::RFC3339);
            $max = (new \DateTime($toExclusive . ' 00:00:00', $tz))->format(\DateTime::RFC3339);

            $resp = (new HttpFactory())->getHttp()->post(
                'https://www.googleapis.com/calendar/v3/freeBusy',
                json_encode([
                    'timeMin' => $min,
                    'timeMax' => $max,
                    'items'   => array_map(fn($id) => ['id' => $id], $reads),
                ]),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                15
            );

            $code = self::httpCode($resp);
            $data = (array) json_decode(self::httpBody($resp), true);
            if ($code < 200 || $code >= 300 || isset($data['error']) || !isset($data['calendars'])) {
                return $this->googleBusyFailed('http' . $code);
            }

            $busy = [];
            foreach ($data['calendars'] as $c) {
                if (!empty($c['errors'])) {
                    return $this->googleBusyFailed('calendar');
                }
                foreach (($c['busy'] ?? []) as $b) {
                    if (isset($b['start'], $b['end'])) {
                        $busy[] = [strtotime($b['start']), strtotime($b['end'])];
                    }
                }
            }

            $this->kvSet($ckey, (string) json_encode(['t' => time(), 'busy' => $busy]));

            return $busy;
        } catch (\Throwable $e) {
            return $this->googleBusyFailed('exception');
        }
    }

    private function googleBusyFailed(string $why): ?array
    {
        $this->wlog(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOG_GOOGLE_BUSY', $why));
        $this->alertStaff('gcal-busy', Text::_('PLG_SYSTEM_WEBGBOOKING_ALERT_GCAL_SUBJECT'), Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ALERT_GCAL_BODY', $why), 6);

        return null;
    }

    /** The freeBusy cache must not outlive a change we made to the calendar. */
    private function purgeBusyCache(): void
    {
        $this->kvDelete('fb:', true);
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
                    ->where($db->quoteName('status') . ' NOT IN (' . $db->quote('cancelled') . ',' . $db->quote('awaiting_payment') . ',' . $db->quote('expired') . ',' . $db->quote('needs_review') . ')')
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

        if ($this->rowLocation($row) === 'phone') {
            $meet = '<tr><td>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_LOCATION')) . '</td><td>' . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOC_PHONE_AT', (string) $row->customer_phone)) . '</td></tr>';
        } else {
            $meet = $row->meeting_url
                ? '<tr><td>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_BTN')) . '</td><td><a href="' . $e($row->meeting_url) . '">' . $e($row->meeting_url) . '</a></td></tr>'
                : '';
        }

        // Reschedule widget: the booking calendar in calendar-only mode, authorised by the token.
        $asset = Uri::root(true) . '/plugins/system/webgbooking/yootheme/elements/booking/assets';
        $ver   = self::ASSET_VER;
        // The manage page is standalone (outside the YOOtheme template), so UIkit isn't present.
        // UIkit is bundled locally (no third-party CDN — privacy + JED compliant + works offline).
        $head  = '<link rel="stylesheet" href="' . $e($asset . '/uikit/css/uikit.min.css?' . $ver) . '">'
            . '<link rel="stylesheet" href="' . $e($asset . '/wgb.css?' . $ver) . '">'
            . '<script src="' . $e($asset . '/uikit/js/uikit.min.js?' . $ver) . '"></script>'
            . '<script src="' . $e($asset . '/uikit/js/uikit-icons.min.js?' . $ver) . '"></script>'
            . '<script src="' . $e($asset . '/wgb.js?' . $ver) . '" defer></script>';

        $cfg = [
            'locale'  => $this->getApplication()->getLanguage()->getTag(),
            'cols'    => 3,
            'mode'    => 'reschedule',
            'token'   => $row->manage_token,
            'ev'      => self::signEv($this->eventFromRow($row), (string) $this->getApplication()->get('secret')),
            'duration' => $this->rowDuration($row),
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
            . ($this->paidLocked($row)
                ? '<hr><p class="muted">' . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAID_LOCKED', max(0, (int) $this->params->get('paid_cancel_hours', 24)))) . '</p>'
                : '<h2>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_RESCHEDULE_TITLE')) . '</h2>'
                . '<div class="wgb-booking" data-wgb="' . $cfgAttr . '"></div>'
                . '<hr>'
                . '<form method="post" action="' . $e($cancelAction) . '">'
                . '<input type="hidden" name="token" value="' . $e($row->manage_token) . '">'
                . '<button type="submit" class="btn btn-danger">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCEL_BTN')) . '</button>'
                . '</form>');

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

        if ($this->paidLocked($row)) {
            $this->htmlPage(
                Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_TITLE'),
                '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_TITLE')) . '</h1><p class="muted">'
                . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAID_LOCKED', max(0, (int) $this->params->get('paid_cancel_hours', 24)))) . '</p>'
            );
        }

        $paid = ($row->payment_status ?? '') === 'paid' && (int) ($row->amount_cents ?? 0) > 0;
        try {
            $this->cancelRow($row, false);
        } catch (\Throwable $ex) {
        }

        $this->htmlPage(
            Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_TITLE'),
            '<h1 class="ok">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_TITLE')) . '</h1>'
            . '<p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_MSG')) . '</p>'
            . ($paid ? '<p class="muted"><strong>' . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_REFUND_CUSTOMER', self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()))) . '</strong></p>' : '')
        );
    }

    /**
     * Cancel one booking from the admin (component list/detail, single or bulk). Does the same thing the
     * customer's own "cancel" does: status, Google event removal, cancellation email to the customer (with an
     * .ics CANCEL so it leaves their calendar). The staff notification is skipped: staff is the one cancelling.
     *
     * @return array{status:string, google:?bool, mail:bool, refund?:bool}  status: ok | already | missing | error
     */
    public function adminCancelBooking(int $id): array
    {
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('id') . ' = ' . (int) $id)
            )->loadObject();

            if (!$row) {
                return ['status' => 'missing', 'google' => null, 'mail' => false];
            }
            if ($row->status === 'cancelled') {
                return ['status' => 'already', 'google' => null, 'mail' => false];
            }

            return $this->cancelRow($row, true);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'google' => null, 'mail' => false];
        }
    }

    /**
     * "Confirm and tell the customer" (admin, manual-confirmation flow): pending -> confirmed, the confirmation email
     * (with the .ics) goes to the customer and the webhook hears booking.confirmed. Idempotent: a second click does nothing.
     * Returns ['status' => ok|already|missing|error, 'mail' => bool].
     */
    public function adminConfirmBooking(int $id): array
    {
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $row = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_bookings'))->where($db->quoteName('id') . ' = ' . (int) $id))->loadObject();
            if (!$row) {
                return ['status' => 'missing', 'mail' => false];
            }
            $db->setQuery(
                $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                    ->set($db->quoteName('status') . ' = ' . $db->quote('confirmed'))
                    ->set($db->quoteName('updated') . ' = ' . $db->quote(Factory::getDate()->toSql()))
                    ->where($db->quoteName('id') . ' = ' . (int) $id)
                    ->where($db->quoteName('status') . ' = ' . $db->quote('pending'))
            )->execute();
            if ((int) $db->getAffectedRows() !== 1) {
                return ['status' => 'already', 'mail' => false];
            }
            $row->status = 'confirmed';
            $this->useSiteLanguage();
            // The element's own customer-email settings, kept on the booking at creation: off stays off, its subject/body apply.
            $cc = json_decode((string) ($row->cust_cfg ?? ''), true);
            $cc = \is_array($cc) ? $cc : [];
            $row->_custOn         = !isset($cc['on']) || (bool) $cc['on'];
            $row->emailSubjectTpl = (string) ($cc['subject'] ?? '');
            $row->emailBodyTpl    = (string) ($cc['body'] ?? '');
            $mail = $row->_custOn ? $this->notify($row, false, 'customer') : 'off';
            $this->fireHook($row, 'booking.confirmed', ['confirmed_by' => 'staff']);

            return ['status' => 'ok', 'mail' => $mail];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'mail' => false];
        }
    }

    /** Shared cancellation: DB status, Google event, email. $byAdmin skips the staff notification. */
    private function cancelRow(object $row, bool $byAdmin): array
    {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $seq = (int) ($row->seq ?? 0) + 1;

        // updateObject() takes the object BY REFERENCE: it must be a variable, not an inline (object) cast.
        $changes = (object) [
            'id'          => $row->id,
            'status'      => 'cancelled',
            'updated'     => Factory::getDate()->toSql(),
            'seq'         => $seq,
            'pending_ctx' => null,
        ];
        $db->updateObject('#__webgbooking_bookings', $changes, 'id', true); // true: pending_ctx NULL is written
        $row->prev_status = $row->status;
        $row->status      = 'cancelled';
        $row->seq         = $seq;

        // A booking that never got paid was never confirmed to the customer: just release the hold.
        if (\in_array($row->prev_status ?? '', ['awaiting_payment', 'expired', 'needs_review'], true) && ($row->payment_status ?? '') !== 'paid') {
            if (($row->prev_status ?? '') === 'awaiting_payment' && !empty($row->stripe_session_id)) {
                $this->stripeExpireSession((string) $row->stripe_session_id, $this->stripeSecretOpt('stripe_sk'));
            }

            return ['status' => 'ok', 'google' => null, 'mail' => false, 'refund' => false];
        }

        $google = null;
        if (!empty($row->google_event_id)) {
            $google = $this->deleteGoogleEvent((string) $row->google_event_id);
        }

        $mail = $this->notifyCancellation($row, $byAdmin);
        $this->fireHook($row, 'booking.cancelled', ['cancelled_by' => $byAdmin ? 'staff' : 'customer']);

        return ['status' => 'ok', 'google' => $google, 'mail' => $mail, 'refund' => ($row->payment_status ?? '') === 'paid'];
    }

    /** Delete the Google Calendar event (and notify its attendees) on cancellation. True when it is gone. */
    private function deleteGoogleEvent(string $eventId): bool
    {
        try {
            if ($eventId === '') {
                return false;
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return false;
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);
            if ($token === '') {
                return false;
            }

            $cal  = $g->calendar_id ?: 'primary';
            $resp = (new HttpFactory())->getHttp()->delete(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode($eventId) . '?sendUpdates=all',
                ['Authorization' => 'Bearer ' . $token],
                15
            );

            $code = self::httpCode($resp);

            // 204/200 = deleted, 404/410 = already gone: both mean the calendar no longer holds it.
            return ($code >= 200 && $code < 300) || $code === 404 || $code === 410;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** HTTP status of a Joomla HTTP response (PSR-7 getStatusCode, or the legacy ->code property). */
    private static function httpCode($resp): int
    {
        if (\is_object($resp) && method_exists($resp, 'getStatusCode')) {
            return (int) $resp->getStatusCode();
        }

        return (int) ($resp->code ?? 0);
    }

    /** Body of a Joomla HTTP response (PSR-7 getBody, or the legacy ->body property). */
    private static function httpBody($resp): string
    {
        if (\is_object($resp) && method_exists($resp, 'getBody')) {
            return (string) $resp->getBody();
        }

        return (string) ($resp->body ?? '');
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

        if ($this->paidLocked($row)) {
            $this->respond(['ok' => false, 'message' => Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAID_LOCKED', max(0, (int) $this->params->get('paid_cancel_hours', 24)))]);
        }

        if ($row->booking_date === $date && $row->booking_time === $time) {
            $this->respond(['ok' => true, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_OK')]);
        }

        // Debounce repeated reschedules (email-bomb / Google-quota protection).
        if (!empty($row->updated) && Factory::getDate($row->updated)->toUnix() > (Factory::getDate()->toUnix() - 60)) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_TOO_SOON')]);
        }

        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $lockName = $this->lockName($db, $date);
        $locked   = false;
        $slotOk   = false;
        $prevDate = (string) $row->booking_date;
        $prevTime = (string) $row->booking_time;

        try {
            $tz   = new \DateTimeZone($this->siteTz());
            $now  = (new \DateTime('now', $tz))->getTimestamp();
            $next = (new \DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
            $busy = $this->googleBusy($date, $next, $tz, false);
            if ($busy === null) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_CAL_UNAVAILABLE')]);
            }
            $ev   = $this->eventFromRow($row);

            // The booking's own existing event must not block its new slot.
            if (!empty($row->google_event_id)) {
                $dur      = $this->rowDuration($row);
                $oldStart = (new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz))->getTimestamp();
                $oldEnd   = $oldStart + $dur * 60;
                $busy     = array_values(array_filter($busy, fn($b) => !($b[0] === $oldStart && $b[1] === $oldEnd)));
            }

            $locked = $this->acquireLock($db, $lockName);
            if (!$locked) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_TAKEN')]);
            }

            try {
                $slots = $this->computeDay($date, $tz, $busy, $now, $this->slotConfig($ev), $this->localBookings($date, $next, $tz, (int) $row->id));
                $slotOk = \in_array($time, $slots, true);

                if ($slotOk) {
                    $seq     = (int) ($row->seq ?? 0) + 1;
                    $newTs   = (new \DateTime($date . ' ' . $time . ':00', $tz))->getTimestamp();
                    $plan    = json_decode((string) ($row->reminder_cfg ?? ''), true);
                    $changes = (object) [
                        'id'           => $row->id,
                        'booking_date' => $date,
                        'booking_time' => $time,
                        'updated'      => Factory::getDate()->toSql(),
                        'seq'          => $seq,
                    ];
                    if (\is_array($plan)) {
                        // The appointment moved: reminders start over for the new time.
                        $changes->reminders_sent = (string) json_encode($this->pastOffsets($plan, $newTs, $now));
                    }
                    $db->updateObject('#__webgbooking_bookings', $changes, 'id');
                    $row->booking_date = $date;
                    $row->booking_time = $time;
                    $row->seq          = $seq;
                }
            } finally {
                $this->releaseLock($db, $lockName);
            }

            if (!$slotOk) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_RESCHEDULE_TAKEN')]);
            }

            if (!empty($row->google_event_id)) {
                $this->patchGoogleEvent($row);
            }

            $row->_prevDate = $prevDate;
            $row->_prevTime = $prevTime;
            $this->notify($row, true);
            $this->fireHook($row, 'booking.rescheduled', ['previous_date' => $prevDate, 'previous_time' => $prevTime]);

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
            $dur   = $this->rowDuration($row);
            $start = $row->booking_date . 'T' . $row->booking_time . ':00';
            $end   = (new \DateTime($start))->modify('+' . $dur . ' minutes')->format('Y-m-d\TH:i:s');

            (new HttpFactory())->getHttp()->patch(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events/' . rawurlencode((string) $row->google_event_id) . '?sendUpdates=all',
                json_encode(['start' => ['dateTime' => $start, 'timeZone' => $tz], 'end' => ['dateTime' => $end, 'timeZone' => $tz]]),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                15
            );
        } catch (\Throwable $e) {
        }
    }

    /**
     * Cancellation emails. The customer always gets an HTML email with an .ics CANCEL (so it disappears from
     * their calendar); staff gets a plain notice only when the customer cancelled by themselves.
     * Returns true when the customer email was sent.
     */
    private function notifyCancellation(object $row, bool $byAdmin = false): bool
    {
        $sent = false;

        try {
            $P   = 'PLG_SYSTEM_WEBGBOOKING_';
            $mf  = Factory::getContainer()->get(MailerFactoryInterface::class);
            $this->rootFrom($row);
            $tos = $this->staffRecipients($row);

            $dateFmt = $row->booking_date;
            try {
                $dateFmt = (new \DateTime($row->booking_date))->format('d/m/Y');
            } catch (\Throwable $e) {
            }

            if (!$byAdmin && $tos) {
                try {
                    $this->sendLayout($mf, $tos, Text::sprintf($P . 'CANCEL_ADMIN_SUBJECT', $this->shortDate((string) $row->booking_date), $row->booking_time), $this->staffMail($row, 'cancelled', $this->manageUrl($row), $dateFmt));
                } catch (\Throwable $e) {
                }
            }

            $notices = [];
            if (!$byAdmin && ($row->payment_status ?? '') === 'paid' && (int) ($row->amount_cents ?? 0) > 0) {
                $notices[] = ['tone' => 'ok', 'text' => Text::sprintf($P . 'CANCEL_REFUND_CUSTOMER', self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()))];
            }
            $back = !empty($row->source_url) && preg_match('#^https?://#i', (string) $row->source_url) ? (string) $row->source_url : '';
            $m = [
                'title'       => Text::_($P . 'MAIL_TITLE_CANCELLED'),
                'preheader'   => Text::sprintf($P . 'MAIL_PRE_APPT', $this->mailDay((string) $row->booking_date), $row->booking_time),
                'lead'        => [Text::_($P . 'EMAIL_HELLO') . ' ' . $row->customer_name . ',', Text::sprintf($byAdmin ? $P . 'CANCEL_BY_STAFF_BODY' : $P . 'CANCEL_CUSTOMER_LINE', $dateFmt, $row->booking_time)],
                'notices'     => $notices,
                'summary'     => ['rows' => $this->mailRows($row, 'customer')],
                'buttons'     => $back !== '' ? [['label' => Text::_($P . 'MAIL_BTN_REBOOK'), 'url' => $back, 'primary' => true]] : [],
                'signoff'     => Text::_($P . 'EMAIL_SIGNOFF'),
                'footer_note' => Text::_($P . 'EMAIL_FOOTER'),
            ];
            $this->sendLayout($mf, $row->customer_email, Text::_($P . 'CANCEL_CUSTOMER_SUBJECT'), $m, $this->buildIcs($row, 'CANCEL'), 'CANCEL');
            $sent = true;
        } catch (\Throwable $e) {
        }

        return $sent;
    }

    // ------------------------------------------------------------------------------------------------
    // Reminders (called by the Task Scheduler plugin plg_task_webgbookingreminders)
    // ------------------------------------------------------------------------------------------------

    /** Make the plugin strings follow the SITE default language (the scheduler may run in a CLI/other context). */
    private function useSiteLanguage(): void
    {
        try {
            $app = $this->getApplication();
            $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            $cur = $app->getLanguage();

            if ($cur->getTag() !== $tag) {
                $cur = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag, false);
                if (method_exists($app, 'loadLanguage')) {
                    $app->loadLanguage($cur);
                } elseif (method_exists($app, 'setLanguage')) {
                    $app->setLanguage($cur);
                }
                Factory::$language = $cur;
            }

            $cur->load('plg_system_webgbooking', JPATH_ADMINISTRATOR, null, true);
            $cur->load('plg_system_webgbooking', JPATH_PLUGINS . '/system/webgbooking', null, true);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Send the due reminder emails. Idempotent and safe to call from several processes at once:
     * each reminder is claimed with an atomic compare-and-set on `reminders_sent` BEFORE sending, so one
     * booking never gets the same reminder twice; if sending fails the claim is released for the next run.
     * Cancelled/completed/past bookings are never reminded.
     *
     * @return array{checked:int, sent:int, failed:int}
     */
    public function runReminders(int $limit = 300): array
    {
        $res = ['checked' => 0, 'sent' => 0, 'failed' => 0];

        try {
            if (!(bool) $this->params->get('reminders_enabled', 1)) {
                return $res;
            }

            $this->useSiteLanguage();

            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $tz  = new \DateTimeZone($this->siteTz());
            $now = (new \DateTime('now', $tz))->getTimestamp();
            $lo  = (new \DateTime('today', $tz))->format('Y-m-d');
            $hi  = (new \DateTime('today', $tz))->modify('+15 days')->format('Y-m-d');

            $rows = $db->setQuery(
                $db->getQuery(true)
                    ->select('*')
                    ->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('status') . ' IN (' . $db->quote('pending') . ',' . $db->quote('confirmed') . ')')
                    ->where($db->quoteName('booking_date') . ' >= ' . $db->quote($lo))
                    ->where($db->quoteName('booking_date') . ' <= ' . $db->quote($hi))
                    ->where($db->quoteName('reminder_cfg') . ' IS NOT NULL')
                    ->where($db->quoteName('reminder_cfg') . ' != ' . $db->quote(''))
                    ->order($db->quoteName('booking_date') . ' ASC')
                    ->order($db->quoteName('booking_time') . ' ASC'),
                0,
                $limit
            )->loadObjectList();

            foreach ($rows ?: [] as $row) {
                $res['checked']++;
                $plan = json_decode((string) $row->reminder_cfg, true);
                if (!\is_array($plan) || empty($plan['on']) || empty($plan['times'])) {
                    continue;
                }

                $start = (new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz))->getTimestamp();
                if ($start <= $now) {
                    continue; // already started or past
                }

                $sent = json_decode((string) ($row->reminders_sent ?? ''), true);
                $sent = \is_array($sent) ? array_map('intval', $sent) : [];
                $due  = [];
                foreach ($plan['times'] as $m) {
                    $m = (int) $m;
                    if ($m > 0 && !\in_array($m, $sent, true) && $start - $m * 60 <= $now) {
                        $due[] = $m;
                    }
                }
                if (!$due) {
                    continue;
                }

                // Several due at once (scheduler was off): one email, all of them are marked as done.
                $oldJson = (string) ($row->reminders_sent ?? '');
                $newJson = (string) json_encode(array_values(array_unique(array_merge($sent, $due))));

                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName('#__webgbooking_bookings'))
                        ->set($db->quoteName('reminders_sent') . ' = ' . $db->quote($newJson))
                        ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                        ->where('COALESCE(' . $db->quoteName('reminders_sent') . ", '') = " . $db->quote($oldJson))
                        ->where($db->quoteName('status') . ' IN (' . $db->quote('pending') . ',' . $db->quote('confirmed') . ')')
                )->execute();

                if ((int) $db->getAffectedRows() !== 1) {
                    continue; // another process claimed it, or it was cancelled meanwhile
                }

                if ($this->sendReminder($row, $plan, min($due))) {
                    $res['sent']++;
                } else {
                    $res['failed']++;
                    // Release the claim so the next run retries (only while the appointment is still ahead).
                    $db->setQuery(
                        $db->getQuery(true)
                            ->update($db->quoteName('#__webgbooking_bookings'))
                            ->set($db->quoteName('reminders_sent') . ' = ' . $db->quote($oldJson))
                            ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                            ->where($db->quoteName('reminders_sent') . ' = ' . $db->quote($newJson))
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
        }

        return $res;
    }

    /** Build and send one reminder email to the customer. */
    private function sendReminder(object $row, array $plan, int $offsetMin): bool
    {
        try {
            $mf        = Factory::getContainer()->get(MailerFactoryInterface::class);
            $this->rootFrom($row);
            if (!empty($plan['root'])) {
                $this->rootHint = (string) $plan['root'];
            }
            $manageUrl = $this->manageUrl($row, (string) ($plan['root'] ?? ''));
            $dateFmt   = (new \DateTime($row->booking_date))->format('d/m/Y');
            $tags      = ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time];

            $subjectTpl = (string) ($plan['subject'] ?? '');
            if ($subjectTpl === '') {
                $subjectTpl = trim((string) $this->params->get('reminder_subject', '')) ?: Text::_('PLG_SYSTEM_WEBGBOOKING_REMINDER_SUBJECT');
            }
            $bodyTpl = (string) ($plan['body'] ?? '');
            if ($bodyTpl === '') {
                $bodyTpl = trim((string) $this->params->get('reminder_body', ''));
            }

            // No .ics on a reminder: the appointment is already in their calendar from the confirmation.
            $this->sendLayout($mf, $row->customer_email, self::fillTpl($subjectTpl, $tags), $this->customerMail($row, 'reminder', $manageUrl, $dateFmt, $bodyTpl));

            // The invited guest gets a reminder too: no management link, no phone of the customer.
            if (!empty($row->guest_email)) {
                try {
                    $this->sendLayout($mf, $row->guest_email, self::fillTpl($subjectTpl, $tags), $this->customerMail($row, 'guest_reminder', '', $dateFmt));
                } catch (\Throwable $e) {
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
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

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
            header('X-Content-Type-Options: nosniff');
        }

        echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>' . $css . '</style>' . $head . '</head>'
            . '<body><div class="wrap"><div class="card">' . $body . '</div></div></body></html>';

        $this->getApplication()->close();
    }

    /** No caching, no referrer leak, no indexing: for answers and pages that carry booking tokens. */
    private function privacyHeaders($app = null): void
    {
        $app = $app ?: $this->getApplication();
        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $app->setHeader('Pragma', 'no-cache', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
    }

    private function respond(array $data): void
    {
        echo json_encode($data);
        $this->getApplication()->close();
    }
}
