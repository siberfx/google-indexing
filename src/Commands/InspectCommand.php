<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Commands;

use Illuminate\Console\Command;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use Siberfx\GoogleIndexing\GoogleIndexing;

final class InspectCommand extends Command
{
    protected $signature = 'indexing:inspect {url : Absolute URL to check} {--json : Print raw JSON}';

    protected $description = 'Check whether Google has actually indexed a URL (Search Console URL Inspection)';

    public function handle(GoogleIndexing $indexing): int
    {
        try {
            $result = $indexing->inspect($this->argument('url'));
        } catch (GoogleIndexingException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['URL', $result['url']],
            ['Indexed', $result['indexed'] ? 'yes' : 'no'],
            ['Verdict', $result['verdict'] ?? '-'],
            ['Coverage', $result['coverage'] ?? '-'],
            ['Last crawl', $result['last_crawl'] ?? '-'],
            ['Robots.txt', $result['robots'] ?? '-'],
            ['Page fetch', $result['page_fetch'] ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
