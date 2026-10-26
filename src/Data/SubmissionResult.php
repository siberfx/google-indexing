<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Data;

final readonly class SubmissionResult
{
    /**
     * @param  list<string>  $accepted  URLs the engine accepted
     * @param  array<string, string>  $failed  URL => error message
     */
    public function __construct(
        public string $engine,
        public array $accepted = [],
        public array $failed = [],
        public ?string $message = null,
    ) {}

    public static function skipped(string $engine, string $reason): self
    {
        return new self($engine, message: $reason);
    }

    public function successful(): bool
    {
        return $this->failed === [] && $this->accepted !== [];
    }

    /** @return array{engine: string, accepted: int, failed: int, message: ?string} */
    public function summary(): array
    {
        return [
            'engine' => $this->engine,
            'accepted' => count($this->accepted),
            'failed' => count($this->failed),
            'message' => $this->message,
        ];
    }
}
