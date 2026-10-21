<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The emails of WebG Booking and what can be changed in each, without code. Every email has a ready-made text (subject, title, a body written
 * with the visual editor, a closing line, the words of the buttons) in the two languages of the product; what the site changes is stored
 * in the table #__webgbooking_emailtexts, ONLY where it differs from the ready-made text, so a text nobody touched follows the new
 * versions by itself. "Restore the default text" simply deletes the stored line.
 *
 * For developers (no way is ever needed to change the product's files):
 *  - the layout of every email is a Joomla layout, overridable from the template: templates/<template>/html/layouts/webgbooking/email/default.php
 *    (html, see plugins/system/webgbooking/layouts/webgbooking/email/default.php) and .../email/text.php (plain text);
 *  - every default string is a language string, replaceable with the Joomla language overrides (Languages, Overrides).
 */

namespace WebG\Plugin\System\Webgbooking\Mail;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;

final class EmailTemplates
{
    private const P = 'PLG_SYSTEM_WEBGBOOKING_';

    /**
     * key => [group, fields, blocks]. The fields are what the editor shows for that email; the blocks are the {tokens} that make sense in it
     * (they are listed in the "Insert placeholder" menu; a block the email has no data for simply shows nothing).
     */
    public const CATALOG = [
        'customer_confirmed'       => ['customer', ['subject', 'title', 'text', 'signoff', 'lbl_meet', 'lbl_calendar', 'lbl_change', 'lbl_reschedule', 'lbl_cancel'], ['appointment_box', 'answers_block', 'meet_button', 'calendar_link', 'change_links', 'payment_block', 'notices']],
        'customer_request'         => ['customer', ['subject', 'title', 'text', 'signoff', 'lbl_meet', 'lbl_calendar', 'lbl_change', 'lbl_reschedule', 'lbl_cancel'], ['appointment_box', 'answers_block', 'meet_button', 'calendar_link', 'change_links', 'payment_block', 'notices']],
        'customer_rescheduled'     => ['customer', ['subject', 'title', 'text', 'signoff', 'lbl_meet', 'lbl_calendar', 'lbl_change', 'lbl_reschedule', 'lbl_cancel'], ['appointment_box', 'answers_block', 'meet_button', 'calendar_link', 'change_links', 'payment_block', 'notices']],
        'customer_reminder'        => ['customer', ['subject', 'title', 'text', 'signoff', 'lbl_meet', 'lbl_change', 'lbl_reschedule', 'lbl_cancel'], ['appointment_box', 'answers_block', 'meet_button', 'change_links', 'notices']],
        'customer_link'            => ['customer', ['subject', 'title', 'text', 'signoff', 'lbl_meet'], ['appointment_box', 'meet_button']],
        'customer_cancelled'       => ['customer', ['subject', 'title', 'text', 'signoff', 'lbl_rebook'], ['appointment_box', 'rebook_button', 'notices']],
        'customer_cancelled_staff' => ['customer', ['subject', 'title', 'text', 'signoff', 'lbl_rebook'], ['appointment_box', 'rebook_button', 'notices']],
        'customer_late_gone'       => ['customer', ['subject', 'title', 'text', 'lbl_rebook'], ['appointment_box', 'rebook_button', 'payment_block']],
        'customer_late_check'      => ['customer', ['subject', 'title', 'text'], ['appointment_box', 'payment_block']],
        'guest_invite'             => ['guest', ['subject', 'title', 'text', 'signoff', 'lbl_meet', 'lbl_calendar'], ['appointment_box', 'meet_button', 'calendar_link']],
        'guest_reminder'           => ['guest', ['subject', 'title', 'text', 'signoff', 'lbl_meet'], ['appointment_box', 'meet_button']],
        'staff_new'                => ['staff', ['subject', 'title', 'text', 'lbl_backend'], ['appointment_box', 'answers_block', 'backend_button', 'notices']],
        'staff_moved'              => ['staff', ['subject', 'title', 'text', 'lbl_backend'], ['appointment_box', 'answers_block', 'backend_button', 'notices']],
        'staff_cancelled'          => ['staff', ['subject', 'title', 'text', 'lbl_backend'], ['appointment_box', 'answers_block', 'backend_button', 'notices']],
        'staff_cancelled_admin'    => ['staff', ['subject', 'title', 'text', 'lbl_backend'], ['appointment_box', 'answers_block', 'backend_button', 'notices']],
        'staff_confirmed_admin'    => ['staff', ['subject', 'title', 'text', 'lbl_backend'], ['appointment_box', 'answers_block', 'backend_button', 'notices']],
    ];

    /** The words of the buttons and of the closing line: the language string each one starts from. */
    private const LABELS = [
        'signoff'        => 'EMAIL_SIGNOFF',
        'lbl_meet'       => 'EMAIL_MEET_BTN',
        'lbl_calendar'   => 'MAIL_BTN_CALENDAR',
        'lbl_change'     => 'CHANGE_Q',
        'lbl_reschedule' => 'BTN_RESCHEDULE',
        'lbl_cancel'     => 'BTN_CANCEL',
        'lbl_backend'    => 'MAIL_BTN_BACKEND',
        'lbl_rebook'     => 'MAIL_BTN_REBOOK',
    ];

    /** Text placeholders (replaced by a value) and block placeholders (replaced by a formatted block). */
    public const TEXT_PLACEHOLDERS  = ['name', 'email', 'phone', 'event', 'duration', 'date', 'day', 'time', 'when', 'timezone', 'short_when', 'host', 'with_host', 'location', 'meet_url', 'manage_url', 'reschedule_url', 'cancel_url', 'notes', 'answers', 'guest', 'amount', 'business', 'booking_id', 'backend_url', 'prev_date', 'prev_time'];
    public const BLOCK_PLACEHOLDERS = ['appointment_box', 'answers_block', 'meet_button', 'calendar_link', 'change_links', 'payment_block', 'notices', 'backend_button', 'rebook_button'];

    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function exists(string $key): bool
    {
        return isset(self::CATALOG[$key]);
    }

    public static function group(string $key): string
    {
        return self::CATALOG[$key][0] ?? 'customer';
    }

    /** The fields the editor shows for an email, in order. */
    public static function fields(string $key): array
    {
        return self::CATALOG[$key][1] ?? [];
    }

    public static function blocks(string $key): array
    {
        return self::CATALOG[$key][2] ?? [];
    }

    /** Which fields are one-line text (the others, the body, use the visual editor). */
    public static function isRich(string $field): bool
    {
        return $field === 'text';
    }

    /** A language object for a tag with the plugin strings (and the site's language overrides) loaded: so the defaults can be shown in any language. */
    public static function language(string $tag): Language
    {
        static $cache = [];
        if (!isset($cache[$tag])) {
            $lang = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag, false);
            $lang->load('plg_system_webgbooking', JPATH_ADMINISTRATOR);
            $lang->load('plg_system_webgbooking', JPATH_PLUGINS . '/system/webgbooking');
            $cache[$tag] = $lang;
        }

        return $cache[$tag];
    }

    /** The ready-made texts of one email in one language: field => text. */
    public static function defaults(string $key, ?string $tag = null): array
    {
        $lang = $tag !== null ? self::language($tag) : null;
        $get  = static fn(string $k): string => $lang ? $lang->_(self::P . $k) : Text::_(self::P . $k);
        $up   = strtoupper($key);
        $out  = [];
        foreach (self::fields($key) as $f) {
            if (isset(self::LABELS[$f])) {
                // the late-payment email invites to choose another time: its own wording
                $out[$f] = $get($key === 'customer_late_gone' && $f === 'lbl_rebook' ? 'PAY_LATE_CTA' : self::LABELS[$f]);
            } else {
                $out[$f] = $get('EM_' . $up . '_' . strtoupper($f));
            }
        }

        return $out;
    }

    /** What the editor shows for the name and the line of help of an email. */
    public static function name(string $key, ?string $tag = null): string
    {
        $lang = $tag !== null ? self::language($tag) : null;

        return $lang ? $lang->_(self::P . 'EM_' . strtoupper($key) . '_NAME') : Text::_(self::P . 'EM_' . strtoupper($key) . '_NAME');
    }

    public static function help(string $key, ?string $tag = null): string
    {
        $lang = $tag !== null ? self::language($tag) : null;

        return $lang ? $lang->_(self::P . 'EM_' . strtoupper($key) . '_DESC') : Text::_(self::P . 'EM_' . strtoupper($key) . '_DESC');
    }

    /** The documented list of placeholders: [token => description] in the language, text ones then blocks. */
    public static function placeholderDocs(?string $tag = null): array
    {
        $lang = $tag !== null ? self::language($tag) : null;
        $out  = [];
        foreach (array_merge(self::TEXT_PLACEHOLDERS, self::BLOCK_PLACEHOLDERS) as $p) {
            $out[$p] = $lang ? $lang->_(self::P . 'PH_' . strtoupper($p)) : Text::_(self::P . 'PH_' . strtoupper($p));
        }

        return $out;
    }

    /** Keep, of a set of fields, only what differs from the default (and is a field of that email): what is stored. */
    public static function onlyDifferences(string $key, array $fields, array $defaults): array
    {
        $keep = [];
        foreach (self::fields($key) as $f) {
            if (!isset($fields[$f])) {
                continue;
            }
            $v = self::normalise($f, (string) $fields[$f]);
            if ($v !== self::normalise($f, (string) ($defaults[$f] ?? ''))) {
                $keep[$f] = $v;
            }
        }

        return $keep;
    }

    private static function normalise(string $field, string $v): string
    {
        $v = str_replace("\r\n", "\n", trim($v));

        return self::isRich($field) ? (string) preg_replace('/>\s+</', '><', $v) : $v;
    }
}
