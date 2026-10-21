<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;



use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
// Italian writes days and months in lower case ("lunedì 26 ottobre 2026"); the core language file gives them capitalised
$lcIt = static fn(string $t): string => strncmp((string) Factory::getApplication()->getLanguage()->getTag(), 'it', 2) === 0 ? mb_strtolower($t) : $t;

/** @var \WebG\Component\Webgbooking\Administrator\View\Booking\HtmlView $this */
$i = $this->item;

$this->getDocument()->getWebAssetManager()->useStyle('com_webgbooking.booking-detail');

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// ---------------------------------------------------------------- status
$badgeCls = [
    'pending'          => 'text-bg-warning',
    'confirmed'        => 'text-bg-success',
    'cancelled'        => 'text-bg-danger',
    'completed'        => 'text-bg-info',
    'no-show'          => 'text-bg-secondary',
    'awaiting_payment' => 'text-bg-info',
    'expired'          => 'text-bg-secondary',
    'needs_review'     => 'text-bg-danger',
][$i->status] ?? 'text-bg-secondary';
$stKey  = 'COM_WEBGBOOKING_STATUS_' . strtoupper(str_replace('-', '', (string) $i->status));
$stText = Text::_($stKey) !== $stKey ? Text::_($stKey) : (string) $i->status;
$status = (string) $i->status;

// ---------------------------------------------------------------- time helpers (DB booking times are site time; created/paid/updated are UTC)
$siteTz = new \DateTimeZone('UTC');
try {
    $siteTz = new \DateTimeZone((string) (Factory::getApplication()->get('offset') ?: 'UTC'));
} catch (\Throwable $ex) {
}
$utc = new \DateTimeZone('UTC');

$fromUtc = static function ($sql) use ($utc, $siteTz): ?Date {
    $sql = trim((string) $sql);
    if ($sql === '' || strpos($sql, '0000') === 0) {
        return null;
    }
    try {
        return (new Date($sql, $utc))->setTimezone($siteTz);
    } catch (\Throwable $ex) {
        return null;
    }
};

$start = null;
try {
    $start = new Date(trim((string) $i->booking_date) . ' ' . trim((string) $i->booking_time), $siteTz);
} catch (\Throwable $ex) {
}
$dur   = (int) ($i->duration_min ?? 0);
$end   = $start && $dur > 0 ? (clone $start)->modify('+' . $dur . ' minutes') : null;
$now   = time();
$short = static fn(Date $d): string => $d->format('j M Y, H:i', true, true);
$timeTag = static fn(Date $d, string $text): string => '<time datetime="' . htmlspecialchars($d->format('Y-m-d\TH:i:sP', true), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</time>';

// human reminder offsets: never "1440 minutes"
$human = static function (int $m): string {
    if ($m >= 1440 && $m % 1440 === 0) {
        return Text::plural('COM_WEBGBOOKING_DUR_DAYS', intdiv($m, 1440));
    }
    if ($m >= 60 && $m % 60 === 0) {
        return Text::plural('COM_WEBGBOOKING_DUR_HOURS', intdiv($m, 60));
    }

    return Text::plural('COM_WEBGBOOKING_DUR_MINUTES', $m);
};

// ---------------------------------------------------------------- data
$isPhone  = ($i->location ?? '') === 'phone';
$phoneRaw = trim((string) ($i->customer_phone ?? ''));
$telHref  = preg_replace('/[^0-9+]/', '', $phoneRaw);
$meetUrl  = trim((string) ($i->meeting_url ?? ''));
$meetOk   = (bool) preg_match('#^https://#i', $meetUrl);
$active   = !\in_array($status, ['cancelled', 'expired', 'no-show'], true);
$custom   = json_decode((string) ($i->custom_data ?? ''), true);
$answers  = [];
if (\is_array($custom)) {
    foreach ($custom as $it) {
        if (\is_array($it) && isset($it['l']) && trim((string) ($it['v'] ?? '')) !== '') {
            $answers[] = [(string) $it['l'], (string) $it['v']];
        }
    }
}
$notes = trim((string) ($i->notes ?? ''));

$custTz = null;
$custTzName = trim((string) ($i->customer_tz ?? ''));
if ($custTzName !== '') {
    try {
        $custTz = new \DateTimeZone($custTzName);
    } catch (\Throwable $ex) {
        $custTz = null;
        $custTzName = '';
    }
}
$custLocal = '';
if ($custTz && $start) {
    $probe = new \DateTime('@' . $start->getTimestamp());
    if ($custTz->getOffset($probe) !== $siteTz->getOffset($probe)) {
        $loc = (new \DateTime('@' . $start->getTimestamp()))->setTimezone($custTz);
        $sameDay   = $loc->format('Y-m-d') === $start->format('Y-m-d', true);
        $custLocal = $sameDay ? $loc->format('H:i') : (new Date($loc->format('Y-m-d H:i:s'), $custTz))->format('j M, H:i', true, true);
    }
}

$hasPay = !empty($i->payment_status) && (int) ($i->amount_cents ?? 0) > 0;
$amount = $hasPay ? number_format(((int) $i->amount_cents) / 100, 2, ',', '.') . ' ' . strtoupper((string) $i->currency) : '';

// ---------------------------------------------------------------- history
$events = [];
$add = static function (?Date $when, string $label, bool $future = false) use (&$events): void {
    if ($when) {
        $events[] = ['ts' => $when->getTimestamp(), 'd' => $when, 'label' => $label, 'future' => $future];
    }
};
$created = $fromUtc($i->created);
$add($created, Text::_('COM_WEBGBOOKING_HIST_CREATED'));
if ($hasPay && !empty($i->paid_at)) {
    $add($fromUtc($i->paid_at), Text::sprintf('COM_WEBGBOOKING_HIST_PAID', $amount));
}
$steps = json_decode((string) ($i->fx_steps ?? ''), true);
$steps = \is_array($steps) ? $steps : [];
$fxAt  = $fromUtc($i->fx_last ?? '') ?: $created;
$okStep = static fn($k): bool => !empty($steps[$k]) && !\in_array($steps[$k], ['error', 'skip', 'needed'], true);
if ($okStep('google') && !empty($i->google_event_id)) {
    $add($fxAt, Text::_('COM_WEBGBOOKING_HIST_GOOGLE'));
}
if ($okStep('mail_customer')) {
    $add($fxAt, Text::_('COM_WEBGBOOKING_HIST_MAIL_CUSTOMER'));
}
if ($okStep('mail_guest')) {
    $add($fxAt, Text::_('COM_WEBGBOOKING_HIST_MAIL_GUEST'));
}
if (($steps['linkmail'] ?? '') === 'sent') {
    $add($fxAt, Text::_('COM_WEBGBOOKING_HIST_LINKMAIL'));
}
if ($okStep('mail_staff')) {
    $add($fxAt, Text::_('COM_WEBGBOOKING_HIST_MAIL_STAFF'));
}

$rc = json_decode((string) ($i->reminder_cfg ?? ''), true);
$rs = json_decode((string) ($i->reminders_sent ?? ''), true);
$rs = \is_array($rs) ? array_map('intval', $rs) : [];
if ($start && \is_array($rc) && !empty($rc['on']) && !empty($rc['times']) && \is_array($rc['times'])) {
    $times = array_values(array_unique(array_filter(array_map('intval', $rc['times']), static fn($m) => $m > 0)));
    rsort($times);
    foreach ($times as $m) {
        $at = (clone $start)->modify('-' . $m . ' minutes');
        if (\in_array($m, $rs, true)) {
            // offsets already behind us when the booking was made were never sent
            if ($created && $at->getTimestamp() > $created->getTimestamp() && $at->getTimestamp() <= $now) {
                $add($at, Text::sprintf('COM_WEBGBOOKING_HIST_REM_SENT', $human($m)));
            }
        } elseif ($at->getTimestamp() > $now && \in_array($status, ['pending', 'confirmed'], true)) {
            $add($at, Text::sprintf('COM_WEBGBOOKING_HIST_REM_NEXT', $human($m)), true);
        }
    }
}

$updated = $fromUtc($i->updated ?? '');
if ($updated && (string) $i->updated !== (string) $i->created) {
    if ($status === 'cancelled') {
        $add($updated, Text::_('COM_WEBGBOOKING_HIST_CANCELLED'));
    } elseif (\in_array($status, ['completed', 'no-show', 'expired'], true)) {
        $add($updated, Text::sprintf('COM_WEBGBOOKING_HIST_STATUS', $stText));
    } elseif ((int) ($i->seq ?? 0) > 0) {
        $add($updated, Text::_('COM_WEBGBOOKING_HIST_MOVED'));
    }
}
usort($events, static fn($a, $b) => $a['ts'] <=> $b['ts']);

// ---------------------------------------------------------------- technical
$manageLink = Uri::root() . 'index.php?option=com_ajax&group=system&plugin=webgbooking&format=html&action=manage&token=' . rawurlencode((string) ($i->manage_token ?? ''));
$fxOrder    = ['google', 'mail_customer', 'mail_guest', 'mail_staff', 'linkmail', 'newsletter', 'brevo', 'webhook', 'sheet'];
$fxStateOf  = static function ($v): string {
    if ($v === 'error') {
        return 'ERROR';
    }
    if ($v === 'skip') {
        return 'SKIP';
    }
    if ($v === 'needed') {
        return 'PENDING';
    }

    return 'OK';
};
$url = trim((string) ($i->source_url ?? ''));

$dl = static function (string $label, string $valueHtml): string {
    return $valueHtml === '' ? '' : '<div class="wgb-bk-row"><dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt><dd>' . $valueHtml . '</dd></div>';
};
?>
<form action="<?php echo Route::_('index.php?option=com_webgbooking&view=booking&id=' . (int) $i->id); ?>" method="post" name="adminForm" id="adminForm">
    <input type="hidden" name="task" value="">
    <input type="hidden" name="id" value="<?php echo (int) $i->id; ?>">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

<div class="wgb-bk">
    <header class="wgb-bk-hero">
        <?php if (!empty($i->event_name)) : ?>
            <p class="wgb-bk-eyebrow"><?php echo $e($i->event_name); ?></p>
        <?php endif; ?>
        <h2 class="wgb-bk-name">
            <span class="wgb-bk-name-text"><?php echo $e($i->customer_name); ?></span>
            <span class="badge <?php echo $badgeCls; ?> wgb-bk-status"><?php echo $e($stText); ?></span>
        </h2>
        <p class="wgb-bk-when">
            <?php if ($start) : ?>
                <?php echo $timeTag($start, $lcIt($start->format('l j F Y', true, true))) . ', <span class="wgb-bk-nowrap">' . $e($start->format('H:i', true) . ($end ? '-' . $end->format('H:i', true) : '')) . '</span>'; ?>
            <?php else : ?>
                <?php echo $e(trim($i->booking_date . ' ' . $i->booking_time)); ?>
            <?php endif; ?>
        </p>
        <p class="wgb-bk-meta">
            <?php
            $meta = [];
            if ($dur > 0) {
                $meta[] = Text::sprintf('COM_WEBGBOOKING_DETAIL_MIN', $dur);
            }
            $meta[] = Text::_($isPhone ? 'COM_WEBGBOOKING_LOC_PHONE' : 'COM_WEBGBOOKING_LOC_MEET');
            echo $e(implode(', ', $meta));
            ?>
        </p>

        <?php if ($active) : ?>
            <div class="wgb-bk-cta">
                <?php if ($isPhone) : ?>
                    <?php if ($telHref !== '') : ?>
                        <a class="btn btn-primary btn-lg wgb-bk-btn" href="tel:<?php echo $e($telHref); ?>">
                            <span class="icon-phone" aria-hidden="true"></span>
                            <?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_CALL')); ?>
                            <span class="wgb-bk-btn-sub"><?php echo $e($phoneRaw); ?></span>
                        </a>
                    <?php else : ?>
                        <p class="wgb-bk-note"><?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_CALL_NOPHONE')); ?></p>
                    <?php endif; ?>
                <?php elseif ($meetOk) : ?>
                    <a class="btn btn-primary btn-lg wgb-bk-btn" href="<?php echo $e($meetUrl); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_JOIN_MEET')); ?>
                    </a>
                <?php else : ?>
                    <p class="wgb-bk-note"><?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_MEET_PENDING')); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="wgb-bk-col wgb-bk-col-main">
        <?php if ($answers || $notes !== '') : ?>
            <section class="wgb-bk-card wgb-bk-answers" aria-labelledby="wgb-bk-h-answers">
                <h3 id="wgb-bk-h-answers"><?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_SEC_ANSWERS')); ?></h3>
                <dl class="wgb-bk-qa">
                    <?php foreach ($answers as [$q, $a]) : ?>
                        <div class="wgb-bk-qa-item">
                            <dt><?php echo $e($q); ?></dt>
                            <dd><?php echo nl2br($e($a)); ?></dd>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($notes !== '') : ?>
                        <div class="wgb-bk-qa-item">
                            <dt><?php echo $e(Text::_('COM_WEBGBOOKING_FIELD_NOTES')); ?></dt>
                            <dd><?php echo nl2br($e($notes)); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>
        <?php endif; ?>

        <section class="wgb-bk-card wgb-bk-history" aria-labelledby="wgb-bk-h-history">
            <h3 id="wgb-bk-h-history"><?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_SEC_HISTORY')); ?></h3>
            <ol class="wgb-bk-timeline">
                <?php foreach ($events as $ev) : ?>
                    <li class="wgb-bk-tl-item<?php echo $ev['future'] ? ' is-future' : ''; ?>">
                        <span class="wgb-bk-tl-what"><?php echo $e($ev['label']); ?></span>
                        <span class="wgb-bk-tl-when"><?php echo $timeTag($ev['d'], $short($ev['d'])); ?><?php if ($ev['future']) : ?> <span class="wgb-bk-tag"><?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_UPCOMING')); ?></span><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>

    <div class="wgb-bk-col wgb-bk-col-side">
        <section class="wgb-bk-card wgb-bk-customer" aria-labelledby="wgb-bk-h-customer">
            <h3 id="wgb-bk-h-customer"><?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_SEC_CUSTOMER')); ?></h3>
            <dl class="wgb-bk-dl">
                <?php
                echo $dl(Text::_('COM_WEBGBOOKING_COL_EMAIL'), trim((string) $i->customer_email) !== '' ? '<a href="mailto:' . $e($i->customer_email) . '">' . $e($i->customer_email) . '</a>' : '');
                echo $dl(Text::_('COM_WEBGBOOKING_COL_PHONE'), $phoneRaw !== '' ? '<a href="tel:' . $e($telHref) . '">' . $e($phoneRaw) . '</a>' : '');
                $guest = trim((string) ($i->guest_email ?? ''));
                echo $dl(Text::_('COM_WEBGBOOKING_FIELD_GUEST'), $guest !== '' ? '<a href="mailto:' . $e($guest) . '">' . $e($guest) . '</a>' : '');
                echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_CUSTOMER_TZ'), $custTzName !== '' ? $e($custTzName) : '');
                echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_CUSTOMER_LOCAL'), $custLocal !== '' ? $e($custLocal) : '');
                ?>
            </dl>
        </section>

        <?php if ($hasPay) : ?>
            <section class="wgb-bk-card wgb-bk-pay" aria-labelledby="wgb-bk-h-pay">
                <h3 id="wgb-bk-h-pay"><?php echo $e(Text::_('COM_WEBGBOOKING_COL_PAYMENT')); ?></h3>
                <dl class="wgb-bk-dl">
                    <?php
                    echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_AMOUNT'), $e($amount));
                    $payKey = 'COM_WEBGBOOKING_PAY_' . strtoupper((string) $i->payment_status);
                    $payTxt = Text::_($payKey) !== $payKey ? Text::_($payKey) : (string) $i->payment_status;
                    echo $dl(Text::_('COM_WEBGBOOKING_COL_STATUS'), $e($payTxt . (($i->payment_status === 'paid' && $status === 'cancelled') ? ', ' . Text::_('COM_WEBGBOOKING_PAY_REFUND_DUE') : '')));
                    $paidAt = !empty($i->paid_at) ? $fromUtc($i->paid_at) : null;
                    echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_PAID_AT'), $paidAt ? $timeTag($paidAt, $short($paidAt)) : '');
                    $hold = (!empty($i->hold_until) && $status === 'awaiting_payment') ? $fromUtc($i->hold_until) : null;
                    echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_HOLD_UNTIL'), $hold ? $timeTag($hold, $short($hold)) : '');
                    ?>
                </dl>
            </section>
        <?php endif; ?>
    </div>

    <details class="wgb-bk-card wgb-bk-tech">
        <summary><?php echo $e(Text::_('COM_WEBGBOOKING_DETAIL_SEC_TECH')); ?></summary>
        <dl class="wgb-bk-dl wgb-bk-dl-tech">
            <?php
            echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_TECH_ID'), '#' . (int) $i->id);
            if (!empty($i->fx_state)) {
                $fxl = '<ul class="wgb-bk-fx">';
                foreach ($fxOrder as $k) {
                    if (isset($steps[$k]) && $steps[$k] !== false && $steps[$k] !== '') {
                        $fxl .= '<li>' . $e(Text::_('COM_WEBGBOOKING_FXSTEP_' . strtoupper($k))) . ': ' . $e(Text::_('COM_WEBGBOOKING_FXSTATE_' . $fxStateOf($steps[$k]))) . '</li>';
                    }
                }
                $fxl .= '</ul>';
                $tries = (int) ($i->fx_tries ?? 0);
                echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_FX'), $e(Text::_('COM_WEBGBOOKING_FX_' . strtoupper((string) $i->fx_state))) . ($tries > 0 ? ', ' . $e(Text::sprintf('COM_WEBGBOOKING_DETAIL_TRIES', $tries)) : '') . ($fxl !== '<ul class="wgb-bk-fx"></ul>' ? $fxl : ''));
            }
            if ($url !== '') {
                echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_SOURCE'), preg_match('#^https?://#i', $url) ? '<a href="' . $e($url) . '" target="_blank" rel="noopener noreferrer">' . $e($url) . '</a>' : $e($url));
            }
            echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_GOOGLE_ID'), !empty($i->google_event_id) ? '<code>' . $e($i->google_event_id) . '</code>' : '');
            echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_STRIPE_PI'), !empty($i->stripe_payment_intent) ? '<code>' . $e($i->stripe_payment_intent) . '</code>' : '');
            echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_STRIPE_SESSION'), !empty($i->stripe_session_id) ? '<code>' . $e($i->stripe_session_id) . '</code>' : '');
            echo $dl(Text::_('COM_WEBGBOOKING_DETAIL_MANAGE'), !empty($i->manage_token) ? '<a href="' . $e($manageLink) . '" target="_blank" rel="noopener noreferrer">' . $e(Text::_('COM_WEBGBOOKING_DETAIL_MANAGE_OPEN')) . '</a>' : '');
            ?>
        </dl>
    </details>
</div>
