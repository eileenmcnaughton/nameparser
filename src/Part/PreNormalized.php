<?php

namespace Iliaal\NameParser\Part;

/**
 * shared implementation for parts that carry a pre-normalized dictionary form
 * decided at map time, rather than deriving one via camelcase at render time
 */
trait PreNormalized
{
    /**
     * the dictionary form to render, or the raw value when none was supplied
     */
    protected string $normalized;

    public function __construct(string $value, ?string $normalized = null)
    {
        $this->normalized = $normalized ?? $value;

        parent::__construct($value);
    }

    /**
     * return the supplied dictionary form, falling back to the raw value
     * verbatim when none was given
     */
    #[\Override]
    public function normalize(): string
    {
        return $this->normalized;
    }
}
