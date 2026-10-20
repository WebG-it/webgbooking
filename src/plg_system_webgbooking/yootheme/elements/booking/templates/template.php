<?php

/**
 * WebG Booking element — render template (UIkit markup + interactive widget).
 * YOOtheme contract: $this->el(), $props, $attrs, $children, $builder.
 *
 * Calendly-style flow: month CALENDAR -> time slots -> details FORM -> confirm.
 * The configuration is built by WidgetConfig (shared with the direct booking page); this file is the markup.
 * All visitor-facing strings are translatable (Joomla language, IT/EN shipped).
 *
 * @license GNU General Public License version 2 or later
 */

use Joomla\CMS\Uri\Uri;
use WebG\Plugin\System\Webgbooking\Booking\EventTypes;
use WebG\Plugin\System\Webgbooking\Booking\WidgetConfig;
use WebG\Plugin\System\Webgbooking\Extension\Webgbooking;

\defined('_JEXEC') or die;

// Since 0.35.0 the configuration (questions, event type, price, signed token, labels) is built by WidgetConfig, the same
// code the direct booking page uses. An element that picks a SAVED EVENT TYPE takes the event definition from it (and the
// server re-reads the type when the booking arrives); an element that does not pick one is configured by hand, as before.
$etypeId = (int) ($props['event_type'] ?? 0);
$wopts   = [];
if ($etypeId > 0) {
    $saved = EventTypes::get($etypeId);
    if ($saved) {
        $props = EventTypes::overlay($props, $saved);
    } else {
        $wopts['unavailable'] = true;
    }
    $wopts['etype'] = $etypeId;
}
$w         = WidgetConfig::build($props, $wopts);
$cardClass = $w['cardClass'];
$layout    = $w['layout'];
$btnStyle  = $w['btnStyle'];
$btnSize   = $w['btnSize'];
$btnText   = $w['btnText'];
$title     = $w['title'];
$service   = $w['service'];
$density   = $w['density'];
$calStyle  = $w['calStyle'];
$accent    = $w['accent'];
$cfg       = $w['cfg'];
$cfgAttr   = $w['cfgAttr'];
$styleVar  = $w['styleVar'];
$uid = 'wgb-' . substr(md5(uniqid('', true)), 0, 8);

// Assets are output directly in the element markup (<link> + <script src>). This executes on the
// initial parse in BOTH the front end and the YOOtheme customizer preview — unlike WebAssetManager
// scripts, which the customizer does not reliably flush to the live preview (the calendar stayed
// blank). A MutationObserver in wgb.js then re-initialises every instance the builder re-renders.
$wgbVer    = Webgbooking::ASSET_VER; // one constant for the cache-buster; tools/build.py refuses a build where it differs from the package version
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
