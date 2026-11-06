<?php

namespace BaseOS\Connect\Tests;

use BaseOS\Connect\Data\BridgeConnection;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Data\BridgePost;
use BaseOS\Connect\Data\RemoteResult;
use PHPUnit\Framework\TestCase;

final class BridgePostTest extends TestCase
{
    private function ctx(?string $tenant = 'site-a'): BridgeContext
    {
        return new BridgeContext(new BridgeConnection('sc_1', 'whsec_x', $tenant, 'https://www.site-a.com'), $tenant, '2.1');
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'remote_id' => '123',
            'state' => 'published',
            'content' => [
                'content_id' => 'c_01J',
                'revision' => 7,
                'content_hash' => 'sha256:abc',
                'locale' => 'vi',
                'content_type' => 'blog_post',
                'title' => 'Mùa lúa chín',
                'slug' => 'mua-lua-chin',
                'excerpt' => 'Tóm tắt',
                'body_html' => '<h2>Mùa lúa</h2><p>Nội dung</p>',
                'seo' => ['title' => 'SEO title', 'description' => 'SEO desc'],
                'taxonomy' => ['category' => 'Ẩm thực', 'tags' => ['du lịch', 'mùa thu']],
                'featured_media' => ['url' => 'https://cdn/x.jpg', 'alt' => 'X'],
                'published_at' => '2026-11-05T00:00:00+00:00',
                'body_blocks' => [['type' => 'h2', 'id' => 'mua-lua', 'text' => 'Mùa lúa']],
            ],
            'target' => [
                'post_type' => 'post',
                'author' => 'user:7',
                'locale' => 'en',
                'body_format' => 'blocks',
                'terms' => [
                    'category' => [['ref' => 'term:12']],
                    'post_tag' => [['ref' => 'term:40'], ['name' => 'du lịch tự túc', 'slug' => 'du-lich-tu-tuc', 'create' => true]],
                ],
                'fields' => ['acf:rating' => 5],
            ],
            'params' => ['site_id' => 3, 'channel' => 'blog'],
            'mapping_version' => 4,
            'fields_mask' => null,
            'options' => [],
        ];
    }

    public function test_content_accessors(): void
    {
        $p = new BridgePost($this->data(), $this->ctx());

        $this->assertSame('123', $p->remoteId());
        $this->assertSame('published', $p->state());
        $this->assertTrue($p->isPublished());
        $this->assertSame('c_01J', $p->contentId());
        $this->assertSame(7, $p->revision());
        $this->assertSame('sha256:abc', $p->contentHash());
        $this->assertSame('Mùa lúa chín', $p->title());
        $this->assertSame('mua-lua-chin', $p->slug());
        $this->assertSame('Tóm tắt', $p->excerpt());
        $this->assertSame('<h2>Mùa lúa</h2><p>Nội dung</p>', $p->bodyHtml());
        $this->assertSame('SEO title', $p->seo('title'));
        $this->assertSame(['url' => 'https://cdn/x.jpg', 'alt' => 'X'], $p->featuredMedia());
        $this->assertSame('SEO desc', $p->content('seo.description'));
        $this->assertCount(1, $p->bodyBlocks());
        $this->assertSame(4, $p->mappingVersion());
    }

    public function test_target_terms_fields_params_and_tenant(): void
    {
        $p = new BridgePost($this->data(), $this->ctx());

        $this->assertSame('en', $p->locale(), 'target.locale overrides content.locale');
        $this->assertSame('post', $p->postType());
        $this->assertSame('user:7', $p->author());
        $this->assertSame('blocks', $p->bodyFormat());
        $this->assertSame([['ref' => 'term:12']], $p->terms('category'));
        $this->assertCount(2, $p->terms('post_tag'));
        $this->assertSame([], $p->terms('unknown'));
        $this->assertSame('term:40', $p->target('terms.post_tag.0.ref'));
        $this->assertSame('fallback', $p->target('nope.deep', 'fallback'));
        $this->assertSame(['acf:rating' => 5], $p->fields());
        $this->assertSame(5, $p->field('acf:rating'));
        $this->assertSame(3, $p->param('site_id'));
        $this->assertSame('x', $p->param('missing', 'x'));
        $this->assertSame(['site_id' => 3, 'channel' => 'blog'], $p->params());
        $this->assertSame('site-a', $p->tenant());
        $this->assertSame('sc_1', $p->context()->connectionId());
        $this->assertTrue($p->manages('title'));
    }

    public function test_protocol_2_0_fallbacks(): void
    {
        $data = $this->data();
        unset($data['target'], $data['params'], $data['content']['body_blocks'], $data['remote_id']);
        $data['fields_mask'] = ['title', 'seo'];
        $p = new BridgePost($data, $this->ctx(null));

        $this->assertNull($p->remoteId());
        $this->assertSame('vi', $p->locale());
        $this->assertSame('html', $p->bodyFormat());
        $this->assertNull($p->bodyBlocks());
        $this->assertSame([['name' => 'Ẩm thực', 'slug' => 'am-thuc', 'create' => true]], $p->terms('category'));
        $this->assertSame('mua-thu', $p->terms('post_tag')[1]['slug']);
        $this->assertSame([], $p->params());
        $this->assertNull($p->tenant());
        $this->assertTrue($p->manages('title'));
        $this->assertFalse($p->manages('slug'));
    }

    public function test_body_html_rendered_from_blocks_when_html_missing(): void
    {
        $data = $this->data();
        $data['content']['body_html'] = '';
        $p = new BridgePost($data, $this->ctx());

        $this->assertSame('<h2 id="mua-lua">Mùa lúa</h2>', $p->bodyHtml());
    }

    public function test_remote_result_to_array_matches_server_keys(): void
    {
        $r = new RemoteResult('42', 'https://s/x', 'https://s/admin/42', 'published', 'sha256:1', 7, 'c_1');

        $this->assertSame([
            'remote_id' => '42',
            'url' => 'https://s/x',
            'admin_url' => 'https://s/admin/42',
            'status' => 'published',
            'remote_hash' => 'sha256:1',
            'revision_seen' => 7,
            'content_id' => 'c_1',
        ], $r->toArray());
        $this->assertEquals($r, RemoteResult::fromArray($r->toArray()));
    }

    public function test_connection_never_exposes_secret(): void
    {
        $c = new BridgeConnection('sc_1', 'whsec_secret', null, 'https://www.Site-A.com/blog');

        $this->assertSame('site-a.com', $c->siteHost());
        $this->assertStringNotContainsString('whsec_secret', json_encode($c->toArray()));
        $this->assertStringNotContainsString('whsec_secret', print_r($c, true));
    }
}
