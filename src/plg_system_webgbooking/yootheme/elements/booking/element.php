<?php

/**
 * WebG Booking — YOOtheme Pro builder element.
 * Element API verified against YOOtheme Pro 5.0.35 native elements.
 *
 * @license GNU General Public License version 2 or later
 */

namespace YOOtheme;

\defined('_JEXEC') or die;

return [
    'name' => 'wgb_booking',
    'title' => 'Booking',
    'group' => 'WebG',
    'icon' => '${url:images/cal.svg}',
    'iconSmall' => '${url:images/cal-sm.svg}',
    'element' => true,
    'width' => 400,

    'defaults' => [
        // Left empty on purpose: the render template falls back to the TRANSLATED
        // default title/button (PLG_SYSTEM_WEBGBOOKING_DEFAULT_*), so the front end is i18n-correct.
        'title' => '',
        'button_text' => '',
        'allow_guest' => true,
        'event_location' => 'meet',
        'act_reminder_on' => true,
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
        'title' => ['label' => 'Title', 'type' => 'text', 'source' => true],
        'service' => [
            'label' => 'Service / Booking Type',
            'description' => 'Free text or bind to a Joomla field via Dynamic Content.',
            'type' => 'text',
            'source' => true,
        ],
        'button_text' => ['label' => 'Button Text', 'type' => 'text', 'source' => true],
        'allow_guest' => [
            'label' => 'Invite a guest',
            'description' => 'Shows an optional "guest email" field so the visitor can invite someone to the meeting.',
            'type' => 'checkbox',
            'text' => 'Allow inviting a guest',
        ],
        // --- Event type (per element, signed server-side via HMAC) ---
        'event_duration' => [
            'label' => 'Duration (minutes)',
            'description' => 'Length of this appointment type, e.g. 30 or 60. Empty = the default duration in the plugin options. Availability and the Google event use this value.',
            'type' => 'text',
        ],
        'event_buffer_before' => [
            'label' => 'Buffer before (minutes)',
            'description' => 'Free time kept before the appointment. Empty = the plugin option.',
            'type' => 'text',
        ],
        'event_buffer_after' => [
            'label' => 'Buffer after (minutes)',
            'description' => 'Free time kept after the appointment. Empty = the plugin option.',
            'type' => 'text',
        ],
        'event_location' => [
            'label' => 'Where',
            'description' => 'Google Meet: a Meet link is created. Phone call: the visitor must give a phone number and you call them (no link; the number goes in the event title, the emails and the .ics). Visitor choice: the widget lets them pick one of the two.',
            'type' => 'select',
            'options' => ['Google Meet' => 'meet', 'Phone call (I call the visitor)' => 'phone', 'Visitor choice' => 'choice'],
        ],
        'redirect_url' => [
            'label' => 'Thank-you page URL',
            'description' => 'Optional. After a completed booking the visitor is taken to this page (the analytics event is sent first). Empty = the confirmation stays in the widget. Absolute https:// URL or a path like /thanks.',
            'type' => 'text',
        ],

        // --- ACTIONS (per element, signed server-side via HMAC, not client-tamperable) ---
        'act_customer_on' => ['label' => 'Customer email', 'type' => 'checkbox', 'text' => 'Send the confirmation email to the customer', 'default' => true],
        'email_subject' => [
            'label' => 'Subject',
            'description' => 'Placeholders: {name} {date} {time}. Empty = default.',
            'type' => 'text',
            'source' => true,
            'enable' => 'act_customer_on',
        ],
        'email_body' => [
            'label' => 'Body (HTML)',
            'description' => 'Placeholders: {name} {date} {time} {phone} {email} {notes} {custom} {location} {duration} {event} {meet_url} {cancel_url} {meet_block} {cancel_block}. Empty = styled default.',
            'type' => 'editor',
            'mode' => 'text/html',
            'source' => true,
            'enable' => 'act_customer_on',
            'default' => "<p>Ciao {name},</p><p>il tuo appuntamento è confermato per il <strong>{date}</strong> alle <strong>{time}</strong>.</p>{meet_block}<p style='font-size:13px;color:#6a6a6a'>In allegato trovi il file .ics per aggiungerlo al tuo calendario.</p>{cancel_block}<p>A presto!</p>",
        ],
        'act_staff_on' => ['label' => 'Staff email', 'type' => 'checkbox', 'text' => 'Send a notification email to the staff', 'default' => true],
        'staff_to' => [
            'label' => 'Staff recipient(s)',
            'description' => 'One or more addresses — separate multiple recipients with a comma (es: a@x.it, b@x.it). Empty = the plugin Notify e-mail / site sender.',
            'type' => 'text',
            'source' => true,
            'enable' => 'act_staff_on',
        ],
        'staff_subject' => ['label' => 'Staff subject', 'type' => 'text', 'source' => true, 'enable' => 'act_staff_on'],
        'staff_body' => [
            'label' => 'Staff body (HTML)',
            'description' => 'Placeholders: {name} {email} {phone} {date} {time} {notes} {custom} {location} {duration} {event}.',
            'type' => 'editor',
            'mode' => 'text/html',
            'source' => true,
            'enable' => 'act_staff_on',
            'default' => "<p>Nuova prenotazione: <strong>{date}</strong> alle <strong>{time}</strong>.</p><p>Nome: {name}<br>Email: {email}<br>Telefono: {phone}</p><p>Note: {notes}</p>{custom}",
        ],
        'act_reminder_on' => ['label' => 'Reminders', 'type' => 'checkbox', 'text' => 'Send reminder emails to the customer before the appointment', 'default' => true],
        'reminder_times' => [
            'label' => 'Reminder times',
            'description' => 'How long before the appointment, comma separated: <code>24h, 1h</code> (also 30m, 2d; a bare number = hours). Empty = the plugin options (default 24h, 1h).',
            'type' => 'text',
            'enable' => 'act_reminder_on',
        ],
        'reminder_subject' => [
            'label' => 'Reminder subject',
            'description' => 'Placeholders: {name} {date} {time}. Empty = default.',
            'type' => 'text',
            'enable' => 'act_reminder_on',
        ],
        'reminder_body' => [
            'label' => 'Reminder body (HTML)',
            'description' => 'Placeholders: {name} {date} {time} {phone} {email} {notes} {custom} {location} {duration} {event} {meet_url} {cancel_url} {meet_block} {cancel_block}. {meet_block} shows the Meet button or the "we call you" line. Empty = styled default.',
            'type' => 'editor',
            'mode' => 'text/html',
            'enable' => 'act_reminder_on',
        ],
        'act_webhook_on' => ['label' => 'Webhook', 'type' => 'checkbox', 'text' => 'POST the booking to a webhook (Zapier / Make / Sheets)'],
        'webhook_url' => [
            'label' => 'Webhook URL',
            'description' => 'Receives the booking. Empty = the plugin Bookings webhook.',
            'type' => 'text',
            'enable' => 'act_webhook_on',
        ],
        'webhook_method' => [
            'label' => 'Method',
            'description' => 'POST sends a JSON body; GET appends the data to the query string.',
            'type' => 'select',
            'options' => ['POST (JSON body)' => 'POST', 'GET (query string)' => 'GET'],
            'enable' => 'act_webhook_on',
        ],
        'analytics_event' => [
            'label' => 'Analytics event',
            'description' => 'Event pushed to dataLayer / gtag on a completed booking (GA4 / GTM). Empty = "wgb_booking_completed".',
            'type' => 'text',
        ],

        // --- Custom form questions (defined here, signed server-side via HMAC, not client-tamperable) ---
        'custom_fields' => [
            'label' => 'Custom questions',
            'description' => 'One question per line: <code>Label | type | required | options</code>.'
                . ' Types: text, textarea, email, tel, number, select, checkbox.'
                . ' Put <code>required</code> in the 3rd slot to make it mandatory; for <code>select</code> list the choices comma-separated in the 4th slot.'
                . ' Example:<br><code>Company | text</code><br><code>Participants | number | required</code>'
                . '<br><code>How did you hear about us? | select | | Google, Word of mouth, Social</code>',
            'type' => 'editor',
            'editor' => 'code',
            'mode' => 'text/html',
            'attrs' => ['debounce' => 500],
        ],
        'show_newsletter' => [
            'label' => 'Newsletter opt-in',
            'description' => 'Shows a newsletter consent checkbox. On consent the contact is forwarded to the webhook set in the plugin options (MailUp / Zapier / Make).',
            'type' => 'checkbox',
            'text' => 'Show newsletter opt-in',
        ],
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
                    'fields' => ['title', 'service', 'button_text', 'allow_guest', 'show_newsletter', 'analytics_event', 'layout'],
                ],
                [
                    'title' => 'Event',
                    'fields' => ['event_duration', 'event_buffer_before', 'event_buffer_after', 'event_location', 'redirect_url'],
                ],
                [
                    'title' => 'Form',
                    'fields' => ['custom_fields'],
                ],
                [
                    'title' => 'Actions',
                    'fields' => [
                        [
                            'label' => 'Customer email',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_customer_on', 'email_subject', 'email_body'],
                        ],
                        [
                            'label' => 'Staff email',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_staff_on', 'staff_to', 'staff_subject', 'staff_body'],
                        ],
                        [
                            'label' => 'Reminders',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_reminder_on', 'reminder_times', 'reminder_subject', 'reminder_body'],
                        ],
                        [
                            'label' => 'Webhook',
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_webhook_on', 'webhook_url', 'webhook_method'],
                        ],
                    ],
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
