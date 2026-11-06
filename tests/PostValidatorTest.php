<?php

namespace BaseOS\Connect\Tests;

use BaseOS\Connect\Data\BridgeConnection;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Data\BridgePost;
use BaseOS\Connect\Validation\PostValidator;
use PHPUnit\Framework\TestCase;

final class PostValidatorTest extends TestCase
{
    private function post(array $override = [], array $content = [], array $target = []): BridgePost
    {
        $data = array_replace([
            'remote_id' => null,
            'state' => 'draft',
            'content' => array_replace([
                'content_id' => 'c_01J',
                'title' => 'Mùa lúa chín',
                'slug' => 'mua-lua-chin',
                'body_html' => '<p>Nội dung</p>',
                'locale' => 'vi',
            ], $content),
            'target' => array_replace([
                'post_type' => 'post',
                'author' => 'user:7',
                'terms' => ['category' => [['ref' => 'term:12']]],
                'fields' => ['meta:rating' => 5],
            ], $target),
            'params' => ['channel' => 'blog'],
        ], $override);
        $ctx = new BridgeContext(new BridgeConnection('sc_1', 'whsec_x', 'site-a', 'https://site-a.com'), 'site-a', '2.1');

        return new BridgePost($data, $ctx);
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'post_types' => [['ref' => 'post', 'label' => 'Bài viết', 'taxonomies' => ['category']], ['ref' => 'tour']],
            'taxonomies' => [['ref' => 'category', 'terms' => [
                ['ref' => 'term:12', 'name' => 'Ẩm thực', 'slug' => 'am-thuc'],
            ]]],
            'fields' => [
                ['ref' => 'meta:rating', 'label' => 'Số sao', 'type' => 'number', 'required' => true, 'post_types' => ['post']],
                ['ref' => 'meta:price', 'label' => 'Giá tour', 'type' => 'number', 'required' => true, 'post_types' => ['tour']],
                ['ref' => 'meta:badge', 'label' => 'Nhãn', 'required' => true, 'default' => 'new'],
            ],
            'authors' => [['ref' => 'user:7', 'name' => 'Biên tập']],
            'locales' => ['vi', 'en'],
            'params_spec' => [['key' => 'channel', 'label' => 'Kênh', 'required' => true]],
            'defaults' => ['post_type' => 'post'],
        ];
    }

    /** @param  list<array<string, mixed>>  $issues */
    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    public function test_valid_post_has_no_issues(): void
    {
        $result = PostValidator::result(PostValidator::check($this->post(), $this->schema()));

        $this->assertSame(['valid' => true, 'issues' => []], $result);
    }

    public function test_empty_title_and_body_are_blocking(): void
    {
        $result = PostValidator::result(PostValidator::check($this->post([], ['title' => '  ', 'body_html' => '']), []));

        $this->assertFalse($result['valid']);
        $this->assertSame(['content.title_required', 'content.body_required'], $this->codes($result['issues']));
        $this->assertSame('content.title', $result['issues'][0]['path']);
        $this->assertTrue($result['issues'][1]['blocking']);
    }

    public function test_fields_mask_skips_unmanaged_title(): void
    {
        $issues = PostValidator::check($this->post(['remote_id' => '5', 'fields_mask' => ['seo']], ['title' => '', 'body_html' => '']), []);

        $this->assertSame([], $issues);
    }

    public function test_required_fields_follow_post_type_and_default(): void
    {
        $issues = PostValidator::check($this->post([], [], ['fields' => []]), $this->schema());
        $this->assertSame(['field.required'], $this->codes($issues));
        $this->assertSame('target.fields.meta:rating', $issues[0]['path']);
        $this->assertTrue($issues[0]['blocking']);

        $tour = PostValidator::check($this->post([], [], ['post_type' => 'tour', 'fields' => [], 'terms' => []]), $this->schema());
        $this->assertSame(['target.fields.meta:price'], array_column($tour, 'path'));
    }

    public function test_required_param_and_unknown_post_type(): void
    {
        $issues = PostValidator::check($this->post(['params' => []], [], ['post_type' => 'page']), $this->schema());

        $this->assertSame(['post_type.unknown', 'param.required'], $this->codes($issues));
        $this->assertSame('params.channel', $issues[1]['path']);
    }

    public function test_terms_author_locale_are_warnings(): void
    {
        $issues = PostValidator::check($this->post([], [], [
            'author' => 'user:99',
            'locale' => 'fr',
            'terms' => [
                'category' => [['ref' => 'term:404'], ['name' => 'Ẩm thực'], ['name' => 'Mới tinh'], ['name' => 'Tạo được', 'create' => true]],
                'brand' => [['ref' => 'term:1']],
            ],
        ]), $this->schema());

        $this->assertSame(['author.unknown', 'term.not_found', 'term.missing', 'taxonomy.unknown', 'locale.unknown'], $this->codes($issues));
        $this->assertSame('target.terms.category.2', $issues[2]['path']);
        $this->assertNotContains(true, array_column($issues, 'blocking'));
        $this->assertTrue(PostValidator::result($issues)['valid']);
    }

    public function test_locale_matches_region_variants(): void
    {
        $issues = PostValidator::check($this->post([], [], ['locale' => 'en-US']), $this->schema());

        $this->assertSame([], $issues);
    }

    public function test_normalize_lets_custom_issues_override_built_in(): void
    {
        $result = PostValidator::result([
            PostValidator::issue('field.required', 'target.fields.meta:rating', 'built-in'),
            ['code' => 'field.required', 'path' => 'target.fields.meta:rating', 'message' => 'site says optional', 'blocking' => false],
            ['code' => 'media.featured_required', 'message' => 'Cần ảnh'],
            'Lỗi dạng chuỗi',
            ['message' => 'no code → dropped'],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertCount(3, $result['issues']);
        $this->assertSame(['code' => 'field.required', 'path' => 'target.fields.meta:rating', 'message' => 'site says optional', 'blocking' => false], $result['issues'][0]);
        $this->assertSame(['code' => 'media.featured_required', 'path' => null, 'message' => 'Cần ảnh', 'blocking' => true], $result['issues'][1]);
        $this->assertSame('post.invalid', $result['issues'][2]['code']);
    }

    public function test_is_test_content_id(): void
    {
        $this->assertTrue($this->post([], ['content_id' => 'baseos-test-01J9'])->isTest());
        $this->assertFalse($this->post()->isTest());
    }
}
