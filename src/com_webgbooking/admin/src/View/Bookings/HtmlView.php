<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\View\Bookings;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $items;
    protected $pagination;
    public $filterForm;
    public $activeFilters;
    protected $state;
    public $stats;
    public $canEditState = false;
    public $canDelete = false;
    /** @var array<int, array{0:string,1:string}> banners shown above the list: [bootstrap class, text] */
    public $alerts = [];

    public function display($tpl = null)
    {
        $this->items         = $this->get('Items');
        $this->pagination    = $this->get('Pagination');
        $this->state         = $this->get('State');
        $this->filterForm    = $this->get('FilterForm');
        $this->activeFilters = $this->get('ActiveFilters');
        $this->stats         = $this->getModel()->getStats();

        $user               = Factory::getApplication()->getIdentity();
        $this->canEditState = $user->authorise('core.edit.state', 'com_webgbooking');
        $this->canDelete    = $user->authorise('core.delete', 'com_webgbooking');

        $this->alerts = $this->collectAlerts();

        $this->addToolbar();

        parent::display($tpl);
    }

    /** Things the admin must not miss: test-mode Stripe, undecryptable keys, bookings whose effects did not complete. */
    private function collectAlerts(): array
    {
        $out = [];

        try {
            if (\Joomla\CMS\Plugin\PluginHelper::isEnabled('system', 'webgbooking')) {
                $engine = Factory::getApplication()->bootPlugin('webgbooking', 'system');
                if (method_exists($engine, 'stripeStatus')) {
                    $st = $engine->stripeStatus();
                    if (!empty($st['configured']) && empty($st['live'])) {
                        $out[] = ['warning', Text::_('COM_WEBGBOOKING_BANNER_TEST_MODE')];
                    }
                    if (empty($st['secret_ok'])) {
                        $out[] = ['danger', Text::_('COM_WEBGBOOKING_BANNER_SECRET_BROKEN')];
                    }
                    if (empty($st['whsec_ok'])) {
                        $out[] = ['danger', Text::_('COM_WEBGBOOKING_BANNER_WHSEC_BROKEN')];
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $n  = (int) $db->setQuery(
                $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_bookings'))
                    ->where($db->quoteName('fx_state') . ' IN (' . $db->quote('retry') . ',' . $db->quote('failed') . ')')
                    ->where($db->quoteName('status') . ' IN (' . $db->quote('pending') . ',' . $db->quote('confirmed') . ')')
            )->loadResult();
            if ($n > 0) {
                $out[] = ['danger', Text::sprintf('COM_WEBGBOOKING_BANNER_FX', $n)];
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_WEBGBOOKING'), 'calendar');

        if ($this->canEditState) {
            ToolbarHelper::custom('booking.confirm', 'check', '', 'COM_WEBGBOOKING_TB_CONFIRM', true);
            ToolbarHelper::custom('booking.complete', 'checkin', '', 'COM_WEBGBOOKING_TB_COMPLETE', true);
            ToolbarHelper::custom('booking.noshow', 'minus', '', 'COM_WEBGBOOKING_TB_NOSHOW', true);
            ToolbarHelper::custom('booking.cancel', 'cancel', '', 'COM_WEBGBOOKING_TB_CANCEL', true);
            ToolbarHelper::divider();
        }

        if ($this->canDelete) {
            ToolbarHelper::deleteList(Text::_('COM_WEBGBOOKING_CONFIRM_DELETE'), 'booking.delete', 'JTOOLBAR_DELETE');
            ToolbarHelper::divider();
        }
    }
}
