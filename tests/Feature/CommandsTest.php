<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\Facades\GoogleIndexing;
use Siberfx\GoogleIndexing\Jobs\SubmitUrlsJob;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/v3/urlNotifications:publish' => Http::response([]),
        'indexing.googleapis.com/v3/urlNotifications/metadata*' => Http::response(['url' => 'https://example.com/p', 'latestUpdate' => ['type' => 'URL_UPDATED']]),
        'api.indexnow.org/*' => Http::response('', 200),
    ]);
});

it('publishes urls from the command', function () {
    $this->artisan('indexing:publish', ['urls' => ['https://example.com/p']])
        ->expectsOutputToContain('[google] 1 accepted, 0 failed')
        ->assertSuccessful();
});

it('fails the publish command when an engine rejects a url', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/v3/urlNotifications:publish' => Http::response(['error' => ['message' => 'Denied']], 403),
    ]);

    $this->artisan('indexing:publish', ['urls' => ['https://example.com/p'], '--only' => ['google']])
        ->expectsOutputToContain('Denied')
        ->assertFailed();
});

it('queues from the publish command', function () {
    Bus::fake();

    $this->artisan('indexing:publish', ['urls' => ['https://example.com/p'], '--queue' => true])->assertSuccessful();

    Bus::assertDispatched(SubmitUrlsJob::class);
});

it('warns instead of publishing when disabled', function () {
    config(['google-indexing.enabled' => false]);
    app()->forgetInstance(\Siberfx\GoogleIndexing\GoogleIndexing::class);

    $this->artisan('indexing:publish', ['urls' => ['https://example.com/p']])->assertSuccessful();

    Http::assertNothingSent();
});

it('prints url status', function () {
    $this->artisan('indexing:status', ['url' => 'https://example.com/p'])
        ->expectsOutputToContain('URL_UPDATED')
        ->assertSuccessful();
});

it('fails the status command on api errors', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/v3/urlNotifications/metadata*' => Http::response(['error' => ['message' => 'Not found']], 404),
    ]);

    $this->artisan('indexing:status', ['url' => 'https://example.com/p'])->assertFailed();
});

it('reports the service account on install', function () {
    config(['google-indexing.indexnow.key' => 'abcdef1234567890abcdef1234567890']);

    $this->artisan('indexing:install')->assertSuccessful();
});

it('throws for an unknown engine', function () {
    GoogleIndexing::engine('nope');
})->throws(GoogleIndexingException::class, 'Unknown indexing engine');
