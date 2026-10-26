<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Concerns;

use Illuminate\Database\Eloquent\Model;
use Siberfx\GoogleIndexing\Enums\UrlAction;
use Siberfx\GoogleIndexing\Facades\GoogleIndexing;

/**
 * Add to an Eloquent model and implement indexableUrl() — the package then
 * notifies search engines (queued) whenever the model is created, changed,
 * restored or deleted. No observers or listeners to register.
 *
 * @mixin Model
 */
trait Indexable
{
    /** The public, absolute URL of this record, or null when it has none. */
    abstract public function indexableUrl(): ?string;

    public static function bootIndexable(): void
    {
        static::created(function (Model $model): void {
            /** @var Model&static $model */
            $model->dispatchIndexing($model->shouldBeIndexed() ? UrlAction::Updated : null);
        });

        static::updated(function (Model $model): void {
            /** @var Model&static $model */
            // touch() and updated_at-only writes are not worth a notification.
            if ($model->hasMeaningfulChanges()) {
                $model->dispatchIndexing($model->shouldBeIndexed() ? UrlAction::Updated : null);
            }
        });

        static::deleted(function (Model $model): void {
            /** @var Model&static $model */
            $model->dispatchIndexing(UrlAction::Deleted);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model): void {
                /** @var Model&static $model */
                $model->dispatchIndexing($model->shouldBeIndexed() ? UrlAction::Updated : null);
            });
        }
    }

    /** Return false for drafts / unpublished / noindex records. */
    public function shouldBeIndexed(): bool
    {
        return true;
    }

    /** Attributes whose change alone should not trigger a notification. */
    public function ignoredIndexingAttributes(): array
    {
        return [static::UPDATED_AT ?? 'updated_at'];
    }

    /** Manually notify engines now (queued). */
    public function requestIndexing(UrlAction $action = UrlAction::Updated): void
    {
        $this->dispatchIndexing($action);
    }

    private function hasMeaningfulChanges(): bool
    {
        return array_diff(array_keys($this->getChanges()), $this->ignoredIndexingAttributes()) !== [];
    }

    private function dispatchIndexing(?UrlAction $action): void
    {
        if ($action === null || ! GoogleIndexing::enabled()) {
            return;
        }

        $url = $this->indexableUrl();

        if ($url !== null && $url !== '') {
            GoogleIndexing::queue($url, $action);
        }
    }
}
