<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Siberfx\GoogleIndexing\Contracts\Engine;
use Siberfx\GoogleIndexing\Data\SitemapEntry;
use Siberfx\GoogleIndexing\Data\SubmissionResult;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\SearchConsoleInspector;
use Siberfx\GoogleIndexing\Engines\SearchConsoleSitemapEngine;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\Events\UrlsSubmitted;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\Jobs\SubmitUrlsJob;
use Siberfx\GoogleIndexing\Sitemap\SitemapReader;
use Stringable;
use Throwable;

final class GoogleIndexing
{
    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly Container $app,
        private readonly array $config,
        private readonly ?Cache $cache = null,
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
     * Synchronously submit URLs to every active engine. An engine that throws
     * does not stop the others: its URLs are reported as failed instead.
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
            $results[] = $this->runEngine($name, $urls, $action);
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
     * URLs already submitted and not modified since are skipped (see `sitemap.dedupe`).
     *
     * @param  string|null  $sitemap  defaults to config / {APP_URL}/sitemap.xml
     * @param  CarbonInterface|string|null  $since  only URLs with <lastmod> newer than this ("1 day" works)
     * @param  list<string>|null  $only
     * @return list<SubmissionResult>
     */
    public function submitSitemap(?string $sitemap = null, CarbonInterface|string|null $since = null, ?array $only = null, bool $queue = false): array
    {
        $sitemap ??= $this->sitemapLocation();
        $urls = $this->pendingUrls($this->sitemapEntries($sitemap, $since));
        $results = [];

        if ($urls !== []) {
            if ($queue) {
                $this->queue($urls, UrlAction::Updated, $only);
                $this->remember($urls);
            } else {
                $results = $this->submit($urls, UrlAction::Updated, $only);
                $this->remember($this->acceptedWithoutFailures($results));
            }
        }

        if ($this->enabled() && ($only === null || in_array('google', $only, true)) && in_array('google', $this->config['use'], true)) {
            $console = $this->app->make(SearchConsoleSitemapEngine::class);

            if ($console->isConfigured() && preg_match('#^https?://#i', $sitemap)) {
                $results[] = $this->guard('google-sitemap', [$sitemap], fn () => $console->submit($sitemap));
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

        return array_map(fn (SitemapEntry $e) => $e->loc, $this->sitemapEntries($sitemap, $since));
    }

    /**
     * Google's last known notification metadata for a URL (Indexing API).
     *
     * @return array<string, mixed>
     */
    public function status(string $url): array
    {
        return $this->app->make(GoogleIndexingApiEngine::class)->metadata($url);
    }

    /**
     * Whether Google has actually indexed the URL (Search Console URL Inspection).
     *
     * @return array{url: string, indexed: bool, verdict: ?string, coverage: ?string, last_crawl: ?string, robots: ?string, page_fetch: ?string, error: ?string}
     */
    public function inspect(string $url): array
    {
        return $this->app->make(SearchConsoleInspector::class)->inspect($url);
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
     * @param  list<string>  $urls
     */
    private function runEngine(string $name, array $urls, UrlAction $action): SubmissionResult
    {
        return $this->guard($name, $urls, function () use ($name, $urls, $action) {
            $engine = $this->engine($name);

            return $engine->isConfigured()
                ? $engine->submit($urls, $action)
                : SubmissionResult::skipped($name, 'Engine is not configured.');
        }, $action);
    }

    /**
     * Turns an engine exception into a failed result so one engine cannot stop the others.
     *
     * @param  list<string>  $urls
     * @param  callable(): SubmissionResult  $run
     */
    private function guard(string $name, array $urls, callable $run, UrlAction $action = UrlAction::Updated): SubmissionResult
    {
        try {
            $result = $run();
        } catch (Throwable $e) {
            report($e);

            $result = new SubmissionResult($name, failed: array_fill_keys($urls, $e->getMessage()));
        }

        event(new UrlsSubmitted($result, $action));

        return $result;
    }

    /**
     * Sitemap entries after the optional <lastmod> filter.
     *
     * @return list<SitemapEntry>
     */
    private function sitemapEntries(string $sitemap, CarbonInterface|string|null $since): array
    {
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

        return array_values(array_filter(
            $reader->read($sitemap),
            // URLs without <lastmod> are kept: we cannot prove they are unchanged.
            fn (SitemapEntry $e) => $threshold === null || $e->lastmod === null || $e->lastmod >= $threshold,
        ));
    }

    /**
     * Drops URLs we already submitted and that have not changed since (sitemap.dedupe).
     *
     * @param  list<SitemapEntry>  $entries
     * @return list<string>
     */
    private function pendingUrls(array $entries): array
    {
        if (! $this->dedupeEnabled() || $entries === []) {
            return array_map(fn (SitemapEntry $e) => $e->loc, $entries);
        }

        $keys = array_map(fn (SitemapEntry $e) => $this->rememberKey($e->loc), $entries);
        $recorded = $this->cache->many($keys);
        $pending = [];

        foreach ($entries as $i => $entry) {
            $submittedAt = $recorded[$keys[$i]] ?? null;

            // Never submitted, or changed after our last submission: send it.
            if ($submittedAt === null || ($entry->lastmod !== null && $entry->lastmod > new DateTimeImmutable($submittedAt))) {
                $pending[] = $entry->loc;
            }
        }

        return $pending;
    }

    /** @param  list<string>  $urls */
    private function remember(array $urls): void
    {
        if ($urls === [] || ! $this->dedupeEnabled()) {
            return;
        }

        $now = CarbonImmutable::now()->toIso8601String();
        $values = [];

        foreach ($urls as $url) {
            $values[$this->rememberKey($url)] = $now;
        }

        $this->cache->putMany($values, $this->resubmitSeconds());
    }

    /**
     * URLs at least one engine accepted and no engine reported as failed.
     *
     * @param  list<SubmissionResult>  $results
     * @return list<string>
     */
    private function acceptedWithoutFailures(array $results): array
    {
        $accepted = [];
        $failed = [];

        foreach ($results as $result) {
            array_push($accepted, ...$result->accepted);
            $failed = [...$failed, ...array_keys($result->failed)];
        }

        return array_values(array_diff(array_unique($accepted), $failed));
    }

    private function dedupeEnabled(): bool
    {
        return $this->cache !== null && (bool) ($this->config['sitemap']['dedupe'] ?? true);
    }

    private function rememberKey(string $url): string
    {
        return 'google-indexing.submitted.'.sha1($url);
    }

    private function resubmitSeconds(): int
    {
        $after = (string) ($this->config['sitemap']['resubmit_after'] ?? '30 days');
        $now = CarbonImmutable::now();

        return (int) abs($now->diffInSeconds($now->sub($after)));
    }

    /**
     * @param  list<string>|null  $only
     * @return list<string>
     */
    private function activeEngineNames(?array $only): array
    {
        return array_values(array_filter($this->config['use'], fn (string $n) => $only === null || in_array($n, $only, true)));
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
