<?php

namespace Tests\Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Part\Nickname;
use Iliaal\NameParser\Part\Salutation;

class NicknameMapperTest extends AbstractMapperTestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function provider(): array
    {
        return [
            [
                'input' => [
                    'James',
                    '(Jim)',
                    'T.',
                    'Kirk',
                ],
                'expectation' => [
                    'James',
                    new Nickname('Jim'),
                    'T.',
                    'Kirk',
                ],
            ],
            [
                'input' => [
                    'James',
                    '(\'Jim\')',
                    'T.',
                    'Kirk',
                ],
                'expectation' => [
                    'James',
                    new Nickname('Jim'),
                    'T.',
                    'Kirk',
                ],
            ],
            [
                'input' => [
                    'William',
                    '"Will"',
                    'Shatner',
                ],
                'expectation' => [
                    'William',
                    new Nickname('Will'),
                    'Shatner',
                ],
            ],
            [
                'input' => [
                    'John',
                    '(O\'Brien)',
                    'Smith',
                ],
                'expectation' => [
                    'John',
                    new Nickname('O\'Brien'),
                    'Smith',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Andre',
                    '(The',
                    'Giant)',
                    'Rene',
                    'Roussimoff',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Andre',
                    new Nickname('The'),
                    new Nickname('Giant'),
                    'Rene',
                    'Roussimoff',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Andre',
                    '["The',
                    'Giant"]',
                    'Rene',
                    'Roussimoff',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Andre',
                    new Nickname('The'),
                    new Nickname('Giant'),
                    'Rene',
                    'Roussimoff',
                ],
            ],
            [
                'input' => [
                    new Salutation('Mr'),
                    'Andre',
                    '"The',
                    'Giant"',
                    'Rene',
                    'Roussimoff',
                ],
                'expectation' => [
                    new Salutation('Mr'),
                    'Andre',
                    new Nickname('The'),
                    new Nickname('Giant'),
                    'Rene',
                    'Roussimoff',
                ],
            ],
            // a leading quote with no closing quote later is an elided particle,
            // not a nickname opener: leave the token verbatim
            [
                'input' => [
                    'Gerard',
                    '\'t',
                    'Hooft',
                ],
                'expectation' => [
                    'Gerard',
                    '\'t',
                    'Hooft',
                ],
            ],
            [
                'input' => [
                    'John',
                    '\'Bob\'',
                    'Smith',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    'Smith',
                ],
            ],
            // lone delimiter tokens clean to empty and must not emit empty Nicknames
            [
                'input' => [
                    'John',
                    '(',
                    'Bob',
                    ')',
                    'Smith',
                ],
                'expectation' => [
                    'John',
                    new Nickname('Bob'),
                    'Smith',
                ],
            ],
        ];
    }

    protected function getMapper(): NicknameMapper
    {
        return new NicknameMapper([
            '[' => ']',
            '{' => '}',
            '(' => ')',
            '<' => '>',
            '"' => '"',
            '\'' => '\'',
        ]);
    }
}
