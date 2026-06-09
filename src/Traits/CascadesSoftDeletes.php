<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Traits;

use Illuminate\Database\Eloquent\Model;

trait CascadesSoftDeletes
{
    protected static function bootCascadesSoftDeletes(): void
    {
        static::deleting(function (Model $model) {
            (new \Krishnaraj\LaravelCascadingSoftDeletes\Services\CascadeService())->cascadeDelete($model);
        });

        static::restoring(function (Model $model) {
            (new \Krishnaraj\LaravelCascadingSoftDeletes\Services\CascadeService())->cascadeRestore($model);
        });
    }
}