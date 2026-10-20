<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Saved event types ("Tipi di evento"): the table #__webgbooking_eventtypes, edited in the component, read here.
 * A saved type has the same fields as the YOOtheme element's event tab (duration, buffers, place, availability, price,
 * questions) plus what the direct page needs (slug, description, public or secret). It can be used in two ways:
 *  - as the direct booking page /prenota/<slug>;
 *  - picked by a YOOtheme element instead of configuring the same things by hand.
 * Reading and URL helpers only: no HTML here.
 */

namespace WebG\Plugin\System\Webgbooking\Booking;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

final class EventTypes
{
    public const TABLE = '#__webgbooking_eventtypes';

    /** Default first path segment of the direct link (plugin option "direct_path"). */
    public const DEFAULT_PATH = 'prenota';

    /** Props of a saved type that REPLACE the element's own when the element picks the type. */
    private const ELEMENT_KEYS = [
        'event_duration', 'event_buffer_before', 'event_buffer_after', 'event_location',
        'avail_start', 'avail_end', 'avail_days', 'avail_notice', 'avail_window',
        'price_amount', 'price_currency', 'price_label', 'custom_fields',
    ];

    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** One saved type by id. $onlyPublished: unpublished and deleted types answer null. */
    public static function get(int $id, bool $onlyPublished = true): ?object
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $db = self::db();
            $q  = $db->getQuery(true)->select('*')->from($db->quoteName(self::TABLE))->where($db->quoteName('id') . ' = ' . (int) $id);
            if ($onlyPublished) {
                $q->where($db->quoteName('state') . ' = 1');
            }

            return $db->setQuery($q)->loadObject() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function bySlug(string $slug, bool $onlyPublished = true): ?object
    {
        if (!self::slugOk($slug)) {
            return null;
        }
        try {
            $db = self::db();
            $q  = $db->getQuery(true)->select('*')->from($db->quoteName(self::TABLE))->where($db->quoteName('slug') . ' = ' . $db->quote($slug));
            if ($onlyPublished) {
                $q->where($db->quoteName('state') . ' = 1');
            }

            return $db->setQuery($q)->loadObject() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Published AND listed ("public") types for the index page, in the admin's order. Secret types are never here. */
    public static function listed(): array
    {
        try {
            $db = self::db();

            return $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('state') . ' = 1')->where($db->quoteName('listed') . ' = 1')
                    ->order($db->quoteName('ordering') . ' ASC')->order($db->quoteName('id') . ' ASC')
            )->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** id => "Title (slug)" of the published types, for the element's selector in the builder. */
    public static function choices(): array
    {
        try {
            $db   = self::db();
            $rows = $db->setQuery(
                $db->getQuery(true)->select([$db->quoteName('id'), $db->quoteName('title'), $db->quoteName('slug')])->from($db->quoteName(self::TABLE))
                    ->where($db->quoteName('state') . ' = 1')->order($db->quoteName('ordering') . ' ASC')->order($db->quoteName('id') . ' ASC')
            )->loadObjectList() ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r->id] = (string) $r->title . ' (' . (string) $r->slug . ')';
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** A slug is lower-case letters, digits and single hyphens, 2 to 80 characters. */
    public static function slugOk(string $slug): bool
    {
        return \strlen($slug) >= 2 && \strlen($slug) <= 80 && (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    /** First path segment of the direct link: letters, digits and hyphens, from the plugin option; the default when it is not usable. */
    public static function pathBase(string $configured): string
    {
        $p = strtolower(trim(trim($configured), '/'));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $p) && \strlen($p) <= 40 ? $p : self::DEFAULT_PATH;
    }

    /**
     * The public address of a type (or of the index when $slug is ''), as Marco sends it by email:
     * https://site/prenota/<slug>, with the "index.php/" step when the site has friendly addresses but no URL rewriting,
     * and the long address (index.php?option=com_webgbooking&view=book&event=<slug>) when the site has no friendly addresses.
     */
    public static function publicUrl(string $slug, string $pathBase, bool $long = false): string
    {
        $app  = Factory::getApplication();
        $root = Uri::root();
        if ($long || !$app->get('sef')) {
            return $root . 'index.php?option=com_webgbooking&view=book' . ($slug !== '' ? '&event=' . rawurlencode($slug) : '');
        }

        return $root . ($app->get('sef_rewrite') ? '' : 'index.php/') . self::pathBase($pathBase) . ($slug !== '' ? '/' . rawurlencode($slug) : '');
    }

    /** The element-style props of a saved type, as the widget builder reads them. */
    public static function props(object $r): array
    {
        return [
            'service'             => (string) $r->title,
            'event_duration'      => (string) ($r->duration ?? ''),
            'event_buffer_before' => (string) ($r->buffer_before ?? ''),
            'event_buffer_after'  => (string) ($r->buffer_after ?? ''),
            'event_location'      => (string) ($r->location ?? 'meet'),
            'avail_start'         => (string) ($r->avail_start ?? ''),
            'avail_end'           => (string) ($r->avail_end ?? ''),
            'avail_days'          => (string) ($r->avail_days ?? ''),
            'avail_notice'        => (string) ($r->avail_notice ?? ''),
            'avail_window'        => (string) ($r->avail_window ?? ''),
            'price_amount'        => (string) ($r->price_amount ?? ''),
            'price_currency'      => (string) ($r->price_currency ?? ''),
            'price_label'         => (string) ($r->price_label ?? ''),
            'custom_fields'       => (string) ($r->custom_fields ?? ''),
        ];
    }

    /** A YOOtheme element that picks a saved type: the type's event definition replaces the element's own; the look and the actions stay the element's. */
    public static function overlay(array $props, object $r): array
    {
        $t = self::props($r);
        foreach (['service'] as $k) {
            $props[$k] = $t[$k];
        }
        foreach (self::ELEMENT_KEYS as $k) {
            $props[$k] = $t[$k];
        }
        if (trim((string) ($props['title'] ?? '')) === '') {
            $props['title'] = (string) $r->title;
            $props['_title_from_type'] = true;
        }

        return $props;
    }

    /** Props for the direct page: the event definition plus what the page settings of the type add (staff address, thank-you page, manual confirmation, guest, newsletter). */
    public static function pageProps(object $r): array
    {
        return self::props($r) + [
            'title'           => (string) $r->title,
            'card_style'      => 'plain',
            'staff_to'        => (string) ($r->staff_to ?? ''),
            'redirect_url'    => (string) ($r->redirect_url ?? ''),
            'confirm_manual'  => !empty($r->confirm_manual),
            'allow_guest'     => !empty($r->allow_guest),
            'show_newsletter' => !empty($r->show_newsletter),
        ];
    }
}
