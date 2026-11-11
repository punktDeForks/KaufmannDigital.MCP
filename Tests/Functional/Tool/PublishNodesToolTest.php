<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Functional\Tool;

use KaufmannDigital\MCP\Tests\Functional\AbstractMcpFunctionalTestCase;
use KaufmannDigital\MCP\Tool\CreateNodeVariantTool;
use KaufmannDigital\MCP\Tool\PublishNodesTool;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;

class PublishNodesToolTest extends AbstractMcpFunctionalTestCase
{
    private const WORKSPACE = 'user-mcp-test';
    private const NORTH_EN = ['region' => ['north'], 'language' => ['en']];
    private const NORTH_DE = ['region' => ['north'], 'language' => ['de']];

    private PublishNodesTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        $workspaceRepository->add(new Workspace(self::WORKSPACE, $workspaceRepository->findByIdentifier('live')));
        // Publishing emits signals whose slots (e.g. cache flushers) expect a current site in the content context
        $site = new Site('mcp-test');
        $site->setName('MCP test site');
        $site->setSiteResourcesPackageKey('KaufmannDigital.MCP');
        $site->setState(Site::STATE_ONLINE);
        $this->objectManager->get(SiteRepository::class)->add($site);
        $this->persistenceManager->persistAll();
        $this->tool = $this->objectManager->get(PublishNodesTool::class);
    }

    /**
     * @test
     */
    public function publishesNodeInNonDefaultDimension(): void
    {
        $fixture = $this->createArticleFixture();
        $this->createEnglishVariantsInUserWorkspace($fixture);
        self::assertNull($this->createContext(self::NORTH_EN)->getNodeByIdentifier($fixture['article']->getIdentifier()));

        $result = $this->decode($this->call([
            'nodeIdentifiers' => [$fixture['site']->getIdentifier(), $fixture['article']->getIdentifier()],
            'workspaceName' => self::WORKSPACE,
            'dimensions' => self::NORTH_EN,
        ]));

        self::assertSame(2, $result['publishedCount']);
        self::assertSame([], $result['errors']);
        $this->persistAndReset();
        self::assertNotNull($this->createContext(self::NORTH_EN)->getNodeByIdentifier($fixture['article']->getIdentifier()));
    }

    /**
     * @test
     */
    public function publishingADocumentAlsoPublishesItsContentChildren(): void
    {
        $fixture = $this->createArticleFixture();
        $this->createEnglishVariantsInUserWorkspace($fixture);

        $result = $this->decode($this->call([
            'nodeIdentifiers' => [$fixture['site']->getIdentifier(), $fixture['article']->getIdentifier()],
            'workspaceName' => self::WORKSPACE,
            'dimensions' => self::NORTH_EN,
        ]));

        self::assertSame([], $result['errors']);
        $this->persistAndReset();
        $live = $this->createContext(self::NORTH_EN);
        self::assertNotNull($live->getNodeByIdentifier($fixture['text']->getIdentifier()));
        self::assertNotNull($live->getNodeByIdentifier($fixture['hiddenText']->getIdentifier()));
    }

    /**
     * @test
     */
    public function withoutDimensionsBehavesAsBefore(): void
    {
        $fixture = $this->createArticleFixture();
        $textIdentifier = $fixture['text']->getIdentifier();
        $this->createContext(self::NORTH_DE, self::WORKSPACE)->getNodeByIdentifier($textIdentifier)->setProperty('text', 'Changed');
        $this->persistAndReset();

        $result = $this->decode($this->call(['nodeIdentifiers' => [$textIdentifier], 'workspaceName' => self::WORKSPACE]));

        self::assertSame(1, $result['publishedCount']);
        $this->persistAndReset();
        self::assertSame('Changed', $this->createContext(self::NORTH_DE)->getNodeByIdentifier($textIdentifier)->getProperty('text'));
    }

    private function createEnglishVariantsInUserWorkspace(array $fixture): void
    {
        $creator = $this->objectManager->get(CreateNodeVariantTool::class);
        foreach ([$fixture['site'], $fixture['article']] as $node) {
            $creator->execute([
                'nodeIdentifier' => $node->getIdentifier(),
                'targetDimensions' => self::NORTH_EN,
                'workspaceName' => self::WORKSPACE,
            ]);
        }
        $this->persistAndReset();
    }

    private function call(array $args): string
    {
        return $this->tool->execute($args)[0]['text'];
    }

    private function decode(string $text): array
    {
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded, 'Tool returned: ' . $text);
        return $decoded;
    }
}
