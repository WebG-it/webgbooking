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
?>
<div class="p-3" style="max-width:820px">

    <?php if (!empty($s->error)) : ?>
        <div class="alert alert-danger"><strong><?php echo Text::_('COM_WEBGBOOKING_ERROR'); ?></strong> <?php echo htmlspecialchars((string) $s->error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <h2 class="card-title h4"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_HEADING'); ?></h2>
            <p class="text-muted"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_WHAT'); ?></p>

            <?php if (!$s->hasClientId) : ?>
                <div class="alert alert-warning"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_NO_CREDS'); ?></div>

            <?php elseif ($s->connected) : ?>
                <div class="alert alert-success">
                    <span class="icon-check" aria-hidden="true"></span>
                    <?php echo Text::sprintf('COM_WEBGBOOKING_GOOGLE_CONNECTED_AS', htmlspecialchars((string) $s->email, ENT_QUOTES, 'UTF-8')); ?>
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
                                        <?php echo htmlspecialchars((string) $c->summary, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($c->primary) : ?> <span class="badge bg-secondary"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_PRIMARY'); ?></span><?php endif; ?>
                                    </td>
                                    <td class="text-center"><input type="checkbox" name="read[]" value="<?php echo htmlspecialchars((string) $c->id, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $c->read ? ' checked' : ''; ?>></td>
                                    <td class="text-center"><input type="radio" name="write" value="<?php echo htmlspecialchars((string) $c->id, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $c->write ? ' checked' : ''; ?>></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CAL_SAVE'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                <?php elseif (!empty($s->apiError)) : ?>
                    <div class="alert alert-warning"><?php echo Text::sprintf('COM_WEBGBOOKING_GOOGLE_CAL_ERROR', htmlspecialchars((string) $s->apiError, ENT_QUOTES, 'UTF-8')); ?></div>
                <?php endif; ?>

                <h3 class="h5 mt-4"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_HEADING'); ?></h3>
                <p class="text-muted"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_INTRO'); ?></p>
                <?php if (!empty($s->sheetsError)) : ?>
                    <div class="alert alert-warning"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_NEEDSCOPE'); ?></div>
                <?php endif; ?>
                <form action="<?php echo Route::_('index.php?option=com_webgbooking&task=google.savesheet'); ?>" method="post" class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="wgb_sheet_id"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_FILE'); ?></label>
                        <select id="wgb_sheet_id" name="sheet_id" class="form-select" onchange="this.form.submit();">
                            <option value=""><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_NONE'); ?></option>
                            <?php foreach ($s->sheets as $sh) : ?>
                                <option value="<?php echo htmlspecialchars((string) $sh->id, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $sh->id === $s->sheetId ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $sh->name, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="wgb_sheet_tab"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_TAB'); ?></label>
                        <select id="wgb_sheet_tab" name="sheet_tab" class="form-select"<?php echo empty($s->tabs) ? ' disabled' : ''; ?>>
                            <option value=""><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_FIRSTTAB'); ?></option>
                            <?php foreach ($s->tabs as $t) : ?>
                                <option value="<?php echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $t === $s->sheetTab ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_SHEET_SAVE'); ?></button>
                    </div>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>

                <hr>
                <form action="<?php echo Route::_('index.php?option=com_webgbooking&task=google.disconnect'); ?>" method="post">
                    <button type="submit" class="btn btn-outline-danger"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_DISCONNECT'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>

            <?php else : ?>
                <p><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_INTRO'); ?></p>
                <a class="btn btn-primary btn-lg" href="<?php echo htmlspecialchars((string) $s->connectUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="icon-google" aria-hidden="true"></span>
                    <?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CONNECT'); ?>
                </a>
            <?php endif; ?>

            <hr>
            <p class="small text-muted mb-1"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_REDIRECT_HINT'); ?></p>
            <code style="word-break:break-all"><?php echo htmlspecialchars((string) $s->redirect, ENT_QUOTES, 'UTF-8'); ?></code>
        </div>
    </div>
</div>
