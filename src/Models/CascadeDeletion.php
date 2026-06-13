<?php

declare(strict_types=1);

namespace Krishnaraj\LaravelCascadingSoftDeletes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CascadeDeletion – Eloquent model for the cascade deletion tracking table.
 *
 * Each record in this table represents a single parent → child cascade-delete
 * event: when a parent model is soft-deleted and the package automatically
 * soft-deletes one of its related children, a row is inserted here to record
 * that relationship.
 *
 * This audit trail is essential for the restore flow. When a parent is later
 * restored, the package consults this table to determine exactly which children
 * were cascade-deleted (as opposed to independently deleted) and restores only
 * those, preventing unintended side-effects.
 *
 * The table name is configurable via the `cascading-soft-deletes.table_name`
 * config key (defaults to `cascade_deletions`).
 *
 * @property int         $id
 * @property string      $parent_type  Fully-qualified class name (morph type) of the parent model.
 * @property string|int  $parent_id    Primary key of the parent model.
 * @property string      $child_type   Fully-qualified class name (morph type) of the child model.
 * @property string|int  $child_id     Primary key of the child model.
 * @property \Illuminate\Support\Carbon|null $created_at  When the cascade deletion record was created.
 *
 * @method static Builder forParent(Model $parent)  Scope: filter records by parent morph.
 * @method static Builder forChild(Model $child)    Scope: filter records by child morph.
 *
 * @see \Krishnaraj\LaravelCascadingSoftDeletes\Traits\CascadesSoftDeletes
 */
class CascadeDeletion extends Model
{
    /**
     * Disable automatic management of both `created_at` and `updated_at`.
     *
     * This table only stores `created_at` (set manually via $fillable) and
     * has no `updated_at` column, so Eloquent's built-in timestamp handling
     * is turned off to prevent "column not found" errors.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_type',
        'parent_id',
        'child_type',
        'child_id',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * Casts `created_at` to a Carbon datetime instance for convenient
     * date manipulation and formatting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the table associated with the model.
     *
     * Reads the table name from the package configuration so that consuming
     * applications can customise the table name without extending the model.
     *
     * @return string
     */
    public function getTable(): string
    {
        return config('cascading-soft-deletes.table_name', 'cascade_deletions');
    }

    /**
     * Scope: filter cascade deletion records for a specific parent model.
     *
     * Constrains the query to only return records where `parent_type` and
     * `parent_id` match the given model's morph type and key, respectively.
     *
     * Usage:
     *     CascadeDeletion::forParent($user)->get();
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  The current query builder instance.
     * @param  \Illuminate\Database\Eloquent\Model    $parent The parent model to filter by.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForParent(Builder $query, Model $parent): Builder
    {
        return $query
            ->where('parent_type', $parent->getMorphClass())
            ->where('parent_id', $parent->getKey());
    }

    /**
     * Scope: filter cascade deletion records for a specific child model.
     *
     * Constrains the query to only return records where `child_type` and
     * `child_id` match the given model's morph type and key, respectively.
     *
     * Usage:
     *     CascadeDeletion::forChild($post)->get();
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query The current query builder instance.
     * @param  \Illuminate\Database\Eloquent\Model    $child The child model to filter by.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForChild(Builder $query, Model $child): Builder
    {
        return $query
            ->where('child_type', $child->getMorphClass())
            ->where('child_id', $child->getKey());
    }
}
