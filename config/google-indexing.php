<?php

declare(strict_types=1);

use Siberfx\GoogleIndexing\Engines\BingWebmasterEngine;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\IndexNowEngine;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false nothing is ever sent (handy for local / staging).
    */
    'enabled' => env('GOOGLE_INDEXING_ENABLED', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Engines
    |--------------------------------------------------------------------------
    | Engines that receive every submission. Add your own class implementing
    | Siberfx\GoogleIndexing\Contracts\Engine and list it here.
    */
    'engines' => [
        'google' => GoogleIndexingApiEngine::class,
        'indexnow' => IndexNowEngine::class,
        'bing' => BingWebmasterEngine::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Which engines are active
    |--------------------------------------------------------------------------
    */
    'use' => array_filter(explode(',', (string) env('GOOGLE_INDEXING_ENGINES', 'google,indexnow'))),

    /*
    |--------------------------------------------------------------------------
    | Google (Indexing API + Search Console sitemaps)
    |--------------------------------------------------------------------------
    | `credentials` accepts an absolute path to the service-account JSON file,
    | the raw JSON string, or an array. Add the service-account e-mail as an
    | *Owner* of your property in Search Console.
    */
    'google' => [
        'credentials' => env('GOOGLE_INDEXING_CREDENTIALS'),

        // Google's default quota is 200 publish requests per day, counted across
        // all runs and reset at midnight Pacific time. URLs over the limit are
        // deferred to a later run instead of failing. 0 = no limit.
        'daily_limit' => (int) env('GOOGLE_INDEXING_DAILY_LIMIT', 200),

        // Concurrent requests per chunk.
        'concurrency' => 10,

        // Property as registered in Search Console, e.g. "https://example.com/"
        // or "sc-domain:example.com". Needed for sitemap submission through
        // the Search Console API. Defaults to the app URL.
        'site_url' => env('GOOGLE_INDEXING_SITE_URL'),

        'publish_endpoint' => 'https://indexing.googleapis.com/v3/urlNotifications:publish',
        'metadata_endpoint' => 'https://indexing.googleapis.com/v3/urlNotifications/metadata',
        'token_endpoint' => 'https://oauth2.googleapis.com/token',
        'sitemaps_endpoint' => 'https://www.googleapis.com/webmasters/v3/sites',
    ],

    /*
    |--------------------------------------------------------------------------
    | IndexNow (Bing, Yandex, Naver, Seznam, Yep ...)
    |--------------------------------------------------------------------------
    | Run `php artisan indexing:install` to generate a key. The key file
    | (/{key}.txt) is served automatically by the package.
    */
    'indexnow' => [
        'key' => env('INDEXNOW_KEY'),
        'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
        'serve_key_file' => true,
        'chunk_size' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bing Webmaster Tools (optional — IndexNow already covers Bing)
    |--------------------------------------------------------------------------
    | Add 'bing' to GOOGLE_INDEXING_ENGINES to submit through the Webmaster API
    | directly (per-request feedback). Key: Bing Webmaster Tools → Settings → API access.
    */
    'bing' => [
        'key' => env('BING_WEBMASTER_API_KEY'),
        'site_url' => env('BING_WEBMASTER_SITE_URL'), // defaults to APP_URL
        'endpoint' => 'https://ssl.bing.com/webmaster/api.svc/json/SubmitUrlbatch',
        'chunk_size' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Submission history (optional)
    |--------------------------------------------------------------------------
    | When enabled every engine result is stored in `table`. Publish and run the
    | migration first: php artisan vendor:publish --tag=google-indexing-migrations
    */
    'history' => [
        'enabled' => (bool) env('GOOGLE_INDEXING_HISTORY', false),
        'table' => 'google_indexing_submissions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap
    |--------------------------------------------------------------------------
    */
    'sitemap' => [
        // Defaults to {APP_URL}/sitemap.xml
        'url' => env('GOOGLE_INDEXING_SITEMAP'),

        // Sitemap-index nesting depth and a hard safety cap on URLs per run.
        'max_depth' => 3,
        'max_urls' => 50000,

        // Only submit URLs whose <lastmod> is newer than this ("1 day", "2 hours"...).
        // null = submit everything.
        'only_changed_within' => env('GOOGLE_INDEXING_ONLY_CHANGED_WITHIN'),

        // Skip URLs already submitted that have not changed since (saves quota on
        // the daily run). A URL is sent again after `resubmit_after`, or when its
        // <lastmod> is newer than the last submission.
        'dedupe' => (bool) env('GOOGLE_INDEXING_SITEMAP_DEDUPE', true),
        'resubmit_after' => env('GOOGLE_INDEXING_RESUBMIT_AFTER', '30 days'),

        // Register `indexing:sitemap` in the scheduler automatically.
        'schedule' => [
            'enabled' => (bool) env('GOOGLE_INDEXING_SCHEDULE', false),
            'frequency' => 'dailyAt:03:00',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Model events (Indexable trait) are always dispatched as queued jobs.
    */
    'queue' => [
        'connection' => env('GOOGLE_INDEXING_QUEUE_CONNECTION'),
        'name' => env('GOOGLE_INDEXING_QUEUE'),
        'tries' => 3,
        'backoff' => [30, 120, 600],
    ],

    'http' => [
        'timeout' => 15,
        'user_agent' => 'siberfx-google-indexing/1.1',
        // Longest pause (seconds) honoured from a 429 Retry-After header.
        'max_backoff' => 30,
    ],
];
