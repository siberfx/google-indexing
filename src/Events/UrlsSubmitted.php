<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Events;

use Siberfx\GoogleIndexing\Data\SubmissionResult;
use Siberfx\GoogleIndexing\Enums\UrlAction;

/** Fired once per engine after a submission finished (successful or not). */
final readonly class UrlsSubmitted
{
    public function __construct(
        public SubmissionResult $result,
        public UrlAction $action,
    ) {}
}
