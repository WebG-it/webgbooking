<?php

/**
 * @package     plg_system_webgbooking
 * @copyright   (C) 2026 Marco Galassi / WebG
 * @license     GNU General Public License version 2 or later
 *
 * The footer text of the emails and of the direct booking page (option "mail_footer"). Since 0.36.0 it can be written in an editor,
 * so a person can make a link with words ("Privacy Policy") instead of showing the address. What is stored is a SMALL, closed
 * language: p, br, strong, b, em, i and links (a href) to http, https or mailto, nothing else. Everything else (script, style,
 * class, on* handlers, javascript: or data: addresses, iframes, forms) is dropped, both when the options are saved and again every
 * time the text is drawn. A text with no tags at all is the old plain text and is drawn exactly as before (line breaks kept,
 * web addresses made into links).
 */

namespace WebG\Plugin\System\Webgbooking\Booking;

\defined('_JEXEC') or die;

final class FooterText
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'a'];

    /** Elements that go away WITH what is inside (their content is code or markup noise, never words). */
    private const DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template', 'svg', 'math', 'form', 'textarea', 'select', 'option', 'button', 'input', 'head', 'title', 'meta', 'link', 'base', 'audio', 'video', 'canvas', 'map', 'xml'];

    /** Does the text carry markup of the editor (as opposed to the old plain text)? */
    public static function isRich(string $s): bool
    {
        // a real tag: "<" then a name made of letters and digits, then a space, a slash or ">" ("<mario@x.it>" and "a < b" are words)
        return (bool) preg_match('#</?[a-zA-Z][a-zA-Z0-9]*(?:[\s/][^>]*)?>#', $s) || (bool) preg_match('#<!--|<\?#', $s);
    }

    /**
     * Allowed markup only, canonical (lower-case tags, one attribute: href on a). Plain text (no tags) is returned as it is.
     * Safe to call again on its own result.
     */
    public static function sanitize(string $s): string
    {
        $s = trim(str_replace("\0", '', $s));
        if ($s === '' || !self::isRich($s)) {
            return $s;
        }
        // the document tags are noise (and a stray </body> would cut what follows); a "<" that does not open a real tag is a word: "<mario@x.it>"
        $s = (string) preg_replace('#</?(?:html|body|head)\b[^>]*>#i', '', $s);
        $s = (string) preg_replace('#<(?!/?[a-zA-Z][a-zA-Z0-9]*[\s/>]|!--|\?)#', '&lt;', $s);
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $s . '</body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementsByTagName('body')->item(0);
        if (!$root) {
            return trim(htmlspecialchars(strip_tags($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        $out = self::walk($root);
        // empty paragraphs and blanks at the ends carry nothing
        $out = (string) preg_replace('#<p>(?:\s|\x{00A0}|<br>)*</p>#iu', '', $out);
        $out = (string) preg_replace('#^(\s|<br>)+|(\s|<br>)+$#i', '', $out);

        return trim($out);
    }

    private static function walk(\DOMNode $node, bool $inLink = false): string
    {
        $out = '';
        foreach ($node->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $out .= htmlspecialchars($c->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!($c instanceof \DOMElement)) {
                continue;                        // comments, processing instructions
            }
            $tag = strtolower($c->tagName);
            if (\in_array($tag, self::DROP, true)) {
                continue;
            }
            if (!\in_array($tag, self::ALLOWED, true)) {
                $out .= self::walk($c, $inLink); // an unknown tag: keep its words, lose the tag
                continue;
            }
            if ($tag === 'br') {
                $out .= '<br>';
            } elseif ($tag === 'a') {
                if ($inLink) {
                    $out .= self::walk($c, true);        // a link inside a link: one link only, the inner words stay
                    continue;
                }
                $href = self::url((string) $c->getAttribute('href'));
                $in   = self::walk($c, true);
                $void = trim(str_replace("\xC2\xA0", ' ', strip_tags($in))) === '' && stripos($in, '<br>') === false;
                $out .= $href !== '' && !$void ? '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $in . '</a>' : $in;
            } else {
                $out .= '<' . $tag . '>' . self::walk($c, $inLink) . '</' . $tag . '>';
            }
        }

        return $out;
    }

    /** An address a footer link may point to: http, https, mailto. '' = refused. Tabs, new lines and entities are resolved first, so "java&#9;script:" is seen for what it is. */
    public static function url(string $u): string
    {
        $u = html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // browsers drop tabs, new lines and invisible characters anywhere in an address ("java<tab>script:" is "javascript:"): so do we, before looking at the scheme
        $u = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', '', $u);
        $u = str_replace(' ', '%20', trim((string) preg_replace('/\p{Zs}/u', ' ', $u)));
        if ($u === '' || strlen($u) > 2000 || preg_match('/[<>"\'\\\\`]/', $u)) {
            return '';
        }
        // a web address: no user name and password in front of the host ("https://good.example@evil.example/" is evil.example)
        if (preg_match('#^https?://([^/?\#]+)#i', $u, $m) === 1) {
            return strpos($m[1], '@') === false ? $u : '';
        }
        // one mail address, nothing else (no second recipient, no subject, no body)
        if (preg_match('/^mailto:[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/i', $u) === 1) {
            return $u;
        }

        return '';
    }

    /**
     * The text for the editor: the old plain text becomes paragraphs and line breaks (and its web addresses links, as they were
     * drawn), so nothing flattens when the editor opens it. Text that is already HTML goes through the filter.
     */
    public static function toEditorHtml(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (self::isRich($s)) {
            return self::sanitize($s);
        }
        $paras = [];
        foreach (preg_split('/\R{2,}/', $s) ?: [] as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $lines = [];
            foreach (preg_split('/\R/', $p) ?: [] as $line) {
                $lines[] = self::linkText(rtrim($line));
            }
            $paras[] = '<p>' . implode('<br>', $lines) . '</p>';
        }

        return implode("\n", $paras);
    }

    /** Plain text escaped, its http(s) addresses made into links (the shape plain texts have always had). */
    private static function linkText(string $t): string
    {
        $parts = preg_split('#(https?://[^\s<>"\']+)#i', $t, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$t];
        $out   = '';
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1) {
                $url   = rtrim($p, '.,;:)');
                $trail = substr($p, \strlen($url));
                $ok    = self::url($url);
                $out  .= ($ok !== '' ? '<a href="' . htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>' : htmlspecialchars($url, ENT_QUOTES, 'UTF-8')) . htmlspecialchars($trail, ENT_QUOTES, 'UTF-8');
            } else {
                $out .= htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            }
        }

        return $out;
    }

    /**
     * For drawing inside ONE paragraph of the layout: paragraphs become line breaks (a paragraph inside a paragraph is not valid),
     * links get rel noopener and the given attributes (colour in emails, new tab on the page), the words go through $textFn (the
     * emails cut them so mail apps do not turn them into blue links). Always filters again first.
     *
     * @param  callable|null  $textFn  fn(string $rawText): string  returns ESCAPED html for a piece of plain text
     */
    public static function inline(string $s, string $linkAttrs, ?callable $textFn = null): string
    {
        $h = self::sanitize($s);
        $h = (string) preg_replace('#</p>\s*<p>#i', '<br>', $h);
        $h = (string) preg_replace('#</?p>#i', '', $h);
        $h = (string) preg_replace('#(?:<br>\s*){3,}#i', '<br><br>', $h);
        $h = (string) preg_replace('#^(\s|<br>)+|(\s|<br>)+$#i', '', $h);
        $parts = preg_split('#(<a href="[^"]*">.*?</a>|<[^>]+>)#is', $h, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($parts as $p) {
            if (preg_match('#^<a href="([^"]*)">(.*)</a>$#is', $p, $m)) {
                $out .= '<a href="' . $m[1] . '" ' . $linkAttrs . ' rel="noopener noreferrer">' . $m[2] . '</a>';
            } elseif ($p !== '' && $p[0] === '<') {
                $out .= $p;
            } else {
                $out .= self::autoLink(html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $linkAttrs, $textFn);
            }
        }

        return $out;
    }

    /** Bare web addresses typed in rich text become links too (as in the plain text). $raw is the decoded text; the result is escaped. */
    private static function autoLink(string $raw, string $linkAttrs, ?callable $textFn): string
    {
        $esc   = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $word  = $textFn ?? $esc;
        $parts = preg_split('#(https?://[^\s<>"\']+)#i', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$raw];
        $out   = '';
        foreach ($parts as $i => $q) {
            if ($i % 2 === 1) {
                $url   = rtrim($q, '.,;:)');
                $trail = substr($q, \strlen($url));
                $ok    = self::url($url);
                $out  .= ($ok !== '' ? '<a href="' . $esc($ok) . '" ' . $linkAttrs . ' rel="noopener noreferrer">' . $esc($url) . '</a>' : $esc($url)) . $word($trail);
            } elseif ($q !== '') {
                $out .= $word($q);
            }
        }

        return $out;
    }

    /** Plain-text version for the text part of an email: tags gone, a link as "words (address)", paragraphs and breaks as lines. */
    public static function toText(string $s): string
    {
        $h = self::sanitize($s);
        if (!self::isRich($h)) {
            return $h;
        }
        $h = (string) preg_replace_callback('#<a href="([^"]*)">(.*?)</a>#is', static function ($m) {
            $url   = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $words = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $bare  = (string) preg_replace('#^mailto:#i', '', $url);
            if ($words === '' || $words === $url || $words === $bare) {
                return $bare;
            }

            return $words . ' (' . $bare . ')';
        }, $h);
        $h = (string) preg_replace('#<br\s*/?>#i', "\n", $h);
        $h = (string) preg_replace('#</p>\s*<p>#i', "\n\n", $h);
        $h = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = (string) preg_replace("/[ \t]+\n/", "\n", $h);
        $h = (string) preg_replace("/\n{3,}/", "\n\n", $h);

        return trim($h);
    }

    /** The web address of the privacy link: the link whose words or address say "privacy". '' when there is none. */
    public static function privacyHref(string $s): string
    {
        $h = self::sanitize($s);
        if (!self::isRich($h)) {
            return '';
        }
        if (preg_match_all('#<a href="([^"]*)">(.*?)</a>#is', $h, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!preg_match('#^https?://#i', $url)) {
                    continue;
                }
                if (stripos(strip_tags($m[2]), 'privacy') !== false || stripos($url, 'privacy') !== false) {
                    return $url;
                }
            }
        }

        return '';
    }
}
