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
use Joomla\CMS\Session\Session;

/** @var \WebG\Component\Webgbooking\Administrator\View\Email\HtmlView $this */
$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_webgbooking');
$wa->useScript('keepalive')->useScript('com_webgbooking.emailtexts');

$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$d = $this->data;
Text::script('COM_WEBGBOOKING_EM_RESTORE_CONFIRM');
Text::script('COM_WEBGBOOKING_EM_PREVIEW_FAILED');
?>
<div class="wgb-em">
<?php if (!$d) : ?>
    <div class="alert alert-warning" role="alert"><?php echo Text::_('COM_WEBGBOOKING_EM_NOENGINE'); ?></div>
<?php else : ?>
    <form action="<?php echo Route::_('index.php?option=com_webgbooking&view=email&key=' . rawurlencode($d['key']) . '&lang=' . rawurlencode($d['lang'])); ?>" method="post" name="adminForm" id="adminForm" class="form-validate"
          data-preview-url="<?php echo $e(Route::_('index.php?option=com_webgbooking&task=email.preview&format=raw', false)); ?>"
          data-test-url="<?php echo $e(Route::_('index.php?option=com_webgbooking&task=email.sendtest&format=raw', false)); ?>"
          data-token="<?php echo $e(Session::getFormToken()); ?>">
        <p class="text-muted"><?php echo $e($d['help']); ?></p>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span class="fw-bold"><?php echo Text::_('COM_WEBGBOOKING_EM_LANGUAGE'); ?></span>
            <?php foreach ($this->languages as $l) : ?>
                <a class="btn btn-sm <?php echo $l === $d['lang'] ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo Route::_('index.php?option=com_webgbooking&view=email&key=' . rawurlencode($d['key']) . '&lang=' . rawurlencode($l)); ?>"<?php echo $l === $d['lang'] ? ' aria-current="true"' : ''; ?>><?php echo $e($l); ?></a>
            <?php endforeach; ?>
            <?php if ($d['stored']) : ?>
                <span class="badge bg-info ms-2"><?php echo Text::_('COM_WEBGBOOKING_EM_STATE_CHANGED'); ?></span>
            <?php else : ?>
                <span class="badge bg-secondary ms-2"><?php echo Text::_('COM_WEBGBOOKING_EM_STATE_DEFAULT'); ?></span>
            <?php endif; ?>
        </div>

        <?php if ($d['legacy'] !== '') : ?>
            <div class="alert alert-warning" role="alert">
                <p class="mb-2"><?php echo Text::_('COM_WEBGBOOKING_EM_LEGACY'); ?></p>
                <?php if ($this->canEdit) : ?>
                    <button type="submit" class="btn btn-warning btn-sm" data-task="email.convert"><?php echo Text::_('COM_WEBGBOOKING_EM_CONVERT'); ?></button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <?php foreach ($d['fields'] as $f) : ?>
                    <?php echo $this->form->renderField($f); ?>
                <?php endforeach; ?>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h2 class="h5 card-title"><?php echo Text::_('COM_WEBGBOOKING_EM_PLACEHOLDERS'); ?></h2>
                        <p class="small text-muted"><?php echo Text::_('COM_WEBGBOOKING_EM_PLACEHOLDERS_HELP'); ?></p>
                        <label class="form-label" for="wgb-ph"><?php echo Text::_('COM_WEBGBOOKING_EM_INSERT'); ?></label>
                        <select id="wgb-ph" class="form-select" data-wgb-insert>
                            <option value=""><?php echo Text::_('COM_WEBGBOOKING_EM_INSERT_PICK'); ?></option>
                            <optgroup label="<?php echo $e(Text::_('COM_WEBGBOOKING_EM_PH_TEXT')); ?>">
                                <?php foreach ($d['placeholders'] as $p => $desc) : ?>
                                    <?php if (\in_array($p, \WebG\Plugin\System\Webgbooking\Mail\EmailTemplates::TEXT_PLACEHOLDERS, true)) : ?>
                                        <option value="{<?php echo $e($p); ?>}">{<?php echo $e($p); ?>} = <?php echo $e($desc); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="<?php echo $e(Text::_('COM_WEBGBOOKING_EM_PH_BLOCKS')); ?>">
                                <?php foreach ($d['placeholders'] as $p => $desc) : ?>
                                    <?php if (\in_array($p, $d['blocks'], true)) : ?>
                                        <option value="{<?php echo $e($p); ?>}">{<?php echo $e($p); ?>} = <?php echo $e($desc); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <details class="mt-3">
                            <summary class="small"><?php echo Text::_('COM_WEBGBOOKING_EM_PH_LIST'); ?></summary>
                            <dl class="small mt-2 mb-0">
                                <?php foreach ($d['placeholders'] as $p => $desc) : ?>
                                    <dt class="fw-bold">{<?php echo $e($p); ?>}</dt>
                                    <dd class="mb-1"><?php echo $e($desc); ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        </details>
                    </div>
                </div>
                <div class="d-grid gap-2 mt-3">
                    <button type="button" class="btn btn-outline-primary" data-wgb-preview><span class="icon-eye" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_EM_PREVIEW'); ?></button>
                    <button type="button" class="btn btn-outline-secondary" data-wgb-test><span class="icon-envelope" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_EM_SEND_TEST'); ?></button>
                </div>
                <p class="small text-muted mt-2"><?php echo Text::_('COM_WEBGBOOKING_EM_SAMPLE_NOTE'); ?></p>
            </div>
        </div>

        <section id="wgb-preview" class="mt-4" hidden aria-live="polite">
            <h2 class="h5"><?php echo Text::_('COM_WEBGBOOKING_EM_PREVIEW'); ?></h2>
            <p class="mb-2"><strong><?php echo Text::_('COM_WEBGBOOKING_EMF_SUBJECT'); ?>:</strong> <span id="wgb-preview-subject"></span></p>
            <iframe id="wgb-preview-frame" title="<?php echo $e(Text::_('COM_WEBGBOOKING_EM_PREVIEW')); ?>" sandbox="" class="w-100 border rounded bg-white" style="min-height:640px"></iframe>
        </section>

        <input type="hidden" name="key" value="<?php echo $e($d['key']); ?>">
        <input type="hidden" name="lang" value="<?php echo $e($d['lang']); ?>">
        <input type="hidden" name="task" value="">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
<?php endif; ?>
</div>
