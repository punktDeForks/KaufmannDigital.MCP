<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use KaufmannDigital\MCP\Utility\NodeSerializer;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\PublishingService;

/**
 * @Flow\Scope("singleton")
 */
class PublishNodesTool implements ToolInterface
{
    use DimensionOverrideTrait;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var PublishingService
     */
    protected $publishingService;

    /**
     * @Flow\Inject
     * @var NodeSerializer
     */
    protected $nodeSerializer;

    public function getDefinition(): array
    {
        return [
            'name' => 'publish_nodes',
            'description' => 'Publish nodes from a user workspace to its base workspace (usually live). Nodes are resolved in the default dimensions unless "dimensions" is given — pass it to publish variants in other dimensions. Publishing a document also publishes its content children; sub-documents must be listed explicitly.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'nodeIdentifiers' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'UUIDs of the nodes to publish',
                    ],
                    'workspaceName' => ['type' => 'string', 'description' => 'Source workspace containing the unpublished nodes'],
                    'dimensions' => $this->dimensionsInputSchema(),
                    'responseProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Fields to include per node in the response. Default: only "identifier". Add node property names (e.g. "title") and/or meta-fields: "nodeType", "label", "name", "path", "parentIdentifier", "ancestors". Meta-fields are returned top-level, node properties in a nested "properties" object (prefix "properties." to request a property named like a meta-field, e.g. "properties.identifier"); unknown names are listed in "unknownProperties".',
                    ],
                ],
                'required' => ['nodeIdentifiers', 'workspaceName'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        if (empty($args['nodeIdentifiers']) || empty($args['workspaceName'])) {
            return [['type' => 'text', 'text' => 'Error: nodeIdentifiers and workspaceName are required']];
        }

        $context = $this->contentContextFactory->create(array_merge([
            'workspaceName' => $args['workspaceName'],
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ], $this->dimensionContextProperties($args['dimensions'] ?? null)));

        $published = [];
        $errors = [];

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);
        $securityContext->withoutAuthorizationChecks(function () use ($context, $args, &$published, &$errors) {
            foreach ($args['nodeIdentifiers'] as $nodeIdentifier) {
                $node = $context->getNodeByIdentifier($nodeIdentifier);
                if ($node === null) {
                    $errors[] = 'Node not found: ' . $nodeIdentifier;
                    continue;
                }

                $this->publishingService->publishNode($node);
                $published[] = $this->nodeSerializer->serializeNodeFiltered($node, $args['responseProperties'] ?? null);
            }
        });

        return [['type' => 'text', 'text' => json_encode(
            ['publishedCount' => count($published), 'published' => $published, 'errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )]];
    }
}
