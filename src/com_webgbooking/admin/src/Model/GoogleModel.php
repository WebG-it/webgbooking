<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

class GoogleModel extends BaseDatabaseModel
{
    private function pluginParams(): Registry
    {
        $plugin = PluginHelper::getPlugin('system', 'webgbooking');

        return new Registry(\is_object($plugin) ? ($plugin->params ?? '') : '');
    }

    public function getStatus(): object
    {
        $status = (object) [
            'connected'   => false,
            'email'       => '',
            'hasClientId' => false,
            'redirect'    => Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=raw&action=oauth',
            'connectUrl'  => '',
            'calendars'   => [],
            'apiError'    => '',
            'error'       => '',
        ];

        try {
            $params   = $this->pluginParams();
            $clientId = trim((string) $params->get('google_client_id', ''));
            $status->hasClientId = $clientId !== '';

            $db = $this->getDatabase();
            $this->ensureTable($db);

            $row = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            $status->connected = $row && !empty($row->refresh_token);
            $status->email     = $row->account_email ?? '';

            if (!$status->connected) {
                if ($clientId !== '') {
                    $status->connectUrl = $this->buildConnectUrl($db, $clientId, $status->redirect);
                }

                return $status;
            }

            // Connected: load the user's calendars and current selections.
            $write    = $row->calendar_id ?: 'primary';
            $readList = json_decode((string) ($row->read_calendars ?? ''), true);
            $readList = \is_array($readList) && $readList ? $readList : [$write];

            try {
                $token = $this->accessToken($params, (string) $row->refresh_token);
                foreach ($this->fetchCalendars($token) as $cal) {
                    $status->calendars[] = (object) [
                        'id'      => $cal['id'],
                        'summary' => $cal['summary'] ?? $cal['id'],
                        'primary' => !empty($cal['primary']),
                        'read'    => \in_array($cal['id'], $readList, true),
                        'write'   => $cal['id'] === $write,
                    ];
                }
            } catch (\Throwable $e) {
                $status->apiError = $e->getMessage();
            }
        } catch (\Throwable $e) {
            $status->error = $e->getMessage();
        }

        return $status;
    }

    public function saveCalendars(array $read, string $write): void
    {
        $db = $this->getDatabase();
        $id = $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__webgbooking_google'))
        )->loadResult();

        if (!$id) {
            return;
        }

        $obj = (object) [
            'id'             => $id,
            'read_calendars' => json_encode(array_values($read)),
            'calendar_id'    => $write !== '' ? $write : 'primary',
            'updated'        => Factory::getDate()->toSql(),
        ];
        $db->updateObject('#__webgbooking_google', $obj, 'id');
    }

    /** Exchange the (encrypted) refresh token for an access token. */
    private function accessToken(Registry $params, string $refreshEnc): string
    {
        $refresh = $this->dec($refreshEnc);

        $resp = (new HttpFactory())->getHttp()->post(
            'https://oauth2.googleapis.com/token',
            [
                'client_id'     => trim((string) $params->get('google_client_id', '')),
                'client_secret' => trim((string) $params->get('google_client_secret', '')),
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
            ],
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        $token = json_decode((string) $resp->body, true)['access_token'] ?? '';

        if ($token === '') {
            throw new \RuntimeException('No access token (' . substr((string) $resp->body, 0, 200) . ')');
        }

        return $token;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchCalendars(string $token): array
    {
        $resp = (new HttpFactory())->getHttp()->get(
            'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250',
            ['Authorization' => 'Bearer ' . $token]
        );

        $data = json_decode((string) $resp->body, true) ?: [];

        return $data['items'] ?? [];
    }

    private function dec(string $stored): string
    {
        $key = hash('sha256', (string) Factory::getApplication()->get('secret'), true);

        if (strncmp($stored, 'g:', 2) === 0) {
            $raw = base64_decode(substr($stored, 2));

            return (string) openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        }

        $raw = base64_decode($stored);

        return (string) openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }

    private function ensureTable($db): void
    {
        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__webgbooking_google') . ' ('
            . $db->quoteName('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . $db->quoteName('refresh_token') . ' TEXT NULL,'
            . $db->quoteName('account_email') . ' VARCHAR(190) NULL,'
            . $db->quoteName('calendar_id') . ' VARCHAR(190) NULL,'
            . $db->quoteName('read_calendars') . ' TEXT NULL,'
            . $db->quoteName('oauth_state') . ' VARCHAR(64) NULL,'
            . $db->quoteName('created') . ' DATETIME NULL,'
            . $db->quoteName('updated') . ' DATETIME NULL,'
            . 'PRIMARY KEY (' . $db->quoteName('id') . ')'
            . ') DEFAULT CHARSET=utf8mb4'
        )->execute();

        try {
            $col = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName('#__webgbooking_google') . " LIKE 'read_calendars'")->loadResult();
            if (!$col) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName('#__webgbooking_google') . ' ADD COLUMN ' . $db->quoteName('read_calendars') . ' TEXT NULL')->execute();
            }
        } catch (\Throwable $e) {
        }
    }

    private function buildConnectUrl($db, string $clientId, string $redirect): string
    {
        $state = bin2hex(random_bytes(16));
        $now   = Factory::getDate()->toSql();

        $id = $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__webgbooking_google'))
        )->loadResult();

        if ($id) {
            $obj = (object) ['id' => $id, 'oauth_state' => $state, 'updated' => $now];
            $db->updateObject('#__webgbooking_google', $obj, 'id');
        } else {
            $obj = (object) ['oauth_state' => $state, 'calendar_id' => 'primary', 'created' => $now];
            $db->insertObject('#__webgbooking_google', $obj);
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'              => $clientId,
            'redirect_uri'           => $redirect,
            'response_type'          => 'code',
            'scope'                  => 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }
}
