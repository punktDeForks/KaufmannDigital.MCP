<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Security;

use Neos\Flow\Http\ServerRequestAttributes;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Determines the client address used for the IP allowlist. Returns an empty string (which no allowlist matches) if the
 * client cannot be identified reliably.
 */
final class ClientIpResolver
{
    public static function resolve(ServerRequestInterface $request): string
    {
        $remoteAddress = (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $clientIp = $request->getAttribute(ServerRequestAttributes::CLIENT_IP);

        if ($clientIp === null) {
            // Only missing if Flow's trusted proxies middleware did not run
            return $remoteAddress;
        }
        if (!is_string($clientIp) || $clientIp === '') {
            // Flow could not determine a client address
            return '';
        }
        if ($request->getAttribute(ServerRequestAttributes::TRUSTED_PROXY) === true && $clientIp === $remoteAddress) {
            // The request was forwarded by a trusted proxy, but Flow resolved the client to the proxy itself: that happens
            // for private, reserved or unparsable forwarded addresses. The real client is unknown, and the proxy address
            // must not count as the client's address.
            return '';
        }

        return $clientIp;
    }
}
