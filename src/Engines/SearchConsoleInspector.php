<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Engines;

use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Auth\ServiceAccountTokenProvider;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;

/**
 * Search Console URL Inspection API: whether Google has actually indexed a page,
 * unlike the Indexing API metadata which only knows about URLs submitted through it.
 * https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect
 */
final class SearchConsoleInspector
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public const ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    /** @param  array<string, mixed>  $config  the full google-indexing config array */
    public function __construct(
        private readonly ServiceAccountTokenProvider $tokens,
        private readonly array $config,
    ) {}

    /**
     * @return array{url: string, indexed: bool, verdict: ?string, coverage: ?string, last_crawl: ?string, robots: ?string, page_fetch: ?string, error: ?string}
     */
    public function inspect(string $url): array
    {
        $response = Http::withToken($this->tokens->token([self::SCOPE]))
            ->timeout((int) ($this->config['http']['timeout'] ?? 15))
            ->post(self::ENDPOINT, [
                'inspectionUrl' => $url,
                'siteUrl' => $this->siteUrl(),
            ]);

        if ($response->failed()) {
            throw GoogleIndexingException::requestFailed((string) ($response->json('error.message') ?? "HTTP {$response->status()}"));
        }

        $index = $response->json('inspectionResult.indexStatusResult') ?? [];
        $verdict = $index['verdict'] ?? null;

        return [
            'url' => $url,
            'indexed' => $verdict === 'PASS',
            'verdict' => $verdict,
            'coverage' => $index['coverageState'] ?? null,
            'last_crawl' => $index['lastCrawlTime'] ?? null,
            'robots' => $index['robotsTxtState'] ?? null,
            'page_fetch' => $index['pageFetchState'] ?? null,
            'error' => null,
        ];
    }

    public function siteUrl(): string
    {
        return (string) ($this->config['google']['site_url'] ?: rtrim((string) config('app.url'), '/').'/');
    }
}
