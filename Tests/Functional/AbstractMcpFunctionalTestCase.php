<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Functional;

use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\ContentDimensionRepository;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ConfigurationContentDimensionPresetSource;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Tests\FunctionalTestCase;

/**
 * Base class for functional tests of the MCP tools.
 *
 * Configures two neutral content dimensions ("region" x "language") on both dimension services, creates the
 * "live" workspace and offers a small article fixture. The original dimension configuration is restored in tearDown().
 */
abstract class AbstractMcpFunctionalTestCase extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    protected Context $sourceContext;

    protected ContextFactoryInterface $contextFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applyDimensionConfiguration($this->testDimensionConfiguration());
        $this->contextFactory = $this->objectManager->get(ContextFactoryInterface::class);
        $workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        $workspaceRepository->add(new Workspace('live'));
        $this->persistAndReset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->inject($this->contextFactory, 'contextInstances', []);
        $configuredDimensions = $this->objectManager->get(ConfigurationManager::class)
            ->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepository.contentDimensions');
        $this->applyDimensionConfiguration($configuredDimensions);
    }

    /**
     * @param array<string, array<int, string>> $dimensions dimension name => dimension values, e.g. ['region' => ['north'], 'language' => ['de']]
     */
    protected function createContext(array $dimensions, string $workspaceName = 'live'): Context
    {
        $targetDimensions = array_map(static fn(array $values) => $values[0], $dimensions);
        return $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensions,
            'targetDimensions' => $targetDimensions,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true,
        ]);
    }

    /**
     * Creates /mcp-test (Document) -> article (Document) -> main (ContentCollection) -> text, hiddenText in region=north, language=de.
     *
     * @return array{site: NodeInterface, article: NodeInterface, text: NodeInterface, hiddenText: NodeInterface}
     */
    protected function createArticleFixture(): array
    {
        $nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $documentType = $nodeTypeManager->getNodeType('KaufmannDigital.MCP:Testing.Document');
        $textType = $nodeTypeManager->getNodeType('KaufmannDigital.MCP:Testing.Text');

        $site = $this->sourceContext->getRootNode()->createNode('mcp-test', $documentType);
        $article = $site->createNode('article', $documentType);
        $main = $article->getNode('main');
        $text = $main->createNode('text', $textType);
        $text->setProperty('text', 'Hello');
        $hiddenText = $main->createNode('hidden-text', $textType);
        $hiddenText->setProperty('text', 'Secret');
        $hiddenText->setHidden(true);

        $identifiers = [
            'site' => $site->getIdentifier(),
            'article' => $article->getIdentifier(),
            'text' => $text->getIdentifier(),
            'hiddenText' => $hiddenText->getIdentifier(),
        ];
        $this->persistAndReset();

        return array_map(fn(string $identifier) => $this->sourceContext->getNodeByIdentifier($identifier), $identifiers);
    }

    /**
     * Persists everything, drops all in-memory node state and rebuilds $sourceContext (live, region=north, language=de).
     */
    protected function persistAndReset(): void
    {
        $this->persistenceManager->persistAll();
        $this->objectManager->get(NodeDataRepository::class)->flushNodeRegistry();
        $this->objectManager->get(NodeFactory::class)->reset();
        $this->contextFactory->reset();
        $this->persistenceManager->clearState();
        $this->sourceContext = $this->createContext(['region' => ['north'], 'language' => ['de']]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function testDimensionConfiguration(): array
    {
        return [
            'region' => [
                'default' => 'north',
                'defaultPreset' => 'north',
                'label' => 'Region',
                'presets' => [
                    'north' => [
                        'label' => 'North',
                        'values' => ['north'],
                        'uriSegment' => 'north',
                        'constraints' => ['language' => ['*' => false, 'de' => true, 'en' => true]],
                    ],
                    'south' => [
                        'label' => 'South',
                        'values' => ['south'],
                        'uriSegment' => 'south',
                        'constraints' => ['language' => ['*' => false, 'de' => true]],
                    ],
                ],
            ],
            'language' => [
                'default' => 'de',
                'defaultPreset' => 'de',
                'label' => 'Language',
                'presets' => [
                    'de' => ['label' => 'German', 'values' => ['de'], 'uriSegment' => 'de'],
                    'en' => ['label' => 'English', 'values' => ['en'], 'uriSegment' => 'en'],
                    'fr' => ['label' => 'French', 'values' => ['fr'], 'uriSegment' => 'fr'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function applyDimensionConfiguration(array $configuration): void
    {
        $this->objectManager->get(ContentDimensionRepository::class)->setDimensionsConfiguration($configuration);
        $this->objectManager->get(ConfigurationContentDimensionPresetSource::class)->setConfiguration($configuration);
    }
}
