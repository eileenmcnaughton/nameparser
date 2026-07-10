<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Language\English;
use Iliaal\NameParser\Mapper\FirstnameMapper;
use Iliaal\NameParser\Mapper\InitialMapper;
use Iliaal\NameParser\Mapper\LastnameMapper;
use Iliaal\NameParser\Mapper\MiddlenameMapper;
use Iliaal\NameParser\Mapper\NicknameMapper;
use Iliaal\NameParser\Mapper\SalutationMapper;
use Iliaal\NameParser\Mapper\SuffixMapper;
use Iliaal\NameParser\Part\GivenNamePart;
use Iliaal\NameParser\Part\Suffix;

class Parser
{
    /**
     * asymmetric nickname delimiter pairs used to shield commas inside a
     * bracketed nickname from the comma split; mirrors NicknameMapper's
     * defaults minus the symmetric quotes, which cannot be paired reliably
     */
    private const array DEFAULT_NICKNAME_DELIMITERS = [
        '[' => ']',
        '{' => '}',
        '(' => ')',
        '<' => '>',
    ];

    private const string COMMA_PLACEHOLDER = "\x00";

    protected string $whitespace = " \r\n\t";

    /**
     * @var array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    protected array $mappers = [];

    protected bool $customMappers = false;

    /**
     * @var array<int, LanguageInterface>
     */
    protected array $languages = [];

    /**
     * @var array<string, string>
     */
    protected array $nicknameDelimiters = [];

    protected int $maxSalutationIndex = 0;

    protected int $maxCombinedInitials = 2;

    /**
     * when true, a space-separated name with no comma is read surname-first
     * (CJK order, "Mao Zedong"): the first token is the surname, the rest is the
     * given-name segment. The caller asserts the order for the batch, the same
     * contract as the comma form; auto-detection is not possible from romanized
     * text where "Lee Harvey" and "Mao Zedong" are structurally identical.
     */
    protected bool $surnameFirst = false;

    /**
     * memoized merge of all languages' lastname prefixes
     *
     * @var array<int|string, string>|null
     */
    private ?array $prefixes = null;

    /**
     * memoized merge of all languages' suffixes
     *
     * @var array<int|string, string>|null
     */
    private ?array $suffixes = null;

    /**
     * memoized merge of all languages' salutations
     *
     * @var array<int|string, string>|null
     */
    private ?array $salutations = null;

    /**
     * memoized whitespace-collapse pattern, rebuilt only when the whitespace
     * character set changes; avoids recompiling the regex on every parse()
     */
    private ?string $normalizePattern = null;

    private ?string $normalizePatternKey = null;

    /**
     * memoized sub-parsers for the comma-separated segments; built once per
     * instance so a batch of comma names does not re-merge the dictionaries
     * on every row
     */
    private ?Parser $firstSegmentParser = null;

    private ?Parser $surnameSegmentParser = null;

    private ?Parser $secondSegmentParser = null;

    /**
     * the InitialMapper instance inside the second-segment sub-parser, held so
     * parseSplitName() can feed it the whole-input uniform-uppercase signal
     */
    private ?InitialMapper $secondSegmentInitialMapper = null;

    /**
     * @param  array<int, LanguageInterface>  $languages
     */
    public function __construct(array $languages = [])
    {
        if (empty($languages)) {
            $languages = [new English()];
        }

        $this->languages = $languages;
    }

    /**
     * split full names into the following parts:
     * - prefix / salutation  (Mr., Mrs., etc)
     * - given name / first name
     * - middle initials
     * - surname / last name
     * - suffix (II, Phd, Jr, etc)
     */
    public function parse(string $name): Name
    {
        $name = $this->normalize($name);

        // split on the first comma that is not shielded inside a nickname span,
        // so "John (Bob, Jr) Doe" is not bisected at the nickname's comma. The
        // surname and given portions are taken from the original string, so a
        // secondary given-side comma still separates its segments normally.
        $commaPos = $this->firstStructuralCommaPos($name);

        if ($commaPos !== null) {
            $surname = substr($name, 0, $commaPos);
            $tailSegments = explode(',', substr($name, $commaPos + 1));

            // a whole post-comma segment that is nothing but credentials
            // ("John Smith, MD, FACS") must not be flattened into the given
            // segment, where an isolated all-caps run reads as a first name. It
            // is pulled out to Suffix parts; the rest folds into the given
            // segment as before ("Smith, John, MD, PhD" keeps John as the first
            // name). Uniformity is judged over the whole input, so an unknown
            // credential candidate ("FACS") is only recognized when the casing
            // still carries a signal.
            [$creditParts, $givenSegments] = $this->splitCommaCredentials(
                $tailSegments,
                $this->isUniformUpperInput($name),
            );

            $given = implode(' ', $givenSegments);

            if ($creditParts === []) {
                return $this->parseSplitName($surname, $given)->setSource($name);
            }

            // an empty given (all segments were credential-only) routes the
            // surname through parseSplitName's Western-order empty-given path
            $parts = array_merge(
                $this->parseSplitName($surname, $given)->getParts(),
                $creditParts,
            );

            return (new Name($parts))->setSource($name);
        }

        if ($this->surnameFirst) {
            $tokens = explode(' ', $name);

            if (count($tokens) > 1) {
                // a leading salutation ("Dr. Kim Jong Un") is not the surname:
                // peel it off and re-attach it to the surname segment where
                // SalutationMapper classifies it, so the first real token
                // becomes the surname rather than being shifted away
                $peeled = $this->peelLeadingSalutations($tokens);

                if (count($tokens) > 1) {
                    $surname = array_shift($tokens);
                    $surnameSegment = $peeled === []
                        ? $surname
                        : implode(' ', $peeled) . ' ' . $surname;

                    return $this->parseSplitName($surnameSegment, implode(' ', $tokens))
                        ->setSource($name);
                }
            }
        }

        $parts = explode(' ', $name);

        foreach ($this->getMappers() as $mapper) {
            $parts = $mapper->map($parts);
        }

        return (new Name($parts))->setSource($name);
    }

    /**
     * handles split-parsing of comma-separated name parts: the surname segment
     * before the first comma, and the given-name segment (first/middle names
     * plus any trailing credentials) after it
     */
    protected function parseSplitName(string $surname, string $given): Name
    {
        // a trailing comma ("John Smith MD,") produces an empty given segment;
        // parsing it would emit an empty Firstname part that pollutes exports
        // with a trailing space
        if (trim($given) === '') {
            // a credential-only tail ("Kim Jong Un, MD") leaves an empty given
            // segment; under surname-first the caller asserted CJK order, so
            // split the surname segment the same way rather than falling back to
            // Western order (which would read "Jong Un" as the surname)
            if ($this->surnameFirst) {
                $surnameTokens = explode(' ', trim($surname));

                if (count($surnameTokens) > 1) {
                    $first = array_shift($surnameTokens);

                    return $this->parseSplitName($first, implode(' ', $surnameTokens));
                }
            }

            return new Name($this->getFirstSegmentParser()->parse($surname)->getParts());
        }

        // the InitialMapper split gate ("JM" -> J M) keys off casing, and the
        // signal must come from the whole input, not the given segment alone:
        // "Smith, JM" splits like "JM Smith" (Smith proves mixed case), while
        // "SMITH, JM" (uniform) does not. Feed the whole-input verdict in, then
        // always reset it — the sub-parser and its mapper are memoized.
        $this->getSecondSegmentParser();
        $this->secondSegmentInitialMapper?->setUniformUpperOverride(
            $this->isUniformUpperInput($surname . ' ' . $given),
        );

        try {
            $givenName = $this->getSecondSegmentParser()->parse($given);
        } finally {
            $this->secondSegmentInitialMapper?->setUniformUpperOverride(null);
        }

        $surnameParser = $this->hasGivenNameParts($givenName)
            ? $this->getSurnameSegmentParser()
            : $this->getFirstSegmentParser();

        $parts = array_merge(
            $surnameParser->parse($surname)->getParts(),
            $givenName->getParts(),
        );

        return new Name($parts);
    }

    /**
     * classify the post-first-comma segments: a segment whose every token is a
     * credential (dictionary suffix under the casing rule, or an all-caps
     * unknown-credential candidate) becomes Suffix parts; the rest are returned
     * verbatim to fold back into the given segment. Candidates only count when a
     * real dictionary suffix anchors the classification somewhere in the tail.
     *
     * @param  list<string>  $tailSegments
     * @return array{0: list<Suffix>, 1: list<string>}
     */
    private function splitCommaCredentials(array $tailSegments, bool $uniformInput): array
    {
        /** @var list<array{0: string, 1: list<array{0: string, 1: int}>}> $classified */
        $classified = [];
        $hasAnchor = false;

        foreach ($tailSegments as $segment) {
            $trimmed = trim($segment);
            $tokens = $trimmed === '' ? [] : (preg_split('/\s+/', $trimmed) ?: []);

            $classes = [];
            foreach ($tokens as $token) {
                $class = $this->creditClass($token, $uniformInput);

                if ($class === 1) {
                    $hasAnchor = true;
                }

                $classes[] = [$token, $class];
            }

            $classified[] = [$segment, $classes];
        }

        /** @var list<Suffix> $creditParts */
        $creditParts = [];
        /** @var list<string> $givenSegments */
        $givenSegments = [];

        foreach ($classified as [$segment, $classes]) {
            if ($hasAnchor && $this->isCredentialOnlySegment($classes)) {
                foreach ($classes as [$token, $class]) {
                    $creditParts[] = $class === 1
                        ? new Suffix($token, $this->getSuffixes()[Text::key($token)])
                        : new Suffix($token);
                }

                continue;
            }

            $givenSegments[] = $segment;
        }

        return [$creditParts, $givenSegments];
    }

    /**
     * a segment is credential-only when it has tokens and every one is a
     * dictionary suffix (class 1) or an unknown-credential candidate (class 2)
     *
     * @param  list<array{0: string, 1: int}>  $classes
     */
    private function isCredentialOnlySegment(array $classes): bool
    {
        if ($classes === []) {
            return false;
        }

        foreach ($classes as [, $class]) {
            if ($class === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * 1 = dictionary suffix under the casing rule, 2 = unknown-credential
     * candidate (all-caps, >=2 letters, only when the input is not uniform
     * uppercase), 0 = neither (a name token)
     */
    private function creditClass(string $token, bool $uniformInput): int
    {
        $key = Text::key($token);

        if (array_key_exists($key, $this->getSuffixes())) {
            if (isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                return Text::isUpperCase($token) ? 1 : 0;
            }

            return 1;
        }

        if (
            ! $uniformInput
            && preg_match('/[()\[\]{}<>"\']/', $token) !== 1
            && Text::isUpperCase($token)
            && mb_strlen(Text::letters($token), 'UTF-8') >= 2
        ) {
            return 2;
        }

        return 0;
    }

    /**
     * true when every cased token in the raw input is uppercase, so casing
     * carries no signal. Judged over the whole comma-bearing string, matching
     * the mapper-level uniform-uppercase gates.
     */
    private function isUniformUpperInput(string $name): bool
    {
        $hasUpper = false;

        foreach (preg_split('/\s+/', $name) ?: [] as $token) {
            $letters = Text::letters($token);

            if ($letters === '') {
                continue;
            }

            if (mb_strtoupper($letters, 'UTF-8') !== $letters) {
                return false;
            }

            if ($letters !== mb_strtolower($letters, 'UTF-8')) {
                $hasUpper = true;
            }
        }

        return $hasUpper;
    }

    protected function hasGivenNameParts(Name $name): bool
    {
        foreach ($name->getParts() as $part) {
            if ($part instanceof GivenNamePart && $part->normalize() !== '') {
                return true;
            }
        }

        return false;
    }

    protected function getFirstSegmentParser(): Parser
    {
        return $this->firstSegmentParser ??= (new Parser())->setMappers([
            new SalutationMapper($this->getSalutations(), $this->getMaxSalutationIndex()),
            new SuffixMapper($this->getSuffixes(), false, 2),
            new NicknameMapper($this->getNicknameDelimiters()),
            new InitialMapper($this->getMaxCombinedInitials()),
            new LastnameMapper($this->getPrefixes(), true),
            new FirstnameMapper(),
            new MiddlenameMapper(false, $this->getPrefixes()),
        ]);
    }

    protected function getSurnameSegmentParser(): Parser
    {
        return $this->surnameSegmentParser ??= (new Parser())->setMappers([
            new SalutationMapper($this->getSalutations(), $this->getMaxSalutationIndex()),
            new SuffixMapper($this->getSuffixes(), false, 1),
            new LastnameMapper($this->getPrefixes(), true, true),
        ]);
    }

    protected function getSecondSegmentParser(): Parser
    {
        if ($this->secondSegmentParser === null) {
            $this->secondSegmentInitialMapper = new InitialMapper($this->getMaxCombinedInitials(), true);
            $this->secondSegmentParser = (new Parser())->setMappers([
                new SuffixMapper($this->getSuffixes(), true, 0),
                new SalutationMapper($this->getSalutations(), $this->getMaxSalutationIndex()),
                new NicknameMapper($this->getNicknameDelimiters()),
                $this->secondSegmentInitialMapper,
                new FirstnameMapper(),
                new MiddlenameMapper(true, $this->getPrefixes()),
            ]);
        }

        return $this->secondSegmentParser;
    }

    /**
     * get the mappers for this parser
     *
     * @return array<int, \Iliaal\NameParser\Mapper\AbstractMapper>
     */
    public function getMappers(): array
    {
        if (! $this->customMappers && empty($this->mappers)) {
            $this->mappers = [
                new SalutationMapper($this->getSalutations(), $this->getMaxSalutationIndex()),
                new SuffixMapper($this->getSuffixes()),
                new NicknameMapper($this->getNicknameDelimiters()),
                new InitialMapper($this->getMaxCombinedInitials()),
                new LastnameMapper($this->getPrefixes()),
                new FirstnameMapper(),
                new MiddlenameMapper(false, $this->getPrefixes()),
            ];
        }

        return $this->mappers;
    }

    /**
     * set the mappers for this parser.
     *
     * Only the single-segment (non-comma) pipeline uses this list. Comma input
     * ("Last, First") is parsed by dedicated surname/given-name sub-parsers
     * (getFirstSegmentParser/getSecondSegmentParser) that build their own mapper
     * lists, so a custom list set here does not affect comma forms.
     * setSurnameFirst(true) routes comma-less input through those same
     * sub-parsers, so a custom list does not apply on that path either. The
     * language dictionaries do propagate to the sub-parsers.
     *
     * An empty list resets the parser to the default pipeline.
     *
     * @param  array<int, \Iliaal\NameParser\Mapper\AbstractMapper>  $mappers
     */
    public function setMappers(array $mappers): static
    {
        $this->mappers = $mappers;
        $this->customMappers = $mappers !== [];

        return $this;
    }

    /**
     * drop the memoized mapper pipeline and comma-segment sub-parsers so the
     * next parse() rebuilds them from the current configuration. Config setters
     * call this; without it, changing a setting after the first parse() has no
     * effect on a reused instance.
     */
    private function invalidateMapperCache(): void
    {
        if (! $this->customMappers) {
            $this->mappers = [];
        }

        $this->firstSegmentParser = null;
        $this->surnameSegmentParser = null;
        $this->secondSegmentParser = null;
        $this->secondSegmentInitialMapper = null;
    }

    /**
     * normalize the name
     */
    protected function normalize(string $name): string
    {
        $whitespace = $this->getWhitespace();

        $name = trim($name);

        // an empty whitespace set has nothing to collapse; building the pattern
        // would emit "/[]+/", an E_WARNING per parse, so short-circuit.
        if ($whitespace === '') {
            return $name;
        }

        // preg_replace returns null on regex compile error; user-set whitespace
        // characters might produce an invalid pattern, so fall back to the input.
        $name = preg_replace($this->normalizePattern($whitespace), ' ', $name) ?? $name;

        // trim again: custom whitespace at the edges becomes a space above and
        // the leading trim() (default charset) would not have removed it.
        return trim($name);
    }

    /**
     * build (or reuse) the whitespace-collapse pattern for the given set
     */
    private function normalizePattern(string $whitespace): string
    {
        if ($this->normalizePattern === null || $this->normalizePatternKey !== $whitespace) {
            $this->normalizePattern = '/[' . preg_quote($whitespace, '/') . ']+/';
            $this->normalizePatternKey = $whitespace;
        }

        return $this->normalizePattern;
    }

    /**
     * byte offset of the first comma that is not shielded inside a matched
     * nickname-delimiter span, or null when there is no such comma. Used to pick
     * the surname/given split point without bisecting a bracketed nickname.
     */
    private function firstStructuralCommaPos(string $name): ?int
    {
        if (! str_contains($name, ',')) {
            return null;
        }

        // masking only swaps ',' <-> a same-width placeholder, so byte offsets in
        // the masked string map directly back onto the original
        $pos = strpos($this->maskDelimitedCommas($name), ',');

        return $pos === false ? null : $pos;
    }

    /**
     * replace each comma that falls inside a matched asymmetric delimiter pair
     * with a placeholder so the comma split leaves the nickname intact. Only
     * spans that actually close are masked; an unmatched opener masks nothing.
     */
    private function maskDelimitedCommas(string $name): string
    {
        if (! str_contains($name, ',')) {
            return $name;
        }

        $delimiters = $this->nicknameDelimiters !== []
            ? $this->nicknameDelimiters
            : self::DEFAULT_NICKNAME_DELIMITERS;

        $pairs = [];
        foreach ($delimiters as $open => $close) {
            if ($open !== '' && $close !== '' && $open !== $close) {
                $pairs[$open] = $close;
            }
        }

        if ($pairs === []) {
            return $name;
        }

        // byte-level pre-check: no opener byte present means nothing to mask,
        // skipping the per-character scan on the common bracket-free row
        if (strpbrk($name, implode('', array_keys($pairs))) === false) {
            return $name;
        }

        $chars = mb_str_split($name, 1, 'UTF-8');

        /** @var list<string> $closers open spans' expected closing delimiters */
        $closers = [];
        /** @var list<list<int>> $pendingCommas comma offsets per open span */
        $pendingCommas = [];
        /** @var array<int, true> $mask */
        $mask = [];

        foreach ($chars as $i => $ch) {
            $depth = count($closers);

            if ($depth > 0 && $ch === $closers[$depth - 1]) {
                array_pop($closers);
                foreach (array_pop($pendingCommas) ?? [] as $pos) {
                    $mask[$pos] = true;
                }

                continue;
            }

            if (isset($pairs[$ch])) {
                $closers[] = $pairs[$ch];
                $pendingCommas[] = [];

                continue;
            }

            if ($ch === ',' && $depth > 0) {
                $pendingCommas[$depth - 1][] = $i;
            }
        }

        if ($mask === []) {
            return $name;
        }

        foreach (array_keys($mask) as $pos) {
            $chars[$pos] = self::COMMA_PLACEHOLDER;
        }

        return implode('', $chars);
    }

    /**
     * remove leading salutation tokens from $tokens (by reference) and return
     * them, greedily matching multi-word salutations ("his honour") first. Used
     * by the surname-first router so a leading honorific attaches to the surname
     * segment instead of being shifted away as the surname itself.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function peelLeadingSalutations(array &$tokens): array
    {
        $salutations = $this->getSalutations();
        $maxWords = $this->maxSalutationWords();

        $peeled = [];

        while ($tokens !== []) {
            $matched = 0;

            for ($n = min(count($tokens), $maxWords); $n >= 1; $n--) {
                $key = Text::key(implode(' ', array_slice($tokens, 0, $n)));

                if (array_key_exists($key, $salutations)) {
                    $matched = $n;

                    break;
                }
            }

            if ($matched === 0) {
                break;
            }

            for ($i = 0; $i < $matched; $i++) {
                $token = array_shift($tokens);

                if ($token !== null) {
                    $peeled[] = $token;
                }
            }
        }

        return $peeled;
    }

    /**
     * the greatest word count among the configured salutation keys, bounding the
     * multi-word match window in peelLeadingSalutations()
     */
    private function maxSalutationWords(): int
    {
        $max = 1;

        foreach (array_keys($this->getSalutations()) as $key) {
            $words = substr_count((string) $key, ' ') + 1;

            if ($words > $max) {
                $max = $words;
            }
        }

        return $max;
    }

    /**
     * get a string of characters that are supposed to be treated as whitespace
     */
    public function getWhitespace(): string
    {
        return $this->whitespace;
    }

    /**
     * set the string of characters that are supposed to be treated as whitespace
     */
    public function setWhitespace(string $whitespace): static
    {
        $this->whitespace = $whitespace;

        return $this;
    }

    /**
     * @return array<int|string, string>
     */
    protected function getPrefixes(): array
    {
        return $this->prefixes ??= $this->mergeFromLanguages('getLastnamePrefixes');
    }

    /**
     * @return array<int|string, string>
     */
    protected function getSuffixes(): array
    {
        return $this->suffixes ??= $this->mergeFromLanguages('getSuffixes');
    }

    /**
     * @return array<int|string, string>
     */
    protected function getSalutations(): array
    {
        return $this->salutations ??= $this->mergeFromLanguages('getSalutations');
    }

    /**
     * @param  'getSuffixes'|'getSalutations'|'getLastnamePrefixes'  $method
     * @return array<int|string, string>
     */
    private function mergeFromLanguages(string $method): array
    {
        $merged = [];

        foreach ($this->languages as $language) {
            $merged += $language->$method();
        }

        return $merged;
    }

    /**
     * @return array<string, string>
     */
    public function getNicknameDelimiters(): array
    {
        return $this->nicknameDelimiters;
    }

    /**
     * @param  array<string, string>  $nicknameDelimiters
     */
    public function setNicknameDelimiters(array $nicknameDelimiters): static
    {
        $this->nicknameDelimiters = $nicknameDelimiters;
        $this->invalidateMapperCache();

        return $this;
    }

    public function getMaxSalutationIndex(): int
    {
        return $this->maxSalutationIndex;
    }

    public function setMaxSalutationIndex(int $maxSalutationIndex): static
    {
        $this->maxSalutationIndex = $maxSalutationIndex;
        $this->invalidateMapperCache();

        return $this;
    }

    public function getMaxCombinedInitials(): int
    {
        return $this->maxCombinedInitials;
    }

    public function setMaxCombinedInitials(int $maxCombinedInitials): static
    {
        $this->maxCombinedInitials = $maxCombinedInitials;
        $this->invalidateMapperCache();

        return $this;
    }

    public function isSurnameFirst(): bool
    {
        return $this->surnameFirst;
    }

    /**
     * read space-separated input surname-first (CJK order). Only affects names
     * without a comma. This path routes through the comma-form surname/given
     * sub-parsers, not the configurable mapper pipeline, so a custom setMappers()
     * list does not apply here; there is no cache to drop.
     */
    public function setSurnameFirst(bool $surnameFirst): static
    {
        $this->surnameFirst = $surnameFirst;

        return $this;
    }
}
