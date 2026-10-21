<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The HTML of every email WebG Booking sends. To change it, copy this file to
 *   templates/<your template>/html/layouts/webgbooking/email/default.php
 * and edit the copy: Joomla uses the copy and the product's own file is never touched, so an update does not undo your work.
 *
 * $displayData (array):
 *   lang          language tag, e.g. "it-IT"
 *   brand         name, logo, logo_dark, color, button, line, link, text, logo_pos, header_style, footer, footer_out, footer_color, site_url
 *   title         the title of the email (plain text)
 *   preheader     the short text mail apps show next to the subject
 *   template      the body: simple HTML (paragraphs, bold, links) with the {block} tokens still in it (appointment_box, answers_block,
 *                 meet_button, calendar_link, change_links, payment_block, notices, backend_button, rebook_button)
 *   blocks        token => data of each block (type summary | sections | primary | links | notices)
 *   signoff       the closing line
 *   footer_note   the small line at the bottom
 * Use \WebG\Plugin\System\Webgbooking\Mail\EmailLayout::render($displayData)['html'] to get the standard result and change it, or write your own.
 * The plain-text twin of the email is email/text.php (same variables).
 */

\defined('_JEXEC') or die;

use WebG\Plugin\System\Webgbooking\Mail\EmailLayout;

echo EmailLayout::render($displayData)['html'];
