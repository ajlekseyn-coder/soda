<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Collection;

final class Extractor
{
    public function extract(Bag $input): array
    {
        return $input->all();
    }
}
