<?php

declare(strict_types=1);

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Whitelist-based HTML sanitizer for rich text fields produced by the Tiptap editor.
 *
 * - Disallowed tags are unwrapped (text content is preserved, the wrapping tag is removed).
 * - Disallowed attributes are stripped from allowed tags.
 * - href/src attributes are rejected when they use javascript:, vbscript:, or data: schemes.
 * - External anchor links are forced to rel="nofollow noopener".
 */
class HtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 's', 'u', 'code',
        'ul', 'ol', 'li', 'blockquote',
        'h2', 'h3', 'h4',
        'a', 'img',
        'pre', 'hr',
        'div', 'span',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'style', 'data-align'],
        'ul' => ['data-type'],
        'ol' => ['start', 'type'],
        'li' => ['data-type', 'data-checked'],
        'p' => ['style'],
        'h2' => ['style'],
        'h3' => ['style'],
        'h4' => ['style'],
        'span' => ['style'],
        'table' => ['class', 'style'],
        'th' => ['colspan', 'rowspan', 'colwidth', 'style'],
        'td' => ['colspan', 'rowspan', 'colwidth', 'style'],
        'col' => ['style', 'span'],
    ];

    /** @var list<string> */
    private const DROP_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta',
        'input', 'button', 'form', 'textarea', 'select', 'option',
    ];

    public static function clean(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"?><div id="sk-sanitizer-root">'.$trimmed.'</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('sk-sanitizer-root');
        if ($root === null) {
            return '';
        }

        self::sanitizeNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        $dropNodes = [];
        $unwrapNodes = [];

        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_ELEMENTS, true)) {
                $dropNodes[] = $child;

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::sanitizeNode($child);
                $unwrapNodes[] = $child;

                continue;
            }

            self::filterAttributes($child, $tag);
            self::sanitizeNode($child);
        }

        foreach ($dropNodes as $dropped) {
            $dropped->parentNode?->removeChild($dropped);
        }

        foreach ($unwrapNodes as $wrapper) {
            self::unwrap($wrapper);
        }
    }

    private static function filterAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if (in_array($name, ['href', 'src'], true) && ! self::isSafeUrl($attribute->nodeValue ?? '')) {
                $element->removeAttribute($name);

                continue;
            }

            if ($name === 'style') {
                $cleanStyle = self::filterStyle($attribute->nodeValue ?? '');
                if ($cleanStyle === '') {
                    $element->removeAttribute('style');
                } else {
                    $element->setAttribute('style', $cleanStyle);
                }
            }
        }

        if ($tag === 'a' && $element->hasAttribute('target')) {
            $element->setAttribute('rel', 'nofollow noopener');
        }
    }

    /**
     * Style attributes are validated property-by-property. Only a small set of
     * typography and sizing declarations survives — no `url()`, `expression()`,
     * `javascript:`, etc. Anything outside the whitelist is silently dropped.
     */
    private static function filterStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }

            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if (preg_match('/(javascript|vbscript|data):/i', $value) === 1) {
                continue;
            }
            if (stripos($value, 'url(') !== false || stripos($value, 'expression(') !== false) {
                continue;
            }

            if ($property === 'text-align') {
                $lowerValue = strtolower($value);
                if (in_array($lowerValue, ['left', 'right', 'center', 'justify'], true)) {
                    $kept[] = 'text-align: '.$lowerValue;
                }

                continue;
            }

            if (in_array($property, ['width', 'min-width', 'max-width', 'height'], true)) {
                if (preg_match('/^\d+(?:\.\d+)?\s*(?:%|px|em|rem|vh|vw)$/i', $value) === 1) {
                    $kept[] = $property.': '.$value;
                }

                continue;
            }

            if (in_array($property, ['color', 'background-color', '--sk-table-border'], true)) {
                if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1) {
                    $kept[] = $property.': '.strtolower($value);
                } elseif (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $value) === 1) {
                    $kept[] = $property.': '.$value;
                }
            }
        }

        return implode('; ', $kept);
    }

    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * Accept only URLs whose scheme (if any) is on a small allowlist.
     *
     * Relative URLs (no scheme — e.g. `/media/x.jpg`, `#top`, `./about`) are
     * considered safe. Absolute URLs must use one of: http, https, mailto,
     * tel. Everything else (javascript:, vbscript:, data:, blob:, file:,
     * ftp:, …) is rejected — blocklist approaches have repeatedly failed as
     * new dangerous schemes surface.
     */
    private static function isSafeUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        // Relative URL (no scheme) — safe.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $trimmed) !== 1) {
            return true;
        }

        return preg_match('#^(?:https?|mailto|tel):#i', $trimmed) === 1;
    }
}
