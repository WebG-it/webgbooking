<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * One layout for every email of the plugin (customer and staff). Pure: it gets plain values and returns the HTML and
 * the plain-text alternative. Every value that is not `body_html` is escaped here, in one place; `body_html` is the only
 * trusted fragment (the element's custom text, already filled by the plugin with escaped values).
 *
 * Structure and spacing follow the WebG newsletter template (one 600 px card, accent line, header, title, summary box,
 * one main button, footer; tables with inline CSS; preheader; light and dark colours through classes; mobile at 620 px;
 * Outlook ghost table). Colours come from the plugin options with a neutral default, so the same layout serves any site.
 */

namespace WebG\Plugin\System\Webgbooking\Mail;

\defined('_JEXEC') or die;

final class EmailLayout
{
    private const FONT = "Roboto,'Segoe UI',Helvetica,Arial,sans-serif";

    // ---------------------------------------------------------------------------------------------------- colours

    public static function hex(string $c, string $default): string
    {
        $c = trim($c);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $default;
    }

    private static function rgb(string $h): array
    {
        return [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];
    }

    private static function toHex(array $c): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, (int) round($c[0]))), max(0, min(255, (int) round($c[1]))), max(0, min(255, (int) round($c[2]))));
    }

    /** $a mixed with $b: $t of $b. */
    public static function mix(string $a, string $b, float $t): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);

        return self::toHex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
    }

    private static function lum(string $h): float
    {
        $f = static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($h);

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    public static function contrast(string $a, string $b): float
    {
        $l1 = self::lum($a);
        $l2 = self::lum($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** $c moved toward black (on a light surface) or white (on a dark one) until it reads at 4.5:1 on $surface. */
    public static function readable(string $c, string $surface): string
    {
        $toward = self::lum($surface) > 0.5 ? '#000000' : '#ffffff';
        for ($i = 0; $i < 20 && self::contrast($c, $surface) < 4.5; $i++) {
            $c = self::mix($c, $toward, 0.12);
        }

        return $c;
    }

    // ---------------------------------------------------------------------------------------------------- text

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** Plain text of a trusted HTML fragment: paragraphs and breaks become lines, links keep their address. */
    public static function htmlToText(string $html): string
    {
        $t = (string) preg_replace('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', '$3 ($2)', $html);
        $t = (string) preg_replace('#<br\s*/?>#i', "\n", $t);
        $t = (string) preg_replace('#</(p|div|tr|h[1-6]|li|table)>#i', "\n", $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = (string) preg_replace("/[ \t]+/", ' ', $t);
        $t = (string) preg_replace("/ ?\n ?/", "\n", $t);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $t));
    }

    /** Escaped text with its web addresses made into links. */
    private static function linkify(string $s, string $color, string $class = 't-link', bool $breakDetect = false): string
    {
        $txt = $breakDetect ? static fn(string $t): string => self::e(self::breakDetect($t)) : static fn(string $t): string => self::e($t);
        $out = '';
        $pos = 0;
        if (preg_match_all('#https?://[^\s<>"]+#i', $s, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$url, $off]) {
                $out .= $txt(substr($s, $pos, $off - $pos));
                $trail = '';
                while ($url !== '' && strpos('.,;:)', substr($url, -1)) !== false) {
                    $trail = substr($url, -1) . $trail;
                    $url   = substr($url, 0, -1);
                }
                $out .= '<a href="' . self::e($url) . '" class="' . $class . '" style="color:' . $color . ';text-decoration:underline;">' . self::e($url) . '</a>' . $txt($trail);
                $pos  = $off + strlen($url) + strlen($trail);
            }
        }

        return $out . $txt(substr($s, $pos));
    }

    /**
     * Mail apps (Gmail, Apple Mail) turn anything that looks like an address, phone number or date into a blue link. In the footer that is
     * unreadable on a dark background, so the text is cut with zero-width spaces after each word and inside long digit runs: it still
     * reads the same and is no longer recognised. Only for the HTML; the plain-text part keeps the text as written.
     */
    private static function breakDetect(string $t): string
    {
        $zw = "\u{200B}";
        $t  = (string) preg_replace('/(\d{3})(?=\d)/', '$1' . $zw, $t);

        return str_replace(' ', ' ' . $zw, $t);
    }

    private static function space(int $h): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="' . $h . '" style="height:' . $h . 'px;line-height:' . $h . 'px;font-size:0;mso-line-height-rule:exactly;">&nbsp;</td></tr></table>';
    }

    // ---------------------------------------------------------------------------------------------------- the layout

    /**
     * @param array $m {
     *   lang: string, brand: {name, logo, logo_dark, color, button, footer, site_url}, preheader: string, title: string,
     *   lead: string[] (paragraphs), notices: [{text, tone: ok|info|warn}], summary: {rows: [[label, value|[text, url]]]}|null,
     *   body_html: string (trusted), buttons: [{label, url, primary: bool}], others_label: string (a line above the quiet links), sections: [{title, stacked?: bool, rows: [[label, value]]}],
     *   signoff: string, footer_note: string }
     * @return array{html: string, text: string}
     */
    public static function render(array $m): array
    {
        $b        = (array) ($m['brand'] ?? []);
        $name     = trim((string) ($b['name'] ?? '')) ?: 'WebG Booking';
        $accent   = self::hex((string) ($b['color'] ?? ''), '#2b59c3');
        $btn      = self::hex((string) ($b['button'] ?? ''), $accent);
        $btnText  = self::contrast('#ffffff', $btn) >= 4.5 ? '#ffffff' : '#111827';
        $line     = self::hex((string) ($b['line'] ?? ''), $accent);
        $linkSet  = self::hex((string) ($b['link'] ?? ''), '');
        $link     = self::readable($linkSet !== '' ? $linkSet : $accent, '#ffffff');
        $linkDark = $linkSet !== '' ? self::readable($line, '#1e2030') : self::readable(self::mix($accent, '#ffffff', 0.35), '#1e2030');
        $textC    = self::hex((string) ($b['text'] ?? ''), '#1f2433');
        if (self::contrast($textC, '#ffffff') < 4.5) {
            $textC = '#1f2433';
        }
        $headC    = ($b['text'] ?? '') !== '' && $textC !== '#1f2433' ? $textC : '#14192b';
        $center   = ($b['logo_pos'] ?? '') === 'center';
        $rule     = ($b['header_style'] ?? '') === 'rule';
        $footOut  = !empty($b['footer_out']);
        $footBg   = self::hex((string) ($b['footer_color'] ?? ''), '#2d3142');
        $footDark = self::contrast('#ffffff', $footBg) >= 4.5;
        $fc1      = $footDark ? '#ffffff' : '#111827';
        $fc2      = $footDark ? '#d5d7e3' : '#2a2f40';
        $fc3      = $footDark ? '#b6b9cc' : '#4a5066';
        if ($footOut) {
            // footer on the page background, outside the card (like the Brevo personal emails): dark text on the light page, light text on the dark page
            $fc1 = '#1b2a4a';
            $fc2 = '#3d4a63';
            $fc3 = '#5b6074';
        }
        $boxBg    = self::mix($accent, '#ffffff', 0.92);
        $boxLine  = self::mix($accent, '#ffffff', 0.55);
        $boxBgD   = self::mix($accent, '#1e2030', 0.82);
        $lang     = preg_replace('/[^a-zA-Z-]/', '', (string) ($m['lang'] ?? 'en')) ?: 'en';
        $title    = (string) ($m['title'] ?? '');
        $pre      = trim((string) ($m['preheader'] ?? ''));
        $siteUrl  = (string) ($b['site_url'] ?? '');
        $F        = self::FONT;
        $text     = [];

        // ---- pieces
        $para = static function (string $s) use ($F, $textC): string {
            return '<p class="t-body" style="margin:0 0 14px 0;font-family:' . $F . ';font-size:16px;line-height:25px;color:' . $textC . ';">' . self::e($s) . '</p>';
        };

        $content = '';
        $content .= '<h1 class="t-head h1" style="margin:0 0 16px 0;font-family:' . $F . ';font-size:25px;line-height:32px;font-weight:700;color:' . $headC . ';">' . self::e($title) . '</h1>';
        $text[] = $title;
        $text[] = str_repeat('=', min(60, max(8, mb_strlen($title))));
        foreach ((array) ($m['lead'] ?? []) as $p) {
            if (trim((string) $p) !== '') {
                $content .= $para((string) $p);
                $text[]   = (string) $p;
            }
        }

        // notices (payment received, refund, warning)
        $notices = (array) ($m['notices'] ?? []);
        foreach ($notices as $n) {
            if (!\is_array($n) || trim((string) ($n['text'] ?? '')) === '') {
                continue;
            }
            $tone = (string) ($n['tone'] ?? 'info');
            [$nb, $nl, $nt, $cls] = $tone === 'ok' ? ['#eef8f1', '#7bc99b', '#14532d', 'note-ok'] : ($tone === 'warn' ? ['#fff6e5', '#f0b45a', '#6b3f00', 'note-warn'] : [$boxBg, $boxLine, '#1f2433', 'note-box']);
            $content .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 16px 0;"><tr><td class="' . $cls . '" style="background-color:' . $nb . ';border:1px solid ' . $nl . ';border-radius:8px;padding:12px 16px;font-family:' . $F . ';font-size:15px;line-height:23px;font-weight:600;color:' . $nt . ';">' . self::e($n['text']) . '</td></tr></table>';
            $text[] = (string) $n['text'];
        }

        // summary box
        $rows = (array) (($m['summary'] ?? [])['rows'] ?? []);
        if ($rows) {
            $content .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px 0;"><tr><td class="box" style="background-color:' . $boxBg . ';border:1px solid ' . $boxLine . ';border-left:6px solid ' . $accent . ';border-radius:8px;padding:14px 20px;">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
            foreach ($rows as $i => $r) {
                $val = $r[1];
                $url = '';
                if (\is_array($val)) {
                    [$val, $url] = [$val[0], $val[1] ?? ''];
                }
                $cellV = $url !== ''
                    ? '<a href="' . self::e($url) . '" class="t-link" style="color:' . $link . ';text-decoration:underline;">' . self::e($val) . '</a>'
                    : self::e($val);
                $content .= '<tr><td class="stack t-muted" valign="top" style="padding:5px 14px 5px 0;width:120px;font-family:' . $F . ';font-size:13px;line-height:20px;color:#5b6074;">' . self::e($r[0]) . '</td>'
                    . '<td class="stack t-head" valign="top" style="padding:5px 0;font-family:' . $F . ';font-size:15px;line-height:22px;font-weight:700;color:' . $headC . ';">' . $cellV . '</td></tr>';
                $text[] = $r[0] . ': ' . $val . ($url !== '' ? ' (' . $url . ')' : '');
            }
            $content .= '</table></td></tr></table>';
        }

        // the element's own text goes in the body of the layout
        $bodyHtml = trim((string) ($m['body_html'] ?? ''));
        if ($bodyHtml !== '') {
            $content .= '<div class="t-body body-custom" style="margin:0 0 14px 0;font-family:' . $F . ';font-size:16px;line-height:25px;color:' . $textC . ';">' . $bodyHtml . '</div>';
            $text[] = self::htmlToText($bodyHtml);
        }

        // data sections (staff: the customer, the answers)
        foreach ((array) ($m['sections'] ?? []) as $sec) {
            $srows = (array) ($sec['rows'] ?? []);
            if (!$srows) {
                continue;
            }
            $content .= '<p class="t-link" style="margin:18px 0 6px 0;font-family:' . $F . ';font-size:12px;line-height:16px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:' . $link . ';">' . self::e($sec['title'] ?? '') . '</p>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 6px 0;border-top:1px solid #e3e6ec;" class="rule">';
            $text[] = '';
            $text[] = mb_strtoupper((string) ($sec['title'] ?? ''));
            foreach ($srows as $r) {
                $v = (string) $r[1];
                if (!empty($sec['stacked'])) {
                    // question in bold, the answer under it (one cell each)
                    $content .= '<tr><td class="t-head rule" style="padding:10px 0 2px 0;font-family:' . $F . ';font-size:14px;line-height:20px;font-weight:700;color:' . $headC . ';">' . self::e($r[0]) . '</td></tr>'
                        . '<tr><td class="t-body rule" style="padding:0 0 10px 0;border-bottom:1px solid #e3e6ec;font-family:' . $F . ';font-size:15px;line-height:22px;color:' . $textC . ';">' . nl2br(self::linkify($v, $link), false) . '</td></tr>';
                    $text[] = $r[0] . ': ' . $v;
                    continue;
                }
                $content .= '<tr><td class="stack t-muted rule" valign="top" style="padding:8px 14px 8px 0;width:140px;border-bottom:1px solid #e3e6ec;font-family:' . $F . ';font-size:13px;line-height:20px;color:#5b6074;">' . self::e($r[0]) . '</td>'
                    . '<td class="stack t-body rule" valign="top" style="padding:8px 0;border-bottom:1px solid #e3e6ec;font-family:' . $F . ';font-size:15px;line-height:22px;color:' . $textC . ';">' . nl2br(self::linkify($v, $link), false) . '</td></tr>';
                $text[] = $r[0] . ': ' . $v;
            }
            $content .= '</table>';
        }

        // buttons: one main action, the others as quiet links
        $buttons = array_values(array_filter((array) ($m['buttons'] ?? []), static fn($x) => \is_array($x) && !empty($x['url']) && !empty($x['label'])));
        $primary = null;
        $others  = [];
        foreach ($buttons as $x) {
            if ($primary === null && !empty($x['primary'])) {
                $primary = $x;
            } else {
                $others[] = $x;
            }
        }
        if ($primary !== null) {
            $content .= self::space(14)
                . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>'
                . '<td class="btn-td" align="center" bgcolor="' . $btn . '" style="background-color:' . $btn . ';border-radius:8px;"><a class="btn-a" href="' . self::e($primary['url']) . '" style="display:inline-block;padding:15px 34px;font-family:' . $F . ';font-size:17px;line-height:22px;font-weight:700;color:' . $btnText . ';text-decoration:none;border-radius:8px;">' . self::e($primary['label']) . '</a></td></tr></table>';
            $text[] = '';
            $text[] = $primary['label'] . ': ' . $primary['url'];
        }
        if ($others) {
            $links = [];
            foreach ($others as $x) {
                $links[] = '<a href="' . self::e($x['url']) . '" class="t-link" style="color:' . $link . ';font-weight:700;text-decoration:underline;">' . self::e($x['label']) . '</a>';
                $text[]  = $x['label'] . ': ' . $x['url'];
            }
            $lbl = trim((string) ($m['others_label'] ?? ''));
            if ($lbl !== '') {
                $content .= '<p class="t-muted" style="margin:18px 0 0 0;text-align:center;font-family:' . $F . ';font-size:14px;line-height:22px;color:#5b6074;">' . self::e($lbl) . '</p>';
                $text[] = '';
                $text[] = $lbl;
            }
            $content .= '<p style="margin:' . ($lbl !== '' ? '2' : ($primary !== null ? '18' : '6')) . 'px 0 0 0;text-align:center;font-family:' . $F . ';font-size:15px;line-height:24px;">' . implode('<span class="t-muted" style="color:#5b6074;">&nbsp;&nbsp;&middot;&nbsp;&nbsp;</span>', $links) . '</p>';
            if ($primary === null) {
                array_unshift($text, '');
            }
        }

        $sign = trim((string) ($m['signoff'] ?? ''));
        if ($sign !== '') {
            $content .= self::space(14) . '<p class="t-body" style="margin:0;font-family:' . $F . ';font-size:16px;line-height:25px;color:' . $textC . ';">' . self::e($sign) . '</p>';
            $text[] = '';
            $text[] = $sign;
        }

        // ---- header
        $logo     = trim((string) ($b['logo'] ?? ''));
        $logoDark = trim((string) ($b['logo_dark'] ?? ''));
        if ($logo !== '') {
            $margin = $center ? 'margin:0 auto;' : '';
            $img    = static fn(string $src, string $cls): string => '<img src="' . self::e($src) . '" alt="' . self::e($name) . '" width="150" class="' . $cls . '" style="display:block;' . $margin . 'border:0;outline:none;text-decoration:none;max-width:60%;height:auto;">';
            $mark   = $img($logo, $logoDark !== '' ? 'logo-l' : 'logo-i') . ($logoDark !== '' ? '<div class="logo-d" style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . $img($logoDark, 'logo-di') . '</div>' : '');
            // a logo without a dark version keeps a white plate in dark mode (dark lettering on a dark card would vanish), as the Brevo templates do
            $header = '<td class="px ' . ($logoDark !== '' ? 'hdr' : 'hdr-plate') . '"' . ($center ? ' align="center"' : '') . ' style="padding:26px 40px ' . ($rule ? '20' : '18') . 'px 40px;background-color:#ffffff;background-image:linear-gradient(#ffffff,#ffffff);">' . ($siteUrl !== '' ? '<a href="' . self::e($siteUrl) . '" style="text-decoration:none;">' . $mark . '</a>' : $mark) . '</td>';
        } else {
            $label  = '<span class="t-head" style="font-family:' . $F . ';font-size:20px;line-height:26px;font-weight:700;color:' . $headC . ';">' . self::e($name) . '</span>';
            $header = '<td class="px hdr"' . ($center ? ' align="center"' : '') . ' style="padding:26px 40px ' . ($rule ? '20' : '18') . 'px 40px;' . ($center ? 'text-align:center;' : '') . '">' . ($siteUrl !== '' ? '<a href="' . self::e($siteUrl) . '" style="text-decoration:none;">' . $label . '</a>' : $label) . '</td>';
        }

        // ---- footer
        $flines = [];
        $flines[] = '<p class="f1" style="margin:0 0 6px 0;font-family:' . $F . ';font-size:14px;line-height:22px;font-weight:700;color:' . $fc1 . ';">' . self::e($name) . '</p>';
        $ftext    = [$name];
        $foot     = trim((string) ($b['footer'] ?? ''));
        if ($foot !== '') {
            $flines[] = '<p class="f2" style="margin:0 0 8px 0;font-family:' . $F . ';font-size:13px;line-height:21px;color:' . $fc2 . ';">' . nl2br(self::linkifyFooter($foot, $fc1), false) . '</p>';
            $ftext[]  = $foot;
        }
        $note = trim((string) ($m['footer_note'] ?? ''));
        if ($note !== '') {
            $flines[] = '<p class="f3" style="margin:0;font-family:' . $F . ';font-size:12px;line-height:19px;color:' . $fc3 . ';">' . self::e($note) . '</p>';
            $ftext[]  = $note;
        }
        $footer = $footOut
            ? '<td class="px footer fout" align="center" style="padding:20px 32px 4px 32px;text-align:center;">' . implode('', $flines) . '</td>'
            : '<td class="px footer" style="padding:26px 40px 28px 40px;background-color:' . $footBg . ';' . ($footDark ? '' : 'border-top:1px solid #e3e6ec;') . '">' . implode('', $flines) . '</td>';

        $text[] = '';
        $text[] = '--';
        foreach ($ftext as $l) {
            $text[] = $l;
        }

        // ---- document
        $css = '  :root { color-scheme: light dark; supported-color-schemes: light dark; }
  body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
  img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
  a { color:' . $link . '; }
  .footer a, .footer a:link, .footer a:visited { color:' . $fc1 . ' !important; text-decoration:underline !important; }
  u + #body .footer a, #MessageViewBody .footer a { color:' . $fc1 . ' !important; text-decoration:underline !important; }
  a[x-apple-data-detectors], .footer a[x-apple-data-detectors] { color:inherit !important; text-decoration:none !important; font-size:inherit !important; font-family:inherit !important; font-weight:inherit !important; line-height:inherit !important; }
  .body-custom p { margin:0 0 14px 0; }
  @media only screen and (max-width:620px) {
    .container { width:100% !important; }
    .px { padding-left:20px !important; padding-right:20px !important; }
    .h1 { font-size:22px !important; line-height:28px !important; }
    .stack { display:block !important; width:100% !important; padding-right:0 !important; }
    .btn-td { display:block !important; width:100% !important; }
    .btn-a { display:block !important; padding:16px 12px !important; }
  }
  @media (prefers-color-scheme: dark) {
    body, .bg-page { background-color:#14151d !important; }
    .card { background-color:#1e2030 !important; }
    .hdr { background-color:#1e2030 !important; background-image:none !important; }
    .t-head { color:#f4f5f9 !important; }
    .t-body { color:#d6d8e2 !important; }
    .t-muted { color:#9ea1b3 !important; }
    .t-link, a.t-link { color:' . $linkDark . ' !important; }
    .box { background-color:' . $boxBgD . ' !important; border-color:#3a3e57 !important; border-left-color:' . $accent . ' !important; }
    .note-box { background-color:' . $boxBgD . ' !important; border-color:#3a3e57 !important; }
    .note-ok { background-color:#16301f !important; border-color:#2f6b45 !important; color:#b9efcd !important; }
    .note-warn { background-color:#3a2a10 !important; border-color:#8a6420 !important; color:#ffdca3 !important; }
    .rule { border-color:#34374d !important; }
    .footer { background-color:#0f1017 !important; }
    .fout { background-color:transparent !important; }
    .footer .f1, .footer a, .footer a:link, .footer a:visited, u + #body .footer a, #MessageViewBody .footer a { color:#ffffff !important; }
    .footer .f2 { color:#d5d7e3 !important; }
    .footer .f3 { color:#b6b9cc !important; }
    .logo-l { display:none !important; }
    .logo-d { display:block !important; max-height:none !important; overflow:visible !important; }
  }
  [data-ogsc] .t-head { color:#f4f5f9 !important; }
  [data-ogsc] .t-body { color:#d6d8e2 !important; }
  [data-ogsc] .t-muted { color:#9ea1b3 !important; }
  [data-ogsc] .t-link { color:' . $linkDark . ' !important; }
  [data-ogsb] .bg-page { background-color:#14151d !important; }
  [data-ogsb] .card { background-color:#1e2030 !important; }
  [data-ogsb] .box, [data-ogsb] .note-box { background-color:' . $boxBgD . ' !important; }
  [data-ogsb] .footer { background-color:#0f1017 !important; }';

        $html = '<!DOCTYPE html>' . "\n"
            . '<html lang="' . self::e($lang) . '" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width,initial-scale=1">' . "\n"
            . '<meta http-equiv="X-UA-Compatible" content="IE=edge">' . "\n"
            . '<meta name="x-apple-disable-message-reformatting">' . "\n"
            . '<meta name="format-detection" content="telephone=no,address=no,email=no,date=no">' . "\n"
            . '<meta name="color-scheme" content="light dark">' . "\n"
            . '<meta name="supported-color-schemes" content="light dark">' . "\n"
            . '<title>' . self::e($title !== '' ? $title : $name) . '</title>' . "\n"
            . '<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->' . "\n"
            . '<style>' . "\n" . $css . "\n" . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body id="body" class="bg-page" style="margin:0;padding:0;background-color:#f2f4f7;font-family:' . $F . ';">' . "\n"
            . ($pre !== '' ? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;">' . self::e($pre) . str_repeat('&#8199;&zwnj;', 40) . '</div>' . "\n" : '')
            . '<table role="presentation" class="bg-page" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f2f4f7;"><tr><td align="center" style="padding:20px 12px 32px 12px;">' . "\n"
            . '<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->' . "\n"
            . '<table role="presentation" class="container card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:10px;overflow:hidden;">' . "\n"
            . ($rule ? '' : '<tr><td class="accent" height="5" bgcolor="' . $line . '" style="height:5px;line-height:5px;font-size:0;background-color:' . $line . ';">&nbsp;</td></tr>' . "\n")
            . '<tr>' . $header . '</tr>' . "\n"
            . ($rule ? '<tr><td class="px" style="padding:0 40px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="accent" style="border-top:3px solid ' . $line . ';font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>' . "\n" : '')
            . '<tr><td class="px" style="padding:' . ($rule ? '24' : '8') . 'px 40px ' . ($footOut ? '28' : '30') . 'px 40px;">' . $content . '</td></tr>' . "\n"
            . ($footOut ? '' : '<tr>' . $footer . '</tr>' . "\n")
            . '</table>' . "\n"
            . ($footOut ? '<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;"><tr>' . $footer . '</tr></table>' . "\n" : '')
            . '<!--[if mso]></td></tr></table><![endif]-->' . "\n"
            . '</td></tr></table>' . "\n"
            . '</body></html>';

        return ['html' => $html, 'text' => implode("\n", $text) . "\n"];
    }

    /** Footer text: escaped, with web addresses as links in the footer colour. */
    private static function linkifyFooter(string $s, string $color = '#ffffff'): string
    {
        return self::linkify($s, $color, 'f-link', true);
    }
}
