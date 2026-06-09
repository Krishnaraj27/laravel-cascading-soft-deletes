<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Krishnaraj\LaravelCascadingSoftDeletes\Traits\CascadesSoftDeletes;

class Post extends Model
{
    use SoftDeletes, CascadesSoftDeletes;

    protected $fillable = ['title', 'user_id'];

    protected $cascadeRelationships = ['comments'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
