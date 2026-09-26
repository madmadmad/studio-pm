<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Server-side counterpart to resources/js/lib/richText.js, for HTML saved
 * by the Tiptap editor (proposal bodies and item details) that has to be
 * printed outside the browser -- the proposal PDF. There's no Tiptap
 * schema to parse against here, so toSafeHtml() keeps only the tags the
 * editor's toolbar can produce and drops every attribute.
 */
class RichText
{
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 's', 'u', 'h2', 'h3', 'h4', 'ul', 'ol', 'li'];

    // Same test as isBlankRichText(): an emptied editor saves "<p></p>".
    public static function isBlank(?string $value): bool
    {
        return $value === null || trim(strip_tags($value)) === '';
    }

    // Sanitized HTML, safe to print unescaped. Plain text that predates
    // rich text is escaped and split into one paragraph per line, like
    // toRichText().
    public static function toSafeHtml(?string $value): string
    {
        if (self::isBlank($value)) {
            return '';
        }

        if (! self::isHtml($value)) {
            return collect(explode("\n", $value))
                ->map(fn ($line) => '<p>'.e($line).'</p>')
                ->implode('');
        }

        $body = self::parse($value);
        self::clean($body);

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $body->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    // Mirrors toPlainText(): one line per paragraph or heading, list items
    // prefixed with "- ". Used to spot item details that just repeat the
    // item's name (a custom item's name is its details as plain text).
    public static function toPlainText(?string $value): string
    {
        if (self::isBlank($value)) {
            return '';
        }

        if (! self::isHtml($value)) {
            return $value;
        }

        $body = self::parse($value);
        $doc = $body->ownerDocument;

        foreach (iterator_to_array($doc->getElementsByTagName('br')) as $br) {
            $br->parentNode->replaceChild($doc->createTextNode("\n"), $br);
        }
        foreach ($doc->getElementsByTagName('li') as $li) {
            $li->insertBefore($doc->createTextNode('- '), $li->firstChild);
        }
        foreach (['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                $el->appendChild($doc->createTextNode("\n"));
            }
        }

        return rtrim($body->textContent, "\n");
    }

    private static function isHtml(string $value): bool
    {
        return (bool) preg_match('/^\s*</', $value);
    }

    // The fragment's <body>. The XML prolog makes libxml read it as UTF-8
    // (it assumes Latin-1 otherwise).
    private static function parse(string $html): DOMElement
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>');
        libxml_clear_errors();

        return $doc->getElementsByTagName('body')->item(0);
    }

    // Unwraps disallowed elements (keeping their text), removes scripts
    // and styles outright, and strips all attributes.
    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, ['script', 'style'], true)) {
                    $node->removeChild($child);

                    continue;
                }

                self::clean($child);

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttributeNode($attr);
                }
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
