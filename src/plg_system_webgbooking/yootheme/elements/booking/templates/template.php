<?php

/**
 * WebG Booking element — render template (UIkit markup).
 * YOOtheme contract: $this->el(), $props, $attrs, $children, $builder.
 * Common props (margin, maxwidth, block_align, animation, visibility) are applied
 * automatically by the builder; here we apply the element-specific design options.
 *
 * @license GNU General Public License version 2 or later
 */

// Container style → card classes.
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

// Sanitise/escape everything that reaches the markup (see docs/04-security.md).
$enum = fn($v, array $ok, $def = '') => in_array($v, $ok, true) ? $v : $def;

$title   = htmlspecialchars((string) ($props['title'] ?? ''), ENT_QUOTES, 'UTF-8');
$service = htmlspecialchars((string) ($props['service'] ?? ''), ENT_QUOTES, 'UTF-8');
$btnText = htmlspecialchars((string) ($props['button_text'] ?: 'Check availability'), ENT_QUOTES, 'UTF-8');

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

?>
<?= $el($props, $attrs) ?>

    <?php if ($title !== '') : ?>
    <h3 class="uk-card-title uk-margin-remove-bottom"><?= $title ?></h3>
    <?php endif ?>

    <?php if ($service !== '') : ?>
    <div class="uk-text-meta uk-margin-small-bottom"><?= $service ?></div>
    <?php endif ?>

    <!-- Tracer placeholder slots. The real availability widget is mounted by media/com_webgbooking JS. -->
    <div class="uk-grid-small uk-child-width-1-<?= $slotCols ?>@s uk-margin-small" uk-grid>
        <div><button class="<?= $slotBtn ?>" type="button" disabled>09:00</button></div>
        <div><button class="<?= $slotBtn ?>" type="button" disabled>10:30</button></div>
        <div><button class="<?= $slotBtn ?>" type="button" disabled>14:00</button></div>
    </div>

    <button class="<?= $mainBtn ?>" type="button"<?= $accentStyle ?>><?= $btnText ?></button>

</div>
