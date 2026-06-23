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
<div class="p-3" style="max-width:760px">
    <?php if (!$s->hasClientId) : ?>
        <div class="alert alert-warning"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_NO_CREDS'); ?></div>
    <?php elseif ($s->connected) : ?>
        <div class="alert alert-success">
            <?php echo Text::sprintf('COM_WEBGBOOKING_GOOGLE_CONNECTED_AS', htmlspecialchars((string) $s->email, ENT_QUOTES, 'UTF-8')); ?>
        </div>
        <form action="<?php echo Route::_('index.php?option=com_webgbooking&task=google.disconnect'); ?>" method="post">
            <button type="submit" class="btn btn-danger"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_DISCONNECT'); ?></button>
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php else : ?>
        <p><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_INTRO'); ?></p>
        <a class="btn btn-primary btn-lg" href="<?php echo htmlspecialchars((string) $s->connectUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo Text::_('COM_WEBGBOOKING_GOOGLE_CONNECT'); ?>
        </a>
    <?php endif; ?>

    <hr>
    <p class="text-muted mb-1"><?php echo Text::_('COM_WEBGBOOKING_GOOGLE_REDIRECT_HINT'); ?></p>
    <code style="word-break:break-all"><?php echo htmlspecialchars((string) $s->redirect, ENT_QUOTES, 'UTF-8'); ?></code>
</div>
