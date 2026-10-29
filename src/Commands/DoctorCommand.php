<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Siberfx\GoogleIndexing\Auth\ServiceAccountTokenProvider;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\SearchConsoleInspector;
use Siberfx\GoogleIndexing\Engines\SearchConsoleSitemapEngine;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\GoogleIndexing;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'indexing:doctor {--live : Also call Google, check the IndexNow key file and the sitemap}';

    protected $description = 'Check the setup: credentials, Search Console access, IndexNow key, sitemap, history table';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private bool $failed = false;

    public function handle(ServiceAccountTokenProvider $tokens): int
    {
        $this->checkLocal($tokens);

        if ($this->option('live')) {
            $this->checkLive($tokens);
        } else {
            $this->rows[] = ['Live checks', 'skipped', 'run with --live to call Google and fetch the key file and sitemap'];
        }

        $this->table(['Check', 'Result', 'Detail'], $this->rows);

        if ($this->failed) {
            $this->components->error('Some checks failed.');

            return self::FAILURE;
        }

        $this->components->info('All checks passed.');

        return self::SUCCESS;
    }

    private function checkLocal(ServiceAccountTokenProvider $tokens): void
    {
        $this->record('PHP >= 8.4', PHP_VERSION_ID >= 80400, PHP_VERSION);
        $this->record('ext-openssl', extension_loaded('openssl'), extension_loaded('openssl') ? 'loaded' : 'missing');
        $this->record('Package enabled', (bool) config('google-indexing.enabled'), (bool) config('google-indexing.enabled') ? 'yes' : 'GOOGLE_INDEXING_ENABLED is false');

        try {
            $configured = $tokens->isConfigured();
            $detail = $configured ? $tokens->clientEmail() : 'set GOOGLE_INDEXING_CREDENTIALS';
            $this->record('Google credentials', $configured, $detail);
        } catch (GoogleIndexingException $e) {
            $this->record('Google credentials', false, $e->getMessage());
        }

        $key = (string) config('google-indexing.indexnow.key');
        $this->record('IndexNow key', (bool) preg_match('/^[A-Za-z0-9\-]{8,128}$/', $key), $key === '' ? 'run indexing:install' : 'format ok');

        if (in_array('bing', (array) config('google-indexing.use'), true)) {
            $this->record('Bing key', filled(config('google-indexing.bing.key')), 'BING_WEBMASTER_API_KEY');
        }

        $sitemap = app(GoogleIndexing::class)->sitemapLocation();
        $this->record('Sitemap URL', (bool) preg_match('#^https?://#i', $sitemap), $sitemap);

        if (config('google-indexing.history.enabled')) {
            $table = (string) config('google-indexing.history.table');
            $this->record('History table', Schema::hasTable($table), $table);
        }
    }

    private function checkLive(ServiceAccountTokenProvider $tokens): void
    {
        if (! $tokens->isConfigured()) {
            $this->record('Google token', false, 'credentials missing');
        } else {
            try {
                $tokens->token([GoogleIndexingApiEngine::SCOPE]);
                $this->record('Google token', true, 'indexing scope');
            } catch (Throwable $e) {
                $this->record('Google token', false, $e->getMessage());
            }

            $this->checkSearchConsole($tokens);
        }

        $key = (string) config('google-indexing.indexnow.key');

        if ($key !== '') {
            $url = rtrim((string) config('app.url'), '/')."/{$key}.txt";

            try {
                $response = Http::timeout(10)->get($url);
                $this->record('IndexNow key file', $response->successful() && trim($response->body()) === $key, $url);
            } catch (Throwable $e) {
                $this->record('IndexNow key file', false, $e->getMessage());
            }
        }

        try {
            $sitemap = app(GoogleIndexing::class)->sitemapLocation();
            $response = Http::timeout(10)->get($sitemap);
            $this->record('Sitemap reachable', $response->successful(), $sitemap.' → HTTP '.$response->status());
        } catch (Throwable $e) {
            $this->record('Sitemap reachable', false, $e->getMessage());
        }
    }

    private function checkSearchConsole(ServiceAccountTokenProvider $tokens): void
    {
        $site = app(SearchConsoleInspector::class)->siteUrl();

        try {
            $response = Http::withToken($tokens->token([SearchConsoleSitemapEngine::SCOPE]))
                ->timeout(10)
                ->get(config('google-indexing.google.sitemaps_endpoint').'/'.rawurlencode($site));

            $level = (string) $response->json('permissionLevel', '');
            $this->record('Search Console access', $level === 'siteOwner', $response->successful() ? "{$site}: {$level}" : "{$site}: HTTP {$response->status()}");
        } catch (Throwable $e) {
            $this->record('Search Console access', false, $e->getMessage());
        }
    }

    private function record(string $check, bool $ok, string $detail): void
    {
        $this->rows[] = [$check, $ok ? 'ok' : 'FAIL', $detail];

        if (! $ok) {
            $this->failed = true;
        }
    }
}
