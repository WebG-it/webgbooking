<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;

/** List actions on saved event types: publish, unpublish, delete (rights: core.edit.state, core.delete, checked by the model). */
class EventtypesController extends AdminController
{
    protected $text_prefix = 'COM_WEBGBOOKING_ET';

    public function getModel($name = 'Eventtype', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
