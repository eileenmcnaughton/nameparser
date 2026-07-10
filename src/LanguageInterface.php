<?php

namespace Iliaal\NameParser;

interface LanguageInterface
{
    /**
     * Array keys are registry lookup keys and must already be in normalized
     * form (lowercase, periods removed, no edge punctuation) as produced by
     * AbstractMapper::getKey; values are the rendered output form.
     *
     * @return array<int|string, string>
     */
    public function getSuffixes(): array;

    /**
     * Array keys are registry lookup keys and must already be in normalized
     * form (lowercase, periods removed, no edge punctuation) as produced by
     * AbstractMapper::getKey; values are the rendered output form.
     *
     * @return array<int|string, string>
     */
    public function getLastnamePrefixes(): array;

    /**
     * Array keys are registry lookup keys and must already be in normalized
     * form (lowercase, periods removed, no edge punctuation) as produced by
     * AbstractMapper::getKey; values are the rendered output form.
     *
     * @return array<int|string, string>
     */
    public function getSalutations(): array;
}
