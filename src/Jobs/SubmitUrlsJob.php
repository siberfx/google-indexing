<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\GoogleIndexing;

final class SubmitUrlsJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Identical bursts (e.g. several saves of one model) collapse into one job. */
    public int $uniqueFor = 60;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    /**
     * @param  list<string>  $urls
     * @param  list<string>|null  $only
     */
    public function __construct(
        public readonly array $urls,
        public readonly UrlAction $action = UrlAction::Updated,
        public readonly ?array $only = null,
    ) {
        $queue = (array) config('google-indexing.queue');

        // Don't dispatch before the surrounding DB transaction committed.
        $this->afterCommit();

        $this->tries = (int) ($queue['tries'] ?? 3);
        $this->backoff = array_values((array) ($queue['backoff'] ?? [30, 120, 600]));

        if (! empty($queue['connection'])) {
            $this->onConnection($queue['connection']);
        }

        if (! empty($queue['name'])) {
            $this->onQueue($queue['name']);
        }
    }

    public function uniqueId(): string
    {
        return sha1($this->action->value.'|'.implode('|', $this->urls).'|'.implode(',', $this->only ?? []));
    }

    public function handle(GoogleIndexing $indexing): void
    {
        $results = $indexing->submit($this->urls, $this->action, $this->only);

        $attempted = array_filter($results, fn ($r) => $r->message === null);

        // Retry only when every engine that actually ran rejected everything.
        if ($attempted !== [] && array_filter($attempted, fn ($r) => $r->accepted !== []) === []) {
            throw new RuntimeException('All indexing engines rejected the submission: '.json_encode(
                array_map(fn ($r) => $r->failed, $attempted),
            ));
        }
    }
}
