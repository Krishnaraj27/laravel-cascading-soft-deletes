<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Krishnaraj\LaravelCascadingSoftDeletes\Traits\CascadesSoftDeletes;

class Company extends Model
{
    use SoftDeletes, CascadesSoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id', 'name'];

    public $cascadeRelationships = ['employees'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
