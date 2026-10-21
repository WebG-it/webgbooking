<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The secrets of the settings (Stripe keys, Brevo key, webhook header profiles) are stored encrypted with the Joomla secret
 * (AES-256-GCM, prefix "g:"). One place for it, so the plugin, the Options and the install script (which has to protect a clear value
 * found in the old plugin options before it copies it) all do exactly the same thing.
 */

namespace WebG\Plugin\System\Webgbooking\Booking;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

final class SecretBox
{
    /** The settings that are secrets (stored as "g:..."). */
    public const KEYS = ['stripe_sk', 'stripe_whsec', 'brevo_key', 'whp1_headers', 'whp2_headers', 'whp3_headers', 'whp4_headers'];

    private static function key(): string
    {
        return hash('sha256', (string) Factory::getApplication()->get('secret'), true);
    }

    public static function enc(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('encrypt');
        }

        return 'g:' . base64_encode($iv . $tag . $ct);
    }

    /** A value that is not empty and not yet encrypted (a clear secret). */
    public static function isClear($v): bool
    {
        $v = trim((string) $v);

        return $v !== '' && strncmp($v, 'g:', 2) !== 0;
    }

    /** The same settings with every clear secret encrypted. [settings, number of values changed] */
    public static function protect(array $params): array
    {
        $n = 0;
        foreach (self::KEYS as $k) {
            if (isset($params[$k]) && self::isClear($params[$k])) {
                $params[$k] = self::enc(trim((string) $params[$k]));
                $n++;
            }
        }

        return [$params, $n];
    }
}
