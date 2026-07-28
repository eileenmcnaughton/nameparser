<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;

class Name
{
    private const string PARTS_NAMESPACE = 'Iliaal\NameParser\Part';

    /**
     * @var array<int, AbstractPart|string> the parts that make up this name
     */
    protected array $parts = [];

    /**
     * the normalized input this name was parsed from, retained so the advisory
     * confidence signal can be derived from the same string the parser saw
     */
    protected ?string $source = null;

    /**
     * @var array<int|string, string>|null
     */
    protected ?array $confidenceSuffixes = null;

    /**
     * constructor takes the array of parts this name consists of
     *
     * raw string parts are retained in getParts() but ignored by every getter
     * and by export(): the getters only ever read AbstractPart instances.
     *
     * @param  array<int, AbstractPart|string>|null  $parts
     * @param  array<int|string, string>|null  $confidenceSuffixes
     */
    public function __construct(?array $parts = null, ?array $confidenceSuffixes = null)
    {
        $this->confidenceSuffixes = $confidenceSuffixes;

        if ($parts !== null) {
            $this->setParts($parts);
        }
    }

    /**
     * the rendered string drops the comma structure and is not guaranteed to
     * re-parse to the same fields (e.g. a surname-plus-credential row); it is a
     * display form, not a round-trippable serialization
     */
    public function __toString(): string
    {
        return implode(' ', $this->getAll(true));
    }

    /**
     * set the parts this name consists of
     *
     * raw string parts are retained in getParts() but ignored by every getter
     * and by export(): the getters only ever read AbstractPart instances.
     *
     * @param  array<int, AbstractPart|string>  $parts
     * @return $this
     */
    public function setParts(array $parts): Name
    {
        $this->parts = $parts;

        return $this;
    }

    /**
     * get the parts this name consists of
     *
     * @return array<int, AbstractPart|string>
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * record the normalized input this name was parsed from
     *
     * @return $this
     */
    public function setSource(string $source): Name
    {
        $this->source = $source;

        return $this;
    }

    /**
     * the normalized input this name was parsed from, or null when none was
     * recorded (e.g. a manually constructed Name)
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * advisory confidence signal for this parse, derived from the same input
     * the parser saw; falls back to the reconstructed name when no source was
     * recorded (e.g. a manually constructed Name). parse() is unaffected: this
     * is a read-only check the caller opts into.
     *
     * The reconstruction fallback sees normalized casing, so it generally
     * cannot flag uniform-case ambiguity; parse via Parser (which records the
     * source) when that signal matters.
     *
     * @return array{ambiguous: bool, notes: list<string>}
     */
    public function getConfidence(): array
    {
        return Confidence::assess($this->source ?? $this->__toString(), $this->confidenceSuffixes);
    }

    /**
     * machine-readable view of every part with a stable key set: each key is
     * always present, empty string when the part is absent. Unlike getAll(),
     * which omits empties and varies its keys, this is safe to consume without
     * existence checks.
     *
     * Note the `lastname` value already contains any prefix; `lastname_prefix`
     * is a convenience extract, not a component to prepend to `lastname`.
     *
     * @return array{salutation: string, firstname: string, initials: string, middlename: string, lastname_prefix: string, lastname: string, suffix: string, nickname: string, given_name: string, full_name: string}
     */
    public function toArray(): array
    {
        return [
            'salutation' => $this->getSalutation(),
            'firstname' => $this->getFirstname(),
            'initials' => $this->getInitials(),
            'middlename' => $this->getMiddlename(),
            'lastname_prefix' => $this->getLastnamePrefix(),
            'lastname' => $this->getLastname(),
            'suffix' => $this->getSuffix(),
            'nickname' => $this->getNickname(),
            'given_name' => $this->getGivenName(),
            'full_name' => $this->getFullName(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAll(bool $format = false): array
    {
        $results = [];
        $keys = [
            'salutation' => [],
            'firstname' => [],
            'nickname' => [$format],
            'middlename' => [],
            'initials' => [],
            'lastname' => [],
            'suffix' => [],
        ];

        foreach ($keys as $key => $args) {
            $method = 'get' . ucfirst($key);
            /** @var callable(): string $callable */
            $callable = [$this, $method];
            $value = $callable(...$args);
            if ($value !== '') {
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * get the given name (first name, middle names and initials)
     * in the order they were entered while still applying normalisation
     */
    public function getGivenName(): string
    {
        return $this->export('GivenNamePart');
    }

    /**
     * get the given name followed by the last name (including any prefixes)
     *
     * like __toString(), the rendered string drops comma structure and is not
     * guaranteed to re-parse to the same fields (e.g. a surname-plus-credential
     * row); it is a display form, not a round-trippable serialization
     */
    public function getFullName(): string
    {
        $parts = array_filter(
            [$this->getGivenName(), $this->getLastname()],
            static fn(string $part): bool => $part !== '',
        );

        return implode(' ', $parts);
    }

    /**
     * get the first name
     */
    public function getFirstname(): string
    {
        return $this->export('Firstname');
    }

    /**
     * get the last name
     */
    public function getLastname(bool $pure = false): string
    {
        return $this->export('Lastname', $pure);
    }

    /**
     * get the last name prefix
     */
    public function getLastnamePrefix(): string
    {
        return $this->export('LastnamePrefix');
    }

    /**
     * get the initials
     */
    public function getInitials(): string
    {
        return $this->export('Initial');
    }

    /**
     * get the suffix(es)
     */
    public function getSuffix(): string
    {
        return $this->export('Suffix');
    }

    /**
     * get the salutation(s)
     */
    public function getSalutation(): string
    {
        return $this->export('Salutation');
    }

    /**
     * the honorific split into one entry per person addressed, for callers with
     * a single prefix field per contact: "Mr. and Mrs. Brad Smith" gives
     * ['Mr.', 'Mrs.']. Stacked titles for one person stay together
     * ("Rev. Dr John Doe" gives ['Rev. Dr.']), and a name with no honorific
     * gives an empty list, so [0] can be indexed without checking isJoint()
     * first. Joining the entries with " and " reproduces getSalutation().
     *
     * @return list<string>
     */
    public function getSalutations(): array
    {
        $groups = [];
        $current = [];

        foreach ($this->parts as $part) {
            if (!$part instanceof Salutation) {
                continue;
            }

            if ($part instanceof SalutationConnector) {
                if ($current !== []) {
                    $groups[] = implode(' ', $current);
                    $current = [];
                }

                continue;
            }

            $normalized = $part->normalize();
            // skip empties for the same reason export() does: a blank token
            // must not become a stray space inside a group
            if ($normalized !== '') {
                $current[] = $normalized;
            }
        }

        if ($current !== []) {
            $groups[] = implode(' ', $current);
        }

        return $groups;
    }

    /**
     * whether the honorific covers two people ("Mr. and Mrs. Brad Smith"). The
     * parsed given and family name belong to the person actually named; the
     * partner is implied by the title alone, so a caller importing households
     * should branch on this rather than treat the row as one individual.
     *
     * Only a title-anchored form is detected. A bare "Brad and Jane Smith"
     * carries no honorific to attach the connector to and reports false.
     */
    public function isJoint(): bool
    {
        foreach ($this->parts as $part) {
            if ($part instanceof SalutationConnector) {
                return true;
            }
        }

        return false;
    }

    /**
     * get the nick name(s)
     */
    public function getNickname(bool $wrap = false): string
    {
        $nickname = $this->export('Nickname');

        if ($wrap && $nickname !== '') {
            return '(' . $nickname . ')';
        }

        return $nickname;
    }

    /**
     * get the middle name(s)
     */
    public function getMiddlename(): string
    {
        return $this->export('Middlename');
    }

    /**
     * helper method used by getters to extract and format relevant name parts
     */
    protected function export(string $type, bool $strict = false): string
    {
        $matched = [];

        foreach ($this->parts as $part) {
            if ($part instanceof AbstractPart && $this->isType($part, $type, $strict)) {
                $normalized = $part->normalize();
                // skip empty normalized values so a blank token cannot inject a
                // stray space into given_name / full_name joins
                if ($normalized !== '') {
                    $matched[] = $normalized;
                }
            }
        }

        return implode(' ', $matched);
    }

    /**
     * helper method to check if a part is of the given type
     */
    protected function isType(AbstractPart $part, string $type, bool $strict = false): bool
    {
        $className = self::PARTS_NAMESPACE . '\\' . $type;

        if ($strict) {
            return $part::class === $className;
        }

        return $part instanceof $className;
    }
}
