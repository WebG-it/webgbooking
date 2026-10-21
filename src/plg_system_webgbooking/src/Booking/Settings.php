<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * Where the settings live. Until 0.35.3 they were the options of the system plugin; since 0.36.0 they are the Options of the
 * component (Components > WebG Booking > Options). ONE place reads them for everybody (plugin, component, task plugin, YOOtheme
 * element): merged(), the component's options on top of whatever the plugin still holds, so a key that was not (yet) copied is
 * still found where it always was. Nothing in the plugin row is ever deleted by this class.
 */

namespace WebG\Plugin\System\Webgbooking\Booking;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class Settings
{
    public const COMPONENT = 'com_webgbooking';

    /** Address of the Options screen of the component. */
    public const OPTIONS_LINK = 'index.php?option=com_config&view=component&component=com_webgbooking';

    /** Component options on top of the plugin's own: a key present in the component wins even when empty, a missing key falls back to the plugin. */
    public static function merged(?Registry $plugin = null): Registry
    {
        $base = $plugin ? $plugin->toArray() : [];
        try {
            $comp = ComponentHelper::getParams(self::COMPONENT)->toArray();
        } catch (\Throwable $e) {
            $comp = [];
        }

        return new Registry(array_replace($base, $comp));
    }

    /** The settings as every reader needs them, from the plugin row Joomla gave to the plugin. */
    public static function all(): Registry
    {
        $pl = PluginHelper::getPlugin('system', 'webgbooking');

        return self::merged(new Registry(\is_object($pl) ? ($pl->params ?? '') : ''));
    }

    /** Params of the component row straight from the database (never the per-request cache): array. */
    public static function componentRow(DatabaseInterface $db): array
    {
        $raw = (string) $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))->where($db->quoteName('element') . ' = ' . $db->quote(self::COMPONENT))
        )->loadResult();
        $a = json_decode($raw, true);

        return \is_array($a) ? $a : [];
    }

    /** Set keys in the component row (the other keys stay as they are). */
    public static function setComponent(DatabaseInterface $db, array $set): void
    {
        $p = array_replace(self::componentRow($db), $set);
        $db->setQuery(
            $db->getQuery(true)->update($db->quoteName('#__extensions'))->set($db->quoteName('params') . ' = ' . $db->quote((string) json_encode($p)))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))->where($db->quoteName('element') . ' = ' . $db->quote(self::COMPONENT))
        )->execute();
    }

    /** Working days from a stored value (a list, "1,2,3", or nothing at all): nothing ticked or empty = Monday to Friday, as in every release before. */
    public static function workDays($v): array
    {
        $a = \is_array($v) ? $v : explode(',', (string) $v);
        $a = array_values(array_unique(array_map('intval', array_filter($a, static fn($x) => trim((string) $x) !== ''))));

        return $a ?: [1, 2, 3, 4, 5];
    }

    /** Empty these keys in the plugin's old copy of the settings (a rotated or cleared secret must not survive there). */
    public static function clearInPlugin(DatabaseInterface $db, array $keys): void
    {
        $where = static fn($q) => $q->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))->where($db->quoteName('element') . ' = ' . $db->quote('webgbooking'))->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
        $raw   = (string) $db->setQuery($where($db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))))->loadResult();
        $p     = json_decode($raw, true);
        if (!\is_array($p)) {
            return;
        }
        $changed = false;
        foreach ($keys as $k) {
            if (isset($p[$k]) && $p[$k] !== '') {
                $p[$k]   = '';
                $changed = true;
            }
        }
        if ($changed) {
            $db->setQuery($where($db->getQuery(true)->update($db->quoteName('#__extensions'))->set($db->quoteName('params') . ' = ' . $db->quote((string) json_encode($p)))))->execute();
        }
    }

    /** Copy into the component the keys the plugin still holds and the component lacks (never overwrites, never deletes). */
    public static function heal(DatabaseInterface $db, Registry $pluginParams): void
    {
        $pp = $pluginParams->toArray();
        if (!$pp) {
            return;
        }
        // a clear secret in the plugin's old copy is encrypted there before anything is copied
        [$prot, $n] = SecretBox::protect($pp);
        if ($n > 0) {
            $only = array_intersect_key($prot, array_flip(SecretBox::KEYS));
            $row  = (string) $db->setQuery($db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))->where($db->quoteName('element') . ' = ' . $db->quote('webgbooking'))->where($db->quoteName('folder') . ' = ' . $db->quote('system')))->loadResult();
            $rp   = json_decode($row, true);
            if (\is_array($rp)) {
                $db->setQuery($db->getQuery(true)->update($db->quoteName('#__extensions'))->set($db->quoteName('params') . ' = ' . $db->quote((string) json_encode($only + $rp)))->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))->where($db->quoteName('element') . ' = ' . $db->quote('webgbooking'))->where($db->quoteName('folder') . ' = ' . $db->quote('system')))->execute();
            }
            $pp = $prot;
        }
        $comp = self::componentRow($db);
        $miss = array_diff_key($pp, $comp);
        if ($miss) {
            self::setComponent($db, $miss + $comp);
            // the Options screen reads the component through Joomla's per-request copy, loaded before this plugin: bring that copy up to date too
            try {
                ComponentHelper::getComponent(self::COMPONENT)->params = new Registry($miss + $comp);
            } catch (\Throwable $e) {
            }
        }
    }

    /** Names and types of the fields of the Options form. */
    public static function formTypes(): array
    {
        $file = JPATH_ADMINISTRATOR . '/components/' . self::COMPONENT . '/config.xml';
        $out  = [];
        $x    = is_file($file) ? @simplexml_load_file($file) : false;
        foreach ($x ? ($x->xpath('//field') ?: []) : [] as $f) {
            $out[(string) $f['name']] = (string) $f['type'];
        }

        return $out;
    }

    /** Names of the fields of the Options form (config.xml of the component), without the notes. */
    public static function formFields(): array
    {
        $file = JPATH_ADMINISTRATOR . '/components/' . self::COMPONENT . '/config.xml';
        $out  = [];
        if (is_file($file)) {
            $x = @simplexml_load_file($file);
            if ($x) {
                foreach ($x->xpath('//field') ?: [] as $f) {
                    $n = (string) $f['name'];
                    if ($n !== '' && !\in_array((string) $f['type'], ['note', 'webhookurl', 'componentlink', 'rules', 'spacer'], true)) {
                        $out[] = $n;
                    }
                }
            }
        }

        return $out;
    }
}
