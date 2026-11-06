<?php

namespace BaseOS\Connect\Http\Controllers;

use BaseOS\Connect\Bridge;
use BaseOS\Connect\BridgeManager;
use Illuminate\Http\JsonResponse;

/**
 * Public, unsigned. Reveals no connection ids or tenants.
 */
class HealthController
{
    public function __invoke(BridgeManager $bridge): JsonResponse
    {
        try {
            $paired = $bridge->connections()->all() !== [];
        } catch (\Throwable) {
            $paired = false;
        }

        return new JsonResponse([
            'ok' => $paired,
            'flavor' => Bridge::FLAVOR,
            'receiver_version' => Bridge::VERSION,
            'protocol_supported' => Bridge::PROTOCOLS,
            'features' => $bridge->features(),
            'time' => gmdate('c'),
        ]);
    }
}
