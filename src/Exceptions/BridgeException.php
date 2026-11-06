<?php

namespace BaseOS\Connect\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Structured receiver error → `{ok:false, error:{code, message, retryable, details?}}`.
 * Codes: docs/cms-bridge/21-protocol-reference.md §5.
 */
class BridgeException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }

    public static function notFound(string $message = 'Post not found.'): self
    {
        return new self('post.not_found', $message, false, 404);
    }

    /** @param  array<string, mixed>  $details */
    public static function invalid(string $message, array $details = []): self
    {
        return new self('post.invalid', $message, false, 422, $details);
    }

    public static function slugTaken(string $slug): self
    {
        return new self('post.slug_taken', "Slug \"{$slug}\" already exists.", false, 409, ['slug' => $slug]);
    }

    public static function locked(string $message = 'Post is locked.'): self
    {
        return new self('post.locked', $message, true, 409);
    }

    public static function mediaFailed(string $message): self
    {
        return new self('media.fetch_failed', $message, true, 502);
    }

    public static function busy(int $retryAfter = 30): self
    {
        return new self('site.busy', 'Site is busy.', true, 503, ['retry_after' => $retryAfter]);
    }

    public static function unsupported(string $type): self
    {
        return new self('command.unsupported', "Unsupported command: {$type}", false, 404);
    }

    public static function tenantUnknown(string $message = 'No website matches this connection.'): self
    {
        return new self('tenant.unknown', $message, false, 422);
    }

    public function status(): int
    {
        return $this->httpStatus ?? ($this->retryable ? 503 : 422);
    }

    /** @return array{code: string, message: string, retryable: bool, details?: array<string, mixed>} */
    public function toError(): array
    {
        $error = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'retryable' => $this->retryable,
        ];
        if ($this->details !== []) {
            $error['details'] = $this->details;
        }

        return $error;
    }
}
