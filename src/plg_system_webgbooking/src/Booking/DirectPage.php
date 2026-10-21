<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * HTML of the direct booking pages (/prenota/<slug> and the index /prenota). Pure: it gets plain values and returns the
 * document; every value is escaped here, in one place. The look is the look of the emails (EmailLayout): one card on a
 * light page, a coloured line on top or a rule under the header, the logo or the business name, the footer text in the
 * footer colour, dark colours in dark mode; the brand comes from the plugin options. Static CSS lives in
 * assets/direct.css, only the brand colours are written here (as CSS variables).
 */

namespace WebG\Plugin\System\Webgbooking\Booking;

\defined('_JEXEC') or die;

use WebG\Plugin\System\Webgbooking\Extension\Webgbooking;
use WebG\Plugin\System\Webgbooking\Mail\EmailLayout;

final class DirectPage
{
    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Escaped text where every plain http(s) address becomes a real link (new tab); nothing else is let through. */
    private static function linkifyText(string $s): string
    {
        $parts = preg_split('#(https?://[^\s<>"\']+)#i', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
        $out   = '';
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1) {
                $url   = rtrim($p, '.,;)');
                $trail = substr($p, \strlen($url));
                $out  .= Webgbooking::httpUrlOk($url)
                    ? '<a href="' . self::e($url) . '" target="_blank" rel="noopener noreferrer">' . self::e($url) . '</a>' . self::e($trail)
                    : self::e($p);
            } else {
                $out .= self::e($p);
            }
        }

        return $out;
    }

    /** Plain text with blank lines between paragraphs and line breaks inside them. */
    private static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out .= '<p>' . nl2br(self::e($p), false) . '</p>';
            }
        }

        return $out;
    }

    private static function icon(string $name): string
    {
        $paths = [
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'video' => '<rect x="3" y="6" width="12" height="12" rx="2"/><path d="M15 10l6-3v10l-6-3"/>',
            'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
            'tag'   => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.2"/>',
        ];

        return '<svg class="bk-ic" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
    }

    /** CSS variables of the brand, readable on the light and on the dark surface. */
    private static function vars(array $b): string
    {
        $accent  = EmailLayout::hex((string) ($b['color'] ?? ''), '#2b59c3');
        $btn     = EmailLayout::hex((string) ($b['button'] ?? ''), $accent);
        $btnInk  = EmailLayout::contrast('#ffffff', $btn) >= 4.5 ? '#ffffff' : '#111827';
        $line    = EmailLayout::hex((string) ($b['line'] ?? ''), $accent);
        $linkSet = EmailLayout::hex((string) ($b['link'] ?? ''), '');
        $link    = EmailLayout::readable($linkSet !== '' ? $linkSet : $accent, '#ffffff');
        $linkD   = $linkSet !== '' ? EmailLayout::readable($line, '#1e2030') : EmailLayout::readable(EmailLayout::mix($accent, '#ffffff', 0.35), '#1e2030');
        $text    = EmailLayout::hex((string) ($b['text'] ?? ''), '#1f2433');
        if (EmailLayout::contrast($text, '#ffffff') < 4.5) {
            $text = '#1f2433';
        }
        $head    = ($b['text'] ?? '') !== '' && $text !== '#1f2433' ? $text : '#14192b';
        $footBg  = EmailLayout::hex((string) ($b['footer_color'] ?? ''), '#2d3142');
        $footDk  = EmailLayout::contrast('#ffffff', $footBg) >= 4.5;
        $f1      = $footDk ? '#ffffff' : '#111827';
        $f2      = $footDk ? '#d5d7e3' : '#2a2f40';
        $f3      = $footDk ? '#b6b9cc' : '#4a5066';
        if (!empty($b['footer_out'])) {
            $f1 = '#1b2a4a';
            $f2 = '#3d4a63';
            $f3 = '#5b6074';
        }
        $box   = EmailLayout::mix($accent, '#ffffff', 0.92);
        $boxLn = EmailLayout::mix($accent, '#ffffff', 0.55);
        $boxD  = EmailLayout::mix($accent, '#1e2030', 0.82);

        return '--bk-accent:' . $accent . ';--bk-btn:' . $btn . ';--bk-btn-ink:' . $btnInk . ';--bk-line:' . $line . ';--bk-link:' . $link . ';--bk-link-d:' . $linkD
            . ';--bk-text:' . $text . ';--bk-head:' . $head . ';--bk-foot-bg:' . $footBg . ';--bk-f1:' . $f1 . ';--bk-f2:' . $f2 . ';--bk-f3:' . $f3
            . ';--bk-box:' . $box . ';--bk-box-line:' . $boxLn . ';--bk-box-d:' . $boxD;
    }

    /** The common frame: head (assets, brand), header, card, footer. */
    private static function shell(array $b, string $lang, string $docTitle, string $inner, array $a): string
    {
        $name     = trim((string) ($b['name'] ?? '')) ?: 'WebG Booking';
        $center   = ($b['logo_pos'] ?? '') === 'center';
        $rule     = ($b['header_style'] ?? '') === 'rule';
        $footOut  = !empty($b['footer_out']);
        $logo     = trim((string) ($b['logo'] ?? ''));
        $logoDark = trim((string) ($b['logo_dark'] ?? ''));
        $siteUrl  = (string) ($b['site_url'] ?? '');
        $asset    = (string) $a['asset'];
        $ver      = (string) $a['ver'];

        if ($logo !== '') {
            $img  = static fn(string $src, string $cls): string => '<img src="' . self::e($src) . '" alt="' . self::e($name) . '" class="' . $cls . '" height="44">';
            $mark = $img($logo, $logoDark !== '' ? 'bk-logo bk-logo-l' : 'bk-logo bk-logo-plate') . ($logoDark !== '' ? $img($logoDark, 'bk-logo bk-logo-d') : '');
        } else {
            $mark = '<span class="bk-name">' . self::e($name) . '</span>';
        }
        $mark = $siteUrl !== '' ? '<a href="' . self::e($siteUrl) . '" class="bk-home">' . $mark . '</a>' : $mark;

        $foot = '<p class="bk-f1">' . self::e($name) . '</p>';
        $ft   = trim((string) ($b['footer'] ?? ''));
        if ($ft !== '' && FooterText::isRich($ft)) {
            $rich = FooterText::inline($ft, 'target="_blank"');
            $foot .= $rich !== '' ? '<p class="bk-f2">' . $rich . '</p>' : '';
        } elseif ($ft !== '') {
            $foot .= '<p class="bk-f2">' . nl2br(self::linkifyText($ft), false) . '</p>';
        }

        return '<!DOCTYPE html><html lang="' . self::e(preg_replace('/[^a-zA-Z-]/', '', $lang) ?: 'en') . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">'
            . '<meta name="color-scheme" content="light dark">'
            . '<title>' . self::e($docTitle) . '</title>'
            . '<link rel="stylesheet" href="' . self::e($asset . '/uikit/css/uikit.min.css?' . $ver) . '">'
            . '<link rel="stylesheet" href="' . self::e($asset . '/wgb.css?' . $ver) . '">'
            . '<link rel="stylesheet" href="' . self::e($asset . '/direct.css?' . $ver) . '">'
            . '<style>:root{' . self::vars($b) . '}</style>'
            . '<script src="' . self::e($asset . '/uikit/js/uikit.min.js?' . $ver) . '"></script>'
            . '<script src="' . self::e($asset . '/uikit/js/uikit-icons.min.js?' . $ver) . '"></script>'
            . '<script src="' . self::e($asset . '/wgb.js?' . $ver) . '" defer></script>'
            . '</head><body class="bk-body">'
            . '<div class="bk-page"><div class="bk-card' . ($rule ? ' bk-rule' : ' bk-band') . '">'
            . '<header class="bk-head' . ($center ? ' bk-center' : '') . '">' . $mark . '</header>'
            . '<div class="bk-content">' . $inner . '</div>'
            . ($footOut ? '' : '<footer class="bk-foot">' . $foot . '</footer>')
            . '</div>'
            . ($footOut ? '<footer class="bk-foot bk-foot-out">' . $foot . '</footer>' : '')
            . '</div></body></html>';
    }

    /**
     * One event: name of the business, title, duration, place, price, description, and the whole widget.
     *
     * @param array $v 'brand', 'lang', 'asset', 'ver', 'title', 'description', 'duration' (min), 'location' (meet|phone|choice),
     *                 'price' (text or ''), 'w' (WidgetConfig::build result), 'labels' => [minutes, meet, phone, choice, intro]
     */
    public static function event(array $v): string
    {
        $L    = (array) $v['labels'];
        $meta = [];
        $meta[] = self::icon('clock') . '<span>' . (int) $v['duration'] . ' ' . self::e($L['minutes']) . '</span>';
        $loc = (string) $v['location'];
        if ($loc === 'phone') {
            $meta[] = self::icon('phone') . '<span>' . self::e($L['phone']) . '</span>';
        } elseif ($loc === 'choice') {
            $meta[] = self::icon('video') . '<span>' . self::e($L['choice']) . '</span>';
        } else {
            $meta[] = self::icon('video') . '<span>' . self::e($L['meet']) . '</span>';
        }
        if ((string) $v['price'] !== '') {
            $meta[] = self::icon('tag') . '<span>' . self::e($v['price']) . '</span>';
        }
        $w     = (array) $v['w'];
        $brand = (array) $v['brand'];
        $name  = trim((string) ($brand['name'] ?? ''));

        // the business name above the title only when the header carries a logo (without one the header already says it)
        $info = ($name !== '' && trim((string) ($brand['logo'] ?? '')) !== '' ? '<p class="bk-eyebrow">' . self::e($name) . '</p>' : '')
            . '<h1 class="bk-title">' . self::e($v['title']) . '</h1>'
            . '<ul class="bk-meta">' . implode('', array_map(static fn($m) => '<li>' . $m . '</li>', $meta)) . '</ul>'
            . (trim((string) $v['description']) !== '' ? '<div class="bk-desc">' . self::paragraphs((string) $v['description']) . '</div>' : '');

        // the widget draws its own steps; the accent of the selected day and the main button follow the brand
        $widget = '<div class="wgb-booking wgb-compact wgb-style-calendly wgb-has-accent" data-wgb="' . $w['cfgAttr'] . '" style="--wgb-accent:var(--bk-btn);--wgb-accent-fg:var(--bk-btn-ink)">'
            . '<div class="wgb-mount"><div class="uk-text-meta">' . self::e($w['cfg']['labels']['stepDay']) . '&hellip;</div></div></div>';

        $inner = '<div class="bk-grid"><section class="bk-info">' . $info . '</section><section class="bk-widget" aria-label="' . self::e($L['intro']) . '">' . $widget . '</section></div>';

        return self::shell($brand, (string) $v['lang'], (string) $v['title'] . ($name !== '' ? ' - ' . $name : ''), $inner, ['asset' => $v['asset'], 'ver' => $v['ver']]);
    }

    /**
     * The index: public events as cards. $v 'items' => [[title, description, duration, price, url], ...]
     */
    public static function index(array $v): string
    {
        $L     = (array) $v['labels'];
        $brand = (array) $v['brand'];
        $name  = trim((string) ($brand['name'] ?? ''));
        $cards = '';
        foreach ((array) $v['items'] as $it) {
            $desc = trim((string) $it['description']);
            if (mb_strlen($desc) > 180) {
                $desc = rtrim(mb_substr($desc, 0, 177)) . '...';
            }
            $cards .= '<a class="bk-ev" href="' . self::e($it['url']) . '"><span class="bk-ev-t">' . self::e($it['title']) . '</span>'
                . '<span class="bk-ev-m">' . self::icon('clock') . ' ' . (int) $it['duration'] . ' ' . self::e($L['minutes']) . ((string) $it['price'] !== '' ? ' &middot; ' . self::e($it['price']) : '') . '</span>'
                . ($desc !== '' ? '<span class="bk-ev-d">' . nl2br(self::e($desc), false) . '</span>' : '')
                . '<span class="bk-ev-go">' . self::e($L['book']) . '</span></a>';
        }
        $inner = ($name !== '' && trim((string) ($brand['logo'] ?? '')) !== '' ? '<p class="bk-eyebrow">' . self::e($name) . '</p>' : '')
            . '<h1 class="bk-title">' . self::e($L['index_title']) . '</h1>'
            . ($cards !== '' ? '<p class="bk-lead">' . self::e($L['index_intro']) . '</p><div class="bk-evs">' . $cards . '</div>' : '<p class="bk-lead">' . self::e($L['index_empty']) . '</p>');

        return self::shell($brand, (string) $v['lang'], (string) $L['index_title'] . ($name !== '' ? ' - ' . $name : ''), $inner, ['asset' => $v['asset'], 'ver' => $v['ver']]);
    }

    /** "This link does not exist": same frame, no widget. */
    public static function notFound(array $v): string
    {
        $L     = (array) $v['labels'];
        $inner = '<div class="bk-narrow"><h1 class="bk-title">' . self::e($L['nf_title']) . '</h1><p class="bk-lead">' . self::e($L['nf_text']) . '</p>'
            . (!empty($v['index_url']) ? '<p><a class="bk-link" href="' . self::e($v['index_url']) . '">' . self::e($L['index_title']) . '</a></p>' : '') . '</div>';

        return self::shell((array) $v['brand'], (string) $v['lang'], (string) $L['nf_title'], $inner, ['asset' => $v['asset'], 'ver' => $v['ver']]);
    }
}
