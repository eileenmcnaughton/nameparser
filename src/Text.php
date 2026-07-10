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
        if (isset(self::$cache[$word])) {
            return self::$cache[$word];
        }

        $key = str_replace('.', '', $word);
        $key = trim($key, " \r\n\t\"'()[]{}<>");
        $key = rtrim($key, ',;:)');
        $key = mb_strtolower($key, 'UTF-8');

        // pure, config-independent transform, so cached entries never go stale;
        // cap the table and drop it wholesale to bound memory on huge batches.
        if (count(self::$cache) >= 4096) {
            self::$cache = [];
        }
        self::$cache[$word] = $key;

        return $key;
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
}
