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
        $db  = $this->getDatabase();
        $row = $db->setQuery(
            $db->getQuery(true)->select('*')->from($db->quoteName('#__webgbooking_google'))
        )->loadObject();

        $params   = new Registry(PluginHelper::getPlugin('system', 'webgbooking')->params ?? '');
        $clientId = trim((string) $params->get('google_client_id', ''));
        $redirect = Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=raw&action=oauth';

        return (object) [
            'connected'   => $row && !empty($row->refresh_token),
            'email'       => $row->account_email ?? '',
            'hasClientId' => $clientId !== '',
            'redirect'    => $redirect,
            'connectUrl'  => $clientId !== '' ? $this->buildConnectUrl($clientId, $redirect) : '',
        ];
    }

    private function buildConnectUrl(string $clientId, string $redirect): string
    {
        $db    = $this->getDatabase();
        $state = bin2hex(random_bytes(16));
        $now   = Factory::getDate()->toSql();

        $id = $db->setQuery(
            $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__webgbooking_google'))
        )->loadResult();

        if ($id) {
            $db->updateObject('#__webgbooking_google', (object) ['id' => $id, 'oauth_state' => $state, 'updated' => $now], 'id');
        } else {
            $db->insertObject('#__webgbooking_google', (object) ['oauth_state' => $state, 'calendar_id' => 'primary', 'created' => $now]);
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
