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
