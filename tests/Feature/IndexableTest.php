<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Siberfx\GoogleIndexing\Concerns\Indexable;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\Jobs\SubmitUrlsJob;

class Post extends Model
{
    use Indexable;

    protected $guarded = [];

    public function indexableUrl(): ?string
    {
        return 'https://example.com/posts/'.$this->slug;
    }

    public function shouldBeIndexed(): bool
    {
        return (bool) $this->published;
    }
}

beforeEach(function () {
    Schema::create('posts', function (Blueprint $t) {
        $t->id();
        $t->string('slug');
        $t->boolean('published')->default(true);
        $t->string('title')->nullable();
        $t->timestamps();
    });
    Bus::fake();
});

it('queues on create, meaningful update and delete', function () {
    $post = Post::create(['slug' => 'hello', 'published' => true]);
    Bus::assertDispatchedTimes(SubmitUrlsJob::class, 1);

    $post->update(['title' => 'Hi']);
    Bus::assertDispatchedTimes(SubmitUrlsJob::class, 2);

    $post->touch();
    Bus::assertDispatchedTimes(SubmitUrlsJob::class, 2);

    $post->delete();
    Bus::assertDispatched(SubmitUrlsJob::class, fn ($j) => $j->action === UrlAction::Deleted);
});

it('skips records that should not be indexed', function () {
    Post::create(['slug' => 'draft', 'published' => false]);

    Bus::assertNothingDispatched();
});
