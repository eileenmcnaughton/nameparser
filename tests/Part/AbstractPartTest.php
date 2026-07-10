<?php

namespace Tests\Iliaal\NameParser\Part;

use Iliaal\NameParser\Part\AbstractPart;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\TestCase;

class AbstractPartTest extends TestCase
{
    public function testCamelcaseCacheIsInvalidatedOnSetValue(): void
    {
        $part = new Lastname('mcdonald');
        $this->assertEquals('Mcdonald', $part->normalize());

        $part->setValue('van der berg');
        $this->assertEquals('Van Der Berg', $part->normalize());
    }

    public function testNormalize(): void
    {
        $part = new class ('abc') extends AbstractPart {};
        $this->assertEquals('abc', $part->normalize());
    }

    public function testSetValueUnwraps(): void
    {
        $part = new class ('abc') extends AbstractPart {};
        $this->assertEquals('abc', $part->getValue());

        $wrapped = new class ($part) extends AbstractPart {};
        $this->assertEquals('abc', $wrapped->getValue());
    }
}
