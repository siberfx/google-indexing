<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\Sitemap\SitemapReader;

it('reads urls and lastmod', function () {
    Http::fake(['example.com/sitemap.xml' => Http::response(sitemapXml(['https://example.com/a' => '2026-01-01', 'https://example.com/b']))]);

    $entries = (new SitemapReader)->read('https://example.com/sitemap.xml');

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->loc)->toBe('https://example.com/a')
        ->and($entries[0]->lastmod?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($entries[1]->lastmod)->toBeNull();
});

it('follows sitemap indexes, gz files and ignores loops', function () {
    $index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .'<sitemap><loc>https://example.com/s1.xml.gz</loc></sitemap>'
        .'<sitemap><loc>https://example.com/sitemap.xml</loc></sitemap></sitemapindex>';

    Http::fake([
        'example.com/sitemap.xml' => Http::response($index),
        'example.com/s1.xml.gz' => Http::response(gzencode(sitemapXml(['https://example.com/gz']))),
    ]);

    $urls = array_map(fn ($e) => $e->loc, (new SitemapReader)->read('https://example.com/sitemap.xml'));

    expect($urls)->toBe(['https://example.com/gz']);
});

it('throws on invalid xml', function () {
    Http::fake(['*' => Http::response('nope')]);

    (new SitemapReader)->read('https://example.com/sitemap.xml');
})->throws(GoogleIndexingException::class);
