<?php

namespace BaseOS\Connect\Data;

/**
 * One paired Base OS site connection. In a multi-site app there is one per website.
 */
final class BridgeConnection
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $connectionId,
        #[\SensitiveParameter] private readonly string $secret,
        public readonly ?string $tenant = null,
        public readonly ?string $siteUrl = null,
        public readonly ?string $receiverUrl = null,
        public readonly string $protocol = '2.1',
        public readonly array $meta = [],
    ) {}

    public function secret(): string
    {
        return $this->secret;
    }

    public function siteHost(): ?string
    {
        if (! $this->siteUrl) {
            return null;
        }
        $host = parse_url($this->siteUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(preg_replace('/^www\./i', '', $host) ?? $host) : null;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function withTenant(?string $tenant): self
    {
        return new self($this->connectionId, $this->secret, $tenant, $this->siteUrl, $this->receiverUrl, $this->protocol, $this->meta);
    }

    /** @param  array<string, mixed>  $meta */
    public function withMeta(array $meta): self
    {
        return new self($this->connectionId, $this->secret, $this->tenant, $this->siteUrl, $this->receiverUrl, $this->protocol, $meta + $this->meta);
    }

    /** Safe representation (never includes the secret). */
    public function toArray(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'tenant' => $this->tenant,
            'site_url' => $this->siteUrl,
            'receiver_url' => $this->receiverUrl,
            'protocol' => $this->protocol,
            'meta' => $this->meta,
        ];
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
