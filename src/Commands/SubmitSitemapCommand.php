<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Commands;

use Illuminate\Console\Command;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\GoogleIndexing;

final class SubmitSitemapCommand extends Command
{
    protected $signature = 'indexing:sitemap
        {--url= : Sitemap URL or local path (default: config / APP_URL/sitemap.xml)}
        {--since= : Only URLs with <lastmod> newer than this, e.g. "1 day"}
        {--only=* : Limit to engines (google, indexnow)}
        {--queue : Dispatch to the queue instead of sending now}
        {--dry-run : List the URLs without sending anything}';

    protected $description = 'Read sitemap.xml and notify Google and IndexNow search engines about its URLs';

    public function handle(GoogleIndexing $indexing): int
    {
        $sitemap = $this->option('url') ?: $indexing->sitemapLocation();
        $only = $this->option('only') ?: null;

        try {
            if ($this->option('dry-run')) {
                $urls = $indexing->sitemapUrls($sitemap, $this->option('since'));
                $this->info(count($urls)." URL(s) found in {$sitemap}:");
                array_map(fn (string $u) => $this->line("  {$u}"), $urls);

                return self::SUCCESS;
            }

            if (! $indexing->enabled()) {
                $this->warn('Indexing is disabled (GOOGLE_INDEXING_ENABLED=false). Use --dry-run to preview.');

                return self::SUCCESS;
            }

            $this->components->info("Submitting {$sitemap}");
            $results = $indexing->submitSitemap($sitemap, $this->option('since'), $only, (bool) $this->option('queue'));
        } catch (GoogleIndexingException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            $this->components->info('Queued.');
        }

        $this->table(['Engine', 'Accepted', 'Failed', 'Note'], array_map(
            fn ($r) => array_values($r->summary()),
            $results,
        ));

        foreach ($results as $result) {
            foreach (array_slice($result->failed, 0, 10, true) as $url => $error) {
                $this->components->warn("[{$result->engine}] {$url} — {$error}");
            }
        }

        return collect($results)->contains(fn ($r) => $r->failed !== []) ? self::FAILURE : self::SUCCESS;
    }
}
