<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Everything after the first comma is the given-name segment. This locks that
 * a comma-separated middle name is retained (not dropped as a non-credential
 * third segment) while trailing credentials are still stripped to the suffix,
 * including a given segment that is nothing but credentials.
 */
class CommaSegmentTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function provider(): array
    {
        return [
            // input, first, middle, last, suffix
            'comma middle name retained'      => ['Smith, John, Robert', 'John', 'Robert', 'Smith', ''],
            'comma middle then credential'    => ['Smith, John Robert, MD', 'John', 'Robert', 'Smith', 'MD'],
            'comma first then credentials'    => ['Smith, John, MD, PhD', 'John', '', 'Smith', 'MD PhD'],
            'credential-only given segment'   => ['Smith, MD, PhD', '', '', 'Smith', 'MD PhD'],
            'single credential given'         => ['Smith, MD', '', '', 'Smith', 'MD'],
            'comma suffix Jr'                 => ['Williams, Hank, Jr.', 'Hank', '', 'Williams', 'Jr'],
            'comma initial + suffix'          => ['Miller, Walter M., Jr.', 'Walter', '', 'Miller', 'Jr'],
            'compound surname'                => ['Hidalgo Castillo, Maria', 'Maria', '', 'Hidalgo Castillo', ''],
            'surname suffix Jr'               => ['Doe Jr, John', 'John', '', 'Doe', 'Jr'],
            'surname roman suffix'            => ['Doe III, John', 'John', '', 'Doe', 'III'],
            'credential-only given keeps first segment western' => ['Anthony Von Fange III, PHD', 'Anthony', '', 'von Fange', 'III PhD'],
            // a whole credential-only segment is pulled out to the suffix; the
            // remaining name segments still fold into the given name
            'credential segment before given' => ['Smith, MD, John', 'John', '', 'Smith', 'MD'],
            'all-credential segments western'  => ['John Smith, MD, FACS', 'John', '', 'Smith', 'MD FACS'],
            'unknown credential rides on known' => ['Garcia, Maria, MD, FACS', 'Maria', '', 'Garcia', 'MD FACS'],
            'ambiguous credential segment keeps middle' => ['Smith, John, DO, Robert', 'John', 'Robert', 'Smith', 'DO'],
            // leading credential run inside the given segment
            'leading credential run in given' => ['Smith, MD John', 'John', '', 'Smith', 'MD'],
            'leading title-case name is not a credential' => ['Smith, Do John', 'Do', 'John', 'Smith', ''],
            'mixed credential positions keep source order' => ['Smith, MD, John PhD', 'John', '', 'Smith', 'MD PhD'],
            'candidate cannot cross a name segment' => ['Smith, JOHN, Robert, MD', 'John', 'Robert', 'Smith', 'MD'],
            'unknown candidate cannot cross a name segment' => ['Smith, FACS, John, MD', 'Facs', 'John', 'Smith', 'MD'],
            // pure all-caps given segments are names, not pre-anchor credentials
            'all-caps given before credential stays name' => ['Smith, JOHN, MD', 'John', '', 'Smith', 'MD'],
            'all-caps multi-token given before credential' => ['Smith, JOHN PAUL, MD', 'John', 'Paul', 'Smith', 'MD'],
            // pure unknown-candidate segments only ride after a dictionary anchor
            'pure unknown before dictionary stays name' => ['Smith, FACS, MD', 'Facs', '', 'Smith', 'MD'],
            // mixed-segment trailing candidate peels onto a later dictionary segment
            'mixed segment trailing candidate rides on later dictionary' => ['Smith, John FACS, MD', 'John', '', 'Smith', 'FACS MD'],
            // terminal-token guard: ALL-CAPS lone ambiguous given is a credential
            'terminal all-caps ambiguous is credential' => ['Smith, DO', '', '', 'Smith', 'DO'],
            'terminal title-case ambiguous stays name' => ['Smith, Do', 'Do', '', 'Smith', ''],
        ];
    }

    #[DataProvider('provider')]
    public function testGivenSegmentFolding(string $input, string $first, string $middle, string $last, string $suffix): void
    {
        $name = (new Parser())->parse($input);

        $this->assertSame($first, $name->getFirstname(), "first name for '$input'");
        $this->assertSame($middle, $name->getMiddlename(), "middle name for '$input'");
        $this->assertSame($last, $name->getLastname(), "last name for '$input'");
        $this->assertSame($suffix, $name->getSuffix(), "suffix for '$input'");
    }

    public function testAllCapsTwoLetterGivenIsNotSplitIntoInitials(): void
    {
        $name = (new Parser())->parse('JO ANDERSON');

        $this->assertSame('Jo', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
    }

    public function testMixedCaseCombinedInitialsStillSplit(): void
    {
        $name = (new Parser())->parse('JM Walker');

        $this->assertSame('J', $name->getFirstname());
        $this->assertSame('M', $name->getInitials());
        $this->assertSame('Walker', $name->getLastname());
    }

    /**
     * The uniform-uppercase signal for the InitialMapper split gate comes from
     * the whole input, not the given segment alone. "Smith" proves mixed case,
     * so the JM token splits exactly as it does in the space-form "JM Smith".
     */
    public function testCommaGivenInitialsUseWholeInputCasing(): void
    {
        $name = (new Parser())->parse('Smith, JM');

        $this->assertSame('J', $name->getFirstname());
        $this->assertSame('M', $name->getInitials());
        $this->assertSame('Smith', $name->getLastname());
    }

    public function testUniformUpperCommaInputSuppressesInitialSplit(): void
    {
        $name = (new Parser())->parse('SMITH, JM');

        $this->assertSame('Jm', $name->getFirstname());
        $this->assertSame('', $name->getInitials());
        $this->assertSame('Smith', $name->getLastname());
    }

    /**
     * The override is transient: a plain single-segment parse on the same
     * instance is unaffected by a preceding comma parse.
     */
    public function testOverrideDoesNotLeakToSingleSegmentParse(): void
    {
        $parser = new Parser();
        $parser->parse('SMITH, JM');
        $name = $parser->parse('JM Walker');

        $this->assertSame('J', $name->getFirstname());
        $this->assertSame('M', $name->getInitials());
        $this->assertSame('Walker', $name->getLastname());
    }

    /**
     * a surname segment that is nothing but a salutation must keep it a
     * salutation, not promote it to a last name
     */
    public function testLoneSalutationSurnameSegmentStaysSalutation(): void
    {
        $name = (new Parser())->parse('Dr., John');

        $this->assertSame('Dr.', $name->getSalutation());
        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('', $name->getLastname());
    }

    /**
     * the surname sub-parser now runs the Nickname and Initial mappers, so a
     * parenthetical nickname is extracted and a stray letter becomes an initial
     * rather than raw middle-name text
     */
    public function testSurnameSegmentExtractsNickname(): void
    {
        $name = (new Parser())->parse('John (Bob) Smith, MD');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob', $name->getNickname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    public function testSurnameSegmentSplitsInitials(): void
    {
        $name = (new Parser())->parse('J. R. Smith MD,');

        $this->assertSame('J.', $name->getFirstname());
        $this->assertSame('R.', $name->getInitials());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MD', $name->getSuffix());
    }

    /**
     * a comma inside a matched nickname delimiter span must not be treated as
     * the surname/given separator
     */
    public function testGivenSideNicknameKeepsItsComma(): void
    {
        $name = (new Parser())->parse('Smith, John (Jack, Robert)');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('Jack, Robert', $name->getNickname());
    }

    public function testQuotedNicknameWithCommaDoesNotBisect(): void
    {
        $name = (new Parser())->parse("John 'Bob, Jr' Doe");

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Doe', $name->getLastname());
        $this->assertSame('Bob, Jr', $name->getNickname());
    }

    public function testRevertedNicknameOpenerDropsTrailingComma(): void
    {
        // the span's closer token is consumed as a suffix, so the opener
        // reverts; the shielded comma must not survive in the middle name
        $name = (new Parser())->parse('Smith, John (Jack, III)');

        $this->assertSame('Jack', $name->getMiddlename());
        $this->assertSame('III', $name->getSuffix());
    }

    public function testCommaInsideNicknameDoesNotBisect(): void
    {
        $name = (new Parser())->parse('John (Bob, Jr) Doe');

        $this->assertSame('John', $name->getFirstname());
        $this->assertSame('Bob, Jr', $name->getNickname());
        $this->assertSame('Doe', $name->getLastname());
    }

    /**
     * a real comma still separates the surname from the given segment; a
     * secondary comma inside a given-side parenthetical is not bisected into
     * the surname
     */
    public function testStructuralCommaStillSplitsWithGivenSideParenthetical(): void
    {
        $name = (new Parser())->parse('Smith, John (Jack, III)');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('John', $name->getFirstname());
    }

    /**
     * "MS" is both a salutation (Ms.) and a credential (MS). In the given
     * segment the Suffix mapper runs before the Salutation mapper, so a bare
     * "MS" given segment is classified as a trailing credential, not promoted
     * to a leading salutation.
     */
    public function testGivenSegmentCredentialOutranksSalutationCollision(): void
    {
        $name = (new Parser())->parse('Smith, MS');

        $this->assertSame('Smith', $name->getLastname());
        $this->assertSame('MS', $name->getSuffix());
        $this->assertSame('', $name->getSalutation());
        $this->assertSame('', $name->getFirstname());
    }
}
