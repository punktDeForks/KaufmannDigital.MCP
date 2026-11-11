<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Utility;

use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Resolves target dimensions given by a tool caller strictly: every configured dimension must be given and the
 * combination must be allowed by the dimension constraints.
 *
 * @Flow\Scope("singleton")
 */
class DimensionTargetResolver
{
    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @param array<string, array<int, string>|string>|string|null $dimensions Dimension name => value or list of values
     *        (the first value is the target value) or a JSON-encoded object of the same shape
     * @throws DimensionResolutionException
     */
    public function resolve(array|string|null $dimensions): ResolvedDimensions
    {
        $configuration = $this->contentDimensionPresetSource->getAllPresets();
        $configuredNames = array_keys($configuration);
        if ($configuredNames === []) {
            throw new DimensionResolutionException('No content dimensions are configured on this installation');
        }

        if (is_string($dimensions)) {
            $dimensions = json_decode($dimensions, true);
        }
        if (!is_array($dimensions) || $dimensions === []) {
            throw new DimensionResolutionException(sprintf(
                'Target dimensions must be an object with a value for every configured dimension (%s), e.g. %s',
                implode(', ', $configuredNames),
                json_encode(array_fill_keys($configuredNames, ['…']), JSON_UNESCAPED_UNICODE)
            ));
        }

        $unknown = array_diff(array_map('strval', array_keys($dimensions)), $configuredNames);
        if ($unknown !== []) {
            throw new DimensionResolutionException(sprintf(
                'Unknown dimension(s): %s. Configured dimensions: %s',
                implode(', ', $unknown),
                implode(', ', $configuredNames)
            ));
        }
        $missing = array_diff($configuredNames, array_keys($dimensions));
        if ($missing !== []) {
            throw new DimensionResolutionException(sprintf(
                'Missing dimension(s): %s. All configured dimensions must be given: %s',
                implode(', ', $missing),
                implode(', ', $configuredNames)
            ));
        }

        $resolvedDimensions = [];
        $targetDimensions = [];
        $presetIdentifiers = [];
        foreach ($configuredNames as $dimensionName) {
            $given = $dimensions[$dimensionName];
            $targetValue = is_array($given) ? ($given[0] ?? null) : $given;
            if (!is_string($targetValue) || $targetValue === '') {
                throw new DimensionResolutionException(sprintf('No value given for dimension %s', $dimensionName));
            }

            $presets = $configuration[$dimensionName]['presets'] ?? [];
            $presetIdentifier = null;
            foreach ($presets as $identifier => $preset) {
                if (($preset['values'][0] ?? null) === $targetValue) {
                    $presetIdentifier = (string)$identifier;
                    $resolvedDimensions[$dimensionName] = array_values($preset['values']);
                    break;
                }
            }
            if ($presetIdentifier === null) {
                $available = array_filter(array_map(static fn(array $preset) => $preset['values'][0] ?? null, $presets));
                throw new DimensionResolutionException(sprintf(
                    'No preset for %s=%s. Available: %s',
                    $dimensionName,
                    $targetValue,
                    implode(', ', $available)
                ));
            }
            $targetDimensions[$dimensionName] = $targetValue;
            $presetIdentifiers[$dimensionName] = $presetIdentifier;
        }

        if (!$this->contentDimensionPresetSource->isPresetCombinationAllowedByConstraints($presetIdentifiers)) {
            $combination = [];
            foreach ($targetDimensions as $dimensionName => $targetValue) {
                $combination[] = $dimensionName . '=' . $targetValue;
            }
            throw new DimensionResolutionException(sprintf(
                'Combination %s is not allowed by the dimension constraints',
                implode(', ', $combination)
            ));
        }

        return new ResolvedDimensions($resolvedDimensions, $targetDimensions, $presetIdentifiers);
    }

    /**
     * Presets of one dimension that are allowed in combination with the given presets of the other dimensions,
     * e.g. all languages allowed for a region.
     *
     * @param array<string, string> $fixedPresets dimension name => preset identifier
     * @return array<string, array<string, mixed>> preset identifier => preset configuration
     * @throws DimensionResolutionException
     */
    public function allowedPresetsAlong(string $dimensionName, array $fixedPresets): array
    {
        $allowed = $this->contentDimensionPresetSource->getAllowedDimensionPresetsAccordingToPreselection($dimensionName, $fixedPresets);
        if ($allowed === null) {
            throw new DimensionResolutionException(sprintf(
                'Unknown dimension(s): %s. Configured dimensions: %s',
                $dimensionName,
                implode(', ', array_keys($this->contentDimensionPresetSource->getAllPresets()))
            ));
        }

        return $allowed[$dimensionName]['presets'];
    }
}
