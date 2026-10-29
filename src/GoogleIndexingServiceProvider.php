<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Siberfx\GoogleIndexing\Auth\ServiceAccountTokenProvider;
use Siberfx\GoogleIndexing\Commands\DoctorCommand;
use Siberfx\GoogleIndexing\Commands\InspectCommand;
use Siberfx\GoogleIndexing\Commands\InstallCommand;
use Siberfx\GoogleIndexing\Commands\PublishUrlCommand;
use Siberfx\GoogleIndexing\Commands\ReportCommand;
use Siberfx\GoogleIndexing\Commands\StatusCommand;
use Siberfx\GoogleIndexing\Commands\SubmitSitemapCommand;
use Siberfx\GoogleIndexing\Engines\BingWebmasterEngine;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\IndexNowEngine;
use Siberfx\GoogleIndexing\Engines\SearchConsoleInspector;
use Siberfx\GoogleIndexing\Engines\SearchConsoleSitemapEngine;
use Siberfx\GoogleIndexing\Events\UrlsSubmitted;
use Siberfx\GoogleIndexing\Http\Controllers\IndexNowKeyController;
use Siberfx\GoogleIndexing\Listeners\RecordSubmission;

final class GoogleIndexingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/google-indexing.php', 'google-indexing');

        $this->app->singleton(ServiceAccountTokenProvider::class, fn ($app) => new ServiceAccountTokenProvider(
            source: $app['config']['google-indexing.google.credentials'],
            cache: $app->make(Cache::class),
            tokenEndpoint: $app['config']['google-indexing.google.token_endpoint'],
            timeout: (int) $app['config']['google-indexing.http.timeout'],
        ));

        $this->app->singleton(GoogleIndexingApiEngine::class, fn ($app) => new GoogleIndexingApiEngine(
            $app->make(ServiceAccountTokenProvider::class),
            $app['config']['google-indexing'],
            $app->make(Cache::class),
        ));

        $this->app->singleton(SearchConsoleSitemapEngine::class, fn ($app) => new SearchConsoleSitemapEngine(
            $app->make(ServiceAccountTokenProvider::class),
            $app['config']['google-indexing'],
        ));

        $this->app->singleton(SearchConsoleInspector::class, fn ($app) => new SearchConsoleInspector(
            $app->make(ServiceAccountTokenProvider::class),
            $app['config']['google-indexing'],
        ));

        $this->app->singleton(IndexNowEngine::class, fn ($app) => new IndexNowEngine(
            $app['config']['google-indexing'],
        ));

        $this->app->singleton(BingWebmasterEngine::class, fn ($app) => new BingWebmasterEngine(
            $app['config']['google-indexing'],
        ));

        $this->app->singleton(GoogleIndexing::class, fn ($app) => new GoogleIndexing(
            $app,
            $app['config']['google-indexing'],
            $app->make(Cache::class),
        ));

        $this->app->alias(GoogleIndexing::class, 'google-indexing');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/google-indexing.php' => config_path('google-indexing.php'),
            ], 'google-indexing-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_google_indexing_submissions_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_google_indexing_submissions_table.php'),
            ], 'google-indexing-migrations');

            $this->commands([
                InstallCommand::class,
                SubmitSitemapCommand::class,
                PublishUrlCommand::class,
                StatusCommand::class,
                InspectCommand::class,
                ReportCommand::class,
                DoctorCommand::class,
            ]);
        }

        if (config('google-indexing.history.enabled')) {
            Event::listen(UrlsSubmitted::class, RecordSubmission::class);
        }

        $this->registerIndexNowKeyRoute();
        $this->registerSchedule();
    }

    /** Auto-serve https://your.site/{key}.txt so IndexNow can verify ownership. */
    private function registerIndexNowKeyRoute(): void
    {
        $key = config('google-indexing.indexnow.key');

        if (! config('google-indexing.indexnow.serve_key_file') || ! is_string($key) || ! preg_match('/^[A-Za-z0-9\-]{8,128}$/', $key)) {
            return;
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::get("{$key}.txt", IndexNowKeyController::class)->name('google-indexing.indexnow-key');
    }

    /** Auto-schedule the sitemap submission — nothing to add to routes/console.php. */
    private function registerSchedule(): void
    {
        if (! config('google-indexing.sitemap.schedule.enabled')) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            [$method, $argument] = array_pad(explode(':', (string) config('google-indexing.sitemap.schedule.frequency', 'daily'), 2), 2, null);

            $event = $schedule->command('indexing:sitemap')->withoutOverlapping();
            $argument === null ? $event->{$method}() : $event->{$method}($argument);
        });
    }
}
