<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Http\Controllers;

use Illuminate\Http\Response;

/** Serves the IndexNow ownership proof at /{key}.txt. */
final class IndexNowKeyController
{
    public function __invoke(): Response
    {
        return new Response((string) config('google-indexing.indexnow.key'), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
