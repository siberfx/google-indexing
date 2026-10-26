<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static list<\Siberfx\GoogleIndexing\Data\SubmissionResult> publish(string|\Stringable|iterable $urls, ?array $only = null)
 * @method static list<\Siberfx\GoogleIndexing\Data\SubmissionResult> delete(string|\Stringable|iterable $urls, ?array $only = null)
 * @method static list<\Siberfx\GoogleIndexing\Data\SubmissionResult> submit(string|\Stringable|iterable $urls, \Siberfx\GoogleIndexing\Enums\UrlAction $action = \Siberfx\GoogleIndexing\Enums\UrlAction::Updated, ?array $only = null)
 * @method static void queue(string|\Stringable|iterable $urls, \Siberfx\GoogleIndexing\Enums\UrlAction $action = \Siberfx\GoogleIndexing\Enums\UrlAction::Updated, ?array $only = null)
 * @method static list<\Siberfx\GoogleIndexing\Data\SubmissionResult> submitSitemap(?string $sitemap = null, \Carbon\CarbonInterface|string|null $since = null, ?array $only = null, bool $queue = false)
 * @method static list<string> sitemapUrls(?string $sitemap = null, \Carbon\CarbonInterface|string|null $since = null)
 * @method static array status(string $url)
 * @method static \Siberfx\GoogleIndexing\Contracts\Engine engine(string $name)
 * @method static string sitemapLocation()
 *
 * @see \Siberfx\GoogleIndexing\GoogleIndexing
 */
final class GoogleIndexing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Siberfx\GoogleIndexing\GoogleIndexing::class;
    }
}
