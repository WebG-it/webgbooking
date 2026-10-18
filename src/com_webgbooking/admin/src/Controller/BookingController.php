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

    /** Manual-confirmation flow: confirm AND email the customer (the plain "confirm" only changes the status). */
    public function confirmnotify()
    {
        $this->checkToken();
        $this->requireRight('core.edit.state');

        $ids = $this->cid();
        $id  = $ids[0] ?? 0;
        try {
            $r = $id > 0 ? $this->getModel('Booking')->confirmAndNotify($id) : ['status' => 'missing', 'mail' => false];
            if (($r['status'] ?? '') === 'ok' && ($r['mail'] ?? null) === 'off') {
                $this->setMessage(Text::_('COM_WEBGBOOKING_CONFIRMNOTIFY_OFF'), 'message');
            } elseif (($r['status'] ?? '') === 'ok') {
                $this->setMessage(Text::_(!empty($r['mail']) ? 'COM_WEBGBOOKING_CONFIRMNOTIFY_OK' : 'COM_WEBGBOOKING_CONFIRMNOTIFY_MAILFAIL'), !empty($r['mail']) ? 'message' : 'warning');
            } elseif (($r['status'] ?? '') === 'already' || ($r['status'] ?? '') === 'missing') {
                $this->setMessage(Text::_('COM_WEBGBOOKING_CONFIRMNOTIFY_ALREADY'), 'warning');
            } else {
                $this->setMessage(Text::_('COM_WEBGBOOKING_CONFIRMNOTIFY_ERROR'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage(Text::_('COM_WEBGBOOKING_CONFIRMNOTIFY_ERROR'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_webgbooking&view=booking&id=' . (int) $id, false));
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
        $this->requireRight('core.edit.state');

        $ids    = $this->cid();
        $status = self::STATUS_TASKS[$task] ?? '';

        try {
            $model = $this->getModel('Booking');
            $n     = $ids ? $model->setStatus($ids, $status) : 0;
            if ($n > 0) {
                $this->setMessage(Text::plural('COM_WEBGBOOKING_N_BOOKINGS_STATUS', $n, Text::_('COM_WEBGBOOKING_STATUS_' . strtoupper(str_replace('-', '', $status)))));
                if ($status === 'cancelled') {
                    $this->reportCancel($model->cancelReport);
                }
            } elseif ($status === 'cancelled' && $ids && ($model->cancelReport['already'] ?? 0) > 0) {
                $this->setMessage(Text::_('COM_WEBGBOOKING_ALREADY_CANCELLED'), 'notice');
            } else {
                $this->setMessage(Text::_('COM_WEBGBOOKING_NO_ROWS_SELECTED'), 'warning');
            }
        } catch (\Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_webgbooking&view=bookings', false));
    }

    /** Tell the admin what the cancellation did besides changing the status. */
    private function reportCancel(array $r): void
    {
        $app = Factory::getApplication();

        if (empty($r['engine'])) {
            $app->enqueueMessage(Text::_('COM_WEBGBOOKING_CANCEL_NO_ENGINE'), 'warning');

            return;
        }
        if (($r['mail_ok'] ?? 0) > 0) {
            $app->enqueueMessage(Text::plural('COM_WEBGBOOKING_CANCEL_MAIL_SENT', (int) $r['mail_ok']), 'message');
        }
        if (($r['google_ok'] ?? 0) > 0) {
            $app->enqueueMessage(Text::plural('COM_WEBGBOOKING_CANCEL_GOOGLE_DELETED', (int) $r['google_ok']), 'message');
        }
        if (($r['refund'] ?? 0) > 0) {
            $app->enqueueMessage(Text::plural('COM_WEBGBOOKING_CANCEL_REFUND_DUE', (int) $r['refund']), 'warning');
        }
        if (($r['mail_fail'] ?? 0) > 0) {
            $app->enqueueMessage(Text::plural('COM_WEBGBOOKING_CANCEL_MAIL_FAILED', (int) $r['mail_fail']), 'warning');
        }
        if (($r['google_fail'] ?? 0) > 0) {
            $app->enqueueMessage(Text::plural('COM_WEBGBOOKING_CANCEL_GOOGLE_FAILED', (int) $r['google_fail']), 'warning');
        }
    }

    public function delete()
    {
        $this->checkToken();
        $this->requireRight('core.delete');

        $ids = $this->cid();

        try {
            $model = $this->getModel('Booking');
            $n     = $ids ? $model->deleteItems($ids) : 0;
            if ($n > 0) {
                $this->setMessage(Text::plural('COM_WEBGBOOKING_N_BOOKINGS_DELETED', $n));
                $app = Factory::getApplication();
                if (($model->deleteReport['google_ok'] ?? 0) > 0) {
                    $app->enqueueMessage(Text::plural('COM_WEBGBOOKING_CANCEL_GOOGLE_DELETED', (int) $model->deleteReport['google_ok']), 'message');
                }
                if (($model->deleteReport['google_fail'] ?? 0) > 0) {
                    $app->enqueueMessage(Text::plural('COM_WEBGBOOKING_CANCEL_GOOGLE_FAILED', (int) $model->deleteReport['google_fail']), 'warning');
                }
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
    private function requireRight(string $action): void
    {
        if (!Factory::getApplication()->getIdentity()->authorise($action, 'com_webgbooking')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
