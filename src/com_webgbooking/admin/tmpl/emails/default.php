<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/** @var \WebG\Component\Webgbooking\Administrator\View\Emails\HtmlView $this */
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// the plugin options (the look of every email: logo, colours, footer) live in the plugin: a link there
$extId = 0;
try {
    $db    = Factory::getContainer()->get(DatabaseInterface::class);
    $extId = (int) $db->setQuery($db->getQuery(true)->select($db->quoteName('extension_id'))->from($db->quoteName('#__extensions'))
        ->where($db->quoteName('element') . ' = ' . $db->quote('webgbooking'))->where($db->quoteName('folder') . ' = ' . $db->quote('system')))->loadResult();
} catch (\Throwable $ex) {
}
$groups = ['customer' => 'COM_WEBGBOOKING_EMG_CUSTOMER', 'guest' => 'COM_WEBGBOOKING_EMG_GUEST', 'staff' => 'COM_WEBGBOOKING_EMG_STAFF'];
?>
<div class="wgb-em">
    <?php if (!$this->engine) : ?>
        <div class="alert alert-warning" role="alert"><?php echo Text::_('COM_WEBGBOOKING_EM_NOENGINE'); ?></div>
    <?php else : ?>
        <p class="lead mb-3"><?php echo Text::_('COM_WEBGBOOKING_EM_INTRO'); ?></p>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span class="fw-bold"><?php echo Text::_('COM_WEBGBOOKING_EM_LANGUAGE'); ?></span>
            <?php foreach ($this->languages as $l) : ?>
                <a class="btn btn-sm <?php echo $l === $this->lang ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo Route::_('index.php?option=com_webgbooking&view=emails&lang=' . rawurlencode($l)); ?>"<?php echo $l === $this->lang ? ' aria-current="true"' : ''; ?>><?php echo $e($l); ?></a>
            <?php endforeach; ?>
            <?php if ($extId > 0) : ?>
                <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?php echo Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extId); ?>"><span class="icon-palette" aria-hidden="true"></span> <?php echo Text::_('COM_WEBGBOOKING_EM_LOOK'); ?></a>
            <?php endif; ?>
        </div>
        <?php foreach ($groups as $g => $gl) : ?>
            <?php $rows = array_filter($this->items, static fn($i) => $i['group'] === $g); ?>
            <?php if (!$rows) { continue; } ?>
            <h2 class="h4 mt-4"><?php echo Text::_($gl); ?></h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <caption class="visually-hidden"><?php echo Text::_($gl); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo Text::_('COM_WEBGBOOKING_EM_COL_EMAIL'); ?></th>
                            <th scope="col" class="d-none d-md-table-cell"><?php echo Text::_('COM_WEBGBOOKING_EM_COL_WHEN'); ?></th>
                            <th scope="col"><?php echo Text::_('COM_WEBGBOOKING_EM_COL_STATE'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r) : ?>
                            <tr>
                                <th scope="row">
                                    <a class="fw-bold" href="<?php echo Route::_('index.php?option=com_webgbooking&view=email&key=' . rawurlencode($r['key']) . '&lang=' . rawurlencode($this->lang)); ?>"><?php echo $e($r['name']); ?></a>
                                </th>
                                <td class="d-none d-md-table-cell small text-muted"><?php echo $e($r['help']); ?></td>
                                <td>
                                    <?php if ($r['changed']) : ?>
                                        <span class="badge bg-info"><?php echo Text::_('COM_WEBGBOOKING_EM_STATE_CHANGED'); ?></span>
                                    <?php else : ?>
                                        <span class="badge bg-secondary"><?php echo Text::_('COM_WEBGBOOKING_EM_STATE_DEFAULT'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($r['legacy']) : ?>
                                        <span class="badge bg-warning text-dark"><?php echo Text::_('COM_WEBGBOOKING_EM_STATE_LEGACY'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
