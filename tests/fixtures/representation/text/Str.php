<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Text;

final readonly class Str
{
    public function __construct(private string $text) {}

    public function value(): string
    {
        return $this->text;
    }
}
