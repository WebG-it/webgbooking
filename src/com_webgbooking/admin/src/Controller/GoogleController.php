<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

class GoogleController extends BaseController
{
    public function disconnect()
    {
        $this->checkToken();

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__webgbooking_google'))->execute();
            $this->setMessage(Text::_('COM_WEBGBOOKING_GOOGLE_DISCONNECTED'));
        } catch (\Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect('index.php?option=com_webgbooking&view=google');
    }
}
