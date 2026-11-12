<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Security;

/**
 * Matches a client IP against a list of exact IPs and CIDR ranges (IPv4 and IPv6). An empty list denies everyone.
 */
final class IpAllowlist
{
    /**
     * @param array<int, string> $allowedEntries exact IP addresses or CIDR ranges, e.g. "10.0.0.1" or "172.16.0.0/12"
     */
    public static function isAllowed(string $ip, array $allowedEntries): bool
    {
        $ipBinary = self::pack($ip);
        if ($ipBinary === null) {
            return false;
        }

        foreach ($allowedEntries as $entry) {
            $entry = (string)$entry;
            if (str_contains($entry, '/')) {
                if (self::matchesCidr($ipBinary, $entry)) {
                    return true;
                }
            } elseif (self::pack($entry) === $ipBinary) {
                return true;
            }
        }

        return false;
    }

    /**
     * Binary form of an IP address, independent of its notation. IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) are
     * reduced to their IPv4 form, so both spellings of the same client match the same allowlist entry.
     */
    private static function pack(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $binary = inet_pton($address);
        if ($binary === false) {
            return null;
        }
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\x00", 10) . "\xff\xff")) {
            return substr($binary, 12);
        }

        return $binary;
    }

    private static function matchesCidr(string $ipBinary, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr, 2);
        if (!ctype_digit($prefixLength)) {
            return false;
        }
        $prefixLength = (int)$prefixLength;

        $subnetBinary = self::pack($subnet);
        if ($subnetBinary === null || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $byteLength = strlen($ipBinary);
        if ($prefixLength > $byteLength * 8) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainder = $prefixLength % 8;

        $mask = str_repeat("\xff", $fullBytes);
        if ($remainder > 0) {
            $mask .= chr(0xff & (0xff << (8 - $remainder)));
        }
        $mask = str_pad($mask, $byteLength, "\x00");

        return ($ipBinary & $mask) === ($subnetBinary & $mask);
    }
}
