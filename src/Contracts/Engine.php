<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Contracts;

use Siberfx\GoogleIndexing\Data\SubmissionResult;
use Siberfx\GoogleIndexing\Enums\UrlAction;

interface Engine
{
    /** Short identifier used in results and logs, e.g. "google". */
    public function name(): string;

    /**
     * Whether the engine has everything it needs (credentials, key ...).
     * Unconfigured engines are skipped instead of failing.
     */
    public function isConfigured(): bool;

    /**
     * @param  list<string>  $urls  absolute URLs
     */
    public function submit(array $urls, UrlAction $action = UrlAction::Updated): SubmissionResult;
}
