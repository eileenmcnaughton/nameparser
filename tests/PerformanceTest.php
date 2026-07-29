<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\TestCase;

class PerformanceTest extends TestCase
{
    private const float MAX_SECONDS = 1.5;

    private const float MAX_SCALING_RATIO = 3.0;

    private const int MAX_INPUT_BYTES = 1024 * 1024;

    private const int MAX_INPUT_TOKENS = 65536;

    public function testCombinedInitialExpansionRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('AB ', $size) . 'Smith',
        );
    }

    public function testMultiwordSalutationMappingRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('the honorable ', $size) . 'John Smith',
        );
    }

    public function testSurnameFirstSalutationPeelingRemainsLinearAtBatchScale(): void
    {
        $this->assertLinearScaling(
            static fn(int $size): string => str_repeat('Dr ', $size) . 'Kim Jong',
            true,
        );
    }

    public function testNestedNicknameDepthRemainsBoundedAtBatchScale(): void
    {
        $elapsed = $this->parseSeconds(
            str_repeat('( ', 16000) . str_repeat(') ', 16000) . 'Smith',
        );

        $this->assertLessThan(0.2, $elapsed);
    }

    public function testCommaHeavyInputUsesBoundedWorkingMemory(): void
    {
        $input = 'Smith,' . str_repeat(',', 500000);
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        (new Parser())->parse($input);

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testRejectsInputOverByteBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A', self::MAX_INPUT_BYTES + 1));
    }

    public function testRejectsInputOverTokenBudget(): void
    {
        $this->expectException(\LengthException::class);

        (new Parser())->parse(str_repeat('A ', self::MAX_INPUT_TOKENS) . 'A');
    }

    /**
     * @param  callable(int): string  $input
     */
    private function assertLinearScaling(callable $input, bool $surnameFirst = false): void
    {
        $small = $this->parseSeconds($input(16000), $surnameFirst);
        $large = $this->parseSeconds($input(32000), $surnameFirst);

        $this->assertLessThan(self::MAX_SECONDS, $large);
        $this->assertLessThan(
            ($small * self::MAX_SCALING_RATIO) + 0.005,
            $large,
        );
    }

    private function parseSeconds(string $input, bool $surnameFirst = false): float
    {
        $started = hrtime(true);
        $parser = (new Parser())->setSurnameFirst($surnameFirst);
        $parser->parse($input)->toArray();

        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
