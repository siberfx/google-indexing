<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Siberfx\GoogleIndexing\Contracts\Engine;
use Siberfx\GoogleIndexing\Data\SitemapEntry;
use Siberfx\GoogleIndexing\Data\SubmissionResult;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\SearchConsoleSitemapEngine;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\Events\UrlsSubmitted;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\Jobs\SubmitUrlsJob;
use Siberfx\GoogleIndexing\Sitemap\SitemapReader;
use Stringable;

final class GoogleIndexing
{
    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly Container $app,
        private readonly array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    /**
     * Notify engines that URL(s) were created or changed.
     *
     * @param  string|Stringable|iterable<string|Stringable>  $urls
     * @param  list<string>|null  $only  limit to engine names, e.g. ['indexnow']
     * @return list<SubmissionResult>
     */
    public function publish(string|Stringable|iterable $urls, ?array $only = null): array
    {
        return $this->submit($urls, UrlAction::Updated, $only);
    }

    /**
     * Notify engines that URL(s) were removed.
     *
     * @param  string|Stringable|iterable<string|Stringable>  $urls
     * @param  list<string>|null  $only
     * @return list<SubmissionResult>
     */
    public function delete(string|Stringable|iterable $urls, ?array $only = null): array
    {
        return $this->submit($urls, UrlAction::Deleted, $only);
    }

    /**
     * Synchronously submit URLs to every active engine.
     *
     * @param  string|Stringable|iterable<string|Stringable>  $urls
     * @param  list<string>|null  $only
     * @return list<SubmissionResult>
     */
    public function submit(string|Stringable|iterable $urls, UrlAction $action = UrlAction::Updated, ?array $only = null): array
    {
        $urls = $this->normalize($urls);

        if ($urls === [] || ! $this->enabled()) {
            return [];
        }

        $results = [];

        foreach ($this->activeEngineNames($only) as $name) {
            $engine = $this->engine($name);

            $result = $engine->isConfigured()
                ? $engine->submit($urls, $action)
                : SubmissionResult::skipped($name, 'Engine is not configured.');

            event(new UrlsSubmitted($result, $action));
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Same as submit() but handled by the queue (recommended in request cycles).
     *
     * @param  string|Stringable|iterable<string|Stringable>  $urls
     * @param  list<string>|null  $only
     */
    public function queue(string|Stringable|iterable $urls, UrlAction $action = UrlAction::Updated, ?array $only = null): void
    {
        $urls = $this->normalize($urls);

        if ($urls === [] || ! $this->enabled()) {
            return;
        }

        $this->app->make(Dispatcher::class)->dispatch(new SubmitUrlsJob($urls, $action, $only));
    }

    /**
     * Read the sitemap and submit its URLs, plus the sitemap file itself to
     * Google Search Console (when credentials are present).
     *
     * @param  string|null  $sitemap  defaults to config / {APP_URL}/sitemap.xml
     * @param  CarbonInterface|string|null  $since  only URLs with <lastmod> newer than this ("1 day" works)
     * @param  list<string>|null  $only
     * @return list<SubmissionResult>
     */
    public function submitSitemap(?string $sitemap = null, CarbonInterface|string|null $since = null, ?array $only = null, bool $queue = false): array
    {
        $urls = $this->sitemapUrls($sitemap, $since);
        $sitemap ??= $this->sitemapLocation();
        $results = [];

        if ($queue) {
            $this->queue($urls, UrlAction::Updated, $only);
        } else {
            $results = $this->submit($urls, UrlAction::Updated, $only);
        }

        if ($this->enabled() && ($only === null || in_array('google', $only, true)) && in_array('google', $this->config['use'], true)) {
            $console = $this->app->make(SearchConsoleSitemapEngine::class);

            if ($console->isConfigured() && preg_match('#^https?://#i', $sitemap)) {
                $results[] = $console->submit($sitemap);
            }
        }

        return $results;
    }

    /**
     * URLs found in the sitemap, optionally filtered by <lastmod>.
     *
     * @return list<string>
     */
    public function sitemapUrls(?string $sitemap = null, CarbonInterface|string|null $since = null): array
    {
        $sitemap ??= $this->sitemapLocation();
        $since ??= $this->config['sitemap']['only_changed_within'] ?? null;

        $threshold = match (true) {
            $since instanceof CarbonInterface => $since,
            is_string($since) && $since !== '' => CarbonImmutable::now()->sub($since),
            default => null,
        };

        $reader = new SitemapReader(
            maxDepth: (int) $this->config['sitemap']['max_depth'],
            maxUrls: (int) $this->config['sitemap']['max_urls'],
            timeout: (int) $this->config['http']['timeout'],
            userAgent: (string) $this->config['http']['user_agent'],
        );

        return array_values(array_map(
            fn (SitemapEntry $e) => $e->loc,
            array_filter(
                $reader->read($sitemap),
                // URLs without <lastmod> are kept: we cannot prove they are unchanged.
                fn (SitemapEntry $e) => $threshold === null || $e->lastmod === null || $e->lastmod >= $threshold,
            ),
        ));
    }

    /**
     * Google's last known notification metadata for a URL.
     *
     * @return array<string, mixed>
     */
    public function status(string $url): array
    {
        return $this->app->make(GoogleIndexingApiEngine::class)->metadata($url);
    }

    public function engine(string $name): Engine
    {
        $class = $this->config['engines'][$name] ?? throw GoogleIndexingException::unknownEngine($name);

        return $this->app->make($class);
    }

    public function sitemapLocation(): string
    {
        return $this->config['sitemap']['url'] ?: rtrim((string) config('app.url'), '/').'/sitemap.xml';
    }

    /**
     * @param  list<string>|null  $only
     * @return list<string>
     */
    private function activeEngineNames(?array $only): array
    {
        $names = array_values(array_filter($this->config['use'], fn (string $n) => $only === null || in_array($n, $only, true)));

        return $names;
    }

    /**
     * @param  string|Stringable|iterable<string|Stringable>  $urls
     * @return list<string>
     */
    private function normalize(string|Stringable|iterable $urls): array
    {
        $urls = is_iterable($urls) ? $urls : [$urls];
        $out = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);

            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $out[$url] = $url;
            }
        }

        return array_values($out);
    }
}
