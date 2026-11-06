<?php

namespace BaseOS\Connect\Tests;

use BaseOS\Connect\Support\Blocks;
use PHPUnit\Framework\TestCase;

final class BlocksTest extends TestCase
{
    public function test_renders_every_block_type(): void
    {
        $html = Blocks::toHtml([
            ['type' => 'h2', 'id' => 'mua-lua', 'text' => 'Mùa <em>lúa</em>'],
            ['type' => 'h3', 'id' => 'sub', 'text' => 'Sub'],
            ['type' => 'p', 'text' => 'Xem <a href="https://x.vn">đây</a>'],
            ['type' => 'ul', 'items' => ['Một', 'Hai']],
            ['type' => 'ol', 'items' => ['A']],
            ['type' => 'image', 'src' => 'https://cdn/a.jpg', 'alt' => 'Ảnh "đẹp"', 'caption' => 'Chú thích'],
            ['type' => 'quote', 'text' => 'Trích dẫn'],
            ['type' => 'html', 'html' => '<table><tr><td>1</td></tr></table>'],
        ]);

        $this->assertSame(implode("\n", [
            '<h2 id="mua-lua">Mùa <em>lúa</em></h2>',
            '<h3 id="sub">Sub</h3>',
            '<p>Xem <a href="https://x.vn">đây</a></p>',
            '<ul><li>Một</li><li>Hai</li></ul>',
            '<ol><li>A</li></ol>',
            '<figure><img src="https://cdn/a.jpg" alt="Ảnh &quot;đẹp&quot;" loading="lazy"><figcaption>Chú thích</figcaption></figure>',
            '<blockquote>Trích dẫn</blockquote>',
            '<table><tr><td>1</td></tr></table>',
        ]), $html);
    }

    public function test_skips_empty_and_invalid_blocks(): void
    {
        $this->assertSame('', Blocks::toHtml([
            ['type' => 'p', 'text' => '   '],
            ['type' => 'ul', 'items' => []],
            ['type' => 'image'],
            'not-an-array',
        ]));
    }

    public function test_escapes_attributes_and_supports_options(): void
    {
        $html = Blocks::toHtml([
            ['type' => 'h2', 'id' => '"><script>', 'text' => 'T'],
            ['type' => 'image', 'src' => 'https://cdn/a.jpg"onerror="x', 'alt' => ''],
        ], ['figure_class' => 'wp-block-image']);

        $this->assertStringNotContainsString('"><script>', $html);
        $this->assertStringContainsString('&quot;onerror=&quot;x', $html);
        $this->assertStringContainsString('<figure class="wp-block-image">', $html);

        $this->assertSame('<h2>T</h2>', Blocks::toHtml([['type' => 'h2', 'id' => 'x', 'text' => 'T']], ['heading_ids' => false]));
    }

    public function test_strip_unsafe_reapplies_inline_allow_list(): void
    {
        $html = Blocks::toHtml([['type' => 'p', 'text' => 'ok <strong>b</strong><script>x</script>']], ['strip_unsafe' => true]);

        $this->assertSame('<p>ok <strong>b</strong>x</p>', $html);
    }

    public function test_headings_and_text(): void
    {
        $blocks = [
            ['type' => 'h2', 'id' => 'a', 'text' => 'A &amp; <em>B</em>'],
            ['type' => 'p', 'text' => 'Body'],
            ['type' => 'h3', 'id' => 'c', 'text' => 'C'],
        ];

        $this->assertSame([
            ['level' => 2, 'id' => 'a', 'text' => 'A & B'],
            ['level' => 3, 'id' => 'c', 'text' => 'C'],
        ], Blocks::headings($blocks));
        $this->assertSame("A & B\nBody\nC", Blocks::toText($blocks));
    }
}
