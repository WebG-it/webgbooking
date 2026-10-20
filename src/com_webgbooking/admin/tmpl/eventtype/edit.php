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
use WebG\Component\Webgbooking\Administrator\Helper\LinkHelper;

/** @var \WebG\Component\Webgbooking\Administrator\View\Eventtype\HtmlView $this */
$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_webgbooking');
$wa->useScript('keepalive')->useScript('form.validate')->useScript('com_webgbooking.copylink');

$e    = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$id   = (int) ($this->item->id ?? 0);
$slug = (string) ($this->item->slug ?? '');
?>
<form action="<?php echo Route::_('index.php?option=com_webgbooking&layout=edit&id=' . $id); ?>" method="post" name="adminForm" id="eventtype-form" class="form-validate">
    <?php if ($id > 0 && $slug !== '') : ?>
        <?php $short = LinkHelper::url($slug); $long = LinkHelper::url($slug, true); ?>
        <div class="alert alert-info" role="note">
            <p class="mb-2"><strong><?php echo Text::_('COM_WEBGBOOKING_ET_LINK_TITLE'); ?></strong></p>
            <div class="input-group mb-2">
                <input type="text" class="form-control" readonly value="<?php echo $e($short); ?>" id="wgb-link-short" aria-label="<?php echo $e(Text::_('COM_WEBGBOOKING_ET_LINK_TITLE')); ?>">
                <button type="button" class="btn btn-primary" data-wgb-copy="<?php echo $e($short); ?>" data-wgb-copied="<?php echo $e(Text::_('COM_WEBGBOOKING_ET_COPIED')); ?>">
                    <span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_ET_COPY'); ?>
                </button>
                <a class="btn btn-secondary" href="<?php echo $e($short); ?>" target="_blank" rel="noopener"><?php echo Text::_('COM_WEBGBOOKING_ET_OPEN'); ?></a>
            </div>
            <?php if ($long !== $short) : ?>
                <div class="small"><?php echo Text::_('COM_WEBGBOOKING_ET_LINK_ALT'); ?>
                    <code><?php echo $e($long); ?></code>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-wgb-copy="<?php echo $e($long); ?>" data-wgb-copied="<?php echo $e(Text::_('COM_WEBGBOOKING_ET_COPIED')); ?>"><?php echo Text::_('COM_WEBGBOOKING_ET_COPY'); ?></button>
                </div>
            <?php endif; ?>
            <p class="small mb-0 mt-2"><?php echo Text::_('COM_WEBGBOOKING_ET_SLUG_WARN'); ?></p>
        </div>
    <?php endif; ?>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'wgbEventType', ['active' => 'general', 'recall' => true, 'breakpoint' => 768]); ?>
    <?php foreach (['general', 'event', 'price', 'questions', 'page'] as $fs) : ?>
        <?php echo HTMLHelper::_('uitab.addTab', 'wgbEventType', $fs, $this->form->getFieldsets()[$fs]->label ? Text::_($this->form->getFieldsets()[$fs]->label) : $fs); ?>
        <div class="row"><div class="col-lg-9">
            <?php echo $this->form->renderFieldset($fs); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php endforeach; ?>
    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
