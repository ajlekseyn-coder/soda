<?php

declare(strict_types=1);

namespace OwnerOpportunities;

// Deliberately attractive-looking smells: each operation is short and typed.
class Post
{
    private bool $archived = false;

    public bool $published = false;

    public bool $deleted = false;

    public function archive(): void
    {
        $this->archived = true;
    }

    public function title(): string
    {
        return 'post';
    }

    public function restore(): void
    {
        $this->archived = false;
    }

    public function isActive(): bool
    {
        return ! $this->archived;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }
}

class Comment
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

class Publisher
{
    public function publish(Post $post): void
    {
        $post->published = true;
    }
}

class Unpublisher
{
    public function unpublish(Post $post): void
    {
        $post->published = false;
    }
}

class PublicationChecker
{
    public function check(Post $post): bool
    {
        return $post->published && ! $post->deleted;
    }
}

function operate(Post $post, Publisher $publisher, Unpublisher $unpublisher, PublicationChecker $checker): void
{
    $publisher->publish($post);
    $unpublisher->unpublish($post);
    $checker->check($post);
}

function show(Post $post): bool
{
    return $post->isPublished() && ! $post->deleted;
}

function notify(Post $post): bool
{
    return $post->isPublished() && ! $post->deleted;
}

function index(Post $post): bool
{
    return $post->isPublished() && ! $post->deleted;
}

class Report
{
    private string $period;

    private string $format;

    public function setPeriod(string $period): void
    {
        $this->period = $period;
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    public function download(): string
    {
        return $this->period.':'.$this->format;
    }
}

function daily(string $period): string
{
    $report = new Report;
    $report->setPeriod($period);
    $report->setFormat('csv');

    return $report->download();
}

function monthly(string $period): string
{
    $report = new Report;
    $report->setPeriod($period);
    $report->setFormat('csv');

    return $report->download();
}

function annual(string $period): string
{
    $report = new Report;
    $report->setPeriod($period);
    $report->setFormat('json');

    return $report->download();
}
