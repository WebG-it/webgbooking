<?php

/**
 * @package     com_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 */

namespace WebG\Component\Webgbooking\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use WebG\Component\Webgbooking\Administrator\Helper\EmailEngine;

/** Save / restore / convert / preview / "send me a test" of the email texts. Changing a text needs the right to edit (core.edit); looking at one needs core.manage. */
class EmailController extends BaseController
{
    private function guard(bool $edit = true): void
    {
        $user = Factory::getApplication()->getIdentity();
        if (!$user->authorise($edit ? 'core.edit' : 'core.manage', 'com_webgbooking')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function token(): void
    {
        if (!Session::checkToken('post') && !Session::checkToken('get')) {
            throw new NotAllowed(Text::_('JINVALID_TOKEN'), 403);
        }
    }

    /** @return array{0:string,1:string} the email and the language the form is about */
    private function target(): array
    {
        $in  = $this->input;
        $key = preg_replace('/[^a-z_]/', '', (string) $in->post->getString('key', $in->getString('key', '')));
        $tag = preg_replace('/[^A-Za-z-]/', '', (string) $in->post->getString('lang', $in->getString('lang', '')));

        return [$key, $tag];
    }

    /** The fields of the form (control jform) as plain strings; the rich text keeps its markup (the plugin reduces it to simple tags). */
    private function fields(): array
    {
        $raw = (array) $this->input->post->get('jform', [], 'raw');
        $out = [];
        foreach ($raw as $k => $v) {
            if (\is_string($v) && preg_match('/^[a-z_]+$/', (string) $k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function back(string $key, string $tag): string
    {
        return Route::_('index.php?option=com_webgbooking&view=email&key=' . rawurlencode($key) . '&lang=' . rawurlencode($tag), false);
    }

    public function save()
    {
        $this->token();
        $this->guard();
        [$key, $tag] = $this->target();
        $engine = EmailEngine::get();
        if (!$engine || $key === '') {
            $this->setRedirect(Route::_('index.php?option=com_webgbooking&view=emails', false), Text::_('COM_WEBGBOOKING_EM_NOENGINE'), 'error');

            return;
        }
        $kept = $engine->saveEmailTexts($key, $tag, $this->fields(), (int) Factory::getApplication()->getIdentity()->id);
        $this->setRedirect($this->back($key, $tag), Text::_($kept ? 'COM_WEBGBOOKING_EM_SAVED' : 'COM_WEBGBOOKING_EM_SAVED_DEFAULT'), 'message');
    }

    public function restore()
    {
        $this->token();
        $this->guard();
        [$key, $tag] = $this->target();
        $engine = EmailEngine::get();
        if ($engine && $key !== '') {
            $engine->restoreEmailTexts($key, $tag);
        }
        $this->setRedirect($this->back($key, $tag), Text::_('COM_WEBGBOOKING_EM_RESTORED'), 'message');
    }

    /** The text written in the old plugin option becomes the body of the new editor. */
    public function convert()
    {
        $this->token();
        $this->guard();
        [$key, $tag] = $this->target();
        $engine = EmailEngine::get();
        $ok     = $engine && $key !== '' && $engine->convertLegacyText($key, $tag, (int) Factory::getApplication()->getIdentity()->id);
        $this->setRedirect($this->back($key, $tag), Text::_($ok ? 'COM_WEBGBOOKING_EM_CONVERTED' : 'COM_WEBGBOOKING_EM_NOTHING_TO_CONVERT'), $ok ? 'message' : 'warning');
    }

    public function cancel()
    {
        $this->setRedirect(Route::_('index.php?option=com_webgbooking&view=emails', false));
    }

    /** JSON: the email as it will look, with sample data, for what is in the form (not saved). */
    public function preview()
    {
        $this->token();
        $this->guard(false);
        [$key, $tag] = $this->target();
        $engine = EmailEngine::get();
        header('Content-Type: application/json; charset=utf-8');
        if (!$engine || $key === '') {
            echo json_encode(['ok' => false, 'message' => Text::_('COM_WEBGBOOKING_EM_NOENGINE')]);
            Factory::getApplication()->close();
        }
        $p = $engine->previewEmail($key, $tag, $this->fields());
        echo json_encode(['ok' => $p['html'] !== '', 'subject' => $p['subject'], 'html' => $p['html']], JSON_UNESCAPED_UNICODE);
        Factory::getApplication()->close();
    }

    /** JSON: send the preview by email to the administrator who asks. */
    public function sendtest()
    {
        $this->token();
        $this->guard(false);
        [$key, $tag] = $this->target();
        $engine = EmailEngine::get();
        $to     = (string) Factory::getApplication()->getIdentity()->email;
        header('Content-Type: application/json; charset=utf-8');
        $ok = $engine && $key !== '' && $engine->sendTestEmail($key, $tag, $this->fields(), $to);
        echo json_encode(['ok' => $ok, 'message' => $ok ? Text::sprintf('COM_WEBGBOOKING_EM_TEST_SENT', $to) : Text::_('COM_WEBGBOOKING_EM_TEST_FAILED')], JSON_UNESCAPED_UNICODE);
        Factory::getApplication()->close();
    }
}
