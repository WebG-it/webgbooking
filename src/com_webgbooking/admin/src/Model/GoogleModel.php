<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

class GoogleModel extends BaseDatabaseModel
{
    public function getStatus(): object
    {
        $status = (object) [
            'connected'   => false,
            'email'       => '',
            'hasClientId' => false,
            'redirect'    => Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=raw&action=oauth',
            'connectUrl'  => '',
            'error'       => '',
        ];

        try {
            $plugin   = PluginHelper::getPlugin('system', 'webgbooking');
            $params   = new Registry(\is_object($plugin) ? ($plugin->params ?? '') : '');
            $clientId = trim((string) $params->get('google_client_id', ''));
            $status->hasClientId = $clientId !== '';

            $db  = $this->getDatabase();
            $this->ensureTable($db);

            $row = $db->setQuery(
                $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
            )->loadObject();

            $status->connected = $row && !empty($row->refresh_token);
            $status->email     = $row->account_email ?? '';

            if ($clientId !== '' && !$status->connected) {
                $status->connectUrl = $this->buildConnectUrl($db, $clientId, $status->redirect);
            }
        } catch (\Throwable $e) {
            $status->error = $e->getMessage();
        }

        return $status;
    }

    /** Defensive: create the shared table if the plugin install script never ran. */
    private function ensureTable($db): void
    {
        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__webgbooking_google') . ' ('
            . $db->quoteName('id') . ' INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . $db->quoteName('refresh_token') . ' TEXT NULL,'
            . $db->quoteName('account_email') . ' VARCHAR(190) NULL,'
            . $db->quoteName('calendar_id') . ' VARCHAR(190) NULL,'
            . $db->quoteName('oauth_state') . ' VARCHAR(64) NULL,'
            . $db->quoteName('created') . ' DATETIME NULL,'
            . $db->quoteName('updated') . ' DATETIME NULL,'
            . 'PRIMARY KEY (' . $db->quoteName('id') . ')'
            . ') DEFAULT CHARSET=utf8mb4'
        )->execute();
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
            'scope'                  => 'openid email https://www.googleapis.com/auth/calendar.events',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }
}
