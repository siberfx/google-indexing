<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Engines;

use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Contracts\Engine;
use Siberfx\GoogleIndexing\Data\SubmissionResult;
use Siberfx\GoogleIndexing\Enums\UrlAction;

/**
 * IndexNow protocol (https://www.indexnow.org) — one call notifies
 * Bing, Yandex, Naver, Seznam, Yep and every other participating engine.
 */
final class IndexNowEngine implements Engine
{
    /** @param  array<string, mixed>  $config  the full google-indexing config array */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'indexnow';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['indexnow']['key'] ?? null);
    }

    public function submit(array $urls, UrlAction $action = UrlAction::Updated): SubmissionResult
    {
        $key = (string) $this->config['indexnow']['key'];
        $accepted = [];
        $failed = [];

        // IndexNow requires every URL of one request to share the same host.
        $byHost = [];
        foreach (array_unique($urls) as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (! is_string($host)) {
                $failed[$url] = 'Invalid URL.';

                continue;
            }

            $byHost[$host][] = $url;
        }

        foreach ($byHost as $host => $hostUrls) {
            $scheme = parse_url($hostUrls[0], PHP_URL_SCHEME) ?: 'https';

            foreach (array_chunk($hostUrls, max(1, (int) $this->config['indexnow']['chunk_size'])) as $chunk) {
                $response = Http::asJson()
                    ->timeout((int) ($this->config['http']['timeout'] ?? 15))
                    ->withUserAgent((string) ($this->config['http']['user_agent'] ?? 'siberfx-google-indexing'))
                    ->post($this->config['indexnow']['endpoint'], [
                        'host' => $host,
                        'key' => $key,
                        'keyLocation' => "{$scheme}://{$host}/{$key}.txt",
                        'urlList' => $chunk,
                    ]);

                // 200 = OK, 202 = accepted (key validation pending).
                if (in_array($response->status(), [200, 202], true)) {
                    array_push($accepted, ...$chunk);

                    continue;
                }

                foreach ($chunk as $url) {
                    $failed[$url] = "HTTP {$response->status()}".($response->body() !== '' ? ': '.$response->body() : '');
                }
            }
        }

        return new SubmissionResult($this->name(), $accepted, $failed);
    }
}
