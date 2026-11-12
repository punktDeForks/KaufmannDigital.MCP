<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Unit\Security;

use KaufmannDigital\MCP\Security\IpAllowlist;
use Neos\Flow\Tests\UnitTestCase;

class IpAllowlistTest extends UnitTestCase
{
    /**
     * @test
     */
    public function emptyListDeniesAll(): void
    {
        self::assertFalse(IpAllowlist::isAllowed('127.0.0.1', []));
    }

    /**
     * @test
     */
    public function exactIpv4Matches(): void
    {
        self::assertTrue(IpAllowlist::isAllowed('10.0.0.1', ['192.168.0.1', '10.0.0.1']));
        self::assertFalse(IpAllowlist::isAllowed('10.0.0.2', ['10.0.0.1']));
    }

    /**
     * @test
     */
    public function cidrIpv4Matches(): void
    {
        self::assertTrue(IpAllowlist::isAllowed('172.20.0.5', ['172.16.0.0/12']));
        self::assertFalse(IpAllowlist::isAllowed('172.32.0.1', ['172.16.0.0/12']));
    }

    /**
     * @test
     */
    public function cidrIpv6Matches(): void
    {
        self::assertTrue(IpAllowlist::isAllowed('::1', ['::1/128']));
        self::assertTrue(IpAllowlist::isAllowed('fd00::1234', ['fd00::/8']));
        self::assertFalse(IpAllowlist::isAllowed('fe80::1', ['fd00::/8']));
    }

    /**
     * @test
     */
    public function mixedFamiliesDoNotMatch(): void
    {
        self::assertFalse(IpAllowlist::isAllowed('::1', ['127.0.0.0/8']));
        self::assertFalse(IpAllowlist::isAllowed('127.0.0.1', ['::1/128']));
    }

    /**
     * @test
     */
    public function invalidIpIsDenied(): void
    {
        self::assertFalse(IpAllowlist::isAllowed('not-an-ip', ['10.0.0.0/8']));
        self::assertFalse(IpAllowlist::isAllowed('', ['10.0.0.0/8']));
        self::assertFalse(IpAllowlist::isAllowed('', ['']));
    }

    /**
     * @test
     */
    public function invalidPrefixLengthDoesNotMatch(): void
    {
        self::assertFalse(IpAllowlist::isAllowed('10.0.0.1', ['10.0.0.0/99']));
        self::assertFalse(IpAllowlist::isAllowed('10.0.0.1', ['10.0.0.0/-1']));
        self::assertFalse(IpAllowlist::isAllowed('10.0.0.1', ['10.0.0.0/abc']));
    }

    /**
     * @test
     */
    public function exactIpv6IsComparedIndependentOfNotation(): void
    {
        self::assertTrue(IpAllowlist::isAllowed('::1', ['0:0:0:0:0:0:0:1']));
        self::assertTrue(IpAllowlist::isAllowed('2001:db8::1', ['2001:0DB8:0000:0000:0000:0000:0000:0001']));
        self::assertFalse(IpAllowlist::isAllowed('2001:db8::2', ['2001:db8::1']));
    }

    /**
     * @test
     */
    public function ipv4MappedIpv6AddressMatchesIpv4Entries(): void
    {
        self::assertTrue(IpAllowlist::isAllowed('::ffff:10.0.0.1', ['10.0.0.1']));
        self::assertTrue(IpAllowlist::isAllowed('::ffff:10.0.0.1', ['10.0.0.0/8']));
        self::assertTrue(IpAllowlist::isAllowed('10.0.0.1', ['::ffff:10.0.0.1']));
        self::assertFalse(IpAllowlist::isAllowed('::ffff:11.0.0.1', ['10.0.0.0/8']));
    }

    /**
     * @test
     */
    public function unparsableEntriesNeverMatch(): void
    {
        self::assertFalse(IpAllowlist::isAllowed('10.0.0.1', ['10.0.0', 'garbage', 'garbage/8', '']));
    }
}
