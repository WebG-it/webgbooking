<?php

/**
 * WebG Booking element — render template (UIkit markup + interactive widget).
 * YOOtheme contract: $this->el(), $props, $attrs, $children, $builder.
 *
 * Calendly-style flow: month CALENDAR -> time slots -> details FORM -> confirm.
 * Availability is demo data for now; real data comes from the com_webgbooking engine.
 * All visitor-facing strings are translatable (Joomla language, IT/EN shipped).
 *
 * @license GNU General Public License version 2 or later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use WebG\Plugin\System\Webgbooking\Extension\Webgbooking;

\defined('_JEXEC') or die;

$enum = fn($v, array $ok, $def = '') => in_array($v, $ok, true) ? $v : $def;

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

$locale  = Factory::getApplication()->getLanguage()->getTag();
$density  = $enum($props['cal_density'] ?? '', ['compact', 'comfortable'], 'compact');
$calStyle = $enum($props['cal_style'] ?? '', ['calendly', 'plain'], 'calendly');
$headCol  = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($props['header_color'] ?? '')) ? $props['header_color'] : '';

// Availability comes from the plugin Options (backend); the widget builds real slots from it.
$pp = new Registry(PluginHelper::getPlugin('system', 'webgbooking')->params ?? '');
$timeOk = fn($v, $def) => preg_match('/^\d{1,2}:\d{2}$/', (string) $v) ? (string) $v : $def;
$wd = $pp->get('work_days', [1, 2, 3, 4, 5]);
$workDays = array_values(array_map('intval', is_array($wd) ? $wd : explode(',', (string) $wd)));

// Custom form questions: parsed from the element config (one per line: "Label | type | required | options").
// The DEFINITIONS travel inside the signed token below, so the server validates/labels answers against
// exactly what it emitted — the client cannot inject fields or turn "required" off.
$customDefs = [];
$cfRaw = (string) ($props['custom_fields'] ?? '');
if ($cfRaw !== '') {
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
}

// EVENT TYPE of this element: duration, buffers, where the meeting happens. Empty duration/buffers fall back to
// the plugin options (resolved server-side on every request, so a change of the defaults applies at once).
$numOrNull = static function ($v) {
    $v = trim((string) $v);

    return ($v !== '' && is_numeric($v) && (int) $v >= 0) ? (int) $v : null;
};
$evCfg = [
    'dur'  => $numOrNull($props['event_duration'] ?? ''),
    'bb'   => $numOrNull($props['event_buffer_before'] ?? ''),
    'ba'   => $numOrNull($props['event_buffer_after'] ?? ''),
    'loc'  => $enum($props['event_location'] ?? '', ['meet', 'phone', 'choice'], 'meet'),
    'name' => mb_substr(trim(strip_tags($service)), 0, 120),
];
$shownDur = ($evCfg['dur'] ?? 0) > 0 ? (int) $evCfg['dur'] : (int) $pp->get('slot_duration', 30);
$shownDur = max(5, min(480, $shownDur));

// Thank-you page: absolute http(s) URL or a site path. Anything else is ignored.
$redir = trim((string) ($props['redirect_url'] ?? ''));
if ($redir !== '' && !preg_match('#^(https?://[^\s]+|/(?!/)[^\s]*)$#i', $redir)) {
    $redir = '';
}

// PRICE of this element (empty / 0 = free event, nothing changes). Signed with the rest of the config, so the
// visitor can neither lower nor remove the price.
$priceAmt = Webgbooking::parseAmount((string) ($props['price_amount'] ?? ''));
$priceCur = strtoupper(trim((string) ($props['price_currency'] ?? '')));
if (!preg_match('/^[A-Z]{3}$/', $priceCur)) {
    $priceCur = 'EUR';
}
$priceCfg = $priceAmt > 0
    ? ['amt' => $priceAmt, 'cur' => $priceCur, 'label' => mb_substr(trim(strip_tags((string) ($props['price_label'] ?? ''))), 0, 120)]
    : [];

// Reminders: element override (on/off, times, text); empty times/text fall back to the plugin options.
$remCfg = [
    'on'      => !isset($props['act_reminder_on']) || !empty($props['act_reminder_on']),
    'times'   => Webgbooking::parseTimes((string) ($props['reminder_times'] ?? '')),
    'subject' => (string) ($props['reminder_subject'] ?? ''),
    'body'    => (string) ($props['reminder_body'] ?? ''),
];

// Per-element ACTIONS config (customer email / staff email / webhook), signed with the site secret
// so the client cannot tamper it (no spam-relay / no SSRF — the server only trusts what it signed).
$actionsCfg = [
    'fields' => $customDefs,
    'event' => $evCfg,
    'price' => $priceCfg,
    'redirect' => $redir,
    'reminder' => $remCfg,
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
        'url'    => (string) ($props['webhook_url'] ?? ''),
        'method' => (string) ($props['webhook_method'] ?? 'POST'),
    ],
];
$secret    = (string) Factory::getApplication()->get('secret');
$payload   = base64_encode((string) json_encode($actionsCfg));
$formToken = $payload . '.' . hash_hmac('sha256', $payload, $secret);
$evToken   = Webgbooking::signEv($evCfg, $secret); // small signed token for the GET availability calls

$cfg = [
    'locale'    => $locale,
    'form'      => $formToken,
    'ev'        => $evToken,
    'location'  => $evCfg['loc'],
    'redirectUrl' => $redir,
    'price'     => $priceCfg ? ['text' => Webgbooking::formatAmount($priceCfg['amt'], $priceCfg['cur'], $locale), 'hold' => max(30, min(1440, (int) $pp->get('stripe_hold_minutes', 30)))] : null,
    'cols'      => $slotCols,
    'slotStyle' => $slotStyle,
    'slotSize'  => $slotSize ? 'uk-button-' . $slotSize : '',
    'btnStyle'  => $btnStyle,
    'allowGuest' => !empty($props['allow_guest']),
    'customFields' => $customDefs,
    'showNewsletter' => !empty($props['show_newsletter']),
    'analyticsEvent' => trim((string) ($props['analytics_event'] ?? '')),
    'accent'    => $accent,
    'workStart' => $timeOk($pp->get('work_start', '09:00'), '09:00'),
    'workEnd'   => $timeOk($pp->get('work_end', '18:00'), '18:00'),
    'interval'  => (int) $pp->get('slot_interval', 30),
    'duration'  => $shownDur,
    'workDays'  => $workDays,
    'minNotice' => (int) $pp->get('min_notice', 2),
    'window'    => (int) $pp->get('window_days', 30),
    'ajaxUrl'   => Uri::root(true) . '/index.php?option=com_ajax&group=system&plugin=webgbooking&format=json',
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
        'privacy'     => Text::_('PLG_SYSTEM_WEBGBOOKING_PRIVACY'),
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

$cfgAttr = htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
// Pick a foreground (white/dark) that stays readable on the chosen accent colour.
$accentFg = static function (string $hex): string {
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
    if (strlen($h) < 6) { return '#ffffff'; }
    $lum = 0.299 * hexdec(substr($h, 0, 2)) + 0.587 * hexdec(substr($h, 2, 2)) + 0.114 * hexdec(substr($h, 4, 2));
    return $lum > 150 ? '#1f2937' : '#ffffff';
};
$vars = [];
if ($accent) { $vars[] = '--wgb-accent:' . $accent; $vars[] = '--wgb-accent-fg:' . $accentFg($accent); }
if ($headCol) { $vars[] = '--wgb-head:' . $headCol; $vars[] = '--wgb-head-op:1'; }
$styleVar = $vars ? ' style="' . htmlspecialchars(implode(';', $vars), ENT_QUOTES, 'UTF-8') . '"' : '';
$uid = 'wgb-' . substr(md5(uniqid('', true)), 0, 8);

// Assets are output directly in the element markup (<link> + <script src>). This executes on the
// initial parse in BOTH the front end and the YOOtheme customizer preview — unlike WebAssetManager
// scripts, which the customizer does not reliably flush to the live preview (the calendar stayed
// blank). A MutationObserver in wgb.js then re-initialises every instance the builder re-renders.
$wgbVer    = '0.32.0';
$wgbAsset  = Uri::root(true) . '/plugins/system/webgbooking/yootheme/elements/booking/assets';
$assetTags = '<link rel="stylesheet" href="' . htmlspecialchars($wgbAsset . '/wgb.css?' . $wgbVer, ENT_QUOTES, 'UTF-8') . '">'
    . '<script src="' . htmlspecialchars($wgbAsset . '/wgb.js?' . $wgbVer, ENT_QUOTES, 'UTF-8') . '" defer></script>';

$root = $this->el('div', ['class' => ['wgb-booking', 'wgb-' . $density, 'wgb-style-' . $calStyle, ($accent ? 'wgb-has-accent' : ''), $cardClass]]);

$inner = function () use ($title, $service, $cfg) {
    ob_start(); ?>
    <?php if ($title !== '') : ?><h3 class="uk-card-title uk-margin-remove-bottom wgb-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3><?php endif ?>
    <?php if ($service !== '') : ?><div class="uk-text-meta uk-margin-small-bottom"><?= htmlspecialchars($service, ENT_QUOTES, 'UTF-8') ?></div><?php endif ?>
    <div class="wgb-mount uk-margin-small-top"><div class="uk-text-meta"><?= htmlspecialchars($cfg['labels']['stepDay'], ENT_QUOTES, 'UTF-8') ?>…</div></div>
    <?php return ob_get_clean();
};

$trigBtn = trim('uk-button uk-button-' . $btnStyle . ($btnSize ? ' uk-button-' . $btnSize : '') . (!empty($props['button_fullwidth']) ? ' uk-width-1-1' : ''));

?>
<?php if (empty($GLOBALS['__wgbAssets'])) { $GLOBALS['__wgbAssets'] = true; echo $assetTags; } ?>
<?php if ($layout === 'inline') : ?>

    <?= $root($props, $attrs) ?>
    <div data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $inner() ?></div>
    </div>

<?php else : ?>

    <?php $trig = $this->el('div', ['class' => ['wgb-booking-trigger']]); ?>
    <?= $trig($props, $attrs) ?>
        <button class="<?= $trigBtn ?>" type="button" uk-toggle="target: #<?= $uid ?>"<?= $styleVar ?>><?= htmlspecialchars($btnText, ENT_QUOTES, 'UTF-8') ?></button>
        <?php if ($layout === 'popup') : ?>
        <div id="<?= $uid ?>" uk-modal>
            <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">
                <button class="uk-modal-close-default" type="button" uk-close></button>
                <div class="wgb-booking wgb-<?= $density ?> wgb-style-<?= $calStyle ?><?= $accent ? ' wgb-has-accent' : '' ?>" data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $inner() ?></div>
            </div>
        </div>
        <?php else : ?>
        <div id="<?= $uid ?>" uk-offcanvas="flip: true; overlay: true">
            <div class="uk-offcanvas-bar">
                <button class="uk-offcanvas-close" type="button" uk-close></button>
                <div class="wgb-booking wgb-<?= $density ?> wgb-style-<?= $calStyle ?><?= $accent ? ' wgb-has-accent' : '' ?>" data-wgb="<?= $cfgAttr ?>"<?= $styleVar ?>><?= $inner() ?></div>
            </div>
        </div>
        <?php endif ?>
    </div>

<?php endif ?>
