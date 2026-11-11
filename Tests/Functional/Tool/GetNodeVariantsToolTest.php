<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Functional\Tool;

use KaufmannDigital\MCP\Tests\Functional\AbstractMcpFunctionalTestCase;
use KaufmannDigital\MCP\Tool\CreateNodeVariantTool;
use KaufmannDigital\MCP\Tool\GetNodeVariantsTool;

class GetNodeVariantsToolTest extends AbstractMcpFunctionalTestCase
{
    private const NORTH_EN = ['region' => ['north'], 'language' => ['en']];

    private GetNodeVariantsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = $this->objectManager->get(GetNodeVariantsTool::class);
    }

    /**
     * @test
     */
    public function listsAllVariants(): void
    {
        $fixture = $this->createArticleFixture();
        $articleIdentifier = $fixture['article']->getIdentifier();
        $creator = $this->objectManager->get(CreateNodeVariantTool::class);
        $creator->execute(['nodeIdentifier' => $fixture['site']->getIdentifier(), 'targetDimensions' => self::NORTH_EN]);
        $creator->execute(['nodeIdentifier' => $articleIdentifier, 'targetDimensions' => self::NORTH_EN]);
        $this->persistAndReset();

        $result = $this->decode($this->call(['nodeIdentifier' => $articleIdentifier]));

        self::assertSame($articleIdentifier, $result['identifier']);
        $dimensions = array_map(static function (array $variant): array {
            ksort($variant['dimensions']);
            return $variant['dimensions'];
        }, $result['variants']);
        self::assertEqualsCanonicalizing(
            [['language' => ['de'], 'region' => ['north']], ['language' => ['en'], 'region' => ['north']]],
            $dimensions
        );
        self::assertArrayNotHasKey('missing', $result);
    }

    /**
     * @test
     */
    public function missingAlongLanguageListsOnlyAllowedAbsentLanguages(): void
    {
        $fixture = $this->createArticleFixture();

        $result = $this->decode($this->call(['nodeIdentifier' => $fixture['article']->getIdentifier(), 'missingAlong' => 'language']));

        // "fr" is excluded by the constraints of region "north", "de" already exists
        self::assertEquals([self::NORTH_EN], $result['missing']);
    }

    /**
     * @test
     */
    public function missingAlongIsEmptyWhenAllAllowedVariantsExist(): void
    {
        $fixture = $this->createArticleFixture();
        $creator = $this->objectManager->get(CreateNodeVariantTool::class);
        $creator->execute(['nodeIdentifier' => $fixture['site']->getIdentifier(), 'targetDimensions' => self::NORTH_EN]);
        $creator->execute(['nodeIdentifier' => $fixture['article']->getIdentifier(), 'targetDimensions' => self::NORTH_EN]);
        $this->persistAndReset();

        $result = $this->decode($this->call(['nodeIdentifier' => $fixture['article']->getIdentifier(), 'missingAlong' => 'language']));

        self::assertSame([], $result['missing']);
    }

    /**
     * @test
     */
    public function notFoundInGivenDimensions(): void
    {
        $fixture = $this->createArticleFixture();
        $identifier = $fixture['article']->getIdentifier();

        $text = $this->call(['nodeIdentifier' => $identifier, 'dimensions' => self::NORTH_EN]);

        self::assertSame('Node not found: ' . $identifier, $text);
    }

    /**
     * @test
     */
    public function rejectsUnknownMissingAlong(): void
    {
        $fixture = $this->createArticleFixture();

        $text = $this->call(['nodeIdentifier' => $fixture['article']->getIdentifier(), 'missingAlong' => 'foo']);

        self::assertStringStartsWith('Error: Unknown dimension(s): foo', $text);
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
