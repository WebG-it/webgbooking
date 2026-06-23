<?php

/**
 * WebG Booking element — render template (UIkit markup).
 * YOOtheme contract: $this->el(), $props, $attrs, $children, $builder.
 * Tracer preview of the real flow: STEP 1 pick a day, STEP 2 pick a time.
 * Common props (margin, maxwidth, block_align, animation, visibility) are applied
 * by the builder; here we apply the element-specific design options.
 *
 * @license GNU General Public License version 2 or later
 */

$cards = [
    'card' => 'uk-card uk-card-default uk-card-body',
    'primary' => 'uk-card uk-card-primary uk-card-body',
    'secondary' => 'uk-card uk-card-secondary uk-card-body',
    'plain' => '',
];
$cardClass = $cards[$props['card_style'] ?? 'card'] ?? $cards['card'];

$el = $this->el('div', [
    'class' => ['wgb-booking', $cardClass],
]);

$enum = fn($v, array $ok, $def = '') => in_array($v, $ok, true) ? $v : $def;

$title   = htmlspecialchars((string) ($props['title'] ?? ''), ENT_QUOTES, 'UTF-8');
$service = htmlspecialchars((string) ($props['service'] ?? ''), ENT_QUOTES, 'UTF-8');
$btnText = htmlspecialchars((string) ($props['button_text'] ?: 'Confirm booking'), ENT_QUOTES, 'UTF-8');

$btnStyle = $enum($props['button_style'] ?? '', ['default', 'primary', 'secondary'], 'primary');
$btnSize  = $enum($props['button_size'] ?? '', ['small', 'large']);
$btnWidth = !empty($props['button_fullwidth']) ? ' uk-width-1-1' : '';

$slotCols  = $enum((string) ($props['slot_columns'] ?? ''), ['2', '3', '4'], '3');
$slotStyle = $enum($props['slot_style'] ?? '', ['default', 'primary', 'secondary'], 'default');
$slotSize  = $enum($props['slot_size'] ?? '', ['small', 'large']);

$accent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($props['accent_color'] ?? '')) ? $props['accent_color'] : '';
$accentStyle = $accent ? ' style="background-color:' . $accent . ';border-color:' . $accent . '"' : '';

$slotBtn = trim("uk-button uk-button-$slotStyle" . ($slotSize ? " uk-button-$slotSize" : '') . ' uk-width-1-1');
$mainBtn = trim("uk-button uk-button-$btnStyle" . ($btnSize ? " uk-button-$btnSize" : '') . $btnWidth);

// Tracer placeholders. Real days/slots come from the com_webgbooking availability engine.
$days  = ['Mon 23', 'Tue 24', 'Wed 25', 'Thu 26', 'Fri 27'];
$slots = ['09:00', '10:30', '14:00', '15:30'];

?>
<?= $el($props, $attrs) ?>

    <?php if ($title !== '') : ?>
    <h3 class="uk-card-title uk-margin-remove-bottom"><?= $title ?></h3>
    <?php endif ?>

    <?php if ($service !== '') : ?>
    <div class="uk-text-meta uk-margin-small-bottom"><?= $service ?></div>
    <?php endif ?>

    <!-- STEP 1 — choose a day -->
    <div class="uk-text-meta uk-text-bold uk-margin-small-bottom">1. Choose a day</div>
    <div class="uk-grid-small uk-child-width-expand uk-margin-small uk-flex-nowrap uk-overflow-auto" uk-grid>
        <?php foreach ($days as $i => $d) : ?>
        <div><button class="uk-button uk-button-<?= $i === 0 ? 'primary' : 'default' ?> uk-width-1-1" type="button" disabled><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?></button></div>
        <?php endforeach ?>
    </div>

    <!-- STEP 2 — choose a time for the selected day -->
    <div class="uk-text-meta uk-text-bold uk-margin-small-bottom uk-margin-top">2. Choose a time</div>
    <div class="uk-grid-small uk-child-width-1-<?= $slotCols ?>@s uk-margin-small" uk-grid>
        <?php foreach ($slots as $s) : ?>
        <div><button class="<?= $slotBtn ?>" type="button" disabled><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></button></div>
        <?php endforeach ?>
    </div>

    <button class="<?= $mainBtn ?> uk-margin-small-top" type="button"<?= $accentStyle ?>><?= $btnText ?></button>

</div>
