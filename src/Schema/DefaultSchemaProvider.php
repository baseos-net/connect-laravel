<?php

namespace BaseOS\Connect\Schema;

use BaseOS\Connect\Contracts\SchemaProvider;
use BaseOS\Connect\Data\BridgeContext;

/**
 * Minimal schema from config('baseos-connect.schema') + tenant. Extend it (or implement
 * SchemaProvider) to report real taxonomies/terms so Base OS can map categories.
 */
class DefaultSchemaProvider implements SchemaProvider
{
    /**
     * @param  array<string, mixed>  $config  baseos-connect.schema
     * @param  array<string, string>  $labels  tenant id => label
     */
    public function __construct(
        protected array $config = [],
        protected array $labels = [],
        protected ?string $appVersion = null,
    ) {}

    public function schema(BridgeContext $ctx): array
    {
        return SchemaBuilder::base($this->appVersion)
            ->merge($this->config)
            ->tenant($ctx->tenant, $ctx->tenant !== null ? ($this->labels[$ctx->tenant] ?? $ctx->connection->siteHost()) : null)
            ->toArray();
    }
}
