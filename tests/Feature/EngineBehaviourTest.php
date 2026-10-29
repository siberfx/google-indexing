<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Siberfx\GoogleIndexing\Engines\BingWebmasterEngine;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\IndexNowEngine;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\Facades\GoogleIndexing;
use Siberfx\GoogleIndexing\GoogleIndexing as Manager;

/** Forget resolved singletons so config changes made in a test take effect. */
function resetEngines(): void
{
    foreach ([Manager::class, GoogleIndexingApiEngine::class, IndexNowEngine::class, BingWebmasterEngine::class] as $class) {
        app()->forgetInstance($class);
    }
}

/** Replace the default fakes for one test; $publish is the response (or callback) for the Indexing API. */
function fakeGoogle(mixed $publish): void
{
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/*' => $publish,
    ]);
}

/** Bing is opt-in: it has to be in the active engine list before publish() reaches it. */
function enableBing(?string $key = 'bing-secret'): void
{
    config([
        'google-indexing.use' => ['google', 'indexnow', 'bing'],
        'google-indexing.bing.key' => $key,
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

it('defers urls over the daily quota instead of failing them', function () {
    config(['google-indexing.google.daily_limit' => 2]);
    resetEngines();
    fakeGoogle(Http::response(['ok' => true]));

    $result = GoogleIndexing::publish(['https://example.com/a', 'https://example.com/b', 'https://example.com/c'], ['google'])[0];

    expect($result->accepted)->toBe(['https://example.com/a', 'https://example.com/b'])
        ->and($result->failed)->toBe([])
        ->and($result->deferred)->toHaveKey('https://example.com/c')
        ->and($result->successful())->toBeTrue();
});

it('remembers the quota across runs and defers everything once it is used up', function () {
    config(['google-indexing.google.daily_limit' => 1]);
    resetEngines();
    fakeGoogle(Http::response(['ok' => true]));

    GoogleIndexing::publish('https://example.com/a', ['google']);
    $second = GoogleIndexing::publish('https://example.com/b', ['google'])[0];

    expect($second->accepted)->toBe([])
        ->and($second->deferred)->toHaveKey('https://example.com/b');

    Http::assertSentCount(2); // one token request + the first publish only
});

it('treats a 429 as retryable and honours Retry-After', function () {
    resetEngines();
    fakeGoogle(Http::response(['error' => ['message' => 'Quota exceeded']], 429, ['Retry-After' => '2']));

    $result = GoogleIndexing::publish('https://example.com/x', ['google'])[0];

    expect($result->failed)->toHaveKey('https://example.com/x')
        ->and($result->retryable)->toBe(['https://example.com/x']);

    Sleep::assertSleptTimes(1);
});

it('does not retry a permanent google error', function () {
    resetEngines();
    fakeGoogle(Http::response(['error' => ['message' => 'Permission denied']], 403));

    $result = GoogleIndexing::publish('https://example.com/x', ['google'])[0];

    expect($result->failed)->toBe(['https://example.com/x' => 'Permission denied'])
        ->and($result->retryable)->toBe([]);
});

it('retries a 5xx from google', function () {
    resetEngines();
    fakeGoogle(Http::response('', 503));

    $result = GoogleIndexing::publish('https://example.com/x', ['google'])[0];

    expect($result->retryable)->toBe(['https://example.com/x']);
});

it('accepts indexnow 202 and marks 5xx as retryable', function () {
    resetEngines();
    Http::swap(new Factory);
    Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);

    $accepted = GoogleIndexing::publish('https://example.com/a', ['indexnow'])[0];
    expect($accepted->successful())->toBeTrue();

    Http::swap(new Factory);
    Http::fake(['api.indexnow.org/*' => Http::response('oops', 500)]);
    resetEngines();

    $failed = GoogleIndexing::publish('https://example.com/b', ['indexnow'])[0];

    expect($failed->failed)->toHaveKey('https://example.com/b')
        ->and($failed->retryable)->toBe(['https://example.com/b']);
});

it('treats an indexnow connection error as retryable instead of crashing', function () {
    resetEngines();
    Http::swap(new Factory);
    Http::fake(['api.indexnow.org/*' => function () {
        throw new ConnectionException('timed out');
    }]);

    $result = GoogleIndexing::publish('https://example.com/x', ['indexnow'])[0];

    expect($result->failed['https://example.com/x'])->toContain('timed out')
        ->and($result->retryable)->toBe(['https://example.com/x']);
});

it('keeps the other engines running when one of them throws', function () {
    resetEngines();
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        'api.indexnow.org/*' => Http::response('', 200),
    ]);

    $results = GoogleIndexing::publish('https://example.com/x', ['google', 'indexnow']);

    expect($results)->toHaveCount(2)
        ->and($results[0]->engine)->toBe('google')
        ->and($results[0]->failed)->toHaveKey('https://example.com/x')
        ->and($results[1]->successful())->toBeTrue();
});

it('submits to bing through the webmaster api with the key in the query', function () {
    enableBing();
    resetEngines();
    Http::swap(new Factory);
    Http::fake(['ssl.bing.com/*' => Http::response(null, 200)]);

    $result = GoogleIndexing::publish('https://example.com/post', ['bing'])[0];

    expect($result->successful())->toBeTrue();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'SubmitUrlbatch?apikey=bing-secret')
        && $r['siteUrl'] === 'https://example.com/'
        && $r['urlList'] === ['https://example.com/post']);
});

it('explains that bing cannot remove URLs', function () {
    enableBing();
    resetEngines();
    Http::swap(new Factory);
    Http::fake();

    $result = GoogleIndexing::delete('https://example.com/gone', ['bing'])[0];

    expect($result->message)->toContain('404 or 410');
    Http::assertNothingSent();
});

it('skips bing when no key is configured', function () {
    enableBing(null);
    resetEngines();

    $result = GoogleIndexing::publish('https://example.com/x', ['bing'])[0];

    expect($result->message)->toBe('Engine is not configured.');
});

it('waits no longer than the configured max_backoff', function () {
    config(['google-indexing.http.max_backoff' => 5]);
    resetEngines();
    fakeGoogle(Http::response('', 429, ['Retry-After' => '600']));

    GoogleIndexing::publish('https://example.com/x', ['google']);

    Sleep::assertSleptTimes(1);
});

it('does not sleep for a 429 without Retry-After', function () {
    resetEngines();
    fakeGoogle(Http::response('', 429));

    $result = GoogleIndexing::publish('https://example.com/x', ['google'])[0];

    expect($result->retryable)->toBe(['https://example.com/x']);
    Sleep::assertNeverSlept();
});

it('reports the remaining daily quota', function () {
    config(['google-indexing.google.daily_limit' => 10]);
    resetEngines();

    expect(app(GoogleIndexingApiEngine::class)->remaining())->toBe(10);
});

it('sends URL_DELETED through submit()', function () {
    resetEngines();
    fakeGoogle(Http::response(['ok' => true]));

    GoogleIndexing::submit('https://example.com/x', UrlAction::Deleted, ['google']);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'publish') && $r['type'] === 'URL_DELETED');
});
