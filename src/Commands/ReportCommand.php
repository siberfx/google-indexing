<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ReportCommand extends Command
{
    protected $signature = 'indexing:report {--hours=24 : Look back this many hours}';

    protected $description = 'Summarise recent submissions per engine and list the latest failures';

    public function handle(): int
    {
        $table = (string) config('google-indexing.history.table');

        if (! Schema::hasTable($table)) {
            $this->components->error("Table [{$table}] not found. Enable google-indexing.history and run the published migration.");

            return self::FAILURE;
        }

        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);

        $counts = DB::table($table)
            ->where('created_at', '>=', $since)
            ->selectRaw('engine, status, count(*) as total')
            ->groupBy('engine', 'status')
            ->orderBy('engine')
            ->get();

        $this->components->info("Submissions in the last {$hours} hour(s)");
        $this->table(['Engine', 'Status', 'Count'], $counts->map(fn ($r) => [$r->engine, $r->status, $r->total])->all());

        $failures = DB::table($table)
            ->where('created_at', '>=', $since)
            ->where('status', 'failed')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['engine', 'url', 'error', 'created_at']);

        if ($failures->isNotEmpty()) {
            $this->components->warn('Latest failures');
            $this->table(['Engine', 'URL', 'Error', 'At'], $failures->map(fn ($r) => [$r->engine, $r->url, $r->error, $r->created_at])->all());
        }

        return self::SUCCESS;
    }
}
