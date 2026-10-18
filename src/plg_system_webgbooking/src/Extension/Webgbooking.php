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

final class Webgbooking extends CMSPlugin implements SubscriberInterface
{
    /** Cache-buster for the bundled front-end assets (wgb.css/js, UIkit). Bump when those change. */
    public const ASSET_VER = '0.32.0';

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => ['onAfterInitialise', Priority::LOW],
            'onAjaxWebgbooking' => 'onAjax',
            'onExtensionBeforeSave' => 'onExtensionBeforeSave',
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

            $customData = $this->collectCustomFields($actions['fields'] ?? [], $input);
            if ($customData === false) {
                $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
            }

            $vtz   = new \DateTimeZone($this->siteTz());
            $vnow  = (new \DateTime('now', $vtz))->getTimestamp();
            $vnext = (new \DateTime($date . ' 00:00:00', $vtz))->modify('+1 day')->format('Y-m-d');
            $cfg   = $this->slotConfig($ev);
            $busy  = $this->googleBusy($date, $vnext, $vtz); // network call BEFORE taking the lock

            $price  = (array) ($actions['price'] ?? []);
            $priced = (int) ($price['amt'] ?? 0) > 0;
            $sk     = '';
            if ($priced) {
                $sk = $this->stripeSecretOpt('stripe_sk');
                if ($sk === '') {
                    // Paid event but Stripe is not configured: never take a booking we cannot charge for.
                    $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_UNAVAILABLE')]);
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
                        'status'          => 'pending',
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
                    ];
                    if ($priced) {
                        // Slot is HELD (not confirmed) until Stripe says paid: nothing is sent or created yet.
                        $row->status         = 'awaiting_payment';
                        $row->payment_status = 'pending';
                        $row->amount_cents   = (int) $price['amt'];
                        $row->currency       = strtoupper(substr((string) ($price['cur'] ?? 'EUR'), 0, 3));
                        $row->hold_until     = Factory::getDate('+' . $this->holdMinutes() . ' minutes')->toSql();
                        $row->pending_ctx    = (string) json_encode([
                            'actions'    => $actions,
                            'newsletter' => $input->post->getInt('newsletter', 0) === 1 ? 1 : 0,
                            'redirect'   => (string) ($actions['redirect'] ?? ''),
                        ]);
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
                            ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                            ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                            ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                    )->execute();
                    $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_UNAVAILABLE')]);
                }
                $upd = (object) ['id' => $row->id, 'stripe_session_id' => (string) $session['id']];
                $db->updateObject('#__webgbooking_bookings', $upd, 'id');

                $this->respond(['ok' => true, 'redirect' => (string) $session['url'], 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REDIRECTING')]);
            }

            // Free event: Google event (+ Meet link unless phone), emails, newsletter, webhook, Sheet.
            $this->completeBooking($row, $actions, $input->post->getInt('newsletter', 0) === 1);

            $this->respond([
                'ok'      => true,
                'message' => Text::_($loc === 'phone' ? 'PLG_SYSTEM_WEBGBOOKING_BOOK_OK_PHONE' : 'PLG_SYSTEM_WEBGBOOKING_BOOK_OK'),
            ]);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'message' => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR')]);
        }
    }

    // ------------------------------------------------------------------------------------------------
    // Event type (duration / buffers / location), per element
    // ------------------------------------------------------------------------------------------------

    /** Sign the (small) event config so GET endpoints (month/slots) can trust it without the full form token. */
    public static function signEv(array $ev, string $secret): string
    {
        $payload = base64_encode((string) json_encode($ev));

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
                    $cfg = json_decode((string) base64_decode($payload), true);
                    if (\is_array($cfg)) {
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

        return [
            'dur'  => max(5, min(480, $dur)),
            'bb'   => max(0, min(240, $bb)),
            'ba'   => max(0, min(240, $ba)),
            'loc'  => \in_array($loc, ['meet', 'phone', 'choice'], true) ? $loc : 'meet',
            'name' => mb_substr(trim(strip_tags((string) ($e['name'] ?? ''))), 0, 120),
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
        ]);
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

    /** "49", "49,00", "1.234,50", "49.9" -> cents (0 when empty/invalid/too large). */
    public static function parseAmount(string $text): int
    {
        $t = preg_replace('/[^\d.,]/', '', trim($text));
        if ($t === '' || $t === null) {
            return 0;
        }
        $lastC = strrpos($t, ',');
        $lastD = strrpos($t, '.');
        $sep   = $lastC === false ? $lastD : ($lastD === false ? $lastC : max($lastC, $lastD));
        if ($sep !== false && \strlen($t) - $sep - 1 <= 2) {
            $int = preg_replace('/[.,]/', '', substr($t, 0, $sep));
            $dec = substr($t, $sep + 1);
        } else {
            $int = preg_replace('/[.,]/', '', $t);
            $dec = '';
        }
        $cents = ((int) $int) * 100 + (int) str_pad($dec, 2, '0');

        return ($cents > 0 && $cents <= 99999999) ? $cents : 0;
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

    /** Stripe secret key of the plugin options (stored AES-256-GCM encrypted), or ''. */
    private function stripeSecretOpt(string $name): string
    {
        $v = trim((string) $this->params->get($name, ''));

        return strncmp($v, 'g:', 2) === 0 ? $this->dec($v) : $v;
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

        // Stripe wants expires_at between 30 minutes and 24 hours from now.
        $expires = max($holdEnd + 90, time() + 31 * 60);

        [$code, $s] = $this->stripeCall('POST', '/v1/checkout/sessions', [
            'mode'                => 'payment',
            'client_reference_id' => (string) $row->id,
            'customer_email'      => (string) $row->customer_email,
            'success_url'         => $base . '&action=payreturn&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'          => $base . '&action=paycancel',
            'expires_at'          => $expires,
            'line_items'          => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower((string) $row->currency),
                    'unit_amount'  => (int) $row->amount_cents,
                    'product_data' => ['name' => $name, 'description' => $when . ' - ' . (int) $this->rowDuration($row) . ' min'],
                ],
            ]],
            'payment_intent_data' => ['receipt_email' => (string) $row->customer_email, 'description' => $name . ' ' . $when],
            'metadata'            => ['booking_id' => (string) $row->id],
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

    /** Check a key against Stripe (GET /v1/account). ['ok'=>bool, 'account'=>string, 'code'=>int] */
    public function checkStripeKey(string $sk): array
    {
        [$code, $s] = $this->stripeCall('GET', '/v1/account', [], $sk);

        return ['ok' => $code === 200, 'account' => (string) ($s['id'] ?? ''), 'code' => $code];
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

    /** Every side effect of a confirmed booking (Google event/Meet, emails, newsletter, webhook, Sheet). */
    private function completeBooking(object $row, array $actions, bool $newsletter): void
    {
        $meet = $this->createGoogleEvent($row);
        if ($meet !== '') {
            $row->meeting_url = $meet;
        }
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $upd = (object) [
            'id'              => $row->id,
            'meeting_url'     => $row->meeting_url,
            'google_event_id' => (string) ($row->google_event_id ?? ''),
        ];
        $db->updateObject('#__webgbooking_bookings', $upd, 'id');

        $cust  = $actions['customer'] ?? [];
        $staff = $actions['staff'] ?? [];
        $row->_custOn         = !isset($cust['on']) || (bool) $cust['on'];
        $row->emailSubjectTpl = (string) ($cust['subject'] ?? '');
        $row->emailBodyTpl    = (string) ($cust['body'] ?? '');
        $row->_staffOn        = !isset($staff['on']) || (bool) $staff['on'];
        $row->_staffTo        = (string) ($staff['to'] ?? '');
        $row->_staffSubject   = (string) ($staff['subject'] ?? '');
        $row->_staffBody      = (string) ($staff['body'] ?? '');

        $this->notify($row);

        if ($newsletter) {
            $this->newsletterSignup($row);
        }

        $wh       = $actions['webhook'] ?? [];
        $whUrl    = (!empty($wh['on']) && !empty($wh['url'])) ? (string) $wh['url'] : '';
        $whMethod = !empty($wh['on']) ? (string) ($wh['method'] ?? '') : '';
        $this->postBookingWebhook($row, $whUrl, $whMethod);

        $this->appendToSheet($row);
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

    /**
     * A paid Checkout Session reached us (webhook or return page). Idempotent: only the transition from
     * awaiting_payment/expired to paid has effects, a second call returns 'already'.
     * If the hold had lapsed the slot is re-checked: still free -> confirmed anyway, taken -> needs_review + staff alert.
     *
     * @return string confirmed | review | already | mismatch
     */
    private function finalizePaid(object $row, array $session): string
    {
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $pi   = (string) ($session['payment_intent'] ?? '');
        $now  = Factory::getDate()->toSql();
        $tz   = new \DateTimeZone($this->siteTz());
        $nowT = (new \DateTime('now', $tz))->getTimestamp();

        if (!\in_array($row->status, ['awaiting_payment', 'expired'], true) || ($row->payment_status ?? '') === 'paid') {
            return 'already';
        }

        $amountOk = (int) ($session['amount_total'] ?? -1) === (int) $row->amount_cents
            && strtolower((string) ($session['currency'] ?? '')) === strtolower((string) $row->currency);

        $startTs = (new \DateTime($row->booking_date . ' ' . $row->booking_time . ':00', $tz))->getTimestamp();
        $holdLapsed = $row->status === 'expired' || (!empty($row->hold_until) && Factory::getDate($row->hold_until)->toUnix() < time());
        $outcome = 'confirmed';

        $lockName = $this->lockName($db, $row->booking_date);
        $this->acquireLock($db, $lockName);
        try {
            if (!$amountOk || $startTs <= $nowT) {
                $outcome = 'review';
            } elseif ($holdLapsed) {
                $next = (new \DateTime($row->booking_date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
                $cfg  = $this->slotConfig($this->eventFromRow($row));
                $cfg['notice'] = 0; // only conflicts matter here, the customer already paid for this slot
                $busy  = $this->googleBusy($row->booking_date, $next, $tz);
                $slots = $this->computeDay($row->booking_date, $tz, $busy, 0, $cfg, $this->localBookings($row->booking_date, $next, $tz, (int) $row->id));
                if (!\in_array($row->booking_time, $slots, true)) {
                    $outcome = 'review';
                }
            }

            $plan  = json_decode((string) ($row->reminder_cfg ?? ''), true);
            $set   = [
                $db->quoteName('status') . ' = ' . $db->quote($outcome === 'confirmed' ? 'confirmed' : 'needs_review'),
                $db->quoteName('payment_status') . ' = ' . $db->quote('paid'),
                $db->quoteName('paid_at') . ' = ' . $db->quote($now),
                $db->quoteName('updated') . ' = ' . $db->quote($now),
                $db->quoteName('stripe_payment_intent') . ' = ' . $db->quote($pi),
            ];
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
        $row->hold_until     = $row->hold_until ?? null;

        if ($outcome === 'review') {
            $this->notifyPaymentReview($row, $amountOk ? 'slot' : 'amount');

            return $amountOk ? 'review' : 'mismatch';
        }

        $ctx = json_decode((string) ($row->pending_ctx ?? ''), true);
        $ctx = \is_array($ctx) ? $ctx : [];
        $this->useSiteLanguage();
        $this->completeBooking($row, (array) ($ctx['actions'] ?? []), !empty($ctx['newsletter']));

        return 'confirmed';
    }

    /** Staff alert: money received but the booking cannot stand (slot taken / amount differs). Refund by hand. */
    private function notifyPaymentReview(object $row, string $why): void
    {
        try {
            $admin = trim((string) $this->params->get('notify_email', '')) ?: (string) $this->getApplication()->getConfig()->get('mailfrom');
            if ($admin === '') {
                return;
            }
            $mf = Factory::getContainer()->get(MailerFactoryInterface::class);
            $m  = $mf->createMailer();
            $m->addRecipient($admin);
            $m->setSubject(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_SUBJECT', $row->booking_date, $row->booking_time));
            $m->setBody(Text::sprintf(
                $why === 'amount' ? 'PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_BODY_AMOUNT' : 'PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_BODY_SLOT',
                $row->booking_date,
                $row->booking_time,
                $row->customer_name,
                $row->customer_email,
                self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()),
                (string) ($row->stripe_payment_intent ?: $row->stripe_session_id),
                (int) $row->id
            ));
            $m->Send();
        } catch (\Throwable $e) {
        }
    }

    /** Hold lapsed without payment: free the slot and close the Stripe session. Returns how many were expired. */
    public function expireHolds(int $limit = 200): int
    {
        $n = 0;
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
                        ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                        ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                        ->set($db->quoteName('updated') . ' = ' . $db->quote($now))
                        ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                        ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
                )->execute();
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
        if (isset($ev['livemode']) && (bool) $ev['livemode'] !== $this->stripeLive()) {
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
                } elseif ($row) {
                    $result = 'unpaid';
                }
            } elseif (\in_array($ev['type'], ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
                $row = !empty($obj['id']) ? $this->bookingBySession((string) $obj['id']) : null;
                if ($row && $row->status === 'awaiting_payment') {
                    $now = Factory::getDate()->toSql();
                    $db->setQuery(
                        $db->getQuery(true)->update($db->quoteName('#__webgbooking_bookings'))
                            ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                            ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                            ->set($db->quoteName('updated') . ' = ' . $db->quote($now))
                            ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                            ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
                    )->execute();
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

        $ctx = json_decode((string) ($row->pending_ctx ?? ''), true);
        if (($row->payment_status ?? '') === 'paid' && $row->status !== 'needs_review') {
            $url = (string) ($ctx['redirect'] ?? '');
            if ($url !== '' && preg_match('#^(https?://[^\s]+|/(?!/)[^\s]*)$#i', $url)) {
                $this->getApplication()->redirect($url);
                return;
            }
            $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_OK_TITLE'), '<h1 class="ok">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_OK_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_OK_MSG')) . '</p>');
        }
        if (($row->payment_status ?? '') === 'paid') {
            $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REVIEW_MSG')) . '</p>');
        }

        $this->htmlPage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_PENDING_TITLE'), '<h1>' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_PENDING_TITLE')) . '</h1><p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_PENDING_MSG')) . '</p>');
    }

    /** Cancel URL: the customer backed out of Stripe. Frees the slot (unless Stripe says it was paid meanwhile). */
    private function payCancel(string $token): void
    {
        $row = $this->bookingByTokenAny($token);
        $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $sk  = $this->stripeSecretOpt('stripe_sk');

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
                    ->set($db->quoteName('status') . ' = ' . $db->quote('expired'))
                    ->set($db->quoteName('payment_status') . ' = ' . $db->quote('expired'))
                    ->set($db->quoteName('updated') . ' = ' . $db->quote($now))
                    ->where($db->quoteName('id') . ' = ' . (int) $row->id)
                    ->where($db->quoteName('status') . ' = ' . $db->quote('awaiting_payment'))
            )->execute();
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
     * Plugin options save: Stripe secrets are encrypted at rest (same AES-256-GCM scheme as the Google token).
     * An empty field keeps the stored value, "-" clears it. A new secret key is checked against Stripe.
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

            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $old = (array) json_decode((string) $db->setQuery(
                $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($db->quoteName('extension_id') . ' = ' . (int) ($table->extension_id ?? 0))
            )->loadResult(), true);

            $freshSk = '';
            foreach (['stripe_sk', 'stripe_whsec'] as $k) {
                $v = trim((string) ($new[$k] ?? ''));
                if ($v === '') {
                    $new[$k] = (string) ($old[$k] ?? '');
                } elseif ($v === '-') {
                    $new[$k] = '';
                } elseif (strncmp($v, 'g:', 2) !== 0) {
                    $new[$k] = $this->enc($v);
                    if ($k === 'stripe_sk') {
                        $freshSk = $v;
                    }
                }
            }
            $table->params = (string) json_encode($new);

            if ($freshSk !== '') {
                $app  = $this->getApplication();
                $mode = (string) ($new['stripe_mode'] ?? 'test');
                $isTestKey = (bool) preg_match('/^(sk|rk)_test_/', $freshSk);
                $isLiveKey = (bool) preg_match('/^(sk|rk)_live_/', $freshSk);
                if (($mode === 'live' && $isTestKey) || ($mode !== 'live' && $isLiveKey)) {
                    $app->enqueueMessage(Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_MODE_MISMATCH'), 'warning');
                }
                $chk = $this->checkStripeKey($freshSk);
                if ($chk['ok']) {
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_OK', $chk['account']), 'message');
                } else {
                    $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_PAY_KEY_BAD', (int) $chk['code']), 'warning');
                }
            }
        } catch (\Throwable $e) {
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

    /** Forward the full booking to a webhook (per-element override → plugin global), POST or GET. */
    private function postBookingWebhook(object $row, string $overrideUrl = '', string $method = ''): void
    {
        try {
            $url = $overrideUrl !== '' ? $overrideUrl : trim((string) $this->params->get('booking_webhook', ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                return;
            }

            $method = strtoupper($method !== '' ? $method : (string) $this->params->get('booking_webhook_method', 'POST'));
            $data   = [
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
                'location'    => $this->rowLocation($row),
                'duration'    => $this->rowDuration($row),
                'event'       => (string) ($row->event_name ?? ''),
                'custom'      => $this->customMap($row),
            ];

            $http = (new HttpFactory())->getHttp();
            if ($method === 'GET') {
                $sep = strpos($url, '?') === false ? '?' : '&';
                $http->get($url . $sep . http_build_query($data), [], 8);
            } else {
                $http->post($url, json_encode($data), ['Content-Type' => 'application/json'], 8);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Append the booking as a row to a configured Google Sheet (live spreadsheet write). */
    private function appendToSheet(object $row): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $g  = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            if (!$g || empty($g->refresh_token)) {
                return;
            }

            // Sheet chosen in the component (Google page) → fallback to the plugin param.
            $sheetId = trim((string) ($g->sheet_id ?? '')) ?: trim((string) $this->params->get('google_sheet_id', ''));
            if ($sheetId === '') {
                return;
            }

            $token = $this->googleAccessToken((string) $g->refresh_token);
            if ($token === '') {
                return;
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

            (new HttpFactory())->getHttp()->post(
                'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId) . '/values/' . $range . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
                json_encode(['values' => $values]),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                8
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
                ['Content-Type' => 'application/json'],
                8
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
                $this->sendHtmlMail($mf, $row->customer_email, $subject, $this->wrapEmail($this->renderTpl($bodyTpl, $row, $manageUrl, $dateFmt, !$reschedule)), $ics);

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
                        $meetLine  = $this->rowLocation($row) === 'phone'
                            ? ' ' . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_STAFF_PHONE_LINE', (string) $row->customer_phone, $this->rowDuration($row))
                            : (!empty($row->meeting_url) ? ' ' . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_MEET_LINE', $row->meeting_url) : '');
                        $guestLine = !empty($row->guest_email) ? "\n" . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_GUEST_LINE', $row->guest_email) : '';
                        if (($row->payment_status ?? '') === 'paid') {
                            $guestLine .= "\n" . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_STAFF_PAID_LINE', self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()), (string) ($row->stripe_payment_intent ?? ''));
                        }
                        $custLines = '';
                        foreach ($this->customAnswers($row) as $it) {
                            $custLines .= "\n" . $it['l'] . ': ' . $it['v'];
                        }
                        $m = $mf->createMailer();
                        $m->addRecipient($tos);
                        $m->setSubject($sSub);
                        $m->setBody(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_ADMIN_BODY', $row->booking_date, $row->booking_time, $row->customer_name, $row->customer_email, $row->customer_phone ?: '-', $row->notes ?: '-') . $meetLine . $guestLine . $custLines);
                        $m->Send();
                    }
                }
            }
        } catch (\Throwable $e) {
            // Email is best-effort: the booking is already stored.
        }
    }

    /** Self-service management URL (cancel / future reschedule), authorised by the per-booking token. */
    private function manageUrl(object $row, string $root = ''): string
    {
        if ($root === '') {
            try {
                $root = Uri::root();
            } catch (\Throwable $e) {
                $root = '';
            }
        }

        return $root . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' . rawurlencode((string) $row->manage_token);
    }

    /** Replace placeholders in a (default or admin-edited) HTML email template. */
    private function renderTpl(string $tpl, object $row, string $manageUrl, string $dateFmt, bool $withPayment = true): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        // Paid events: "Paid 49,00 EUR" (the receipt itself is Stripe's, sent to the customer's email).
        $amount = '';
        $payBlock = '';
        if (($row->payment_status ?? '') === 'paid' && (int) ($row->amount_cents ?? 0) > 0) {
            $amount = self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag());
            if ($withPayment) {
                $payBlock = '<p style="margin:16px 0 0;padding:10px 14px;background:#eef8f1;border:1px solid #cfe9d8;border-radius:8px;font-weight:600;color:#1d6b3f;">'
                    . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_PAID', $amount)) . '</p>';
            }
        }

        $isPhone   = $this->rowLocation($row) === 'phone';
        $meetBlock = '';
        if ($isPhone) {
            // Phone appointment: no link, we say we will call the customer at the number they gave.
            $meetBlock = '<p style="margin:20px 0;padding:12px 16px;background:#f6f8fa;border:1px solid #e6e9ee;border-radius:8px;font-weight:600;">'
                . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_EMAIL_PHONE_CALL', (string) $row->customer_phone)) . '</p>';
        } elseif (!empty($row->meeting_url)) {
            $meetBlock = '<p style="margin:20px 0;"><a href="' . $e($row->meeting_url) . '" style="display:inline-block;background:#1f2937;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;">'
                . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_BTN')) . '</a></p>';
        }
        $locLabel = $isPhone
            ? Text::sprintf('PLG_SYSTEM_WEBGBOOKING_LOC_PHONE_AT', (string) $row->customer_phone)
            : Text::_('PLG_SYSTEM_WEBGBOOKING_LOC_MEET');
        $cancelBlock = '<p style="margin:18px 0 0;font-size:13px;color:#6a6a6a;"><a href="' . $e($manageUrl) . '" style="color:#1f2937;">'
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
            '{location_block}' => $meetBlock,
            '{cancel_block}' => $cancelBlock,
            '{custom}'       => $customBlock,
            '{location}'     => $e($locLabel),
            '{duration}'     => $e((string) $this->rowDuration($row)),
            '{event}'        => $e((string) ($row->event_name ?? '')),
            '{amount}'       => $e($amount),
            '{payment_block}' => $payBlock,
        ]);
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
            . '{payment_block}'
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
    private function sendHtmlMail($mf, $to, string $subject, string $html, string $ics, string $icsMethod = 'PUBLISH'): void
    {
        $m = $mf->createMailer();
        $m->isHtml(true);
        $m->addRecipient($to);
        $m->setSubject($subject);
        $m->setBody($html);
        if ($ics !== '') {
            $m->addStringAttachment($ics, 'appuntamento.ics', 'base64', 'text/calendar; charset=utf-8; method=' . $icsMethod);
        }
        $m->Send();
    }

    /** Guest-facing email body: an invitation, without any management/cancel link. */
    private function guestEmailBody(object $row, string $dateFmt, string $introKey = 'PLG_SYSTEM_WEBGBOOKING_EMAIL_GUEST_INTRO'): string
    {
        $t    = fn($k) => htmlspecialchars(Text::_($k), ENT_QUOTES, 'UTF-8');
        $e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $cell = 'padding:6px 0;font-size:14px;';

        $meetBlock = '';
        if ($this->rowLocation($row) === 'phone') {
            $meetBlock = '<p style="margin:20px 0;font-weight:600;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_PHONE_GUEST') . '</p>';
        } elseif (!empty($row->meeting_url)) {
            $meetBlock = '<p style="margin:20px 0;"><a href="' . $e($row->meeting_url) . '" style="display:inline-block;background:#1f2937;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;">'
                . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_MEET_BTN') . '</a></p>';
        }

        return '<p style="margin:0 0 14px;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_HELLO') . ',</p>'
            . '<p style="margin:0 0 18px;">' . htmlspecialchars(Text::sprintf($introKey, $row->customer_name), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e6e9ee;border-radius:8px;">'
            . '<tr><td style="' . $cell . 'padding-left:14px;color:#6a6a6a;width:90px;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_DATE') . '</td><td style="' . $cell . 'font-weight:600;">' . $e($dateFmt) . '</td></tr>'
            . '<tr><td style="' . $cell . 'padding-left:14px;color:#6a6a6a;border-top:1px solid #eef1f4;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_LABEL_TIME') . '</td><td style="' . $cell . 'font-weight:600;border-top:1px solid #eef1f4;">' . $e($row->booking_time) . '</td></tr>'
            . '</table>'
            . $meetBlock
            . '<p style="margin:18px 0 0;font-size:13px;color:#6a6a6a;">' . $t('PLG_SYSTEM_WEBGBOOKING_EMAIL_ICS_HINT') . '</p>';
    }

    /**
     * Build an .ics (iCalendar) for the appointment. $method: PUBLISH (new), REQUEST (moved), CANCEL (cancelled).
     * Uses the booking's own duration; a phone appointment says "we call you at X" instead of a link.
     */
    private function buildIcs(object $row, string $method = 'PUBLISH'): string
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
                'UID:wgb-' . $row->id . '-' . $row->manage_token . '@' . $host,
                'DTSTAMP:' . $fmt(new \DateTime('now', $utc)),
                'DTSTART:' . $fmt($start),
                'DTEND:' . $fmt($end),
                'SUMMARY:' . $esc($evName !== '' ? $evName : Text::_('PLG_SYSTEM_WEBGBOOKING_ICS_SUMMARY')),
            ];

            if ($this->rowLocation($row) === 'phone') {
                $call = Text::sprintf('PLG_SYSTEM_WEBGBOOKING_ICS_PHONE', (string) ($row->customer_phone ?? ''));
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

        return json_decode(self::httpBody($resp), true)['access_token'] ?? '';
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
                    'requestId'             => 'wgb-' . bin2hex(random_bytes(6)),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ]];
            }

            // sendUpdates=all => Google emails the calendar invite to the customer and the guest.
            $resp = (new HttpFactory())->getHttp()->post(
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . ($phone ? '' : 'conferenceDataVersion=1&') . 'sendUpdates=all',
                json_encode($payload),
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json']
            );

            $data = json_decode(self::httpBody($resp), true) ?: [];

            if (!empty($data['id'])) {
                $row->google_event_id = (string) $data['id'];
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
            'start'  => ((int) ($ws[0] ?? 9)) * 60 + (int) ($ws[1] ?? 0),
            'end'    => ((int) ($we[0] ?? 18)) * 60 + (int) ($we[1] ?? 0),
            'iv'     => max(5, (int) $this->params->get('slot_interval', 30)),
            'dur'    => isset($ev['dur']) ? max(5, (int) $ev['dur']) : max(5, (int) $this->params->get('slot_duration', 30)),
            'notice' => (int) $this->params->get('min_notice', 2) * 3600,
            'window' => max(1, (int) $this->params->get('window_days', 30)),
            'days'   => $wd ?: [1, 2, 3, 4, 5],
            'bufBefore' => (isset($ev['bb']) ? max(0, (int) $ev['bb']) : max(0, (int) $this->params->get('buffer_before', 0))) * 60,
            'bufAfter'  => (isset($ev['ba']) ? max(0, (int) $ev['ba']) : max(0, (int) $this->params->get('buffer_after', 0))) * 60,
        ];
    }

    /** Available "HH:MM" slots for one day, given pre-fetched busy intervals. */
    private function computeDay(string $dayStr, \DateTimeZone $tz, array $busy, int $now, array $c, array $local = []): array
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

            $data = (array) json_decode(self::httpBody($resp), true);
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

        try {
            $this->cancelRow($row, false);
        } catch (\Throwable $ex) {
        }

        $this->htmlPage(
            Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_TITLE'),
            '<h1 class="ok">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_TITLE')) . '</h1>'
            . '<p class="muted">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_MANAGE_CANCELLED_MSG')) . '</p>'
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

    /** Shared cancellation: DB status, Google event, email. $byAdmin skips the staff notification. */
    private function cancelRow(object $row, bool $byAdmin): array
    {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $seq = (int) ($row->seq ?? 0) + 1;

        // updateObject() takes the object BY REFERENCE: it must be a variable, not an inline (object) cast.
        $changes = (object) [
            'id'      => $row->id,
            'status'  => 'cancelled',
            'updated' => Factory::getDate()->toSql(),
            'seq'     => $seq,
        ];
        $db->updateObject('#__webgbooking_bookings', $changes, 'id');
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
                ['Authorization' => 'Bearer ' . $token]
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

        try {
            $tz   = new \DateTimeZone($this->siteTz());
            $now  = (new \DateTime('now', $tz))->getTimestamp();
            $next = (new \DateTime($date . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d');
            $busy = $this->googleBusy($date, $next, $tz);
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
            $dur   = $this->rowDuration($row);
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

    /**
     * Cancellation emails. The customer always gets an HTML email with an .ics CANCEL (so it disappears from
     * their calendar); staff gets a plain notice only when the customer cancelled by themselves.
     * Returns true when the customer email was sent.
     */
    private function notifyCancellation(object $row, bool $byAdmin = false): bool
    {
        $sent = false;

        try {
            $mf    = Factory::getContainer()->get(MailerFactoryInterface::class);
            $admin = trim((string) $this->params->get('notify_email', '')) ?: (string) $this->getApplication()->getConfig()->get('mailfrom');

            if (!$byAdmin && $admin !== '') {
                try {
                    $m = $mf->createMailer();
                    $m->addRecipient($admin);
                    $m->setSubject(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_ADMIN_SUBJECT', $row->booking_date, $row->booking_time));
                    $m->setBody(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_ADMIN_BODY', $row->booking_date, $row->booking_time, $row->customer_name, $row->customer_email)
                        . (($row->payment_status ?? '') === 'paid' ? "\n\n" . Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_REFUND_STAFF', self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()), (string) ($row->stripe_payment_intent ?? '')) : ''));
                    $m->Send();
                } catch (\Throwable $e) {
                }
            }

            $dateFmt = $row->booking_date;
            try {
                $dateFmt = (new \DateTime($row->booking_date))->format('d/m/Y');
            } catch (\Throwable $e) {
            }

            $e    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
            $body = '<p style="margin:0 0 14px;">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_HELLO')) . ' ' . $e($row->customer_name) . ',</p>'
                . '<p style="margin:0 0 14px;">' . $e(Text::sprintf($byAdmin ? 'PLG_SYSTEM_WEBGBOOKING_CANCEL_BY_STAFF_BODY' : 'PLG_SYSTEM_WEBGBOOKING_CANCEL_CUSTOMER_LINE', $dateFmt, $row->booking_time)) . '</p>'
                . (!$byAdmin && ($row->payment_status ?? '') === 'paid'
                    ? '<p style="margin:0 0 14px;">' . $e(Text::sprintf('PLG_SYSTEM_WEBGBOOKING_CANCEL_REFUND_CUSTOMER', self::formatAmount((int) $row->amount_cents, (string) $row->currency, $this->langTag()))) . '</p>' : '')
                . '<p style="margin:22px 0 0;">' . $e(Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_SIGNOFF')) . '</p>';

            $this->sendHtmlMail($mf, $row->customer_email, Text::_('PLG_SYSTEM_WEBGBOOKING_CANCEL_CUSTOMER_SUBJECT'), $this->wrapEmail($body), $this->buildIcs($row, 'CANCEL'), 'CANCEL');
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
            $manageUrl = $this->manageUrl($row, (string) ($plan['root'] ?? ''));
            $dateFmt   = (new \DateTime($row->booking_date))->format('d/m/Y');
            $tags      = ['{name}' => $row->customer_name, '{date}' => $dateFmt, '{time}' => $row->booking_time];

            $subjectTpl = (string) ($plan['subject'] ?? '');
            if ($subjectTpl === '') {
                $subjectTpl = trim((string) $this->params->get('reminder_subject', '')) ?: Text::_('PLG_SYSTEM_WEBGBOOKING_REMINDER_SUBJECT');
            }
            $bodyTpl = (string) ($plan['body'] ?? '');
            if ($bodyTpl === '') {
                $bodyTpl = trim((string) $this->params->get('reminder_body', '')) ?: $this->bodyTemplate('PLG_SYSTEM_WEBGBOOKING_EMAIL_REMINDER');
            }

            // No .ics on a reminder: the appointment is already in their calendar from the confirmation.
            $this->sendHtmlMail(
                $mf,
                $row->customer_email,
                strtr($subjectTpl, $tags),
                $this->wrapEmail($this->renderTpl($bodyTpl, $row, $manageUrl, $dateFmt, false)),
                ''
            );

            // The invited guest gets a reminder too: no management link, no phone of the customer.
            if (!empty($row->guest_email)) {
                try {
                    $this->sendHtmlMail(
                        $mf,
                        $row->guest_email,
                        strtr($subjectTpl, $tags),
                        $this->wrapEmail($this->guestEmailBody($row, $dateFmt, 'PLG_SYSTEM_WEBGBOOKING_EMAIL_GUEST_REMINDER_INTRO')),
                        ''
                    );
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
