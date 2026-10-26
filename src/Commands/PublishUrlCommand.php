<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Commands;

use Illuminate\Console\Command;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\GoogleIndexing;

final class PublishUrlCommand extends Command
{
    protected $signature = 'indexing:publish
        {urls* : One or more absolute URLs}
        {--delete : Tell engines the URLs were removed}
        {--only=* : Limit to engines (google, indexnow)}
        {--queue : Dispatch to the queue instead of sending now}';

    protected $description = 'Notify search engines about new, updated or deleted URLs';

    public function handle(GoogleIndexing $indexing): int
    {
        $action = $this->option('delete') ? UrlAction::Deleted : UrlAction::Updated;
        $only = $this->option('only') ?: null;

        if (! $indexing->enabled()) {
            $this->warn('Indexing is disabled (GOOGLE_INDEXING_ENABLED=false).');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $indexing->queue($this->argument('urls'), $action, $only);
            $this->components->info('Queued.');

            return self::SUCCESS;
        }

        $results = $indexing->submit($this->argument('urls'), $action, $only);

        foreach ($results as $result) {
            $line = sprintf('[%s] %d accepted, %d failed%s', $result->engine, count($result->accepted), count($result->failed), $result->message ? " ({$result->message})" : '');
            $result->failed === [] ? $this->components->info($line) : $this->components->warn($line);

            foreach ($result->failed as $url => $error) {
                $this->line("    {$url} — {$error}");
            }
        }

        return collect($results)->contains(fn ($r) => $r->failed !== []) ? self::FAILURE : self::SUCCESS;
    }
}
