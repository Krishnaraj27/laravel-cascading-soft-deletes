<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Traits;

use Illuminate\Database\Eloquent\Model;
use Krishnaraj\LaravelCascadingSoftDeletes\Services\CascadeService;

trait CascadesSoftDeletes
{
    /**
     * Boot the CascadesSoftDeletes trait.
     *
     * Registers Eloquent model event listeners for cascading soft deletes
     * and restores, as well as cleanup of cascade tracking records.
     *
     * Event hooks:
     * - deleting: Cascades the delete to configured child relationships and
     *             cleans up tracking records when the model is force-deleted.
     * - restoring: Cleans up stale tracking records where this model is a child
     *              (handles independent restores) and cascades the restore to
     *              children that were originally deleted by this model's cascade.
     *
     * @return void
     */
    protected static function bootCascadesSoftDeletes(): void
    {
        static::deleting(function (Model $model) {
            $service = new CascadeService();

            /*
             * When a model is being force-deleted, any tracking records referencing
             * it as a parent or child are now stale and must be purged. This prevents
             * future restore operations from attempting to restore permanently deleted
             * records or from querying a non-existent parent.
             */
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                $service->removeTrackingRecordsForParent($model);
                $service->removeTrackingRecordsForChild($model);
            }

            $service->cascadeDelete($model);
        });

        static::restoring(function (Model $model) {
            $service = new CascadeService();

            /*
             * When a model is restored (either independently or via cascade),
             * remove all tracking records where it is listed as a child. This
             * ensures that if the model was independently restored by a developer,
             * no stale tracking entry will cause it to be incorrectly re-processed
             * during a future parent restore operation.
             *
             * Note: During cascade restores, the CascadeService already removes the
             * specific parent→child entry before calling restore(). This hook acts
             * as a safety net to clean up any remaining entries from other parents
             * that may no longer be relevant after this independent restore.
             */
            $service->removeTrackingRecordsForChild($model);

            $service->cascadeRestore($model);
        });
    }
}