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

/**
 * Admin actions on individual bookings: change status (confirm/cancel/complete/no-show) and delete.
 */
class BookingController extends BaseController
{
    /** Map of task name → target status. */
    private const STATUS_TASKS = [
        'confirm'  => 'confirmed',
        'cancel'   => 'cancelled',
        'complete' => 'completed',
        'noshow'   => 'no-show',
        'pending'  => 'pending',
    ];

    public function confirm()
    {
        $this->changeStatus('confirm');
    }

    public function cancel()
    {
        $this->changeStatus('cancel');
    }

    public function complete()
    {
        $this->changeStatus('complete');
    }

    public function noshow()
    {
        $this->changeStatus('noshow');
    }

    public function setpending()
    {
        $this->changeStatus('pending');
    }

    private function changeStatus(string $task): void
    {
        $this->checkToken();
        $this->assertAllowed('core.edit.state');

        $ids    = $this->cid();
        $status = self::STATUS_TASKS[$task] ?? '';

        try {
            $n = $ids ? $this->getModel('Booking')->setStatus($ids, $status) : 0;
            if ($n > 0) {
                $this->setMessage(Text::plural('COM_WEBGBOOKING_N_BOOKINGS_STATUS', $n, Text::_('COM_WEBGBOOKING_STATUS_' . strtoupper(str_replace('-', '', $status)))));
            } else {
                $this->setMessage(Text::_('COM_WEBGBOOKING_NO_ROWS_SELECTED'), 'warning');
            }
        } catch (\Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_webgbooking&view=bookings', false));
    }

    public function delete()
    {
        $this->checkToken();
        $this->assertAllowed('core.delete');

        $ids = $this->cid();

        try {
            $n = $ids ? $this->getModel('Booking')->deleteItems($ids) : 0;
            if ($n > 0) {
                $this->setMessage(Text::plural('COM_WEBGBOOKING_N_BOOKINGS_DELETED', $n));
            } else {
                $this->setMessage(Text::_('COM_WEBGBOOKING_NO_ROWS_SELECTED'), 'warning');
            }
        } catch (\Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_webgbooking&view=bookings', false));
    }

    /** Selected row ids from the list checkboxes (cid[]) or a single id. */
    private function cid(): array
    {
        $input = $this->input;
        $cid   = (array) $input->get('cid', [], 'array');

        if (!$cid && $input->getInt('id', 0) > 0) {
            $cid = [$input->getInt('id', 0)];
        }

        return array_values(array_filter(array_map('intval', $cid), static fn($i) => $i > 0));
    }

    /** Enforce the admin permission for the action. */
    private function assertAllowed(string $action): void
    {
        if (!Factory::getApplication()->getIdentity()->authorise($action, 'com_webgbooking')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
