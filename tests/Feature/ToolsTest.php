<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Engines\GoogleIndexingApiEngine;
use Siberfx\GoogleIndexing\Engines\IndexNowEngine;
use Siberfx\GoogleIndexing\Events\UrlsSubmitted;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\Facades\GoogleIndexing;
use Siberfx\GoogleIndexing\GoogleIndexing as Manager;
use Siberfx\GoogleIndexing\Listeners\RecordSubmission;

const TOOLS_TABLE = 'google_indexing_submissions';

/** Runs the published migration stub against the test database. */
function createHistoryTable(): void
{
    (require __DIR__.'/../../database/migrations/create_google_indexing_submissions_table.php.stub')->up();
}

function freshManager(): void
{
    app()->forgetInstance(Manager::class);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('reports whether google has indexed the page', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'searchconsole.googleapis.com/*' => Http::response(['inspectionResult' => ['indexStatusResult' => [
            'verdict' => 'PASS',
            'coverageState' => 'Submitted and indexed',
            'lastCrawlTime' => '2026-10-01T10:00:00Z',
            'robotsTxtState' => 'ALLOWED',
            'pageFetchState' => 'SUCCESSFUL',
        ]]]),
    ]);
    freshManager();

    $result = GoogleIndexing::inspect('https://example.com/post');

    expect($result)->toMatchArray([
        'indexed' => true,
        'verdict' => 'PASS',
        'coverage' => 'Submitted and indexed',
        'page_fetch' => 'SUCCESSFUL',
    ]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'urlInspection')
        && $r['inspectionUrl'] === 'https://example.com/post'
        && $r['siteUrl'] === 'https://example.com/'
        && $r->hasHeader('Authorization', 'Bearer tok'));
});

it('reports a page that is not indexed', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'searchconsole.googleapis.com/*' => Http::response(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'NEUTRAL', 'coverageState' => 'Discovered - currently not indexed']]]),
    ]);
    freshManager();

    expect(GoogleIndexing::inspect('https://example.com/new'))
        ->indexed->toBeFalse()
        ->coverage->toBe('Discovered - currently not indexed');
});

it('throws when the inspection api refuses the request', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'searchconsole.googleapis.com/*' => Http::response(['error' => ['message' => 'User does not have sufficient permission']], 403),
    ]);
    freshManager();

    expect(fn () => GoogleIndexing::inspect('https://example.com/post'))
        ->toThrow(GoogleIndexingException::class, 'User does not have sufficient permission');
});

it('prints the inspection result from the command', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'searchconsole.googleapis.com/*' => Http::response(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'PASS']]]),
    ]);
    freshManager();

    $this->artisan('indexing:inspect', ['url' => 'https://example.com/post'])
        ->expectsOutputToContain('PASS')
        ->assertSuccessful();
});

it('records every engine result when history is enabled', function () {
    createHistoryTable();
    Event::listen(UrlsSubmitted::class, RecordSubmission::class);
    Http::swap(new Factory);
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
    freshManager();
    app()->forgetInstance(IndexNowEngine::class);

    GoogleIndexing::publish('https://example.com/a', ['indexnow']);

    $row = DB::table(TOOLS_TABLE)->first();

    expect($row->engine)->toBe('indexnow')
        ->and($row->status)->toBe('accepted')
        ->and($row->url)->toBe('https://example.com/a')
        ->and($row->action)->toBe('URL_UPDATED');
});

it('stores failed and deferred rows with their reasons', function () {
    createHistoryTable();
    Event::listen(UrlsSubmitted::class, RecordSubmission::class);
    config(['google-indexing.google.daily_limit' => 1]);
    app()->forgetInstance(GoogleIndexingApiEngine::class);
    freshManager();
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'indexing.googleapis.com/*' => Http::response(['error' => ['message' => 'Permission denied']], 403),
    ]);

    GoogleIndexing::publish(['https://example.com/a', 'https://example.com/b'], ['google']);

    expect(DB::table(TOOLS_TABLE)->where('status', 'failed')->value('error'))->toBe('Permission denied')
        ->and(DB::table(TOOLS_TABLE)->where('status', 'deferred')->value('url'))->toBe('https://example.com/b');
});

it('summarises recent submissions in the report command', function () {
    createHistoryTable();
    DB::table(TOOLS_TABLE)->insert([
        ['engine' => 'google', 'action' => 'URL_UPDATED', 'url' => 'https://example.com/a', 'status' => 'accepted', 'error' => null, 'created_at' => now()],
        ['engine' => 'google', 'action' => 'URL_UPDATED', 'url' => 'https://example.com/b', 'status' => 'failed', 'error' => 'Permission denied', 'created_at' => now()],
    ]);

    $this->artisan('indexing:report', ['--hours' => 24])
        ->expectsOutputToContain('Latest failures')
        ->assertSuccessful();
});

it('fails the report command when history is not set up', function () {
    $this->artisan('indexing:report')
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

it('passes the doctor check on a complete local setup', function () {
    $this->artisan('indexing:doctor')
        ->expectsOutputToContain('All checks passed.')
        ->assertSuccessful();
});

it('fails the doctor check when the bing key is missing and bing is active', function () {
    config(['google-indexing.use' => ['google', 'indexnow', 'bing'], 'google-indexing.bing.key' => null]);

    $this->artisan('indexing:doctor')
        ->expectsOutputToContain('Some checks failed.')
        ->assertFailed();
});

it('confirms search console ownership and the key file with --live', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'www.googleapis.com/webmasters/v3/sites/*' => Http::response(['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner']),
        'example.com/abcdef1234567890abcdef1234567890.txt' => Http::response('abcdef1234567890abcdef1234567890'),
        'example.com/sitemap.xml' => Http::response('<urlset/>'),
    ]);

    $this->artisan('indexing:doctor', ['--live' => true])
        ->expectsOutputToContain('siteOwner')
        ->assertSuccessful();
});

it('fails --live when the account is not an owner of the property', function () {
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'www.googleapis.com/webmasters/v3/sites/*' => Http::response(['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteRestrictedUser']),
        'example.com/abcdef1234567890abcdef1234567890.txt' => Http::response('abcdef1234567890abcdef1234567890'),
        'example.com/sitemap.xml' => Http::response('<urlset/>'),
    ]);

    $this->artisan('indexing:doctor', ['--live' => true])
        ->expectsOutputToContain('siteRestrictedUser')
        ->assertFailed();
});
