<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Salutation;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class SalutationMapper extends AbstractMapper
{
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
     */
    public function __construct(
        protected array $salutations,
        protected int $maxIndex = 0,
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
            if ($parts[$input] instanceof AbstractPart) {
                break;
            }

            [$part, $consumed] = $this->matchAt($parts, $input);
            $mapped[] = $part;
            $input += $consumed;
            $scanned++;
        }

        return array_merge($mapped, array_slice($parts, $input));
    }

    /**
     * We pass the full parts array and the current position to allow
     * not only single-word matches but also combined matches with
     * subsequent words (parts).
     *
     * @param  PartArray  $parts
     * @return PartArray
     */
    protected function substituteWithSalutation(array $parts, int $start): array
    {
        [$replacement, $consumed] = $this->matchAt($parts, $start);

        if ($consumed === 1) {
            $parts[$start] = $replacement;

            return $parts;
        }

        array_splice($parts, $start, $consumed, [$replacement]);

        return $parts;
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

    /**
     * check if the given word is a viable salutation
     */
    protected function isSalutation(string $word): bool
    {
        return array_key_exists($this->getKey($word), $this->salutations);
    }
}
