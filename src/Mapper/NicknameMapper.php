<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Nickname;

/**
 * @phpstan-import-type PartArray from AbstractMapper
 */
class NicknameMapper extends AbstractMapper
{
    /**
     * @var array<string, string>
     */
    protected array $delimiters = [
        '[' => ']',
        '{' => '}',
        '(' => ')',
        '<' => '>',
        '"' => '"',
        '\'' => '\'',
    ];

    protected string $regexp;

    /**
     * @param  array<string, string>  $delimiters
     */
    public function __construct(array $delimiters = [])
    {
        if (! empty($delimiters)) {
            $this->delimiters = $delimiters;
        }

        // an empty-string key compiles to a degenerate pattern that matches every
        // token and warns per parse; drop it. If nothing valid remains the mapper
        // no-ops (buildRegexp returns '').
        $this->delimiters = array_filter(
            $this->delimiters,
            static fn(string $key): bool => $key !== '',
            ARRAY_FILTER_USE_KEY
        );

        $this->regexp = $this->buildRegexp();
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        if ($this->regexp === '') {
            return $parts;
        }

        $isEncapsulated = false;

        $closingDelimiter = '';

        /** @var PartArray $pending parts mapped under the current still-open delimiter */
        $pending = [];

        /** @var array<int, true> $emptyKeys keys whose cleaned nickname value was empty */
        $emptyKeys = [];

        /** @var list<int> $strayDrops lone symmetric-quote tokens to remove */
        $strayDrops = [];

        foreach ($parts as $k => $part) {
            if ($part instanceof AbstractPart) {
                continue;
            }

            if (preg_match($this->regexp, $part, $matches)) {
                $opener = $matches[1];
                $closer = $this->delimiters[$opener] ?? '';
                $stripped = mb_substr($part, mb_strlen($opener, 'UTF-8'), null, 'UTF-8');

                // a symmetric delimiter (quote) is only an opener when its closing
                // partner appears later; otherwise a leading quote is an elided
                // particle ("'t Hooft") that must survive verbatim.
                $shouldOpen = $opener !== $closer
                    || $this->symmetricCloserAppears($parts, $k, $stripped, $closer);

                if ($shouldOpen) {
                    $isEncapsulated = true;
                    $part = $stripped;
                    $closingDelimiter = $closer;
                    $pending = [];
                } elseif (! $isEncapsulated) {
                    if ($stripped === '') {
                        $strayDrops[] = $k;
                    }

                    continue;
                }
            }

            if (! $isEncapsulated) {
                continue;
            }

            $pending[$k] = $parts[$k];

            $closerLength = mb_strlen($closingDelimiter, 'UTF-8');
            if ($closingDelimiter !== ''
                && mb_substr($part, -$closerLength, null, 'UTF-8') === $closingDelimiter) {
                $isEncapsulated = false;
                $part = mb_substr($part, 0, -$closerLength, 'UTF-8');
                $pending = [];
            }

            $value = trim($part, '"\'');

            // a lone delimiter pair (" ( ) ") cleans to nothing; emitting an empty
            // Nickname pollutes getNickname() with joined spaces, so drop the token.
            if ($value === '') {
                $emptyKeys[$k] = true;

                continue;
            }

            $parts[$k] = new Nickname($value);
        }

        // an opening delimiter with no matching close is not a nickname: revert
        // the swallowed parts so the surname survives (e.g. "John (Bob Smith").
        if ($isEncapsulated) {
            foreach ($pending as $k => $original) {
                $parts[$k] = $original;

                // reverted tokens are restored verbatim, so a value that cleaned
                // empty must not also be dropped below
                unset($emptyKeys[$k]);
            }

            // the opening token still carries its unmatched delimiter char; drop
            // it so a stray "(" or quote does not leak into a name part
            // ("Bob Jones (" must not yield last name "Jones (").
            $open = array_key_first($pending);
            if ($open !== null && is_string($parts[$open])) {
                $cleaned = ltrim($parts[$open], implode('', array_keys($this->delimiters)));
                if ($cleaned === '') {
                    unset($parts[$open]);
                } else {
                    $parts[$open] = $cleaned;
                }
            }
        }

        foreach ($strayDrops as $k) {
            unset($parts[$k]);
        }

        foreach (array_keys($emptyKeys) as $k) {
            unset($parts[$k]);
        }

        return array_values($parts);
    }

    /**
     * whether a symmetric delimiter opened at $openKey has a matching closer
     * later: the same token's tail, or a subsequent token ending with $closer.
     *
     * @param  PartArray  $parts
     */
    private function symmetricCloserAppears(array $parts, int $openKey, string $stripped, string $closer): bool
    {
        $closerLength = mb_strlen($closer, 'UTF-8');

        if ($stripped !== '' && mb_substr($stripped, -$closerLength, null, 'UTF-8') === $closer) {
            return true;
        }

        $seen = false;
        foreach ($parts as $k => $part) {
            if ($k === $openKey) {
                $seen = true;

                continue;
            }

            if (! $seen || ! is_string($part)) {
                continue;
            }

            if (mb_substr($part, -$closerLength, null, 'UTF-8') === $closer) {
                return true;
            }
        }

        return false;
    }

    protected function buildRegexp(): string
    {
        if (empty($this->delimiters)) {
            return '';
        }

        $keys = array_keys($this->delimiters);

        // longest opener first so a multi-char delimiter ("<<") wins over a
        // single-char prefix ("<") when both are configured
        usort($keys, static fn(string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        $alternation = implode('|', array_map(
            static fn(string $key): string => preg_quote($key, '/'),
            $keys
        ));

        return '/^(' . $alternation . ')/u';
    }
}
