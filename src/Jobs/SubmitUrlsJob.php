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
        public readonly int $attempt = 1,
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
        // The attempt is part of the key: a retry of the same URLs must not be dropped while the first attempt still holds the lock.
        return sha1($this->action->value.'|'.implode('|', $this->urls).'|'.implode(',', $this->only ?? []).'|'.$this->attempt);
    }

    /**
     * Only temporarily failed URLs are sent again, as a follow-up job with the
     * next backoff delay. Accepted and permanently rejected URLs are never resent.
     */
    public function handle(GoogleIndexing $indexing): void
    {
        $results = $indexing->submit($this->urls, $this->action, $this->only);

        $retry = array_values(array_unique(array_merge([], ...array_map(fn ($r) => $r->retryable, $results))));

        if ($retry === []) {
            return;
        }

        if ($this->attempt < $this->tries) {
            $delay = $this->backoff[min($this->attempt - 1, count($this->backoff) - 1)] ?? 30;

            dispatch((new self($retry, $this->action, $this->only, $this->attempt + 1)))->delay(now()->addSeconds((int) $delay));

            return;
        }

        throw new RuntimeException(sprintf(
            'Indexing still failing after %d attempt(s) for %d URL(s).',
            $this->attempt,
            count($retry),
        ));
    }
}
