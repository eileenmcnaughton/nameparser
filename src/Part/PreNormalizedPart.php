<?php

namespace Iliaal\NameParser\Part;

abstract class PreNormalizedPart extends AbstractPart
{
    protected string $normalized;

    public function __construct(string $value, ?string $normalized = null)
    {
        $this->normalized = $normalized ?? $value;

        parent::__construct($value);
    }

    #[\Override]
    public function normalize(): string
    {
        return $this->normalized;
    }
}
