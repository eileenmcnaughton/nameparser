<?php

namespace Iliaal\NameParser;

/**
 * Shared token-normalization primitives. The parser's mappers and the advisory
 * Confidence pass must key and case-test tokens identically, so both routes go
 * through this single implementation rather than duplicating the transforms.
 *
 * @internal
 */
final class Text
{
    /**
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * registry lookup key for the given word
     */
    public static function key(string $word): string
    {
        // the entry cap bounds the count, not the bytes: a run of huge unique
        // tokens would retain megabytes, and nothing that long is a name worth
        // caching anyway
        if (strlen($word) > 64) {
            return self::transform($word);
        }

        if (isset(self::$cache[$word])) {
            return self::$cache[$word];
        }

        // pure, config-independent transform, so cached entries never go stale;
        // cap the table and drop it wholesale to bound memory on huge batches.
        if (count(self::$cache) >= 4096) {
            self::$cache = [];
        }

        return self::$cache[$word] = self::transform($word);
    }

    private static function transform(string $word): string
    {
        $key = str_replace('.', '', $word);
        $key = trim($key, " \r\n\t\"'()[]{}<>");
        $key = rtrim($key, ',;:)');

        return mb_strtolower($key, 'UTF-8');
    }

    /**
     * the word's letters only, everything else stripped
     */
    public static function letters(string $word): string
    {
        return preg_replace('/[^\p{L}]/u', '', $word) ?? '';
    }

    /**
     * true when the word's letters are all uppercase and carry a case signal
     * (letters exist and are not caseless)
     */
    public static function isUpperCase(string $word): bool
    {
        $letters = self::letters($word);

        if ($letters === '') {
            return false;
        }

        return $letters === mb_strtoupper($letters, 'UTF-8')
            && $letters !== mb_strtolower($letters, 'UTF-8');
    }

    /**
     * true when the word's letters are all lowercase and carry a case signal
     */
    public static function isLowerCase(string $word): bool
    {
        $letters = self::letters($word);

        if ($letters === '') {
            return false;
        }

        return $letters === mb_strtolower($letters, 'UTF-8')
            && $letters !== mb_strtoupper($letters, 'UTF-8');
    }

    /**
     * true when the word's letters have a distinct upper/lower form (Latin,
     * Greek, Cyrillic) rather than a caseless script (Han, Hebrew, Arabic)
     */
    public static function isCased(string $word): bool
    {
        $letters = self::letters($word);

        return $letters !== ''
            && mb_strtolower($letters, 'UTF-8') !== mb_strtoupper($letters, 'UTF-8');
    }

    /**
     * an all-caps unknown token that reads as a credential candidate ("FACS"):
     * at least two letters, not bracket/quote-wrapped. Callers still gate on
     * dictionary membership and uniform-uppercase input.
     */
    public static function isUnknownCredentialCandidate(string $token): bool
    {
        // a bracket/quote-wrapped token is a nickname or aside ("(JJ)"), not a
        // credential; those are resolved by later mappers, so leave them be.
        if (preg_match('/[()\[\]{}<>"\']/', $token) === 1) {
            return false;
        }

        if (! self::isUpperCase($token)) {
            return false;
        }

        return mb_strlen(self::letters($token), 'UTF-8') >= 2;
    }
}
