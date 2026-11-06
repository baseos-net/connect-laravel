<?php

namespace BaseOS\Connect\Contracts;

use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Data\BridgePost;
use BaseOS\Connect\Data\RemoteResult;

/**
 * The only thing your app must implement: how a Base OS post maps to your storage.
 *
 * Throw BaseOS\Connect\Exceptions\BridgeException for expected failures
 * (e.g. BridgeException::notFound(), BridgeException::invalid('...')).
 * When a tenant is resolved, the configured TenantScope has already been entered.
 */
interface ContentRepository
{
    /**
     * Create ($remoteId === null) or update the post. Must be idempotent per content id:
     * if $remoteId is null but a row with $post->contentId() exists, update it.
     */
    public function upsert(BridgePost $post, ?string $remoteId): RemoteResult;

    /**
     * @param  string  $state  published | draft | unpublished | trashed
     */
    public function setState(string $remoteId, string $state, BridgeContext $ctx): RemoteResult;

    /** Locate a post previously created for this Base OS content id. */
    public function find(string $contentId, BridgeContext $ctx): ?RemoteResult;

    /** Current snapshot of a post (null = not found). */
    public function fetch(string $remoteId, BridgeContext $ctx): ?RemoteResult;
}
