<?php

namespace BaseOS\Bridge;

final class Bridge
{
    public const VERSION = '0.3.0';

    public const FLAVOR = 'laravel';

    /** First entry becomes the connection protocol on the Base OS side (PairingService). */
    public const PROTOCOLS = ['2.1', '2.0'];

    public const COMMANDS = ['post.upsert', 'post.set_state', 'post.fetch', 'post.find', 'post.validate', 'site.health', 'site.schema', 'site.verify'];

    /** `content.content_id` prefix of the test draft Base OS creates when a receiver lacks post.validate. */
    public const TEST_CONTENT_PREFIX = 'baseos-test-';
}
