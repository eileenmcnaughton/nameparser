<?php

namespace Iliaal\NameParser\Mapper;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Initial;
use Iliaal\NameParser\Text;

/**
 * single letter, possibly followed by a period
 *
 * @phpstan-import-type PartArray from AbstractMapper
 */
class InitialMapper extends AbstractMapper
{
    private ?bool $uniformUpperOverride = null;

    public function __construct(
        private int $combinedMax = 2,
        protected bool $matchLastPart = false,
    ) {}

    /**
     * force the uniform-uppercase verdict instead of deriving it from the
     * local part list. The comma pipeline sets this so the split gate reads the
     * whole-input casing signal, not just the given segment ("Smith, JM" must
     * see that "Smith" proves the input is mixed-case). Null restores
     * self-derivation. Always reset after the parse; the mapper is memoized.
     */
    public function setUniformUpperOverride(?bool $override): void
    {
        $this->uniformUpperOverride = $override;
    }

    /**
     * @param  PartArray  $parts
     * @return PartArray
     */
    #[\Override]
    public function map(array $parts): array
    {
        $last = count($parts) - 1;

        // Splitting an all-uppercase token into separate initials ("JM" -> J M)
        // reads the caps as "these are initials". Under uniform-uppercase input
        // (legacy/registry data) caps carry no signal, so the same heuristic
        // shreds two-letter given names ("JO" -> J O). Suppress the split there
        // and keep the token as a name, mirroring the casing-as-signal policy of
        // SuffixMapper.
        $splitCombined = ! $this->isUniformUpperContext($parts);

        for ($k = 0; $k < count($parts); $k++) {
            $part = $parts[$k];

            if ($part instanceof AbstractPart) {
                continue;
            }

            if (! $this->matchLastPart && $k === $last) {
                continue;
            }

            if ($splitCombined && mb_strtoupper($part, 'UTF-8') === $part) {
                $stripped = str_replace('.', '', $part);
                $length = mb_strlen($stripped, 'UTF-8');

                // caseless scripts (CJK, Hebrew) are trivially "uppercase", so the
                // gate above passes for a 2-char given name like "李明". Only split
                // when the token carries genuine cased capitals, otherwise the name
                // is shredded into bogus initials.
                if (
                    $length > 1
                    && $length <= $this->combinedMax
                    && $stripped !== mb_strtolower($stripped, 'UTF-8')
                ) {
                    array_splice($parts, $k, 1, mb_str_split($stripped, 1, 'UTF-8'));
                    $last = count($parts) - 1;
                    $part = $parts[$k];
                }
            }

            if (is_string($part) && $this->isInitial($part)) {
                $parts[$k] = new Initial($part);
            }
        }

        return $parts;
    }

    protected function isInitial(string $part): bool
    {
        $length = mb_strlen($part, 'UTF-8');

        // a caseless single character ("李") is a whole name, not an initial; an
        // initial is a genuinely cased letter ("É", "J"). Casing is the signal.
        if ($length === 1) {
            return $this->isCased($part);
        }

        return $length === 2 && str_ends_with($part, '.') && $this->isCased($part);
    }

    /**
     * true when the token's letters have a distinct upper/lower form, i.e. they
     * carry a case signal (Latin, Greek, Cyrillic) rather than a caseless script
     * (Han, Hebrew, Arabic).
     */
    private function isCased(string $part): bool
    {
        $letters = Text::letters($part);

        return $letters !== ''
            && mb_strtolower($letters, 'UTF-8') !== mb_strtoupper($letters, 'UTF-8');
    }

    /**
     * true when every unmapped cased token is uppercase and none carries a
     * lowercase letter, i.e. the input casing gives no signal (all-caps registry
     * data). Already-mapped salutations and suffixes are ignored because their
     * normalized values may differ from the original token casing.
     *
     * @param  PartArray  $parts
     */
    private function isUniformUpperContext(array $parts): bool
    {
        if ($this->uniformUpperOverride !== null) {
            return $this->uniformUpperOverride;
        }

        $hasUpper = false;

        foreach ($parts as $part) {
            if ($part instanceof AbstractPart) {
                continue;
            }

            $letters = Text::letters($part);

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
}
