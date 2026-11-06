<?php

namespace BaseOS\Connect\Tests;

use BaseOS\Connect\Bridge;
use PHPUnit\Framework\TestCase;

final class ProtocolContractTest extends TestCase
{
    public function test_package_matches_shared_protocol(): void
    {
        $path = dirname(__DIR__).'/protocol/connect-protocol.json';
        $contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2.1', $contract['version']);
        $this->assertSame('https://baseos.net', $contract['production']['origin']);
        $this->assertSame('/api/v1/connect', $contract['production']['path_prefix']);
        $this->assertSame(['2.1', '2.0'], Bridge::PROTOCOLS);
        $this->assertSame($contract['commands'], Bridge::COMMANDS);
        $this->assertSame('baseos/connect-laravel', $contract['packages']['composer']);
    }
}
