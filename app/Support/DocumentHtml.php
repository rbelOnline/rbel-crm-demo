<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Whitelist sanitizer for HTML coming from the document editor.
 *
 * Keeps only document formatting (paragraphs, headings, inline styles, lists,
 * tables, embedded images) and a small set of CSS properties. Scripts, event
 * handlers, links, iframes, forms and remote images are removed, so the HTML is
 * safe to store, render in the editor and convert to .docx (the converter would
 * otherwise fetch remote image URLs). Output is well-formed XHTML.
 */
class DocumentHtml
{
    private const TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'span', 'mark', 'br',
        'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'blockquote', 'hr',
    ];

    /** Elements removed together with their content. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'template', 'head', 'title', 'meta', 'link'];

    private const CSS = ['text-align', 'font-weight', 'font-style', 'text-decoration', 'color', 'background-color', 'font-size', 'font-family', 'width'];

    public const MAX_BYTES = 8 * 1024 * 1024;

    public static function sanitize(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        // The XML declaration makes libxml read the fragment as UTF-8.
        @$dom->loadHTML('<?xml encoding="UTF-8"?><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }

        self::clean($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveXML($child);
        }

        return trim($out);
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROP, true)) {
                    $node->removeChild($child);

                    continue;
                }

                self::clean($child);

                if (! in_array($tag, self::TAGS, true)) {
                    // Unknown wrapper (div, a, font, …): keep its content, drop the tag.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                self::cleanAttributes($child, $tag);

                if ($tag === 'img' && ! $child->hasAttribute('src')) {
                    $node->removeChild($child);
                }
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanAttributes(DOMElement $el, string $tag): void
    {
        $keep = [];
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = $attr->value;

            if ($name === 'style' && ($style = self::cleanStyle($value)) !== '') {
                $keep['style'] = $style;
            } elseif (in_array($name, ['colspan', 'rowspan'], true) && in_array($tag, ['td', 'th'], true) && ctype_digit($value) && (int) $value <= 50) {
                $keep[$name] = $value;
            } elseif ($tag === 'img' && $name === 'src' && preg_match('#^data:image/(png|jpe?g|gif);base64,[A-Za-z0-9+/=\s]+$#', $value)) {
                // Embedded images only: no remote URLs.
                $keep['src'] = $value;
            } elseif ($tag === 'img' && in_array($name, ['width', 'height'], true) && ctype_digit($value) && (int) $value <= 3000) {
                $keep[$name] = $value;
            } elseif ($tag === 'img' && $name === 'alt') {
                $keep['alt'] = mb_substr($value, 0, 200);
            }
        }

        while ($el->attributes->length) {
            $el->removeAttribute($el->attributes->item(0)->name);
        }
        foreach ($keep as $name => $value) {
            $el->setAttribute($name, $value);
        }
    }

    private static function cleanStyle(string $style): string
    {
        $rules = [];
        foreach (explode(';', $style) as $decl) {
            [$prop, $value] = array_map('trim', array_pad(explode(':', $decl, 2), 2, ''));
            $prop = strtolower($prop);

            // Plain values only: no url(), expression(), escapes or quotes games.
            if (in_array($prop, self::CSS, true) && $value !== '' && preg_match('/^[#%.,\w\s\-"\']{1,80}$/u', $value) && ! preg_match('/url|expression|javascript/i', $value)) {
                $rules[] = "{$prop}: {$value}";
            }
        }

        return implode('; ', $rules);
    }

    /** Placeholders still present as text, e.g. ["owner_address"]. */
    public static function placeholders(string $html): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', html_entity_decode(strip_tags($html)), $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }
}
