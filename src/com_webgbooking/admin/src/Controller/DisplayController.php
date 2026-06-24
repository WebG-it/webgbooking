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
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

class DisplayController extends BaseController
{
    protected $default_view = 'bookings';

    /** Stream all bookings as a CSV download (Excel/Sheets compatible). */
    public function exportcsv()
    {
        if (!Session::checkToken('get')) {
            throw new NotAllowed(Text::_('JINVALID_TOKEN'), 403);
        }

        $app = Factory::getApplication();
        $db  = Factory::getContainer()->get(DatabaseInterface::class);

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__webgbooking_bookings'))
                ->order($db->quoteName('booking_date') . ' DESC')
                ->order($db->quoteName('booking_time') . ' DESC')
        )->loadObjectList();

        $app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="webgbooking-' . date('Ymd-His') . '.csv"', true);
        $app->sendHeaders();

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens accents correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Created', 'Date', 'Time', 'Name', 'Email', 'Phone', 'Guest', 'Notes', 'Status', 'Meeting']);

        foreach ($rows ?: [] as $r) {
            fputcsv($out, [
                $r->id,
                $r->created,
                $r->booking_date,
                $r->booking_time,
                $r->customer_name,
                $r->customer_email,
                $r->customer_phone ?? '',
                $r->guest_email ?? '',
                $r->notes ?? '',
                $r->status,
                $r->meeting_url ?? '',
            ]);
        }

        fclose($out);
        $app->close();
    }
}
