<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Siberfx\GoogleIndexing\Events\UrlsSubmitted;

/** Stores every engine result in the submissions table (only when history is enabled). */
final class RecordSubmission
{
    public function handle(UrlsSubmitted $event): void
    {
        $now = CarbonImmutable::now();
        $rows = [];

        $base = [
            'engine' => $event->result->engine,
            'action' => $event->action->value,
            'created_at' => $now,
        ];

        foreach ($event->result->accepted as $url) {
            $rows[] = $base + ['url' => $url, 'status' => 'accepted', 'error' => null];
        }

        foreach ($event->result->failed as $url => $error) {
            $rows[] = $base + ['url' => $url, 'status' => 'failed', 'error' => $error];
        }

        foreach ($event->result->deferred as $url => $reason) {
            $rows[] = $base + ['url' => $url, 'status' => 'deferred', 'error' => $reason];
        }

        if ($event->result->message !== null && $rows === []) {
            // Engine-level message (not configured, unsupported action): keep it visible in history too.
            $rows[] = $base + ['url' => '-', 'status' => 'skipped', 'error' => $event->result->message];
        }

        if ($rows !== []) {
            DB::table((string) config('google-indexing.history.table'))->insert($rows);
        }
    }
}
