<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Krishnaraj\LaravelCascadingSoftDeletes\Traits\CascadesSoftDeletes;

class Employee extends Model
{
    use SoftDeletes, CascadesSoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['id', 'name', 'company_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
