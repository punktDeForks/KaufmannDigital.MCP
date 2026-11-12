<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\DimensionResolutionException;
use KaufmannDigital\MCP\Utility\DimensionTargetResolver;
use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

/**
 * @Flow\Scope("singleton")
 */
class CreateNodeVariantTool implements ToolInterface
{
    use DimensionOverrideTrait;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodeSerializer
     */
    protected $nodeSerializer;

    /**
     * @Flow\Inject
     * @var DimensionTargetResolver
     */
    protected $dimensionTargetResolver;

    public function getDefinition(): array
    {
        return [
            'name' => 'create_node_variant',
            'description' => 'Create a variant of an existing node in other content dimensions (e.g. a translation). '
                . 'The variant keeps the identity (identifier) of the original, so dimension and language menus link both together — '
                . 'unlike create_node or copy_node, which create a new node with a new identifier. '
                . 'Content child nodes (including hidden ones) are copied along, sub-documents are not: create variants of the parent documents first. '
                . 'ALL configured dimensions must be given in "targetDimensions" and the combination must be allowed by the dimension constraints. '
                . 'The call is idempotent: if the variant already exists, "created" is false. '
                . 'The variant starts with the properties of the original; translate them afterwards with update_property or batch_update_property '
                . '(including uriPathSegment for documents) using the target dimensions.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifier' => ['type' => 'string', 'description' => 'UUID of the node to create a variant of'],
                    'targetDimensions' => [
                        'type' => 'object',
                        'description' => 'Dimensions of the variant to create. ' . $this->requiredDimensionsDescription(),
                    ],
                    'sourceDimensions' => $this->dimensionsInputSchema(),
                    'workspaceName' => ['type' => 'string', 'description' => 'Workspace to create the variant in (default: live)'],
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields of the variant to include in the response. Default: only "identifier". Add node property names and/or meta-fields: "nodeType", "label", "name", "path".',
                    ],
                ],
                'required' => ['nodeIdentifier', 'targetDimensions'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $nodeIdentifier = $args['nodeIdentifier'] ?? null;
        if (empty($nodeIdentifier)) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifier is required']];
        }

        $contextProperties = [
            'workspaceName' => $args['workspaceName'] ?? 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ];

        $sourceContext = $this->contentContextFactory->create(array_merge(
            $contextProperties,
            $this->dimensionContextProperties($args['sourceDimensions'] ?? null)
        ));
        $node = $sourceContext->getNodeByIdentifier($nodeIdentifier);
        if ($node === null) {
            return [['type' => 'text', 'text' => 'Node not found: ' . $nodeIdentifier]];
        }

        try {
            $resolved = $this->dimensionTargetResolver->resolve($args['targetDimensions'] ?? null);
        } catch (DimensionResolutionException $exception) {
            return [['type' => 'text', 'text' => 'Error: ' . $exception->getMessage()]];
        }
        $targetContext = $this->contentContextFactory->create(array_merge($contextProperties, $resolved->contextProperties()));

        // A node resolved through a dimension fallback is not a variant in the target dimensions
        $existingVariant = $targetContext->getNodeByIdentifier($nodeIdentifier);
        if ($existingVariant !== null && $existingVariant->dimensionsAreMatchingTargetDimensionValues()) {
            return $this->response($existingVariant, false, $args['responseProperties'] ?? null);
        }

        // The root node and the "/sites" node exist without content dimensions and never get variants of their own
        $parent = $node->getParent();
        if ($parent !== null && $parent->getPath() !== '/' && $parent->getDimensions() !== []) {
            $parentVariant = $targetContext->getNodeByIdentifier($parent->getIdentifier());
            if ($parentVariant === null || !$parentVariant->dimensionsAreMatchingTargetDimensionValues()) {
                return [['type' => 'text', 'text' => sprintf(
                    'Error: Parent node %s has no variant in the target dimensions. Create a variant of the parent first.',
                    $parent->getIdentifier()
                )]];
            }
        }

        $variant = null;
        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($targetContext, $node, &$variant) {
            $variant = $targetContext->adoptNode($node, true);
        });
        $this->persistenceManager->persistAll();

        return $this->response($variant, true, $args['responseProperties'] ?? null);
    }

    private function response(\Neos\ContentRepository\Domain\Model\NodeInterface $variant, bool $created, array|string|null $responseProperties): array
    {
        return [['type' => 'text', 'text' => json_encode([
            'variant' => $this->nodeSerializer->serializeNodeFiltered($variant, $responseProperties),
            'created' => $created,
            'dimensions' => $variant->getDimensions(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
