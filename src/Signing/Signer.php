<?php

namespace BaseOS\Connect\Signing;

/**
 * Standard Webhooks signing, byte-compatible with Base OS `StandardWebhookSigner`:
 *
 *   webhook-signature = "v1," . base64(hmac_sha256(key = secret, "{id}.{timestamp}.{body}"))
 *
 * The HMAC key is the FULL secret string as issued by Base OS (including the `whsec_`
 * prefix) — it is NOT base64-decoded, unlike the upstream Standard Webhooks spec.
 */
final class Signer
{
    public function __construct(private int $tolerance = 300) {}

    /**
     * @return array{id: string, timestamp: int, signature: string}
     */
    public function sign(string $payload, string $secret, ?string $id = null, ?int $timestamp = null): array
    {
        $id ??= 'req_'.bin2hex(random_bytes(12));
        $timestamp ??= time();

        return [
            'id' => $id,
            'timestamp' => $timestamp,
            'signature' => 'v1,'.self::compute($payload, $secret, $id, $timestamp),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $payload, string $secret, string $connectionId, string $protocol = '2.1', ?string $id = null): array
    {
        $signed = $this->sign($payload, $secret, $id);

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'webhook-id' => $signed['id'],
            'webhook-timestamp' => (string) $signed['timestamp'],
            'webhook-signature' => $signed['signature'],
            'baseos-connection' => $connectionId,
            'baseos-protocol' => $protocol,
        ];
    }

    /**
     * @param  list<string>|string  $secrets  current secret (+ previous during rotation)
     */
    public function verify(string $payload, array|string $secrets, string $id, int $timestamp, string $signatureHeader, ?int $now = null): bool
    {
        if ($id === '' || $timestamp <= 0 || trim($signatureHeader) === '') {
            return false;
        }
        if (! $this->timestampValid($timestamp, $now)) {
            return false;
        }

        $candidates = [];
        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $part) {
            if (str_starts_with($part, 'v1,')) {
                $candidates[] = substr($part, 3);
            }
        }
        if ($candidates === []) {
            return false;
        }

        foreach ((array) $secrets as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }
            $expected = self::compute($payload, $secret, $id, $timestamp);
            foreach ($candidates as $got) {
                if (hash_equals($expected, $got)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function timestampValid(int $timestamp, ?int $now = null): bool
    {
        return abs(($now ?? time()) - $timestamp) <= $this->tolerance;
    }

    public static function compute(string $payload, string $secret, string $id, int $timestamp): string
    {
        return base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$payload, $secret, true));
    }
}
