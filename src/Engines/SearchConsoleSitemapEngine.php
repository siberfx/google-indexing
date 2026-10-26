<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Engines;

use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Auth\ServiceAccountTokenProvider;
use Siberfx\GoogleIndexing\Data\SubmissionResult;

/**
 * Submits the sitemap *file itself* to Google through the Search Console API.
 * This is the supported replacement for the retired `google.com/ping?sitemap=` endpoint.
 */
final class SearchConsoleSitemapEngine
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters';

    /** @param  array<string, mixed>  $config  the full google-indexing config array */
    public function __construct(
        private readonly ServiceAccountTokenProvider $tokens,
        private readonly array $config,
    ) {}

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured();
    }

    public function submit(string $sitemapUrl): SubmissionResult
    {
        $site = (string) ($this->config['google']['site_url'] ?: rtrim((string) config('app.url'), '/').'/');

        // The endpoint takes no body. Laravel's `put()` would send a JSON `[]`, which Google rejects
        // with "Invalid JSON payload received. Unknown name "": Root element must be a message".
        $response = Http::withToken($this->tokens->token([self::SCOPE]))
            ->withBody('', 'application/x-www-form-urlencoded')
            ->timeout((int) ($this->config['http']['timeout'] ?? 15))
            ->put(sprintf(
                '%s/%s/sitemaps/%s',
                rtrim($this->config['google']['sitemaps_endpoint'], '/'),
                rawurlencode($site),
                rawurlencode($sitemapUrl),
            ));

        return $response->successful()
            ? new SubmissionResult('google-sitemap', [$sitemapUrl])
            : new SubmissionResult('google-sitemap', [], [
                $sitemapUrl => (string) ($response->json('error.message') ?? "HTTP {$response->status()}"),
            ]);
    }
}
