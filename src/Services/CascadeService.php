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
     * @param Model $model
     * @return void
     * @throws InvalidRelationshipException
     * @throws NestingLimitExceededException
     * @throws \Throwable
     */
    public function cascadeRestore(Model $model): void
    {
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

            $deletedAtColumn = $model->getDeletedAtColumn();
            $parentDeletedAt = $model->{$deletedAtColumn};

            if (!$parentDeletedAt) {
                return;
            }

            $this->runInTransaction($model, function () use ($model, $relationships, $parentDeletedAt) {
                $processed = [];
                $this->executeRestore($model, $relationships, $parentDeletedAt, $processed);
            });
        } catch (\Throwable $e) {
            $this->handleException($model, $e);
        }
    }

    /**
     * Recursively delete relationships.
     *
     * @param Model $model
     * @param array $relations
     * @param bool $isForceDeleting
     * @param array $processed
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
                $relation->detach();
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
                    if (!empty($subRelations)) {
                        $this->executeDelete($child, $subRelations, $isForceDeleting, $processed);
                    }

                    if ($isForceDeleting) {
                        if (method_exists($child, 'forceDelete')) {
                            $child->forceDelete();
                        } else {
                            $child->delete();
                        }
                    } else {
                        $child->delete();
                    }
                }
            }
        }
    }

    /**
     * Recursively restore relationships.
     *
     * @param Model $model
     * @param array $relations
     * @param mixed $parentDeletedAt
     * @param array $processed
     * @return void
     */
    protected function executeRestore(Model $model, array $relations, mixed $parentDeletedAt, array &$processed): void
    {
        $modelKey = get_class($model) . ':' . $model->getKey();
        if (in_array($modelKey, $processed, true)) {
            return;
        }
        $processed[] = $modelKey;

        $grouped = $this->groupPaths($relations);

        foreach ($grouped as $relationName => $subRelations) {
            $relation = $this->getRelationInstance($model, $relationName);

            // BelongsToMany relationships are detached on delete, cannot be restored.
            if ($relation instanceof BelongsToMany) {
                continue;
            }

            if ($relation instanceof HasOneOrMany) {
                $relatedModel = $relation->getRelated();
                if (!$this->usesSoftDeletes($relatedModel)) {
                    continue;
                }

                $childDeletedAtColumn = $relatedModel->getDeletedAtColumn();

                // Retrieve only trashed children deleted at or after parent was deleted.
                $children = $relation->onlyTrashed()
                    ->where($childDeletedAtColumn, '>=', $parentDeletedAt)
                    ->get();

                foreach ($children as $child) {
                    if (!empty($subRelations)) {
                        $childDeletedAt = $child->{$childDeletedAtColumn};
                        $this->executeRestore($child, $subRelations, $childDeletedAt, $processed);
                    }

                    $child->restore();
                }
            }
        }
    }

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
                $property->setAccessible(true);
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
                $property->setAccessible(true);
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
                    $property->setAccessible(true);
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
                    $property->setAccessible(true);
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
                    $property->setAccessible(true);
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
}
