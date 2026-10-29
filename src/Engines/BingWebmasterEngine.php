<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Engines;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Siberfx\GoogleIndexing\Contracts\Engine;
use Siberfx\GoogleIndexing\Data\SubmissionResult;
use Siberfx\GoogleIndexing\Enums\UrlAction;

/**
 * Bing Webmaster Tools URL submission (API key from Bing Webmaster Tools → Settings → API access).
 * Bing has no removal action: a deleted page only drops out once it answers 404 or 410.
 */
final class BingWebmasterEngine implements Engine
{
    /** @param  array<string, mixed>  $config  the full google-indexing config array */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'bing';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['bing']['key'] ?? null);
    }

    public function submit(array $urls, UrlAction $action = UrlAction::Updated): SubmissionResult
    {
        if ($action === UrlAction::Deleted) {
            return new SubmissionResult($this->name(), message: 'Bing Webmaster has no URL removal; the page must return 404 or 410.');
        }

        $site = (string) ($this->config['bing']['site_url'] ?: rtrim((string) config('app.url'), '/').'/');
        $accepted = [];
        $failed = [];
        $retryable = [];

        foreach (array_chunk(array_values(array_unique($urls)), max(1, (int) $this->config['bing']['chunk_size'])) as $chunk) {
            try {
                $response = Http::asJson()
                    ->timeout((int) ($this->config['http']['timeout'] ?? 15))
                    ->post($this->config['bing']['endpoint'].'?apikey='.urlencode((string) $this->config['bing']['key']), [
                        'siteUrl' => $site,
                        'urlList' => $chunk,
                    ]);
            } catch (ConnectionException $e) {
                foreach ($chunk as $url) {
                    $failed[$url] = 'Connection failed: '.$e->getMessage();
                    $retryable[] = $url;
                }

                continue;
            }

            if ($response->successful()) {
                array_push($accepted, ...$chunk);

                continue;
            }

            $message = (string) ($response->json('Message') ?? "HTTP {$response->status()}");
            $temporary = $response->status() === 429 || $response->serverError();

            foreach ($chunk as $url) {
                $failed[$url] = $message;

                if ($temporary) {
                    $retryable[] = $url;
                }
            }

            if ($response->status() === 429) {
                $this->pause($response);
            }
        }

        return new SubmissionResult($this->name(), $accepted, $failed, retryable: $retryable);
    }

    private function pause(Response $response): void
    {
        $wait = (int) $response->header('Retry-After');

        if ($wait > 0) {
            Sleep::for(min($wait, (int) ($this->config['http']['max_backoff'] ?? 30)))->seconds();
        }
    }
}
