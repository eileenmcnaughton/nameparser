<?php

namespace Tests\Iliaal\NameParser;

use Iliaal\NameParser\Parser;
use PHPUnit\Framework\TestCase;

class PerformanceTest extends TestCase
{
    private const float MAX_SECONDS = 1.5;

    public function testCombinedInitialExpansionRemainsLinearAtBatchScale(): void
    {
        $elapsed = $this->parseSeconds(str_repeat('AB ', 32000) . 'Smith');

        $this->assertLessThan(self::MAX_SECONDS, $elapsed);
    }

    public function testMultiwordSalutationMappingRemainsLinearAtBatchScale(): void
    {
        $elapsed = $this->parseSeconds(str_repeat('the honorable ', 32000) . 'John Smith');

        $this->assertLessThan(self::MAX_SECONDS, $elapsed);
    }

    public function testSurnameFirstSalutationPeelingRemainsLinearAtBatchScale(): void
    {
        $parser = (new Parser())->setSurnameFirst(true);
        $started = hrtime(true);
        $parser->parse(str_repeat('Dr ', 32000) . 'Kim Jong');
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertLessThan(self::MAX_SECONDS, $elapsed);
    }

    public function testCommaHeavyInputUsesBoundedWorkingMemory(): void
    {
        $input = 'Smith,' . str_repeat(',', 500000);
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        (new Parser())->parse($input);

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    private function parseSeconds(string $input): float
    {
        $started = hrtime(true);
        (new Parser())->parse($input);

        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
