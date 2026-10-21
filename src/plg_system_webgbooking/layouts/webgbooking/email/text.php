<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The plain-text version of every email WebG Booking sends (what mail apps without HTML show). Override it like default.php:
 *   templates/<your template>/html/layouts/webgbooking/email/text.php
 * It receives the same $displayData as default.php.
 */

\defined('_JEXEC') or die;

use WebG\Plugin\System\Webgbooking\Mail\EmailLayout;

echo EmailLayout::render($displayData)['text'];
