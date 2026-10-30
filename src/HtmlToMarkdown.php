<?php

namespace GlpiPlugin\Gitlabissues;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Converts the rich-text HTML of GLPI tickets to GitLab-flavoured Markdown.
 *
 * Only the markup produced by GLPI's editor is handled (paragraphs, headings, lists,
 * tables, links, images, code, quotes, emphasis). Unknown tags are replaced by their
 * content, scripts and styles are dropped.
 */
final class HtmlToMarkdown
{
    /**
     * @param Closure|null $image_resolver fn(string $src, string $alt): ?string
     *        Returns the Markdown to use for an image, or null to fall back to a plain link.
     * @param string       $base_url       Used to make relative links absolute (GLPI base URL)
     */
    public function __construct(
        private readonly ?Closure $image_resolver = null,
        private readonly string $base_url = '',
    ) {}

    public function convert(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom      = new DOMDocument();
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $html . '</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        $markdown = $this->blocks($body);
        $markdown = preg_replace("/[ \t]+\n/", "\n", $markdown) ?? $markdown;
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    // ------------------------------------------------------------------ blocks

    /**
     * Render the children of a container as a sequence of blocks separated by blank lines.
     */
    private function blocks(DOMNode $container, string $indent = ''): string
    {
        $out    = '';
        $inline = '';

        $flush = function () use (&$out, &$inline, $indent): void {
            $text = $this->tidy($inline);
            if ($text !== '') {
                $out .= $indent . str_replace("\n", "\n" . $indent, $text) . "\n\n";
            }
            $inline = '';
        };

        foreach ($container->childNodes as $node) {
            if ($node instanceof DOMElement && $this->isBlock($node->nodeName)) {
                $flush();
                $out .= $this->block($node, $indent);
            } else {
                $inline .= $this->inline($node);
            }
        }
        $flush();

        return $out;
    }

    private function isBlock(string $tag): bool
    {
        return in_array($tag, [
            'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote',
            'pre', 'hr', 'table', 'section', 'article', 'header', 'footer', 'figure', 'li',
        ], true);
    }

    private function block(DOMElement $el, string $indent): string
    {
        switch ($el->nodeName) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $text = $this->tidy($this->inlineChildren($el));

                return $text === '' ? '' : $indent . str_repeat('#', (int) $el->nodeName[1]) . ' ' . $text . "\n\n";

            case 'ul':
            case 'ol':
                return $this->listBlock($el, $indent) . "\n";

            case 'blockquote':
                $inner = trim($this->blocks($el));
                if ($inner === '') {
                    return '';
                }
                $quoted = implode("\n", array_map(
                    static fn(string $line): string => rtrim($indent . '> ' . $line),
                    explode("\n", $inner)
                ));

                return $quoted . "\n\n";

            case 'pre':
                $code = $el->textContent;
                $lang = '';
                $code_el = $el->getElementsByTagName('code')->item(0);
                if ($code_el instanceof DOMElement && preg_match('/language-([\w+#-]+)/', $code_el->getAttribute('class'), $m)) {
                    $lang = $m[1];
                }
                $code  = rtrim(str_replace("\r", '', $code), "\n");
                // Use a fence longer than any backtick run in the code.
                $fence = '```';
                while (str_contains($code, $fence)) {
                    $fence .= '`';
                }

                return $indent . $fence . $lang . "\n" . $code . "\n" . $fence . "\n\n";

            case 'hr':
                return $indent . "---\n\n";

            case 'table':
                return $this->table($el, $indent);

            default: // p, div, section... : paragraphs and nested blocks
                return $this->blocks($el, $indent);
        }
    }

    private function listBlock(DOMElement $list, string $indent): string
    {
        $ordered = $list->nodeName === 'ol';
        $number  = max(1, (int) ($list->getAttribute('start') ?: 1));
        $out     = '';

        foreach ($list->childNodes as $item) {
            if (!($item instanceof DOMElement) || $item->nodeName !== 'li') {
                continue;
            }
            $marker  = $ordered ? ($number++ . '. ') : '- ';
            $padding = str_repeat(' ', strlen($marker));

            // Split the item into its own text and its nested lists / blocks.
            $text   = '';
            $nested = '';
            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->nodeName, ['ul', 'ol'], true)) {
                    $nested .= $this->listBlock($child, $indent . $padding);
                } elseif ($child instanceof DOMElement && $this->isBlock($child->nodeName)) {
                    $text .= ' ' . trim($this->blocks($child));
                } else {
                    $text .= $this->inline($child);
                }
            }
            $text = $this->tidy(preg_replace('/\s*\n\s*/', ' ', str_replace("\\\n", ' ', $text)) ?? $text);
            $out .= $indent . $marker . $text . "\n" . $nested;
        }

        return $out;
    }

    private function table(DOMElement $table, string $indent): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array($cell->nodeName, ['td', 'th'], true)) {
                    $text    = $this->tidy(preg_replace('/\s+/', ' ', str_replace("\\\n", ' ', $this->inlineChildren($cell))) ?? '');
                    $cells[] = str_replace('|', '\\|', $text);
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return '';
        }

        $columns = max(array_map('count', $rows));
        $line    = static fn(array $cells): string
            => '| ' . implode(' | ', array_pad($cells, $columns, '')) . ' |';

        $out  = $indent . $line(array_shift($rows)) . "\n";
        $out .= $indent . '|' . str_repeat(' --- |', $columns) . "\n";
        foreach ($rows as $row) {
            $out .= $indent . $line($row) . "\n";
        }

        return $out . "\n";
    }

    // ------------------------------------------------------------------ inline

    private function inlineChildren(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->inline($child);
        }

        return $out;
    }

    private function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $this->escape(preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '');
        }
        if (!($node instanceof DOMElement)) {
            return '';
        }

        switch ($node->nodeName) {
            case 'br':
                // CommonMark hard line break (survives trailing-space trimming)
                return "\\\n";

            case 'strong': case 'b':
                return $this->wrap($this->inlineChildren($node), '**');

            case 'em': case 'i':
                return $this->wrap($this->inlineChildren($node), '*');

            case 'del': case 's': case 'strike':
                return $this->wrap($this->inlineChildren($node), '~~');

            case 'code':
                $code  = $node->textContent;
                $fence = str_contains($code, '`') ? '``' : '`';

                return $code === '' ? '' : $fence . ($fence === '``' ? ' ' . $code . ' ' : $code) . $fence;

            case 'a':
                $text = trim($this->inlineChildren($node));
                $href = $this->safeUrl($node->getAttribute('href'));
                if ($href === null) {
                    return $text;
                }

                return '[' . ($text !== '' ? $text : $href) . '](' . $this->escapeUrl($href) . ')';

            case 'img':
                return $this->image($node);

            case 'script': case 'style': case 'head':
                return '';

            default:
                // span, font, u, sub, sup, label... and block elements met inside inline context
                return $this->inlineChildren($node);
        }
    }

    private function image(DOMElement $img): string
    {
        $src = $img->getAttribute('src');
        $alt = trim($img->getAttribute('alt'));

        if ($this->image_resolver !== null) {
            $md = ($this->image_resolver)($src, $alt);
            if ($md !== null) {
                return $md;
            }
        }

        $url = $this->safeUrl($src);
        if ($url === null) {
            return $alt !== '' ? $this->escape($alt) : '';
        }

        return '![' . $this->escape($alt) . '](' . $this->escapeUrl($url) . ')';
    }

    /**
     * Only http(s) and mailto links are kept; relative URLs are made absolute.
     */
    private function safeUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && $this->base_url !== '') {
            $url = rtrim($this->base_url, '/') . $url;
        }

        return preg_match('#^(https?://|mailto:)#i', $url) ? $url : null;
    }

    private function escapeUrl(string $url): string
    {
        return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $url);
    }

    /**
     * Trim and collapse the runs of spaces produced when an emphasis marker is
     * surrounded by spaces coming from the source text nodes.
     */
    private function tidy(string $text): string
    {
        return trim(preg_replace('/(?<=\S) {2,}(?=\S)/u', ' ', $text) ?? $text);
    }

    private function wrap(string $text, string $mark): string
    {
        // Keep surrounding spaces outside the markers, otherwise Markdown does not render them.
        if (trim($text) === '') {
            return $text;
        }
        preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m);

        return $m[1] . $mark . $m[2] . $mark . $m[3];
    }

    /**
     * Escape the characters that would be interpreted as Markdown inside normal text.
     */
    private function escape(string $text): string
    {
        $text = preg_replace('/([\\\\`*_\[\]<>~|])/', '\\\\$1', $text) ?? $text;
        // Line-start constructs (headings, lists, quotes) are only special at the start of a block.
        return preg_replace('/^(\s*)([#>+-]|\d+[.)])(\s)/m', '$1\\\\$2$3', $text) ?? $text;
    }
}
