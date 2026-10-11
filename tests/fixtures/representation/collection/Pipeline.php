<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Collection;

final class Pipeline
{
    public function run(): Bag
    {
        $bag = new Bag(items: ['first' => 10, 'second' => 20]);
        $extractor = new Extractor();
        $processor = new Processor();
        $assembler = new Assembler();
        $items = $extractor->extract($bag);
        $processed = $processor->process($items);

        return $assembler->assemble($processed);
    }
}
