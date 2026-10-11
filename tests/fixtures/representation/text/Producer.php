<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Text;

final class Producer
{
    public function load(string $input): Str
    {
        return new Str(text: $input);
    }
}
