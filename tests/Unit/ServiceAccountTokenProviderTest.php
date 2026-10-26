<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Auth\ServiceAccountTokenProvider;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;

function provider(array|string|null $source): ServiceAccountTokenProvider
{
    return new ServiceAccountTokenProvider($source, new Repository(new ArrayStore));
}

it('is not configured without credentials', function (mixed $source) {
    expect(provider($source)->isConfigured())->toBeFalse();
})->with([[null], [''], [[]], [['client_email' => 'a@b.c']]]);

it('accepts an array, raw json or a file path', function () {
    $creds = $this->serviceAccount();
    $path = tempnam(sys_get_temp_dir(), 'gi');
    file_put_contents($path, json_encode($creds));

    foreach ([$creds, json_encode($creds), $path] as $source) {
        expect(provider($source)->clientEmail())->toBe('bot@project.iam.gserviceaccount.com');
    }

    unlink($path);
});

it('rejects a missing credentials file', function () {
    provider('/nope/missing.json')->clientEmail();
})->throws(GoogleIndexingException::class, 'not found');

it('requests a signed RS256 jwt and caches the token', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])]);
    $provider = provider($this->serviceAccount());

    expect($provider->token(['scope-a']))->toBe('tok')
        ->and($provider->token(['scope-a']))->toBe('tok');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) {
        [$header, $claims, $signature] = explode('.', $r['assertion']);
        $decode = fn (string $s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);

        return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $decode($header)['alg'] === 'RS256'
            && $decode($claims)['scope'] === 'scope-a'
            && $decode($claims)['iss'] === 'bot@project.iam.gserviceaccount.com'
            && $signature !== '';
    });
});

it('caches tokens per scope', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok'])]);
    $provider = provider($this->serviceAccount());

    $provider->token(['a']);
    $provider->token(['b']);

    Http::assertSentCount(2);
});

it('throws when google refuses the token request', function () {
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    provider($this->serviceAccount())->token(['a']);
})->throws(GoogleIndexingException::class, 'invalid_grant');

it('throws when the private key cannot sign', function () {
    Http::fake();

    provider(['client_email' => 'a@b.c', 'private_key' => 'garbage'])->token(['a']);
})->throws(GoogleIndexingException::class);
