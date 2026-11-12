<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\DimensionResolutionException;
use KaufmannDigital\MCP\Utility\DimensionTargetResolver;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class GetNodeVariantsTool implements ToolInterface
{
    use DimensionOverrideTrait;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var DimensionTargetResolver
     */
    protected $dimensionTargetResolver;

    public function getDefinition(): array
    {
        return [
            'name' => 'get_node_variants',
            'description' => 'List the dimension combinations a node exists in (its variants, e.g. translations). '
                . 'With "missingAlong" (a dimension name, e.g. "language") the response also lists the variants that are still missing along that dimension: '
                . 'all presets of that dimension that are allowed by the dimension constraints in combination with the other dimensions of the node. '
                . 'Each "missing" entry can be used directly as "targetDimensions" of create_node_variant. '
                . 'If the dimensions of the located node are not a valid combination (any more), "missing" is replaced by a "missingError" message and the variants are still listed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the node'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to look in (default: live)'],
                    'missingAlong' => ['type' => 'string', 'description' => 'Dimension name to list missing variants for, e.g. "language"'],
                ],
                'required' => ['nodeIdentifier'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $nodeIdentifier = $args['nodeIdentifier'] ?? null;
        if (empty($nodeIdentifier)) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifier is required']];
        }

        $context = $this->contentContextFactory->create(array_merge([
            'workspaceName' => $args['workspaceName'] ?? 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ], $this->dimensionContextProperties($args['dimensions'] ?? null)));

        $node = $context->getNodeByIdentifier($nodeIdentifier);
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Node not found: ' . $nodeIdentifier]];
        }

        // The same variant can be stored in several workspaces (base workspace and user workspace): list each combination once.
        // A variant that was removed in the requested workspace does not exist there any more, even if the base workspace still has it.
        $workspaceName = $args['workspaceName'] ?? 'live';
        $variants = [];
        foreach (array_merge([$node], array_values($node->getOtherNodeVariants())) as $variant) {
            $dimensions = $variant->getDimensions();
            ksort($dimensions);
            $key = json_encode($dimensions);
            if (!isset($variants[$key]) && $this->existsInWorkspace($nodeIdentifier, $dimensions, $workspaceName)) {
                $variants[$key] = ['dimensions' => $dimensions];
            }
        }
        ksort($variants);
        $result = ['identifier' => $nodeIdentifier, 'variants' => array_values($variants)];

        $missingAlong = $args['missingAlong'] ?? null;
        if ($missingAlong !== null && $missingAlong !== '') {
            try {
                $this->dimensionTargetResolver->assertDimensionConfigured((string)$missingAlong);
            } catch (DimensionResolutionException $exception) {
                return [['type' => 'text', 'text' => 'Error: ' . $exception->getMessage()]];
            }
            try {
                $result['missing'] = $this->missingVariants($node->getDimensions(), (string)$missingAlong, array_keys($variants));
            } catch (DimensionResolutionException $exception) {
                // The node itself is stored in a combination that is not valid (any more): keep the variants, explain the gap
                $result['missingError'] = $exception->getMessage();
            }
        }

        return [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }

    /**
     * Looks the node up with exactly these dimensions: removed nodes are hidden by the context, nodes that only resolve through a dimension fallback do not count.
     *
     * @param array<string, array<int, string>> $dimensions
     */
    private function existsInWorkspace(string $nodeIdentifier, array $dimensions, string $workspaceName): bool
    {
        $context = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensions,
            'targetDimensions' => array_map(static fn(array $values) => $values[0] ?? null, $dimensions),
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);
        $existing = $context->getNodeByIdentifier($nodeIdentifier);

        return $existing !== null && $existing->dimensionsAreMatchingTargetDimensionValues();
    }

    /**
     * @param array<string, array<int, string>> $nodeDimensions dimensions of the located node
     * @param array<int, string> $existingVariantKeys json encoded, key-sorted dimensions of all existing variants
     * @return array<int, array<string, array<int, string>>>
     * @throws DimensionResolutionException
     */
    private function missingVariants(array $nodeDimensions, string $dimensionName, array $existingVariantKeys): array
    {
        $targetValues = array_map(static fn(array $values) => $values[0], $nodeDimensions);
        $fixedPresets = $this->dimensionTargetResolver->resolve($targetValues)->presets;
        unset($fixedPresets[$dimensionName]);
        $allowedPresets = $this->dimensionTargetResolver->allowedPresetsAlong($dimensionName, $fixedPresets);

        $missing = [];
        foreach ($allowedPresets as $preset) {
            $candidate = array_map(static fn(string $value) => [$value], $targetValues);
            $candidate[$dimensionName] = [$preset['values'][0]];
            $sortedCandidate = $candidate;
            ksort($sortedCandidate);
            if (!$this->variantExists($sortedCandidate, $existingVariantKeys)) {
                $missing[] = $candidate;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, array<int, string>> $candidate key-sorted target dimensions, one value per dimension
     * @param array<int, string> $existingVariantKeys
     */
    private function variantExists(array $candidate, array $existingVariantKeys): bool
    {
        foreach ($existingVariantKeys as $key) {
            $existing = json_decode($key, true);
            if (array_map(static fn(array $values) => [$values[0]], $existing) === $candidate) {
                return true;
            }
        }
        return false;
    }
}
