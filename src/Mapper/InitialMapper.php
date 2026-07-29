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
    public const int MAX_COMBINED = 64;

    private ?bool $uniformUpperOverride = null;

    public function __construct(
        private int $combinedMax = 2,
        protected bool $matchLastPart = false,
    ) {
        if ($combinedMax < 0 || $combinedMax > self::MAX_COMBINED) {
            throw new \InvalidArgumentException(
                'Combined initials limit must be between 0 and ' . self::MAX_COMBINED,
            );
        }
    }

    public function getCombinedMax(): int
    {
        return $this->combinedMax;
    }

    public function matchesLastPart(): bool
    {
        return $this->matchLastPart;
    }

    /**
     * @internal Comma-pipeline whole-input casing signal. Always reset after
     * the parse; the mapper is memoized. Not part of the stable public API.
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
        $parts = $this->normalizeParts($parts);
        $last = count($parts) - 1;

        // Splitting an all-uppercase token into separate initials ("JM" -> J M)
        // reads the caps as "these are initials". Under uniform-uppercase input
        // (legacy/registry data) caps carry no signal, so the same heuristic
        // shreds two-letter given names ("JO" -> J O). Suppress the split there
        // and keep the token as a name, mirroring the casing-as-signal policy of
        // SuffixMapper.
        $splitCombined = ! $this->isUniformUpperContext($parts, $this->uniformUpperOverride);

        $mapped = [];

        foreach ($parts as $k => $part) {
            if ($part instanceof AbstractPart) {
                $mapped[] = $part;

                continue;
            }

            if (! $this->matchLastPart && $k === $last) {
                $mapped[] = $part;

                continue;
            }

            if ($splitCombined && mb_strtoupper($part, 'UTF-8') === $part) {
                $stripped = str_replace('.', '', $part);
                $length = Text::graphemeLength($stripped);

                // caseless scripts (CJK, Hebrew) are trivially "uppercase", so the
                // gate above passes for a 2-char given name like "李明". Only split
                // when the token carries genuine cased capitals, otherwise the name
                // is shredded into bogus initials.
                if (
                    $length > 1
                    && $length <= $this->combinedMax
                    && $stripped !== mb_strtolower($stripped, 'UTF-8')
                ) {
                    foreach (Text::graphemes($stripped) as $initial) {
                        $mapped[] = $this->isInitial($initial) ? new Initial($initial) : $initial;
                    }

                    continue;
                }
            }

            $mapped[] = $this->isInitial($part) ? new Initial($part) : $part;
        }

        return $mapped;
    }

    protected function isInitial(string $part): bool
    {
        // a caseless single character ("李") is a whole name, not an initial; an
        // initial is a genuinely cased letter ("É", "J"). Casing is the signal.
        if (Text::graphemeLength($part) === 1) {
            return Text::isCased($part);
        }

        return str_ends_with($part, '.')
            && Text::graphemeLength(substr($part, 0, -1)) === 1
            && Text::isCased($part);
    }
}
