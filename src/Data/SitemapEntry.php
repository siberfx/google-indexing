<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Data;

use DateTimeImmutable;
use Throwable;

final readonly class SitemapEntry
{
    public function __construct(
        public string $loc,
        public ?DateTimeImmutable $lastmod = null,
    ) {}

    public static function make(string $loc, ?string $lastmod = null): self
    {
        try {
            $date = $lastmod !== null && $lastmod !== '' ? new DateTimeImmutable($lastmod) : null;
        } catch (Throwable) {
            $date = null;
        }

        return new self(trim($loc), $date);
    }
}
