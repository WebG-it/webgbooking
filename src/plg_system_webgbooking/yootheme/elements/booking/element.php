<?php

/**
 * WebG Booking — YOOtheme Pro builder element (tracer).
 * Element API verified against YOOtheme Pro 5.0.35 native `button` element.
 *
 * @license GNU General Public License version 2 or later
 */

namespace YOOtheme;

return [
    'name' => 'wgb_booking',
    'title' => 'Booking',
    'group' => 'WebG',
    'icon' => '${url:images/icon.svg}',
    'iconSmall' => '${url:images/iconSmall.svg}',
    'element' => true,
    'width' => 400,

    'defaults' => [
        'title' => 'Book an appointment',
        'button_text' => 'Check availability',
        'layout' => 'inline',
        'button_style' => 'primary',
        'margin_top' => 'default',
        'margin_bottom' => 'default',
    ],

    'templates' => [
        'render' => __DIR__ . '/templates/template.php',
        'content' => __DIR__ . '/templates/content.php',
    ],

    'fields' => [
        // --- Content ---
        'title' => [
            'label' => 'Title',
            'type' => 'text',
        ],
        'service' => [
            'label' => 'Service / Booking type',
            'description' => 'Tracer placeholder. Will become a select bound to com_webgbooking services.',
            'type' => 'text',
        ],
        'button_text' => [
            'label' => 'Button Text',
            'type' => 'text',
        ],
        'layout' => [
            'label' => 'Layout',
            'type' => 'select',
            'options' => [
                'Inline' => 'inline',
                'Popup' => 'popup',
                'Slide-in' => 'slidein',
            ],
        ],

        // --- Settings ---
        'button_style' => [
            'label' => 'Button Style',
            'type' => 'select',
            'options' => [
                'Default' => 'default',
                'Primary' => 'primary',
                'Secondary' => 'secondary',
            ],
        ],

        // --- Inherited builder fields (native parity, responsive + advanced) ---
        'margin_top' => '${builder.margin_top}',
        'margin_bottom' => '${builder.margin_bottom}',
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
            'description' =>
                'Custom CSS. These selectors are prefixed automatically for this element: <code>.el-element</code>.',
            'type' => 'editor',
            'editor' => 'code',
            'mode' => 'css',
            'attrs' => [
                'debounce' => 500,
                'hints' => ['.el-element'],
            ],
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
                            'label' => 'Booking',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['button_style'],
                        ],
                        [
                            'label' => 'General',
                            'type' => 'group',
                            'fields' => ['margin_top', 'margin_bottom', 'animation', 'visibility'],
                        ],
                    ],
                ],
                '${builder.advanced}',
            ],
        ],
    ],
];
