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
use WebG\Component\Webgbooking\Administrator\Helper\LinkHelper;

/** @var \WebG\Component\Webgbooking\Administrator\View\Eventtypes\HtmlView $this */
$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_webgbooking');
$wa->useScript('com_webgbooking.copylink');

$e         = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$listOrder = $this->escape($this->state->get('list.ordering', 'ordering'));
$listDirn  = $this->escape($this->state->get('list.direction', 'ASC'));
$loc       = ['meet' => 'COM_WEBGBOOKING_ET_LOC_MEET', 'phone' => 'COM_WEBGBOOKING_ET_LOC_PHONE', 'choice' => 'COM_WEBGBOOKING_ET_LOC_CHOICE'];
?>
<form action="<?php echo Route::_('index.php?option=com_webgbooking&view=eventtypes'); ?>" method="post" name="adminForm" id="adminForm">
    <p class="text-muted"><?php echo Text::_('COM_WEBGBOOKING_ET_INTRO'); ?></p>
    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

    <?php if (empty($this->items)) : ?>
        <div class="alert alert-info"><?php echo Text::_('COM_WEBGBOOKING_ET_EMPTY'); ?></div>
    <?php else : ?>
        <div class="table-responsive">
            <table class="table" id="eventtypeList">
                <caption class="visually-hidden"><?php echo Text::_('COM_WEBGBOOKING_ET_TITLE'); ?></caption>
                <thead>
                    <tr>
                        <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                        <th scope="col" class="w-1 text-center"><?php echo Text::_('COM_WEBGBOOKING_ET_F_STATE'); ?></th>
                        <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_WEBGBOOKING_ET_F_TITLE', 'title', $listDirn, $listOrder); ?></th>
                        <th scope="col"><?php echo Text::_('COM_WEBGBOOKING_ET_COL_LINK'); ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_WEBGBOOKING_ET_F_LISTED'); ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?php echo Text::_('COM_WEBGBOOKING_ET_COL_WHAT'); ?></th>
                        <th scope="col" class="w-1 d-none d-md-table-cell"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'id', $listDirn, $listOrder); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <?php
                        $short = LinkHelper::url((string) $item->slug);
                        $edit  = Route::_('index.php?option=com_webgbooking&task=eventtype.edit&id=' . (int) $item->id);
                        $what  = [Text::_($loc[$item->location] ?? 'COM_WEBGBOOKING_ET_LOC_MEET')];
                        if ((string) $item->duration !== '') {
                            array_unshift($what, (int) $item->duration . ' ' . Text::_('COM_WEBGBOOKING_ET_MIN'));
                        }
                        if ((string) $item->price_amount !== '') {
                            $what[] = $item->price_amount . ' ' . ($item->price_currency ?: 'EUR');
                        }
                        ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                            <td class="text-center">
                                <?php if ($this->canEditState) : ?>
                                    <?php echo HTMLHelper::_('jgrid.published', (int) $item->state, $i, 'eventtypes.', true); ?>
                                <?php else : ?>
                                    <span class="badge bg-<?php echo (int) $item->state === 1 ? 'success' : 'secondary'; ?>"><?php echo Text::_('COM_WEBGBOOKING_ET_STATE_' . (int) $item->state); ?></span>
                                <?php endif; ?>
                            </td>
                            <th scope="row">
                                <?php if ($this->canEdit) : ?>
                                    <a href="<?php echo $edit; ?>" class="fw-bold"><?php echo $e($item->title); ?></a>
                                <?php else : ?>
                                    <span class="fw-bold"><?php echo $e($item->title); ?></span>
                                <?php endif; ?>
                                <div class="small text-muted"><?php echo $e($item->slug); ?></div>
                            </th>
                            <td>
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <button type="button" class="btn btn-sm btn-primary" data-wgb-copy="<?php echo $e($short); ?>" data-wgb-copied="<?php echo $e(Text::_('COM_WEBGBOOKING_ET_COPIED')); ?>">
                                        <span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_ET_COPY'); ?>
                                    </button>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo $e($short); ?>" target="_blank" rel="noopener"><?php echo Text::_('COM_WEBGBOOKING_ET_OPEN'); ?></a>
                                </div>
                                <div class="small text-muted mt-1 text-break"><?php echo $e($short); ?></div>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <span class="badge bg-<?php echo (int) $item->listed === 1 ? 'info' : 'dark'; ?>"><?php echo Text::_('COM_WEBGBOOKING_ET_LISTED_' . (int) $item->listed); ?></span>
                            </td>
                            <td class="d-none d-lg-table-cell small"><?php echo $e(implode(' · ', $what)); ?></td>
                            <td class="d-none d-md-table-cell"><?php echo (int) $item->id; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
