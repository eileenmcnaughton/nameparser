<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Salutation;
use Iliaal\NameParser\Part\SalutationConnector;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class SalutationMapper extends AbstractMapper
{
    /**
     * The article that may sit between the start of the name and an honorific
     * ("The Rev. Mark Williams"). Anything else ends the leading run.
     */
    private const string LEADING_ARTICLE = 'the';

    /**
     * Tokens that join two titles into one honorific ("Mr. and Mrs."). Both
     * render as "and" so the two spellings normalize to one salutation.
     */
    private const array CONNECTOR_KEYS = [
        'and' => true, '&' => true,
    ];

    private const string CONNECTOR_RENDERED = 'and';

    /**
     * Salutation keys that are also real personal names, so reading one as an
     * honorific costs a name part. Attested in the bundled NPI corpus: Lord (3
     * surnames), Master (1 surname), Hon (1 given name). Dame, Lady and Pastor
     * are unattested there but collide in other populations (Pastor is both a
     * Spanish surname and a given name). Drives the requireRemainder guard
     * below, and the leading-title note in Confidence.
     */
    public const array NAME_COLLIDING_KEYS = [
        'dame' => true, 'hon' => true, 'lady' => true,
        'lord' => true, 'master' => true, 'pastor' => true,
    ];

    /**
     * Multi-word salutation patterns ("the honorable", "his honour"), split
     * once. Single-word salutations are handled by the exact-match check in
     * matchAt(), so only these need the subset loop.
     *
     * @var list<array{array<int, string>, string}>
     */
    private array $multiWord = [];

    /**
     * @param  array<int|string, string>  $salutations
     * @param  bool  $requireRemainder  refuse to consume the segment's last
     *                                  token, for segments the caller has
     *                                  already asserted to be a surname
     */
    public function __construct(
        protected array $salutations,
        protected int $maxIndex = 0,
        protected bool $requireRemainder = false,
    ) {
        foreach ($salutations as $key => $salutation) {
            if (str_contains((string) $key, ' ')) {
                $this->multiWord[] = [explode(' ', (string) $key), $salutation];
            }
        }
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $max = ($this->maxIndex > 0) ? min($this->maxIndex, count($parts)) : max(1, intdiv(count($parts), 2));

        $mapped = [];
        $input = 0;
        $scanned = 0;
        $total = count($parts);

        while ($input < $total && $scanned < $max) {
            $current = $parts[$input];

            if ($current instanceof AbstractPart) {
                break;
            }

            [$part, $consumed] = $this->matchAt($parts, $input);

            // a connector joining two titles is part of the honorific, not a
            // given name ("Mr. and Mrs. Brad Smith" keeps Brad as the first
            // name). It needs a title on both sides, so a stray "and" is never
            // absorbed, and it does not count toward the scan budget because it
            // is not itself a title.
            if (is_string($part)
                && isset(self::CONNECTOR_KEYS[$this->getKey($part)])
                && $mapped !== []
                && end($mapped) instanceof Salutation
                && $this->isSalutationAt($parts, $input + $consumed)) {
                $mapped[] = new SalutationConnector($part, self::CONNECTOR_RENDERED);
                $input += $consumed;

                continue;
            }

            // honorifics lead the name, so only a bare article may sit between
            // the start and a title ("The Rev. Mark Williams"). Once a real name
            // token is seen, a later dictionary hit belongs to the person rather
            // than to a title, so "John Lord Smith Jr" keeps Lord as a middle
            // name. An explicit maxSalutationIndex is the caller asserting that
            // titles do appear further in ("Francis Mr"), so it opts out.
            if ($this->maxIndex === 0
                && is_string($part)
                && $this->getKey($part) !== self::LEADING_ARTICLE) {
                break;
            }

            // the comma form asserts everything before the comma is the surname,
            // so consuming the segment whole would leave the name with no
            // surname at all. Only yield the last token back when the title is
            // also a real name ("Lord, Jack"); an unambiguous title stays a
            // salutation ("Dr., John").
            if ($this->requireRemainder
                && $input + $consumed >= $total
                && isset(self::NAME_COLLIDING_KEYS[$this->getKey($current)])) {
                break;
            }

            $mapped[] = $part;
            $input += $consumed;
            $scanned++;
        }

        return array_merge($mapped, array_slice($parts, $input));
    }

    /**
     * @param  PartArray  $parts
     * @return array{AbstractPart|string, int}
     */
    private function matchAt(array $parts, int $start): array
    {
        $current = $parts[$start];

        if (! is_string($current)) {
            return [$current, 1];
        }

        $currentKey = $this->getKey($current);

        if (array_key_exists($currentKey, $this->salutations)) {
            return [new Salutation($current, $this->salutations[$currentKey]), 1];
        }

        foreach ($this->multiWord as [$keys, $salutation]) {
            // a multi-word match requires the first pattern word to key-equal the
            // current token, so skip the slice+compare when it can't.
            if ($keys[0] !== $currentKey) {
                continue;
            }

            $length = count($keys);

            $subset = array_slice($parts, $start, $length);

            if ($this->isMatchingSubset($keys, $subset)) {
                return [new Salutation(implode(' ', $subset), $salutation), $length];
            }
        }

        return [$current, 1];
    }

    /**
     * whether a title starts at the given index, used as the right-hand guard
     * for a connector so "Mr. and Brad Smith" leaves the connector alone
     *
     * @param  PartArray  $parts
     */
    private function isSalutationAt(array $parts, int $index): bool
    {
        if (! isset($parts[$index]) || ! is_string($parts[$index])) {
            return false;
        }

        [$part] = $this->matchAt($parts, $index);

        return $part instanceof Salutation;
    }

    /**
     * check if the given subset matches the given keys entry by entry,
     * which means word by word, except that we first need to key-ify
     * the subset words
     *
     * @param  array<int, string>  $keys
     * @param  PartArray  $subset
     *
     * @phpstan-assert-if-true array<int, string> $subset
     */
    private function isMatchingSubset(array $keys, array $subset): bool
    {
        // array_slice() returns fewer parts than the pattern near the end of the
        // token list; without this a one-token tail would match the first key of
        // a multi-word salutation ("Smith, Her" -> "Her Honour").
        if (count($subset) !== count($keys)) {
            return false;
        }

        for ($i = 0; $i < count($subset); $i++) {
            $part = $subset[$i];
            if (! is_string($part) || $this->getKey($part) !== $keys[$i]) {
                return false;
            }
        }

        return true;
    }
}
