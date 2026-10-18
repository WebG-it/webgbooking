<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$listOrder = $this->escape($this->state->get('list.ordering', 'booking_date'));
$listDirn  = $this->escape($this->state->get('list.direction', 'DESC'));
$token     = Session::getFormToken();
$st        = $this->stats;

$badgeClass = [
    'pending'   => 'bg-warning',
    'confirmed' => 'bg-success',
    'cancelled' => 'bg-danger',
    'completed' => 'bg-info',
    'no-show'   => 'bg-dark',
    'awaiting_payment' => 'bg-info',
    'expired'   => 'bg-secondary',
    'needs_review' => 'bg-danger',
];
$stLabel = static function (string $st): string {
    $k = 'COM_WEBGBOOKING_STATUS_' . strtoupper(str_replace('-', '', $st));

    return Text::_($k) !== $k ? Text::_($k) : $st;
};
$payInfo = static function ($i): string {
    $ps = (string) ($i->payment_status ?? '');
    if ($ps === '' || (int) ($i->amount_cents ?? 0) <= 0) {
        return '';
    }
    $amt = number_format(((int) $i->amount_cents) / 100, 2, ',', '.') . ' ' . strtoupper((string) $i->currency);
    $lbl = Text::_('COM_WEBGBOOKING_PAY_' . strtoupper($ps));
    if ($ps === 'paid' && $i->status === 'cancelled') {
        $lbl = Text::_('COM_WEBGBOOKING_PAY_REFUND_DUE');
    }

    return $amt . ' · ' . $lbl;
};
?>
<?php foreach ((array) $this->alerts as $al) : ?>
    <div class="alert alert-<?php echo htmlspecialchars((string) $al[0], ENT_QUOTES, 'UTF-8'); ?>" role="alert"><?php echo htmlspecialchars((string) $al[1], ENT_QUOTES, 'UTF-8'); ?></div>
<?php endforeach; ?>
<div class="row mb-3 g-2">
    <?php
    $cards = [
        ['COM_WEBGBOOKING_STAT_TODAY', (int) $st->today, 'text-bg-primary'],
        ['COM_WEBGBOOKING_STAT_UPCOMING', (int) $st->upcoming, 'text-bg-light'],
        ['COM_WEBGBOOKING_STAT_PENDING', (int) $st->pending, 'text-bg-warning'],
        ['COM_WEBGBOOKING_STAT_CONFIRMED', (int) $st->confirmed, 'text-bg-success'],
        ['COM_WEBGBOOKING_STAT_TOTAL', (int) $st->total, 'text-bg-secondary'],
    ];
    foreach ($cards as $c) : ?>
        <div class="col">
            <div class="card <?php echo $c[2]; ?> text-center h-100">
                <div class="card-body py-2">
                    <div class="fs-3 fw-bold"><?php echo $c[1]; ?></div>
                    <div class="small text-uppercase"><?php echo Text::_($c[0]); ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="mb-3">
    <a class="btn btn-success" href="<?php echo Route::_('index.php?option=com_webgbooking&task=exportcsv&' . $token . '=1'); ?>">
        <span class="icon-file-csv" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_EXPORT_CSV'); ?>
    </a>
    <a class="btn btn-success" href="<?php echo Route::_('index.php?option=com_webgbooking&task=exportxlsx&' . $token . '=1'); ?>">
        <span class="icon-file-excel" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_EXPORT_XLSX'); ?>
    </a>
</div>

<form action="<?php echo Route::_('index.php?option=com_webgbooking&view=bookings'); ?>" method="post" name="adminForm" id="adminForm">
    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

    <div class="table-responsive">
        <table class="table" id="bookingList">
            <thead>
                <tr>
                    <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                    <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_COL_DATE', 'booking_date', $listDirn, $listOrder); ?></th>
                    <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_COL_TIME', 'booking_time', $listDirn, $listOrder); ?></th>
                    <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_COL_NAME', 'customer_name', $listDirn, $listOrder); ?></th>
                    <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_COL_EMAIL', 'customer_email', $listDirn, $listOrder); ?></th>
                    <th scope="col" class="d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_COL_PHONE', 'customer_phone', $listDirn, $listOrder); ?></th>
                    <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_WEBGBOOKING_COL_PAYMENT'); ?></th>
                    <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_WEBGBOOKING_COL_LOCATION'); ?></th>
                    <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_WEBGBOOKING_COL_MEETING'); ?></th>
                    <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_COL_STATUS', 'status', $listDirn, $listOrder); ?></th>
                    <th scope="col" class="d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_COL_CREATED', 'created', $listDirn, $listOrder); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($this->items)) : ?>
                    <tr><td colspan="11"><?php echo Text::_('COM_WEBGBOOKING_EMPTY'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($this->items as $rowNum => $i) : ?>
                        <?php
                        $cd       = json_decode((string) ($i->custom_data ?? ''), true);
                        $detail   = Route::_('index.php?option=com_webgbooking&view=booking&id=' . (int) $i->id);
                        $badge    = $badgeClass[$i->status] ?? 'bg-secondary';
                        ?>
                        <tr>
                            <td class="text-center"><?php echo HTMLHelper::_('grid.id', $rowNum, (int) $i->id); ?></td>
                            <td><?php echo htmlspecialchars((string) $i->booking_date, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $i->booking_time, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <a href="<?php echo $detail; ?>" class="fw-bold"><?php echo htmlspecialchars((string) $i->customer_name, ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php if (!empty($i->notes)) : ?>
                                    <span class="icon-comment text-muted ms-1" title="<?php echo htmlspecialchars((string) $i->notes, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></span>
                                <?php endif; ?>
                                <?php if (\is_array($cd) && $cd) : ?>
                                    <?php foreach ($cd as $it) : ?>
                                        <?php if (\is_array($it) && isset($it['l'])) : ?>
                                            <div class="small text-muted"><?php echo htmlspecialchars((string) $it['l'] . ': ' . (string) ($it['v'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><a href="mailto:<?php echo htmlspecialchars((string) $i->customer_email, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $i->customer_email, ENT_QUOTES, 'UTF-8'); ?></a></td>
                            <td class="d-none d-md-table-cell"><?php echo htmlspecialchars((string) ($i->customer_phone ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="d-none d-lg-table-cell">
                                <?php $pi = $payInfo($i); ?>
                                <?php if ($pi !== '') : ?>
                                    <div><?php echo htmlspecialchars($pi, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($i->stripe_payment_intent) || !empty($i->stripe_session_id)) : ?>
                                        <div class="small text-muted"><?php echo htmlspecialchars((string) ($i->stripe_payment_intent ?: $i->stripe_session_id), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <?php echo htmlspecialchars((($i->location ?? '') === 'phone' ? Text::_('COM_WEBGBOOKING_LOC_PHONE') : Text::_('COM_WEBGBOOKING_LOC_MEET')) . (!empty($i->duration_min) ? ' · ' . (int) $i->duration_min . ' min' : ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (($i->location ?? '') === 'phone' && !empty($i->customer_phone)) : ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string) $i->customer_phone, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell">
                                <?php if (!empty($i->meeting_url)) : ?>
                                    <a href="<?php echo htmlspecialchars((string) $i->meeting_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo Text::_('COM_WEBGBOOKING_MEETING_LINK'); ?></a>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($stLabel((string) $i->status), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td class="d-none d-md-table-cell"><?php echo htmlspecialchars((string) $i->created, ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($this->pagination)) : ?>
        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
