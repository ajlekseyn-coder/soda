<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Collection;

use function array_reverse;

final class Processor
{
    public function process(array $input): array
    {
        return array_reverse($input, preserve_keys: true);
    }
}
