<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Text;

use function trim;

final class Normalizer
{
    public function normalize(Str $input): string
    {
        return trim($input->value());
    }
}
