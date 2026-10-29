<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Data;

final readonly class SubmissionResult
{
    /**
     * @param  list<string>  $accepted  URLs the engine accepted
     * @param  array<string, string>  $failed  URL => error message
     * @param  list<string>  $retryable  failed URLs that may succeed later (429, 5xx, network) — a subset of $failed's keys
     * @param  array<string, string>  $deferred  URL => reason, not sent this run (e.g. daily quota reached). Not a failure.
     */
    public function __construct(
        public string $engine,
        public array $accepted = [],
        public array $failed = [],
        public ?string $message = null,
        public array $retryable = [],
        public array $deferred = [],
    ) {}

    public static function skipped(string $engine, string $reason): self
    {
        return new self($engine, message: $reason);
    }

    public function successful(): bool
    {
        return $this->failed === [] && $this->accepted !== [];
    }

    /** @return array{engine: string, accepted: int, failed: int, deferred: int, message: ?string} */
    public function summary(): array
    {
        return [
            'engine' => $this->engine,
            'accepted' => count($this->accepted),
            'failed' => count($this->failed),
            'deferred' => count($this->deferred),
            'message' => $this->message,
        ];
    }
}
