<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Collection;

final class Assembler
{
    public function assemble(array $input): Bag
    {
        return new Bag(items: $input);
    }
}
