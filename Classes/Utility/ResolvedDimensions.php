<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

/**
 * Target dimensions resolved against the configured dimension presets.
 */
final class ResolvedDimensions
{
    /**
     * @param array<string, array<int, string>> $dimensions dimension name => full preset values (fallback chain)
     * @param array<string, string> $targetDimensions dimension name => first preset value
     * @param array<string, string> $presets dimension name => preset identifier
     */
    public function __construct(
        public readonly array $dimensions,
        public readonly array $targetDimensions,
        public readonly array $presets,
    ) {
    }

    /**
     * @return array{dimensions: array<string, array<int, string>>, targetDimensions: array<string, string>}
     */
    public function contextProperties(): array
    {
        return [
            'dimensions' => $this->dimensions,
            'targetDimensions' => $this->targetDimensions,
        ];
    }
}
