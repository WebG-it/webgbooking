<?php

/**
 * WebG Booking element — render template (UIkit markup).
 * Helpers/vars per YOOtheme contract: $this->el(), $props, $attrs, $children, $builder.
 *
 * @license GNU General Public License version 2 or later
 */

// Root element via the YOOtheme helper (merges class/attrs, applies responsive syntax).
$el = $this->el('div', [
    'class' => [
        'wgb-booking',
        'uk-card uk-card-default uk-card-body',
    ],
]);

// Escape all admin-entered text before output (defence in depth, see docs/04-security.md).
$title  = htmlspecialchars((string) ($props['title'] ?? ''), ENT_QUOTES, 'UTF-8');
$service = htmlspecialchars((string) ($props['service'] ?? ''), ENT_QUOTES, 'UTF-8');
$btnText = htmlspecialchars((string) ($props['button_text'] ?: 'Check availability'), ENT_QUOTES, 'UTF-8');
$btnStyle = preg_replace('/[^a-z]/', '', (string) ($props['button_style'] ?: 'primary'));

?>
<?= $el($props, $attrs) ?>

    <?php if ($title !== '') : ?>
    <h3 class="uk-card-title uk-margin-remove-bottom"><?= $title ?></h3>
    <?php endif ?>

    <?php if ($service !== '') : ?>
    <div class="uk-text-meta uk-margin-small-bottom"><?= $service ?></div>
    <?php endif ?>

    <!-- Tracer placeholder: static slots. The real widget is mounted by media/com_webgbooking JS. -->
    <div class="uk-grid-small uk-child-width-1-3@s uk-margin-small" uk-grid>
        <div><button class="uk-button uk-button-default uk-width-1-1" type="button" disabled>09:00</button></div>
        <div><button class="uk-button uk-button-default uk-width-1-1" type="button" disabled>10:30</button></div>
        <div><button class="uk-button uk-button-default uk-width-1-1" type="button" disabled>14:00</button></div>
    </div>

    <button class="uk-button uk-button-<?= $btnStyle ?>" type="button"><?= $btnText ?></button>

</div>
