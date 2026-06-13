<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Krishnaraj\LaravelCascadingSoftDeletes\Traits\CascadesSoftDeletes;

/**
 * A secondary parent model for testing multi-parent cascade scenarios.
 *
 * A Team can own Posts, and so can a User. When both parents cascade-delete
 * the same Post, the Post should only be restored when both parents are restored.
 */
class Team extends Model
{
    use SoftDeletes, CascadesSoftDeletes;

    protected $fillable = ['name'];

    public $cascadeRelationships = ['posts'];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
