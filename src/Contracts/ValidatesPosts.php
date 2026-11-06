<?php

namespace BaseOS\Connect\Contracts;

use BaseOS\Connect\Data\BridgePost;

/**
 * Optional: implement on your ContentRepository to add site-specific checks to `post.validate`
 * (the "Kiểm tra với website" button in Base OS). Must NOT write anything.
 *
 * Your issues are merged with the built-in ones (title/body, schema-required fields, required params…);
 * an issue with the same `code` + `path` as a built-in one replaces it.
 */
interface ValidatesPosts
{
    /**
     * `blocking` defaults to true; `message` is shown to the user as-is (Vietnamese is fine).
     * Throwing BridgeException is reported as one blocking issue with the exception code.
     *
     * @return list<array{code: string, path?: string|null, message: string, blocking?: bool}>
     */
    public function validate(BridgePost $post, ?string $remoteId): array;
}
