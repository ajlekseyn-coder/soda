<?php

declare(strict_types=1);

namespace OwnerRefactored;

trait Archivable
{
    private bool $archived = false;

    public function archive(): void
    {
        $this->archived = true;
    }

    public function restore(): void
    {
        $this->archived = false;
    }

    public function isActive(): bool
    {
        return ! $this->archived;
    }
}

trait Publishable
{
    public bool $published = false;

    public bool $deleted = false;

    public function publish(): void
    {
        $this->published = true;
    }

    public function unpublish(): void
    {
        $this->published = false;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->published && ! $this->deleted;
    }
}

class Post
{
    use Archivable;
    use Publishable;

    public function title(): string
    {
        return 'post';
    }
}

class Comment
{
    use Archivable;
}

class Report
{
    private string $format = 'csv';

    private function __construct(private readonly string $period) {}

    public static function for(string $period): self
    {
        return new self($period);
    }

    public function asCsv(): self
    {
        $this->format = 'csv';

        return $this;
    }

    public function asJson(): self
    {
        $this->format = 'json';

        return $this;
    }

    public function download(): string
    {
        return $this->period.':'.$this->format;
    }
}

function daily(string $period): string
{
    return Report::for($period)->asCsv()->download();
}

function monthly(string $period): string
{
    return Report::for($period)->asCsv()->download();
}

function annual(string $period): string
{
    return Report::for($period)->asJson()->download();
}
