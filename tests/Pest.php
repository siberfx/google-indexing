<?php

declare(strict_types=1);

use Siberfx\GoogleIndexing\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/** @param array<int|string, string> $urls  loc => lastmod, or a bare loc */
function sitemapXml(array $urls): string
{
    $items = collect($urls)->map(fn ($lastmod, $loc) => is_int($loc)
        ? "<url><loc>{$lastmod}</loc></url>"
        : "<url><loc>{$loc}</loc><lastmod>{$lastmod}</lastmod></url>")->implode('');

    return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$items.'</urlset>';
}
