<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Functional\Tool;

use KaufmannDigital\MCP\Tests\Functional\AbstractMcpFunctionalTestCase;
use KaufmannDigital\MCP\Tool\CreateNodeVariantTool;
use KaufmannDigital\MCP\Tests\Unit\TestDimensionConfiguration;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;

class CreateNodeVariantToolTest extends AbstractMcpFunctionalTestCase
{
    private const NORTH_EN = ['region' => ['north'], 'language' => ['en']];
    private const NORTH_DE = ['region' => ['north'], 'language' => ['de']];

    private CreateNodeVariantTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->objectManager->get(CreateNodeVariantTool::class);
    }

    /**
     * @test
     */
    public function targetDimensionsSchemaDescribesRequiredDimensionsWithoutContradiction(): void
    {
        $description = $this->tool->getDefinition()['inputSchema']['properties']['targetDimensions']['description'];

        self::assertStringContainsString('Required:', $description);
        self::assertStringNotContainsString('Optional', $description);
        self::assertStringNotContainsString('defaults to', $description);
        self::assertStringContainsString('language', $description);
    }

    /**
     * @test
     */
    public function createsVariantWithSameIdentifier(): void
    {
        $fixture = $this->createArticleFixture();
        $articleIdentifier = $fixture['article']->getIdentifier();
        self::assertTrue($this->decode($this->call($fixture['site']->getIdentifier(), self::NORTH_EN))['created']);

        $result = $this->decode($this->call($articleIdentifier, self::NORTH_EN));

        self::assertTrue($result['created']);
        self::assertSame($articleIdentifier, $result['variant']['identifier']);
        self::assertEquals(self::NORTH_EN, $result['dimensions']);
        $this->persistAndReset();
        self::assertNotNull($this->createContext(self::NORTH_EN)->getNodeByIdentifier($articleIdentifier));
        $source = $this->createContext(self::NORTH_DE)->getNodeByIdentifier($articleIdentifier);
        self::assertNotNull($source);
        self::assertEquals(self::NORTH_DE, $source->getDimensions());
    }

    /**
     * @test
     */
    public function copiesContentChildrenIncludingHidden(): void
    {
        $fixture = $this->createArticleFixture();
        $this->call($fixture['site']->getIdentifier(), self::NORTH_EN);

        $this->call($fixture['article']->getIdentifier(), self::NORTH_EN);

        $this->persistAndReset();
        $target = $this->createContext(self::NORTH_EN);
        self::assertNotNull($target->getNodeByIdentifier($fixture['text']->getIdentifier()));
        self::assertNotNull($target->getNodeByIdentifier($fixture['hiddenText']->getIdentifier()));
    }

    /**
     * @test
     */
    public function isIdempotent(): void
    {
        $fixture = $this->createArticleFixture();
        $articleIdentifier = $fixture['article']->getIdentifier();
        $this->call($fixture['site']->getIdentifier(), self::NORTH_EN);
        $this->call($articleIdentifier, self::NORTH_EN);
        $this->persistAndReset();
        $nodeDataRepository = $this->objectManager->get(NodeDataRepository::class);
        $countBefore = count($nodeDataRepository->findByNodeIdentifier($articleIdentifier));

        $result = $this->decode($this->call($articleIdentifier, self::NORTH_EN));

        self::assertFalse($result['created']);
        self::assertSame($articleIdentifier, $result['variant']['identifier']);
        $this->persistAndReset();
        self::assertCount($countBefore, $nodeDataRepository->findByNodeIdentifier($articleIdentifier));
    }

    /**
     * @test
     */
    public function rejectsMissingParentVariant(): void
    {
        $fixture = $this->createArticleFixture();
        $articleIdentifier = $fixture['article']->getIdentifier();

        $text = $this->call($articleIdentifier, self::NORTH_EN);

        self::assertStringStartsWith('Error: Parent node', $text);
        self::assertStringContainsString('Create a variant of the parent first.', $text);
        $this->persistAndReset();
        self::assertNull($this->createContext(self::NORTH_EN)->getNodeByIdentifier($articleIdentifier));
    }

    /**
     * @test
     */
    public function rejectsIncompleteTargetDimensions(): void
    {
        $fixture = $this->createArticleFixture();

        $text = $this->call($fixture['article']->getIdentifier(), ['language' => ['en']]);

        self::assertStringStartsWith('Error: Missing dimension(s): region', $text);
    }

    /**
     * @test
     */
    public function rejectsDisallowedCombination(): void
    {
        $fixture = $this->createArticleFixture();

        $text = $this->call($fixture['article']->getIdentifier(), ['region' => ['south'], 'language' => ['en']]);

        self::assertStringStartsWith('Error: Combination region=south, language=en is not allowed by the dimension constraints', $text);
    }

    /**
     * @test
     */
    public function reportsUnknownNode(): void
    {
        $this->createArticleFixture();

        $text = $this->call('00000000-0000-4000-8000-000000000001', self::NORTH_EN);

        self::assertSame('Node not found: 00000000-0000-4000-8000-000000000001', $text);
    }

    /**
     * @test
     */
    public function createsVariantOfContentNode(): void
    {
        $fixture = $this->createArticleFixture();
        $articleIdentifier = $fixture['article']->getIdentifier();
        $this->call($fixture['site']->getIdentifier(), self::NORTH_EN);
        $this->call($articleIdentifier, self::NORTH_EN);
        $this->persistAndReset();
        // Content added to the original after the document was translated
        $textType = $this->objectManager->get(NodeTypeManager::class)->getNodeType('KaufmannDigital.MCP:Testing.Text');
        $late = $this->sourceContext->getNodeByIdentifier($articleIdentifier)->getNode('main')->createNode('late-text', $textType);
        $late->setProperty('text', 'Late');
        $lateIdentifier = $late->getIdentifier();
        $this->persistAndReset();

        $result = $this->decode($this->call($lateIdentifier, self::NORTH_EN));

        self::assertTrue($result['created']);
        self::assertSame($lateIdentifier, $result['variant']['identifier']);
        $this->persistAndReset();
        $variant = $this->createContext(self::NORTH_EN)->getNodeByIdentifier($lateIdentifier);
        self::assertNotNull($variant);
        self::assertSame('Late', $variant->getProperty('text'));
    }

    /**
     * Pins the documented behaviour along a dimension fallback chain (here: language de_AT falls back to de): the
     * content is taken from the node as it resolves in "sourceDimensions", and content children that already resolve
     * through the fallback chain are not copied.
     *
     * @test
     */
    public function takesTheContentOfTheNodeResolvedInTheSourceDimensions(): void
    {
        $configuration = TestDimensionConfiguration::get();
        $configuration['language']['presets']['de_at'] = ['label' => 'German (AT)', 'values' => ['de_AT', 'de'], 'uriSegment' => 'de-at'];
        $configuration['region']['presets']['north']['constraints']['language']['de_at'] = true;
        $this->applyDimensionConfiguration($configuration);
        $this->persistAndReset();
        $fixture = $this->createArticleFixture();
        $source = ['region' => ['north'], 'language' => ['de_AT', 'de']];
        $target = ['region' => ['north'], 'language' => ['de_AT']];
        foreach ([$fixture['site'], $fixture['article']] as $node) {
            $result = $this->decode($this->tool->execute([
                'nodeIdentifier' => $node->getIdentifier(),
                'targetDimensions' => $target,
                'sourceDimensions' => $source,
                'responseProperties' => ['text'],
            ])[0]['text']);
            self::assertTrue($result['created']);
        }

        $this->persistAndReset();
        // Looked up without the fallback chain: only a node stored for exactly de_AT counts
        $articleVariant = $this->createContext($target)->getNodeByIdentifier($fixture['article']->getIdentifier());
        self::assertNotNull($articleVariant);
        self::assertSame(['de_AT'], $articleVariant->getDimensions()['language']);
        // The content children already resolve through the fallback chain, so they are not copied but stay shared with "de"
        self::assertNull($this->createContext($target)->getNodeByIdentifier($fixture['text']->getIdentifier()));
        $sharedText = $this->createContext($source)->getNodeByIdentifier($fixture['text']->getIdentifier());
        self::assertNotNull($sharedText);
        self::assertSame('Hello', $sharedText->getProperty('text'));
    }

    private function call(string $nodeIdentifier, array $targetDimensions): string
    {
        $result = $this->tool->execute(['nodeIdentifier' => $nodeIdentifier, 'targetDimensions' => $targetDimensions]);
        return $result[0]['text'];
    }

    private function decode(string $text): array
    {
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded, 'Tool returned: ' . $text);
        return $decoded;
    }
}
