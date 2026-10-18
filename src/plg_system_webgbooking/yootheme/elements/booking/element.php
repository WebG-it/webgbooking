<?php

/**
 * WebG Booking — YOOtheme Pro builder element.
 * Element API verified against YOOtheme Pro 5.0.35 native elements.
 *
 * @license GNU General Public License version 2 or later
 */

namespace YOOtheme;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

// The builder shows these labels in the language of the administrator: they come from the plugin language files.
try {
    \Joomla\CMS\Factory::getApplication()->getLanguage()->load('plg_system_webgbooking', JPATH_PLUGINS . '/system/webgbooking');
} catch (\Throwable $e) {
}

return [
    'name' => 'wgb_booking',
    'title' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BOOKING'),
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
        'title' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_TITLE'), 'type' => 'text', 'source' => true],
        'service' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SERVICE_BOOKING_TYPE'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_FREE_TEXT_OR_BIND'),
            'type' => 'text',
            'source' => true,
        ],
        'button_text' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BUTTON_TEXT'), 'type' => 'text', 'source' => true],
        'allow_guest' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_INVITE_A_GUEST'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SHOWS_AN_OPTIONAL_GUEST'),
            'type' => 'checkbox',
            'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ALLOW_INVITING_A_GUEST'),
        ],
        // --- Event type (per element, signed server-side via HMAC) ---
        'event_duration' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_DURATION_MINUTES'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_LENGTH_OF_THIS_APPOINTMENT'),
            'type' => 'text',
        ],
        'event_buffer_before' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BUFFER_BEFORE_MINUTES'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_FREE_TIME_KEPT_BEFORE'),
            'type' => 'text',
        ],
        'event_buffer_after' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BUFFER_AFTER_MINUTES'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_FREE_TIME_KEPT_AFTER'),
            'type' => 'text',
        ],
        'event_location' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_WHERE'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_GOOGLE_MEET_A_MEET'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_GOOGLE_MEET') => 'meet', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PHONE_CALL_I_CALL') => 'phone', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_VISITOR_CHOICE') => 'choice'],
        ],
        'avail_start' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_AVAILABLE_FROM_HH_MM'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_OPTIONAL_FOR_THIS_APPOINTMENT'),
            'type' => 'text',
        ],
        'avail_end' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_AVAILABLE_UNTIL_HH_MM'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_OPTIONAL_E_G_19'),
            'type' => 'text',
        ],
        'avail_days' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_DAYS_OF_THE_WEEK'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_OPTIONAL_NUMBERS_SEPARATED_BY'),
            'type' => 'text',
        ],
        'avail_notice' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_MINIMUM_NOTICE_HOURS'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_OPTIONAL_EMPTY_THE_PLUGIN'),
            'type' => 'text',
        ],
        'avail_window' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BOOKING_WINDOW_DAYS_AHEAD'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_OPTIONAL_EMPTY_THE_PLUGIN'),
            'type' => 'text',
        ],
        'price_amount' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PRICE'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PAID_EVENT_THE_AMOUNT'),
            'type' => 'text',
        ],
        'price_currency' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CURRENCY'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_EUR_USD_GBP_OR'),
            'type' => 'text',
        ],
        'price_label' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CHARGE_DESCRIPTION'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_LINE_SHOWN_ON_THE'),
            'type' => 'text',
        ],
        'redirect_url' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_THANK_YOU_PAGE_URL'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_OPTIONAL_AFTER_A_COMPLETED'),
            'type' => 'text',
        ],

        'confirm_manual' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_MANUAL_CONFIRMATION'),
            'type' => 'checkbox',
            'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_I_CONFIRM_EACH_BOOKING'),
            'default' => false,
        ],

        // --- ACTIONS (per element, signed server-side via HMAC, not client-tamperable) ---
        'act_customer_on' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CUSTOMER_EMAIL'), 'type' => 'checkbox', 'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SEND_THE_CONFIRMATION_EMAIL'), 'default' => true],
        'email_subject' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SUBJECT'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PLACEHOLDERS_NAME_DATE_TIME'),
            'type' => 'text',
            'source' => true,
            'enable' => 'act_customer_on',
        ],
        'email_body' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BODY_HTML'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PLACEHOLDERS_NAME_DATE_TIME_2'),
            'type' => 'editor',
            'mode' => 'text/html',
            'source' => true,
            'enable' => 'act_customer_on',
        ],
        'act_staff_on' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_STAFF_EMAIL'), 'type' => 'checkbox', 'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SEND_A_NOTIFICATION_EMAIL'), 'default' => true],
        'staff_to' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_STAFF_RECIPIENT_S'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ONE_OR_MORE_ADDRESSES'),
            'type' => 'text',
            'source' => true,
            'enable' => 'act_staff_on',
        ],
        'staff_subject' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_STAFF_SUBJECT'), 'type' => 'text', 'source' => true, 'enable' => 'act_staff_on'],
        'staff_body' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_STAFF_BODY_HTML'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PLACEHOLDERS_NAME_EMAIL_PHONE'),
            'type' => 'editor',
            'mode' => 'text/html',
            'source' => true,
            'enable' => 'act_staff_on',
        ],
        'act_reminder_on' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_REMINDERS'), 'type' => 'checkbox', 'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SEND_REMINDER_EMAILS_TO'), 'default' => true],
        'reminder_times' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_REMINDER_TIMES'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_HOW_LONG_BEFORE_THE'),
            'type' => 'text',
            'enable' => 'act_reminder_on',
        ],
        'reminder_subject' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_REMINDER_SUBJECT'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PLACEHOLDERS_NAME_DATE_TIME'),
            'type' => 'text',
            'enable' => 'act_reminder_on',
        ],
        'reminder_body' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_REMINDER_BODY_HTML'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PLACEHOLDERS_NAME_DATE_TIME_3'),
            'type' => 'editor',
            'mode' => 'text/html',
            'enable' => 'act_reminder_on',
        ],
        'act_webhook_on' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_WEBHOOK'), 'type' => 'checkbox', 'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_POST_THE_BOOKING_TO')],
        'webhook_url' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_WEBHOOK_URL'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_RECEIVES_THE_BOOKING_EMPTY'),
            'type' => 'text',
            'enable' => 'act_webhook_on',
        ],
        'webhook_method' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_METHOD'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_POST_SENDS_A_JSON'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_POST_JSON_BODY') => 'POST', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_GET_QUERY_STRING') => 'GET'],
            'enable' => 'act_webhook_on',
        ],
        'webhook_profile' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_HEADER_PROFILE'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_OPTIONAL_THE_NAME_OF'),
            'type' => 'text',
            'enable' => 'act_webhook_on',
        ],
        'act_brevo_on' => ['label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BREVO'), 'type' => 'checkbox', 'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ADD_THE_BOOKER_TO')],
        'brevo_lists' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BREVO_LIST_ID_S'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_COMMA_SEPARATED_NUMERIC_IDS'),
            'type' => 'text',
            'enable' => 'act_brevo_on',
        ],
        'brevo_when' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_WHEN'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_NEWSLETTER_TICK_THE_CONTACT'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ONLY_WITH_THE_NEWSLETTER') => 'newsletter', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_EVERY_BOOKING_CONTACT_ONLY') => 'always'],
            'default' => 'newsletter',
            'enable' => 'act_brevo_on',
        ],
        'brevo_attrs' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_EXTRA_ATTRIBUTES'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ONE_PER_LINE_BREVO'),
            'type' => 'textarea',
            'attrs' => ['rows' => 3],
            'enable' => 'act_brevo_on',
        ],
        'brevo_doi_template' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_DOUBLE_OPT_IN_TEMPLATE'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_REQUIRED_TO_ADD_ANYONE'),
            'type' => 'text',
            'enable' => 'act_brevo_on',
        ],
        'brevo_doi_redirect' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_AFTER_CONFIRMATION_GO_TO'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ABSOLUTE_HTTPS_URL_EMPTY'),
            'type' => 'text',
            'enable' => 'act_brevo_on',
        ],
        'analytics_event' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ANALYTICS_EVENT'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_EVENT_PUSHED_TO_DATALAYER'),
            'type' => 'text',
        ],

        // --- Custom form questions (defined here, signed server-side via HMAC, not client-tamperable) ---
        'custom_fields' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CUSTOM_QUESTIONS'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CUSTOM_QUESTIONS_HELP'),
            'type' => 'editor',
            'editor' => 'code',
            'mode' => 'text/html',
            'attrs' => ['debounce' => 500],
        ],
        'show_newsletter' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_NEWSLETTER_OPT_IN'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SHOWS_A_NEWSLETTER_CONSENT'),
            'type' => 'checkbox',
            'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SHOW_NEWSLETTER_OPT_IN'),
        ],
        'layout' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_LAYOUT'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_INLINE') => 'inline', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_POPUP') => 'popup', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SLIDE_IN') => 'slidein'],
        ],

        // --- Settings: Container ---
        'card_style' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CONTAINER_STYLE'),
            'type' => 'select',
            'options' => [
                Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CARD_DEFAULT') => 'card',
                Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CARD_PRIMARY') => 'primary',
                Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CARD_SECONDARY') => 'secondary',
                Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PLAIN_NO_CARD') => 'plain',
            ],
        ],
        'accent_color' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ACCENT_COLOR'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_HIGHLIGHTS_THE_SELECTED_DAY'),
            'type' => 'color',
        ],
        'cal_style' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CALENDAR_STYLE'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CALENDLY_ROUNDED') => 'calendly', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PLAIN_BORDERED') => 'plain'],
        ],
        'cal_density' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CALENDAR_DENSITY'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_COMPACT') => 'compact', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_COMFORTABLE') => 'comfortable'],
        ],
        'header_color' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_WEEKDAY_HEADER_COLOR'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_COLOUR_OF_THE_WEEKDAY'),
            'type' => 'color',
        ],

        // --- Settings: Slots ---
        'slot_columns' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SLOT_COLUMNS'),
            'type' => 'select',
            'options' => ['2' => '2', '3' => '3', '4' => '4'],
        ],
        'slot_style' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SLOT_BUTTON_STYLE'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_DEFAULT') => 'default', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PRIMARY') => 'primary', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SECONDARY') => 'secondary'],
        ],
        'slot_size' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SLOT_SIZE'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SMALL') => 'small', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_DEFAULT') => '', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_LARGE') => 'large'],
        ],

        // --- Settings: Button ---
        'button_style' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BUTTON_STYLE'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_DEFAULT') => 'default', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_PRIMARY') => 'primary', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SECONDARY') => 'secondary'],
        ],
        'button_size' => [
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BUTTON_SIZE'),
            'type' => 'select',
            'options' => [Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SMALL') => 'small', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_DEFAULT') => '', Text::_('PLG_SYSTEM_WEBGBOOKING_EL_LARGE') => 'large'],
        ],
        'button_fullwidth' => ['type' => 'checkbox', 'text' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_FULL_WIDTH_BUTTON')],

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
            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CSS'),
            'description' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CUSTOM_CSS_SELECTOR_PREFIXED'),
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
                    'title' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CONTENT'),
                    'fields' => ['title', 'service', 'button_text', 'allow_guest', 'show_newsletter', 'analytics_event', 'layout'],
                ],
                [
                    'title' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_EVENT'),
                    'fields' => ['event_duration', 'event_buffer_before', 'event_buffer_after', 'event_location', 'avail_start', 'avail_end', 'avail_days', 'avail_notice', 'avail_window', 'price_amount', 'price_currency', 'price_label', 'redirect_url'],
                ],
                [
                    'title' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_FORM'),
                    'fields' => ['custom_fields'],
                ],
                [
                    'title' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_ACTIONS'),
                    'fields' => [
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CUSTOMER_EMAIL'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['confirm_manual', 'act_customer_on', 'email_subject', 'email_body'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_STAFF_EMAIL'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_staff_on', 'staff_to', 'staff_subject', 'staff_body'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_REMINDERS'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_reminder_on', 'reminder_times', 'reminder_subject', 'reminder_body'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_WEBHOOK'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_webhook_on', 'webhook_url', 'webhook_method', 'webhook_profile'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BREVO'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['act_brevo_on', 'brevo_lists', 'brevo_when', 'brevo_attrs', 'brevo_doi_template', 'brevo_doi_redirect'],
                        ],
                    ],
                ],
                [
                    'title' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SETTINGS'),
                    'fields' => [
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CONTAINER'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['card_style', 'accent_color'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_CALENDAR'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['cal_style', 'cal_density', 'header_color'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_SLOTS'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['slot_columns', 'slot_style', 'slot_size'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_BUTTON'),
                            'type' => 'group',
                            'divider' => true,
                            'fields' => ['button_style', 'button_size', 'button_fullwidth'],
                        ],
                        [
                            'label' => Text::_('PLG_SYSTEM_WEBGBOOKING_EL_GENERAL'),
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
