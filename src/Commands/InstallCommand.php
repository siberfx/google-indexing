<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Siberfx\GoogleIndexing\Auth\ServiceAccountTokenProvider;

final class InstallCommand extends Command
{
    protected $signature = 'indexing:install {--publish-config : Also publish config/google-indexing.php}';

    protected $description = 'Generate an IndexNow key, add env keys and check your Google credentials';

    public function handle(ServiceAccountTokenProvider $google): int
    {
        if ($this->option('publish-config')) {
            $this->callSilently('vendor:publish', ['--tag' => 'google-indexing-config']);
            $this->components->info('Config published.');
        }

        $env = base_path('.env');
        $contents = is_file($env) ? (string) file_get_contents($env) : '';

        if (blank(config('google-indexing.indexnow.key')) && ! str_contains($contents, 'INDEXNOW_KEY=')) {
            $key = Str::lower(Str::random(32));
            $block = "\n# siberfx/google-indexing\nGOOGLE_INDEXING_ENABLED=true\nGOOGLE_INDEXING_CREDENTIALS=\nINDEXNOW_KEY={$key}\n";
            file_put_contents($env, rtrim($contents).$block);
            $this->components->info("IndexNow key generated and written to .env. It will be served at /{$key}.txt");
        } else {
            $this->components->info('IndexNow key already present.');
        }

        if ($google->isConfigured()) {
            $this->components->info('Google service account: '.$google->clientEmail());
            $this->line('  Add this e-mail as an <options=bold>Owner</> of your property in Search Console.');
        } else {
            $this->components->warn('Google credentials not set — set GOOGLE_INDEXING_CREDENTIALS (path or JSON). IndexNow works without them.');
        }

        $this->newLine();
        $this->line('Try it:  php artisan indexing:sitemap --dry-run');

        return self::SUCCESS;
    }
}
