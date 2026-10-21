<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The booking widget's configuration, built from a flat list of properties ("props"). Since 0.35.0 it lives here, in ONE
 * place, because three callers need exactly the same result:
 *  - the YOOtheme element (templates/template.php), whose props come from the builder;
 *  - the direct booking page (/prenota/<slug>), whose props come from a saved event type;
 *  - the server, which rebuilds the event definition, the questions and the price of a saved event type from the
 *    database when a booking arrives (the client never decides them).
 * The code of these blocks is the code that used to sit in template.php, moved without changing what it computes:
 * elements that do not pick a saved event type produce the same configuration as before.
 */

namespace WebG\Plugin\System\Webgbooking\Booking;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use WebG\Plugin\System\Webgbooking\Extension\Webgbooking;

final class WidgetConfig
{
    /** Plugin parameters as a Registry (the availability defaults and the Stripe hold come from there). */
    public static function pluginParams(): Registry
    {
        return Settings::all();
    }

    /**
     * Address of the privacy policy: the "privacy_url" option, otherwise the web address on the footer line that
     * mentions "privacy" (the footer text of the email brand, the same one the direct page shows). '' = no link.
     */
    public static function privacyUrl(Registry $pp): string
    {
        $own = trim((string) $pp->get('privacy_url', ''));
        if ($own !== '') {
            return Webgbooking::httpUrlOk($own) ? $own : '';
        }
        $footer = (string) $pp->get('mail_footer', '');
        if (FooterText::isRich($footer)) {
            // written in the editor: the link whose words (or address) say "privacy"; its address is what counts, not what is shown
            $u = FooterText::privacyHref($footer);

            return $u !== '' && Webgbooking::httpUrlOk($u) ? $u : '';
        }
        foreach (preg_split('/\R/', $footer) ?: [] as $line) {
            if (stripos($line, 'privacy') !== false && preg_match('#https?://[^\s<>"\']+#i', $line, $m)) {
                $u = rtrim($m[0], '.,;)');

                return Webgbooking::httpUrlOk($u) ? $u : '';
            }
        }

        return '';
    }

    /** The consent label with "privacy policy" as a link in a new tab; '' when no address is set (the plain label stays). */
    public static function privacyHtml(Registry $pp): string
    {
        $url = self::privacyUrl($pp);
        $txt = Text::_('PLG_SYSTEM_WEBGBOOKING_PRIVACY_LINKED');
        if ($url === '' || substr_count($txt, '[') !== 1 || substr_count($txt, ']') !== 1) {
            return '';
        }
        $h = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');

        return str_replace(['[', ']'], ['<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">', '</a>'], $h);
    }

    /** The zone of the site (the one the server computes slots in), validated. */
    public static function siteTzId(): string
    {
        $tz = (string) (Factory::getApplication()->get('offset') ?: date_default_timezone_get());
        try {
            new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            $tz = 'UTC';
        }

        return $tz;
    }

    private static function enum($v, array $ok, $def = '')
    {
        return \in_array($v, $ok, true) ? $v : $def;
    }

    /**
     * Thank-you page: absolute http(s) URL or a site path. Anything else is ignored.
     * (a backslash is refused everywhere: browsers read "/\host" as "//host", an open redirect)
     */
    public static function redirectUrl(string $raw): string
    {
        $redir = trim($raw);
        if ($redir !== '' && !preg_match('#^(https?://[^\s\\\\]+|/(?![/\\\\])[^\s\\\\]*)$#i', $redir)) {
            $redir = '';
        }

        return $redir;
    }

    /**
     * Custom form questions, one per line: "Label | type | required | options". Returns the definitions that travel
     * inside the signed token (the server validates answers against exactly these).
     */
    public static function customDefs(string $cfRaw): array
    {
        $customDefs = [];
        if ($cfRaw === '') {
            return $customDefs;
        }
        $allowedTypes = ['text', 'textarea', 'email', 'tel', 'number', 'select', 'checkbox'];
        $i = 0;
        foreach (preg_split('/\r\n|\r|\n/', $cfRaw) as $line) {
            $line = trim(strip_tags(html_entity_decode($line, ENT_QUOTES, 'UTF-8')));
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $label = $parts[0] ?? '';
            if ($label === '') {
                continue;
            }
            $type = strtolower($parts[1] ?? 'text');
            if (!\in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }
            $required = isset($parts[2]) && \in_array(strtolower($parts[2]), ['required', '*', 'yes', '1', 'si', 'sì'], true);
            $options = [];
            if ($type === 'select' && !empty($parts[3])) {
                foreach (explode(',', $parts[3]) as $opt) {
                    $opt = trim($opt);
                    if ($opt !== '') {
                        $options[] = $opt;
                    }
                }
            }
            $customDefs[] = ['name' => 'cf' . $i, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $options];
            if (++$i >= 20) {
                break; // safety cap
            }
        }

        return $customDefs;
    }

    /**
     * EVENT TYPE: duration, buffers, where the meeting happens, availability of this event. Empty duration and buffers
     * fall back to the Options of the component (resolved server-side on every request).
     */
    public static function eventCfg(array $props): array
    {
        $numOrNull = static function ($v) {
            $v = trim((string) $v);

            return ($v !== '' && is_numeric($v) && (int) $v >= 0) ? (int) $v : null;
        };
        $hhmm = static fn($v) => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim((string) $v)) ? trim((string) $v) : null;
        $availWs = $hhmm($props['avail_start'] ?? '');
        $availWe = $hhmm($props['avail_end'] ?? '');
        $availDays = null;
        // since 0.35.1 the element has one check box per day (avail_day_0 = Sunday ... avail_day_6 = Saturday); ticked boxes win,
        // otherwise the old text field ("1,2,3,4,5") is read exactly as before. Nothing ticked and nothing typed = the plugin's days.
        $ticked = [];
        for ($d = 0; $d <= 6; $d++) {
            if (!empty($props['avail_day_' . $d])) {
                $ticked[] = $d;
            }
        }
        if ($ticked) {
            $availDays = $ticked;
        } elseif (trim((string) ($props['avail_days'] ?? '')) !== '') {
            $availDays = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s;]+/', trim((string) $props['avail_days']))), static fn($d) => $d >= 0 && $d <= 6)));
            $availDays = $availDays ?: null;
        }

        return [
            'ws'   => ($availWs && $availWe) ? $availWs : null,
            'we'   => ($availWs && $availWe) ? $availWe : null,
            'wd'   => $availDays,
            'nt'   => $numOrNull($props['avail_notice'] ?? ''),
            'wn'   => $numOrNull($props['avail_window'] ?? ''),
            'dur'  => $numOrNull($props['event_duration'] ?? ''),
            'bb'   => $numOrNull($props['event_buffer_before'] ?? ''),
            'ba'   => $numOrNull($props['event_buffer_after'] ?? ''),
            'loc'  => self::enum($props['event_location'] ?? '', ['meet', 'phone', 'choice'], 'meet'),
            'name' => mb_substr(trim(strip_tags((string) ($props['service'] ?? ''))), 0, 120),
        ];
    }

    /**
     * PRICE (empty / 0 = free event). An amount that cannot be read unambiguously ("49.999", "4,9,9") or a currency
     * outside EUR/USD/GBP/CHF marks the price INVALID: the event then refuses bookings instead of becoming free.
     * Returns [config for the token, invalid?].
     */
    public static function priceCfg(array $props): array
    {
        $priceRaw = trim((string) ($props['price_amount'] ?? ''));
        $priceAmt = $priceRaw === '' ? 0 : Webgbooking::parseAmountStrict($priceRaw);
        $priceCur = strtoupper(trim((string) ($props['price_currency'] ?? '')));
        $priceCur = $priceCur === '' ? 'EUR' : $priceCur;
        $priceBad = ($priceRaw !== '' && ($priceAmt === null || !\in_array($priceCur, Webgbooking::CURRENCIES, true)));
        $priceAmt = (int) $priceAmt;
        $cfg      = $priceBad
            ? ['invalid' => true]
            : ($priceAmt > 0 ? ['amt' => $priceAmt, 'cur' => $priceCur, 'label' => mb_substr(trim(strip_tags((string) ($props['price_label'] ?? ''))), 0, 120)] : []);

        return [$cfg, $priceBad];
    }

    /**
     * Everything the element template (or the direct page) needs.
     *
     * @param array $props flat properties: the element's, or EventTypes::pageProps() of a saved event type
     * @param array $opts  'etype' => saved event type id (sealed into the token: the server re-reads the type),
     *                     'page' => true for the direct page, 'unavailable' => the saved type the element points to is
     *                     gone or unpublished
     */
    public static function build(array $props, array $opts = []): array
    {
        $enum = static fn($v, array $ok, $def = '') => self::enum($v, $ok, $def);

        $cardClass = [
            'card' => 'uk-card uk-card-default uk-card-body',
            'primary' => 'uk-card uk-card-primary uk-card-body',
            'secondary' => 'uk-card uk-card-secondary uk-card-body',
            'plain' => 'wgb-plain',
        ][$props['card_style'] ?? 'card'] ?? 'uk-card uk-card-default uk-card-body';

        $layout    = $enum($props['layout'] ?? '', ['inline', 'popup', 'slidein'], 'inline');
        $slotCols  = (int) $enum((string) ($props['slot_columns'] ?? ''), ['2', '3', '4'], '3');
        $slotStyle = $enum($props['slot_style'] ?? '', ['default', 'primary', 'secondary'], 'default');
        $slotSize  = $enum($props['slot_size'] ?? '', ['small', 'large']);
        $btnStyle  = $enum($props['button_style'] ?? '', ['default', 'primary', 'secondary'], 'primary');
        $btnSize   = $enum($props['button_size'] ?? '', ['small', 'large']);
        $accent    = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($props['accent_color'] ?? '')) ? $props['accent_color'] : '';

        $title    = (string) ($props['title'] ?? '') !== '' ? $props['title'] : Text::_('PLG_SYSTEM_WEBGBOOKING_DEFAULT_TITLE');
        $service  = (string) ($props['service'] ?? '');
        $btnText  = (string) ($props['button_text'] ?? '') !== '' ? $props['button_text'] : Text::_('PLG_SYSTEM_WEBGBOOKING_DEFAULT_BUTTON');

        $locale   = Factory::getApplication()->getLanguage()->getTag();
        $density  = $enum($props['cal_density'] ?? '', ['compact', 'comfortable'], 'compact');
        $calStyle = $enum($props['cal_style'] ?? '', ['calendly', 'plain'], 'calendly');
        $headCol  = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($props['header_color'] ?? '')) ? $props['header_color'] : '';

        // Availability comes from the Options of the component (backend); the widget builds real slots from it.
        $pp = self::pluginParams();
        $timeOk = fn($v, $def) => preg_match('/^\d{1,2}:\d{2}$/', (string) $v) ? (string) $v : $def;
        $workDays = Settings::workDays($pp->get('work_days', null));

        $customDefs = self::customDefs((string) ($props['custom_fields'] ?? ''));
        $evCfg      = self::eventCfg($props);
        $etype      = (int) ($opts['etype'] ?? 0);
        $evCfgTok   = $evCfg;
        if ($etype > 0) {
            $evCfgTok['et'] = $etype; // the small availability token names the saved type: the server reads its current definition
        }
        $shownDur = ($evCfg['dur'] ?? 0) > 0 ? (int) $evCfg['dur'] : (int) $pp->get('slot_duration', 30);
        $shownDur = max(5, min(480, $shownDur));

        $redir = self::redirectUrl((string) ($props['redirect_url'] ?? ''));

        [$priceCfg, $priceBad] = self::priceCfg($props);

        // Reminders: element override (on/off, times, text); empty times/text fall back to the Options of the component.
        $remCfg = [
            'on'      => !isset($props['act_reminder_on']) || !empty($props['act_reminder_on']),
            'times'   => Webgbooking::parseTimes((string) ($props['reminder_times'] ?? '')),
            'subject' => (string) ($props['reminder_subject'] ?? ''),
            'body'    => (string) ($props['reminder_body'] ?? ''),
        ];

        // Per-element ACTIONS config (customer email / staff email / webhook), signed with the site secret
        // so the client cannot tamper it (no spam-relay / no SSRF: the server only trusts what it signed).
        $actionsCfg = [
            'fields' => $customDefs,
            'v'     => (int) max(1, (int) $pp->get('config_epoch', 1)),
            'iat'   => time(),
            'event' => $evCfg,
            'price' => $priceCfg,
            'redirect' => $redir,
            'analytics' => preg_match('/^[A-Za-z0-9_\-]{1,40}$/', trim((string) ($props['analytics_event'] ?? ''))) ? trim((string) $props['analytics_event']) : 'wgb_booking_completed',
            'reminder' => $remCfg,
            'manual'   => !empty($props['confirm_manual']),
            // Signed: the newsletter tick only counts when this element really shows the box (the POST field is the visitor's).
            'showNewsletter' => !empty($props['show_newsletter']),
            'customer' => [
                'on'      => !isset($props['act_customer_on']) || !empty($props['act_customer_on']),
                'subject' => (string) ($props['email_subject'] ?? ''),
                'body'    => (string) ($props['email_body'] ?? ''),
            ],
            'staff' => [
                'on'      => !isset($props['act_staff_on']) || !empty($props['act_staff_on']),
                'to'      => (string) ($props['staff_to'] ?? ''),
                'subject' => (string) ($props['staff_subject'] ?? ''),
                'body'    => (string) ($props['staff_body'] ?? ''),
            ],
            'webhook' => [
                'on'     => !empty($props['act_webhook_on']),
                'url'    => preg_match('#^https?://[^\s]+$#i', trim((string) ($props['webhook_url'] ?? ''))) ? trim((string) $props['webhook_url']) : '',
                'method' => (string) ($props['webhook_method'] ?? 'POST'),
                // Only the NAME of a header profile: the headers (and their secrets) live in the Options of the component, never in this markup.
                'profile' => mb_substr(trim((string) ($props['webhook_profile'] ?? '')), 0, 60),
            ],
            'brevo' => [
                'on'    => !empty($props['act_brevo_on']),
                'lists' => array_slice(array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) ($props['brevo_lists'] ?? '')) ?: []), static fn($i) => $i > 0)), 0, 20),
                'when'  => (string) ($props['brevo_when'] ?? 'newsletter') === 'always' ? 'always' : 'newsletter',
                'attrs' => mb_substr((string) ($props['brevo_attrs'] ?? ''), 0, 1500),
                'doi'   => [
                    'template' => (int) ($props['brevo_doi_template'] ?? 0),
                    'redirect' => preg_match('#^https://[^\s\\\\]+$#i', trim((string) ($props['brevo_doi_redirect'] ?? ''))) ? trim((string) $props['brevo_doi_redirect']) : '',
                ],
            ],
        ];
        if ($etype > 0) {
            // A saved event type: the server re-reads its definition (questions, price, duration ...) from the database
            // when the booking arrives; 'src' says whether the staff recipient / redirect / manual flag also come from it.
            $actionsCfg['etype'] = $etype;
            if (!empty($opts['page'])) {
                $actionsCfg['src'] = 'page';
            }
        }
        $secret    = (string) Factory::getApplication()->get('secret');
        // Sealed (AES-GCM + HMAC): the page source does not show the webhook address, staff recipients or Brevo settings.
        $formToken = Webgbooking::sealFormToken($actionsCfg, $secret);
        $evToken   = Webgbooking::signEv($evCfgTok, $secret, (int) max(1, (int) $pp->get('config_epoch', 1))); // small signed token for the GET availability calls

        $cfg = [
            'locale'    => $locale,
            'form'      => $formToken,
            'ev'        => $evToken,
            'location'  => $evCfg['loc'],
            'redirectUrl' => $redir,
            'priceInvalid' => $priceBad,
            'price'     => ($priceCfg && empty($priceCfg['invalid'])) ? ['text' => Webgbooking::formatAmount($priceCfg['amt'], $priceCfg['cur'], $locale), 'hold' => max(30, min(1440, (int) $pp->get('stripe_hold_minutes', 30)))] : null,
            'cols'      => $slotCols,
            'slotStyle' => $slotStyle,
            'slotSize'  => $slotSize ? 'uk-button-' . $slotSize : '',
            'btnStyle'  => $btnStyle,
            'allowGuest' => !empty($props['allow_guest']),
            'customFields' => $customDefs,
            'showNewsletter' => !empty($props['show_newsletter']),
            'analyticsEvent' => trim((string) ($props['analytics_event'] ?? '')),
            'accent'    => $accent,
            'workStart' => $timeOk($evCfg['ws'] ?? $pp->get('work_start', '09:00'), '09:00'),
            'workEnd'   => $timeOk($evCfg['we'] ?? $pp->get('work_end', '18:00'), '18:00'),
            'interval'  => (int) $pp->get('slot_interval', 30),
            'duration'  => $shownDur,
            'workDays'  => $evCfg['wd'] ?? $workDays,
            'minNotice' => $evCfg['nt'] ?? (int) $pp->get('min_notice', 2),
            'window'    => $evCfg['wn'] ?? (int) $pp->get('window_days', 30),
            'ajaxUrl'   => Uri::root(true) . '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=json',
            'siteTz'    => self::siteTzId(),   // the zone the server works in; the page shows the visitor's own and sends both
            'labels'    => [
                'stepDay'     => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_DAY'),
                'stepTime'    => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_TIME'),
                'stepDetails' => Text::_('PLG_SYSTEM_WEBGBOOKING_STEP_DETAILS'),
                'back'        => Text::_('PLG_SYSTEM_WEBGBOOKING_BACK'),
                'noSlots'     => Text::_('PLG_SYSTEM_WEBGBOOKING_NO_SLOTS'),
                'fName'       => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_NAME'),
                'fEmail'      => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_EMAIL'),
                'fPhone'      => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_PHONE'),
                'fNotes'      => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_NOTES'),
                'fGuest'      => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_GUEST'),
                'fNewsletter' => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_NEWSLETTER'),
                'emailNote'   => Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_NOTE'),
                'emailNotePhone' => Text::_('PLG_SYSTEM_WEBGBOOKING_EMAIL_NOTE_PHONE'),
                'locChoice'   => Text::_('PLG_SYSTEM_WEBGBOOKING_LOC_CHOICE'),
                'locMeet'     => Text::_('PLG_SYSTEM_WEBGBOOKING_LOC_MEET'),
                'locPhone'    => Text::_('PLG_SYSTEM_WEBGBOOKING_LOC_PHONE'),
                'fPhoneNeeded' => Text::_('PLG_SYSTEM_WEBGBOOKING_FIELD_PHONE_NEEDED'),
                'phoneReq'    => Text::_('PLG_SYSTEM_WEBGBOOKING_PHONE_REQUIRED'),
                'minShort'    => Text::_('PLG_SYSTEM_WEBGBOOKING_MINUTES'),
                'payNow'      => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_NOW'),
                'payNote'     => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_NOTE'),
                'payRedirecting' => Text::_('PLG_SYSTEM_WEBGBOOKING_PAY_REDIRECTING'),
                'priceInvalid' => Text::_('PLG_SYSTEM_WEBGBOOKING_PRICE_INVALID'),
                'calUnavailable' => Text::_('PLG_SYSTEM_WEBGBOOKING_CAL_UNAVAILABLE'),
                'privacy'     => Text::_('PLG_SYSTEM_WEBGBOOKING_PRIVACY'),
                'privacyHtml' => self::privacyHtml($pp),
                'phoneHint'   => Text::_('PLG_SYSTEM_WEBGBOOKING_PHONE_HINT'),
                'tzLabel'     => Text::_('PLG_SYSTEM_WEBGBOOKING_TZ_LABEL'),
                'bookNow'     => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_NOW'),
                'required'    => Text::_('PLG_SYSTEM_WEBGBOOKING_REQUIRED'),
                'demo'        => Text::_('PLG_SYSTEM_WEBGBOOKING_DEMO_NOTE'),
                'done'        => Text::_('PLG_SYSTEM_WEBGBOOKING_DONE'),
                'sending'     => Text::_('PLG_SYSTEM_WEBGBOOKING_SENDING'),
                'bookErr'     => Text::_('PLG_SYSTEM_WEBGBOOKING_BOOK_ERR'),
                'prevMonth'   => Text::_('PLG_SYSTEM_WEBGBOOKING_PREV_MONTH'),
                'nextMonth'   => Text::_('PLG_SYSTEM_WEBGBOOKING_NEXT_MONTH'),
                'loading'     => Text::_('PLG_SYSTEM_WEBGBOOKING_LOADING'),
            ],
        ];
        if (!empty($opts['unavailable'])) {
            // The saved event type this element points to was deleted or unpublished: say so instead of guessing.
            $cfg['unavailable'] = Text::_('PLG_SYSTEM_WEBGBOOKING_ETYPE_GONE');
        }

        // Pick a foreground (white/dark) that stays readable on the chosen accent colour.
        $accentFg = static function (string $hex): string {
            $h = ltrim($hex, '#');
            if (strlen($h) === 3) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }
            if (strlen($h) < 6) {
                return '#ffffff';
            }
            $lum = 0.299 * hexdec(substr($h, 0, 2)) + 0.587 * hexdec(substr($h, 2, 2)) + 0.114 * hexdec(substr($h, 4, 2));

            return $lum > 150 ? '#1f2937' : '#ffffff';
        };
        $vars = [];
        if ($accent) {
            $vars[] = '--wgb-accent:' . $accent;
            $vars[] = '--wgb-accent-fg:' . $accentFg($accent);
        }
        if ($headCol) {
            $vars[] = '--wgb-head:' . $headCol;
            $vars[] = '--wgb-head-op:1';
        }

        return [
            'cfg'       => $cfg,
            'cfgAttr'   => htmlspecialchars((string) json_encode($cfg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'),
            'styleVar'  => $vars ? ' style="' . htmlspecialchars(implode(';', $vars), ENT_QUOTES, 'UTF-8') . '"' : '',
            'cardClass' => $cardClass,
            'layout'    => $layout,
            'density'   => $density,
            'calStyle'  => $calStyle,
            'accent'    => $accent,
            'btnStyle'  => $btnStyle,
            'btnSize'   => $btnSize,
            'btnText'   => $btnText,
            'title'     => $title,
            'service'   => !empty($props['_title_from_type']) ? '' : $service, // the heading already says it when it came from the saved type
            'ev'        => $evCfg,
            'price'     => $priceCfg,
            'fields'    => $customDefs,
        ];
    }
}
