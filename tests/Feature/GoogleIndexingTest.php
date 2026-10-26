<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Engines\IndexNowEngine;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\Facades\GoogleIndexing;
use Siberfx\GoogleIndexing\GoogleIndexing as Manager;
use Siberfx\GoogleIndexing\Jobs\SubmitUrlsJob;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/v3/urlNotifications:publish' => Http::response(['urlNotificationMetadata' => []]),
        'api.indexnow.org/*' => Http::response('', 200),
        'www.googleapis.com/webmasters/*' => Http::response([], 200),
        'example.com/sitemap.xml' => Http::response(sitemapXml([
            'https://example.com/new' => now()->subHour()->toAtomString(),
            'https://example.com/old' => '2020-01-01',
            'https://example.com/unknown',
        ])),
    ]);
});

it('publishes to google and indexnow', function () {
    $results = GoogleIndexing::publish('https://example.com/post');

    expect($results)->toHaveCount(2)
        ->and($results[0]->successful())->toBeTrue()
        ->and($results[1]->successful())->toBeTrue();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'urlNotifications:publish')
        && $r['type'] === 'URL_UPDATED' && $r['url'] === 'https://example.com/post'
        && $r->hasHeader('Authorization', 'Bearer tok'));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'indexnow')
        && $r['host'] === 'example.com'
        && $r['keyLocation'] === 'https://example.com/abcdef1234567890abcdef1234567890.txt'
        && $r['urlList'] === ['https://example.com/post']);
});

it('sends URL_DELETED', function () {
    GoogleIndexing::delete('https://example.com/gone', ['google']);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'publish') && $r['type'] === 'URL_DELETED');
});

it('reports google api errors per url', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/*' => Http::response(['error' => ['message' => 'Permission denied']], 403),
    ]);

    $result = GoogleIndexing::publish('https://example.com/x', ['google'])[0];

    expect($result->failed)->toBe(['https://example.com/x' => 'Permission denied']);
});

it('skips unconfigured engines', function () {
    config(['google-indexing.indexnow.key' => null]);
    app()->forgetInstance(IndexNowEngine::class);
    app()->forgetInstance(Manager::class);

    $results = GoogleIndexing::publish('https://example.com/x', ['indexnow']);

    expect($results[0]->message)->toBe('Engine is not configured.');
});

it('does nothing when disabled', function () {
    config(['google-indexing.enabled' => false]);
    app()->forgetInstance(Manager::class);

    expect(GoogleIndexing::publish('https://example.com/x'))->toBe([]);
    Http::assertNothingSent();
});

it('filters sitemap urls by lastmod and submits the sitemap to search console', function () {
    $results = GoogleIndexing::submitSitemap(since: '1 day');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'indexnow')
        && $r['urlList'] === ['https://example.com/new', 'https://example.com/unknown']);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'sitemaps/https%3A%2F%2Fexample.com%2Fsitemap.xml'));

    expect(collect($results)->pluck('engine')->all())->toBe(['google', 'indexnow', 'google-sitemap']);
});

it('queues submissions', function () {
    Bus::fake();

    GoogleIndexing::queue(['https://example.com/a', 'https://example.com/a', 'not-a-url']);

    Bus::assertDispatched(SubmitUrlsJob::class, fn ($j) => $j->urls === ['https://example.com/a'] && $j->action === UrlAction::Updated);
});

it('serves the indexnow key file', function () {
    $this->get('/abcdef1234567890abcdef1234567890.txt')
        ->assertOk()
        ->assertSee('abcdef1234567890abcdef1234567890');
});

it('runs the sitemap command in dry-run mode', function () {
    $this->artisan('indexing:sitemap', ['--dry-run' => true])
        ->expectsOutputToContain('3 URL(s) found')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'indexnow'));
});
