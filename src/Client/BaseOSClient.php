<?php

namespace BaseOS\Bridge\Client;

use BaseOS\Bridge\Bridge;
use BaseOS\Bridge\Data\BridgeConnection;
use BaseOS\Bridge\Exceptions\BridgeException;
use BaseOS\Bridge\Signing\Signer;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Receiver → Base OS calls: /api/v1/connect/pair (unsigned), /hello and /events (signed).
 */
class BaseOSClient
{
    public function __construct(
        protected Http $http,
        protected Signer $signer,
        protected string $baseUrl = 'https://baseos.net',
        protected int $timeout = 20,
    ) {}

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Consume a one-time pair code.
     *
     * @return array{connection_id: string, secret: string, protocol: string, poll_url?: string, events_url?: string, status: string}
     */
    public function pair(string $code, string $siteUrl, string $receiverUrl): array
    {
        $response = $this->http->timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->post($this->url('pair'), [
                'pair_code' => strtoupper(str_replace(' ', '', trim($code))),
                'site_url' => rtrim($siteUrl, '/'),
                'receiver_url' => rtrim($receiverUrl, '/'),
                'flavor' => Bridge::FLAVOR,
                'receiver_version' => Bridge::VERSION,
                'protocol_supported' => Bridge::PROTOCOLS,
            ]);

        $body = $this->decode($response);
        if (empty($body['connection_id']) || empty($body['secret'])) {
            throw new BridgeException('connect.pair_invalid', 'Base OS did not return connection_id/secret.', false, $response->status());
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function hello(BridgeConnection $connection, array $payload): array
    {
        return $this->decode($this->signed($connection, 'hello', $payload));
    }

    /**
     * @param  list<array<string, mixed>>  $events  ≤ 50 per batch
     * @return array{accepted?: int, duplicates?: int}
     */
    public function events(BridgeConnection $connection, array $events): array
    {
        $out = ['accepted' => 0, 'duplicates' => 0];
        foreach (array_chunk($events, 50) as $batch) {
            $r = $this->decode($this->signed($connection, 'events', ['events' => $batch], 'ev_'.bin2hex(random_bytes(12))));
            $out['accepted'] += (int) ($r['accepted'] ?? 0);
            $out['duplicates'] += (int) ($r['duplicates'] ?? 0);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $payload */
    public function signed(BridgeConnection $connection, string $path, array $payload, ?string $messageId = null): Response
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = $this->signer->headers($json, $connection->secret(), $connection->connectionId, $connection->protocol, $messageId);

        return $this->http->timeout($this->timeout)
            ->withHeaders($headers)
            ->withBody($json, 'application/json')
            ->post($this->url($path));
    }

    protected function url(string $path): string
    {
        return $this->baseUrl().'/api/v1/connect/'.ltrim($path, '/');
    }

    /**
     * Base OS errors: `{error: {code, message, status, retryable}}` (no `ok` key) or Laravel 422 `{message, errors}`.
     *
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return $body;
        }

        $err = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = (string) ($err['message'] ?? $body['message'] ?? mb_substr($response->body(), 0, 300));
        if (isset($body['errors']) && is_array($body['errors'])) {
            $message .= ' '.json_encode($body['errors'], JSON_UNESCAPED_UNICODE);
        }

        throw new BridgeException(
            (string) ($err['code'] ?? 'http.'.$response->status()),
            trim($message) ?: 'HTTP '.$response->status(),
            (bool) ($err['retryable'] ?? $response->serverError()),
            $response->status(),
        );
    }
}
