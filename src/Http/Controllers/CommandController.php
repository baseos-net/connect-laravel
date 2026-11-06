<?php

namespace BaseOS\Connect\Http\Controllers;

use BaseOS\Connect\Commands\CommandDispatcher;
use BaseOS\Connect\Data\BridgeConnection;
use BaseOS\Connect\Http\Middleware\VerifyBaseOSSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandController
{
    public function __invoke(Request $request, CommandDispatcher $dispatcher): JsonResponse
    {
        /** @var BridgeConnection $connection */
        $connection = $request->attributes->get(VerifyBaseOSSignature::ATTRIBUTE);

        $envelope = json_decode($request->getContent(), true);
        if (! is_array($envelope)) {
            return $this->json(['id' => null, 'ok' => false, 'error' => [
                'code' => 'protocol.invalid_envelope',
                'message' => 'Body must be a JSON command envelope.',
                'retryable' => false,
            ]], 400);
        }

        $protocol = (string) $request->header('baseos-protocol', '') ?: null;
        $out = $dispatcher->dispatch($envelope, $connection, $protocol);

        return $this->json($out['body'], $out['status']);
    }

    /** @param  array<string, mixed>  $body */
    protected function json(array $body, int $status): JsonResponse
    {
        return new JsonResponse($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
