<?php

namespace BaseOS\Connect\Http\Middleware;

use BaseOS\Connect\Contracts\ConnectionStore;
use BaseOS\Connect\Data\BridgeConnection;
use BaseOS\Connect\Signing\Signer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Standard Webhooks headers with the secret of the connection named in
 * `baseos-connection` (many connections may share one receiver URL).
 */
class VerifyBaseOSSignature
{
    public const ATTRIBUTE = 'baseos.connection';

    public function __construct(
        protected ConnectionStore $connections,
        protected Signer $signer,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $messageId = (string) $request->header('webhook-id', '');
        $connectionId = trim((string) $request->header('baseos-connection', ''));

        if ($connectionId === '') {
            return $this->deny($messageId, 'auth.connection_unknown', 'Missing baseos-connection header.');
        }

        $connection = $this->connections->find($connectionId);
        if (! $connection || $connection->secret() === '') {
            return $this->deny($messageId, 'auth.connection_unknown', 'Unknown connection.');
        }

        $timestamp = (int) $request->header('webhook-timestamp', 0);
        $signature = (string) $request->header('webhook-signature', '');
        if ($messageId === '' || $timestamp === 0 || $signature === '') {
            return $this->deny($messageId, 'auth.signature_invalid', 'Missing webhook-id / webhook-timestamp / webhook-signature.');
        }

        if (! $this->signer->timestampValid($timestamp)) {
            return $this->deny($messageId, 'auth.signature_expired', 'Timestamp outside tolerance — check server clock (NTP).');
        }

        if (! $this->signer->verify($request->getContent(), $this->secrets($connection), $messageId, $timestamp, $signature)) {
            return $this->deny($messageId, 'auth.signature_invalid', 'Invalid signature.');
        }

        $request->attributes->set(self::ATTRIBUTE, $connection);

        return $next($request);
    }

    /** @return list<string> */
    protected function secrets(BridgeConnection $connection): array
    {
        $secrets = [$connection->secret()];
        $prev = $connection->meta('previous_secret');
        $until = $connection->meta('previous_secret_expires_at');
        if (is_string($prev) && $prev !== '' && (! $until || strtotime((string) $until) > time())) {
            $secrets[] = $prev;
        }

        return $secrets;
    }

    protected function deny(string $id, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'id' => $id !== '' ? $id : null,
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message, 'retryable' => false],
        ], 401);
    }
}
