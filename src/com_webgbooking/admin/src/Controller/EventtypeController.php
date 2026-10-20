<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\FormController;

/** Add / edit / save / cancel of one saved event type. Rights: core.create to add, core.edit to change (the FormController checks them). */
class EventtypeController extends FormController
{
    protected $view_list = 'eventtypes';
    protected $text_prefix = 'COM_WEBGBOOKING_ET';

    protected function allowAdd($data = [])
    {
        return $this->app->getIdentity()->authorise('core.create', 'com_webgbooking');
    }

    protected function allowEdit($data = [], $key = 'id')
    {
        return $this->app->getIdentity()->authorise('core.edit', 'com_webgbooking');
    }
}
