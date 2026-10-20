<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

/**
 * A saved event type (a row of #__webgbooking_eventtypes). check() is the server-side gate: whatever the form let through,
 * nothing that would break the booking widget (unreadable price, bad hours, a slug that is already someone else's) is stored.
 */
class EventtypeTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__webgbooking_eventtypes', 'id', $db);
        $this->setColumnAlias('published', 'state');
    }

    public function check()
    {
        $this->title = trim(strip_tags((string) $this->title));
        if ($this->title === '') {
            throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_TITLE'));
        }

        // slug: what the customer sees in the address; lower case, digits, single hyphens
        $slug = trim((string) $this->slug) !== '' ? (string) $this->slug : (string) $this->title;
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(ApplicationHelper::stringURLSafe($slug))), '-'));
        $slug = substr($slug, 0, 80);
        if (\strlen($slug) < 2 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_SLUG'));
        }
        if (trim((string) $this->slug) === '' && (int) $this->listed !== 1 && !(int) $this->id) {
            // a secret event is protected only by its address: when the administrator did not choose one, it gets a part nobody can guess
            $slug = substr($slug, 0, 63) . '-' . bin2hex(random_bytes(8));
        }
        $this->slug = $slug;
        $db = $this->getDbo();
        $dup = (int) $db->setQuery(
            $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__webgbooking_eventtypes'))
                ->where($db->quoteName('slug') . ' = ' . $db->quote($slug))->where($db->quoteName('id') . ' != ' . (int) $this->id)
        )->loadResult();
        if ($dup > 0) {
            throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_SLUG_TAKEN'));
        }

        $this->description = trim(strip_tags((string) $this->description));
        $this->state       = (int) $this->state === 1 ? 1 : 0;
        $this->listed      = (int) $this->listed === 1 ? 1 : 0;
        foreach (['confirm_manual', 'allow_guest', 'show_newsletter'] as $f) {
            $this->$f = (int) $this->$f === 1 ? 1 : 0;
        }
        $this->ordering   = (int) $this->ordering;
        $this->location   = \in_array((string) $this->location, ['meet', 'phone', 'choice'], true) ? (string) $this->location : 'meet';

        // minutes: empty = the plugin's default; a number outside the range the widget accepts is refused
        foreach (['duration' => [5, 480], 'buffer_before' => [0, 240], 'buffer_after' => [0, 240], 'avail_notice' => [0, 720], 'avail_window' => [1, 365]] as $f => [$lo, $hi]) {
            $v = trim((string) $this->$f);
            if ($v === '') {
                $this->$f = '';
                continue;
            }
            if (!preg_match('/^\d{1,4}$/', $v) || (int) $v < $lo || (int) $v > $hi) {
                throw new \UnexpectedValueException(Text::sprintf('COM_WEBGBOOKING_ET_ERR_RANGE', Text::_('COM_WEBGBOOKING_ET_F_' . strtoupper($f)), $lo, $hi));
            }
            $this->$f = (string) (int) $v;
        }

        // hours of this event: both or none, HH:MM, start before end
        $ws = trim((string) $this->avail_start);
        $we = trim((string) $this->avail_end);
        if ($ws !== '' || $we !== '') {
            $ok = preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $ws) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $we);
            if ($ok) {
                $a = array_map('intval', explode(':', $ws));
                $b = array_map('intval', explode(':', $we));
                $ok = $a[0] * 60 + $a[1] < $b[0] * 60 + $b[1];
            }
            if (!$ok) {
                throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_HOURS'));
            }
        }
        $this->avail_start = $ws;
        $this->avail_end   = $we;
        $days = trim((string) $this->avail_days);
        if ($days !== '' && !preg_match('/^[0-6]([,\s;]+[0-6])*$/', $days)) {
            throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_DAYS'));
        }
        $this->avail_days = $days;

        // price: the same reading the widget uses (an amount that cannot be read, or a currency we do not charge in, would make the event refuse bookings)
        $amount = trim((string) $this->price_amount);
        $cur    = strtoupper(trim((string) $this->price_currency));
        if ($amount !== '') {
            $cur = $cur === '' ? 'EUR' : $cur;
            $ctrl = '\\WebG\\Plugin\\System\\Webgbooking\\Extension\\Webgbooking';
            if (class_exists($ctrl)) {
                if ($ctrl::parseAmountStrict($amount) === null || (int) $ctrl::parseAmountStrict($amount) <= 0 || !\in_array($cur, $ctrl::CURRENCIES, true)) {
                    throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_PRICE'));
                }
            } elseif (!preg_match('/^\d{1,6}([.,]\d{1,2})?$/', $amount) || !\in_array($cur, ['EUR', 'USD', 'GBP', 'CHF'], true)) {
                throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_PRICE'));
            }
            $this->price_currency = $cur;
        } else {
            $this->price_currency = '';
        }
        $this->price_amount = $amount;
        $this->price_label  = mb_substr(trim(strip_tags((string) $this->price_label)), 0, 120);

        $this->custom_fields = trim(strip_tags((string) $this->custom_fields));

        // who is told about a booking: addresses only
        $staff = trim((string) $this->staff_to);
        if ($staff !== '') {
            foreach (preg_split('/[\s,;]+/', $staff, -1, PREG_SPLIT_NO_EMPTY) as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_STAFF'));
                }
            }
        }
        $this->staff_to = $staff;

        // thank-you page: the widget refuses anything but an http(s) address or a path of the site
        $redir = trim((string) $this->redirect_url);
        if ($redir !== '' && !preg_match('#^(https?://[^\s\\\\]+|/(?![/\\\\])[^\s\\\\]*)$#i', $redir)) {
            throw new \UnexpectedValueException(Text::_('COM_WEBGBOOKING_ET_ERR_REDIRECT'));
        }
        $this->redirect_url = $redir;

        $now = Factory::getDate()->toSql();
        if (!(int) $this->id) {
            $this->created    = $now;
            $this->created_by = (int) Factory::getApplication()->getIdentity()->id;
        }
        $this->modified = $now;

        return true;
    }
}
