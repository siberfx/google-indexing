<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Commands;

use Illuminate\Console\Command;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\GoogleIndexing;

final class StatusCommand extends Command
{
    protected $signature = 'indexing:status {url : Absolute URL to look up}';

    protected $description = 'Show when Google last received a notification for a URL';

    public function handle(GoogleIndexing $indexing): int
    {
        try {
            $metadata = $indexing->status($this->argument('url'));
        } catch (GoogleIndexingException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (isset($metadata['error'])) {
            $this->components->warn($metadata['error']['message'] ?? 'Unknown error');

            return self::FAILURE;
        }

        $this->line(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
