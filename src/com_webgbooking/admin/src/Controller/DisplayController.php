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
use Joomla\CMS\Router\Route;
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

    /** Stream all bookings as a real .xlsx (Excel) download. */
    public function exportxlsx()
    {
        if (!Session::checkToken('get')) {
            throw new NotAllowed(Text::_('JINVALID_TOKEN'), 403);
        }

        $app = Factory::getApplication();

        if (!class_exists('ZipArchive')) {
            $app->enqueueMessage(Text::_('COM_WEBGBOOKING_XLSX_NOZIP'), 'warning');
            $app->redirect(Route::_('index.php?option=com_webgbooking&view=bookings', false));

            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__webgbooking_bookings'))
                ->order($db->quoteName('booking_date') . ' DESC')
                ->order($db->quoteName('booking_time') . ' DESC')
        )->loadObjectList();

        $data = [['ID', 'Created', 'Date', 'Time', 'Name', 'Email', 'Phone', 'Guest', 'Notes', 'Status', 'Meeting']];
        foreach ($rows ?: [] as $r) {
            $data[] = [
                (string) $r->id, (string) $r->created, (string) $r->booking_date, (string) $r->booking_time,
                (string) $r->customer_name, (string) $r->customer_email, (string) ($r->customer_phone ?? ''),
                (string) ($r->guest_email ?? ''), (string) ($r->notes ?? ''), (string) $r->status, (string) ($r->meeting_url ?? ''),
            ];
        }

        $xlsx = $this->buildXlsx($data);

        if ($xlsx === '') {
            $app->enqueueMessage(Text::_('COM_WEBGBOOKING_XLSX_NOZIP'), 'warning');
            $app->redirect(Route::_('index.php?option=com_webgbooking&view=bookings', false));

            return;
        }

        $app->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="webgbooking-' . date('Ymd-His') . '.xlsx"', true);
        $app->sendHeaders();
        echo $xlsx;
        $app->close();
    }

    /** Build a minimal Office Open XML (.xlsx) workbook with inline strings from a 2D array. */
    private function buildXlsx(array $rows): string
    {
        $col = static function (int $n): string {
            $s = '';
            for ($n++; $n > 0; $n = intdiv($n - 1, 26)) {
                $s = chr(65 + ($n - 1) % 26) . $s;
            }

            return $s;
        };
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $row) {
            $r      = $ri + 1;
            $sheet .= '<row r="' . $r . '">';
            foreach (array_values($row) as $ci => $val) {
                $sheet .= '<c r="' . $col($ci) . $r . '" t="inlineStr"><is><t xml:space="preserve">' . $esc($val) . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $files = [
            '[Content_Types].xml'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Bookings" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'   => $sheet,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'wgbxlsx');
        if ($tmp === false) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);

            return '';
        }

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $out = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $out;
    }
}
