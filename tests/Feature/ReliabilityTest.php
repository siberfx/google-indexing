<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\IndexNowEngine;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\Facades\GoogleIndexing;
use Siberfx\GoogleIndexing\GoogleIndexing as Manager;
use Siberfx\GoogleIndexing\Jobs\SubmitUrlsJob;

/** Sitemap fixture served by a fresh fake; $lastmods maps URL => lastmod (null = no lastmod). */
function reliabilityFakes(array $lastmods): void
{
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/*' => fn (Request $r) => Http::response(['ok' => true]),
        'api.indexnow.org/*' => Http::response('', 200),
        'www.googleapis.com/webmasters/*' => Http::response([], 200),
        'example.com/sitemap.xml' => Http::response(sitemapXml($lastmods)),
    ]);
}

function resetManager(): void
{
    foreach ([Manager::class, GoogleIndexingApiEngine::class, IndexNowEngine::class] as $class) {
        app()->forgetInstance($class);
    }
}

function indexNowCalls(): int
{
    return count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'indexnow')));
}

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

it('sends only the temporarily failed urls again, as a follow-up job', function () {
    Bus::fake();
    resetManager();
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/*' => fn (Request $r) => $r['url'] === 'https://example.com/b'
            ? Http::response('', 503)
            : Http::response(['ok' => true]),
    ]);

    (new SubmitUrlsJob(['https://example.com/a', 'https://example.com/b'], UrlAction::Updated, ['google']))
        ->handle(app(Manager::class));

    Bus::assertDispatched(SubmitUrlsJob::class, fn (SubmitUrlsJob $job) => $job->urls === ['https://example.com/b']
        && $job->attempt === 2
        && $job->action === UrlAction::Updated);
});

it('does not resend urls that were accepted or permanently rejected', function () {
    Bus::fake();
    resetManager();
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/*' => fn (Request $r) => $r['url'] === 'https://example.com/b'
            ? Http::response(['error' => ['message' => 'Permission denied']], 403)
            : Http::response(['ok' => true]),
    ]);

    (new SubmitUrlsJob(['https://example.com/a', 'https://example.com/b'], UrlAction::Updated, ['google']))
        ->handle(app(Manager::class));

    Bus::assertNotDispatched(SubmitUrlsJob::class);
});

it('gives up on the last attempt when urls still fail temporarily', function () {
    resetManager();
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/*' => Http::response('', 503),
    ]);

    $job = new SubmitUrlsJob(['https://example.com/b'], UrlAction::Updated, ['google'], attempt: 3);

    expect(fn () => $job->handle(app(Manager::class)))
        ->toThrow(RuntimeException::class, 'Indexing still failing after 3 attempt(s) for 1 URL(s).');
});

it('gives each retry its own unique id so it is not dropped by the lock', function () {
    $first = new SubmitUrlsJob(['https://example.com/a'], UrlAction::Updated);
    $retry = new SubmitUrlsJob(['https://example.com/a'], UrlAction::Updated, attempt: 2);

    expect($first->uniqueId())->not->toBe($retry->uniqueId());
});

it('skips sitemap urls it already submitted and that have not changed', function () {
    reliabilityFakes([
        'https://example.com/new' => now()->subHour()->toAtomString(),
        'https://example.com/old' => '2020-01-01',
        'https://example.com/unknown' => null,
    ]);
    resetManager();

    GoogleIndexing::submitSitemap();
    $afterFirst = indexNowCalls();

    GoogleIndexing::submitSitemap();

    expect($afterFirst)->toBe(1)
        ->and(indexNowCalls())->toBe(1);
});

it('submits a sitemap url again once its lastmod is newer than the last submission', function () {
    reliabilityFakes(['https://example.com/new' => now()->subHour()->toAtomString()]);
    resetManager();
    GoogleIndexing::submitSitemap();

    reliabilityFakes(['https://example.com/new' => now()->addHour()->toAtomString()]);
    resetManager();
    GoogleIndexing::submitSitemap();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'indexnow') && $r['urlList'] === ['https://example.com/new']);
});

it('sends everything again when sitemap dedupe is switched off', function () {
    config(['google-indexing.sitemap.dedupe' => false]);
    reliabilityFakes(['https://example.com/new' => now()->subHour()->toAtomString()]);
    resetManager();

    GoogleIndexing::submitSitemap();
    GoogleIndexing::submitSitemap();

    expect(indexNowCalls())->toBe(2);
});

it('does not dedupe manual publish calls', function () {
    reliabilityFakes([]);
    resetManager();

    GoogleIndexing::publish('https://example.com/x', ['indexnow']);
    GoogleIndexing::publish('https://example.com/x', ['indexnow']);

    expect(indexNowCalls())->toBe(2);
});
