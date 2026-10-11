<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Text;

final class Formatter
{
    public function rebuild(string $input): Str
    {
        return new Str(text: $input);
    }
}
