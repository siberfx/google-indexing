<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Engines;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Siberfx\GoogleIndexing\Auth\ServiceAccountTokenProvider;
use Siberfx\GoogleIndexing\Contracts\Engine;
use Siberfx\GoogleIndexing\Data\SubmissionResult;
use Siberfx\GoogleIndexing\Enums\UrlAction;

/**
 * Google Indexing API (https://developers.google.com/search/apis/indexing-api/v3/using-api).
 */
final class GoogleIndexingApiEngine implements Engine
{
    public const SCOPE = 'https://www.googleapis.com/auth/indexing';

    /** Google resets the quota at midnight Pacific time. */
    private const QUOTA_TIMEZONE = 'America/Los_Angeles';

    /** @param  array<string, mixed>  $config  the full google-indexing config array */
    public function __construct(
        private readonly ServiceAccountTokenProvider $tokens,
        private readonly array $config,
        private readonly ?Cache $cache = null,
    ) {}

    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured();
    }

    /**
     * URLs left in today's quota, or null when no daily limit is configured.
     */
    public function remaining(): ?int
    {
        $limit = (int) ($this->config['google']['daily_limit'] ?? 200);

        if ($limit <= 0) {
            return null;
        }

        return max(0, $limit - (int) $this->cache?->get($this->quotaKey(), 0));
    }

    public function submit(array $urls, UrlAction $action = UrlAction::Updated): SubmissionResult
    {
        $urls = array_values(array_unique($urls));
        $deferred = [];
        $failed = [];
        $retryable = [];
        $accepted = [];

        $budget = $this->remaining();
        $limit = (int) ($this->config['google']['daily_limit'] ?? 200);

        if ($budget !== null && count($urls) > $budget) {
            foreach (array_slice($urls, $budget) as $over) {
                $deferred[$over] = "Daily limit of {$limit} reached; sent on the next run.";
            }

            $urls = array_slice($urls, 0, $budget);
        }

        if ($urls === []) {
            return new SubmissionResult($this->name(), deferred: $deferred);
        }

        $token = $this->tokens->token([self::SCOPE]);
        $endpoint = $this->config['google']['publish_endpoint'];
        $timeout = (int) ($this->config['http']['timeout'] ?? 15);
        $concurrency = max(1, (int) ($this->config['google']['concurrency'] ?? 10));

        foreach (array_chunk($urls, $concurrency) as $chunk) {
            $this->consumeQuota(count($chunk));

            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $url) => $pool->as($url)
                    ->withToken($token)
                    ->timeout($timeout)
                    ->post($endpoint, ['url' => $url, 'type' => $action->value]),
                $chunk,
            ));

            $pause = 0;

            foreach ($chunk as $url) {
                $response = $responses[$url] ?? null;

                if ($response instanceof Response && $response->successful()) {
                    $accepted[] = $url;

                    continue;
                }

                $failed[$url] = $response instanceof Response
                    ? (string) ($response->json('error.message') ?? "HTTP {$response->status()}")
                    : 'Request failed.';

                if ($this->isTemporary($response)) {
                    $retryable[] = $url;
                }

                if ($response instanceof Response && $response->status() === 429) {
                    $pause = max($pause, (int) $response->header('Retry-After'));
                }
            }

            // Rate limited: honour Retry-After (capped) before the next chunk.
            if ($pause > 0) {
                Sleep::for(min($pause, (int) ($this->config['http']['max_backoff'] ?? 30)))->seconds();
            }
        }

        return new SubmissionResult($this->name(), $accepted, $failed, retryable: $retryable, deferred: $deferred);
    }

    /**
     * Ask Google when it last received a notification for the URL.
     *
     * @return array<string, mixed>
     */
    public function metadata(string $url): array
    {
        $response = Http::withToken($this->tokens->token([self::SCOPE]))
            ->timeout((int) ($this->config['http']['timeout'] ?? 15))
            ->get($this->config['google']['metadata_endpoint'], ['url' => $url]);

        return $response->json() ?? ['error' => ['message' => "HTTP {$response->status()}"]];
    }

    private function isTemporary(mixed $response): bool
    {
        return ! $response instanceof Response || $response->status() === 429 || $response->serverError();
    }

    private function consumeQuota(int $count): void
    {
        if ($this->cache === null || (int) ($this->config['google']['daily_limit'] ?? 200) <= 0) {
            return;
        }

        $key = $this->quotaKey();

        $this->cache->add($key, 0, $this->secondsUntilReset());
        $this->cache->increment($key, $count);
    }

    private function quotaKey(): string
    {
        return 'google-indexing.quota.'.CarbonImmutable::now(self::QUOTA_TIMEZONE)->format('Y-m-d');
    }

    private function secondsUntilReset(): int
    {
        $now = CarbonImmutable::now(self::QUOTA_TIMEZONE);

        return (int) abs($now->diffInSeconds($now->endOfDay())) + 1;
    }
}
