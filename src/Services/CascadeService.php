<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Krishnaraj\LaravelCascadingSoftDeletes\Exceptions\InvalidRelationshipException;
use Krishnaraj\LaravelCascadingSoftDeletes\Exceptions\NestingLimitExceededException;

class CascadeService
{
    /**
     * Cascade delete relationships of a model.
     *
     * @param Model $model
     * @return void
     * @throws InvalidRelationshipException
     * @throws NestingLimitExceededException
     * @throws \Throwable
     */
    public function cascadeDelete(Model $model): void
    {
        $relationships = $this->getRelationships($model);
        if (empty($relationships)) {
            return;
        }

        try {
            $limit = $this->getNestingLimit($model);
            $this->validateNestingLimit($relationships, $limit);

            $isForceDeleting = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();

            $this->runInTransaction($model, function () use ($model, $relationships, $isForceDeleting) {
                $processed = [];
                $this->executeDelete($model, $relationships, $isForceDeleting, $processed);
            });
        } catch (\Throwable $e) {
            $this->handleException($model, $e);
        }
    }

    /**
     * Cascade restore relationships of a model.
     *
     * Uses the cascade_deletions tracking table to determine exactly which
     * children were deleted by this parent's cascade operation, rather than
     * relying on timestamp comparisons. This approach:
     *
     * 1. Avoids restoring children that were independently deleted (not via cascade).
     * 2. Prevents restoring a child that is still held by another soft-deleted parent
     *    in a multi-parent relationship scenario.
     *
     * @param Model $model
     * @return void
     * @throws InvalidRelationshipException
     * @throws NestingLimitExceededException
     * @throws \Throwable
     */
    public function cascadeRestore(Model $model): void
    {
        if (!$this->shouldCascadeRestore($model)) {
            return;
        }

        $relationships = $this->getRelationships($model);
        if (empty($relationships)) {
            return;
        }

        try {
            $limit = $this->getNestingLimit($model);
            $this->validateNestingLimit($relationships, $limit);

            if (!$this->usesSoftDeletes($model)) {
                return;
            }

            $this->runInTransaction($model, function () use ($model, $relationships) {
                $processed = [];
                $this->executeRestore($model, $relationships, $processed);
            });
        } catch (\Throwable $e) {
            $this->handleException($model, $e);
        }
    }

    /**
     * Recursively delete relationships and create tracking records.
     *
     * For each child in the cascade chain:
     * - If the child was NOT already trashed, soft-delete it and log a tracking
     *   record in the cascade_deletions table linking parent → child.
     * - If the child was already trashed (independently deleted), skip tracking
     *   since this cascade did not cause the deletion.
     * - If force-deleting, permanently remove the child and purge any existing
     *   tracking records for it.
     *
     * @param Model $model The parent model whose children are being deleted.
     * @param array $relations The relationship paths to cascade through.
     * @param bool $isForceDeleting Whether the root deletion is a force delete.
     * @param array $processed Tracks already-processed models to prevent cycles.
     * @return void
     */
    protected function executeDelete(Model $model, array $relations, bool $isForceDeleting, array &$processed): void
    {
        $modelKey = get_class($model) . ':' . $model->getKey();
        if (in_array($modelKey, $processed, true)) {
            return;
        }
        $processed[] = $modelKey;

        $grouped = $this->groupPaths($relations);

        foreach ($grouped as $relationName => $subRelations) {
            $relation = $this->getRelationInstance($model, $relationName);

            if ($relation instanceof BelongsToMany) {
                if ($this->shouldDetachBelongsToMany($model)) {
                    $relation->detach();
                }
                continue;
            }

            if ($relation instanceof HasOneOrMany) {
                $relatedModel = $relation->getRelated();
                $usesSoft = $this->usesSoftDeletes($relatedModel);

                $query = $relation;
                if ($usesSoft && method_exists($relation, 'withTrashed')) {
                    $query = $relation->withTrashed();
                }

                $children = $query->get();

                foreach ($children as $child) {
                    // Recurse into sub-relations before deleting the child itself,
                    // so that grandchildren are processed while the child is still accessible.
                    if (!empty($subRelations)) {
                        $this->executeDelete($child, $subRelations, $isForceDeleting, $processed);
                    }

                    if ($isForceDeleting) {
                        // Purge tracking records before permanent deletion since the
                        // child will no longer exist in the database.
                        $this->removeTrackingRecordsForChild($child);

                        if (method_exists($child, 'forceDelete')) {
                            $child->forceDelete();
                        } else {
                            $child->delete();
                        }
                    } else {
                        /*
                         * Only create a tracking record if:
                         * 1. The child uses SoftDeletes (non-soft-delete children
                         *    cannot be restored, so tracking is meaningless).
                         * 2. The child was NOT already trashed before this cascade.
                         *    If it was already trashed (e.g. independently deleted by
                         *    the developer), this cascade did not cause the deletion,
                         *    and the child should NOT be restored when the parent is
                         *    restored later.
                         */
                        $wasAlreadyTrashed = $usesSoft && $child->trashed();

                        $child->delete();

                        if ($usesSoft && !$wasAlreadyTrashed) {
                            $this->createTrackingRecord($model, $child);
                        }
                    }
                }
            }
        }
    }

    /**
     * Recursively restore relationships using the cascade tracking table.
     *
     * Instead of relying on timestamp comparisons (which can be imprecise with
     * low-resolution clocks or concurrent operations), this method queries the
     * cascade_deletions tracking table to identify exactly which children were
     * deleted by this parent's cascade.
     *
     * For each tracked child:
     * 1. Remove the tracking entry for THIS parent → child pair.
     * 2. Check if the child has any REMAINING tracking entries from other parents
     *    (multi-parent scenario). If so, do NOT restore — another soft-deleted
     *    parent still "holds" the deletion.
     * 3. If no remaining entries, restore the child and recurse into its sub-relations.
     * 4. Clean up any stale tracking entries for children that were already restored
     *    or force-deleted independently.
     *
     * @param Model $model The parent model whose children are being restored.
     * @param array $relations The relationship paths to cascade through.
     * @param array $processed Tracks already-processed models to prevent cycles.
     * @return void
     */
    protected function executeRestore(Model $model, array $relations, array &$processed): void
    {
        if (!$this->shouldCascadeRestore($model)) {
            return;
        }

        $modelKey = get_class($model) . ':' . $model->getKey();
        if (in_array($modelKey, $processed, true)) {
            return;
        }
        $processed[] = $modelKey;

        $grouped = $this->groupPaths($relations);

        foreach ($grouped as $relationName => $subRelations) {
            $relation = $this->getRelationInstance($model, $relationName);

            // BelongsToMany relationships are detached on delete and cannot be
            // automatically restored (the pivot data is permanently lost).
            if ($relation instanceof BelongsToMany) {
                continue;
            }

            if ($relation instanceof HasOneOrMany) {
                $relatedModel = $relation->getRelated();
                if (!$this->usesSoftDeletes($relatedModel)) {
                    continue;
                }

                // Query the tracking table for child IDs that were cascade-deleted
                // by THIS specific parent model instance.
                $trackedChildIds = $this->getTrackedChildIds($model, get_class($relatedModel));

                if (empty($trackedChildIds)) {
                    continue;
                }

                // Fetch only the trashed children that appear in the tracking table.
                // Children that were already restored or force-deleted independently
                // will not appear here.
                $children = $relation->onlyTrashed()
                    ->whereIn($relatedModel->getKeyName(), $trackedChildIds)
                    ->get();

                foreach ($children as $child) {
                    // Remove the tracking entry for THIS parent → child pair first,
                    // so that the "has other active deletions" check below does not
                    // count this parent's own entry.
                    $this->removeTrackingRecord($model, $child);

                    /*
                     * Multi-parent guard: If another soft-deleted parent also cascade-
                     * deleted this child, a tracking entry from that other parent will
                     * still exist. In that case, do NOT restore the child — it should
                     * remain soft-deleted until ALL parents that cascade-deleted it
                     * have been restored.
                     */
                    if ($this->hasOtherActiveDeletions($child)) {
                        continue;
                    }

                    // Recurse into sub-relations before restoring the child,
                    // so grandchildren are processed while the child is still trashed.
                    if (!empty($subRelations)) {
                        $this->executeRestore($child, $subRelations, $processed);
                    }

                    $child->restore();
                }

                /*
                 * Stale entry cleanup: If tracked children were independently restored
                 * or force-deleted (and thus not found in the onlyTrashed() query above),
                 * their tracking entries are now stale. Remove them to prevent unbounded
                 * growth of the tracking table.
                 */
                $restoredChildIds = $children->pluck($relatedModel->getKeyName())->toArray();
                $staleChildIds = array_diff($trackedChildIds, $restoredChildIds);

                if (!empty($staleChildIds)) {
                    $this->removeStaleTrackingRecords($model, get_class($relatedModel), $staleChildIds);
                }
            }
        }
    }

    // =========================================================================
    // Cascade Tracking Table Operations
    // =========================================================================

    /**
     * Get the configured cascade deletions table name.
     *
     * @return string
     */
    protected function getTrackingTable(): string
    {
        return config('cascading-soft-deletes.table_name', 'cascade_deletions');
    }

    /**
     * Create a tracking record logging that a parent's cascade caused a child's deletion.
     *
     * @param Model $parent The parent model that triggered the cascade.
     * @param Model $child The child model that was soft-deleted by the cascade.
     * @return void
     */
    protected function createTrackingRecord(Model $parent, Model $child): void
    {
        DB::table($this->getTrackingTable())->insert([
            'parent_type' => get_class($parent),
            'parent_id' => $parent->getKey(),
            'child_type' => get_class($child),
            'child_id' => $child->getKey(),
            'created_at' => now(),
        ]);
    }

    /**
     * Remove the tracking record for a specific parent → child relationship.
     *
     * Called during restore to "release" this parent's hold on the child,
     * allowing the multi-parent guard to accurately determine if any other
     * parent still requires the child to remain soft-deleted.
     *
     * @param Model $parent The parent model being restored.
     * @param Model $child The child model whose tracking entry should be removed.
     * @return void
     */
    protected function removeTrackingRecord(Model $parent, Model $child): void
    {
        DB::table($this->getTrackingTable())
            ->where('parent_type', get_class($parent))
            ->where('parent_id', $parent->getKey())
            ->where('child_type', get_class($child))
            ->where('child_id', $child->getKey())
            ->delete();
    }

    /**
     * Check if a child model still has active cascade deletion entries from other parents.
     *
     * This is the core of the multi-parent guard. If any entries remain after
     * removing the current parent's entry, it means another soft-deleted parent
     * also cascade-deleted this child and hasn't been restored yet.
     *
     * @param Model $child The child model to check.
     * @return bool True if other cascade deletion entries exist for this child.
     */
    protected function hasOtherActiveDeletions(Model $child): bool
    {
        return DB::table($this->getTrackingTable())
            ->where('child_type', get_class($child))
            ->where('child_id', $child->getKey())
            ->exists();
    }

    /**
     * Get the IDs of children that were tracked as cascade-deleted by a specific parent.
     *
     * @param Model $parent The parent model.
     * @param string $childType The fully qualified class name of the child model.
     * @return array Array of child model IDs.
     */
    protected function getTrackedChildIds(Model $parent, string $childType): array
    {
        return DB::table($this->getTrackingTable())
            ->where('parent_type', get_class($parent))
            ->where('parent_id', $parent->getKey())
            ->where('child_type', $childType)
            ->pluck('child_id')
            ->toArray();
    }

    /**
     * Remove all tracking records where the given model is listed as a child.
     *
     * Called when a model is independently restored or force-deleted to ensure
     * no stale tracking entries remain that could cause incorrect behaviour
     * during future parent restore operations.
     *
     * @param Model $child The child model whose tracking entries should be removed.
     * @return void
     */
    public function removeTrackingRecordsForChild(Model $child): void
    {
        DB::table($this->getTrackingTable())
            ->where('child_type', get_class($child))
            ->where('child_id', $child->getKey())
            ->delete();
    }

    /**
     * Remove all tracking records where the given model is listed as a parent.
     *
     * Called when a model is force-deleted to clean up all tracking entries
     * it created as a cascade source, since those children may now be orphaned
     * or managed by other relationships.
     *
     * @param Model $parent The parent model whose tracking entries should be removed.
     * @return void
     */
    public function removeTrackingRecordsForParent(Model $parent): void
    {
        DB::table($this->getTrackingTable())
            ->where('parent_type', get_class($parent))
            ->where('parent_id', $parent->getKey())
            ->delete();
    }

    /**
     * Remove stale tracking records for children that are no longer trashed.
     *
     * This handles the case where a child was independently restored or
     * force-deleted after being cascade-deleted. The tracking entry is now
     * orphaned and should be cleaned up to prevent table bloat.
     *
     * @param Model $parent The parent model whose stale entries should be removed.
     * @param string $childType The fully qualified class name of the child model.
     * @param array $childIds The IDs of the stale child records.
     * @return void
     */
    protected function removeStaleTrackingRecords(Model $parent, string $childType, array $childIds): void
    {
        DB::table($this->getTrackingTable())
            ->where('parent_type', get_class($parent))
            ->where('parent_id', $parent->getKey())
            ->where('child_type', $childType)
            ->whereIn('child_id', $childIds)
            ->delete();
    }

    // =========================================================================
    // Relationship Helpers
    // =========================================================================

    /**
     * Group relationship paths by their first segment.
     *
     * @param array $paths
     * @return array
     */
    protected function groupPaths(array $paths): array
    {
        $grouped = [];
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $firstSegment = $segments[0];

            if (!isset($grouped[$firstSegment])) {
                $grouped[$firstSegment] = [];
            }

            if (count($segments) > 1) {
                $grouped[$firstSegment][] = implode('.', array_slice($segments, 1));
            }
        }
        return $grouped;
    }

    /**
     * Validate the relationship path nesting limit.
     *
     * @param array $paths
     * @param int $maxLimit
     * @return void
     * @throws NestingLimitExceededException
     */
    protected function validateNestingLimit(array $paths, int $maxLimit): void
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            if (count($segments) > $maxLimit) {
                throw new NestingLimitExceededException(
                    "Relationship path '{$path}' exceeds the maximum nesting limit of {$maxLimit} levels."
                );
            }
        }
    }

    /**
     * Get the relation instance from the model.
     *
     * @param Model $model
     * @param string $relationName
     * @return Relation
     * @throws InvalidRelationshipException
     */
    protected function getRelationInstance(Model $model, string $relationName): Relation
    {
        if (!method_exists($model, $relationName)) {
            throw new InvalidRelationshipException(
                "Relationship method '{$relationName}' does not exist on model " . get_class($model) . "."
            );
        }

        $relation = $model->{$relationName}();

        if (!$relation instanceof Relation) {
            throw new InvalidRelationshipException(
                "Method '{$relationName}' on model " . get_class($model) . " does not return a valid Eloquent relation."
            );
        }

        return $relation;
    }

    /**
     * Get configured cascade relationships from the model.
     *
     * @param Model $model
     * @return array
     */
    protected function getRelationships(Model $model): array
    {
        if (method_exists($model, 'cascadeRelationships')) {
            return (array) $model->cascadeRelationships();
        }

        try {
            $reflector = new \ReflectionClass($model);
            if ($reflector->hasProperty('cascadeRelationships')) {
                $property = $reflector->getProperty('cascadeRelationships');
                return (array) $property->getValue($model);
            }
        } catch (\ReflectionException $e) {
            // Fallback
        }

        // Check dynamic property (useful for runtime setting or testing overrides)
        if (isset($model->cascadeRelationships)) {
            return (array) $model->cascadeRelationships;
        }

        return [];
    }

    /**
     * Get nesting limit for the model.
     *
     * @param Model $model
     * @return int
     */
    protected function getNestingLimit(Model $model): int
    {
        try {
            $reflector = new \ReflectionClass($model);
            if ($reflector->hasProperty('cascadeNestingLimit')) {
                $property = $reflector->getProperty('cascadeNestingLimit');
                return (int) $property->getValue($model);
            }
        } catch (\ReflectionException $e) {
            // Fallback
        }

        // Check dynamic property (useful for runtime setting or testing overrides)
        if (isset($model->cascadeNestingLimit)) {
            return (int) $model->cascadeNestingLimit;
        }

        return (int) config('cascading-soft-deletes.max_nesting_level', 3);
    }

    /**
     * Check if the model uses SoftDeletes trait.
     *
     * @param Model $model
     * @return bool
     */
    protected function usesSoftDeletes(Model $model): bool
    {
        return in_array(
            'Illuminate\Database\Eloquent\SoftDeletes',
            class_uses_recursive($model),
            true
        );
    }

    // =========================================================================
    // Transaction & Error Handling
    // =========================================================================

    /**
     * Run the callback within a database transaction if configured and not already inside one.
     *
     * @param Model $model
     * @param callable $callback
     * @return void
     */
    protected function runInTransaction(Model $model, callable $callback): void
    {
        $useTransaction = $this->shouldUseTransaction($model);
        $alreadyInTransaction = DB::transactionLevel() > 0;

        if ($useTransaction && !$alreadyInTransaction) {
            DB::beginTransaction();
            try {
                $callback();
                DB::commit();
            } catch (\Throwable $e) {
                if ($this->shouldRollbackOnError($model)) {
                    DB::rollBack();
                } else {
                    DB::commit();
                }
                throw $e;
            }
        } else {
            $callback();
        }
    }

    /**
     * Handle an exception by logging it and optionally throwing it.
     *
     * @param Model $model
     * @param \Throwable $e
     * @return void
     * @throws \Throwable
     */
    protected function handleException(Model $model, \Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::error("Cascading soft delete/restore error on model " . get_class($model) . ": " . $e->getMessage(), [
            'exception' => $e,
            'model_id' => $model->getKey(),
        ]);

        if ($this->shouldThrowOnError($model)) {
            throw $e;
        }
    }

    /**
     * Determine if transaction should be used.
     *
     * @param Model $model
     * @return bool
     */
    protected function shouldUseTransaction(Model $model): bool
    {
        if (property_exists($model, 'cascadeUseTransaction') || isset($model->cascadeUseTransaction)) {
            try {
                $reflector = new \ReflectionClass($model);
                if ($reflector->hasProperty('cascadeUseTransaction')) {
                    $property = $reflector->getProperty('cascadeUseTransaction');
                    return (bool) $property->getValue($model);
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
            if (isset($model->cascadeUseTransaction)) {
                return (bool) $model->cascadeUseTransaction;
            }
        }
        return (bool) config('cascading-soft-deletes.use_transaction', true);
    }

    /**
     * Determine if exception should be thrown on error.
     *
     * @param Model $model
     * @return bool
     */
    protected function shouldThrowOnError(Model $model): bool
    {
        if (property_exists($model, 'cascadeThrowOnError') || isset($model->cascadeThrowOnError)) {
            try {
                $reflector = new \ReflectionClass($model);
                if ($reflector->hasProperty('cascadeThrowOnError')) {
                    $property = $reflector->getProperty('cascadeThrowOnError');
                    return (bool) $property->getValue($model);
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
            if (isset($model->cascadeThrowOnError)) {
                return (bool) $model->cascadeThrowOnError;
            }
        }
        return (bool) config('cascading-soft-deletes.throw_on_error', true);
    }

    /**
     * Determine if transaction should be rolled back on error.
     *
     * @param Model $model
     * @return bool
     */
    protected function shouldRollbackOnError(Model $model): bool
    {
        if (property_exists($model, 'cascadeRollbackOnError') || isset($model->cascadeRollbackOnError)) {
            try {
                $reflector = new \ReflectionClass($model);
                if ($reflector->hasProperty('cascadeRollbackOnError')) {
                    $property = $reflector->getProperty('cascadeRollbackOnError');
                    return (bool) $property->getValue($model);
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
            if (isset($model->cascadeRollbackOnError)) {
                return (bool) $model->cascadeRollbackOnError;
            }
        }
        return (bool) config('cascading-soft-deletes.rollback_on_error', true);
    }

    /**
     * Determine if cascade restore should be performed.
     *
     * @param Model $model
     * @return bool
     */
    public function shouldCascadeRestore(Model $model): bool
    {
        if (property_exists($model, 'cascadeOnRestore') || isset($model->cascadeOnRestore)) {
            try {
                $reflector = new \ReflectionClass($model);
                if ($reflector->hasProperty('cascadeOnRestore')) {
                    $property = $reflector->getProperty('cascadeOnRestore');
                    return (bool) $property->getValue($model);
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
            if (isset($model->cascadeOnRestore)) {
                return (bool) $model->cascadeOnRestore;
            }
        }
        return (bool) config('cascading-soft-deletes.cascade_on_restore', true);
    }

    /**
     * Determine if BelongsToMany relationships should be detached on delete.
     *
     * @param Model $model
     * @return bool
     */
    protected function shouldDetachBelongsToMany(Model $model): bool
    {
        if (property_exists($model, 'cascadeDetachBelongsToMany') || isset($model->cascadeDetachBelongsToMany)) {
            try {
                $reflector = new \ReflectionClass($model);
                if ($reflector->hasProperty('cascadeDetachBelongsToMany')) {
                    $property = $reflector->getProperty('cascadeDetachBelongsToMany');
                    return (bool) $property->getValue($model);
                }
            } catch (\ReflectionException $e) {
                // ignore
            }
            if (isset($model->cascadeDetachBelongsToMany)) {
                return (bool) $model->cascadeDetachBelongsToMany;
            }
        }
        return (bool) config('cascading-soft-deletes.detach_belongs_to_many', true);
    }
}
