<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Google;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $status;

    public function display($tpl = null)
    {
        $this->status = $this->getModel()->getStatus();

        $app = Factory::getApplication();
        if ($app->getInput()->getInt('wgbconnected')) {
            $app->enqueueMessage(Text::_('COM_WEBGBOOKING_GOOGLE_OK'), 'success');
        }
        if ($err = $app->getInput()->getCmd('wgberror', '')) {
            $app->enqueueMessage(Text::sprintf('COM_WEBGBOOKING_GOOGLE_ERR', $err), 'error');
        }

        ToolbarHelper::title(Text::_('COM_WEBGBOOKING_SUBMENU_GOOGLE'), 'users');

        parent::display($tpl);
    }
}
