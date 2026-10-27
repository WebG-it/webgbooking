<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

class GoogleModel extends BaseDatabaseModel
{
    /** Exception code meaning: Google says the saved refresh token is revoked or expired. */
    private const E_EXPIRED = 401;

    /** One line to the component log (administrator/logs/webgbooking.php): the detail the screen never shows. */
    private function log(string $msg): void
    {
        static $ready = false;
        try {
            if (!$ready) {
                \Joomla\CMS\Log\Log::addLogger(['text_file' => 'webgbooking.php'], \Joomla\CMS\Log\Log::ALL, ['webgbooking']);
                $ready = true;
            }
            \Joomla\CMS\Log\Log::add($msg, \Joomla\CMS\Log\Log::WARNING, 'webgbooking');
        } catch (\Throwable $e) {
        }
    }

    private function pluginParams(): Registry
    {
        // the settings are the Options of the component since 0.36.0: read through the one place that knows (plugin as fallback)
        $settings = '\WebG\Plugin\System\Webgbooking\Booking\Settings';
        if (class_exists($settings)) {
            return $settings::all();
        }
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
            'sheetId'     => '',
            'sheetTab'    => '',
            'tabs'        => [],
            // none = never connected, expired = Google refused the saved token (revoked / expired), ok = token works
            'state'       => 'none',
            'expired'     => false,
            // true when Google answered with an error that is not an expired token (detail goes to the log, never on screen)
            'apiProblem'  => false,
            // why the copy to the Sheet last failed: '' | api_disabled | denied | notfound | badrange | down | other (a kind, never Google's text)
            'sheetErr'    => '',
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
            $status->sheetId   = $row->sheet_id ?? '';
            $status->sheetTab  = $row->sheet_tab ?? '';

            if (!$status->connected) {
                if ($clientId !== '') {
                    $status->connectUrl = $this->buildConnectUrl($db, $clientId, $status->redirect);
                }

                return $status;
            }

            $status->state = 'ok';

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

                // Optional Sheets copy: only the tab names of the sheet the admin pasted (Sheets scope, no Drive).
                if ($status->sheetId !== '') {
                    try {
                        $status->tabs = $this->listTabs($token, $status->sheetId, $status->sheetErr);
                    } catch (\Throwable $eTabs) {
                        $this->log(Text::sprintf('COM_WEBGBOOKING_LOG_SHEET_TABS', $eTabs->getMessage()));
                    }
                }
            } catch (\Throwable $e) {
                if ($e->getCode() === self::E_EXPIRED) {
                    // The saved token no longer works: the honest state is "expired", with ONE way out (reconnect).
                    $status->state     = 'expired';
                    $status->expired   = true;
                    $status->calendars = [];
                    $status->tabs      = [];
                    if ($clientId !== '') {
                        $status->connectUrl = $this->buildConnectUrl($db, $clientId, $status->redirect);
                    }
                } else {
                    $status->apiProblem = true;
                }
                $this->log(Text::sprintf('COM_WEBGBOOKING_LOG_GOOGLE_PAGE', $e->getMessage()));
            }
        } catch (\Throwable $e) {
            $status->error = $e->getMessage();
        }

        // a failure of the last copy to the Sheet, left by the booking engine (kind only)
        if ($status->sheetErr === '' && $status->sheetId !== '') {
            try {
                $db = $this->getDatabase();
                $j  = json_decode((string) $db->setQuery($db->getQuery(true)->select($db->quoteName('v'))->from($db->quoteName('#__webgbooking_kv'))->where($db->quoteName('k') . ' = ' . $db->quote('sheet_err')))->loadResult(), true);
                if (\is_array($j) && \in_array($j['kind'] ?? '', ['api_disabled', 'denied', 'notfound', 'badrange', 'down', 'other'], true)) {
                    $status->sheetErr = (string) $j['kind'];
                }
            } catch (\Throwable $e) {
            }
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

    public function saveSheet(string $sheetId, string $tab): void
    {
        $db  = $this->getDatabase();
        $row = $db->setQuery(
            $db->getQuery(true)->select($db->quoteName(['id', 'sheet_id', 'sheet_tab']))->from($db->quoteName('#__webgbooking_google'))
        )->loadObject();

        if (!$row) {
            return;
        }

        // Spreadsheet changed and the tab field still holds the OLD sheet's tab: drop it (a tab typed for the new one is kept).
        if ((string) ($row->sheet_id ?? '') !== $sheetId && $tab === (string) ($row->sheet_tab ?? '')) {
            $tab = '';
        }
        if ($sheetId === '') {
            $tab = '';
        }

        $obj = (object) [
            'id'        => $row->id,
            'sheet_id'  => $sheetId,
            'sheet_tab' => $tab,
            'updated'   => Factory::getDate()->toSql(),
        ];
        $db->updateObject('#__webgbooking_google', $obj, 'id');
    }

    /** After "Save sheet": the booking engine puts the header in row 1 when the tab needs it. written | inserted | present | foreign | nosheet | error */
    public function ensureSheetHeader(): string
    {
        try {
            if (\Joomla\CMS\Plugin\PluginHelper::isEnabled('system', 'webgbooking')) {
                $engine = \Joomla\CMS\Factory::getApplication()->bootPlugin('webgbooking', 'system');
                if (method_exists($engine, 'adminSheetHeader')) {
                    return (string) $engine->adminSheetHeader();
                }
            }
        } catch (\Throwable $e) {
        }

        return 'error';
    }

    /** Tab (worksheet) titles of a spreadsheet. @return array<int,string> */
    private function listTabs(string $token, string $sheetId, string &$err = ''): array
    {
        $resp = (new HttpFactory())->getHttp()->get(
            'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId) . '?fields=sheets.properties.title',
            ['Authorization' => 'Bearer ' . $token]
        );

        $body = (string) $resp->body;
        if ((int) $resp->code === 403 && (stripos($body, 'SERVICE_DISABLED') !== false || stripos($body, 'accessNotConfigured') !== false || stripos($body, 'has not been used in project') !== false)) {
            $err = 'api_disabled';
        }
        $data = json_decode($body, true) ?: [];
        $out  = [];
        foreach ($data['sheets'] ?? [] as $s) {
            $title = $s['properties']['title'] ?? '';
            if ($title !== '') {
                $out[] = $title;
            }
        }

        return $out;
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

        $data  = json_decode((string) $resp->body, true);
        $data  = is_array($data) ? $data : [];
        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            // Only Google's error code and description go to the log: never the request, the token or the raw body.
            $code = preg_replace('/[^a-z_]/', '', strtolower((string) ($data['error'] ?? 'unknown')));
            $desc = substr((string) preg_replace('/[^A-Za-z0-9 .,:;()\/-]/', '', (string) ($data['error_description'] ?? '')), 0, 200);

            throw new \RuntimeException(Text::sprintf('COM_WEBGBOOKING_LOG_TOKEN_REFUSED', $code, $desc !== '' ? ' (' . $desc . ')' : ''), $code === 'invalid_grant' ? self::E_EXPIRED : 0);
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

        if (isset($data['error'])) {
            $err = $data['error'];
            $msg = is_array($err) ? (string) ($err['message'] ?? '') : (string) $err;

            throw new \RuntimeException(Text::sprintf('COM_WEBGBOOKING_LOG_CAL_REFUSED', (int) $resp->code, substr((string) preg_replace('/[^A-Za-z0-9 .,:;()\/-]/', '', $msg), 0, 300)));
        }

        return $data['items'] ?? [];
    }

    private function dec(string $stored): string
    {
        $key = hash('sha256', (string) Factory::getApplication()->get('secret'), true);

        $gcm = strncmp($stored, 'g:', 2) === 0;
        $raw = (string) base64_decode($gcm ? substr($stored, 2) : $stored);

        return $gcm
            ? (string) openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16))
            : (string) openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
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
            . $db->quoteName('sheet_id') . ' VARCHAR(190) NULL,'
            . $db->quoteName('sheet_tab') . ' VARCHAR(190) NULL,'
            . $db->quoteName('oauth_state') . ' VARCHAR(64) NULL,'
            . $db->quoteName('created') . ' DATETIME NULL,'
            . $db->quoteName('updated') . ' DATETIME NULL,'
            . 'PRIMARY KEY (' . $db->quoteName('id') . ')'
            . ') DEFAULT CHARSET=utf8mb4'
        )->execute();

        foreach (['read_calendars' => 'TEXT', 'sheet_id' => 'VARCHAR(190)', 'sheet_tab' => 'VARCHAR(190)'] as $name => $type) {
            try {
                $col = $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName('#__webgbooking_google') . ' LIKE ' . $db->quote($name))->loadResult();
                if (!$col) {
                    $db->setQuery('ALTER TABLE ' . $db->quoteName('#__webgbooking_google') . ' ADD COLUMN ' . $db->quoteName($name) . ' ' . $type . ' NULL')->execute();
                }
            } catch (\Throwable $e) {
            }
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
            'scope'                  => 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/spreadsheets',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'state'                  => $state,
        ]);
    }
}
