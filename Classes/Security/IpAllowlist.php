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
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($allowedEntries as $entry) {
            $entry = (string)$entry;
            if (str_contains($entry, '/')) {
                if (self::matchesCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($ip === $entry) {
                return true;
            }
        }

        return false;
    }

    private static function matchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr, 2);
        if (!ctype_digit($prefixLength)) {
            return false;
        }
        $prefixLength = (int)$prefixLength;

        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
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
