<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Auth;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;

/**
 * Minimal OAuth2 service-account (JWT bearer) flow. No Google SDK required.
 */
final class ServiceAccountTokenProvider
{
    /** @var array{client_email: string, private_key: string}|null */
    private ?array $credentials = null;

    /**
     * @param  array<string, mixed>|string|null  $source  path, raw JSON or decoded array
     */
    public function __construct(
        private readonly array|string|null $source,
        private readonly Cache $cache,
        private readonly string $tokenEndpoint = 'https://oauth2.googleapis.com/token',
        private readonly int $timeout = 15,
    ) {}

    public function isConfigured(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (GoogleIndexingException) {
            return false;
        }
    }

    public function clientEmail(): string
    {
        return $this->credentials()['client_email'];
    }

    /**
     * @param  list<string>  $scopes
     */
    public function token(array $scopes): string
    {
        $creds = $this->credentials();
        $scope = implode(' ', $scopes);
        $key = 'google-indexing.token.'.sha1($creds['client_email'].'|'.$scope);

        if (is_string($cached = $this->cache->get($key))) {
            return $cached;
        }

        $response = Http::asForm()->timeout($this->timeout)->post($this->tokenEndpoint, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->assertion($creds, $scope),
        ]);

        if ($response->failed() || ! is_string($token = $response->json('access_token'))) {
            throw GoogleIndexingException::tokenRequestFailed($response->body());
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 120);
        $this->cache->put($key, $token, $ttl);

        return $token;
    }

    /** @param  array{client_email: string, private_key: string}  $creds */
    private function assertion(array $creds, string $scope): string
    {
        $now = time();
        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64Url(json_encode([
                'iss' => $creds['client_email'],
                'scope' => $scope,
                'aud' => $this->tokenEndpoint,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR)),
        ];

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            throw GoogleIndexingException::invalidCredentials('private_key could not be used for signing.');
        }

        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    /** @return array{client_email: string, private_key: string} */
    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $data = $this->source;

        if ($data === null || $data === '' || $data === []) {
            throw GoogleIndexingException::missingCredentials();
        }

        if (is_string($data)) {
            $json = str_starts_with(ltrim($data), '{')
                ? $data
                : (is_file($data) ? file_get_contents($data) : false);

            if ($json === false) {
                throw GoogleIndexingException::invalidCredentials("file [{$data}] not found.");
            }

            $data = json_decode($json, true);
        }

        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            throw GoogleIndexingException::invalidCredentials('client_email / private_key missing.');
        }

        return $this->credentials = [
            'client_email' => (string) $data['client_email'],
            'private_key' => (string) $data['private_key'],
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
