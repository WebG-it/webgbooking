<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$s = $this->status;
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="p-3" style="max-width:820px">

    <?php if (!empty($s->error)) : ?>
        <div class="alert alert-danger"><strong><?php echo Text::_('COM_WEBGBOOKING_ERROR'); ?></strong> <?php echo $e($s->error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <h2 class="card-title h4"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_HEADING'); ?></h2>
            <p class="text-muted"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_WHAT'); ?></p>

            <?php if (!$s->hasClientId) : ?>
                <div class="alert alert-warning"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_NO_CREDS'); ?></div>

            <?php elseif (!$s->connected) : ?>
                <?php // Never connected: one explanation line and ONE primary action. ?>
                <p><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_INTRO'); ?></p>
                <a class="btn btn-primary btn-lg" href="<?php echo $e($s->connectUrl); ?>">
                    <span class="icon-google" aria-hidden="true"></span>
                    <?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CONNECT'); ?>
                </a>

            <?php elseif ($s->expired) : ?>
                <?php // Google refused the saved token: not "connected", and the only way out is to reconnect (no Disconnect step). ?>
                <div class="alert alert-warning">
                    <h3 class="h5 alert-heading"><span class="icon-warning" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_GOOGLE_EXPIRED_TITLE'); ?></h3>
                    <p class="mb-3"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_EXPIRED_TEXT'); ?></p>
                    <a class="btn btn-primary btn-lg" href="<?php echo $e($s->connectUrl); ?>">
                        <span class="icon-google" aria-hidden="true"></span>
                        <?php echo Text::_('COM_WEBGBOOKING_GOOGLE_RECONNECT'); ?>
                    </a>
                </div>

            <?php else : ?>
                <div class="alert alert-success">
                    <span class="icon-check" aria-hidden="true"></span>
                    <?php echo Text::sprintf('COM_WEBGBOOKING_GOOGLE_CONNECTED_AS', $e($s->email)); ?>
                </div>

                <?php if (!empty($s->calendars)) : ?>
                    <h3 class="h5 mt-4"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_HEADING'); ?></h3>
                    <p class="text-muted"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_INTRO'); ?></p>
                    <form action="<?php echo Route::_('index.php?option=com_webgbooking&task=google.savecalendars'); ?>" method="post">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_NAME'); ?></th>
                                    <th class="text-center"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_BUSY'); ?></th>
                                    <th class="text-center"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_WRITE'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($s->calendars as $c) : ?>
                                <tr>
                                    <td>
                                        <?php echo $e($c->summary); ?>
                                        <?php if ($c->primary) : ?> <span class="badge bg-secondary"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_PRIMARY'); ?></span><?php endif; ?>
                                    </td>
                                    <td class="text-center"><input type="checkbox" name="read[]" value="<?php echo $e($c->id); ?>"<?php echo $c->read ? ' checked' : ''; ?>></td>
                                    <td class="text-center"><input type="radio" name="write" value="<?php echo $e($c->id); ?>"<?php echo $c->write ? ' checked' : ''; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_SAVE'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                <?php elseif (!empty($s->apiProblem)) : ?>
                    <?php // One clear line; what Google actually said is in administrator/logs/webgbooking.php. ?>
                    <div class="alert alert-warning"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_ERROR'); ?></div>
                <?php endif; ?>

                <?php // Optional: a copy of every booking in a Google Sheet. Closed by default; nothing is written unless filled in. ?>
                <details class="mt-4 border rounded p-3"<?php echo !empty($s->sheetErr) ? ' open' : ''; ?>>
                    <summary class="fw-semibold"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_HEADING'); ?></summary>
                    <?php if (!empty($s->sheetErr)) : ?>
                        <?php // One clear line per kind of problem; what Google actually said is never shown. ?>
                        <div class="alert alert-warning mt-3"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_ERR_' . strtoupper($s->sheetErr)); ?></div>
                    <?php endif; ?>
                    <p class="text-muted mt-3"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_INTRO'); ?></p>

                    <form action="<?php echo Route::_('index.php?option=com_webgbooking&task=google.savesheet'); ?>" method="post">
                        <div class="mb-3">
                            <label class="form-label" for="wgb_sheet_manual"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_MANUAL'); ?></label>
                            <input type="text" id="wgb_sheet_manual" name="sheet_id_manual" class="form-control"
                                   value="<?php echo $e($s->sheetId); ?>"
                                   placeholder="https://docs.google.com/spreadsheets/d/...  /  1AbC...xyz">
                            <small class="form-text text-muted"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_MANUAL_HINT'); ?></small>
                        </div>

                        <div class="row g-2 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label" for="wgb_sheet_tab"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_TAB'); ?></label>
                                <input type="text" id="wgb_sheet_tab" name="sheet_tab" class="form-control" list="wgb_sheet_tabs"
                                       value="<?php echo $e($s->sheetTab); ?>"
                                       placeholder="<?php echo $e(Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_FIRSTTAB')); ?>">
                                <datalist id="wgb_sheet_tabs">
                                    <?php foreach ($s->tabs as $t) : ?>
                                        <option value="<?php echo $e($t); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-secondary w-100"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_SAVE'); ?></button>
                            </div>
                        </div>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                </details>

                <hr>
                <form action="<?php echo Route::_('index.php?option=com_webgbooking&task=google.disconnect'); ?>" method="post">
                    <button type="submit" class="btn btn-outline-danger"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_DISCONNECT'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            <?php endif; ?>

            <hr>
            <p class="small text-muted mb-1"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_REDIRECT_HINT'); ?></p>
            <code style="word-break:break-all"><?php echo $e($s->redirect); ?></code>
        </div>
    </div>
</div>
