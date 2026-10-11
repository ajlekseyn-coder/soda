<?php

declare(strict_types=1);

namespace SodaRepresentationFixture\Text;

final readonly class Pipeline
{
    public function __construct(private Producer $producer, private Normalizer $normalizer, private Cleaner $cleaner, private Formatter $formatter) {}

    public function handle(string $input): Str
    {
        $text = $this->producer->load(input: $input);
        $normalized = $this->normalizer->normalize(input: $text);
        $cleaned = $this->cleaner->clean(input: $normalized);

        return $this->formatter->rebuild(input: $cleaned);
    }
}
