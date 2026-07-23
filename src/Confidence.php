<?php

namespace Iliaal\NameParser;

use Iliaal\NameParser\Mapper\SuffixMapper;

/**
 * Advisory pass: flags inputs where a token collides with a credential AND the
 * casing signal is uninformative (uniform-case input, or a lowercase token), so
 * the import pipeline can route the row to manual review instead of trusting a
 * silently-chosen first/last split.
 */
class Confidence
{
    /**
     * When suffixes are supplied, only collisions present in that parser's
     * configured dictionaries contribute to the result.
     *
     * @param  array<int|string, string>|null  $suffixes
     * @return array{ambiguous: bool, notes: list<string>}
     */
    public static function assess(string $original, ?array $suffixes = null): array
    {
        $tokens = preg_split('/[\s,]+/u', trim($original), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // derive uniform-case from tokens (same shape as the parser), never from
        // a whole-string letters() strip on multi-megabyte hostile rows
        $uniformUpper = true;
        $uniformLower = true;
        $hasCased = false;
        foreach ($tokens as $token) {
            $letters = Text::letters($token);
            if ($letters === '') {
                continue;
            }
            $hasCased = $hasCased
                || $letters !== mb_strtolower($letters, 'UTF-8')
                || $letters !== mb_strtoupper($letters, 'UTF-8');
            if ($letters !== mb_strtoupper($letters, 'UTF-8')) {
                $uniformUpper = false;
            }
            if ($letters !== mb_strtolower($letters, 'UTF-8')) {
                $uniformLower = false;
            }
        }
        if (! $hasCased) {
            $uniformUpper = false;
            $uniformLower = false;
        }

        $notes = [];
        foreach ($tokens as $token) {
            $key = Text::key($token);
            if (! isset(SuffixMapper::AMBIGUOUS_KEYS[$key])) {
                continue;
            }

            if ($suffixes !== null && ! array_key_exists($key, $suffixes)) {
                continue;
            }

            $tokenLower = Text::isLowerCase($token);

            if ($uniformUpper) {
                // an uppercase token is read as a credential and stripped; flag
                // it only when it plausibly collides with a real name (Do, Ma,
                // Ba... or a Census surname like Ii/Iv/Mba), since casing
                // carries no signal here. Clean creds (RN/PT/OD...) stay
                // unflagged to keep review noise down on all-caps datasets.
                if (isset(SuffixMapper::NAME_LEANING_KEYS[$key])
                    || isset(SuffixMapper::SURNAME_COLLIDING_KEYS[$key])) {
                    $notes[] = "'{$token}' could be a name or a credential; input casing is uniform";
                }
            } elseif ($uniformLower) {
                $notes[] = "'{$token}' could be a name or a credential; input casing is uniform";
            } elseif ($tokenLower) {
                $notes[] = "'{$token}' could be a name or a credential; token is lowercase";
            }
        }

        return ['ambiguous' => $notes !== [], 'notes' => $notes];
    }
}
