<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Collection;

final readonly class Bag
{
    /** @param array<string, int> $items */
    public function __construct(private array $items) {}

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->items;
    }
}
