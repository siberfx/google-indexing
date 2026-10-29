<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Exceptions;

use RuntimeException;

class GoogleIndexingException extends RuntimeException
{
    public static function missingCredentials(): self
    {
        return new self('Google credentials are not configured. Set GOOGLE_INDEXING_CREDENTIALS to a service-account JSON path or JSON string.');
    }

    public static function invalidCredentials(string $reason): self
    {
        return new self("Invalid Google service-account credentials: {$reason}");
    }

    public static function tokenRequestFailed(string $body): self
    {
        return new self("Could not obtain a Google access token: {$body}");
    }

    public static function sitemapUnreadable(string $url, string $reason): self
    {
        return new self("Sitemap [{$url}] could not be read: {$reason}");
    }

    public static function requestFailed(string $message): self
    {
        return new self("Google API request failed: {$message}");
    }

    public static function unknownEngine(string $name): self
    {
        return new self("Unknown indexing engine [{$name}]. Register it in config/google-indexing.php under 'engines'.");
    }
}
