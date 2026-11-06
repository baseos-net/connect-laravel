<?php

namespace BaseOS\Connect\Contracts;

use BaseOS\Connect\Data\BridgeContext;

/**
 * Describes the site structure (post types, taxonomies + terms, fields, authors, locales,
 * tenant, params_spec) — sent with `hello` and in reply to `site.schema`.
 * Shape: docs/cms-bridge/25-MAPPING-AND-CUSTOM-PARAMS.md §4.3.
 */
interface SchemaProvider
{
    /** @return array<string, mixed> */
    public function schema(BridgeContext $ctx): array;
}
