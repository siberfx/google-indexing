<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Siberfx\GoogleIndexing\GoogleIndexingServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GoogleIndexingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://example.com');
        $app['config']->set('google-indexing.enabled', true);
        $app['config']->set('google-indexing.indexnow.key', 'abcdef1234567890abcdef1234567890');
        $app['config']->set('google-indexing.google.credentials', $this->serviceAccount());
    }

    /** @return array{client_email: string, private_key: string} */
    protected function serviceAccount(): array
    {
        // Throwaway key used only by the test-suite (fixtures/test-key.pem).
        return [
            'client_email' => 'bot@project.iam.gserviceaccount.com',
            'private_key' => (string) file_get_contents(__DIR__.'/fixtures/test-key.pem'),
        ];
    }
}
