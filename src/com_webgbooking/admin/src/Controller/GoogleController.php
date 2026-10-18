<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Database\DatabaseInterface;

class GoogleController extends BaseController
{
    /** Google integration is a global, account-level setting: restrict to component admins. */
    private function assertAdmin(): void
    {
        if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_webgbooking')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    public function disconnect()
    {
        $this->checkToken();
        $this->assertAdmin();

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
        $this->assertAdmin();

        $input   = Factory::getApplication()->getInput();
        $clean   = fn($v) => preg_replace('/[^A-Za-z0-9@._#+\-]/', '', (string) $v);
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

    public function savesheet()
    {
        $this->checkToken();
        $this->assertAdmin();

        $input = Factory::getApplication()->getInput();
        // A pasted ID/URL (works with the Sheets scope alone) takes precedence over the Drive picker.
        $manual = trim($input->getString('sheet_id_manual', ''));
        $sheetId = $manual !== '' ? $this->extractSheetId($manual) : trim($input->getString('sheet_id', ''));
        $tab     = trim($input->getString('sheet_tab', ''));

        try {
            $this->getModel('Google')->saveSheet($sheetId, $tab);
            $this->setMessage(Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_SAVED'));
        } catch (\Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect('index.php?option=com_webgbooking&view=google');
    }

    /** Accept a full spreadsheet URL or a raw ID and return the bare spreadsheet ID. */
    private function extractSheetId(string $value): string
    {
        if (preg_match('~/spreadsheets/d/([A-Za-z0-9_-]+)~', $value, $m)) {
            return $m[1];
        }
        if (preg_match('~^[A-Za-z0-9_-]{20,}$~', $value)) {
            return $value;
        }

        return '';
    }
}
