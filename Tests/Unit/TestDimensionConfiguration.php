<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tests\Unit;

/**
 * Neutral content dimension setup ("region" x "language") shared by unit and functional tests. It lives in the Tests\Unit namespace because the unit test
 * bootstrap autoloader only resolves test classes from that namespace.
 *
 * Constraints: region "north" allows languages de and en, region "south" only de. Language "fr" is not allowed anywhere.
 */
final class TestDimensionConfiguration
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
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
}
