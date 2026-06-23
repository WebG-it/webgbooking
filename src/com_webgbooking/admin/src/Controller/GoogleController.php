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

    public function savecalendars()
    {
        $this->checkToken();

        $input   = Factory::getApplication()->getInput();
        $clean   = fn($v) => preg_replace('/[^A-Za-z0-9@._\-]/', '', (string) $v);
        $readRaw = (array) $input->get('read', [], 'array');
        $read    = array_values(array_filter(array_map($clean, $readRaw)));
        $write   = $clean($input->getString('write', 'primary'));

        try {
            $this->getModel('Google')->saveCalendars($read, $write !== '' ? $write : 'primary');
            $this->setMessage(Text::_('COM_WEBGBOOKING_GOOGLE_CAL_SAVED'));
        } catch (\Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect('index.php?option=com_webgbooking&view=google');
    }
}
