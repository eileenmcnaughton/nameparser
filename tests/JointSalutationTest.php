<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use Iliaal\NameParser\Part\Lastname;
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
     * the honorific splits into one entry per person addressed, so a caller with
     * a single prefix field per contact can take the first and derive the
     * partner from the second
     *
     * @param  list<string>  $expected
     */
    #[DataProvider('salutationsProvider')]
    public function testGetSalutationsSplitsPerPerson(string $input, array $expected): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($expected, $name->getSalutations(), "getSalutations for '$input'");

        // the entries recompose into the rendered honorific
        $this->assertSame($name->getSalutation(), implode(' and ', $expected), "recomposition for '$input'");
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function salutationsProvider(): array
    {
        return [
            'and spelled out'  => ['Mr. and Mrs. Brad Smith', ['Mr.', 'Mrs.']],
            'ampersand'        => ['Mr. & Mrs. Brad Smith', ['Mr.', 'Mrs.']],
            'no periods'       => ['Mr and Mrs Brad Smith', ['Mr.', 'Mrs.']],
            'uppercase input'  => ['MR. AND MRS. BRAD SMITH', ['Mr.', 'Mrs.']],
            'two doctors'      => ['Dr. & Dr. Chen', ['Dr.', 'Dr.']],
            'mixed titles'     => ['Dr. and Mrs. Brad Smith', ['Dr.', 'Mrs.']],
            'comma form'       => ['Mr. and Mrs. Smith, Brad', ['Mr.', 'Mrs.']],
            'surname only'     => ['Mr. and Mrs. Smith', ['Mr.', 'Mrs.']],

            // stacked titles address one person, so they stay in one entry
            'stacked titles'   => ['Rev. Dr John Doe', ['Rev. Dr.']],
            'stacked and joint' => ['Rev. Dr. and Mrs. John Doe', ['Rev. Dr.', 'Mrs.']],

            'single title'     => ['Mr. Brad Smith', ['Mr.']],
            // the leading article is not retained by the mapper
            'article led'      => ['The Rev. Mark Williams', ['Rev.']],
            'no honorific'     => ['Brad Smith', []],
            'unabsorbed and'   => ['Mr. and Brad Smith', ['Mr.']],
        ];
    }

    /**
     * the shape the reported CiviCRM import needs: one prefix for the named
     * contact, the partner assembled from the second title and the surname
     */
    public function testSalutationsDrivePerContactMapping(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $salutations = $name->getSalutations();

        $this->assertTrue($name->isJoint());
        $this->assertSame('Mr.', $salutations[0]);
        $this->assertSame('Mrs. Smith', $salutations[1] . ' ' . $name->getLastname());
    }

    /**
     * the second addressee comes back as a Name carrying her title and the
     * shared surname, so the caller renders "Mrs. Smith" or "Mrs. Brad Smith"
     * to its own taste
     */
    #[DataProvider('partnerProvider')]
    public function testGetPartner(string $input, ?string $salutation, ?string $lastname): void
    {
        $partner = (new Parser())->parse($input)->getPartner();

        if ($salutation === null) {
            $this->assertNull($partner, "partner for '$input'");

            return;
        }

        $this->assertNotNull($partner, "partner for '$input'");
        $this->assertSame($salutation, $partner->getSalutation(), "partner salutation for '$input'");
        $this->assertSame($lastname, $partner->getLastname(), "partner lastname for '$input'");
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function partnerProvider(): array
    {
        return [
            // input, partner salutation, partner lastname (null salutation = no partner)
            'and spelled out'    => ['Mr. and Mrs. Brad Smith', 'Mrs.', 'Smith'],
            'ampersand'          => ['Mr. & Mrs. Brad Smith', 'Mrs.', 'Smith'],
            'no periods'         => ['Mr and Mrs Brad Smith', 'Mrs.', 'Smith'],
            'uppercase input'    => ['MR. AND MRS. BRAD SMITH', 'Mrs.', 'Smith'],
            'two doctors'        => ['Dr. & Dr. Chen', 'Dr.', 'Chen'],
            'mixed titles'       => ['Dr. and Mrs. Brad Smith', 'Mrs.', 'Smith'],
            'comma form'         => ['Mr. and Mrs. Smith, Brad', 'Mrs.', 'Smith'],
            'surname only'       => ['Mr. and Mrs. Smith', 'Mrs.', 'Smith'],

            // the particle belongs to the shared surname
            'prefix surname'     => ['Mr. and Mrs. van der Berg', 'Mrs.', 'van der Berg'],

            // a stacked honorific addresses the first person, so only the
            // second group crosses over
            'stacked and joint'  => ['Rev. Dr. and Mrs. John Doe', 'Mrs.', 'Doe'],

            'single title'       => ['Mr. Brad Smith', null, null],
            'no honorific'       => ['Brad Smith', null, null],
            'unabsorbed and'     => ['Mr. and Brad Smith', null, null],
            'bare two givens'    => ['Brad and Jane Smith', null, null],
        ];
    }

    /**
     * the given name and any credential belong to the person actually named,
     * so neither follows the partner
     */
    public function testPartnerCarriesNoGivenNameOrSuffix(): void
    {
        $partner = (new Parser())->parse('Mr. and Mrs. Brad J. Smith Jr')->getPartner();

        $this->assertNotNull($partner);
        $this->assertSame('', $partner->getFirstname());
        $this->assertSame('', $partner->getInitials());
        $this->assertSame('', $partner->getMiddlename());
        $this->assertSame('', $partner->getSuffix());
        $this->assertSame('Smith', $partner->getFullName());
        $this->assertSame('Mrs. Smith', (string) $partner);
    }

    /**
     * the partner is one person, so she carries a single-entry salutation list
     * and no connector of her own
     */
    public function testPartnerIsNotItselfJoint(): void
    {
        $partner = (new Parser())->parse('Mr. and Mrs. Brad Smith')->getPartner();

        $this->assertNotNull($partner);
        $this->assertFalse($partner->isJoint());
        $this->assertSame(['Mrs.'], $partner->getSalutations());
        $this->assertNull($partner->getPartner());
    }

    /**
     * parts are cloned into the partner, so writing through one Name cannot
     * reach into the other
     */
    public function testPartnerDoesNotShareMutablePartsWithTheSource(): void
    {
        $name = (new Parser())->parse('Mr. and Mrs. Brad Smith');
        $partner = $name->getPartner();

        $this->assertNotNull($partner);

        foreach ($partner->getParts() as $part) {
            if ($part instanceof Lastname) {
                $part->setValue('Jones');
            }
        }

        $this->assertSame('Jones', $partner->getLastname());
        $this->assertSame('Smith', $name->getLastname());
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
