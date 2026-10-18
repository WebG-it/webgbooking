<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

$i = $this->item;

$badge = [
    'pending'   => 'bg-warning',
    'confirmed' => 'bg-success',
    'cancelled' => 'bg-danger',
    'completed' => 'bg-info',
    'no-show'   => 'bg-dark',
][$i->status] ?? 'bg-secondary';

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$row = static function (string $label, string $value, bool $raw = false) use ($e) {
    if ($value === '') {
        return;
    }
    echo '<tr><th scope="row" style="width:200px;color:#6a6a6a;font-weight:600;">' . $e($label) . '</th><td>' . ($raw ? $value : $e($value)) . '</td></tr>';
};

$custom = json_decode((string) ($i->custom_data ?? ''), true);
$manageLink = Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' . rawurlencode((string) ($i->manage_token ?? ''));
?>
<form action="<?php echo \Joomla\CMS\Router\Route::_('index.php?option=com_webgbooking&view=booking&id=' . (int) $i->id); ?>" method="post" name="adminForm" id="adminForm">
    <input type="hidden" name="task" value="">
    <input type="hidden" name="id" value="<?php echo (int) $i->id; ?>">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
</form>
<div class="p-3" style="max-width:820px">
    <div class="card">
        <div class="card-body">
            <h2 class="card-title h4">
                <?php echo $e($i->customer_name); ?>
                <span class="badge <?php echo $badge; ?> align-middle"><?php echo $e($i->status); ?></span>
            </h2>

            <table class="table table-striped align-middle mt-3">
                <tbody>
                <?php
                $row(Text::_('COM_WEBGBOOKING_COL_DATE'), (string) $i->booking_date);
                $row(Text::_('COM_WEBGBOOKING_COL_TIME'), (string) $i->booking_time);
                $row(Text::_('COM_WEBGBOOKING_COL_NAME'), (string) $i->customer_name);
                $row(Text::_('COM_WEBGBOOKING_COL_EMAIL'), '<a href="mailto:' . $e($i->customer_email) . '">' . $e($i->customer_email) . '</a>', true);
                $row(Text::_('COM_WEBGBOOKING_COL_PHONE'), (string) ($i->customer_phone ?? ''));
                $row(Text::_('COM_WEBGBOOKING_FIELD_GUEST'), (string) ($i->guest_email ?? ''));
                $row(Text::_('COM_WEBGBOOKING_FIELD_NOTES'), nl2br($e($i->notes ?? '')), true);

                if (\is_array($custom)) {
                    foreach ($custom as $it) {
                        if (\is_array($it) && isset($it['l'])) {
                            $row((string) $it['l'], (string) ($it['v'] ?? ''));
                        }
                    }
                }

                if (!empty($i->event_name)) {
                    $row(Text::_('COM_WEBGBOOKING_DETAIL_EVENT'), (string) $i->event_name);
                }
                $isPhone = ($i->location ?? '') === 'phone';
                $row(Text::_('COM_WEBGBOOKING_COL_LOCATION'), $isPhone ? Text::_('COM_WEBGBOOKING_LOC_PHONE') : Text::_('COM_WEBGBOOKING_LOC_MEET'));
                if (!empty($i->duration_min)) {
                    $row(Text::_('COM_WEBGBOOKING_DETAIL_DURATION'), (int) $i->duration_min . ' min');
                }
                $rs = json_decode((string) ($i->reminders_sent ?? ''), true);
                $rc = json_decode((string) ($i->reminder_cfg ?? ''), true);
                if (\is_array($rc) && !empty($rc['on']) && !empty($rc['times'])) {
                    $row(Text::_('COM_WEBGBOOKING_DETAIL_REMINDERS'), Text::sprintf('COM_WEBGBOOKING_DETAIL_REMINDERS_VAL', implode(', ', array_map('intval', $rc['times'])), \is_array($rs) && $rs ? implode(', ', array_map('intval', $rs)) : '-'));
                }
                if (!empty($i->meeting_url)) {
                    $row(Text::_('COM_WEBGBOOKING_COL_MEETING'), '<a href="' . $e($i->meeting_url) . '" target="_blank" rel="noopener noreferrer">' . $e($i->meeting_url) . '</a>', true);
                }
                $row(Text::_('COM_WEBGBOOKING_DETAIL_SOURCE'), (string) ($i->source_url ?? ''));
                $row(Text::_('COM_WEBGBOOKING_COL_CREATED'), (string) $i->created);
                if (!empty($i->updated) && $i->updated !== $i->created && strpos((string) $i->updated, '0000') !== 0) {
                    $row(Text::_('COM_WEBGBOOKING_DETAIL_UPDATED'), (string) $i->updated);
                }
                if (!empty($i->manage_token)) {
                    $row(Text::_('COM_WEBGBOOKING_DETAIL_MANAGE'), '<a href="' . $e($manageLink) . '" target="_blank" rel="noopener noreferrer">' . $e(Text::_('COM_WEBGBOOKING_DETAIL_MANAGE_OPEN')) . '</a>', true);
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
