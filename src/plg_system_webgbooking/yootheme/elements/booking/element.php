<?php

/**
 * WebG Booking — YOOtheme Pro builder element.
 * Element API verified against YOOtheme Pro 5.0.35 native elements.
 *
 * @license GNU General Public License version 2 or later
 */

namespace YOOtheme;

return [
    'name' => 'wgb_booking',
    'title' => 'Booking',
    'group' => 'WebG',
    'icon' => '${url:images/cal.svg}',
    'iconSmall' => '${url:images/cal-sm.svg}',
    'element' => true,
    'width' => 400,

    'defaults' => [
        'title' => 'Book an appointment',
        'button_text' => 'Check availability',
        'layout' => 'inline',
        'card_style' => 'card',
        'slot_columns' => '3',
        'slot_style' => 'default',
        'slot_size' => '',
        'button_style' => 'primary',
        'button_size' => '',
        'margin_top' => 'default',
        'margin_bottom' => 'default',
    ],

    'templates' => [
        'render' => __DIR__ . '/templates/template.php',
        'content' => __DIR__ . '/templates/content.php',
    ],

    'fields' => [
        // --- Content ---
        'title' => ['label' => 'Title', 'type' => 'text'],
        'service' => [
            'label' => 'Service / Booking Type',
            'description' => 'Tracer placeholder. Will become a select bound to com_webgbooking services.',
            'type' => 'text',
        ],
        'button_text' => ['label' => 'Button Text', 'type' => 'text'],
        'layout' => [
            'label' => 'Layout',
            'type' => 'select',
            'options' => ['Inline' => 'inline', 'Popup' => 'popup', 'Slide-in' => 'slidein'],
        ],

        // --- Settings: Container ---
        'card_style' => [
            'label' => 'Container Style',
            'type' => 'select',
            'options' => [
                'Card (default)' => 'card',
                'Card primary' => 'primary',
                'Card secondary' => 'secondary',
                'Plain (no card)' => 'plain',
            ],
        ],
        'accent_color' => [
            'label' => 'Accent Color',
            'description' => 'Highlights the selected day and time. Leave empty to use the theme default.',
            'type' => 'color',
        ],
        'cal_style' => [
            'label' => 'Calendar Style',
            'type' => 'select',
            'options' => ['Calendly (rounded)' => 'calendly', 'Plain (bordered)' => 'plain'],
        ],
        'cal_density' => [
            'label' => 'Calendar Density',
            'type' => 'select',
            'options' => ['Compact' => 'compact', 'Comfortable' => 'comfortable'],
        ],
        'header_color' => [
            'label' => 'Weekday Header Color',
            'description' => 'Colour of the weekday column headers. Empty = theme muted colour.',
            'type' => 'color',
        ],

        // --- Settings: Slots ---
        'slot_columns' => [
            'label' => 'Slot Columns',
            'type' => 'select',
            'options' => ['2' => '2', '3' => '3', '4' => '4'],
        ],
        'slot_style' => [
            'label' => 'Slot Button Style',
            'type' => 'select',
            'options' => ['Default' => 'default', 'Primary' => 'primary', 'Secondary' => 'secondary'],
        ],
        'slot_size' => [
            'label' => 'Slot Size',
            'type' => 'select',
            'options' => ['Small' => 'small', 'Default' => '', 'Large' => 'large'],
        ],

        // --- Settings: Button ---
        'button_style' => [
            'label' => 'Button Style',
            'type' => 'select',
            'options' => ['Default' => 'default', 'Primary' => 'primary', 'Secondary' => 'secondary'],
        ],
        'button_size' => [
            'label' => 'Button Size',
            'type' => 'select',
            'options' => ['Small' => 'small', 'Default' => '', 'Large' => 'large'],
        ],
        'button_fullwidth' => ['type' => 'checkbox', 'text' => 'Full width button'],

        // --- Inherited builder fields (native parity: responsive + advanced) ---
        'margin_top' => '${builder.margin_top}',
        'margin_bottom' => '${builder.margin_bottom}',
        'maxwidth' => '${builder.maxwidth}',
        'maxwidth_breakpoint' => '${builder.maxwidth_breakpoint}',
        'block_align' => '${builder.block_align}',
        'block_align_breakpoint' => '${builder.block_align_breakpoint}',
        'block_align_fallback' => '${builder.block_align_fallback}',
        'animation' => '${builder.animation}',
        'visibility' => '${builder.visibility}',
        'name' => '${builder.name}',
        'status' => '${builder.status}',
        'source' => '${builder.source}',
        'id' => '${builder.id}',
        'class' => '${builder.cls}',
        'attributes' => '${builder.attrs}',
        'css' => [
            'label' => 'CSS',
            'description' => 'Custom CSS. Selector prefixed automatically: <code>.el-element</code>.',
            'type' => 'editor',
            'editor' => 'code',
            'mode' => 'css',
            'attrs' => ['debounce' => 500, 'hints' => ['.el-element']],
            'source' => true,
        ],
        'transform' => '${builder.transform}',
    ],

    'fieldset' => [
        'default' => [
            'type' => 'tabs',
            'fields' => [
                [
                    'title' => 'Content',
                    'fields' => ['title', 'service', 'button_text', 'layout'],
                ],
                [
                    'title' => 'Settings',
                    'fields' => [
                        [
                            'label' => 'Container',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['card_style', 'accent_color'],
                        ],
                        [
                            'label' => 'Calendar',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['cal_style', 'cal_density', 'header_color'],
                        ],
                        [
                            'label' => 'Slots',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['slot_columns', 'slot_style', 'slot_size'],
                        ],
                        [
                            'label' => 'Button',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['button_style', 'button_size', 'button_fullwidth'],
                        ],
                        [
                            'label' => 'General',
                            'type' => 'group',
                            'fields' => [
                                'margin_top',
                                'margin_bottom',
                                'maxwidth',
                                'maxwidth_breakpoint',
                                'block_align',
                                'block_align_breakpoint',
                                'block_align_fallback',
                                'animation',
                                'visibility',
                            ],
                        ],
                    ],
                ],
                '${builder.advanced}',
            ],
        ],
    ],
];
