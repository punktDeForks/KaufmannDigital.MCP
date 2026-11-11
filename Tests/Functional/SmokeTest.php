<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Functional;

class SmokeTest extends AbstractMcpFunctionalTestCase
{
    /**
     * @test
     */
    public function fixtureCanBeCreatedInTestDimensions(): void
    {
        $fixture = $this->createArticleFixture();
        $identifier = $fixture['article']->getIdentifier();

        $northDe = $this->createContext(['region' => ['north'], 'language' => ['de']]);
        $northEn = $this->createContext(['region' => ['north'], 'language' => ['en']]);

        self::assertNotNull($northDe->getNodeByIdentifier($identifier));
        self::assertNull($northEn->getNodeByIdentifier($identifier));
    }
}
