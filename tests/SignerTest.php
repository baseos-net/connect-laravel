<?php

namespace BaseOS\Connect\Tests;

use BaseOS\Connect\Signing\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    private const SECRET = 'whsec_abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    public function test_matches_base_os_standard_webhook_signer_byte_for_byte(): void
    {
        $body = '{"id":"cmd_1","type":"site.health","data":{}}';
        $id = 'cmd_1';
        $ts = 1792400000;

        // Same expression as App\Services\Webhook\StandardWebhookSigner::sign (raw secret as key).
        $expected = 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$body, self::SECRET, true));

        $signed = (new Signer)->sign($body, self::SECRET, $id, $ts);

        $this->assertSame($expected, $signed['signature']);
        $this->assertSame($id, $signed['id']);
        $this->assertSame($ts, $signed['timestamp']);
    }

    public function test_verifies_valid_signature(): void
    {
        $signer = new Signer(300);
        $body = '{"hello":"world"}';
        $s = $signer->sign($body, self::SECRET, 'msg_1', time());

        $this->assertTrue($signer->verify($body, self::SECRET, 'msg_1', $s['timestamp'], $s['signature']));
    }

    public function test_rejects_tampered_body_wrong_id_and_wrong_secret(): void
    {
        $signer = new Signer;
        $s = $signer->sign('{"a":1}', self::SECRET, 'msg_1', time());

        $this->assertFalse($signer->verify('{"a":2}', self::SECRET, 'msg_1', $s['timestamp'], $s['signature']));
        $this->assertFalse($signer->verify('{"a":1}', self::SECRET, 'msg_2', $s['timestamp'], $s['signature']));
        $this->assertFalse($signer->verify('{"a":1}', 'whsec_other', 'msg_1', $s['timestamp'], $s['signature']));
    }

    public function test_rejects_timestamp_outside_tolerance(): void
    {
        $signer = new Signer(300);
        $old = time() - 301;
        $s = $signer->sign('{}', self::SECRET, 'msg_1', $old);

        $this->assertFalse($signer->verify('{}', self::SECRET, 'msg_1', $old, $s['signature']));
        $this->assertTrue($signer->verify('{}', self::SECRET, 'msg_1', $old, $s['signature'], now: $old + 10));
    }

    public function test_accepts_any_of_multiple_signatures_and_previous_secret(): void
    {
        $signer = new Signer;
        $ts = time();
        $old = $signer->sign('{}', 'whsec_old', 'msg_1', $ts)['signature'];
        $new = $signer->sign('{}', self::SECRET, 'msg_1', $ts)['signature'];

        $this->assertTrue($signer->verify('{}', self::SECRET, 'msg_1', $ts, 'v1,garbage '.$new));
        $this->assertTrue($signer->verify('{}', [self::SECRET, 'whsec_old'], 'msg_1', $ts, $old));
        $this->assertFalse($signer->verify('{}', self::SECRET, 'msg_1', $ts, $old));
    }

    public function test_rejects_missing_or_non_v1_signature(): void
    {
        $signer = new Signer;
        $ts = time();
        $raw = Signer::compute('{}', self::SECRET, 'msg_1', $ts);

        $this->assertFalse($signer->verify('{}', self::SECRET, 'msg_1', $ts, ''));
        $this->assertFalse($signer->verify('{}', self::SECRET, 'msg_1', $ts, 'v2,'.$raw));
        $this->assertFalse($signer->verify('{}', self::SECRET, '', $ts, 'v1,'.$raw));
    }

    public function test_headers_include_bridge_headers(): void
    {
        $h = (new Signer)->headers('{}', self::SECRET, 'sc_123', '2.1', 'req_1');

        $this->assertSame('sc_123', $h['baseos-connection']);
        $this->assertSame('2.1', $h['baseos-protocol']);
        $this->assertSame('req_1', $h['webhook-id']);
        $this->assertStringStartsWith('v1,', $h['webhook-signature']);
    }
}
