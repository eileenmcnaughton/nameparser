<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A connector joining two titles belongs to the honorific, not to the given
 * name ("Mr. and Mrs. Brad Smith" keeps Brad as the first name). It needs a
 * title on both sides, so a stray "and" is never absorbed, and Name::isJoint()
 * reports the rows that cover two people.
 */
class JointSalutationTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, expected salutation, expected first, expected last

            // the reported forms
            'and spelled out'     => ['Mr. and Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'ampersand'           => ['Mr. & Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'no periods'          => ['Mr and Mrs Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'surname only'        => ['Mr. and Mrs. Smith', 'Mr. and Mrs.', '', 'Smith'],

            // the connector normalizes, so both spellings land on one value
            'uppercase input'     => ['MR. AND MRS. BRAD SMITH', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'lowercase input'     => ['mr. and mrs. brad smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'title case and'      => ['Mr. And Mrs. Brad Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],

            // any pairing of titles, not just Mr/Mrs
            'two Ms'              => ['Ms. & Ms. Jane Doe', 'Ms. and Ms.', 'Jane', 'Doe'],
            'two Mr'              => ['Mr. and Mr. John Smith', 'Mr. and Mr.', 'John', 'Smith'],
            'two Dr, no first'    => ['Dr. & Dr. Chen', 'Dr. and Dr.', '', 'Chen'],
            'mixed titles'        => ['Dr. and Mrs. Brad Smith', 'Dr. and Mrs.', 'Brad', 'Smith'],
            'Prof pairing'        => ['Prof. and Mrs. Alan Turing', 'Prof. and Mrs.', 'Alan', 'Turing'],

            // composes with the rest of the pipeline
            'with initial'        => ['Mr. and Mrs. Brad J. Smith', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'with suffix'         => ['Mr. and Mrs. Brad Smith Jr', 'Mr. and Mrs.', 'Brad', 'Smith'],
            'with credential'     => ['Mr. & Mrs. John Smith, MD', 'Mr. and Mrs.', 'John', 'Smith'],
            'with prefix surname' => ['Mr. and Mrs. van der Berg', 'Mr. and Mrs.', '', 'van der Berg'],
            'comma form'          => ['Mr. and Mrs. Smith, Brad', 'Mr. and Mrs.', 'Brad', 'Smith'],

            // a connector needs a title on both sides
            'no title after'      => ['Mr. and Brad Smith', 'Mr.', 'And', 'Smith'],
            'no title before'     => ['Brad and Smith', '', 'Brad', 'Smith'],
            'doubled connector'   => ['Mr. and and Mrs. Smith', 'Mr.', 'And', 'Smith'],

            // real names are matched whole, so these never come close
            'surname Anderson'    => ['Anderson, Andrea', '', 'Andrea', 'Anderson'],
            'given Andre'         => ['Andre Smith', '', 'Andre', 'Smith'],
            'surname Andrews'     => ['Amanda Andrews', '', 'Amanda', 'Andrews'],

            // single titles are untouched
            'single title'        => ['Mr. Brad Smith', 'Mr.', 'Brad', 'Smith'],
            'stacked titles'      => ['Rev. Dr John Doe', 'Rev. Dr.', 'John', 'Doe'],
        ];
    }

    #[DataProvider('provider')]
    public function testJointSalutations(string $input, string $salutation, string $first, string $last): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($salutation, $name->getSalutation(), "salutation for '$input'");
        $this->assertSame($first, $name->getFirstname(), "firstname for '$input'");
        $this->assertSame($last, $name->getLastname(), "lastname for '$input'");
    }

    /**
     * the connector must not leak into the name getters it used to pollute
     */
    public function testConnectorLeavesTheNameGettersClean(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');

        $this->assertSame('', $name->getMiddlename());
        $this->assertSame('Brad', $name->getGivenName());
        $this->assertSame('Brad Smith', $name->getFullName());
    }

    #[DataProvider('jointProvider')]
    public function testIsJointReportsTwoPersonRows(string $input, bool $joint): void
    {
        $this->assertSame($joint, (new Parser())->parse($input)->isJoint(), "isJoint for '$input'");
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function jointProvider(): array
    {
        return [
            'and spelled out'   => ['Mr. and Mrs. Brad Smith', true],
            'ampersand'         => ['Mr. & Mrs. Brad Smith', true],
            'two doctors'       => ['Dr. & Dr. Chen', true],
            'comma form'        => ['Mr. and Mrs. Smith, Brad', true],

            'single title'      => ['Mr. Brad Smith', false],
            'no title'          => ['Brad Smith', false],
            'unabsorbed and'    => ['Mr. and Brad Smith', false],
            // no honorific to anchor the connector, so this stays undetected
            'bare two givens'   => ['Brad and Jane Smith', false],
        ];
    }
}
