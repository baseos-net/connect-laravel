<?php

namespace BaseOS\Connect\Support;

/**
 * Render / inspect `content.body_blocks` (produced by Base OS `HtmlToBlocks`).
 *
 *   h2 | h3 | h4  {id, text}      text = inline HTML
 *   p             {text}
 *   ul | ol       {items: list<inline HTML>}
 *   image         {src, alt, caption}
 *   quote         {text}
 *   html          {html}          tables, SVG, anything not convertible
 *
 * Inline HTML is already sanitized by Base OS (a strong b em i u code br sup sub mark s);
 * it is output as-is. Attributes (src, alt, id) are escaped here.
 */
final class Blocks
{
    public const INLINE_ALLOWED = '<a><strong><b><em><i><u><code><br><sup><sub><mark><s>';

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array{heading_ids?: bool, figure_class?: string|null, strip_unsafe?: bool}  $options
     *   strip_unsafe: re-apply the inline allow-list to text/items (defense in depth).
     */
    public static function toHtml(array $blocks, array $options = []): string
    {
        $headingIds = $options['heading_ids'] ?? true;
        $figureClass = $options['figure_class'] ?? null;
        $strip = $options['strip_unsafe'] ?? false;
        $inline = fn (mixed $v): string => $strip
            ? strip_tags((string) $v, self::INLINE_ALLOWED)
            : (string) $v;

        $out = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');

            switch ($type) {
                case 'h2':
                case 'h3':
                case 'h4':
                    $id = $headingIds && ! empty($block['id']) ? ' id="'.self::e((string) $block['id']).'"' : '';
                    $out[] = "<{$type}{$id}>".$inline($block['text'] ?? '')."</{$type}>";
                    break;

                case 'p':
                    $text = $inline($block['text'] ?? '');
                    if (trim($text) !== '') {
                        $out[] = '<p>'.$text.'</p>';
                    }
                    break;

                case 'ul':
                case 'ol':
                    $items = array_filter((array) ($block['items'] ?? []), fn ($i) => is_scalar($i) && trim((string) $i) !== '');
                    if ($items !== []) {
                        $out[] = "<{$type}>".implode('', array_map(fn ($i) => '<li>'.$inline($i).'</li>', $items))."</{$type}>";
                    }
                    break;

                case 'image':
                    if (empty($block['src'])) {
                        break;
                    }
                    $img = '<img src="'.self::e((string) $block['src']).'" alt="'.self::e((string) ($block['alt'] ?? '')).'" loading="lazy">';
                    $caption = isset($block['caption']) && $block['caption'] !== '' ? (string) $block['caption'] : null;
                    $class = $figureClass ? ' class="'.self::e($figureClass).'"' : '';
                    $out[] = $caption !== null
                        ? "<figure{$class}>{$img}<figcaption>".self::e($caption).'</figcaption></figure>'
                        : "<figure{$class}>{$img}</figure>";
                    break;

                case 'quote':
                    $out[] = '<blockquote>'.$inline($block['text'] ?? '').'</blockquote>';
                    break;

                case 'html':
                    $out[] = (string) ($block['html'] ?? '');
                    break;

                default:
                    if (isset($block['html'])) {
                        $out[] = (string) $block['html'];
                    } elseif (isset($block['text'])) {
                        $out[] = '<p>'.$inline($block['text']).'</p>';
                    }
            }
        }

        return implode("\n", $out);
    }

    /**
     * Headings for a table of contents.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{level: int, id: string|null, text: string}>
     */
    public static function headings(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $type = is_array($b) ? (string) ($b['type'] ?? '') : '';
            if (in_array($type, ['h2', 'h3', 'h4'], true)) {
                $out[] = [
                    'level' => (int) substr($type, 1),
                    'id' => isset($b['id']) ? (string) $b['id'] : null,
                    'text' => trim(html_entity_decode(strip_tags((string) ($b['text'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                ];
            }
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $blocks */
    public static function toText(array $blocks): string
    {
        $html = self::toHtml($blocks, ['heading_ids' => false]);
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</h2>', '</h3>', '</h4>', '<br>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{2,}/", "\n", $text) ?? $text);
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
