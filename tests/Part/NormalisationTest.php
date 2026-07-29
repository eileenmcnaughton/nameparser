<?php

namespace Tests\Iliaal\NameParser\Part;

use Iliaal\NameParser\Part\Firstname;
use Iliaal\NameParser\Part\Lastname;
use PHPUnit\Framework\TestCase;

class NormalisationTest extends TestCase
{
    public function testCamelcasingNormalizesUnicodeNames(): void
    {
        $part = new Lastname('McDonald');
        $this->assertEquals('McDonald', $part->normalize());

        $part = new Lastname('übel');
        $this->assertEquals('Übel', $part->normalize());

        $part = new Firstname('Anne-Marie');
        $this->assertEquals('Anne-Marie', $part->normalize());

        $part = new Firstname('etna');
        $this->assertEquals('Etna', $part->normalize());

        $part = new Firstname('thái');
        $this->assertEquals('Thái', $part->normalize());

        $part = new Lastname('nguyễn');
        $this->assertEquals('Nguyễn', $part->normalize());
    }

    public function testCamelcasingTreatsDecomposedAccentAsOneWord(): void
    {
        $decomposed = "Rene\u{0301}e";

        $this->assertSame($decomposed, (new Firstname($decomposed))->normalize());
        $this->assertSame($decomposed, (new Firstname("rene\u{0301}e"))->normalize());
    }
}
