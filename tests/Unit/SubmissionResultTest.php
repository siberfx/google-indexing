<?php

declare(strict_types=1);

use Siberfx\GoogleIndexing\Data\SubmissionResult;

it('is successful only with accepted urls and no failures', function () {
    expect((new SubmissionResult('google', accepted: ['https://a.test']))->successful())->toBeTrue()
        ->and((new SubmissionResult('google'))->successful())->toBeFalse()
        ->and((new SubmissionResult('google', ['https://a.test'], ['https://b.test' => 'x']))->successful())->toBeFalse();
});

it('builds a skipped result and a summary', function () {
    $skipped = SubmissionResult::skipped('indexnow', 'Engine is not configured.');

    expect($skipped->successful())->toBeFalse()
        ->and($skipped->summary())->toBe(['engine' => 'indexnow', 'accepted' => 0, 'failed' => 0, 'deferred' => 0, 'message' => 'Engine is not configured.']);
});
