<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Unit\Security;

use GuzzleHttp\Psr7\ServerRequest;
use KaufmannDigital\MCP\Security\ClientIpResolver;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Tests\UnitTestCase;

class ClientIpResolverTest extends UnitTestCase
{
    /**
     * @test
     */
    public function fallsBackToRemoteAddressWhenTheTrustedProxiesMiddlewareDidNotRun(): void
    {
        self::assertSame('192.0.2.10', ClientIpResolver::resolve($this->request('192.0.2.10')));
    }

    /**
     * @test
     */
    public function usesTheClientAddressBehindATrustedProxy(): void
    {
        $request = $this->request('172.18.0.5')
            ->withAttribute(ServerRequestAttributes::TRUSTED_PROXY, true)
            ->withAttribute(ServerRequestAttributes::CLIENT_IP, '203.0.113.7');

        self::assertSame('203.0.113.7', ClientIpResolver::resolve($request));
    }

    /**
     * @test
     */
    public function usesTheDirectlyConnectedAddressWhenNoProxyIsTrusted(): void
    {
        $request = $this->request('192.0.2.10')
            ->withAttribute(ServerRequestAttributes::TRUSTED_PROXY, false)
            ->withAttribute(ServerRequestAttributes::CLIENT_IP, '192.0.2.10');

        self::assertSame('192.0.2.10', ClientIpResolver::resolve($request));
    }

    /**
     * @test
     */
    public function deniesWhenFlowCouldNotDetermineAClientAddress(): void
    {
        $request = $this->request('172.18.0.5')->withAttribute(ServerRequestAttributes::CLIENT_IP, false);

        self::assertSame('', ClientIpResolver::resolve($request));
    }

    /**
     * @test
     */
    public function deniesWhenAProxyForwardedTheRequestButTheRealClientIsUnknown(): void
    {
        // Flow resolves to the proxy itself for private, reserved or unparsable forwarded addresses
        $request = $this->request('172.18.0.5')
            ->withAttribute(ServerRequestAttributes::TRUSTED_PROXY, true)
            ->withAttribute(ServerRequestAttributes::CLIENT_IP, '172.18.0.5');

        self::assertSame('', ClientIpResolver::resolve($request));
    }

    /**
     * @test
     */
    public function deniesWithoutAnyAddress(): void
    {
        self::assertSame('', ClientIpResolver::resolve(new ServerRequest('POST', 'http://localhost/mcp')));
    }

    private function request(string $remoteAddress): ServerRequest
    {
        return new ServerRequest('POST', 'http://localhost/mcp', [], null, '1.1', ['REMOTE_ADDR' => $remoteAddress]);
    }
}
