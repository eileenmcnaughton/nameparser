<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Part\Lastname;
use Iliaal\NameParser\Part\Salutation;

class InitialMapperTest extends AbstractMapperTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function provider(): array
    {
        return [
            [
                'input' => [
                    'A',
                    'B',
                ],
                'expectation' => [
                    new Initial('A'),
                    'B',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'P.',
                    'Pan',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    new Initial('P.'),
                    'Pan',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Peter',
                    'D.',
                    new Lastname('Pan'),
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Peter',
                    new Initial('D.'),
                    new Lastname('Pan'),
                ],
            ],
            [
                'input' => [
                    'James',
                    'B',
                ],
                'expectation' => [
                    'James',
                    'B',
                ],
            ],
            [
                'input' => [
                    'James',
                    'B',
                ],
                'expectation' => [
                    'James',
                    new Initial('B'),
                ],
                'arguments' => [
                    2,
                    true,
                ],
            ],
            [
                'input' => [
                    'JM',
                    'Walker',
                ],
                'expectation' => [
                    new Initial('J'),
                    new Initial('M'),
                    'Walker',
                ],
            ],
            [
                'input' => [
                    'JM',
                    'Walker',
                ],
                'expectation' => [
                    'JM',
                    'Walker',
                ],
                'arguments' => [
                    1,
                ],
            ],
            // caseless two-character token (Han) must not be split into initials:
            // it is trivially "uppercase" but carries no case signal
            [
                'input' => [
                    "\u{674E}\u{660E}",
                    'Wang',
                ],
                'expectation' => [
                    "\u{674E}\u{660E}",
                    'Wang',
                ],
            ],
            // lone caseless character is a whole name, not an initial
            [
                'input' => [
                    'Wang',
                    "\u{674E}",
                ],
                'expectation' => [
                    'Wang',
                    "\u{674E}",
                ],
                'arguments' => [
                    2,
                    true,
                ],
            ],
            // lone cased character stays an initial
            [
                'input' => [
                    'Durand',
                    "\u{00C9}",
                ],
                'expectation' => [
                    'Durand',
                    new Initial("\u{00C9}"),
                ],
                'arguments' => [
                    2,
                    true,
                ],
            ],
        ];
    }

    protected function getMapper(int $maxCombined = 2, bool $matchLastPart = false): InitialMapper
    {
        return new InitialMapper($maxCombined, $matchLastPart);
    }
}
