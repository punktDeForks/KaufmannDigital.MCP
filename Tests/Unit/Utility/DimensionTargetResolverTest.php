<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Unit\Utility;

use KaufmannDigital\MCP\Tests\Unit\TestDimensionConfiguration;
use KaufmannDigital\MCP\Utility\DimensionResolutionException;
use KaufmannDigital\MCP\Utility\DimensionTargetResolver;
use Neos\ContentRepository\Domain\Service\ConfigurationContentDimensionPresetSource;
use Neos\Flow\Tests\UnitTestCase;

class DimensionTargetResolverTest extends UnitTestCase
{
    private DimensionTargetResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $source = new ConfigurationContentDimensionPresetSource();
        $source->setConfiguration(TestDimensionConfiguration::get());
        $this->resolver = new DimensionTargetResolver();
        $this->inject($this->resolver, 'contentDimensionPresetSource', $source);
    }

    /**
     * @test
     */
    public function resolvesCompleteAllowedCombination(): void
    {
        $resolved = $this->resolver->resolve(['region' => ['south'], 'language' => 'de']);

        self::assertSame(['region' => ['south'], 'language' => ['de']], $resolved->dimensions);
        self::assertSame(['region' => 'south', 'language' => 'de'], $resolved->targetDimensions);
        self::assertSame(['region' => 'south', 'language' => 'de'], $resolved->presets);
        self::assertSame(
            ['dimensions' => ['region' => ['south'], 'language' => ['de']], 'targetDimensions' => ['region' => 'south', 'language' => 'de']],
            $resolved->contextProperties()
        );
    }

    /**
     * @test
     */
    public function acceptsJsonString(): void
    {
        $resolved = $this->resolver->resolve('{"region":["north"],"language":["en"]}');

        self::assertSame(['region' => 'north', 'language' => 'en'], $resolved->targetDimensions);
    }

    /**
     * @test
     */
    public function rejectsMissingDimension(): void
    {
        $this->expectException(DimensionResolutionException::class);
        $this->expectExceptionMessage('Missing dimension(s): region. All configured dimensions must be given: region, language');

        $this->resolver->resolve(['language' => ['de']]);
    }

    /**
     * @test
     */
    public function rejectsUnknownDimension(): void
    {
        $this->expectException(DimensionResolutionException::class);
        $this->expectExceptionMessage('Unknown dimension(s): foo. Configured dimensions: region, language');

        $this->resolver->resolve(['region' => ['north'], 'language' => ['de'], 'foo' => ['x']]);
    }

    /**
     * @test
     */
    public function rejectsUnknownValue(): void
    {
        $this->expectException(DimensionResolutionException::class);
        $this->expectExceptionMessage('No preset for language=xx. Available: de, en, fr');

        $this->resolver->resolve(['region' => ['north'], 'language' => ['xx']]);
    }

    /**
     * @test
     */
    public function rejectsDisallowedCombination(): void
    {
        $this->expectException(DimensionResolutionException::class);
        $this->expectExceptionMessage('Combination region=south, language=fr is not allowed by the dimension constraints');

        $this->resolver->resolve(['region' => ['south'], 'language' => ['fr']]);
    }

    /**
     * @test
     */
    public function rejectsEmptyAndInvalidInput(): void
    {
        foreach ([null, [], '', 'not json'] as $input) {
            try {
                $this->resolver->resolve($input);
                self::fail('Expected a DimensionResolutionException for ' . var_export($input, true));
            } catch (DimensionResolutionException $exception) {
                self::assertStringContainsString('Target dimensions must be an object', $exception->getMessage());
            }
        }
    }

    /**
     * @test
     */
    public function allowedPresetsAlongReturnsLanguagesOfRegion(): void
    {
        $presets = $this->resolver->allowedPresetsAlong('language', ['region' => 'south']);

        self::assertSame(['de'], array_keys($presets));
    }

    /**
     * @test
     */
    public function allowedPresetsAlongRejectsUnknownDimension(): void
    {
        $this->expectException(DimensionResolutionException::class);
        $this->expectExceptionMessage('Unknown dimension(s): foo. Configured dimensions: region, language');

        $this->resolver->allowedPresetsAlong('foo', ['region' => 'south']);
    }
}
