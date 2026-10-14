<?php

namespace App\Services;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class MarkdownImport
{
    public function document(string $markdown): array
    {
        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = (string) $converter->convert($markdown);
        $dom = new \DOMDocument;
        $prior = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        $nodes = [];
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $child) {
            $nodes = [...$nodes, ...$this->convert($child)];
        }

        return ['type' => 'doc', 'content' => $nodes ?: [['type' => 'paragraph']]];
    }

    private function convert(\DOMNode $node, array $marks = []): array
    {
        if ($node instanceof \DOMText) {
            return trim($node->textContent) === '' && ! in_array(strtolower($node->parentNode?->nodeName ?? ''), ['p', 'li', 'td', 'th', 'strong', 'em', 'a', 'span']) ? [] : [['type' => 'text', 'text' => $node->textContent, ...($marks ? ['marks' => $marks] : [])]];
        }
        if (! $node instanceof \DOMElement) {
            return [];
        }
        $tag = strtolower($node->tagName);
        $mark = ['strong' => 'bold', 'b' => 'bold', 'em' => 'italic', 'i' => 'italic', 's' => 'strike', 'del' => 'strike', 'code' => 'code'][$tag] ?? null;
        if ($mark) {
            $marks[] = ['type' => $mark];
        }
        if ($tag === 'a') {
            $href = $node->getAttribute('href');
            if (preg_match('~^(https?://|mailto:)~', $href)) {
                $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
            }
        }
        if ($tag === 'br') {
            return [['type' => 'hardBreak']];
        } if ($tag === 'hr') {
            return [['type' => 'horizontalRule']];
        }
        if ($tag === 'pre') {
            return [['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => $node->textContent]]]];
        }
        if ($tag === 'img') {
            return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '[Изображение из источника: '.$node->getAttribute('alt').']']]]];
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            $children = [...$children, ...$this->convert($child, $marks)];
        }
        $map = ['p' => 'paragraph', 'ul' => 'bulletList', 'ol' => 'orderedList', 'li' => 'listItem', 'blockquote' => 'blockquote', 'table' => 'table', 'tr' => 'tableRow', 'td' => 'tableCell', 'th' => 'tableHeader'];
        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            return [['type' => 'heading', 'attrs' => ['level' => (int) $m[1]], 'content' => $children]];
        }
        if (! isset($map[$tag])) {
            return $children;
        }
        if ($tag === 'li' || $tag === 'td' || $tag === 'th') {
            $out = [];
            $inline = [];
            foreach ($children as $c) {
                if (in_array($c['type'], ['text', 'hardBreak'])) {
                    $inline[] = $c;
                } else {
                    if ($inline) {
                        $out[] = ['type' => 'paragraph', 'content' => $inline];
                        $inline = [];
                    }$out[] = $c;
                }
            }
            if ($inline) {
                $out[] = ['type' => 'paragraph', 'content' => $inline];
            }$children = $out ?: [['type' => 'paragraph']];
        }

        return [['type' => $map[$tag], 'content' => $children]];
    }
}
