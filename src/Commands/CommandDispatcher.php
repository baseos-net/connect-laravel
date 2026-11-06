<?php

namespace BaseOS\Connect\Commands;

use BaseOS\Connect\Bridge;
use BaseOS\Connect\BridgeManager;
use BaseOS\Connect\Contracts\IdempotencyStore;
use BaseOS\Connect\Contracts\ValidatesPosts;
use BaseOS\Connect\Data\BridgeConnection;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Data\BridgePost;
use BaseOS\Connect\Data\RemoteResult;
use BaseOS\Connect\Exceptions\BridgeException;
use BaseOS\Connect\Validation\PostValidator;
use Throwable;

/**
 * Executes one command envelope:
 *   {id, type, issued_at, expires_at, idempotency_key, correlation_id, data}
 * and returns `{id, ok:true, result}` or `{id, ok:false, error:{code, message, retryable}}`.
 *
 * Idempotency: successful results of mutating commands are cached per
 * (connection, type, idempotency_key). The type is part of the key because Base OS
 * sends `post.find` with the SAME envelope key as the `post.upsert` it is recovering.
 * Read-only commands (`post.validate`, `post.find`, `post.fetch`, `site.*`) are never cached.
 */
class CommandDispatcher
{
    public const MUTATING = ['post.upsert', 'post.set_state'];

    public const STATES = ['published', 'draft', 'unpublished', 'trashed'];

    public function __construct(
        protected BridgeManager $bridge,
        protected IdempotencyStore $idempotency,
        protected int $idempotencyTtl = 604800,
        protected bool $exposeErrors = false,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{status: int, body: array<string, mixed>}
     */
    public function dispatch(array $envelope, BridgeConnection $connection, ?string $protocol = null): array
    {
        $id = (string) ($envelope['id'] ?? '');
        $type = (string) ($envelope['type'] ?? '');
        $key = (string) ($envelope['idempotency_key'] ?? '');
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $cacheKey = $key !== '' && in_array($type, self::MUTATING, true) ? self::cacheKey($connection->connectionId, $type, $key) : null;

        if ($cacheKey !== null && ($cached = $this->idempotency->get($cacheKey)) !== null) {
            return ['status' => 200, 'body' => ['id' => $id] + $cached + ['replayed' => true]];
        }

        try {
            if ($type === '') {
                throw new BridgeException('protocol.invalid_envelope', 'Missing command type.', false, 400);
            }
            if (! in_array($type, Bridge::COMMANDS, true)) {
                throw BridgeException::unsupported($type);
            }
            if (! empty($envelope['expires_at']) && ($exp = strtotime((string) $envelope['expires_at'])) !== false && $exp < time()) {
                throw new BridgeException('command.expired', 'Command expired.', true, 410);
            }

            $ctx = $this->bridge->context($connection, $protocol, $id ?: null, $key ?: null);
            $result = $this->bridge->withinTenant($ctx, fn () => $this->run($type, $data, $ctx));
            $body = ['id' => $id, 'ok' => true, 'result' => $result];

            if ($cacheKey !== null) {
                $this->idempotency->put($cacheKey, ['ok' => true, 'result' => $result], $this->idempotencyTtl);
            }

            return ['status' => 200, 'body' => $body];
        } catch (BridgeException $e) {
            return ['status' => $e->status(), 'body' => ['id' => $id, 'ok' => false, 'error' => $e->toError()]];
        } catch (Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return ['status' => 500, 'body' => ['id' => $id, 'ok' => false, 'error' => [
                'code' => 'internal',
                'message' => $this->exposeErrors ? $e->getMessage() : 'Receiver internal error.',
                'retryable' => true,
            ]]];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function run(string $type, array $data, BridgeContext $ctx): array
    {
        return match ($type) {
            'site.health' => $this->health($ctx),
            'site.schema' => $this->bridge->schema($ctx, enterTenant: false),
            'site.verify' => ['challenge_signature' => hash_hmac('sha256', (string) ($data['challenge'] ?? ''), $ctx->connection->secret())],
            'post.upsert' => $this->upsert($data, $ctx),
            'post.set_state' => $this->setState($data, $ctx),
            'post.fetch' => $this->fetch($data, $ctx),
            'post.find' => $this->find($data, $ctx),
            'post.validate' => $this->validate($data, $ctx),
            default => throw BridgeException::unsupported($type),
        };
    }

    /**
     * Dry-run of post.upsert: `{valid, issues[]}`. Writes nothing and is never idempotency-cached.
     * Built-in checks (PostValidator, against the current tenant's schema) + ValidatesPosts if implemented.
     *
     * @param  array<string, mixed>  $data
     */
    protected function validate(array $data, BridgeContext $ctx): array
    {
        if (! is_array($data['content'] ?? null)) {
            return PostValidator::result([PostValidator::issue('content.missing', 'content', 'Thiếu nội dung bài (receiver này chưa hỗ trợ content_url).')]);
        }

        $post = new BridgePost($data, $ctx);
        $issues = [];

        try {
            $schema = $this->bridge->schema($ctx, enterTenant: false);
        } catch (Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            $schema = [];
            $issues[] = PostValidator::issue('schema.unavailable', null, 'Không đọc được cấu trúc site — bỏ qua kiểm tra trường/tham số bắt buộc.', false);
        }
        $issues = array_merge($issues, PostValidator::check($post, $schema));

        $repo = $this->bridge->repository();
        if ($repo instanceof ValidatesPosts) {
            try {
                $issues = array_merge($issues, $repo->validate($post, $post->remoteId()));
            } catch (BridgeException $e) {
                $issues[] = PostValidator::issue($e->errorCode, null, $e->getMessage());
            }
        }

        return PostValidator::result($issues);
    }

    /** @return array<string, mixed> */
    protected function health(BridgeContext $ctx): array
    {
        return [
            'ok' => true,
            'flavor' => Bridge::FLAVOR,
            'receiver_version' => Bridge::VERSION,
            'protocol_supported' => Bridge::PROTOCOLS,
            'features' => $this->bridge->features(),
            'tenant' => $ctx->tenant,
            'time' => gmdate('c'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function upsert(array $data, BridgeContext $ctx): array
    {
        if (! is_array($data['content'] ?? null)) {
            if (! empty($data['content_url'])) {
                throw new BridgeException('post.invalid', 'content_url (payload > 2 MB) is not supported by this receiver.', false, 422);
            }
            throw BridgeException::invalid('Missing content.');
        }

        $post = new BridgePost($data, $ctx);
        if (trim($post->title()) === '') {
            throw BridgeException::invalid('Missing title.');
        }
        if (! in_array($post->state(), ['published', 'draft'], true)) {
            throw BridgeException::invalid('Invalid state: '.$post->state());
        }

        $result = $this->bridge->repository()->upsert($post, $post->remoteId());

        return $this->complete($result, $post)->toArray();
    }

    /** @param  array<string, mixed>  $data */
    protected function setState(array $data, BridgeContext $ctx): array
    {
        $remoteId = (string) ($data['remote_id'] ?? '');
        $state = (string) ($data['state'] ?? '');
        if ($remoteId === '') {
            throw BridgeException::notFound('Missing remote_id.');
        }
        if (! in_array($state, self::STATES, true)) {
            throw BridgeException::invalid('Invalid state: '.$state);
        }

        return $this->bridge->repository()->setState($remoteId, $state, $ctx)->toArray();
    }

    /** @param  array<string, mixed>  $data */
    protected function fetch(array $data, BridgeContext $ctx): array
    {
        $remoteId = (string) ($data['remote_id'] ?? '');
        $found = $remoteId !== '' ? $this->bridge->repository()->fetch($remoteId, $ctx) : null;
        if (! $found) {
            throw BridgeException::notFound();
        }

        return $found->toArray() + ['found' => true];
    }

    /**
     * `{found, seen_key, ...RemoteSnapshot}` — flat, as read by ReceiverAdapter::snapshotFromResult.
     *
     * @param  array<string, mixed>  $data
     */
    protected function find(array $data, BridgeContext $ctx): array
    {
        $key = (string) ($data['idempotency_key'] ?? '');
        if ($key !== '') {
            foreach (self::MUTATING as $type) {
                $cached = $this->idempotency->get(self::cacheKey($ctx->connectionId(), $type, $key));
                if (is_array($cached['result'] ?? null) && ! empty($cached['result']['remote_id'])) {
                    return ['found' => true, 'seen_key' => true] + $cached['result'];
                }
            }
        }

        $repo = $this->bridge->repository();
        $remoteId = (string) ($data['remote_id'] ?? '');
        $result = $remoteId !== '' ? $repo->fetch($remoteId, $ctx) : null;

        $contentId = (string) ($data['content_id'] ?? '');
        if (! $result && $contentId !== '') {
            $result = $repo->find($contentId, $ctx);
        }

        return $result
            ? ['found' => true, 'seen_key' => false] + $result->toArray()
            : ['found' => false, 'seen_key' => false];
    }

    protected function complete(RemoteResult $result, BridgePost $post): RemoteResult
    {
        if ($result->remoteId === '') {
            throw BridgeException::invalid('Repository returned an empty remote_id.');
        }

        return new RemoteResult(
            remoteId: $result->remoteId,
            url: $result->url,
            adminUrl: $result->adminUrl,
            status: $result->status,
            remoteHash: $result->remoteHash ?? $post->contentHash(),
            revisionSeen: $result->revisionSeen ?? $post->revision(),
            contentId: $result->contentId ?? $post->contentId(),
            modifiedAt: $result->modifiedAt,
            fields: $result->fields,
        );
    }

    public static function cacheKey(string $connectionId, string $type, string $idempotencyKey): string
    {
        return hash('sha256', $connectionId.'|'.$type.'|'.$idempotencyKey);
    }
}
