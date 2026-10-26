<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Engines;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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

    /** @param  array<string, mixed>  $config  the full google-indexing config array */
    public function __construct(
        private readonly ServiceAccountTokenProvider $tokens,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured();
    }

    public function submit(array $urls, UrlAction $action = UrlAction::Updated): SubmissionResult
    {
        $urls = array_values(array_unique($urls));
        $limit = (int) ($this->config['google']['daily_limit'] ?? 200);
        $failed = [];

        if ($limit > 0 && count($urls) > $limit) {
            foreach (array_slice($urls, $limit) as $over) {
                $failed[$over] = "Skipped: limit of {$limit} URLs per run reached.";
            }
            $urls = array_slice($urls, 0, $limit);
        }

        $token = $this->tokens->token([self::SCOPE]);
        $endpoint = $this->config['google']['publish_endpoint'];
        $timeout = (int) ($this->config['http']['timeout'] ?? 15);
        $accepted = [];

        foreach (array_chunk($urls, max(1, (int) ($this->config['google']['concurrency'] ?? 10))) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $url) => $pool->as($url)
                    ->withToken($token)
                    ->timeout($timeout)
                    ->post($endpoint, ['url' => $url, 'type' => $action->value]),
                $chunk,
            ));

            foreach ($chunk as $url) {
                $response = $responses[$url] ?? null;

                if ($response instanceof Response && $response->successful()) {
                    $accepted[] = $url;
                } else {
                    $failed[$url] = $response instanceof Response
                        ? (string) ($response->json('error.message') ?? "HTTP {$response->status()}")
                        : 'Request failed.';
                }
            }
        }

        return new SubmissionResult($this->name(), $accepted, $failed);
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
}
