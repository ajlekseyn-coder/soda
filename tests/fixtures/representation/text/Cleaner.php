<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Text;

use function str_replace;

final class Cleaner
{
    public function clean(string $input): string
    {
        return str_replace('obsolete', '', $input);
    }
}
